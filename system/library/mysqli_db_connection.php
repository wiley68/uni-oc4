<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Direct mysqli connection for integration tests (optional).
 */
final class MysqliDbConnection implements DbConnection
{
    private \mysqli $mysqli;

    private string $prefix;

    public function __construct(\mysqli $mysqli, string $prefix)
    {
        $this->mysqli = $mysqli;
        $this->prefix = $prefix;
    }

    public function query(string $sql): mixed
    {
        $result = $this->mysqli->query($sql);
        if ($result === false) {
            throw new PersistenceException('Database query failed: ' . $this->mysqli->error);
        }

        if ($result instanceof \mysqli_result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            $object = new \stdClass();
            $object->num_rows = count($rows);
            $object->row = $rows[0] ?? [];
            $object->rows = $rows;

            return $object;
        }

        return true;
    }

    public function escape(string $value): string
    {
        return $this->mysqli->real_escape_string($value);
    }

    public function countAffected(): int
    {
        return $this->mysqli->affected_rows;
    }

    public function getLastId(): int
    {
        return (int) $this->mysqli->insert_id;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }
}
