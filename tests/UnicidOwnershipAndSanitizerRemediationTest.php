<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderAmbiguousException;
use Opencart\System\Library\Extension\MtUniCredit\FinancingOrderResolver;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * REVIEW-02 / REVIEW-05 behavioral coverage.
 */
final class UnicidOwnershipAndSanitizerRemediationTest extends TestCase
{
    public function testResolverRequiresMatchingUnicid(): void
    {
        $db = new FilteringFakeDbForResolver([
            ['attempt_id' => 9, 'store_id' => 0, 'order_id' => 42, 'unicid' => 'shop-a'],
        ]);
        $resolver = new FinancingOrderResolver($db);

        $ok = $resolver->resolve(0, 'shop-a', '42');
        self::assertNotNull($ok);
        self::assertSame(9, (int) $ok['attempt']['attempt_id']);

        self::assertNull($resolver->resolve(0, 'shop-b', '42'));
        self::assertNull($resolver->resolve(1, 'shop-a', '42'));
        self::assertNull($resolver->resolve(0, 'shop-a', '99'));
    }

    public function testResolverAmbiguousOnDuplicateUnicidBindings(): void
    {
        $resolver = new FinancingOrderResolver(new FilteringFakeDbForResolver([
            ['attempt_id' => 1, 'store_id' => 0, 'order_id' => 7, 'unicid' => 'u'],
            ['attempt_id' => 2, 'store_id' => 0, 'order_id' => 7, 'unicid' => 'u'],
        ]));
        $this->expectException(FinancingOrderAmbiguousException::class);
        $resolver->resolve(0, 'u', '7');
    }

    public function testSanitizerRecursesListsAndNormalizesSecretKeyShapes(): void
    {
        $sanitized = ShopSnapshotSanitizer::sanitize([
            'uni_user' => 'keep-user',
            'uni_password' => 'keep-pass',
            'apiKey' => 'strip-camel',
            'Access-Token' => 'strip-kebab',
            'PrivateKeyPem' => 'strip-pascal',
            'client_secret' => 'strip-snake',
            'safe_field' => 'keep',
            'nested' => [
                'Authorization' => 'strip-nested',
                'items' => [
                    ['BearerToken' => 'strip-list-obj', 'label' => 'ok'],
                    [['password' => 'strip-deep-list']],
                ],
            ],
        ]);

        self::assertSame('keep-user', $sanitized['uni_user']);
        self::assertSame('keep-pass', $sanitized['uni_password']);
        self::assertSame('keep', $sanitized['safe_field']);
        self::assertArrayNotHasKey('apiKey', $sanitized);
        self::assertArrayNotHasKey('Access-Token', $sanitized);
        self::assertArrayNotHasKey('PrivateKeyPem', $sanitized);
        self::assertArrayNotHasKey('client_secret', $sanitized);
        self::assertArrayNotHasKey('Authorization', $sanitized['nested']);
        self::assertSame('ok', $sanitized['nested']['items'][0]['label']);
        self::assertArrayNotHasKey('BearerToken', $sanitized['nested']['items'][0]);
        self::assertSame([[]], $sanitized['nested']['items'][1]);
    }
}

/**
 * Filters rows by store_id / order_id / unicid from the SQL (string contains match).
 */
final class FilteringFakeDbForResolver implements \Opencart\System\Library\Extension\MtUniCredit\DbConnection
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function query(string $sql): object
    {
        $matched = [];
        foreach ($this->rows as $row) {
            $storeOk = str_contains($sql, '`store_id` = ' . (int) $row['store_id']);
            $orderOk = str_contains($sql, '`order_id` = ' . (int) $row['order_id']);
            $unicidOk = str_contains($sql, "`unicid` = '" . addslashes((string) $row['unicid']) . "'");
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
