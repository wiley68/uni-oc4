<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Generalized durable financing attempt identity (Product, Cart, Checkout).
 */
final class FinancingAttemptRepository
{
    private const CP_BODY_AUTHORIZATION_PREFIX = 'cp_body_v1:';

    private DbConnection $db;

    private PersistenceClock $clock;

    public function __construct(DbConnection $db, ?PersistenceClock $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? new PersistenceClock();
        CpOriginGuard::ensureSchema($db);
    }

    public function database(): DbConnection
    {
        return $this->db;
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
        string $unicid,
        ?int $cartId = null,
        ?string $cartFingerprint = null,
        ?array $consentSeed = null
    ): array {
        if (!in_array($entryPoint, [OperationEntryPoint::PRODUCT, OperationEntryPoint::CART, OperationEntryPoint::CHECKOUT], true)) {
            throw new PersistenceValidationException('Submission tokens are issued only for product, cart, or checkout entry points.');
        }

        $this->validateIssueInputs($storeId, $entryPoint, $operationKeyHash, $actorBindingHash, $selectionHash, $cartId, $cartFingerprint, $unicid);
        if ($consentSeed === null) throw new PersistenceValidationException('New attempts require server-issued consent context.');

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
                    $cartFingerprint,
                    $unicid,
                    $consentSeed
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
        string $unicid,
        ?int $cartId = null,
        ?string $cartFingerprint = null,
        ?array $consentSeed = null
    ): array {
        return $this->issueWithSubmissionToken(
            $storeId, OperationEntryPoint::CHECKOUT, $operationKeyHash, $actorBindingHash,
            $selectionHash, $unicid, $cartId, $cartFingerprint, $consentSeed
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

    public static function isCpBodyAuthorization(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::CP_BODY_AUTHORIZATION_PREFIX);
    }

    public static function canPrepareCpSubmission(array $row): bool
    {
        $state = $row['state'] ?? null;
        $error = $row['last_error_class'] ?? null;

        return in_array($state, [FinancingAttemptState::ORDER_CREATED, FinancingAttemptState::CP_FAILED_RETRYABLE], true)
            && ($error === null || self::isCpBodyAuthorization($error)
                || ($state === FinancingAttemptState::CP_FAILED_RETRYABLE && $error === ControlPanelErrorClass::RECOVERY_FAILED));
    }

    public static function cpBodyAuthorization(array $payload): ?string
    {
        foreach (['price', 'parva', 'vnoska', 'gpr'] as $key) {
            if (!is_numeric($payload[$key] ?? null) || !is_finite((float) $payload[$key])) {
                return null;
            }
            $amount = (float) $payload[$key];
            // Encode the exact CP numeric value without depending on PHP's JSON float precision.
            $payload[$key] = bin2hex(pack('E', $amount == 0.0 ? 0.0 : $amount));
        }
        ksort($payload, SORT_STRING);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true);

        return self::CP_BODY_AUTHORIZATION_PREFIX . rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    public function reserveCpBodyAuthorization(array $row, string $owner, string $authorization): void
    {
        if (!self::canPrepareCpSubmission($row) || self::isCpBodyAuthorization($row['last_error_class'] ?? null)) {
            return;
        }

        $table = $this->tableName();
        $now = $this->clock->formatUtc($this->clock->now());
        $conditions = $this->cpSubmissionConditions($row);
        $lease = $this->cpLeaseCondition($row, $owner, $now);
        $expectedState = $this->db->escape($row['state']);
        $authorization = $this->db->escape($authorization);
        $this->db->query(
            "UPDATE `{$table}` SET `last_error_class` = '{$authorization}', `updated_at` = '{$now}'
             WHERE {$conditions} {$lease} AND `state` = '{$expectedState}'"
        );
    }

    public function persistCpPayload(int $attemptId, array $payload): bool
    {
        $authorization = self::cpBodyAuthorization($payload);
        if ($attemptId <= 0 || $authorization === null) {
            return false;
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $table = $this->tableName();
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $this->db->query(
            "UPDATE `{$table}`
             SET `cp_payload` = '" . $this->db->escape($json) . "',
                 `updated_at` = '" . $this->db->escape($updatedAt) . "'
             WHERE `attempt_id` = " . (int) $attemptId . "
               AND `state` = '" . FinancingAttemptState::ORDER_CREATED . "'
               AND `control_panel_order_id` IS NULL
               AND BINARY `last_error_class` = '" . $this->db->escape($authorization) . "'
               AND `cp_payload` IS NULL"
        );

        return $this->db->countAffected() === 1;
    }

    public function holdCpPayloadConflict(int $attemptId, int $storeId, int $orderId): void
    {
        $table = $this->tableName();
        $this->db->query(
            "UPDATE `{$table}`
             SET `state` = 'cp_outcome_unknown', `last_error_class` = 'cp_conflict',
                 `updated_at` = '" . $this->clock->formatUtc($this->clock->now()) . "'
             WHERE `attempt_id` = {$attemptId} AND `store_id` = {$storeId} AND `order_id` = {$orderId}
               AND `control_panel_order_id` IS NULL
               AND `state` IN ('order_created', 'cp_failed_retryable')"
        );
    }

    public function claimCpSubmission(array $row, string $owner): bool
    {
        $table = $this->tableName();
        $authorization = $row['last_error_class'] ?? null;
        if (!self::canPrepareCpSubmission($row) || !self::isCpBodyAuthorization($authorization)
            || !is_string($row['cp_payload'] ?? null)) {
            return false;
        }
        try {
            $body = json_decode($row['cp_payload'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($body) || self::cpBodyAuthorization($body) !== $authorization) {
            return false;
        }
        $now = $this->clock->formatUtc($this->clock->now());
        $state = $this->db->escape($row['state']);
        $conditions = $this->cpSubmissionConditions($row);
        $lease = $this->cpLeaseCondition($row, $owner, $now);
        $this->db->query(
            "UPDATE `{$table}` SET `state` = 'cp_submitting', `updated_at` = '{$now}'
             WHERE {$conditions} {$lease} AND `state` = '{$state}'"
        );
        if ($this->db->countAffected() !== 1) {
            return false;
        }

        // Only the caller that entered cp_submitting may dispatch, after confirming its lease and row.
        $lease = $this->cpLeaseCondition($row, $owner, $this->clock->formatUtc($this->clock->now()));
        $result = $this->db->query(
            "SELECT `attempt_id` FROM `{$table}` WHERE {$conditions} {$lease} AND `state` = 'cp_submitting'"
        );

        return is_object($result) && (int) ($result->num_rows ?? 0) === 1;
    }

    private function cpSubmissionConditions(array $row): string
    {
        $attemptId = (int) $row['attempt_id'];
        $storeId = (int) $row['store_id'];
        $orderId = (int) $row['order_id'];
        $entry = $this->db->escape($row['entry_point']);
        $operation = $this->db->escape($row['operation_key_hash']);
        $body = isset($row['cp_payload']) ? "= '" . $this->db->escape($row['cp_payload']) . "'" : 'IS NULL';
        $origin = $this->db->escape($row['cp_origin']);
        $consent = $this->db->escape($row['consent_context_json']);
        $error = isset($row['last_error_class']) ? "'" . $this->db->escape($row['last_error_class']) . "'" : 'NULL';

        return "`attempt_id` = {$attemptId} AND `store_id` = {$storeId} AND `order_id` = {$orderId}
               AND `entry_point` = '{$entry}' AND `operation_key_hash` = '{$operation}'
               AND `control_panel_order_id` IS NULL AND BINARY `last_error_class` <=> {$error}
               AND BINARY `cp_payload` {$body} AND BINARY `cp_origin` = '{$origin}'
               AND BINARY `consent_context_json` = '{$consent}'";
    }

    private function cpLeaseCondition(array $row, string $owner, string $at): string
    {
        $locks = $this->db->getPrefix() . PersistenceTableNames::OPERATION_LOCK;
        $storeId = (int) $row['store_id'];
        $entry = $this->db->escape($row['entry_point']);
        $operation = $this->db->escape($row['operation_key_hash']);
        $owner = $this->db->escape($owner);

        return "AND EXISTS (SELECT 1 FROM `{$locks}` WHERE `store_id` = {$storeId}
                   AND `entry_point` = '{$entry}' AND `operation_key_hash` = '{$operation}'
                   AND `owner_token` = '{$owner}' AND `expires_at` > '{$at}')";
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

    public function recordCpCreated(array $claimed, int $cpOrderId): bool
    {
        if ($cpOrderId <= 0) {
            return false;
        }

        return $this->recordCpDisposition($claimed, FinancingAttemptState::CP_CREATED, null, $cpOrderId);
    }

    public function recordCpFailure(array $claimed, string $errorClass, bool $definitive = false): bool
    {
        if (!ControlPanelErrorClass::isValid($errorClass) || ($definitive && $errorClass !== ControlPanelErrorClass::REJECTED)) {
            return false;
        }

        $state = $definitive ? FinancingAttemptState::TERMINAL_FAILED : FinancingAttemptState::CP_OUTCOME_UNKNOWN;

        return $this->recordCpDisposition($claimed, $state, $errorClass, null);
    }

    private function recordCpDisposition(array $claimed, string $state, ?string $errorClass, ?int $cpOrderId): bool
    {
        if (!self::isCpBodyAuthorization($claimed['last_error_class'] ?? null) || !is_string($claimed['cp_payload'] ?? null)) {
            return false;
        }

        // The lease may have expired during transport. The original dispatch snapshot still owns its result.
        $conditions = $this->cpSubmissionConditions($claimed);
        $table = $this->tableName();
        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $error = $errorClass === null ? 'NULL' : "'" . $this->db->escape($errorClass) . "'";
        $order = $cpOrderId ?? 'NULL';
        try {
            $this->db->query(
                "UPDATE `{$table}`
                 SET `state` = '{$state}', `last_error_class` = {$error},
                     `control_panel_order_id` = {$order}, `updated_at` = '{$updatedAt}'
                 WHERE {$conditions} AND `state` = 'cp_submitting'"
            );
            if ($this->db->countAffected() === 1) {
                return true;
            }
        } catch (\Exception) {
            // The update may have committed before the connection lost its acknowledgement.
        }

        try {
            $saved = $this->findById((int) $claimed['attempt_id']);
        } catch (\Exception) {
            return false;
        }
        if ($saved === null || $saved['state'] !== $state || ($saved['last_error_class'] ?? null) !== $errorClass
            || (isset($saved['control_panel_order_id']) ? (int) $saved['control_panel_order_id'] : null) !== $cpOrderId) {
            return false;
        }
        foreach (['store_id', 'order_id', 'entry_point', 'operation_key_hash', 'cp_payload', 'cp_origin', 'consent_context_json'] as $key) {
            if ($saved[$key] !== $claimed[$key]) {
                return false;
            }
        }

        return true;
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

        $result = $this->db->query(
            "SELECT * FROM `{$table}` WHERE `order_id` IS NULL AND `expires_at` IS NOT NULL
             AND `expires_at` <= '" . $this->db->escape($now) . "'
             AND `state` IN (" . implode(', ', $escapedStates) . ") LIMIT " . $limit
        );
        $deleted = 0;
        foreach (is_object($result) ? ($result->rows ?? []) : [] as $row) {
            $raw = $row['consent_context_json'] ?? null;
            $kind = ConsentContext::classify($raw);
            if (!in_array($kind, ['legacy_absent', 'issued'], true)) continue;
            if ($kind === 'issued') {
                try { ConsentContext::assertBinding(ConsentContext::decode($raw), $row); } catch (ProductFinancingFlowException) { continue; }
            }
            $evidence = $raw === null ? '`consent_context_json` IS NULL' : "BINARY `consent_context_json` = BINARY '" . $this->db->escape($raw) . "'";
            $this->db->query("DELETE FROM `{$table}` WHERE `attempt_id` = " . (int) $row['attempt_id'] . " AND `order_id` IS NULL AND {$evidence}");
            $deleted += $this->db->countAffected();
        }
        return $deleted;
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
        string $submissionToken,
        string $operationKeyHash,
        string $actorBindingHash,
        string $selectionHash,
        ?int $cartId,
        ?string $cartFingerprint,
        string $unicid,
        array $consentSeed
    ): array {
        $now = $this->clock->now();
        $createdAt = $this->clock->formatUtc($now);
        $expiresAt = $this->clock->formatUtc($now + SecurityConstants::FINANCING_ATTEMPT_ISSUED_TTL_SECONDS);
        $table = $this->tableName();

        $tokenSql = "'" . $this->db->escape($submissionToken) . "'";
        $cartIdSql = ($cartId !== null && $cartId > 0) ? (string) (int) $cartId : 'NULL';
        $cartFingerprintSql = ($cartFingerprint !== null && PersistenceHashValidator::isSha256Hex($cartFingerprint))
            ? "'" . $this->db->escape($cartFingerprint) . "'"
            : 'NULL';
        $unicidSql = "'" . $this->db->escape($unicid) . "'";

        $raw = ConsentContext::issue($consentSeed, [
            'store_id' => $storeId, 'entry_point' => $entryPoint, 'submission_token' => $submissionToken,
            'operation_key_hash' => $operationKeyHash, 'actor_binding_hash' => $actorBindingHash,
            'selection_hash' => $selectionHash, 'unicid' => $unicid,
            'created_at' => $createdAt, 'expires_at' => $expiresAt,
        ]);
        $consentSql = "'" . $this->db->escape($raw) . "'";

        $this->db->query(
            "INSERT INTO `{$table}`
                (`store_id`, `unicid`, `cp_origin`, `entry_point`, `submission_token`, `operation_key_hash`, `actor_binding_hash`,
                 `selection_hash`, `cart_id`, `cart_fingerprint`, `state`, `expires_at`, `created_at`, `updated_at`, `consent_context_json`)
             VALUES (
                " . (int) $storeId . ",
                {$unicidSql},
                '" . $this->db->escape(CpOriginGuard::current()) . "',
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
                '" . $this->db->escape($createdAt) . "',
                {$consentSql}
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
        ?string $cartFingerprint,
        string $unicid
    ): void {
        OpenCartStoreScope::require($storeId);
        if (!OperationEntryPoint::isValid($entryPoint)) {
            throw new PersistenceValidationException('Unsupported financing attempt entry point.');
        }
        $unicid = trim($unicid);
        if ($unicid === '' || strlen($unicid) > 64) {
            throw new PersistenceValidationException('Financing attempt UNICID is required for new attempts.');
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
