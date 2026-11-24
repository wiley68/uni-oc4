<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\ProcessTwoLifecycleCoordinator;
use Opencart\System\Library\Extension\MtUniCredit\ProcessTwoLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\ProcessTwoLifecycleStates;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\RecordingProcessTwoMailer;
use Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationFlags;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionCoordinator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

final class Phase11BProcess2ContractTest extends TestCase
{
    public function testSelectorAndVersion(): void
    {
        self::assertTrue(ShopConfigurationFlags::isSecondaryProcess(['uni_proces' => 1]));
        self::assertFalse(ShopConfigurationFlags::isSecondaryProcess(['uni_proces' => 0]));
        self::assertSame('2.0.5', ModuleConstants::VERSION);
        self::assertSame(BankStatus::SENT_PROCESS2, BankStatus::process2Sent()['status_id']);
        self::assertSame(BankStatus::LABEL_SENT_PROCESS2, BankStatus::process2Sent()['status_label']);
    }

    public function testRetiredInputsAreOutsideApplicantContract(): void
    {
        self::assertSame(['firstname' => 'Ivan'], \Opencart\System\Library\Extension\MtUniCredit\ProductPopupFormNormalizer::applicantFields([
            'firstname' => 'Ivan', 'egn' => ['old'], 'phone2' => [['old']],
        ]));
    }

