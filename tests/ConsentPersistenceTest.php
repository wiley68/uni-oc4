<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\ConsentFakeDb;
use MtUniCredit\Tests\Support\ConsentFixture;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness as H;
use Opencart\System\Library\Extension\MtUniCredit\ConsentContext as C;
use Opencart\System\Library\Extension\MtUniCredit\DurableConsentAuthority as A;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use PHPUnit\Framework\TestCase;

final class ConsentPersistenceTest extends TestCase
{
    private function refused(callable $f): void
    {
        try { $f(); self::fail('Expected consent refusal'); } catch (ProductFinancingFlowException $e) { self::assertSame('invalid_consent',$e->errorCode()); }
    }
    private function seed(ConsentFakeDb $db, int $id=1, string $actor='actor', string $token='a', string $entry='product'): \Opencart\System\Library\Extension\MtUniCredit\ValidatedFinancingSubmission
    {
        $s=H::productSubmission($entry,$entry==='checkout'?77:null);$s->submissionToken=str_repeat($token,64);
        $db->rows[$id]=ConsentFixture::row($s,['attempt_id'=>$id,'actor_binding_hash'=>hash('sha256',$actor)],false);
        return $s;
    }
    public function testIssuedContextIsInTheInsertAndBoundToGeneratedToken(): void
    {
        $db=new ConsentFakeDb();$s=H::productSubmission();$seed=C::seed([],C::fromCalculation($s->financingCalculation));
        $row=(new FinancingAttemptRepository($db))->issueWithSubmissionToken($s->storeId,'product',$s->operationKeyHash,hash('sha256','actor'),$s->selectionHash,$s->shopUnicid,null,null,$seed);
        self::assertSame('issued',C::classify($row['consent_context_json'])); C::assertBinding(C::decode($row['consent_context_json']),$row);
        self::assertSame($row['submission_token'],C::decode($row['consent_context_json'])['issued']['binding']['submission_token']);
        self::assertCount(1,$db->effectQueries());self::assertStringContainsString('`consent_context_json`',$db->effectQueries()[0]);
    }
    public function testSameTokenFirstWinnerIsWriteOnceAndLostCommitAckReconciles(): void
    {
        $db=new ConsentFakeDb();$s=$this->seed($db);$db->loseCommitAck=true;$a=new A($db);
        $a->finalizeSubmission(1,$s,[],LockOwnerTokenGenerator::generate());$first=$db->rows[1]['consent_context_json'];
        $a->finalizeSubmission(1,$s,['POST B cannot replace A'],LockOwnerTokenGenerator::generate());
        self::assertSame($first,$db->rows[1]['consent_context_json']);self::assertSame(1,$db->evidenceWrites);self::assertSame([],$db->locks);
        $sql=implode("\n",$db->queries);self::assertStringContainsString('START TRANSACTION',$sql);self::assertStringContainsString('FOR UPDATE',$sql);self::assertStringContainsString('BINARY `consent_context_json`',$sql);self::assertStringContainsString('`order_id` IS NULL',$sql);
    }
    public function testConcurrentSameTokenWinnerSurvivesLosingCas(): void
    {
        $db=new ConsentFakeDb();$s=$this->seed($db);$issued=$db->rows[1]['consent_context_json'];
        // Concurrent commit becomes visible after this transaction rolls back.
        $winner=C::finalize($issued,1,[],'2026-01-01 00:00:02');
        $db->beforeUpdate=static function(ConsentFakeDb $db) use($winner): void { $db->rows[1]['consent_context_json']=$winner; $db->externalCommittedRows[1]=$db->rows[1]; };
        (new A($db))->finalizeSubmission(1,$s,[],LockOwnerTokenGenerator::generate());
        self::assertSame($winner,$db->rows[1]['consent_context_json']);
        self::assertSame(0,$db->evidenceWrites);
    }
    public function testOverlappingTokensCompeteButDifferentActorsDoNot(): void
    {
        foreach (['product','cart','checkout'] as $entry) {
            $db=new ConsentFakeDb();$first=$this->seed($db,1,'actor','a',$entry);$second=$this->seed($db,2,'actor','b',$entry);$third=$this->seed($db,3,'other','c',$entry);
            $a=new A($db);$a->finalizeSubmission(1,$first,[],LockOwnerTokenGenerator::generate());
            $winner=$db->rows[1]['consent_context_json'];
            $this->refused(fn()=>$a->finalizeSubmission(2,$second,[],LockOwnerTokenGenerator::generate()));
            if ($entry==='checkout') $this->refused(fn()=>$a->finalizeSubmission(3,$third,[],LockOwnerTokenGenerator::generate()));
            else $a->finalizeSubmission(3,$third,[],LockOwnerTokenGenerator::generate());
            self::assertSame($winner,$db->rows[1]['consent_context_json']);self::assertSame('issued',C::classify($db->rows[2]['consent_context_json']));
        }
    }
    public function testExpiryDoesNotFreeUnresolvedWinnerButResolvedSeparateWindowCanProceed(): void
    {
        $db=new ConsentFakeDb();$s=$this->seed($db);$a=new A($db);$a->finalizeSubmission(1,$s,[],LockOwnerTokenGenerator::generate());
        $next=$this->seed($db,2,'actor','b');
        $db->rows[1]=ConsentFixture::row($s,['attempt_id'=>1,'actor_binding_hash'=>hash('sha256','actor'),'created_at'=>'2025-01-01 00:00:00','expires_at'=>'2025-01-02 00:00:00'],true);
        $this->refused(fn()=>$a->finalizeSubmission(2,$next,[],LockOwnerTokenGenerator::generate()));
        $db->rows[1]['smartucf_state']='created';
        $a->finalizeSubmission(2,$next,[],LockOwnerTokenGenerator::generate());self::assertSame('finalized',C::classify($db->rows[2]['consent_context_json']));
    }
    public function testMalformedAndLegacyEvidenceCannotBeReplacedByPost(): void
    {
        foreach ([null,'{}','bad',''] as $raw) {
            $db=new ConsentFakeDb();$s=$this->seed($db);$db->rows[1]['consent_context_json']=$raw;
            $this->refused(fn()=>(new A($db))->finalizeSubmission(1,$s,[1],LockOwnerTokenGenerator::generate()));
            self::assertSame($raw,$db->rows[1]['consent_context_json']);self::assertSame(0,$db->evidenceWrites);
        }
    }
    public function testPruningProtectsFinalizedMalformedAndRacingFinalization(): void
    {
        $db=new ConsentFakeDb();$s=$this->seed($db);$db->rows[1]=ConsentFixture::row($s,['attempt_id'=>1,'created_at'=>'2025-01-01 00:00:00','expires_at'=>'2025-01-02 00:00:00'],false);$issued=$db->rows[1];$db->rows[2]=ConsentFixture::row($s,['attempt_id'=>2,'created_at'=>'2025-01-01 00:00:00','expires_at'=>'2025-01-02 00:00:00'],true);
        $db->rows[3]=$issued;$db->rows[3]['attempt_id']=3;$db->rows[3]['consent_context_json']='bad';
        (new FinancingAttemptRepository($db))->deleteExpiredPreOrderBatch();
        self::assertArrayNotHasKey(1,$db->rows);self::assertArrayHasKey(2,$db->rows);self::assertArrayHasKey(3,$db->rows);
        $redaction=(string)file_get_contents(dirname(__DIR__).'/system/library/financing_presentation_repository.php');
        self::assertStringNotContainsString('consent_context_json',$redaction);
    }
    public function testAuthorityRejectsSameTotalWithDifferentKopMonthsTypeOrFilter(): void
    {
        $db=new ConsentFakeDb();$s=$this->seed($db);(new A($db))->finalizeSubmission(1,$s,[],LockOwnerTokenGenerator::generate());
        foreach (['kopCode'=>'OTHER','months'=>24,'type'=>'promo','filterId'=>2] as $key=>$value) {
            $clone=clone $s;$clone->financingCalculation=clone $s->financingCalculation;$clone->financingCalculation->scheme=clone $s->financingCalculation->scheme;
            $clone->financingCalculation->scheme->$key=$value;
            $this->refused(fn()=>(new A($db))->requireFinalized(1,$clone));
        }
        self::assertSame(1,$db->evidenceWrites);
    }
    public function testNewIssuanceWithoutEvidenceIsRefusedBeforeInsert(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission();
        try {
            (new FinancingAttemptRepository($db))->issueWithSubmissionToken($s->storeId, 'product', $s->operationKeyHash, hash('sha256','actor'), $s->selectionHash, $s->shopUnicid);
            self::fail('New evidence-free attempt was issued');
        } catch (\Opencart\System\Library\Extension\MtUniCredit\PersistenceValidationException $expected) {
            self::assertStringContainsString('server-issued consent', $expected->getMessage());
        }
        self::assertSame([], $db->effectQueries());
    }

