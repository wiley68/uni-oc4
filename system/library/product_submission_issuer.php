<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProductSubmissionIssuer
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
        string $unicid = '',
        ?array $consentSeed = null
    ): array {
        if ($consentSeed === null) throw ConsentResolver::refusal();
        if ($preferredToken !== null && $preferredToken !== '') {
            $preferred = $this->attempts->findByToken($storeId, $preferredToken);
            if ($preferred !== null && $this->matchesIdentity($preferred, $actorBindingHash, $selectionHash, $consentSeed)) {
                if (!$this->isExpired($preferred) && $this->isReusableState((string) $preferred['state'])) {
                    return $preferred;
                }
            }
        }

        $existing = $this->attempts->findByOperationIdentity(
            $storeId,
            OperationEntryPoint::PRODUCT,
            $operationKeyHash,
            FinancingAttemptState::ISSUED
        );
        if ($existing !== null
            && $this->matchesIdentity($existing, $actorBindingHash, $selectionHash, $consentSeed)
            && !$this->isExpired($existing)
        ) {
            return $existing;
        }

        return $this->attempts->issueWithSubmissionToken(
            $storeId,
            OperationEntryPoint::PRODUCT,
            $operationKeyHash,
            $actorBindingHash,
            $selectionHash,
            $unicid,
            null,
            null,
            $consentSeed
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matchesIdentity(array $row, string $actorBindingHash, string $selectionHash, ?array $consentSeed): bool
    {
        if (!hash_equals((string) ($row['actor_binding_hash'] ?? ''), $actorBindingHash)) return false;
        if ($consentSeed !== null) {
            if (($row['consent_context_json'] ?? null) === null) return false; // unbound legacy issuance needs a fresh v1 attempt.
            $record = ConsentContext::decode($row['consent_context_json']);
            ConsentContext::assertBinding($record, $row); // invalid A must never silently become a newly issued B.
            if (ConsentContext::hash($record['issued']['context']) !== ConsentContext::hash($consentSeed)) return false;
        }
        return hash_equals((string) ($row['selection_hash'] ?? ''), $selectionHash);
    }

    /**
     * @param array<string, mixed> $row
     */
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
        return in_array($state, [FinancingAttemptState::ISSUED, FinancingAttemptState::VALIDATING], true);
    }
}
