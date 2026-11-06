<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\ConsentFakeDb;
use MtUniCredit\Tests\Support\ConsentFixture;
use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\InMemoryOrderCorrelationRepository;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness as H;
use MtUniCredit\Tests\Support\RecordingStatusPort;
use Opencart\System\Library\Extension\MtUniCredit as U;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsentRemediationTest extends TestCase
{
    private function refused(callable $call): void
    {
        try { $call(); self::fail('Expected consent refusal'); }
        catch (U\ProductFinancingFlowException $e) { self::assertSame('invalid_consent', $e->errorCode()); }
    }

    public function testValidSnapshotCannotAuthorizeMissingOrInvalidPersistedEvidence(): void
    {
        foreach (['product','cart','checkout'] as $entry) {
            foreach (['absent','null','issued','malformed','unsupported','actor','store','token','operation','selection','unicid','financing'] as $kind) {
                $s = H::productSubmission($entry, $entry === 'checkout' ? 77 : null);
                $snapshot = ConsentFixture::row($s);
                $db = new ConsentFakeDb();
                if ($kind !== 'absent') {
                    $persisted = $snapshot;
                    if ($kind === 'null') $persisted['consent_context_json'] = null;
                    if ($kind === 'issued') $persisted = ConsentFixture::row($s, [], false);
                    if ($kind === 'malformed') $persisted['consent_context_json'] = '{}';
                    if ($kind === 'unsupported') $persisted['consent_context_json'] = str_replace('"version":1', '"version":2', $snapshot['consent_context_json']);
                    $fields = ['actor'=>'actor_binding_hash','store'=>'store_id','token'=>'submission_token','operation'=>'operation_key_hash','selection'=>'selection_hash','unicid'=>'unicid'];
                    if (isset($fields[$kind])) {
                        $key = $fields[$kind];
                        $persisted = ConsentFixture::row($s, [$key => $key === 'store_id' ? 5 : ($key === 'unicid' ? 'wrong' : str_repeat('b',64))]);
                    }
                    if ($kind === 'financing') {
                        $other = H::productSubmission($entry, $entry === 'checkout' ? 77 : null);
                        $other->financingCalculation->scheme->months = 24;
                        $persisted = ConsentFixture::row($other);
                    }
                    $db->rows[1] = $persisted;
                }
                $orders = new InMemoryCheckoutOrderAdapter();
                $authority = new U\DurableConsentAuthority($db);
                $gateway = $entry === 'checkout'
                    ? new U\CheckoutExistingOrderGateway($orders, new U\OpenCartOrderVerifier(), new U\FinancingOrderStatusPolicy(25,16), $authority)
                    : H::buildMaterializer($orders, new InMemoryOrderCorrelationRepository(), $db);
                $attempt = new U\FinancingAttemptContext($snapshot);
                $this->refused(fn() => $entry === 'checkout' ? $gateway->materialize($s,$attempt) : $gateway->materializeNew($s,$attempt));
                self::assertSame(0,$orders->addOrderCallCount(),$entry . '/' . $kind);
                self::assertSame([],$db->effectQueries());
            }
        }
    }

    public function testDirectOrderDraftCurrencyAndStoreMustMatchBeforeCreation(): void
    {
        foreach (['currencyCode'=>'BGN','currencyValue'=>2.0,'currencyId'=>0,'storeId'=>5,'orderTotal'=>1201.0] as $key=>$value) {
            $db = new ConsentFakeDb(); $s = H::productSubmission();
            $row = $db->rows[1] = ConsentFixture::row($s);
            $s->orderDraft->$key = $value;
            $orders = new InMemoryCheckoutOrderAdapter();
            $this->refused(fn() => H::buildMaterializer($orders,new InMemoryOrderCorrelationRepository(),$db)->materializeNew($s,new U\FinancingAttemptContext($row)));
            self::assertSame(0,$orders->addOrderCallCount());
        }
    }

    public function testPersistedReceiptAllowsCreationWithoutReacquiringHeldLease(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission();
        $row = $db->rows[1] = ConsentFixture::row($s);
        $owner = U\LockOwnerTokenGenerator::generate();
        $locks = new U\OperationLockRepository($db);
        self::assertTrue($locks->acquire($s->storeId,$s->entryPoint,$s->operationKeyHash,$owner));
        $db->queries = [];
        $orders = new InMemoryCheckoutOrderAdapter();
        H::buildMaterializer($orders,new InMemoryOrderCorrelationRepository(),$db)->materializeNew($s,new U\FinancingAttemptContext($row));
        self::assertSame(1,$orders->addOrderCallCount());
        self::assertSame($row,$db->rows[1]);
        self::assertSame([],array_values(array_filter($db->queries,static fn(string $sql): bool => str_contains($sql,'operation_lock'))));
    }

    public function testAlternateStatusStoreCannotAuthorizeAdmissionOrPatch(): void
    {
        foreach (['absent','issued','malformed','unsupported','wrong_order','wrong_process','legacy'] as $kind) {
            $db = ConsentFixture::statusDatabase(1,77);
            if ($kind === 'absent') unset($db->rows[1]);
            if ($kind === 'issued') $db->rows[1] = ConsentFixture::row(H::productSubmission(),['order_id'=>77],false);
            if ($kind === 'malformed') $db->rows[1]['consent_context_json'] = '{}';
            if ($kind === 'unsupported') $db->rows[1]['consent_context_json'] = str_replace('"version":1','"version":2',$db->rows[1]['consent_context_json']);
            if ($kind === 'legacy') { $db->rows[1]['consent_context_json'] = null; $db->rows[1]['smartucf_state'] = 'created'; }
            if ($kind === 'wrong_process') $db = ConsentFixture::statusDatabase(1,77,2);
            $store = new ConsentRemediationStatusStore();
            $store->row = ['cp_origin'=>U\CpOriginGuard::current(),'cp_status_sync_state'=>'pending','cp_status_sync_status_id'=>U\BankStatus::SENT_PROCESS1,'cp_status_sync_status'=>U\BankStatus::LABEL_SENT_PROCESS1];
            $port = new RecordingStatusPort();
            $service = new U\ControlPanelStatusSyncService($store,$port,new U\DurableConsentAuthority($db));
            if ($kind !== 'wrong_order') $this->refused(fn() => $service->admitTarget(1,U\BankStatus::SENT_PROCESS1,U\BankStatus::LABEL_SENT_PROCESS1));
            $this->refused(fn() => $service->retryPending(1,$kind === 'wrong_order' ? '999' : '77'));
            self::assertSame(0,$store->mutations); self::assertSame(0,$port->calls);
            self::assertSame([],$db->effectQueries());
        }
    }

    /** In-memory production gateway fixture; native amounts stay in base units. */
    private function currencyFixture(string $entry, float $price = 600.0): array
    {
        $submission = H::productSubmission($entry, $entry === 'checkout' ? 77 : null);
        $submission->orderDraft->currencyValue = 0.5;
        $submission->financingCalculation = H::calculation($price);
        $db = new ConsentFakeDb();
        $row = $db->rows[1] = ConsentFixture::row($submission);
        $orders = new InMemoryCheckoutOrderAdapter();
        if ($entry === 'checkout') {
            $native = (new U\OpenCartOrderDataBuilder())->build($submission->orderDraft);
            $native['order_status_id'] = 0;
            $orders->seedExistingOrder(77, $native, $native['products'], $native['totals']);
            $gateway = new U\CheckoutExistingOrderGateway($orders, new U\OpenCartOrderVerifier(), new U\FinancingOrderStatusPolicy(25,16), new U\DurableConsentAuthority($db));
        } else {
            $gateway = H::buildMaterializer($orders, new InMemoryOrderCorrelationRepository(), $db);
        }
        return [$submission, $db, $row, $orders, $gateway];
    }

    private function materializeCurrency(string $entry, object $gateway, U\ValidatedFinancingSubmission $submission, array $snapshot): U\CreatedOpenCartOrder
    {
        $attempt = new U\FinancingAttemptContext($snapshot);
        return $entry === 'checkout' ? $gateway->materialize($submission, $attempt) : $gateway->materializeNew($submission, $attempt);
    }

    public static function convertedEurCases(): array
    {
        $cases = [];
        foreach (['product','cart','checkout'] as $entry) {
            foreach ([[0.5,600.0],[1.0,1200.0],[2.0,2400.0]] as [$factor,$price]) {
                $cases[$entry . '/' . $factor] = [$entry,$factor,$price];
            }
        }
        return $cases;
    }

    #[DataProvider('convertedEurCases')]
    public function testConvertedEurMaterializationPreservesNativeAmounts(string $entry, float $factor, float $price): void
    {
        [$submission,$db,$row,$orders,$gateway] = $this->currencyFixture($entry,$price);
        $submission->orderDraft->currencyValue = $factor;
        if ($entry === 'checkout') {
            $native = (new U\OpenCartOrderDataBuilder())->build($submission->orderDraft);
            $native['order_status_id'] = 0;
            $orders->seedExistingOrder(77,$native,$native['products'],$native['totals']);
        }
        $created = $this->materializeCurrency($entry,$gateway,$submission,$row);
        self::assertSame(1200.0,$created->total);
        self::assertSame($factor,$orders->getOrder($created->orderId)['currency_value']);
        self::assertSame($entry === 'checkout' ? 0 : 1,$orders->addOrderCallCount());
        self::assertSame($row,$db->rows[1]);
        self::assertSame([],$db->effectQueries());
    }

    public static function nonUnitRefusalCases(): array
    {
        $cases = [];
        foreach (['product','cart','checkout'] as $entry) {
            foreach (['wrong_price','unconverted_price','zero','negative','infinite','negative_infinite','nan','overflow','currency','currency_id','absent','null','issued','malformed','unsupported','persisted_conflict','caller_conflict'] as $reason) {
                $cases[$entry . '/' . $reason] = [$entry,$reason];
            }
        }
        return $cases;
    }

    #[DataProvider('nonUnitRefusalCases')]
    public function testNonUnitCurrencyCannotBypassAmountOrPersistedAuthority(string $entry, string $reason): void
    {
        $price = $reason === 'wrong_price' ? 601.0 : ($reason === 'unconverted_price' ? 1200.0 : 600.0);
        [$submission,$db,$snapshot,$orders,$gateway] = $this->currencyFixture($entry,$price);
        $factors = ['zero'=>0.0,'negative'=>-0.5,'infinite'=>INF,'negative_infinite'=>-INF,'nan'=>NAN,'overflow'=>PHP_FLOAT_MAX];
        if (array_key_exists($reason,$factors)) $submission->orderDraft->currencyValue = $factors[$reason];
        if ($reason === 'currency') $submission->orderDraft->currencyCode = 'BGN';
        if ($reason === 'currency_id') $submission->orderDraft->currencyId = 0;
        if ($reason === 'absent') unset($db->rows[1]);
        if ($reason === 'null') $db->rows[1]['consent_context_json'] = null;
        if ($reason === 'issued') $db->rows[1] = ConsentFixture::row($submission,[],false);
        if ($reason === 'malformed') $db->rows[1]['consent_context_json'] = '{}';
        if ($reason === 'unsupported') $db->rows[1]['consent_context_json'] = str_replace('"version":1','"version":2',$snapshot['consent_context_json']);
        if ($reason === 'persisted_conflict') {
            $other = clone $submission;
            $other->financingCalculation = H::calculation(601.0);
            $db->rows[1] = ConsentFixture::row($other);
        }
        if ($reason === 'caller_conflict') $snapshot = ConsentFixture::row($submission,['actor_binding_hash'=>hash('sha256','other-actor')]);
        $before = $db->rows;
        $this->refused(fn() => $this->materializeCurrency($entry,$gateway,$submission,$snapshot));
        self::assertSame(0,$orders->addOrderCallCount());
        self::assertSame($before,$db->rows);
        self::assertSame([],$db->effectQueries());
    }

    public function testMalformedCurrencyFactorCannotEnterTypedDraft(): void
    {
        foreach (['product','cart','checkout'] as $entry) foreach (['bad',null,[]] as $factor) {
            [$submission,$db,$row,$orders] = $this->currencyFixture($entry);
            try {
                $submission->orderDraft->currencyValue = $factor;
                self::fail('Malformed factor entered the typed draft');
            } catch (\TypeError) {
                self::assertSame(0,$orders->addOrderCallCount());
                self::assertSame($row,$db->rows[1]);
                self::assertSame([],$db->effectQueries());
            }
        }
    }

    public static function statusProcesses(): array
    {
        return ['process1'=>[1], 'process2'=>[2]];
    }

    private function statusFixture(int $process, bool $pending = false): array
    {
        $db = ConsentFixture::statusDatabase(1,77,$process);
        $status = $process === 1 ? U\BankStatus::process1Sent() : U\BankStatus::process2Sent();
        $store = new ConsentRemediationStatusStore();
        $store->row = ['cp_origin'=>U\CpOriginGuard::current(), 'cp_status_sync_state'=>$pending ? 'pending' : 'not_needed',
            'cp_status_sync_status_id'=>$pending ? $status['status_id'] : null, 'cp_status_sync_status'=>$pending ? $status['status_label'] : null];
        $port = new RecordingStatusPort();
        $service = new U\ControlPanelStatusSyncService($store,$port,new U\DurableConsentAuthority($db));
        return [$db,$status,$store,$port,$service];
    }

    #[DataProvider('statusProcesses')]
    public function testStatusHandoffWrongOrderCannotAdmitTarget(int $process): void
    {
        [$db,$status,$store,$port,$service] = $this->statusFixture($process);
        $before = $store->row;
        $this->refused(fn() => $service->synchronizeAfterHandoff(1,'999',$status));
        self::assertSame($before,$store->row);
        self::assertSame(0,$store->mutations);
        self::assertSame(0,$port->calls);
        self::assertSame([],$db->effectQueries());
    }

    #[DataProvider('statusProcesses')]
    public function testStatusHandoffMatchingOrderAdmitsAndPatches(int $process): void
    {
        [$db,$status,$store,$port,$service] = $this->statusFixture($process);
        $receipt = $db->rows[1]['consent_context_json'];
        self::assertSame('confirmed',$service->synchronizeAfterHandoff(1,'77',$status));
        self::assertSame($status['status_id'],$store->row['cp_status_sync_status_id']);
        self::assertSame($status['status_label'],$store->row['cp_status_sync_status']);
        self::assertSame(2,$store->mutations);
        self::assertSame(1,$port->calls);
        self::assertSame($receipt,$db->rows[1]['consent_context_json']);
    }

    #[DataProvider('statusProcesses')]
    public function testStatusHandoffWrongProcessCannotAdmitTarget(int $process): void
    {
        [$db,,$store,$port,$service] = $this->statusFixture($process);
        $wrong = $process === 1 ? U\BankStatus::process2Sent() : U\BankStatus::process1Sent();
        $before = $store->row;
        $this->refused(fn() => $service->synchronizeAfterHandoff(1,'77',$wrong));
        self::assertSame($before,$store->row);
        self::assertSame(0,$store->mutations);
        self::assertSame(0,$port->calls);
    }

    #[DataProvider('statusProcesses')]
    public function testStatusRetryWrongOrderIsReadOnlyAndMatchingRepairStillWorks(int $process): void
    {
        [$db,$status,$store,$port,$service] = $this->statusFixture($process,true);
        $before = $store->row;
        $this->refused(fn() => $service->retryPending(1,'999'));
        self::assertSame($before,$store->row);
        self::assertSame(0,$store->mutations);
        self::assertSame(0,$port->calls);
        self::assertSame('confirmed',$service->retryPending(1,'77'));
        self::assertSame($status['status_id'],$store->row['cp_status_sync_status_id']);
        self::assertSame(1,$store->mutations);
        self::assertSame(1,$port->calls);
    }

    public static function malformedConsents(): array
    {
        $out = [];
        foreach ([null, false, 1, '', 'bad', '{}', 'null', '{"id":1}', ['bad'=>'value']] as $raw) $out[] = [$raw];
        foreach ([['id'=>1,'name'=>''], ['name'=>'Required'], ['id'=>0,'name'=>'Required'], ['id'=>-1,'name'=>'Required'], ['id'=>'bad','name'=>'Required'], ['id'=>true,'name'=>'Required'], ['id'=>1.5,'name'=>'Required'], ['id'=>1,'name'=>[]]] as $entry) $out[] = [[$entry+['mandatory'=>1]]];
        foreach (['9223372036854775808','1.5','1e100',INF,NAN,[],null] as $id) $out[] = [[['id'=>$id,'name'=>'Required','mandatory'=>1]]];
        $out[] = ['[{"id":1,"mandatory":1,"name":""}]'];
        return $out;
    }

    #[DataProvider('malformedConsents')]
    public function testMalformedConsentConfigurationCannotBecomeEmpty(mixed $raw): void
    {
        $this->refused(fn() => (new U\ConsentResolver())->validate(['consents'=>$raw],[]));
        $this->refused(fn() => U\ConsentContext::seed(['consents'=>$raw],U\ConsentContext::fromCalculation(H::productSubmission()->financingCalculation)));
    }

    public function testEmptyAndOptionalConfigurationCompatibility(): void
    {
        $r = new U\ConsentResolver();
        foreach ([[],['consents'=>[]],['consents'=>'[]']] as $shop) self::assertSame([],$r->validate($shop,[]));
        $rows = $r->normalize(['consents'=>[['name'=>'No ID','mandatory'=>0],['id'=>2,'name'=>'Информация','mandatory'=>0,'interest_scope'=>false],['id'=>'3','name'=>'Terms','mandatory'=>1]]]);
        self::assertSame([2,3],array_column($rows,'id'));
        self::assertSame('all',$rows[0]['interest_scope']); self::assertFalse($rows[0]['has_checkbox']);
        self::assertSame([3],$r->validateDescriptors($rows,['3']));
    }

    public function testFinalizedProcessTwoMissingSecretReachesEncryptionGuard(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission();
        $db->rows[1] = ConsentFixture::row($s, [], true, ['uni_proces'=>1]);
        try {
            U\ProcessTwoSubmissionSupport::persist($s,1,$db,new U\ProcessTwoSensitiveData('1990011599','0888123456'),'');
            self::fail('Missing encryption secret was accepted');
        } catch (U\ProductFinancingFlowException $e) {
            self::assertSame('process2_encryption_unavailable',$e->errorCode());
            self::assertStringNotContainsString('DB_PASSWORD',$e->getMessage());
        }
        self::assertSame([],$db->effectQueries());
        self::assertStringNotContainsString('1990011599',json_encode($db->rows));
        self::assertStringNotContainsString('0888123456',json_encode($db->rows));
    }

    public function testAllCasBindingPredicatesRejectConcurrentChanges(): void
    {
        foreach (['attempt_id','store_id','submission_token','actor_binding_hash','operation_key_hash','selection_hash','entry_point','unicid','created_at','expires_at','state','order_id'] as $key) {
            $db = new ConsentFakeDb(); $s = H::productSubmission();
            $db->rows[1] = ConsentFixture::row($s,[],false);
            $raw = $db->rows[1]['consent_context_json'];
            $db->beforeUpdate = static function(ConsentFakeDb $db) use($key): void {
                $old = $db->rows[1][$key];
                $db->rows[1][$key] = is_int($old) ? $old+1 : ($key === 'order_id' ? 77 : 'changed');
                $db->externalCommittedRows[1] = $db->rows[1];
            };
            $this->refused(fn() => (new U\DurableConsentAuthority($db))->finalizeSubmission(1,$s,[],U\LockOwnerTokenGenerator::generate()));
            self::assertSame(0,$db->evidenceWrites,$key);
            self::assertSame($raw,$db->rows[1]['consent_context_json']);
            self::assertSame([0],$db->evidenceCasAffected);
        }
    }
}

