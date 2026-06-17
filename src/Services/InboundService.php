<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Services;

use Tabbly\Inbound\Config\Config;
use Tabbly\Inbound\Legacy\LegacyBootstrap;
use Tabbly\Inbound\Repositories\AgentRepository;
use Tabbly\Inbound\Repositories\InboundRepository;
use Tabbly\Inbound\Support\PhoneUtil;

final class InboundService
{
    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly InboundRepository $inbound = new InboundRepository(),
        private readonly RefreshMetadataService $refreshService = new RefreshMetadataService(),
    ) {
        LegacyBootstrap::init();
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(string $agentId): array
    {
        $agent = $this->agents->findBasicById($agentId);
        if (!$agent) {
            throw new \RuntimeException('Agent not found for the given id.', 404);
        }

        $phone = $agent['phone_number'] ?? '';
        if ($phone === '' || $phone === null) {
            throw new \RuntimeException('No phone number is attached to this agent yet.', 400);
        }

        $ids = $this->inbound->getInboundIdsByPhone($phone);
        $active = $this->inbound->isInboundActive($phone, $agentId);

        return [
            'agent_id' => $agentId,
            'agent_name' => $agent['agent_name'] ?? '',
            'phone_number' => $phone,
            'custom_first_line' => $agent['custom_first_line'] ?? '',
            'inbound_active' => $active,
            'inbound_trunk_id' => $ids['trunk_id'],
            'inbound_dispatch_rule_id' => $ids['rule_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $agentId, int|string $organizationId): array
    {
        global $livekitServerUrl, $apiKey, $apiSecret, $plivoAuthId, $plivoAuthToken;

        $agent = $this->agents->findBasicById($agentId);
        if (!$agent) {
            throw new \RuntimeException('No voice_agents record found for id=' . $agentId, 404);
        }

        $phone = $agent['phone_number'] ?? '';
        if ($phone === '' || $phone === null) {
            throw new \RuntimeException('Agent id or phone number is missing.', 400);
        }

        if ($this->inbound->isDuplicateInboundPhone($phone, $organizationId)) {
            throw new \RuntimeException(
                'This phone number is already configured for inbound calling with another agent. Each phone number can only be used once.',
                409
            );
        }

        $debugLog = [];
        $debugLog[] = ['step' => '1_start', 'id' => $agentId, 'phone' => $phone];

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
        $trunkName = PhoneUtil::trunkName($phone);
        $createdTrunkId = null;
        $plivoEndpointId = null;
        $plivoEndpointCreated = false;

        try {
            deleteInboundTrunksForNumber($livekitServerUrl, $token, $phone, $debugLog);
        } catch (\Exception $e) {
            $debugLog[] = ['step' => '6b_delete_trunks_error', 'phone' => $phone, 'error' => $e->getMessage()];
        }

        try {
            $createdTrunk = createInboundTrunk($livekitServerUrl, $token, $trunkName, $phone, $debugLog);
            $createdTrunkId = $this->extractTrunkId($createdTrunk);
        } catch (\Exception $e) {
            $createdTrunkId = $this->handleTrunkConflict($e, $livekitServerUrl, $token, $trunkName, $phone, $debugLog);
        }

        if (!empty($plivoAuthId) && !empty($plivoAuthToken)) {
            try {
                $livekitSipEndpoint = getLiveKitSipEndpoint($livekitServerUrl, $debugLog);
                $plivoTrunkResult = createPlivoInboundTrunk($trunkName, $livekitSipEndpoint, $debugLog);
                $plivoTrunkId = $this->extractPlivoTrunkId($plivoTrunkResult);
                if ($plivoTrunkId) {
                    $plivoEndpointCreated = true;
                    $plivoEndpointId = $plivoTrunkId;
                    try {
                        connectPhoneNumberToTrunk($phone, $plivoTrunkId, $debugLog);
                        getPlivoPhoneNumber($phone, $debugLog);
                    } catch (\Exception $e) {
                        $debugLog[] = ['step' => '12_plivo_phone_error', 'error' => $e->getMessage()];
                    }
                }
            } catch (\Exception $e) {
                $debugLog[] = ['step' => 'plivo_trunk_error', 'error' => $e->getMessage(), 'note' => 'Plivo integration failed but continuing with LiveKit'];
            }
        }

        $agentName = 'central';
        $ruleName = PhoneUtil::dispatchRuleName($phone);
        $dispatch = createSipDispatchRuleIndividual(
            $livekitServerUrl,
            $token,
            $ruleName,
            $agentName,
            $agentMetadataJson,
            $createdTrunkId,
            $debugLog,
            $apiTools
        );

        $createdDispatchRuleId = $this->extractDispatchRuleId($dispatch);

        if ($createdTrunkId && $createdDispatchRuleId) {
            $this->inbound->storeInboundIds($phone, $createdTrunkId, $createdDispatchRuleId);
            $this->inbound->insertCallLog($phone, $agentId, $agent['custom_first_line'] ?? null);
        }

        $result = [
            'action' => 'created',
            'agent_id' => $agentId,
            'voice_agent_id' => $agentId,
            'phone_number' => $phone,
            'inbound_trunk_id' => $createdTrunkId,
            'inbound_dispatch_rule_id' => $createdDispatchRuleId,
            'plivo_trunk_id' => $plivoEndpointId,
            'plivo_trunk_created' => $plivoEndpointCreated,
            'inbound_active' => true,
        ];

        if (Config::bool('APP_DEBUG')) {
            $result['debug'] = $debugLog;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshMetadata(string $agentId): array
    {
        return $this->refreshService->refresh($agentId);
    }

    /**
     * @return array<string, mixed>
     */
    public function disable(string $agentId): array
    {
        global $livekitServerUrl, $apiKey, $apiSecret, $plivoAuthId, $plivoAuthToken;

        $agent = $this->agents->findBasicById($agentId);
        if (!$agent) {
            throw new \RuntimeException('Agent not found.', 404);
        }

        $phone = $agent['phone_number'] ?? '';
        if ($phone === '') {
            throw new \RuntimeException('Agent id or phone number is missing.', 400);
        }

        $debugLog = [];
        $ids = $this->inbound->getInboundIdsByPhone($phone);
        $storedTrunkId = $ids['trunk_id'];
        $storedRuleId = $ids['rule_id'];

        $this->inbound->clearInboundIds($phone);
        $this->inbound->deleteCallLog($agentId, $phone);

        $token = generateLiveKitToken($apiKey, $apiSecret);

        if ($storedRuleId) {
            try {
                deleteSipDispatchRule($livekitServerUrl, $token, $storedRuleId, $debugLog);
            } catch (\Exception $e) {
                $debugLog[] = ['step' => 'disable_dispatch_rule_delete_error', 'error' => $e->getMessage()];
            }
        }

        try {
            deleteInboundTrunksForNumber($livekitServerUrl, $token, $phone, $debugLog);
        } catch (\Exception $e) {
            $debugLog[] = ['step' => 'disable_trunk_delete_error', 'error' => $e->getMessage()];
        }

        if ($storedTrunkId) {
            try {
                deleteDispatchRulesForTrunk($livekitServerUrl, $token, $storedTrunkId, $debugLog);
            } catch (\Exception $e) {
                $debugLog[] = ['step' => 'disable_dispatch_rules_by_trunk_error', 'error' => $e->getMessage()];
            }
        } else {
            try {
                deleteDispatchRulesForNumber($livekitServerUrl, $token, $phone, $debugLog);
            } catch (\Exception $e) {
                $debugLog[] = ['step' => 'disable_dispatch_rules_by_phone_error', 'error' => $e->getMessage()];
            }
        }

        if (!empty($plivoAuthId) && !empty($plivoAuthToken)) {
            try {
                $phoneInfo = getPlivoPhoneNumber($phone, $debugLog);
                if ($phoneInfo && !empty($phoneInfo['inbound_trunk_id'])) {
                    $disconnectData = ['app_id' => ''];
                    plivoApiRequest('POST', 'Number/' . preg_replace('/[^0-9]/', '', $phone) . '/', $disconnectData, $debugLog);
                    deletePlivoTrunk($phoneInfo['inbound_trunk_id'], $debugLog);
                } else {
                    $trunks = listPlivoTrunks($debugLog);
                    $phonePattern = preg_replace('/[^0-9]/', '', $phone);
                    $trunkList = $trunks['trunks'] ?? (is_array($trunks) ? $trunks : []);
                    foreach ($trunkList as $trunk) {
                        $trunkName = $trunk['name'] ?? '';
                        if (str_contains($trunkName, 'trunk-' . $phonePattern)) {
                            $trunkId = $trunk['trunk_id'] ?? ($trunk['id'] ?? null);
                            if ($trunkId) {
                                deletePlivoTrunk($trunkId, $debugLog);
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $debugLog[] = ['step' => 'disable_plivo_error', 'error' => $e->getMessage()];
            }
        }

        $result = [
            'action' => 'disabled',
            'agent_id' => $agentId,
            'voice_agent_id' => $agentId,
            'phone_number' => $phone,
            'inbound_active' => false,
        ];

        if (Config::bool('APP_DEBUG')) {
            $result['debug'] = $debugLog;
        }

        return $result;
    }

    private function extractTrunkId(?array $createdTrunk): ?string
    {
        if (!$createdTrunk) {
            return null;
        }
        if (isset($createdTrunk['inbound_trunk'])) {
            $trunkData = $createdTrunk['inbound_trunk'];
        } elseif (isset($createdTrunk['trunk'])) {
            $trunkData = $createdTrunk['trunk'];
        } else {
            $trunkData = $createdTrunk;
        }

        return $trunkData['sid'] ?? ($trunkData['sip_trunk_id'] ?? ($trunkData['id'] ?? null));
    }

    private function extractPlivoTrunkId(array $result): ?string
    {
        return $result['trunk_id']
            ?? ($result['id'] ?? ($result['trunk']['trunk_id'] ?? ($result['trunk']['id'] ?? null)));
    }

    private function extractDispatchRuleId(array $dispatch): ?string
    {
        return $dispatch['sip_dispatch_rule_id']
            ?? ($dispatch['dispatch_rule']['sip_dispatch_rule_id'] ?? ($dispatch['id'] ?? null));
    }

    /**
     * @param list<array<string, mixed>> $debugLog
     */
    private function handleTrunkConflict(
        \Exception $e,
        string $livekitServerUrl,
        string $token,
        string $trunkName,
        string $phone,
        array &$debugLog
    ): ?string {
        $errorMessage = $e->getMessage();
        if (!str_contains($errorMessage, 'Conflicting inbound SIP Trunks')) {
            throw new \RuntimeException('LiveKit trunk creation failed: ' . $errorMessage, 502);
        }

        $trunkId = null;
        if (preg_match('/"ST_([A-Za-z0-9_]+)"/', $errorMessage, $matches)) {
            $trunkId = 'ST_' . $matches[1];
        } elseif (preg_match('/\\\\"ST_([A-Za-z0-9_]+)\\\\"/', $errorMessage, $matches)) {
            $trunkId = 'ST_' . $matches[1];
        } elseif (preg_match('/and\s+["\']?([ST_][A-Za-z0-9_]+)["\']?/', $errorMessage, $matches)) {
            $trunkId = $matches[1];
        } elseif (preg_match('/(ST_[A-Za-z0-9_]+)/', $errorMessage, $matches)) {
            $trunkId = $matches[1];
        }

        if (!$trunkId) {
            throw new \RuntimeException('LiveKit trunk creation failed: ' . $errorMessage, 502);
        }

        deleteSipTrunkById($livekitServerUrl, $token, $trunkId, $debugLog);
        sleep(1);
        $createdTrunk = createInboundTrunk($livekitServerUrl, $token, $trunkName, $phone, $debugLog);

        return $this->extractTrunkId($createdTrunk);
    }
}
