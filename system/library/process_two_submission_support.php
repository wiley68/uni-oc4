<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Persist Process 2 EGN/phone2 onto the financing attempt (encrypted).
 */
final class ProcessTwoSubmissionSupport
{
    /**
     * Validate Process 2 fields before claiming VALIDATING (so failures leave ISSUED retryable).
     *
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $posted
     */
    public static function validateIfRequired(
        array $shop,
        array $posted,
        bool $checkoutCopy = false
    ): ?ProcessTwoSensitiveData {
        if (!ShopConfigurationFlags::isSecondaryProcess($shop)) {
            return null;
        }

        return (new ProcessTwoFieldValidator())->validate($posted, $checkoutCopy);
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $posted
     */
    public static function validateAndPersist(
        array $shop,
        array $posted,
        ValidatedFinancingSubmission $submission,
        int $attemptId,
        DbConnection $db,
        bool $checkoutCopy = false,
        ?string $encryptionSecretOverride = null
    ): void {
        $data = self::validateIfRequired($shop, $posted, $checkoutCopy);
        if ($data === null) {
            return;
        }
        self::persist($submission, $attemptId, $db, $data, $encryptionSecretOverride);
    }

    public static function persist(
        ValidatedFinancingSubmission $submission,
        int $attemptId,
        DbConnection $db,
        ProcessTwoSensitiveData $data,
        ?string $encryptionSecretOverride = null
    ): void {
        try {
            $cipher = new ProcessTwoSensitiveCipher($encryptionSecretOverride);
            $encrypted = $cipher->encrypt($data);
        } catch (\Throwable $exception) {
            throw new ProductFinancingFlowException(
                'process2_encryption_unavailable',
                'Поръчката не може да бъде обработена в момента. Моля, опитайте отново.',
                [
                    'error_class' => 'process2_encryption_unavailable',
                    'recoverable' => '0',
                ],
                $exception
            );
        }

        $submission->process2Sensitive = $data;
        (new ProcessTwoLifecycleRepository($db))->persistSensitiveEncrypted(
            $attemptId,
            $encrypted
        );
    }
}
