<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductSubmissionIssuer;
use Opencart\System\Library\Extension\MtUniCredit\SubmissionTokenGenerator;
use PHPUnit\Framework\TestCase;

final class Phase7AttemptAndSubmitTest extends TestCase
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

    public function testIssueCreatesSixtyFourHexTokenWithHashes(): void
    {
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        $actor = ProductFinancingTestHarness::actorBinding();
        $selection = ProductFinancingTestHarness::selectionHash(
            $line,
            'standard|KOPSTD|12|0',
            'standard',
            'KOPSTD',
            12,
            0,
            0.0,
            $actor
        );
        $operation = ProductOperationIdentity::hash(ProductFinancingTestHarness::STORE_ID, 42, [], 1, 'BGN');
        $issuer = new ProductSubmissionIssuer($this->attempts, new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock());
        $row = $issuer->issueOrReuse(ProductFinancingTestHarness::STORE_ID, $operation, $actor, $selection);
        self::assertSame(OperationEntryPoint::PRODUCT, $row['entry_point']);
        self::assertTrue(SubmissionTokenGenerator::isValidFormat((string) $row['submission_token']));
        self::assertSame(FinancingAttemptState::ISSUED, $row['state']);
        self::assertSame($actor, $row['actor_binding_hash']);
        self::assertSame($selection, $row['selection_hash']);
    }

    public function testReuseReturnsSameAttemptForSameIdentity(): void
    {
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        $actor = ProductFinancingTestHarness::actorBinding();
        $selection = ProductFinancingTestHarness::selectionHash(
            $line,
            'standard|KOPSTD|12|0',
            'standard',
            'KOPSTD',
            12,
            0,
            0.0,
            $actor
        );
        $operation = ProductOperationIdentity::hash(ProductFinancingTestHarness::STORE_ID, 42, [], 1, 'BGN');
        $issuer = new ProductSubmissionIssuer($this->attempts, new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock());
        $first = $issuer->issueOrReuse(ProductFinancingTestHarness::STORE_ID, $operation, $actor, $selection);
        $second = $issuer->issueOrReuse(ProductFinancingTestHarness::STORE_ID, $operation, $actor, $selection);
        self::assertSame($first['attempt_id'], $second['attempt_id']);
    }

    public function testSuccessfulSubmitCreatesExactlyOneOrderAndReplay(): void
    {
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
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
            ProductFinancingTestHarness::shop(),
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
            ProductFinancingTestHarness::validPostedCustomer(),
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
        self::assertSame('local_order_prepared', $result->step);
        self::assertFalse(str_contains(strtolower($result->message), 'bank'));

        $replay = $service->submit(
            ProductFinancingTestHarness::shop(),
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
            ProductFinancingTestHarness::validPostedCustomer(),
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
        self::assertTrue($replay->replay);
        self::assertSame($result->orderId, $replay->orderId);
        self::assertSame(1, $orders->addOrderCallCount());
    }

    public function testChangedPriceFailsBeforeOrderCreation(): void
    {
        $orders = new \MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders);
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        $actor = ProductFinancingTestHarness::actorBinding();
        $scheme = ProductFinancingTestHarness::defaultSchemeSelection();
        $staleSelection = ProductFinancingTestHarness::selectionHash(
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
            ->issueOrReuse(ProductFinancingTestHarness::STORE_ID, $operation, $actor, $staleSelection);

        $this->expectException(ProductFinancingFlowException::class);
        $service->submit(
            ProductFinancingTestHarness::shop(),
            ProductFinancingTestHarness::STORE_ID,
            (string) $attempt['submission_token'],
            $actor,
            'sess-a',
            0,
            1,
            43,
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
            ProductFinancingTestHarness::validPostedCustomer(),
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
    }

    public function testWrongActorIsRejected(): void
    {
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
        $service = ProductFinancingTestHarness::submissionService($this->attempts);

        $this->expectException(ProductFinancingFlowException::class);
        $service->submit(
            ProductFinancingTestHarness::shop(),
            ProductFinancingTestHarness::STORE_ID,
            (string) $attempt['submission_token'],
            ProductFinancingTestHarness::actorBinding(99, 'other'),
            'other',
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
            ProductFinancingTestHarness::validPostedCustomer(),
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
    }
}

final class Phase7ActiveCartTest extends TestCase
{
    public function testCatalogControllerChecksCartCountWithoutMutation(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php');
        self::assertStringContainsString('countActiveCartProducts', $controller);
        self::assertStringContainsString('cart_unchanged', $controller);
        self::assertStringNotContainsString('$this->cart->add(', $controller);
        self::assertStringNotContainsString('$this->cart->clear(', $controller);
    }
}
