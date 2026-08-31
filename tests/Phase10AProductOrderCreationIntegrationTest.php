<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductSubmissionIssuer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Phase 10A integration — Product submit creates one visible local order.
 */
#[Group('integration')]
final class Phase10AProductOrderCreationIntegrationTest extends TestCase
{
    private FinancingAttemptRepository $attempts;

    protected function setUp(): void
    {
        if (!getenv('MT_UNI_CREDIT_INTEGRATION')) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $this->attempts = new FinancingAttemptRepository(PersistenceIntegrationHarness::connection());
    }

    public function testProductSubmitCreatesOneOrderWithAwaitingStatusCorrelationAndPaymentIdentity(): void
    {
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders);
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        $actor = ProductFinancingTestHarness::actorBinding();
        $scheme = ProductFinancingTestHarness::defaultSchemeSelection();
        $selection = ProductFinancingTestHarness::selectionHash(
            $line,
            $scheme['scheme_key'],
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['first_installment'],
            $actor
        );
        $operation = ProductOperationIdentity::hash(ProductFinancingTestHarness::STORE_ID, 42, [], 1, 'BGN');
        $attempt = (new ProductSubmissionIssuer($this->attempts, new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock()))
            ->issueOrReuse(ProductFinancingTestHarness::STORE_ID, $operation, $actor, $selection);

        $result = $service->submit(
            array_replace(ProductFinancingTestHarness::shop(), ['uni_proces' => 1]),
            ProductFinancingTestHarness::STORE_ID,
            (string) $attempt['submission_token'],
            $actor,
            'sess-a',
            0,
            1,
            42,
            1,
            [],
            'BGN',
            'standard',
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['scheme_key'],
            $scheme['first_installment'],
            array_replace(ProductFinancingTestHarness::validPostedCustomer(), ['egn' => '1990011599', 'phone2' => '0888123456']),
            'test-unicid',
            '2026-08-28 12:00:00',
            1,
            'bg-bg',
            1,
            1.0,
            'Store',
            'https://example.test/',
            'INV-',
            LockOwnerTokenGenerator::generate()
        );

        self::assertTrue($result->success);
        self::assertSame('process2_prepared', $result->step);
        self::assertNotNull($result->orderId);
        self::assertSame(1, $orders->addOrderCallCount());

        $order = $orders->getOrder((int) $result->orderId);
        self::assertSame(OrderMaterializationTestHarness::TEST_AWAITING_STATUS_ID, (int) $order['order_status_id']);
        self::assertSame(0, (int) ($order['customer_id'] ?? 0));
        self::assertTrue(PaymentIdentity::matchesStoredPayment($order['payment_method'] ?? null));
        self::assertNotSame('', (string) $order['firstname']);
        self::assertNotSame('', (string) $order['telephone']);

        $bound = $this->attempts->findById((int) $attempt['attempt_id']);
        self::assertNotNull($bound);
        self::assertSame(FinancingAttemptState::CP_CREATED, (string) $bound['state']);
        self::assertSame((int) $result->orderId, (int) $bound['order_id']);
        self::assertGreaterThan(0, (int) ($bound['control_panel_order_id'] ?? 0));
    }

    public function testProductDuplicateSubmitDoesNotDuplicateOrder(): void
    {
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders);
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        $actor = ProductFinancingTestHarness::actorBinding();
        $scheme = ProductFinancingTestHarness::defaultSchemeSelection();
        $selection = ProductFinancingTestHarness::selectionHash(
            $line,
            $scheme['scheme_key'],
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['first_installment'],
            $actor
        );
        $operation = ProductOperationIdentity::hash(ProductFinancingTestHarness::STORE_ID, 42, [], 1, 'BGN');
        $attempt = (new ProductSubmissionIssuer($this->attempts, new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock()))
            ->issueOrReuse(ProductFinancingTestHarness::STORE_ID, $operation, $actor, $selection);

        $args = [
            array_replace(ProductFinancingTestHarness::shop(), ['uni_proces' => 1]),
            ProductFinancingTestHarness::STORE_ID,
            (string) $attempt['submission_token'],
            $actor,
            'sess-a',
            0,
            1,
            42,
            1,
            [],
            'BGN',
            'standard',
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['scheme_key'],
            $scheme['first_installment'],
            array_replace(ProductFinancingTestHarness::validPostedCustomer(), ['egn' => '1990011599', 'phone2' => '0888123456']),
            'test-unicid',
            '2026-08-28 12:00:00',
            1,
            'bg-bg',
            1,
            1.0,
            'Store',
            'https://example.test/',
            'INV-',
        ];
        $first = $service->submit(...[...$args, LockOwnerTokenGenerator::generate()]);
        $second = $service->submit(...[...$args, LockOwnerTokenGenerator::generate()]);
        self::assertTrue($first->success);
        self::assertTrue($second->success);
        self::assertSame($first->orderId, $second->orderId);
        self::assertSame(1, $orders->addOrderCallCount());
    }
}
