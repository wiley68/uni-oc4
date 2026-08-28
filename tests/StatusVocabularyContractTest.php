<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FixtureLoader;
use PHPUnit\Framework\TestCase;

final class StatusVocabularyContractTest extends TestCase
{
    public function testModuleOutboundBankStatusesAreExact(): void
    {
        $fixture = FixtureLoader::load('status_vocabulary.json');
        $ids = array_column($fixture['module_outbound_bank_status'], 'status_id');
        $labels = array_column($fixture['module_outbound_bank_status'], 'status_label');

        self::assertSame([
            'bank_sent_process1',
            'bank_sent_process2',
            'bank_send_failed',
            'bank_send_failed_cp',
            'bank_send_failed_smartucf',
        ], $ids);

        self::assertSame([
            'Изпратен Банка - Процес 1',
            'Изпратен Банка - Процес 2',
            'Неуспешно изпратен Банка',
            'Неуспешно изпратен Банка - КП',
            'Неуспешно изпратен Банка - SmartUCF',
        ], $labels);
    }

    public function testProcessFlagIsInvertedRelativeToProcessName(): void
    {
        $flag = FixtureLoader::load('status_vocabulary.json')['process_flag'];
        self::assertSame('uni_proces', $flag['field']);
        self::assertSame(1, $flag['process_2_when']);
        self::assertTrue($flag['process_1_otherwise']);
    }

    public function testControlPanelEnumIsCapturedWithoutRenaming(): void
    {
        $enum = FixtureLoader::load('status_vocabulary.json')['cp_order_status_enum'];
        $defaults = array_values(array_filter($enum, static fn(array $row): bool => !empty($row['default_on_create'])));
        self::assertCount(1, $defaults);
        self::assertSame('Създаден в КП Банка', $defaults[0]['status']);
        self::assertSame('cp_sent', $defaults[0]['status_id']);
        self::assertCount(11, $enum);
        self::assertTrue(FixtureLoader::load('status_vocabulary.json')['cp_api_does_not_enum_validate_status']);
    }

    public function testEachBankStatusHasBusinessSemantics(): void
    {
        foreach (FixtureLoader::load('status_vocabulary.json')['module_outbound_bank_status'] as $row) {
            self::assertNotSame('', $row['when']);
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $row['status_id']);
        }
    }
}
