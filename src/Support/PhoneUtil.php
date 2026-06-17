<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Support;

final class PhoneUtil
{
    public const FREE_TIER_NUMBERS = ['918035736739'];

    public static function normalizeDigits(?string $phone): string
    {
        return preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
    }

    public static function isFreeTier(?string $phone): bool
    {
        return in_array(self::normalizeDigits($phone), self::FREE_TIER_NUMBERS, true);
    }

    public static function trunkName(string $phone): string
    {
        return 'trunk-' . preg_replace('/[^0-9+]/', '', $phone);
    }

    public static function dispatchRuleName(string $phone): string
    {
        return 'dispatch-for-' . self::trunkName($phone);
    }
}
