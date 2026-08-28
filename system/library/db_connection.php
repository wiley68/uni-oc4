<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Minimal DB abstraction for centralized repositories (OpenCart DB or test mysqli).
 */
interface DbConnection
{
    public function query(string $sql): mixed;

    public function escape(string $value): string;

    public function countAffected(): int;

    public function getLastId(): int;

    public function getPrefix(): string;
}
