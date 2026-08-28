<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FixtureLoader;
use MtUniCredit\Tests\Support\SourceRoot;
use PHPUnit\Framework\TestCase;

final class OpencartLifecycleCharacterizationTest extends TestCase
{
    public function testFrozenLifecycleFindingsAreComplete(): void
    {
        $findings = FixtureLoader::load('opencart_lifecycle.json')['findings'];
        self::assertTrue($findings['addOrder_called_from_confirm_index']);
        self::assertTrue($findings['session_order_id_set_immediately_after_addOrder']);
        self::assertTrue($findings['payment_controller_rendered_after_order_create_or_edit']);
        self::assertTrue($findings['status_0_order_reused_via_editOrder']);
        self::assertTrue($findings['editOrder_first_voids_via_addHistory']);
        self::assertTrue($findings['after_first_edit_status_no_longer_zero']);
        self::assertSame('{code}.{option}', $findings['payment_method_identifier_format']);
        self::assertTrue($findings['payment_method_stored_as_json_in_order_row']);
        self::assertTrue($findings['addHistory_updates_order_status_and_inserts_order_history']);
        self::assertTrue($findings['checkout_success_clears_cart_and_session_not_db']);
        self::assertTrue($findings['addOrder_omits_order_status_id_insert_uses_db_default_0']);
    }

    public function testConfirmCreatesThenRendersPayment(): void
    {
        $root = SourceRoot::openCart();
        if ($root === null) {
            self::markTestSkipped('OPENCART_ROOT is not available');
        }

        $confirm = (string) file_get_contents($root . '/catalog/controller/checkout/confirm.php');
        $snippets = FixtureLoader::load('opencart_lifecycle.json')['snippets'];

        self::assertStringContainsString($snippets['addOrder_or_editOrder'], $confirm);
        self::assertTrue(
            strpos($confirm, 'addOrder($order_data)') < strpos($confirm, "load->controller('extension/'")
        );
        self::assertStringContainsString($snippets['payment_render'], $confirm);
        self::assertStringContainsString($snippets['payment_code_prefix'], $confirm);
    }

    public function testEditOrderVoidsStatusZeroOrders(): void
    {
        $root = SourceRoot::openCart();
        if ($root === null) {
            self::markTestSkipped('OPENCART_ROOT is not available');
        }

        $order = (string) file_get_contents($root . '/catalog/model/checkout/order.php');
        self::assertStringContainsString(
            FixtureLoader::load('opencart_lifecycle.json')['snippets']['editOrder_void'],
            $order
        );
        self::assertStringNotContainsString('order_status_id', $this->addOrderInsertSql($order));
        self::assertStringContainsString('order_history', $order);
    }

    public function testPaymentMethodIdentifierAndSuccessCleanup(): void
    {
        $root = SourceRoot::openCart();
        if ($root === null) {
            self::markTestSkipped('OPENCART_ROOT is not available');
        }

        $payment = (string) file_get_contents($root . '/catalog/controller/checkout/payment_method.php');
        self::assertStringContainsString(
            FixtureLoader::load('opencart_lifecycle.json')['snippets']['payment_select'],
            $payment
        );

        $success = (string) file_get_contents($root . '/catalog/controller/checkout/success.php');
        self::assertStringContainsString('$this->cart->clear();', $success);
        self::assertStringContainsString("unset(\$this->session->data['order_id']);", $success);
        self::assertStringContainsString("unset(\$this->session->data['payment_method']);", $success);

        $schema = (string) file_get_contents($root . '/system/helper/db_schema.php');
        self::assertStringContainsString("'name'    => 'order_status_id'", $schema);
        self::assertStringContainsString("'default' => '0'", $schema);
    }

    public function testJetPaymentOptionCodeFormat(): void
    {
        $jet = SourceRoot::jet();
        if ($jet === null) {
            self::markTestSkipped('JET_OC4_ROOT is not available');
        }

        $model = (string) file_get_contents($jet . '/catalog/model/payment/jet.php');
        self::assertStringContainsString("'code' => 'jet.jet'", $model);
        $controller = (string) file_get_contents($jet . '/catalog/controller/payment/jet.php');
        self::assertStringContainsString("\$this->session->data['order_id']", $controller);
    }

    private function addOrderInsertSql(string $orderModel): string
    {
        $start = strpos($orderModel, 'public function addOrder');
        self::assertNotFalse($start);
        $end = strpos($orderModel, 'public function editOrder', $start);
        self::assertNotFalse($end);

        return substr($orderModel, $start, $end - $start);
    }
}
