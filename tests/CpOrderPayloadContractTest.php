<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\CpOrderPayloadConstraints;
use MtUniCredit\Tests\Support\FixtureLoader;
use PHPUnit\Framework\TestCase;

final class CpOrderPayloadContractTest extends TestCase
{
    public function testValidSampleSatisfiesControlPanelLimits(): void
    {
        $fixture = FixtureLoader::load('cp_order_payload.json');
        $sample = $fixture['valid_sample'];

        self::assertSame([], CpOrderPayloadConstraints::violations($sample));
        self::assertLessThanOrEqual(CpOrderPayloadConstraints::ORDER_ID_MAX, strlen($sample['order_id']));
        self::assertContains($sample['currency'], CpOrderPayloadConstraints::CURRENCIES);
        self::assertSame(1, preg_match(CpOrderPayloadConstraints::VERSION_PATTERN, $sample['version']));
        self::assertSame('2.0.2', $sample['version']);
        self::assertContains('order_id', $fixture['ps9_create_field_order']);
        self::assertSame(
            CpOrderPayloadConstraints::IDEMPOTENCY_SEMANTIC_FIELDS,
            $fixture['idempotency']['semantic_fields']
        );
    }

    public function testInvalidSamplesViolateDocumentedLimits(): void
    {
        $fixture = FixtureLoader::load('cp_order_payload.json');
        $base = $fixture['valid_sample'];

        foreach ($fixture['invalid_samples'] as $sample) {
            $payload = $base;
            foreach ($sample['override'] ?? [] as $key => $value) {
                $payload[$key] = $value;
            }
            foreach ($sample['remove'] ?? [] as $key) {
                unset($payload[$key]);
            }

            self::assertNotSame(
                [],
                CpOrderPayloadConstraints::violations($payload),
                $sample['reason']
            );
        }
    }

    public function testUsdIsRejectedDespiteErrorMessageMentioningUsd(): void
    {
        $fixture = FixtureLoader::load('cp_order_payload.json');
        self::assertStringContainsString('USD', $fixture['currency_message_discrepancy']);
        self::assertSame(['BGN', 'EUR'], $fixture['limits']['currency']['in']);
        self::assertSame('BGN', $fixture['limits']['currency']['api_default']);
        self::assertSame('EUR', $fixture['limits']['currency']['db_default']);
    }

    public function testPhase10BCreateOmitsBankSentStatusesIncludingProcess2(): void
    {
        $extra = FixtureLoader::load('cp_order_payload.json')['process2_extra_fields'];
        self::assertSame('omit status and status_id (CP defaults cp_sent)', $extra['phase_10b_create']);
        self::assertSame('bank_sent_process2', $extra['phase_11_status_id']);
        self::assertStringContainsString('Phase 11', $extra['when']);

        $semantics = FixtureLoader::load('status_vocabulary.json')['process_semantics'];
        self::assertFalse($semantics['process_1']['phase_10b_cp_create_status_fields']);
        self::assertFalse($semantics['process_2']['phase_10b_cp_create_status_fields']);
    }

    public function testApiEndpointCatalogIsComplete(): void
    {
        $endpoints = FixtureLoader::load('cp_api_endpoints.json')['endpoints'];
        $paths = array_map(static fn(array $row): string => $row['method'] . ' ' . $row['path'], $endpoints);
        self::assertSame([
            'POST /api/v1/auth/login',
            'POST /api/v1/auth/refresh',
            'POST /api/v1/auth/logout',
            'GET /api/v1/shop',
            'POST /api/v1/orders',
            'PATCH /api/v1/orders/status',
        ], $paths);
    }
}
