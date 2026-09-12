<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderAmbiguousException;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderResolver;
use PHPUnit\Framework\TestCase;

final class FinancingOrderResolverTest extends TestCase
{
    public function testRejectsNonDigitOrderId(): void
    {
        $resolver = new FinancingOrderResolver(new FakeDbForResolver([]));
        self::assertNull($resolver->resolve(0, 'unicid', 'ABC'));
        self::assertNull($resolver->resolve(0, 'unicid', ''));
        self::assertNull($resolver->resolve(0, 'unicid', '0123')); // leading zero ≠ int cast identity
    }

    public function testZeroRowsReturnsNull(): void
    {
        $resolver = new FinancingOrderResolver(new FakeDbForResolver([]));
        self::assertNull($resolver->resolve(0, 'unicid', '42'));
    }

    public function testOneRowReturnsAttempt(): void
    {
        $resolver = new FinancingOrderResolver(new FakeDbForResolver([
            ['attempt_id' => 9, 'store_id' => 0, 'order_id' => 42, 'unicid' => 'unicid'],
        ]));
        $resolved = $resolver->resolve(0, 'unicid', '42');
        self::assertNotNull($resolved);
        self::assertSame(42, $resolved['order_id']);
        self::assertSame(9, (int) $resolved['attempt']['attempt_id']);
    }

    public function testWrongUnicidReturnsNull(): void
    {
        $resolver = new FinancingOrderResolver(new FakeDbForResolver([
            ['attempt_id' => 9, 'store_id' => 0, 'order_id' => 42, 'unicid' => 'correct'],
        ]));
        self::assertNull($resolver->resolve(0, 'wrong', '42'));
    }

    public function testMultipleRowsThrowAmbiguous(): void
    {
        $resolver = new FinancingOrderResolver(new FakeDbForResolver([
            ['attempt_id' => 1, 'store_id' => 0, 'order_id' => 7, 'unicid' => 'unicid'],
            ['attempt_id' => 2, 'store_id' => 0, 'order_id' => 7, 'unicid' => 'unicid'],
        ]));
        $this->expectException(FinancingOrderAmbiguousException::class);
        $resolver->resolve(0, 'unicid', '7');
    }
}

/**
 * Minimal DbConnection double for FinancingOrderResolver cardinality tests.
 */
final class FakeDbForResolver implements \Opencart\System\Library\Extension\MtUniCredit\DbConnection
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function query(string $sql): object
    {
        $matched = [];
        foreach ($this->rows as $row) {
            $storeOk = !isset($row['store_id']) || str_contains($sql, '`store_id` = ' . (int) $row['store_id']);
            $orderOk = !isset($row['order_id']) || str_contains($sql, '`order_id` = ' . (int) $row['order_id']);
            $unicid = (string) ($row['unicid'] ?? 'unicid');
            $unicidOk = str_contains($sql, "`unicid` = '" . addslashes($unicid) . "'");
            if ($storeOk && $orderOk && $unicidOk) {
                $matched[] = $row;
            }
        }

        return new class ($matched) {
            public int $num_rows;
            /** @var array<string, mixed> */
            public array $row;
            /** @var list<array<string, mixed>> */
            public array $rows;

            /** @param list<array<string, mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
                $this->num_rows = count($rows);
                $this->row = $rows[0] ?? [];
            }
        };
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function countAffected(): int
    {
        return 0;
    }

    public function getLastId(): int
    {
        return 0;
    }

    public function getPrefix(): string
    {
        return 'oc_';
    }
}
