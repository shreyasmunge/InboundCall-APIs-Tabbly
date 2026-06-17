<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Legacy;

use Tabbly\Inbound\Config\Config;

final class LegacyBootstrap
{
    private static bool $booted = false;

    public static function init(): void
    {
        if (self::$booted) {
            return;
        }

        global $livekitServerUrl, $apiKey, $apiSecret, $livekitSipEndpoint;
        global $plivoAuthId, $plivoAuthToken, $plivoApiUrl;

        $livekitServerUrl = Config::get('LIVEKIT_SERVER_URL', '');
        $apiKey = Config::get('LIVEKIT_API_KEY', '');
        $apiSecret = Config::get('LIVEKIT_API_SECRET', '');
        $livekitSipEndpoint = Config::get('LIVEKIT_SIP_ENDPOINT', '');
        $plivoAuthId = Config::get('PLIVO_AUTH_ID', '');
        $plivoAuthToken = Config::get('PLIVO_AUTH_TOKEN', '');
        $plivoApiUrl = 'https://api.plivo.com/v1/Account';

        require_once __DIR__ . '/inbound_helpers.php';
        self::$booted = true;
    }
}
