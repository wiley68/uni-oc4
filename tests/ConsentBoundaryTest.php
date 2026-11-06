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
use MtUniCredit\Tests\Support\RecordingStatusPort;
use Opencart\System\Library\Extension\MtUniCredit as U;
use PHPUnit\Framework\TestCase;

final class ConsentBoundaryTest extends TestCase
{
    private function refused(callable $f): void
    {
        try { $f();self::fail('Consent refusal required'); } catch (U\ProductFinancingFlowException $e) { self::assertSame('invalid_consent',$e->errorCode()); }
    }
    private function row(ConsentFakeDb $db, U\ValidatedFinancingSubmission $s, int $process=1): void
    {
        $db->rows[1]=ConsentFixture::row($s,['state'=>'cp_created','order_id'=>77,'control_panel_order_id'=>99],true,['uni_proces'=>$process===2?1:0]);
        $db->rows[1]['cp_payload']=json_encode(['order_id'=>'77','currency'=>'EUR','price'=>1200.0,'parva'=>0.0,'vnoska'=>100.0,'vnoski'=>12,'gpr'=>0.0,'products_id'=>'42','products_q'=>'2']);
        $db->rows[1]['leasing_presentation_json']=json_encode(['shop_order_id'=>77,'financed_amount'=>1200,'first_installment'=>0,'monthly_installment'=>100]);
        $db->rows[1]['smartucf_state']='not_started';$db->rows[1]['process2_state']='not_started';
        $db->orderLines[77]=$s->orderDraft->products;
        $db->orders[77]=['order_id'=>77,'store_id'=>$s->storeId,'total'=>1200.0,'currency_code'=>'EUR','currency_id'=>1,'currency_value'=>1.0];
    }
    private function cp(ConsentFakeDb $db, FakeCpHttpTransport $transport): U\ControlPanelOrderLifecycleService
    {
        $settings=Phase4TestHarness::settings();Phase4TestHarness::prepareCredentials($settings);
        $client=new U\ControlPanelClient(new U\ModuleCredentialsRepository($settings,Phase4TestHarness::cipher()),new U\CpTokenRepository($settings,Phase4TestHarness::cipher(),Phase4TestHarness::TEST_STORE_ID),$transport,Phase4TestHarness::TEST_SHOP_URL,Phase4TestHarness::TEST_STORE_ID);
        return new U\ControlPanelOrderLifecycleService(new U\FinancingAttemptRepository($db),new U\OperationLockRepository($db),$client,new U\ControlPanelOrderPayloadBuilder());
    }
    private function coordinator(ConsentFakeDb $db, object $client, FakeCpHttpTransport $transport, RecordingStatusPort $port): U\SmartUcfSessionCoordinator
    {
        $cp=$this->cp($db,$transport);
        return new U\SmartUcfSessionCoordinator(new U\SmartUcfLifecycleRepository($db),$client,new U\SmartUcfFailureClassifier(),new U\OrderBankStatusRepository($db),$cp->client(),new U\ControlPanelStatusSyncService(new U\ControlPanelStatusSyncRepository($db),$port,new U\DurableConsentAuthority($db)));
    }
    public function testDirectMaterializersRefuseAbsentIssuedAndMalformedBeforeOrderEffects(): void
    {
        foreach (['product','cart','checkout'] as $entry) foreach ([null,'issued','bad'] as $kind) {
            $s=H::productSubmission($entry,$entry==='checkout'?77:null);
            $row=ConsentFixture::row($s,[],false);if ($kind!=='issued')$row['consent_context_json']=$kind;
            $db=new ConsentFakeDb();$db->rows[1]=$row;
            $orders=new InMemoryCheckoutOrderAdapter();$correlation=new InMemoryOrderCorrelationRepository();
            $gateway=$entry==='checkout'?new U\CheckoutExistingOrderGateway($orders,new U\OpenCartOrderVerifier(),new U\FinancingOrderStatusPolicy(25,16),new U\DurableConsentAuthority($db)):H::buildMaterializer($orders,$correlation,$db);
            $this->refused(fn()=>$entry==='checkout'?$gateway->materialize($s,new U\FinancingAttemptContext($row)):$gateway->materializeNew($s,new U\FinancingAttemptContext($row)));
            self::assertSame(0,$orders->addOrderCallCount());
        }
    }
    public function testDirectProcess1RejectsMismatchedHandoffBeforeClaimOrBankingClassifier(): void
    {
        foreach (['kopCode'=>'OTHER','months'=>24,'type'=>'promo','filterId'=>4] as $key=>$value) {
            $db=new ConsentFakeDb();$s=H::productSubmission();$this->row($db,$s);
            $transport=new FakeCpHttpTransport();$port=new RecordingStatusPort();$client=new class { public int $calls=0;public function createSession():array{++$this->calls;throw new \LogicException('Must not send');} };
            $coordinator=$this->coordinator($db,$client,$transport,$port);
            $s->financingCalculation->scheme->$key=$value;
            $this->refused(fn()=>$coordinator->run(1,[], $s,77,99));
            self::assertSame([],$db->effectQueries());self::assertSame(0,$client->calls);self::assertSame(0,$port->calls);self::assertSame(0,$transport->countOrderCreates());
        }
    }
    public function testCpRetryAndCpCreatedRefuseLegacyWithoutStartingBankActivity(): void
    {
        foreach (['order_created','cp_failed_retryable','cp_created'] as $state) {
            $db=new ConsentFakeDb();$s=H::productSubmission();$this->row($db,$s);$db->rows[1]['state']=$state;$db->rows[1]['consent_context_json']=null;
            $transport=new FakeCpHttpTransport();$cp=$this->cp($db,$transport);
            $this->refused(fn()=>$state==='cp_created'?U\FinancingControlPanelCompletion::resumeExistingCp($cp,1,$s,77,99,[]):$cp->submitOrRecover(new U\FinancingAttemptContext($db->rows[1]),$s,77,[],U\LockOwnerTokenGenerator::generate()));
            self::assertSame([],$db->effectQueries());self::assertSame(0,$transport->countOrderCreates());
        }
    }
    public function testProcess2PreparationStatusPatchMailAndDirectSensitivePersistRefuse(): void
    {
        foreach ([null,'bad','issued'] as $kind) {
            $db=new ConsentFakeDb();$s=H::productSubmission();$this->row($db,$s,2);
            $db->rows[1]['consent_context_json']=$kind==='issued'?ConsentFixture::row($s,[],false,['uni_proces'=>1])['consent_context_json']:$kind;
            $port=new RecordingStatusPort();$sync=new U\ControlPanelStatusSyncService(new U\ControlPanelStatusSyncRepository($db),$port,new U\DurableConsentAuthority($db));$mailer=new U\RecordingProcessTwoMailer();
            $coordinator=new U\ProcessTwoLifecycleCoordinator(new U\ProcessTwoLifecycleRepository($db),new U\OrderBankStatusRepository($db),$sync,new U\ProcessTwoSensitiveCipher(str_repeat('x',32)),$mailer);
            $this->refused(fn()=>$coordinator->run(1,$s->storeId,77,['uni_proces'=>1],['order_id'=>77]));
            $this->refused(fn()=>U\ProcessTwoSubmissionSupport::persist($s,1,$db,new U\ProcessTwoSensitiveData('1990011599','0888123456'),str_repeat('x',32)));
            $this->refused(fn()=>$sync->admitTarget(1,U\BankStatus::SENT_PROCESS2,U\BankStatus::LABEL_SENT_PROCESS2));
            self::assertSame([],$db->effectQueries());self::assertSame([],$mailer->sent);self::assertSame(0,$port->calls);
        }
    }
    public function testRecordedLegacyProcess1AndProcess2RemainReadOnly(): void
    {
        $db=new ConsentFakeDb();$s=H::productSubmission();$this->row($db,$s);$db->rows[1]['consent_context_json']=null;
        // The historical EUR/payload proof remains required alongside the recorded outcome.
        $db->rows[1]['leasing_presentation_json']=json_encode(['shop_order_id'=>77,'financed_amount'=>1200,'first_installment'=>0,'monthly_installment'=>100]);
        $db->rows[1]['smartucf_state']='created';$db->rows[1]['smartucf_session_id']='session';$db->rows[1]['smartucf_redirect_url']='https://onlinetest.ucfin.bg/sucf-online/Request/Start/session';
        $transport=new FakeCpHttpTransport();$port=new RecordingStatusPort();$client=new class{public function createSession():array{throw new \LogicException('Must not send');}};
        self::assertTrue($this->coordinator($db,$client,$transport,$port)->run(1,[],$s,77,99)->isCreated());
        $db->rows[1]['process2_state']='process2_prepared';$mailer=new U\RecordingProcessTwoMailer();
        $sync=new U\ControlPanelStatusSyncService(new U\ControlPanelStatusSyncRepository($db),$port,new U\DurableConsentAuthority($db));
        $p2=new U\ProcessTwoLifecycleCoordinator(new U\ProcessTwoLifecycleRepository($db),new U\OrderBankStatusRepository($db),$sync,new U\ProcessTwoSensitiveCipher(str_repeat('x',32)),$mailer);
        self::assertTrue($p2->run(1,$s->storeId,77,[],[])->success);self::assertSame([],$db->effectQueries());self::assertSame([],$mailer->sent);self::assertSame(0,$port->calls);
    }
    public function testNewEvidenceSurvivesPresentationRedactionWithNativeEurProof(): void
    {
        $db=new ConsentFakeDb();$s=H::productSubmission();$this->row($db,$s);$db->rows[1]['leasing_presentation_json']=null;
        self::assertSame(1200.0,(new U\DurableEurOrderGuard($db))->prove(1,$s->storeId,77,true)->eurTotal);
        $db->orders[77] = (new U\OpenCartOrderDataBuilder())->build($s->orderDraft) + $db->orders[77];
        $db->orderLines[77] = $s->orderDraft->products;
        $resume = U\ResumeSubmissionFactory::create('product', $s->storeId, $s->submissionToken, $s->operationKeyHash, 77);
        $proof = (new U\DurableEurOrderGuard($db))->prove(1, $s->storeId, 77, true);
        (new U\DurableEurResumeHydrator($db))->hydrate(1, $resume, $proof);
        self::assertSame(U\ConsentContext::fromCalculation($s->financingCalculation), U\ConsentContext::fromCalculation($resume->financingCalculation));
        (new U\DurableConsentAuthority($db))->requireFinalized(1, $resume, 77);

        $db->orders[77]['currency_code']='BGN';
        try {(new U\DurableEurOrderGuard($db))->prove(1,$s->storeId,77,true);self::fail('Native EUR proof remains mandatory');}
        catch(U\ProductFinancingFlowException $e){self::assertSame('currency_unavailable',$e->errorCode());}
    }
    public function testDirectCommerceAndNativeSameTotalSubstitutionAreRefused(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission(); $this->row($db, $s);
        $s->orderDraft->products[0]['product_id'] = 99;
        $this->refused(fn() => (new U\DurableConsentAuthority($db))->requireFinalized(1, $s, 77));
        $s->orderDraft->products[0]['product_id'] = 42;
        $db->orderLines[77][0]['product_id'] = 99;
        $this->refused(fn() => (new U\DurableConsentAuthority($db))->requireFinalized(1, $s, 77));
        self::assertSame([], $db->effectQueries());
    }