    public function testMissingAcceptanceDoesNotFinalizeAndWinnerReceiptCannotBeReplaced(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission();
        $db->rows[1] = ConsentFixture::row($s, [], false, ['consents'=>[['id'=>1,'name'=>'Terms','mandatory'=>1]]]);
        $issued = $db->rows[1]['consent_context_json']; $a = new A($db);
        $this->refused(fn() => $a->finalizeSubmission(1, $s, [], LockOwnerTokenGenerator::generate()));
        self::assertSame($issued, $db->rows[1]['consent_context_json']); self::assertSame(0, $db->evidenceWrites);
        $a->finalizeSubmission(1, $s, ['1'], LockOwnerTokenGenerator::generate());
        $winner = $db->rows[1]['consent_context_json'];
        $a->finalizeSubmission(1, $s, ['bad POST B'], LockOwnerTokenGenerator::generate());
        self::assertSame($winner, $db->rows[1]['consent_context_json']);
        self::assertSame([1], C::decode($winner)['finalized']['accepted_ids']); self::assertSame(1, $db->evidenceWrites);
    }

    public function testMalformedPreferredEvidenceCannotFallBackToNewToken(): void
    {
        $db = new ConsentFakeDb(); $s = $this->seed($db); $db->rows[1]['consent_context_json'] = '{}';
        $issuer = new \Opencart\System\Library\Extension\MtUniCredit\ProductSubmissionIssuer(new FinancingAttemptRepository($db), new PersistenceClock());
        $this->refused(fn() => $issuer->issueOrReuse($s->storeId, $s->operationKeyHash, hash('sha256','actor'), $s->selectionHash, $s->submissionToken, $s->shopUnicid, C::seed([], C::fromCalculation($s->financingCalculation))));
        self::assertCount(1, $db->rows); self::assertSame([], $db->effectQueries());
    }

