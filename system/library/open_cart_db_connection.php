<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Wraps OpenCart's DB facade from admin/catalog models.
 */
final class OpenCartDbConnection implements DbConnection
{
    private object $db;

    private string $prefix;

    public function __construct(object $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    public function query(string $sql): mixed
    {
        return $this->db->query($sql);
    }

    public function escape(string $value): string
    {
        return $this->db->escape($value);
    }

    public function countAffected(): int
    {
        return (int) $this->db->countAffected();
    }

    public function getLastId(): int
    {
        return (int) $this->db->getLastId();
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }
}