    public function testDirectStatusCannotPatchAnotherOrderOrOppositeProcess(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission(); $this->row($db, $s);
        $db->rows[1]['cp_status_sync_state'] = 'pending'; $db->rows[1]['cp_status_sync_status_id'] = U\BankStatus::SENT_PROCESS1;
        $db->rows[1]['cp_status_sync_status'] = U\BankStatus::LABEL_SENT_PROCESS1;
        $port = new RecordingStatusPort(); $sync = new U\ControlPanelStatusSyncService(new U\ControlPanelStatusSyncRepository($db), $port, new \Opencart\System\Library\Extension\MtUniCredit\DurableConsentAuthority($db));
        $this->refused(fn() => $sync->retryPending(1, '999'));
        $this->refused(fn() => $sync->admitTarget(1, U\BankStatus::SENT_PROCESS2, U\BankStatus::LABEL_SENT_PROCESS2));
        self::assertSame(0, $port->calls); self::assertSame([], $db->effectQueries());
    }

    public function testVisibleOrderHistoryAndNativeMailAreRefusedWithoutConsent(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission(); $this->row($db, $s); $db->rows[1]['consent_context_json'] = null;
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = H::buildService($orders, new U\FinancingAttemptRepository($db), new U\OperationLockRepository($db), new InMemoryOrderCorrelationRepository());
        $created = new U\CreatedOpenCartOrder(77, $s->storeId, 1200, 'EUR', [], [], U\PaymentIdentity::optionCode(), 0);
        $this->refused(fn() => $service->applyProductCartVisibleStatus($created, 'product'));
        self::assertSame(0, $orders->historyCountFor(77)); self::assertSame([], $db->effectQueries());
    }

