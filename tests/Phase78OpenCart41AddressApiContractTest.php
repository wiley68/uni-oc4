<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * OpenCart 4.1 Address::getAddress(customer_id, address_id) — Cart/Product must not call with 1 arg.
 * Regression for operator technical_failure ArgumentCountError on Cart submit.
 */
final class Phase78OpenCart41AddressApiContractTest extends TestCase
{
    public function testCartAddressResolverUsesTwoArgumentGetAddress(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_cart.php');
        self::assertSame(2, substr_count($src, 'getAddress($customerId, $addressId)'));
        self::assertDoesNotMatchRegularExpression('/getAddress\(\s*\$addressId\s*\)/', $src);
    }

    public function testProductAddressResolverUsesTwoArgumentGetAddress(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_product.php');
        self::assertSame(2, substr_count($src, 'getAddress($customerId, $addressId)'));
        self::assertDoesNotMatchRegularExpression('/getAddress\(\s*\$addressId\s*\)/', $src);
    }

    public function testAddressLoaderCallableReceivesCustomerId(): void
    {
        $resolver = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/open_cart_catalog_address_resolver.php'
        );
        self::assertStringContainsString('@param callable(int, int): array<string, mixed>|null $addressLoader', $resolver);
        self::assertStringContainsString('($this->addressLoader)($postedAddressId, $customerId)', $resolver);
        self::assertStringContainsString('($this->addressLoader)($postedShippingAddressId, $customerId)', $resolver);
    }

    public function testOpenCart41AddressModelRequiresCustomerId(): void
    {
        $oc = '/var/www/open40.avalonbg.com/catalog/model/account/address.php';
        if (!is_file($oc)) {
            self::markTestSkipped('OpenCart address model not present in this environment.');
        }
        $src = (string) file_get_contents($oc);
        self::assertMatchesRegularExpression(
            '/function getAddress\s*\(\s*int\s+\$customer_id\s*,\s*int\s+\$address_id\s*\)/',
            $src
        );
    }
}