    public function testProcess2SuccessWritesBankStatusWithShopOrderIdAndNoSmartUcf(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        (new \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller($db))->installAll();

        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'p2-op'),
            hash('sha256', 'p2-actor'),
            hash('sha256', 'p2-sel'),
            PersistenceIntegrationHarness::TEST_UNICID,
            null,
            null,
            \MtUniCredit\Tests\Support\ConsentFixture::seedForEntry($submission->entryPoint, isset($submission) ? $submission : null)
        );
        $attemptId = (int) $row['attempt_id'];
        $attempts->attachOrder($attemptId, 9011);
        self::assertTrue($attempts->transitionFromStates(
            $attemptId,
            [\Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState::ISSUED],
            \Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState::ORDER_CREATED
        ));
        $attempts->persistControlPanelOrderId($attemptId, 777001);
        self::assertTrue($attempts->transitionFromStates(
            $attemptId,
            [\Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState::ORDER_CREATED],
            \Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState::CP_CREATED
        ));

        PersistenceIntegrationHarness::seedSuccessfulEurAttempt($attemptId, 9011, $submission, 777001, true);

        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $services = Phase4TestHarness::services($transport, null, $db, $submission->storeId);
        $smartCalls = 0;
        $smartClient = new class ($smartCalls) {
            public int $calls = 0;

            public function createSession(): array
            {
                $this->calls++;
                throw new \RuntimeException('SmartUCF must not run for Process 2');
            }
        };
        $mailer = new RecordingProcessTwoMailer();
        $statusSync = new \Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncService(
            new \Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncRepository($db),
            $services['client'],
            new \Opencart\System\Library\Extension\MtUniCredit\DurableConsentAuthority($db)
        );
        $process2 = new ProcessTwoLifecycleCoordinator(
            new ProcessTwoLifecycleRepository($db),
            new OrderBankStatusRepository($db),
            $statusSync,
            $mailer
        );
        $coordinator = new SmartUcfSessionCoordinator(
            new SmartUcfLifecycleRepository($db),
            $smartClient,
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            $services['client'],
            $statusSync
        );
        $lifecycle = new \Opencart\System\Library\Extension\MtUniCredit\PostControlPanelLifecycleService(
            $coordinator,
            $process2,
            'https://shop.example/checkout/success'
        );

        $shop = mt_uni_credit_valid_shop_snapshot(['uni_proces' => 1, 'uni_email' => 'merchant@example.test']);
        $result = $lifecycle->handle(
            $attemptId,
            $submission,
            9011,
            777001,
            $shop,
            false,
            [
                'order_id' => 9011,
                'customer_name' => 'Ivan Petrov',
                'customer_email' => 'ivan@example.test',
                'store_email' => 'store@example.test',
                'scheme_label' => 'KOP / 12',
                'monthly_amount' => '100.00',
            ]
        );

        self::assertTrue($result->success);
        self::assertSame('process2_prepared', $result->step);
        self::assertSame(0, $smartClient->calls);
        $status = $db->query(
            'SELECT `status_id` FROM `' . $db->getPrefix() . 'mt_uni_credit_order_bank_status` WHERE `order_id` = 9011'
        );
        self::assertSame(BankStatus::SENT_PROCESS2, $status->row['status_id']);
        $patches = array_values(array_filter(
            $transport->requests,
            static fn(array $request): bool => $request['method'] === 'PATCH'
                && str_contains($request['url'], '/orders/status')
        ));
        self::assertNotEmpty($patches);
        self::assertSame('9011', $patches[0]['payload']['order_id']);
        self::assertSame(BankStatus::SENT_PROCESS2, $patches[0]['payload']['status_id']);
        self::assertSame(BankStatus::LABEL_SENT_PROCESS2, $patches[0]['payload']['status']);

        $adminBodies = array_column(
            array_filter($mailer->sent, static fn(array $m): bool => $m['audience'] === 'admin'),
            'body_html'
        );
        self::assertNotEmpty($adminBodies);
        self::assertStringNotContainsString('ЕГН', $adminBodies[0]);
        self::assertStringNotContainsString('1990011599', $adminBodies[0]);
        self::assertStringNotContainsString('Втори телефон', $adminBodies[0]);
        $customerBodies = array_column(
            array_filter($mailer->sent, static fn(array $m): bool => $m['audience'] === 'customer'),
            'body_html'
        );
        self::assertNotEmpty($customerBodies);
        self::assertStringNotContainsString('1990011599', $customerBodies[0]);

        // Replay: no second SmartUCF, mail not duplicated.
        $mailCount = count($mailer->sent);
        $second = $lifecycle->handle($attemptId, $submission, 9011, 777001, $shop, true, [
            'order_id' => 9011,
            'customer_email' => 'ivan@example.test',
            'store_email' => 'store@example.test',
        ]);
        self::assertTrue($second->success);
        self::assertSame(0, $smartClient->calls);
        self::assertSame($mailCount, count($mailer->sent));
        $p2row = (new ProcessTwoLifecycleRepository($db))->findByAttempt($attemptId);
        self::assertSame(ProcessTwoLifecycleStates::PREPARED, $p2row['process2_state']);

        PersistenceIntegrationHarness::seedNativeOrder(9011, $submission->storeId, 1200.0, 'BGN', 2, 1.0);
        try {
            $lifecycle->handle($attemptId, $submission, 9011, 777001, $shop, true);
            self::fail('Prepared Process 2 replay accepted a stale BGN order.');
        } catch (ProductFinancingFlowException $expected) {
            self::assertSame('currency_unavailable', $expected->errorCode());
        }
        self::assertSame(0, $smartClient->calls);
        self::assertSame($mailCount, count($mailer->sent));
    }

    public function testCheckoutPrimaryPhoneRemainsOptional(): void
    {
        $checkout = new \Opencart\System\Library\Extension\MtUniCredit\CheckoutCustomerValidator();
        $result = $checkout->validate([
            'firstname' => 'Ivan',
            'lastname' => 'Petrov',
            'email' => 'ivan@example.test',
            'telephone' => '',
        ], 1, 0);
        self::assertSame('', $result['customer']->telephone);

    }

    public function testUiOmitsRetiredFieldsForBothProcesses(): void
    {
        $product = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_modal.twig');
        $cart = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_cart_modal.twig');
        $checkout = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/payment/mt_uni_credit.twig');
        foreach ([$product, $cart, $checkout] as $twig) {
            self::assertStringNotContainsString('name="egn"', $twig);
            self::assertStringNotContainsString('name="phone2"', $twig);
        }

        $coordinator = (string) file_get_contents(dirname(__DIR__) . '/system/library/smart_ucf_session_coordinator.php');
        self::assertStringContainsString('isSecondaryProcess', $coordinator);
        self::assertStringContainsString('process2()', $coordinator);
    }
}
