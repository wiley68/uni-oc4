<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\ConsentFixture;
use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use Opencart\System\Library\Extension\MtUniCredit as Uni;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceConflictException;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceValidationException;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use Opencart\System\Library\Extension\MtUniCredit\SubmissionTokenGenerator;
use PHPUnit\Framework\TestCase;

final class Phase3FinancingAttemptRepositoryIntegrationTest extends TestCase
{
    private FinancingAttemptRepository $repository;

    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
        $this->repository = new FinancingAttemptRepository(PersistenceIntegrationHarness::connection());
    }

    public function testIssueProductAndCartAttemptsWithUniqueTokens(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $product = $this->repository->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $hash('op-product'),
            $hash('actor'),
            $hash('selection'),
            PersistenceIntegrationHarness::TEST_UNICID,
            null,
            null,
            \MtUniCredit\Tests\Support\ConsentFixture::seedForEntry(OperationEntryPoint::PRODUCT, isset($submission) ? $submission : null)
        );
        $cart = $this->repository->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::CART,
            $hash('op-cart'),
            $hash('actor'),
            $hash('selection'),
            PersistenceIntegrationHarness::TEST_UNICID,
            null,
            null,
            \MtUniCredit\Tests\Support\ConsentFixture::seedForEntry(OperationEntryPoint::CART, isset($submission) ? $submission : null)
        );

        self::assertTrue(SubmissionTokenGenerator::isValidFormat((string) $product['submission_token']));
        self::assertTrue(SubmissionTokenGenerator::isValidFormat((string) $cart['submission_token']));
        self::assertNotSame($product['submission_token'], $cart['submission_token']);
        self::assertSame(FinancingAttemptState::ISSUED, $product['state']);
    }

    public function testNewCheckoutAttemptHasTokenAndIssuedConsent(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $checkout = $this->repository->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $hash('op-checkout'),
            $hash('actor'),
            $hash('selection'),
            PersistenceIntegrationHarness::TEST_UNICID,
            null,
            null,
            \MtUniCredit\Tests\Support\ConsentFixture::seedForEntry('checkout', isset($submission) ? $submission : null)
        );
        self::assertTrue(SubmissionTokenGenerator::isValidFormat($checkout['submission_token']));
        self::assertSame('issued', \Opencart\System\Library\Extension\MtUniCredit\ConsentContext::classify($checkout['consent_context_json']));
        self::assertSame(OperationEntryPoint::CHECKOUT, $checkout['entry_point']);
    }

    public function testTransitionAndInvalidTransition(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $row = $this->repository->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $hash('op-transition'),
            $hash('actor'),
            $hash('selection'),
            PersistenceIntegrationHarness::TEST_UNICID,
            null,
            null,
            \MtUniCredit\Tests\Support\ConsentFixture::seedForEntry('checkout', isset($submission) ? $submission : null)
        );
        $attemptId = (int) $row['attempt_id'];
        self::assertTrue($this->repository->transition($attemptId, FinancingAttemptState::ISSUED, FinancingAttemptState::VALIDATING));
        self::assertFalse($this->repository->transition($attemptId, FinancingAttemptState::ISSUED, FinancingAttemptState::ORDER_CREATING));
    }

    public function testCpPayloadIsWrittenOnceBeforeSubmission(): void
    {
        $row = $this->repository->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            hash('sha256', 'cp-payload-op'),
            hash('sha256', 'cp-payload-actor'),
            hash('sha256', 'cp-payload-selection'),
            PersistenceIntegrationHarness::TEST_UNICID,
            null,
            null,
            \MtUniCredit\Tests\Support\ConsentFixture::seedForEntry(OperationEntryPoint::CHECKOUT)
        );
        $id = (int) $row['attempt_id'];
        $body = ['order_id' => '123', 'price' => 499.0, 'parva' => 100.0, 'vnoska' => 37.91, 'gpr' => 27.94];

        self::assertFalse($this->repository->persistCpPayload($id, $body));
        self::assertTrue($this->repository->attachOrder($id, 123));
        self::assertTrue($this->repository->transition($id, FinancingAttemptState::ISSUED, FinancingAttemptState::ORDER_CREATED));
        self::assertFalse($this->repository->persistCpPayload($id, $body));
        $saved = $this->repository->findById($id);
        $locks = new Uni\OperationLockRepository(PersistenceIntegrationHarness::connection());
        $owner = Uni\LockOwnerTokenGenerator::generate();
        self::assertTrue($locks->acquire((int) $saved['store_id'], $saved['entry_point'], $saved['operation_key_hash'], $owner));
        try {
            $authorization = FinancingAttemptRepository::cpBodyAuthorization($body);
            $this->repository->reserveCpBodyAuthorization($saved, $owner, $authorization);
            self::assertSame($authorization, $this->repository->findById($id)['last_error_class']);
            self::assertFalse($this->repository->persistCpPayload($id, ['price' => 399.0] + $body));
            self::assertTrue($this->repository->persistCpPayload($id, $body));
            self::assertFalse($this->repository->persistCpPayload($id, $body));
            self::assertFalse($this->repository->persistCpPayload($id, ['price' => 399.0] + $body));
            $saved = $this->repository->findById($id);
            self::assertSame(json_encode($body, JSON_THROW_ON_ERROR), $saved['cp_payload']);
            self::assertFalse($this->repository->claimCpSubmission(['order_id' => 124] + $saved, $owner));
            self::assertFalse($this->repository->claimCpSubmission(['cp_payload' => '{}'] + $saved, $owner));
            self::assertTrue($this->repository->claimCpSubmission($saved, $owner));
            $submitting = $this->repository->findById($id);
            self::assertSame(FinancingAttemptState::CP_SUBMITTING, $submitting['state']);
            self::assertSame($authorization, $submitting['last_error_class']);
            self::assertFalse($this->repository->claimCpSubmission($submitting, $owner));
        } finally {
            $locks->release((int) $saved['store_id'], $saved['entry_point'], $saved['operation_key_hash'], $owner);
        }
    }

    public function testCpBodyAuthorizationOutlivesRolledBackConflictHold(): void
    {
        $db = PersistenceIntegrationHarness::connection();
        $other = PersistenceIntegrationHarness::independentConnection();
        $otherAttempts = new FinancingAttemptRepository($other);
        [$id, $submission, $shop, $body] = $this->issueNativeOrderForCp();
        $table = $db->getPrefix() . Uni\PersistenceTableNames::FINANCING_ATTEMPT;
        $authorization = FinancingAttemptRepository::cpBodyAuthorization($body);
        $locks = new Uni\OperationLockRepository($db);
        $owner = Uni\LockOwnerTokenGenerator::generate();
        self::assertTrue($locks->acquire($submission->storeId, $submission->entryPoint, $submission->operationKeyHash, $owner));
        try {
            $this->repository->reserveCpBodyAuthorization($this->repository->findById($id), $owner, $authorization);
            self::assertSame($authorization, $otherAttempts->findById($id)['last_error_class']);
            $raw = json_encode(['price' => 399.0] + $body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $other->query("UPDATE `{$table}` SET `cp_payload` = '" . $other->escape($raw) . "' WHERE `attempt_id` = {$id}");
            self::assertFalse($this->repository->persistCpPayload($id, $body));
            self::assertFalse($this->repository->claimCpSubmission($this->repository->findById($id), $owner));

            $other->query('START TRANSACTION');
            try {
                $otherAttempts->holdCpPayloadConflict($id, $submission->storeId, 123);
                self::assertSame('cp_outcome_unknown', $otherAttempts->findById($id)['state']);
            } finally {
                $other->query('ROLLBACK');
            }
            self::assertSame($authorization, $this->repository->findById($id)['last_error_class']);
        } finally {
            $locks->release($submission->storeId, $submission->entryPoint, $submission->operationKeyHash, $owner);
        }

        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $recovery = $this->cpLifecycle($other, $submission, $transport);
        $result = $recovery->submitOrRecover(new Uni\FinancingAttemptContext($otherAttempts->findById($id)), $submission, 123, $shop, Uni\LockOwnerTokenGenerator::generate());
        self::assertSame(Uni\ControlPanelErrorClass::CONFLICT, $result->errorCode);
        self::assertSame([], $transport->requests);
        $blocked = $this->repository->findById($id);
        self::assertSame($raw, $blocked['cp_payload']);
        self::assertSame('cp_outcome_unknown', $blocked['state']);
    }

    public static function cpDispositionCases(): iterable
    {
        yield 'ambiguous timeout' => [FinancingAttemptState::CP_OUTCOME_UNKNOWN, Uni\ControlPanelErrorClass::TIMEOUT, null];
        yield 'definitive rejection' => [FinancingAttemptState::TERMINAL_FAILED, Uni\ControlPanelErrorClass::REJECTED, null];
        yield 'confirmed CP result' => [FinancingAttemptState::CP_CREATED, null, 901];
    }

    #[DataProvider('cpDispositionCases')]
    public function testCpDispatchSurvivesRolledBackDisposition(
        string $state,
        ?string $error,
        ?int $cpOrderId
    ): void {
        $db = PersistenceIntegrationHarness::connection();
        $other = PersistenceIntegrationHarness::independentConnection();
        $independent = new FinancingAttemptRepository($other);
        [$id, $submission, $shop, $body] = $this->issueNativeOrderForCp();
        $owner = Uni\LockOwnerTokenGenerator::generate();
        $locks = new Uni\OperationLockRepository($db);
        self::assertTrue($locks->acquire($submission->storeId, $submission->entryPoint, $submission->operationKeyHash, $owner));

        $authorization = FinancingAttemptRepository::cpBodyAuthorization($body);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        try {
            $this->repository->reserveCpBodyAuthorization($this->repository->findById($id), $owner, $authorization);
            self::assertTrue($this->repository->persistCpPayload($id, $body));
            $prepared = $independent->findById($id);
            self::assertSame(FinancingAttemptState::ORDER_CREATED, $prepared['state']);
            self::assertSame($authorization, $prepared['last_error_class']);
            self::assertSame($raw, $prepared['cp_payload']);
            self::assertFalse($independent->claimCpSubmission($prepared, Uni\LockOwnerTokenGenerator::generate()));
            self::assertTrue($this->repository->claimCpSubmission($prepared, $owner));
            $claimed = $independent->findById($id);
            self::assertSame(FinancingAttemptState::CP_SUBMITTING, $claimed['state']);
            self::assertSame($authorization, $claimed['last_error_class']);
            self::assertSame($raw, $claimed['cp_payload']);
            self::assertFalse($this->repository->claimCpSubmission($prepared, $owner));
            self::assertFalse($this->repository->claimCpSubmission($claimed, $owner));
        } finally {
            $locks->release($submission->storeId, $submission->entryPoint, $submission->operationKeyHash, $owner);
        }

        $record = static fn(array $row): bool => $cpOrderId === null
            ? $independent->recordCpFailure($row, $error, $state === FinancingAttemptState::TERMINAL_FAILED)
            : $independent->recordCpCreated($row, $cpOrderId);
        $caseChanged = substr($authorization, 0, 11) . strtolower(substr($authorization, 11));
        self::assertNotSame($authorization, $caseChanged);
        self::assertFalse($record(['last_error_class' => $caseChanged] + $claimed));
        self::assertFalse($record(['cp_payload' => $raw . ' '] + $claimed));

        $other->query('START TRANSACTION');
        try {
            self::assertTrue($record($claimed));
            $this->assertCpDisposition($independent->findById($id), $state, $error, $cpOrderId, $raw);
        } finally {
            $other->query('ROLLBACK');
        }
        $this->assertCpDisposition($this->repository->findById($id), FinancingAttemptState::CP_SUBMITTING, $authorization, null, $raw);

        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $service = $this->cpLifecycle($db, $submission, $transport);
        $blocked = $service->submitOrRecover(
            new Uni\FinancingAttemptContext($claimed), $submission, 123, $shop, Uni\LockOwnerTokenGenerator::generate()
        );
        self::assertSame(Uni\ControlPanelErrorClass::RECOVERY_FAILED, $blocked->errorCode);
        self::assertFalse($blocked->recoverable);
        self::assertSame([], $transport->requests);

        self::assertTrue($record($claimed));
        self::assertTrue($record($claimed)); // An exact, already committed disposition can be confirmed again.
        $saved = $this->repository->findById($id);
        $this->assertCpDisposition($saved, $state, $error, $cpOrderId, $raw);
        $alternative = $cpOrderId === null
            ? $independent->recordCpCreated($claimed, 902)
            : $independent->recordCpFailure($claimed, Uni\ControlPanelErrorClass::TIMEOUT);
        self::assertFalse($alternative);
        $this->assertCpDisposition($this->repository->findById($id), $state, $error, $cpOrderId, $raw);

        $nextTransport = new FakeCpHttpTransport();
        $nextTransport->enableAutoAuthAndCreate();
        $next = $this->cpLifecycle($other, $submission, $nextTransport)->submitOrRecover(
            new Uni\FinancingAttemptContext($saved), $submission, 123, $shop, Uni\LockOwnerTokenGenerator::generate()
        );
        self::assertSame($cpOrderId !== null, $next->success);
        self::assertSame($cpOrderId !== null, $next->replay);
        self::assertSame($cpOrderId, $next->cpOrderId);
        self::assertFalse($next->recoverable);
        self::assertSame([], $nextTransport->requests);
    }

    private function assertCpDisposition(array $row, string $state, ?string $error, ?int $cpOrderId, string $raw): void
    {
        self::assertSame($state, $row['state']);
        self::assertSame($error, $row['last_error_class']);
        self::assertSame($cpOrderId, isset($row['control_panel_order_id']) ? (int) $row['control_panel_order_id'] : null);
        self::assertSame($raw, $row['cp_payload']);
    }

    private function issueNativeOrderForCp(): array
    {
        $db = PersistenceIntegrationHarness::connection();
        $shop = ProductFinancingTestHarness::shop();
        $shop['uni_first_vnoska'] = 1;
        $submission = OrderMaterializationTestHarness::productSubmission(OperationEntryPoint::CHECKOUT, 123);
        $scheme = new Uni\AvailableScheme('standard', 'KOP1', 12, 1, ['uni_parva' => 0], ['coeff' => 0.095]);
        $submission->financingCalculation = (new Uni\Calculator())->calculateScheme($shop, 499.0, $scheme, 100.0);
        $submission->orderDraft->orderTotal = 499.0;
        $row = $this->repository->issueCheckoutAttempt(
            $submission->storeId,
            $submission->operationKeyHash,
            hash('sha256', 'actor'),
            $submission->selectionHash,
            $submission->shopUnicid,
            consentSeed: ConsentFixture::seedForEntry(OperationEntryPoint::CHECKOUT, $submission)
        );
        $id = (int) $row['attempt_id'];
        $submission->submissionToken = $row['submission_token'];
        $issued = Uni\ConsentContext::decode($row['consent_context_json']);
        (new Uni\DurableConsentAuthority($db))->finalizeSubmission(
            $id,
            $submission,
            $issued['issued']['context']['mandatory_ids'],
            Uni\LockOwnerTokenGenerator::generate(),
            1,
            $shop
        );
        PersistenceIntegrationHarness::seedNativeOrder(
            123,
            $submission->storeId,
            $submission->orderDraft->orderTotal,
            $submission->orderDraft->currencyCode,
            $submission->orderDraft->currencyId,
            $submission->orderDraft->currencyValue,
            (new Uni\OpenCartOrderDataBuilder())->build($submission->orderDraft)
        );
        self::assertTrue($this->repository->attachOrder($id, 123));
        self::assertTrue($this->repository->transition($id, FinancingAttemptState::ISSUED, FinancingAttemptState::ORDER_CREATED));
        $submission->eurOrderProof = (new Uni\DurableEurOrderGuard($db))->prove($id, $submission->storeId, 123);
        $builder = new Uni\ControlPanelOrderPayloadBuilder();
        $body = $builder->build($submission, 123, $shop);
        self::assertSame(499.0, $body['price']);

        return [$id, $submission, $shop, $body];
    }

    private function cpLifecycle(Uni\DbConnection $db, Uni\ValidatedFinancingSubmission $submission, FakeCpHttpTransport $transport): Uni\ControlPanelOrderLifecycleService
    {
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings, $submission->storeId);
        $cipher = Phase4TestHarness::cipher();
        $client = new Uni\ControlPanelClient(
            new Uni\ModuleCredentialsRepository($settings, $cipher),
            new Uni\CpTokenRepository($settings, $cipher, $submission->storeId),
            $transport,
            Phase4TestHarness::TEST_SHOP_URL,
            $submission->storeId
        );

        return new Uni\ControlPanelOrderLifecycleService(
            new FinancingAttemptRepository($db), new Uni\OperationLockRepository($db), $client, new Uni\ControlPanelOrderPayloadBuilder()
        );
    }

    public function testAttachOrderOnceAndConflictSafety(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $attemptA = $this->repository->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $hash('op-a'),
            $hash('actor-a'),
            $hash('selection-a'),
            PersistenceIntegrationHarness::TEST_UNICID,
            null,
            null,
            \MtUniCredit\Tests\Support\ConsentFixture::seedForEntry('checkout', isset($submission) ? $submission : null)
        );
        $attemptB = $this->repository->issueCheckoutAttempt(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            $hash('op-b'),
            $hash('actor-b'),
            $hash('selection-b'),
            PersistenceIntegrationHarness::TEST_UNICID,
            null,
            null,
            \MtUniCredit\Tests\Support\ConsentFixture::seedForEntry('checkout', isset($submission) ? $submission : null)
        );

        $attemptAId = (int) $attemptA['attempt_id'];
        $attemptBId = (int) $attemptB['attempt_id'];
        self::assertTrue($this->repository->attachOrder($attemptAId, 5001));
        self::assertTrue($this->repository->attachOrder($attemptAId, 5001));

        $this->expectException(PersistenceConflictException::class);
        $this->repository->attachOrder($attemptBId, 5001);
    }

    public function testLookupByTokenOrderAndOperationIdentity(): void
    {
        $hash = static fn(string $suffix): string => hash('sha256', $suffix);
        $operationHash = $hash('lookup-op');
        $issued = $this->repository->issueWithSubmissionToken(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $operationHash,
            $hash('actor'),
            $hash('selection'),
            PersistenceIntegrationHarness::TEST_UNICID,
            null,
            null,
            \MtUniCredit\Tests\Support\ConsentFixture::seedForEntry(OperationEntryPoint::PRODUCT, isset($submission) ? $submission : null)
        );
        $token = (string) $issued['submission_token'];
        self::assertNotNull($this->repository->findByToken(PersistenceIntegrationHarness::TEST_STORE_ID, $token));
        self::assertNotNull($this->repository->findByOperationIdentity(
            PersistenceIntegrationHarness::TEST_STORE_ID,
            OperationEntryPoint::PRODUCT,
            $operationHash,
            FinancingAttemptState::ISSUED
        ));
        $this->repository->attachOrder((int) $issued['attempt_id'], 6001);
        self::assertNotNull($this->repository->findByOrderId(PersistenceIntegrationHarness::TEST_STORE_ID, 6001));
    }
}

final class Phase3ShopCacheRepositoryIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for MySQL integration tests.');
        }
        PersistenceIntegrationHarness::resetTables();
    }

    public function testReplaceValidatedAndRejectInvalidWithoutDeletingExisting(): void
    {
        $repo = new ShopCacheRepository(PersistenceIntegrationHarness::connection());
        $repo->replaceValidated(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID, ['shop' => 'ok']);
        self::assertNotNull($repo->findFresh(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID));

        try {
            $repo->replaceValidated(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID, []);
            self::fail('Expected invalid replacement to throw.');
        } catch (PersistenceValidationException) {
        }

        self::assertNotNull($repo->findFresh(PersistenceIntegrationHarness::TEST_STORE_ID, PersistenceIntegrationHarness::TEST_UNICID));
    }
}