    public function testPruningCannotDeleteAnAttemptFinalizedAfterSelection(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission();
        $db->rows[1] = ConsentFixture::row($s, ['created_at'=>'2025-01-01 00:00:00','expires_at'=>'2025-01-02 00:00:00'], false);
        $db->beforeDelete = static function(ConsentFakeDb $db): void {
            $db->rows[1]['consent_context_json'] = C::finalize($db->rows[1]['consent_context_json'], 1, [], '2025-01-01 00:00:01');
        };
        self::assertSame(0, (new FinancingAttemptRepository($db))->deleteExpiredPreOrderBatch());
        self::assertSame('finalized', C::classify($db->rows[1]['consent_context_json']));
    }

    public function testUnfinalizedScopedRawInterestDriftRefusesEvenWhenDisplayedGlpIsUnchanged(): void
    {
        $db = new ConsentFakeDb(); $s = H::productSubmission();
        $shop = ['consents'=>[['id'=>1,'name'=>'Zero','mandatory'=>1,'interest_scope'=>'zero_interest']],
            'coeff_list'=>[['onlineProductCode'=>'KOP1','installmentCount'=>12,'coeff'=>0.1,'interestPercent'=>0]]];
        $db->rows[1] = ConsentFixture::row($s, [], false, $shop); $issued = $db->rows[1]['consent_context_json'];
        $shop['coeff_list'][0]['interestPercent'] = 0.00002; // both display as 0.00, but applicability changes.
        $this->refused(fn() => (new A($db))->finalizeSubmission(1, $s, [1], LockOwnerTokenGenerator::generate(), 1, $shop));
        self::assertSame($issued, $db->rows[1]['consent_context_json']); self::assertSame(0, $db->evidenceWrites);
    }

    public function testAllOnlyAndEmptyConfigurationsDoNotAcquireRawInterestDriftBlock(): void
    {
        foreach ([[], [['id'=>1,'name'=>'All','mandatory'=>1]]] as $descriptors) {
            $db = new ConsentFakeDb(); $s = H::productSubmission(); $shop = ['consents'=>$descriptors];
            $db->rows[1] = ConsentFixture::row($s, [], false, $shop);
            $shop['coeff_list'] = [['onlineProductCode'=>'KOP1','installmentCount'=>12,'coeff'=>0.1,'interestPercent'=>0.00002]];
            (new A($db))->finalizeSubmission(1, $s, $descriptors === [] ? [] : [1], LockOwnerTokenGenerator::generate(), 1, $shop);
            self::assertSame('finalized', C::classify($db->rows[1]['consent_context_json']));
        }
    }

}
