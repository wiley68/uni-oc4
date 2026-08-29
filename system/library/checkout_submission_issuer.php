<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CheckoutSubmissionIssuer
{
    public function __construct(
        private FinancingAttemptRepository $attempts,
        private PersistenceClock $clock
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function issueOrReuse(
        int $storeId,
        string $operationKeyHash,
        string $actorBindingHash,
        string $selectionHash,
        ?string $preferredToken = null,
        ?int $cartId = null,
        ?string $cartFingerprint = null
    ): array {
        if ($preferredToken !== null && $preferredToken !== '') {
            $preferred = $this->attempts->findByToken($storeId, $preferredToken);
            if ($preferred !== null && $this->matchesIdentity($preferred, $actorBindingHash, $selectionHash)) {
                if (!$this->isExpired($preferred) && $this->isReusableState((string) $preferred['state'])) {
                    return $preferred;
                }
            }
        }

        $existing = $this->attempts->findByOperationIdentity(
            $storeId,
            OperationEntryPoint::CHECKOUT,
            $operationKeyHash,
            FinancingAttemptState::ISSUED
        );
        if ($existing !== null
            && $this->matchesIdentity($existing, $actorBindingHash, $selectionHash)
            && !$this->isExpired($existing)
        ) {
            return $existing;
        }

        return $this->attempts->issueWithSubmissionToken(
            $storeId,
            OperationEntryPoint::CHECKOUT,
            $operationKeyHash,
            $actorBindingHash,
            $selectionHash,
            $cartId,
            $cartFingerprint
        );
    }

    /** @param array<string, mixed> $row */
    private function matchesIdentity(array $row, string $actorBindingHash, string $selectionHash): bool
    {
        return hash_equals((string) ($row['actor_binding_hash'] ?? ''), $actorBindingHash)
            && hash_equals((string) ($row['selection_hash'] ?? ''), $selectionHash);
    }

    /** @param array<string, mixed> $row */
    private function isExpired(array $row): bool
    {
        $expiresAt = (string) ($row['expires_at'] ?? '');
        if ($expiresAt === '') {
            return false;
        }
        $expires = strtotime($expiresAt . ' UTC');
        if ($expires === false) {
            return false;
        }

        return $this->clock->now() >= $expires;
    }

    private function isReusableState(string $state): bool
    {
        return in_array($state, [FinancingAttemptState::ISSUED, FinancingAttemptState::ORDER_CREATED], true);
    }
}
