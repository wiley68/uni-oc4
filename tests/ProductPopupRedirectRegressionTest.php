<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class ProductPopupRedirectRegressionTest extends TestCase
{
    public function testSubmitCarriesOneStrictSnapshotThroughValidationBeforeSideEffects(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php'
        );
        $start = strpos($source, 'public function submit(): void');
        $end = strpos($source, '/**', $start !== false ? $start : 0);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $submit = substr($source, $start, $end - $start);

        self::assertSame(1, substr_count($submit, 'getShopConfigurationForSubmission()'));
        self::assertStringContainsString('readSelectionContext($model, $shop)', $submit);
        self::assertStringNotContainsString('readSelectionContext($model)', $submit);
        self::assertLessThan(
            strpos($submit, '$service->submit('),
            strpos($submit, 'getShopConfigurationForSubmission()')
        );
    }

    public function testSuccessfulRedirectIsSerializedBeforeAnyNonEssentialResponseWork(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php'
        );
        self::assertStringContainsString('$payload = $result->toArray();', $source);
        self::assertStringContainsString('return $payload;', $source);

        $result = new \Opencart\System\Library\Extension\MtUniCredit\ProductFinancingResult(
            true,
            'bank_redirect',
            101,
            'ok',
            false,
            'completed',
            202,
            null,
            'https://onlinetest.ucfin.bg/sucf-online/Request/Start/session-ok',
            true
        );
        $payload = $result->toArray();
        self::assertTrue($payload['success']);
        self::assertSame('bank_redirect', $payload['step']);
        self::assertSame(
            'https://onlinetest.ucfin.bg/sucf-online/Request/Start/session-ok',
            $payload['redirect_url']
        );
    }
}
