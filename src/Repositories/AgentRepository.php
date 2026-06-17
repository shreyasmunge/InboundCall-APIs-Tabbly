<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Repositories;

use Tabbly\Inbound\Database\Database;

final class AgentRepository
{
    public function findById(string $agentId): ?array
    {
        $mysqli = Database::connect();
        $stmt = $mysqli->prepare('SELECT * FROM voice_agents WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $mysqli->close();
            throw new \RuntimeException('Agent prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param('s', $agentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $mysqli->close();

        return $row ?: null;
    }

    public function findBasicById(string $agentId): ?array
    {
        $mysqli = Database::connect();
        $stmt = $mysqli->prepare('SELECT id, organization_id, agent_name, phone_number, custom_first_line FROM voice_agents WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $mysqli->close();
            throw new \RuntimeException('Agent prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param('s', $agentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $mysqli->close();

        return $row ?: null;
    }
}
