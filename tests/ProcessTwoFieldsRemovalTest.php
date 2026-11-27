<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\ConsentFakeDb;
use MtUniCredit\Tests\Support\ConsentFixture;
use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\InMemoryOrderCorrelationRepository;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness as H;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness as P;
use Opencart\System\Library\Extension\MtUniCredit as U;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessTwoFieldsRemovalTest extends TestCase
{
    private const ALIASES = ['egn', 'EGN', 'clientEGN', 'clientegn', 'phone2', 'unipayment_egn', 'unipayment_phone2', 'second_phone', 'secondary_phone', 'personal_id', 'phone_2'];

    protected function setUp(): void
    {
        // The existing in-memory order adapter can seed native SQL fixtures when integration is enabled.
        if (\MtUniCredit\Tests\Support\PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Offline field-removal fixtures require MT_UNI_CREDIT_INTEGRATION=0.');
        }
    }

    public static function submissions(): iterable
    {
        foreach (['product', 'cart', 'checkout'] as $entry) {
            foreach ([0, 5] as $customer) {
                foreach (['missing', 'scalar', 'array', 'nested'] as $shape) yield "$entry/$customer/$shape" => [$entry, $customer, $shape];
            }
        }
    }

    #[DataProvider('submissions')]
    public function testSubmissionWithoutRetiredFieldsAndReplay(string $entry, int $customer, string $shape): void
    {
        [$service, $args, $db, $cp, $transport, $submission] = $this->submission($entry, $customer);
        $postedIndex = $entry === 'product' ? 18 : 17;
        if ($shape !== 'missing') foreach (self::ALIASES as $alias) {
            $args[$postedIndex][$alias] = match ($shape) {
                'scalar' => 'retired-marker', 'array' => ['retired-marker'], default => [['retired-marker']],
            };
        }
        // Synthetic pre-send claim failure stops before CP and the production mail transport.
        try {
            $service->submit(...$args);
            self::fail('Expected the synthetic CP failure');
        } catch (U\ProductFinancingFlowException $e) {
            self::assertStringStartsWith('cp_', $e->errorCode());
        }
        $row = $db->data->rows[1];
        self::assertNull($row['process2_sensitive_enc']);
        self::assertTrue(json_decode($row['leasing_presentation_json'], true)['process2']);
        self::assertStringNotContainsString('retired-marker', json_encode([$row, $db->queries], JSON_THROW_ON_ERROR));
        self::assertSame($entry === 'checkout' ? 0 : 1, $db->orders->addOrderCallCount());
        self::assertSame($customer, $db->orders->getOrder((int) $row['order_id'])['customer_id']);

        // Recover the same frozen order through the real CP completion and status code, recording mail only.
        $transport->enableAutoAuthAndCreate(901);
        $resume = U\ResumeSubmissionFactory::create($entry, 7, $row['submission_token'], $row['operation_key_hash'], (int) $row['order_id']);
        $proof = (new U\DurableEurOrderGuard($db))->prove(1, 7, (int) $row['order_id']);
        (new U\DurableEurResumeHydrator($db))->hydrate(1, $resume, $proof, true);
        $mailer = new U\RecordingProcessTwoMailer();
        $result = U\FinancingControlPanelCompletion::apply($cp, new U\FinancingAttemptContext($row), $resume, (int) $row['order_id'], $args[0], U\LockOwnerTokenGenerator::generate(), process2Mailer: $mailer);
        self::assertTrue($result->success);
        self::assertSame('process2_prepared', $result->step);
        self::assertFalse($result->bankSubmitted);
        self::assertSame(U\BankStatus::SENT_PROCESS2, $db->bank['status_id']);
        self::assertNull($db->data->rows[1]['process2_sensitive_enc']);
        $evidence = $db->data->rows[1]['consent_context_json'];
        $payload = $db->data->rows[1]['cp_payload'];
        $mailCount = count($mailer->sent);
        $replay = U\FinancingControlPanelCompletion::resumeExistingCp($cp, 1, $resume, (int) $row['order_id'], 901, $args[0], process2Mailer: $mailer);
        self::assertTrue($replay->replay);
        self::assertSame($mailCount, count($mailer->sent));
        self::assertSame($payload, $db->data->rows[1]['cp_payload']);
        self::assertSame($evidence, $db->data->rows[1]['consent_context_json']);
        self::assertStringNotContainsString('retired-marker', json_encode($mailer->sent, JSON_THROW_ON_ERROR));
        if ($entry === 'checkout') {
            // Already-sent durable marker keeps this confirm() recovery path away from real mail.
            self::assertSame('sent', $db->data->rows[1]['process2_mail_state']);
            self::assertTrue($service->resume($args[0], 7, $row['submission_token'], $args[3], 77, U\LockOwnerTokenGenerator::generate())->replay);
            try {
                $service->resume($args[0], 7, $row['submission_token'], $args[3], 78, U\LockOwnerTokenGenerator::generate());
                self::fail('Recovery accepted a different native order');
            } catch (U\ProductFinancingFlowException $e) {
                self::assertSame('invalid_consent', $e->errorCode());
            }
        }
    }

    public function testRemainingValidationAndOwnershipStillRefuseBeforeEffects(): void
    {
        foreach (['product', 'cart', 'checkout'] as $entry) foreach (['consent', 'email', 'phone', 'actor'] as $bad) {
            [$service, $args, $db] = $this->submission($entry, 5);
            $posted = $entry === 'product' ? 18 : 17;
            if ($bad === 'consent') $args[$posted]['consent'] = [];
            if ($bad === 'actor') $args[3] = hash('sha256', 'wrong-actor');
            if ($bad === 'email') {
                if ($entry === 'checkout') $args[33]['email'] = 'invalid';
                else $args[$posted]['email'] = 'invalid';
            }
            if ($bad === 'phone') {
                if ($entry === 'checkout') $args[33]['telephone'] = str_repeat('1', 33);
                else $args[$posted]['telephone'] = '';
            }
            try { $service->submit(...$args); self::fail("$entry/$bad accepted"); }
            catch (U\ProductFinancingFlowException $e) { self::assertContains($e->errorCode(), ['validation', 'invalid_customer', 'invalid_consent', 'attempt_conflict']); }
            self::assertSame(0, $db->orders->addOrderCallCount());
            self::assertNull($db->data->rows[1]['order_id']);
        }
    }

    public function testHistoricalMailRetryAndPresentationNeverDecrypt(): void
    {
        $valid = (new U\ProcessTwoSensitiveCipher('synthetic-historical-secret'))->encrypt(new U\ProcessTwoSensitiveData('retired-egn', 'retired-phone'));
        foreach ([null, $valid, 'enc:v1:malformed'] as $ciphertext) {
            [$service, $args, $db, $cp, $transport] = $this->submission('product', 0);
            try { $service->submit(...$args); } catch (U\ProductFinancingFlowException) {}
            $row = &$db->data->rows[1];
            $row['state'] = 'cp_created'; $row['control_panel_order_id'] = 901;
            $row['process2_state'] = 'process2_prepared'; $row['process2_sensitive_enc'] = $ciphertext;
            $row['process2_mail_sent'] = 0; $row['process2_mail_state'] = 'not_sent';
            $row['cp_status_sync_state'] = 'confirmed';
            $row['cp_status_sync_status_id'] = U\BankStatus::SENT_PROCESS2;
            $mailer = new U\RecordingProcessTwoMailer();
            $coordinator = new U\ProcessTwoLifecycleCoordinator(new U\ProcessTwoLifecycleRepository($db), new U\OrderBankStatusRepository($db), new U\ControlPanelStatusSyncService(new U\ControlPanelStatusSyncRepository($db), $cp->client(), new U\DurableConsentAuthority($db)), $mailer);
            self::assertTrue($coordinator->run(1, 7, (int) $row['order_id'], $args[0], ['order_id' => $row['order_id']])->success);
            self::assertNotEmpty($mailer->sent);
            self::assertSame($ciphertext, $row['process2_sensitive_enc']);
            $presentation = new U\FinancingPresentationService(new U\FinancingPresentationRepository($db));
            foreach (['customer', 'admin_email', 'admin_panel'] as $audience) {
                $html = $presentation->htmlForOrder(7, (int) $row['order_id'], $audience);
                self::assertNotSame('', $html);
                self::assertStringNotContainsString('retired-', $html);
                self::assertStringNotContainsString('ЕГН', $html);
                self::assertStringNotContainsString('Втори телефон', $html);
            }
        }
    }

    public function testDiagnosticAliasesAtWriteReadAndTextBoundaries(): void
    {
        foreach (self::ALIASES as $alias) foreach ([$alias, strtoupper($alias)] as $key) {
            $raw = ['nested' => [$key => ['marker-value']], 'json' => json_encode(['nested' => [$key => 'marker-value']])];
            self::assertStringNotContainsString('marker-value', json_encode(U\DiagnosticPayloadRedactor::redact($raw)));
            foreach (['redactText', 'sanitizeLogMessage'] as $method) {
                self::assertStringNotContainsString('marker-value', U\DiagnosticPayloadRedactor::$method($key . '="marker-value with spaces"'));
            }
            $db = new ProcessTwoFieldsDb();
            (new U\DiagnosticDebugLogRepository($db))->insert(7, 77, 'product', 'synthetic', 200, $raw);
            self::assertStringNotContainsString('marker-value', end($db->queries));
            $db->diagnostic = ['diagnostic_debug_log_id'=>1, 'order_id'=>77, 'summary_json'=>json_encode($raw)];
            self::assertStringNotContainsString('marker-value', json_encode((new U\DiagnosticDebugLogRepository($db))->findLatestByOrderId(7,77)));
            self::assertStringNotContainsString('marker-value', json_encode((new U\DiagnosticDebugLogRepository($db))->findAllForStore(7)));
        }
    }

    public function testAllowlistPreservesAdditionalAddressDataAndPrimaryPhoneContracts(): void
    {
        $posted = P::validPostedCustomer() + ['address_2'=>'Unit 2', 'company'=>'Synthetic', 'address_id'=>900, 'shipping_address_id'=>901, 'phone'=>'0888000000'];
        self::assertSame($posted, U\ProductPopupFormNormalizer::applicantFields($posted + ['egn'=>[['old']], 'phone2'=>['old']]));
        $normalizer = new U\ProductPopupFormNormalizer();
        self::assertSame(['old'], $normalizer->normalize($posted + ['egn'=>['old']], [])['egn']); // P1 passthrough is unchanged.
        $checkout = new U\CheckoutCustomerValidator();
        self::assertSame('', $checkout->validate(array_replace($posted, ['telephone'=>'']),1)['customer']->telephone);
    }

    public function testUnknownCpOutcomeCannotResendOrStartProcessTwo(): void
    {
        [$service, $args, $db, $cp, $transport] = $this->submission('product', 0);
        try { $service->submit(...$args); } catch (U\ProductFinancingFlowException) {}
        $db->data->rows[1]['state'] = U\FinancingAttemptState::CP_OUTCOME_UNKNOWN;
        $before = count($transport->requests);
        $row = $db->data->rows[1];
        $resume = U\ResumeSubmissionFactory::create('product', 7, $row['submission_token'], $row['operation_key_hash'], (int) $row['order_id']);
        $proof = (new U\DurableEurOrderGuard($db))->prove(1, 7, (int) $row['order_id']);
        (new U\DurableEurResumeHydrator($db))->hydrate(1, $resume, $proof, true);
        $result = $cp->submitOrRecover(new U\FinancingAttemptContext($row), $resume, (int) $row['order_id'], $args[0], U\LockOwnerTokenGenerator::generate());
        self::assertFalse($result->success);
        self::assertSame($before, count($transport->requests));
        self::assertSame('not_started', $db->data->rows[1]['process2_state']);
    }

    /** Builds real services with synthetic SQL and HTTP ports; never opens a DB or sends mail. */
    private function submission(string $entry, int $customer): array
    {
        $db = new ProcessTwoFieldsDb();
        $shop = array_replace(P::shop(), ['uni_proces'=>1, 'uni_email'=>'merchant@example.test']);
        $calculator = new U\Calculator(); $resolver = new U\CartSchemeResolver($calculator);
        $line = P::factory()->create(7,42,1,[]);
        $scheme = U\ProductSchemeList::find($calculator->availableSchemes($shop,$line->toProductContext(),'standard'),'KOPSTD',12,0);
        $calculation = $calculator->calculateScheme($shop,$line->financingPrice,$scheme,0);
        $cart = new U\CartContext([new U\CartLine($line->toProductContext(),0,1,$line->financingPrice)],$line->financingPrice);
        $fingerprint = U\CartFingerprint::hash($cart,'EUR'); $actor = P::actorBinding($customer);
        $key = U\ProductSchemeList::key($scheme);
        $selection = $entry === 'product' ? P::selectionHash($line,$key,'standard','KOPSTD',12,0,0,$actor) : ($entry === 'cart'
            ? U\CartSelectionHash::hash(7,$fingerprint,'EUR',$cart->total,$key,'standard','KOPSTD',12,0,0,$actor)
            : U\CheckoutSelectionHash::hash(7,77,$fingerprint,'EUR',$cart->total,$key,'standard','KOPSTD',12,0,0,$actor));
        $customerData = H::customer(); $customerData->customerId = $customer;
        $draft = (new U\OpenCartProductOrderDraftBuilder(new U\ProductOrderDraftFactory()))->build(7,'Synthetic','https://example.test/','INV-',$customerData,H::address(),null,null,$line,$line->financingPrice,1,'bg-bg',1,'EUR',1);
        $s = H::productSubmission($entry,$entry === 'checkout' ? 77 : null);
        $s->storeId=7; $s->orderDraft=$draft; $s->customer=$customerData; $s->financingCalculation=$calculation;
        $s->selectionHash=$selection; $s->cartFingerprint=$fingerprint;
        $db->products=$draft->products;
        $db->native=(new U\OpenCartOrderDataBuilder())->build($draft);
        $db->data->rows[1] = ConsentFixture::row($s, ['actor_binding_hash'=>$actor, 'cart_fingerprint'=>$fingerprint, 'process2_sensitive_enc'=>null, 'process2_state'=>'not_started', 'process2_mail_state'=>'not_sent', 'process2_mail_sent'=>0, 'cp_status_sync_state'=>'not_needed'], false, $shop);
        $attempts=new U\FinancingAttemptRepository($db); $locks=new U\OperationLockRepository($db);
        $materialization=H::buildService($db->orders,$attempts,$locks,new InMemoryOrderCorrelationRepository());
        $address=new U\OpenCartCatalogAddressResolver(static fn()=>false, static fn()=>null, static fn(array $p,U\FinancingCustomerData $c)=>H::address(),static fn()=>null);
        $transport=new FakeCpHttpTransport(); $transport->enableAutoAuthAndCreate(901);
        $settings=Phase4TestHarness::settings(); Phase4TestHarness::prepareCredentials($settings,7);
        $client=new U\ControlPanelClient(new U\ModuleCredentialsRepository($settings,Phase4TestHarness::cipher()),new U\CpTokenRepository($settings,Phase4TestHarness::cipher(),7),$transport,Phase4TestHarness::TEST_SHOP_URL,7);
        $cp=new U\ControlPanelOrderLifecycleService($attempts,$locks,$client,new U\ControlPanelOrderPayloadBuilder());
        $common=[$shop,7,$s->submissionToken,$actor,'synthetic-session',$customer,1];
        $tail=[$s->shopUnicid,'2026-01-01 00:00:00',1,'bg-bg',1,1.0,'Synthetic','https://example.test/','INV-',U\LockOwnerTokenGenerator::generate()];
        $posted=P::validPostedCustomer();
        if ($entry==='product') {
            $service=new U\ProductFinancingSubmissionService($attempts,$locks,$materialization,P::factory(),new U\ProductSchemeCalculator($calculator,new U\CurrencyGate(),new U\AmountDisplayFormatter()),new U\ProductCustomerValidator(),new U\ProductAddressValidator(),$address,new U\ConsentResolver(),new U\OpenCartProductOrderDraftBuilder(new U\ProductOrderDraftFactory()),new U\PersistenceClock(),$calculator,$cp);
            $args=[...$common,42,1,[],'EUR','standard','standard','KOPSTD',12,0,$key,0.0,$posted,...$tail];
        } else {
            $cartCalculator=new U\CartSchemeCalculator($calculator,$resolver,new U\CurrencyGate(),new U\AmountDisplayFormatter());
            $deps=[$attempts,$locks,$materialization,$cartCalculator,$calculator,$resolver,$entry==='checkout'?new U\CheckoutCustomerValidator():new U\ProductCustomerValidator(),new U\ProductAddressValidator(),$address,new U\ConsentResolver(),new U\CartOrderDraftFactory(),new U\PersistenceClock(),$cp];
            $service=$entry==='cart'?new U\CartFinancingSubmissionService(...$deps):new U\CheckoutFinancingSubmissionService(...[...$deps,$db->orders]);
            $args=[...$common,$cart,$fingerprint,'EUR','standard','standard','KOPSTD',12,0,$key,0.0,$posted,$draft->products,$draft->totals,$draft->orderTotal,false,...$tail];
            if ($entry==='checkout') {
                $native=(new U\OpenCartOrderDataBuilder())->build($draft)+['order_id'=>77,'order_status_id'=>0];
                $native['telephone']='';
                $db->orders->seedExistingOrder(77,$native,$draft->products,$draft->totals);
                $args=[...$args,77,$native];
            }
        }
        return [$service,$args,$db,$cp,$transport,$s];
    }
}

