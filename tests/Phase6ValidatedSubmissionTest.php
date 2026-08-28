<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\FinancingCustomerData;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceValidationException;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ValidatedFinancingSubmission;
use PHPUnit\Framework\TestCase;

final class Phase6ValidatedSubmissionTest extends TestCase
{
    public function testValidProductSubmission(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        self::assertSame(OperationEntryPoint::PRODUCT, $submission->entryPoint);
        self::assertSame(PaymentIdentity::optionCode(), $submission->orderDraft->paymentMethod['code']);
        self::assertCount(1, $submission->orderDraft->products);
    }

    public function testValidCartSubmission(): void
    {
        $submission = OrderMaterializationTestHarness::cartSubmission();
        self::assertSame(OperationEntryPoint::CART, $submission->entryPoint);
        self::assertCount(2, $submission->orderDraft->products);
        self::assertNotNull($submission->orderDraft->shippingMethod);
    }

    public function testValidCheckoutSubmission(): void
    {
        $submission = OrderMaterializationTestHarness::checkoutSubmissionForOrder(50001);
        self::assertSame(OperationEntryPoint::CHECKOUT, $submission->entryPoint);
        self::assertSame(50001, $submission->existingOrderId);
        self::assertNull($submission->submissionToken);
    }

    public function testUnsupportedEntryPointRejected(): void
    {
        $this->expectException(PersistenceValidationException::class);
        new ValidatedFinancingSubmission(
            'popup',
            1,
            null,
            hash('sha256', 'x'),
            null,
            null,
            new FinancingCustomerData(0, 1, 'A', 'B', 'a@b.c', '1'),
            OrderMaterializationTestHarness::address(),
            null,
            OrderMaterializationTestHarness::calculation(),
            OrderMaterializationTestHarness::productSubmission()->orderDraft,
            hash('sha256', 'sel'),
            hash('sha256', 'fp'),
            'unicid',
            '2026-01-01',
            'test'
        );
    }

    public function testCustomerDtoDoesNotExposeEgnField(): void
    {
        $customer = OrderMaterializationTestHarness::customer();
        self::assertObjectNotHasProperty('egn', $customer);
        self::assertObjectNotHasProperty('personalId', $customer);
    }
}
