<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * UTC datetime helpers for persistence repositories.
 */
final class PersistenceClock
{
    /** @var callable */
    private $now;

    /** @param callable():int|null $now */
    public function __construct(?callable $now = null)
    {
        $this->now = $now ?? static fn(): int => time();
    }

    public function now(): int
    {
        return (int) ($this->now)();
    }

    public function formatUtc(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
