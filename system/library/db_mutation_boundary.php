<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Store-scoped MySQL named lock + transaction boundary for multi-statement mutations.
 *
 * Uses the shared {@see DbConnection} so OpenCart setting rows and shop_cache participate together.
 */
final class DbMutationBoundary
{
    public const LOCK_TIMEOUT_SECONDS = 15;

    public function __construct(private DbConnection $db)
    {
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function runExclusive(string $lockName, callable $callback): mixed
    {
        $lockName = $this->normalizeLockName($lockName);
        $escaped = $this->db->escape($lockName);
        $acquired = $this->db->query(
            'SELECT GET_LOCK(\'' . $escaped . '\', ' . self::LOCK_TIMEOUT_SECONDS . ') AS `locked`'
        );
        $locked = is_object($acquired) ? (int) ($acquired->row['locked'] ?? 0) : 0;
        if ($locked !== 1) {
            throw new PersistenceException(
                'Unable to acquire exclusive mutation lock for SmartUCF credential persistence.'
            );
        }

        try {
            $this->db->query('START TRANSACTION');
            try {
                $result = $callback();
                $this->db->query('COMMIT');

                return $result;
            } catch (\Throwable $exception) {
                try {
                    $this->db->query('ROLLBACK');
                } catch (\Throwable $rollbackException) {
                    error_log(
                        '[mt_uni_credit] credential persistence ROLLBACK failed after mutation error: '
                        . DiagnosticPayloadRedactor::sanitizeLogMessage($rollbackException->getMessage())
                    );
                    throw new PersistenceException(
                        'SmartUCF credential persistence failed and database ROLLBACK also failed.',
                        0,
                        $exception
                    );
                }

                throw $exception;
            }
        } finally {
            try {
                $this->db->query('SELECT RELEASE_LOCK(\'' . $escaped . '\') AS `released`');
            } catch (\Throwable $releaseException) {
                error_log(
                    '[mt_uni_credit] credential persistence RELEASE_LOCK failed: '
                    . DiagnosticPayloadRedactor::sanitizeLogMessage($releaseException->getMessage())
                );
            }
        }
    }

    public static function smartUcfCredentialLockName(int $storeId, string $unicid): string
    {
        $unicidHash = substr(hash('sha256', trim($unicid)), 0, 24);

        return 'mtuc_sucf_cred_' . (int) $storeId . '_' . $unicidHash;
    }

    private function normalizeLockName(string $lockName): string
    {
        $lockName = trim($lockName);
        if ($lockName === '' || strlen($lockName) > 64) {
            throw new PersistenceValidationException('Mutation lock name must be 1–64 characters.');
        }

        return $lockName;
    }
}
