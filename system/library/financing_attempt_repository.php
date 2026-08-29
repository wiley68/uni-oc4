<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Generalized durable financing attempt identity (Product, Cart, Checkout).
 */
final class FinancingAttemptRepository
{
    private DbConnection $db;

    private PersistenceClock $clock;

    public function __construct(DbConnection $db, ?PersistenceClock $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? new PersistenceClock();
    }

    /**
     * @return array<string, mixed>
     */
    public function issueWithSubmissionToken(
        int $storeId,
        string $entryPoint,
        string $operationKeyHash,
        string $actorBindingHash,
        string $selectionHash,
        ?int $cartId = null,
        ?string $cartFingerprint = null
    ): array {
        if (!in_array($entryPoint, [OperationEntryPoint::PRODUCT, OperationEntryPoint::CART, OperationEntryPoint::CHECKOUT], true)) {
            throw new PersistenceValidationException('Submission tokens are issued only for product, cart, or checkout entry points.');
        }

        $this->validateIssueInputs($storeId, $entryPoint, $operationKeyHash, $actorBindingHash, $selectionHash, $cartId, $cartFingerprint);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $token = SubmissionTokenGenerator::generate();
            try {
                return $this->insertAttempt(
                    $storeId,
                    $entryPoint,
                    $token,
                    $operationKeyHash,
                    $actorBindingHash,
                    $selectionHash,
                    $cartId,
                    $cartFingerprint
                );
            } catch (\Throwable $exception) {
                if (!self::isDuplicateKeyError($exception)) {
                    throw $exception;
                }
            }
        }

