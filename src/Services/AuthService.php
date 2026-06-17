<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Services;

use Tabbly\Inbound\Config\Config;
use Tabbly\Inbound\Database\Database;

final class AuthService
{
    /**
     * @return array{organization_id: int|string, subscription_status: string}
     */
    public function resolveOrganization(string $organizationApiKey): ?array
    {
        $column = Config::get('ORGANIZATIONS_API_KEY_COLUMN', 'api_key') ?? 'api_key';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException('Invalid API key column configuration');
        }

        $mysqli = Database::connect();
        $sql = "SELECT organization_id, subscription_status FROM organizations WHERE `{$column}` = ? LIMIT 1";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $mysqli->close();
            throw new \RuntimeException('Auth prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('s', $organizationApiKey);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $mysqli->close();

        return $row ?: null;
    }
}