    public function testFinalizedBeforeMaterializationKeepsFrozenConditionsWhenCurrentAuthorityDisappears(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission();
        $shop = ['consents'=>[['id'=>1,'name'=>'Zero','mandatory'=>1,'interest_scope'=>'zero_interest']],
            'coeff_list'=>[['onlineProductCode'=>'KOP1','installmentCount'=>12,'coeff'=>0.1,'interestPercent'=>0]]];
        $row = ConsentFixture::row($s, [], true, $shop); $db->rows[1] = $row;
        $record = U\ConsentContext::decode($row['consent_context_json']);
        $s->financingCalculation = U\ConsentContext::frozenCalculation($record);
        $shop['coeff_list'] = [];
        (new U\DurableConsentAuthority($db))->finalizeSubmission(1, $s, ['POST B'], U\LockOwnerTokenGenerator::generate(), 1, $shop);
        self::assertSame($row['consent_context_json'], $db->rows[1]['consent_context_json']);
        $orders = new InMemoryCheckoutOrderAdapter();
        $created = H::buildMaterializer($orders, new InMemoryOrderCorrelationRepository(), $db)->materializeNew($s, new U\FinancingAttemptContext($row));
        self::assertGreaterThan(0, $created->orderId); self::assertSame(1, $orders->addOrderCallCount()); self::assertSame(0, $db->evidenceWrites);
        self::assertSame('KOP1', $s->financingCalculation->scheme->kopCode);
    }

    public function testCheckoutControllerStatusAuthorityKeepsLegacyRecordedOutcomeReadOnly(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission('checkout', 77); $this->row($db, $s);
        $service = (new \ReflectionClass(U\CheckoutFinancingSubmissionService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'attempts'))->setValue($service, new U\FinancingAttemptRepository($db));
        (new \ReflectionProperty($service, 'clock'))->setValue($service, new U\PersistenceClock());
        $actor = $db->rows[1]['actor_binding_hash'];
        self::assertTrue($service->guardOrderStatus($s->storeId, $s->submissionToken, $actor, 77));
        $db->rows[1]['consent_context_json'] = null; $db->rows[1]['smartucf_state'] = 'created';
        self::assertFalse($service->guardOrderStatus($s->storeId, $s->submissionToken, $actor, 77));
        self::assertSame([], $db->effectQueries());
    }

}
