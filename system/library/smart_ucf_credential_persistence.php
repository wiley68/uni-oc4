<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shared GET/push persistence: classify SmartUCF pair, encrypt, strip cache.
 *
 * Complete pair + sanitized cache mutate under store/UNICID named lock + DB transaction.
 */
final class SmartUcfCredentialPersistence
{
    public const ERROR_INCOMPLETE_PAIR = 'incomplete_credential_pair';

    public const ERROR_REQUIRED = 'required';

    private SmartUcfCredentialRepository $credentials;

    private ModuleSettingCipher $cipher;

    private ShopCacheRepository $cache;

    private DbMutationBoundary $boundary;

    public function __construct(
        SmartUcfCredentialRepository $credentials,
        ModuleSettingCipher $cipher,
        ShopCacheRepository $cache,
        DbConnection $db,
        ?DbMutationBoundary $boundary = null
    ) {
        $this->credentials = $credentials;
        $this->cipher = $cipher;
        $this->cache = $cache;
        $this->boundary = $boundary ?? new DbMutationBoundary($db);
    }

    /**
     * Validate pair contract, optionally rotate encrypted credentials, persist credential-free cache.
     *
     * @param array<string, mixed> $shopData full ingress snapshot (may include uni_user/uni_password)
     * @return array<string, mixed> sanitized shop_data written to general cache (no credentials)
     */
    public function persistValidatedSnapshot(int $storeId, string $unicid, array $shopData): array
    {
        $state = SmartUcfCredentialPairClassifier::classify($shopData);
        $process2 = ((int) ($shopData['uni_proces'] ?? 0)) === 1;

        if ($state === SmartUcfCredentialPairClassifier::INVALID) {
            throw new ShopSnapshotValidationException([
                ['path' => 'uni_user', 'code' => self::ERROR_INCOMPLETE_PAIR],
                ['path' => 'uni_password', 'code' => self::ERROR_INCOMPLETE_PAIR],
            ]);
        }

        if (!$process2 && $state !== SmartUcfCredentialPairClassifier::COMPLETE) {
            throw new ShopSnapshotValidationException([
                ['path' => 'uni_user', 'code' => self::ERROR_REQUIRED],
                ['path' => 'uni_password', 'code' => self::ERROR_REQUIRED],
            ]);
        }

        $sanitized = SmartUcfCredentialPairClassifier::stripFromSnapshot($shopData);
        $rotatePair = $state === SmartUcfCredentialPairClassifier::COMPLETE;

        $encryptedUser = null;
        $encryptedPassword = null;
        if ($rotatePair) {
            // Encrypt outside the transaction so crypto work does not hold the lock longer than needed.
            $encryptedUser = $this->cipher->encrypt(trim((string) $shopData['uni_user']));
            $encryptedPassword = $this->cipher->encrypt(trim((string) $shopData['uni_password']));
        }

        $lockName = DbMutationBoundary::smartUcfCredentialLockName($storeId, $unicid);

        return $this->boundary->runExclusive($lockName, function () use (
            $storeId,
            $unicid,
            $sanitized,
            $rotatePair,
            $encryptedUser,
            $encryptedPassword
        ): array {
            if ($rotatePair) {
                $this->credentials->replaceEncryptedPair(
                    $storeId,
                    (string) $encryptedUser,
                    (string) $encryptedPassword
                );
            }
            $this->cache->replaceValidated($storeId, $unicid, $sanitized);

            return $sanitized;
        });
    }
}
