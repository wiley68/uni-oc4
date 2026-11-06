<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\ConsentFixture;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness as H;
use Opencart\System\Library\Extension\MtUniCredit\ConsentContext as C;
use Opencart\System\Library\Extension\MtUniCredit\ConsentInterestAuthority as I;
use Opencart\System\Library\Extension\MtUniCredit\ConsentResolver as R;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsentDomainTest extends TestCase
{
    public static function scopes(): array
    {
        return array_map(static fn($v): array => [$v, in_array($v, ['all','zero_interest','nonzero_interest'], true) ? $v : 'all'], ['all','zero_interest','nonzero_interest','bad','',null,1,[],false]);
    }
    #[DataProvider('scopes')]
    public function testScopeNormalization(mixed $value, string $expected): void { self::assertSame($expected, R::scope($value)); }

    public static function interest(): array
    {
        return [[0,'zero_interest'],[-0.00001,'zero_interest'],[0.00001,'zero_interest'],[0.0000100001,'nonzero_interest'],[-0.0000100001,'nonzero_interest'],['0.000009','zero_interest'],['bad','unknown'],[null,'unknown'],[INF,'unknown'],[NAN,'unknown'],[true,'unknown']];
    }
    #[DataProvider('interest')]
    public function testRawEpsilon(mixed $raw, string $expected): void
    {
        self::assertSame($expected, I::classify(['coeff_list' => [['onlineProductCode'=>'K','installmentCount'=>12,'interestPercent'=>$raw,'coeff'=>0.1]]], 'K', 12)['classification']);
    }
    public function testConflictingAndInvalidMatchingRowsAreOrderIndependent(): void
    {
        $base = ['onlineProductCode'=>'K','installmentCount'=>12,'interestPercent'=>0,'coeff'=>0.1];
        foreach ([['interestPercent'=>4],['coeff'=>0],['coeff'=>INF],['coeff'=>'oops'],['interestPercent'=>null]] as $bad) {
            $rows = [$base, array_replace($base,$bad)];
            self::assertSame('unknown', I::classify(['coeff_list'=>$rows],'K',12)['classification']);
            self::assertSame(I::classify(['coeff_list'=>$rows],'K',12), I::classify(['coeff_list'=>array_reverse($rows)],'K',12));
        }
        self::assertSame('unknown', I::classify([], 'K', 12)['classification']);
    }
    public function testScopedInformationalUnknownRefusesWhileAllAndEmptyDoNot(): void
    {
        $s=H::productSubmission(); $calc=C::fromCalculation($s->financingCalculation);
        self::assertSame([],C::seed([], $calc)['mandatory_ids']);
        self::assertSame([1],C::seed(['consents'=>[['id'=>1,'name'=>'All','mandatory'=>1]]],$calc)['mandatory_ids']);
        $this->expectException(ProductFinancingFlowException::class);
        C::seed(['consents'=>[['id'=>1,'name'=>'Info','mandatory'=>0,'interest_scope'=>'zero_interest']]],$calc);
    }
    public function testOnlyApplicableMandatoryIdsAreAccepted(): void
    {
        $shop=['consents'=>[['id'=>1,'name'=>'All','mandatory'=>1],['id'=>2,'name'=>'Zero','mandatory'=>1,'interest_scope'=>'zero_interest'],['id'=>3,'name'=>'Nonzero','mandatory'=>1,'interest_scope'=>'nonzero_interest'],['id'=>4,'name'=>'Info','mandatory'=>0]]];
        $r=new R();$ds=$r->applicable($r->normalize($shop),'zero_interest');
        self::assertSame([1,2,4],array_column($ds,'id'));
        self::assertSame([1,2],$r->validateDescriptors($ds,['2',1]));
        self::assertFalse($ds[2]['has_checkbox']);
    }
    public static function invalidIds(): array { return array_map(static fn($v): array => [$v], ['1',[true],[1.0],['1x'],['01'],['1.0'],['1e0'],[[]],[1,1],[1,999],[1,2],[0],[-1],['99999999999999999999999999999'],['1,2']]); }
    #[DataProvider('invalidIds')]
    public function testStrictIds(mixed $ids): void
    {
        $this->expectException(ProductFinancingFlowException::class);
        (new R())->validateDescriptors((new R())->normalize(['consents'=>[['id'=>1,'name'=>'Required','mandatory'=>1],['id'=>2,'name'=>'Info','mandatory'=>0]]]),$ids);
    }
    public function testCanonicalIssuedAndFinalizedAndPersistedAcceptance(): void
    {
        $row=ConsentFixture::row(H::productSubmission(),[],false);
        $raw=$row['consent_context_json'];self::assertSame('issued',C::classify($raw));
        $record=C::decode($raw);self::assertNull($record['finalized']);self::assertSame($raw,C::json($record));
        $final=C::finalize($raw,1,[],'2026-01-01 00:00:01'); self::assertSame('finalized',C::classify($final));
        self::assertSame($final,C::finalize($final,1,['bogus'],'2027-01-01 00:00:01'));
        self::assertSame($record['issued_hash'],C::decode($final)['issued_hash']);
        self::assertNotNull(C::decode($final)['finalized_hash']);
        self::assertStringNotContainsString('ivan@example',$raw);
        self::assertSame('legacy_absent',C::classify(null));
        foreach (['','null','{}','[]','bad',$raw.' ',str_replace('"version":1','"version":2',$raw),str_replace('"monthly_installment":100.0','"monthly_installment":101.0',$raw)] as $bad) self::assertSame('malformed',C::classify($bad));
    }
    public function testInvalidMatchingCodeAndMonthsCannotDisappearBehindAValidRow(): void
    {
        $valid = ['onlineProductCode'=>'123','installmentCount'=>12,'interestPercent'=>0,'coeff'=>0.1];
        foreach ([['onlineProductCode'=>123], ['installmentCount'=>'12garbage'], ['installmentCount'=>12.5]] as $invalid) {
            $rows = [$valid, array_replace($valid, $invalid)];
            self::assertSame('unknown', I::classify(['coeff_list'=>$rows], '123', 12)['classification']);
            self::assertSame('unknown', I::classify(['coeff_list'=>array_reverse($rows)], '123', 12)['classification']);
        }
    }

}
