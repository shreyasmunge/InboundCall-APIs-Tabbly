<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Repositories;

use Tabbly\Inbound\Database\Database;

final class InboundRepository
{
    public function getInboundIdsByPhone(string $phone): array
    {
        $mysqli = @Database::connect();
        $trunkId = null;
        $ruleId = null;

        $stmt = $mysqli->prepare('SELECT inbound_trunk_id, inbound_dispatch_rule_id FROM agents_phone_numbers WHERE phone_number = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $phone);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $trunkId = $row['inbound_trunk_id'] ?? null;
                    $ruleId = $row['inbound_dispatch_rule_id'] ?? null;
                }
            }
            $stmt->close();
        }
        $mysqli->close();

        return ['trunk_id' => $trunkId, 'rule_id' => $ruleId];
    }

    public function isInboundActive(string $phone, string $agentId): bool
    {
        $ids = $this->getInboundIdsByPhone($phone);
        if (!empty($ids['trunk_id']) && !empty($ids['rule_id'])) {
            return true;
        }

        $mysqli = Database::connect();
        $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM agent_call_logs WHERE use_agent_id = ? AND participant_identity = ?');
        if (!$stmt) {
            $mysqli->close();
            return false;
        }
        $stmt->bind_param('ss', $agentId, $phone);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $mysqli->close();

        return $row && (int) $row['cnt'] > 0;
    }

    public function isDuplicateInboundPhone(string $phone, int|string $organizationId): bool
    {
        $mysqli = Database::connect();

        $sqlCheck = '
            SELECT COUNT(*) AS cnt
            FROM agents_phone_numbers
            WHERE phone_number = ?
            AND inbound_trunk_id IS NOT NULL
            AND inbound_dispatch_rule_id IS NOT NULL
        ';
        $stmt = $mysqli->prepare($sqlCheck);
        if ($stmt) {
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row && (int) $row['cnt'] > 0) {
                $mysqli->close();
                return true;
            }
        }

        $sqlFallback = '
            SELECT COUNT(*) AS cnt
            FROM agent_call_logs acl
            INNER JOIN voice_agents va ON acl.use_agent_id = va.id
            WHERE acl.participant_identity = ?
            AND va.organization_id = ?
        ';
        $stmt2 = $mysqli->prepare($sqlFallback);
        if ($stmt2) {
            $stmt2->bind_param('ss', $phone, $organizationId);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            $row2 = $res2 ? $res2->fetch_assoc() : null;
            $stmt2->close();
            $mysqli->close();
            return $row2 && (int) $row2['cnt'] > 0;
        }

        $mysqli->close();
        return false;
    }

    public function clearInboundIds(string $phone): void
    {
        $mysqli = @Database::connect();
        if ($mysqli->connect_errno) {
            return;
        }
        $stmt = $mysqli->prepare('UPDATE agents_phone_numbers SET inbound_trunk_id = NULL, inbound_dispatch_rule_id = NULL WHERE phone_number = ?');
        if ($stmt) {
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $stmt->close();
        }
        $mysqli->close();
    }

    public function deleteCallLog(string $agentId, string $phone): void
    {
        $mysqli = @Database::connect();
        if ($mysqli->connect_errno) {
            return;
        }
        $stmt = $mysqli->prepare('DELETE FROM agent_call_logs WHERE use_agent_id = ? AND participant_identity = ?');
        if ($stmt) {
            $stmt->bind_param('ss', $agentId, $phone);
            $stmt->execute();
            $stmt->close();
        }
        $mysqli->close();
    }

    public function storeInboundIds(string $phone, string $trunkId, string $ruleId): void
    {
        $mysqli = @Database::connect();
        if ($mysqli->connect_errno) {
            return;
        }
        $stmt = $mysqli->prepare('
            UPDATE agents_phone_numbers
            SET inbound_trunk_id = ?, inbound_dispatch_rule_id = ?
            WHERE phone_number = ?
        ');
        if ($stmt) {
            $stmt->bind_param('sss', $trunkId, $ruleId, $phone);
            $stmt->execute();
            $stmt->close();
        }
        $mysqli->close();
    }

    public function updateDispatchRuleId(string $phone, string $ruleId, string $trunkId): void
    {
        $mysqli = @Database::connect();
        if ($mysqli->connect_errno) {
            return;
        }
        $stmt = $mysqli->prepare('
            UPDATE agents_phone_numbers
            SET inbound_dispatch_rule_id = ?, inbound_trunk_id = ?
            WHERE phone_number = ?
        ');
        if ($stmt) {
            $stmt->bind_param('sss', $ruleId, $trunkId, $phone);
            $stmt->execute();
            $stmt->close();
        }
        $mysqli->close();
    }

    public function insertCallLog(string $phone, string $agentId, ?string $customFirstLine): void
    {
        $mysqli = @Database::connect();
        if ($mysqli->connect_errno) {
            return;
        }
        $stmt = $mysqli->prepare('INSERT INTO agent_call_logs (participant_identity, called_time, use_agent_id, custom_first_line) VALUES (?, UTC_TIMESTAMP(), ?, ?)');
        if ($stmt) {
            $custom = $customFirstLine ?? '';
            $stmt->bind_param('sss', $phone, $agentId, $custom);
            $stmt->execute();
            $stmt->close();
        }
        $mysqli->close();
    }
}
