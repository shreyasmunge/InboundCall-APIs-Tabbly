<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Support;

final class ApiResponse
{
    public static function success(array $data, int $httpCode = 200): void
    {
        self::send([
            'status' => 'success',
            'data' => $data,
        ], $httpCode);
    }

    public static function error(string $message, int $httpCode = 400, ?array $extra = null): void
    {
        $body = [
            'status' => 'error',
            'message' => $message,
        ];
        if ($extra !== null && ConfigBool::appDebug()) {
            $body['debug'] = $extra;
        }
        self::send($body, $httpCode);
    }

    private static function send(array $body, int $httpCode): void
    {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

final class ConfigBool
{
    public static function appDebug(): bool
    {
        return \Tabbly\Inbound\Config\Config::bool('APP_DEBUG', false);
    }
}