/** Sequential SQL fixture for field-removal behavior; concurrency/CAS has dedicated existing suites. */
final class ProcessTwoFieldsDb implements U\DbConnection
{
    public ConsentFakeDb $data;
    public InMemoryCheckoutOrderAdapter $orders;
    public array $products=[];
    public array $native=[];
    private bool $rejectCpSubmitOnce=true;
    public array $queries=[];
    public ?array $bank=null;
    public ?array $diagnostic=null;
    private int $affected=0;
    public function __construct() { $this->data=new ConsentFakeDb(); $this->data->simulateCpLifecycle=true; $this->orders=new InMemoryCheckoutOrderAdapter(); }
    public function query(string $sql): object
    {
        $this->queries[]=$sql; $this->affected=0; $sql=trim($sql);
        $result=static fn(array $rows)=>(object)['rows'=>$rows,'row'=>$rows[0]??[],'num_rows'=>count($rows)];
        if (str_contains($sql,'diagnostic_debug_log') && str_starts_with($sql,'SELECT')) return $result($this->diagnostic===null?[]:[$this->diagnostic]);
        if (preg_match('/FROM `consent_order`.*?`order_id` = (\d+)/s',$sql,$m)) return $result([$this->orders->getOrder((int)$m[1]) + $this->native]);
        if (str_contains($sql,'FROM `consent_order_product`')) return $result($this->products);
        if (str_contains($sql,'order_bank_status')) {
            if (str_starts_with($sql,'INSERT') && preg_match("/VALUES\s*\(\s*(\d+),\s*(\d+),\s*'([^']*)',\s*'([^']*)',\s*'([^']*)'/s",$sql,$m)) $this->bank=['order_id'=>(int)$m[2],'order_reference'=>$m[3],'status_id'=>$m[4],'status_label'=>$m[5]];
            return $result($this->bank===null?[]:[$this->bank]);
        }
        if (str_contains($sql, 'AND EXISTS (SELECT 1 FROM `consent_mt_uni_credit_operation_lock`')
            || str_contains($sql, 'SET `cp_payload`')) {
            $this->data->failCpClaim = $this->rejectCpSubmitOnce && str_contains($sql, "SET `state` = 'cp_submitting'");
            if ($this->data->failCpClaim) {
                $this->rejectCpSubmitOnce = false;
            }
            $value = $this->data->query($sql);
            $this->affected = $this->data->countAffected();
            return $value;
        }
        if (str_starts_with($sql,'UPDATE') && str_contains($sql,'financing_attempt') && !str_contains($sql,'SET `consent_context_json`') && preg_match('/WHERE `attempt_id` = (\d+)/',$sql,$m)) {
            $id=(int)$m[1];
            $set=substr($sql,strpos($sql,'SET')+3,strpos($sql,'WHERE')-strpos($sql,'SET')-3);
            preg_match_all("/`([^`]+)`\s*=\s*(?:'((?:\\\\.|[^'\\\\])*)'|(NULL|\d+))/s",$set,$values,PREG_SET_ORDER);
            foreach ($values as $v) $this->data->rows[$id][$v[1]]=isset($v[3])&&$v[3]!==''?($v[3]==='NULL'?null:(int)$v[3]):stripcslashes($v[2]);
            $this->affected=1; return $result([]);
        }
        $value=$this->data->query($sql); $this->affected=$this->data->countAffected(); return $value;
    }
    public function escape(string $s): string { return addslashes($s); }
    public function countAffected(): int { return $this->affected; }
    public function getLastId(): int { return $this->data->getLastId(); }
    public function getPrefix(): string { return 'consent_'; }
}