        throw new PersistenceException('Unable to issue a unique submission token.');
    }

    /**
     * @return array<string, mixed>
     */
    public function issueCheckoutAttempt(
        int $storeId,
        string $operationKeyHash,
        string $actorBindingHash,
        string $selectionHash,
        ?int $cartId = null,
        ?string $cartFingerprint = null
    ): array {
        $this->validateIssueInputs(
            $storeId,
            OperationEntryPoint::CHECKOUT,
            $operationKeyHash,
            $actorBindingHash,
            $selectionHash,
            $cartId,
            $cartFingerprint
        );

        return $this->insertAttempt(
            $storeId,
            OperationEntryPoint::CHECKOUT,
            null,
            $operationKeyHash,
            $actorBindingHash,
            $selectionHash,
            $cartId,
            $cartFingerprint
        );
    }

    public function transition(int $attemptId, string $expectedState, string $newState): bool
    {
        return $this->transitionFromStates($attemptId, [$expectedState], $newState);
    }

    /**
     * @param list<string> $expectedStates
     */
    public function transitionFromStates(int $attemptId, array $expectedStates, string $newState): bool
    {
        if ($attemptId <= 0 || $expectedStates === []) {
            return false;
        }
        foreach ($expectedStates as $state) {
            if (!FinancingAttemptState::isValid($state)) {
                throw new PersistenceValidationException('Invalid expected financing attempt state.');
            }
        }
        if (!FinancingAttemptState::isValid($newState)) {
            throw new PersistenceValidationException('Invalid target financing attempt state.');
        }

        $escapedStates = array_map(
            fn(string $state): string => "'" . $this->db->escape($state) . "'",
            $expectedStates
        );
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();

        $this->db->query(
            "UPDATE `{$table}`
             SET `state` = '" . $this->db->escape($newState) . "',
                 `updated_at` = '" . $this->db->escape($updatedAt) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `state` IN (" . implode(', ', $escapedStates) . ")"
        );

        return $this->db->countAffected() === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function persistCpPayload(int $attemptId, array $payload): bool
    {
        if ($attemptId <= 0) {
            return false;
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PersistenceValidationException('Control Panel payload could not be encoded.', 0, $exception);
        }

        $table = $this->tableName();
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $this->db->query(
            "UPDATE `{$table}`
             SET `cp_payload` = '" . $this->db->escape($json) . "',
                 `updated_at` = '" . $this->db->escape($updatedAt) . "'
             WHERE `attempt_id` = " . (int) $attemptId
        );

        return $this->db->countAffected() === 1;
    }

    public function persistControlPanelOrderId(int $attemptId, int $controlPanelOrderId): bool
    {
        if ($attemptId <= 0 || $controlPanelOrderId <= 0) {
            return false;
        }

        $table = $this->tableName();
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $this->db->query(
            "UPDATE `{$table}`
             SET `control_panel_order_id` = " . (int) $controlPanelOrderId . ",
                 `updated_at` = '" . $this->db->escape($updatedAt) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND (`control_panel_order_id` IS NULL OR `control_panel_order_id` = " . (int) $controlPanelOrderId . ")"
        );

        if ($this->db->countAffected() === 1) {
            return true;
        }

        $row = $this->findById($attemptId);

        return $row !== null
            && isset($row['control_panel_order_id'])
            && (int) $row['control_panel_order_id'] === $controlPanelOrderId;
    }

    public function persistLastErrorClass(int $attemptId, string $errorClass): bool
    {
        if ($attemptId <= 0 || !ControlPanelErrorClass::isValid($errorClass)) {
            return false;
        }

        $table = $this->tableName();
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $this->db->query(
            "UPDATE `{$table}`
             SET `last_error_class` = '" . $this->db->escape($errorClass) . "',
                 `updated_at` = '" . $this->db->escape($updatedAt) . "'
             WHERE `attempt_id` = " . (int) $attemptId
        );

        return $this->db->countAffected() === 1;
    }

    public function clearLastErrorClass(int $attemptId): bool
    {
        if ($attemptId <= 0) {
            return false;
        }

        $table = $this->tableName();
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $this->db->query(
            "UPDATE `{$table}`
             SET `last_error_class` = NULL,
                 `updated_at` = '" . $this->db->escape($updatedAt) . "'
             WHERE `attempt_id` = " . (int) $attemptId
        );

        return $this->db->countAffected() === 1;
    }

    public function attachOrder(int $attemptId, int $orderId): bool
    {
        if ($attemptId <= 0 || $orderId <= 0) {
            throw new PersistenceValidationException('Attempt and order identifiers are required.');
        }

        $table = $this->tableName();
        $updatedAt = $this->clock->formatUtc($this->clock->now());

        try {
            $this->db->query(
                "UPDATE `{$table}`
                 SET `order_id` = " . (int) $orderId . ",
                     `updated_at` = '" . $this->db->escape($updatedAt) . "'
                 WHERE `attempt_id` = " . (int) $attemptId . "
                   AND (`order_id` IS NULL OR `order_id` = " . (int) $orderId . ")"
            );
        } catch (\Throwable $exception) {
            if (self::isDuplicateKeyError($exception)) {
                throw new PersistenceConflictException('The order is already bound to another financing attempt.');
            }

            throw new PersistenceException('Order attachment failed.', 0, $exception);
        }

        if ($this->db->countAffected() === 1) {
            return true;
        }

        $row = $this->findById($attemptId);
        if ($row === null) {
            throw new PersistenceNotFoundException('Financing attempt was not found.');
        }
        if (isset($row['order_id']) && (int) $row['order_id'] === $orderId) {
            return true;
        }
        if (isset($row['order_id']) && $row['order_id'] !== null && (int) $row['order_id'] !== $orderId) {
            throw new PersistenceConflictException('The financing attempt is already bound to a different order.');
        }

        throw new PersistenceConflictException('The order could not be attached to the financing attempt.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(int $storeId, string $submissionToken): ?array
    {
        if (!OpenCartStoreScope::isValid($storeId) || !SubmissionTokenGenerator::isValidFormat($submissionToken)) {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT * FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `submission_token` = '" . $this->db->escape($submissionToken) . "'
             LIMIT 1"
        );

        return (is_object($result) && $result->num_rows === 1) ? $result->row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderId(int $storeId, int $orderId): ?array
    {
        if (!OpenCartStoreScope::isValid($storeId) || $orderId <= 0) {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT * FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `order_id` = " . (int) $orderId . "
             LIMIT 1"
        );

        return (is_object($result) && $result->num_rows === 1) ? $result->row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByOperationIdentity(int $storeId, string $entryPoint, string $operationKeyHash, string $state): ?array
    {
        if (!OpenCartStoreScope::isValid($storeId) || !OperationEntryPoint::isValid($entryPoint) || !FinancingAttemptState::isValid($state)) {
            return null;
        }
        PersistenceHashValidator::requireSha256Hex($operationKeyHash, 'operation_key_hash');

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT * FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `entry_point` = '" . $this->db->escape($entryPoint) . "'
               AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'
               AND `state` = '" . $this->db->escape($state) . "'
             ORDER BY `attempt_id` DESC
             LIMIT 1"
        );

        return (is_object($result) && $result->num_rows === 1) ? $result->row : null;
    }

    public function deleteExpiredPreOrderBatch(int $limit = SecurityConstants::CLEANUP_DEFAULT_BATCH_SIZE): int
    {
        $limit = max(1, min(1000, $limit));
        $table = $this->tableName();
        $now = $this->clock->formatUtc($this->clock->now());
        $states = [
            FinancingAttemptState::ISSUED,
            FinancingAttemptState::VALIDATING,
            FinancingAttemptState::TERMINAL_FAILED,
        ];
        $escapedStates = array_map(
            fn(string $state): string => "'" . $this->db->escape($state) . "'",
            $states
        );

        $this->db->query(
            "DELETE FROM `{$table}`
             WHERE `order_id` IS NULL
               AND `expires_at` IS NOT NULL
               AND `expires_at` <= '" . $this->db->escape($now) . "'
               AND `state` IN (" . implode(', ', $escapedStates) . ")
             LIMIT " . (int) $limit
        );

        return $this->db->countAffected();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $attemptId): ?array
    {
        if ($attemptId <= 0) {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT * FROM `{$table}` WHERE `attempt_id` = " . (int) $attemptId . " LIMIT 1"
        );

        return (is_object($result) && $result->num_rows === 1) ? $result->row : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function insertAttempt(
        int $storeId,
        string $entryPoint,
        ?string $submissionToken,
        string $operationKeyHash,
        string $actorBindingHash,
        string $selectionHash,
        ?int $cartId,
        ?string $cartFingerprint
    ): array {
        $now = $this->clock->now();
        $createdAt = $this->clock->formatUtc($now);
        $expiresAt = $this->clock->formatUtc($now + SecurityConstants::FINANCING_ATTEMPT_ISSUED_TTL_SECONDS);
        $table = $this->tableName();

        $tokenSql = $submissionToken === null
            ? 'NULL'
            : "'" . $this->db->escape($submissionToken) . "'";
        $cartIdSql = ($cartId !== null && $cartId > 0) ? (string) (int) $cartId : 'NULL';
        $cartFingerprintSql = ($cartFingerprint !== null && PersistenceHashValidator::isSha256Hex($cartFingerprint))
            ? "'" . $this->db->escape($cartFingerprint) . "'"
            : 'NULL';

        $this->db->query(
            "INSERT INTO `{$table}`
                (`store_id`, `entry_point`, `submission_token`, `operation_key_hash`, `actor_binding_hash`,
                 `selection_hash`, `cart_id`, `cart_fingerprint`, `state`, `expires_at`, `created_at`, `updated_at`)
             VALUES (
                " . (int) $storeId . ",
                '" . $this->db->escape($entryPoint) . "',
                {$tokenSql},
                '" . $this->db->escape($operationKeyHash) . "',
                '" . $this->db->escape($actorBindingHash) . "',
                '" . $this->db->escape($selectionHash) . "',
                {$cartIdSql},
                {$cartFingerprintSql},
                '" . $this->db->escape(FinancingAttemptState::ISSUED) . "',
                '" . $this->db->escape($expiresAt) . "',
                '" . $this->db->escape($createdAt) . "',
                '" . $this->db->escape($createdAt) . "'
             )"
        );

        $attemptId = $this->db->getLastId();
        $row = $this->findById($attemptId);
        if ($row === null) {
            throw new PersistenceException('Financing attempt could not be loaded after insert.');
        }

        return $row;
    }

    private function validateIssueInputs(
        int $storeId,
        string $entryPoint,
        string $operationKeyHash,
        string $actorBindingHash,
        string $selectionHash,
        ?int $cartId,
        ?string $cartFingerprint
    ): void {
        OpenCartStoreScope::require($storeId);
        if (!OperationEntryPoint::isValid($entryPoint)) {
            throw new PersistenceValidationException('Unsupported financing attempt entry point.');
        }
        PersistenceHashValidator::requireSha256Hex($operationKeyHash, 'operation_key_hash');
        PersistenceHashValidator::requireSha256Hex($actorBindingHash, 'actor_binding_hash');
        PersistenceHashValidator::requireSha256Hex($selectionHash, 'selection_hash');
        if ($cartId !== null && $cartId <= 0) {
            throw new PersistenceValidationException('Cart id must be positive when provided.');
        }
        if ($cartFingerprint !== null && $cartFingerprint !== '' && !PersistenceHashValidator::isSha256Hex($cartFingerprint)) {
            throw new PersistenceValidationException('Cart fingerprint must be SHA-256 hex when provided.');
        }
    }

    private function tableName(): string
    {
        return $this->db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
    }

    private static function isDuplicateKeyError(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, '1062')
            || str_contains($message, 'unique');
    }
}
