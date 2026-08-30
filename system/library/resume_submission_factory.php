<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Minimal validated submission for post-CP resume (Process 1 SmartUCF replay / Process 2 handoff).
 */
final class ResumeSubmissionFactory
{
    public static function create(
        string $entryPoint,
        int $storeId,
        ?string $submissionToken,
        string $operationKeyHash,
        int $localOrderId
    ): ValidatedFinancingSubmission {
        $customer = new FinancingCustomerData(0, 0, '-', '-', '', '');
        $address = new FinancingAddressData(
            0,
            '-',
            '-',
            '',
            '-',
            '',
            '-',
            '',
            '-',
            0,
            '-',
            0
        );
        $scheme = new AvailableScheme('standard', 'RESUME', 1, 0, null, ['installmentCount' => 1, 'coefficient' => 1.0]);
        $calculation = new CalculationResult(
            $scheme,
            0.0,
            new FirstInstallmentState(0.0, false, false),
            0.0,
            0.0,
            0.0,
            0.0,
            0.0
        );
        $draft = new OrderDraft(
            $storeId,
            '',
            '',
            '',
            $customer,
            $address,
            null,
            PaymentIdentity::paymentMethod(),
            null,
            [],
            [],
            0.0,
            0,
            '',
            0,
            '',
            1.0,
            '',
            '127.0.0.1'
        );

        return new ValidatedFinancingSubmission(
            $entryPoint,
            $storeId,
            $submissionToken,
            $operationKeyHash,
            null,
            $localOrderId,
            $customer,
            $address,
            null,
            $calculation,
            $draft,
            hash('sha256', 'resume'),
            hash('sha256', 'resume-cart'),
            '',
            gmdate('Y-m-d H:i:s'),
            'resume'
        );
    }
}
