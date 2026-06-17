<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Services;

use Tabbly\Inbound\Config\Config;
use Tabbly\Inbound\Legacy\LegacyBootstrap;
use Tabbly\Inbound\Repositories\AgentRepository;
use Tabbly\Inbound\Repositories\InboundRepository;
use Tabbly\Inbound\Support\PhoneUtil;

final class RefreshMetadataService
{
    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly InboundRepository $inbound = new InboundRepository(),
    ) {
        LegacyBootstrap::init();
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(string $agentId): array
    {
        global $livekitServerUrl, $apiKey, $apiSecret;

        $agent = $this->agents->findBasicById($agentId);
        if (!$agent) {
            throw new \RuntimeException('No voice_agents record found for id=' . $agentId, 404);
        }

        $phone = $agent['phone_number'] ?? '';
        if ($phone === '') {
            throw new \RuntimeException('Agent id or phone number is missing.', 400);
        }

        $debugLog = [];
        $debugLog[] = ['step' => 'refresh_metadata_start', 'id' => $agentId, 'phone' => $phone];

        $mysqli = \Tabbly\Inbound\Database\Database::connect();
        $stmt = $mysqli->prepare('SELECT * FROM voice_agents WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $mysqli->close();
            throw new \RuntimeException('DB prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param('s', $agentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $mysqli->close();

        if (!$row) {
            throw new \RuntimeException('No voice_agents record found for id=' . $agentId, 404);
        }

        $apiTools = isset($row['api_tools']) && $row['api_tools'] !== '' && $row['api_tools'] !== null
            ? $row['api_tools'] : null;
        $row['call_direction'] = 'inbound';
        $agentMetadataJson = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($agentMetadataJson === false) {
            throw new \RuntimeException('Failed to encode metadata: ' . json_last_error_msg());
        }

        $token = generateLiveKitToken($apiKey, $apiSecret);
        $ids = $this->inbound->getInboundIdsByPhone($phone);
        $ruleId = $ids['rule_id'];
        $trunkId = $ids['trunk_id'];

        $trunkName = PhoneUtil::trunkName($phone);
        $ruleName = PhoneUtil::dispatchRuleName($phone);
        $phonePattern = PhoneUtil::normalizeDigits($phone);

        if (!$ruleId || !$trunkId) {
            [$ruleId, $trunkId] = $this->findRuleAndTrunk(
                $livekitServerUrl,
                $token,
                $agentId,
                $phone,
                $trunkName,
                $ruleName,
                $phonePattern,
                $ruleId,
                $trunkId,
                $debugLog
            );
        }

        if (!$ruleId) {
            throw new \RuntimeException('Could not find existing dispatch rule for phone: ' . $phone, 404);
        }

        if (!$trunkId) {
            $trunkId = $this->findTrunkIdForPhone($livekitServerUrl, $token, $phone, $debugLog);
        }

        if (!$trunkId) {
            throw new \RuntimeException('Could not find trunk ID for phone number: ' . $phone, 404);
        }

        deleteSipDispatchRule($livekitServerUrl, $token, $ruleId, $debugLog);

        $newRuleResult = createSipDispatchRuleIndividual(
            $livekitServerUrl,
            $token,
            $ruleName,
            'central',
            $agentMetadataJson,
            $trunkId,
            $debugLog,
            $apiTools
        );

        $newRuleId = $newRuleResult['dispatch_rule']['sip_dispatch_rule_id']
            ?? ($newRuleResult['sip_dispatch_rule_id'] ?? ($newRuleResult['rule']['sip_dispatch_rule_id'] ?? null));

        if ($newRuleId && $trunkId) {
            $this->inbound->updateDispatchRuleId($phone, $newRuleId, $trunkId);
        }

        $result = [
            'action' => 'metadata_refreshed',
            'agent_id' => $agentId,
            'voice_agent_id' => $agentId,
            'phone_number' => $phone,
            'old_rule_id' => $ruleId,
            'inbound_dispatch_rule_id' => $newRuleId,
            'inbound_trunk_id' => $trunkId,
        ];

        if (Config::bool('APP_DEBUG')) {
            $result['debug'] = $debugLog;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $debugLog
     * @return array{0: ?string, 1: ?string}
     */
    private function findRuleAndTrunk(
        string $serverUrl,
        string $token,
        string $agentId,
        string $phone,
        string $trunkName,
        string $ruleName,
        string $phonePattern,
        ?string $ruleId,
        ?string $trunkId,
        array &$debugLog
    ): array {
        $allRules = listSipDispatchRules($serverUrl, $token, $debugLog);
        $rules = $this->normalizeRulesList($allRules);

        if ($ruleId && $trunkId) {
            return [$ruleId, $trunkId];
        }

        foreach ($rules as $rule) {
            if (($rule['name'] ?? '') === $ruleName) {
                $ruleId = $this->extractRuleId($rule);
                $trunkId = $this->extractTrunkIdFromRule($rule) ?? $trunkId;
                if ($ruleId) {
                    return [$ruleId, $trunkId];
                }
            }
        }

        foreach ($rules as $rule) {
            $name = $rule['name'] ?? '';
            if (str_contains($name, $trunkName) || str_contains($name, $phonePattern)) {
                $ruleId = $this->extractRuleId($rule);
                $trunkId = $this->extractTrunkIdFromRule($rule) ?? $trunkId;
                if ($ruleId) {
                    return [$ruleId, $trunkId];
                }
            }
        }

        $foundTrunkId = $this->findTrunkIdForPhone($serverUrl, $token, $phone, $debugLog);
        if ($foundTrunkId) {
            foreach ($rules as $rule) {
                $ruleTrunkIds = $rule['trunk_ids'] ?? [];
                if (isset($rule['trunk_ids']['set']) && is_array($rule['trunk_ids']['set'])) {
                    $ruleTrunkIds = $rule['trunk_ids']['set'];
                }
                if (in_array($foundTrunkId, $ruleTrunkIds, true)) {
                    $ruleId = $this->extractRuleId($rule);
                    if ($ruleId) {
                        return [$ruleId, $foundTrunkId];
                    }
                }
            }
        }

        foreach ($rules as $rule) {
            $agents = $rule['room_config']['agents'] ?? [];
            foreach ($agents as $agent) {
                $metadata = json_decode($agent['metadata'] ?? '', true);
                if ($metadata && isset($metadata['id']) && (string) $metadata['id'] === (string) $agentId) {
                    $ruleId = $this->extractRuleId($rule);
                    $trunkId = $this->extractTrunkIdFromRule($rule) ?? $trunkId;
                    if ($ruleId) {
                        return [$ruleId, $trunkId];
                    }
                }
            }
        }

        if (!$ruleId && count($rules) === 1) {
            $rule = $rules[0];
            $ruleId = $this->extractRuleId($rule);
            $trunkId = $this->extractTrunkIdFromRule($rule) ?? $trunkId;
        }

        return [$ruleId, $trunkId];
    }

    /**
     * @param list<array<string, mixed>> $debugLog
     */
    private function findTrunkIdForPhone(string $serverUrl, string $token, string $phone, array &$debugLog): ?string
    {
        $endpointList = rtrim($serverUrl, '/') . '/twirp/livekit.SIP/ListSIPInboundTrunk';
        $listRes = httpPostJson($endpointList, $token, (object) [], $debugLog);
        $trunks = [];
        if (isset($listRes['trunks']) && is_array($listRes['trunks'])) {
            $trunks = $listRes['trunks'];
        } elseif (is_array($listRes)) {
            $trunks = $listRes;
        }

        foreach ($trunks as $trunk) {
            $numbers = [];
            if (isset($trunk['numbers']) && is_array($trunk['numbers'])) {
                $numbers = array_merge($numbers, $trunk['numbers']);
            }
            if (isset($trunk['numbers']['set']) && is_array($trunk['numbers']['set'])) {
                $numbers = array_merge($numbers, $trunk['numbers']['set']);
            }
            if (in_array($phone, $numbers, true)) {
                return $trunk['sip_trunk_id'] ?? ($trunk['id'] ?? ($trunk['sid'] ?? null));
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeRulesList(array $allRules): array
    {
        if (isset($allRules['dispatch_rules']) && is_array($allRules['dispatch_rules'])) {
            $rules = $allRules['dispatch_rules'];
        } elseif (isset($allRules['rules']) && is_array($allRules['rules'])) {
            $rules = $allRules['rules'];
        } elseif (isset($allRules['data']) && is_array($allRules['data'])) {
            $rules = $allRules['data'];
        } elseif (is_array($allRules) && !isset($allRules['dispatch_rules'])) {
            $rules = $allRules;
        } else {
            $rules = [];
        }

        $unwrapped = [];
        foreach ($rules as $rule) {
            if (isset($rule['dispatch_rule']) && is_array($rule['dispatch_rule'])) {
                $unwrapped[] = array_merge($rule['dispatch_rule'], array_filter(
                    $rule,
                    static fn ($k) => $k !== 'dispatch_rule',
                    ARRAY_FILTER_USE_KEY
                ));
            } elseif (isset($rule['rule']) && is_array($rule['rule'])) {
                $unwrapped[] = array_merge($rule['rule'], array_filter(
                    $rule,
                    static fn ($k) => $k !== 'rule',
                    ARRAY_FILTER_USE_KEY
                ));
            } else {
                $unwrapped[] = $rule;
            }
        }

        return $unwrapped;
    }

    private function extractRuleId(array $rule): ?string
    {
        if (isset($rule['sip_dispatch_rule_id']) && $rule['sip_dispatch_rule_id'] !== '') {
            return (string) $rule['sip_dispatch_rule_id'];
        }
        if (isset($rule['id']) && $rule['id'] !== '') {
            return (string) $rule['id'];
        }
        if (isset($rule['dispatch_rule_id']) && $rule['dispatch_rule_id'] !== '') {
            return (string) $rule['dispatch_rule_id'];
        }
        if (isset($rule['dispatch_rule']['sip_dispatch_rule_id'])) {
            return (string) $rule['dispatch_rule']['sip_dispatch_rule_id'];
        }

        return $this->recursiveRuleIdSearch($rule);
    }

    private function recursiveRuleIdSearch(array $arr): ?string
    {
        foreach ($arr as $k => $v) {
            if (is_string($k) && (stripos($k, 'sip_dispatch_rule_id') !== false || stripos($k, 'dispatch_rule_id') !== false)) {
                if (is_string($v) && strlen($v) > 5) {
                    return $v;
                }
            }
            if (is_array($v)) {
                $found = $this->recursiveRuleIdSearch($v);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function extractTrunkIdFromRule(array $rule): ?string
    {
        if (isset($rule['trunk_ids']) && is_array($rule['trunk_ids']) && !empty($rule['trunk_ids'])) {
            $first = $rule['trunk_ids'][0] ?? null;
            if (is_string($first)) {
                return $first;
            }
        }
        if (isset($rule['trunk_ids']['set']) && is_array($rule['trunk_ids']['set']) && !empty($rule['trunk_ids']['set'])) {
            return (string) $rule['trunk_ids']['set'][0];
        }

        return null;
    }
}