/** Alternate status persistence never supplies authority; its mutations are observable. */
final class ConsentRemediationStatusStore implements U\ControlPanelStatusSyncStoreInterface
{
    public array $row = [];
    public int $mutations = 0;
    public function findByAttempt(int $id): ?array { return $this->row; }
    public function compareAndSetPendingTarget(int $id,string $state,?string $oldId,?string $oldLabel,string $newId,string $newLabel): bool
    {
        if (!$this->matches($state,$oldId,$oldLabel)) return false;
        $this->row['cp_status_sync_state'] = 'pending';
        $this->row['cp_status_sync_status_id'] = $newId;
        $this->row['cp_status_sync_status'] = $newLabel;
        ++$this->mutations;
        return true;
    }
    public function compareAndSetConfirmed(int $id,string $statusId,string $label): bool
    {
        if (!$this->matches('pending',$statusId,$label)) return false;
        $this->row['cp_status_sync_state'] = 'confirmed';
        ++$this->mutations;
        return true;
    }
    public function compareAndSetFailure(int $id,string $statusId,string $label,string $state,string $error): bool
    {
        if (!$this->matches('pending',$statusId,$label)) return false;
        $this->row['cp_status_sync_state'] = $state;
        $this->row['cp_status_sync_error_class'] = $error;
        ++$this->mutations;
        return true;
    }
    private function matches(string $state, ?string $id, ?string $label): bool
    {
        return ($this->row['cp_status_sync_state'] ?? null) === $state
            && ($this->row['cp_status_sync_status_id'] ?? null) === $id
            && ($this->row['cp_status_sync_status'] ?? null) === $label;
    }
}
