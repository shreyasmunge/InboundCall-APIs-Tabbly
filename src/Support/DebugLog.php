<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Support;

final class DebugLog
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    public function add(array $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->entries;
    }

    public function mergeInto(array &$legacyLog): void
    {
        foreach ($this->entries as $entry) {
            $legacyLog[] = $entry;
        }
    }
}
