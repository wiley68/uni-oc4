<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\FinancingLeasingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationAudience;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationSnapshot;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ProcessTwoSensitiveData;
use PHPUnit\Framework\TestCase;

final class Phase11BPresentationParityTest extends TestCase
{
    public function testVersionFrozen(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testCustomerRowsMatchCanonicalVocabularyAndExcludeSensitive(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(
            906,
            177,
            true,
            6,
            'POS COM 50',
            0.0,
            1000.0,
            181.55,
            1089.30,
            30.0,
            34.49
        );
        $sensitive = new ProcessTwoSensitiveData('1990011599', '0888999000');
        $rows = $presenter->rows(
            $snapshot,
            BankStatus::LABEL_SENT_PROCESS2,
            FinancingPresentationAudience::CUSTOMER,
            $sensitive
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row['label']] = $row['value'];
        }
        self::assertSame([
            FinancingLeasingPresenter::LABEL_BANK_STATUS,
            FinancingLeasingPresenter::LABEL_CP_INTERNAL_ID,
            FinancingLeasingPresenter::LABEL_CP_SHOP_ORDER_ID,
            FinancingLeasingPresenter::LABEL_MONTHS,
            FinancingLeasingPresenter::LABEL_KOP,
            FinancingLeasingPresenter::LABEL_FIRST,
            FinancingLeasingPresenter::LABEL_LOAN,
            FinancingLeasingPresenter::LABEL_MONTHLY,
            FinancingLeasingPresenter::LABEL_TOTAL,
            FinancingLeasingPresenter::LABEL_GLP_GPR,
            FinancingLeasingPresenter::LABEL_MESSAGE,
        ], array_keys($map));
        self::assertSame(BankStatus::LABEL_SENT_PROCESS2, $map[FinancingLeasingPresenter::LABEL_BANK_STATUS]);
        self::assertSame('177', $map[FinancingLeasingPresenter::LABEL_CP_INTERNAL_ID]);
        self::assertSame('906', $map[FinancingLeasingPresenter::LABEL_CP_SHOP_ORDER_ID]);
        self::assertNotSame(
            $map[FinancingLeasingPresenter::LABEL_CP_INTERNAL_ID],
            $map[FinancingLeasingPresenter::LABEL_CP_SHOP_ORDER_ID]
        );
        self::assertSame('0.00', $map[FinancingLeasingPresenter::LABEL_FIRST]);
        self::assertSame('1000.00', $map[FinancingLeasingPresenter::LABEL_LOAN]);
        self::assertSame('181.55', $map[FinancingLeasingPresenter::LABEL_MONTHLY]);
        self::assertSame('1089.30', $map[FinancingLeasingPresenter::LABEL_TOTAL]);
        self::assertSame('30.00% / 34.49%', $map[FinancingLeasingPresenter::LABEL_GLP_GPR]);
        self::assertSame(FinancingLeasingPresenter::PROCESS2_MESSAGE, $map[FinancingLeasingPresenter::LABEL_MESSAGE]);
        self::assertArrayNotHasKey(FinancingLeasingPresenter::LABEL_EGN, $map);
        self::assertArrayNotHasKey(FinancingLeasingPresenter::LABEL_PHONE2, $map);

        $html = $presenter->renderHtml($rows);
        self::assertStringContainsString('УниКредит лизинг', $html);
        self::assertStringNotContainsString('1990011599', $html);
        self::assertStringNotContainsString('ЕГН', $html);
    }

    public function testAdminEmailIncludesEgnAndPhone2(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(10, 99, true, 12, 'POS COM 100', 10.0, 900.0, 80.0, 960.0, 21.0, 23.0);
        $rows = $presenter->rows(
            $snapshot,
            BankStatus::LABEL_SENT_PROCESS2,
            FinancingPresentationAudience::ADMIN_EMAIL,
            new ProcessTwoSensitiveData('1990011599', '0888123456')
        );
        $map = array_column($rows, 'value', 'label');
        self::assertSame('1990011599', $map[FinancingLeasingPresenter::LABEL_EGN]);
        self::assertSame('0888123456', $map[FinancingLeasingPresenter::LABEL_PHONE2]);
        self::assertArrayNotHasKey(FinancingLeasingPresenter::LABEL_MESSAGE, $map);
    }

    public function testAdminPanelIncludesSensitivePerPs9(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(10, 99, true, 12, 'POS COM 100', 10.0, 900.0, 80.0, 960.0, 21.0, 23.0);
        $rows = $presenter->rows(
            $snapshot,
            BankStatus::LABEL_SENT_PROCESS2,
            FinancingPresentationAudience::ADMIN_PANEL,
            new ProcessTwoSensitiveData('1990011599', '0888123456')
        );
        $map = array_column($rows, 'value', 'label');
        self::assertSame('1990011599', $map[FinancingLeasingPresenter::LABEL_EGN]);
        self::assertSame('0888123456', $map[FinancingLeasingPresenter::LABEL_PHONE2]);
    }

    public function testProcess1CustomerHasNoEgnAndUsesProcess1Status(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(55, 200, false, 12, 'POS COM 50', 0, 500, 50, 600, 10, 12);
        $rows = $presenter->rows(
            $snapshot,
            BankStatus::LABEL_SENT_PROCESS1,
            FinancingPresentationAudience::CUSTOMER,
            new ProcessTwoSensitiveData('1990011599', '0888123456')
        );
        $map = array_column($rows, 'value', 'label');
        self::assertSame(BankStatus::LABEL_SENT_PROCESS1, $map[FinancingLeasingPresenter::LABEL_BANK_STATUS]);
        self::assertArrayNotHasKey(FinancingLeasingPresenter::LABEL_EGN, $map);
        self::assertArrayNotHasKey(FinancingLeasingPresenter::LABEL_MESSAGE, $map);
    }

    public function testSnapshotMutationDoesNotAffectFrozenValues(): void
    {
        $frozen = new FinancingPresentationSnapshot(1, 2, true, 6, 'POS COM 50', 0, 1000, 181.55, 1089.30, 30, 34.49);
        $array = $frozen->toArray();
        $array['monthly_installment'] = 999.99; // mutate copy only
        $again = FinancingPresentationSnapshot::fromArray($frozen->toArray());
        self::assertSame(181.55, $again->monthlyInstallment);
        self::assertSame('POS COM 50', $again->kopCode);
    }

    public function testEventRegistryIncludesPresentationHooks(): void
    {
        $triggers = array_column(
            \Opencart\System\Library\Extension\MtUniCredit\EventRegistry::definitions(),
            'trigger'
        );
        self::assertContains('catalog/view/common/success/before', $triggers);
        self::assertNotContains('catalog/view/common/success/after', $triggers);
        self::assertContains('catalog/controller/checkout/success/before', $triggers);
        self::assertContains('catalog/view/mail/order_add/after', $triggers);
        self::assertContains('catalog/view/mail/order_alert/after', $triggers);
        self::assertContains('admin/view/sale/order_list/before', $triggers);
        self::assertContains('admin/view/sale/order_list/after', $triggers);
        self::assertContains('admin/view/sale/order_info/after', $triggers);
    }

    public function testAdminOrderListHtmlInjectsColumnBeforeTotal(): void
    {
        $output = <<<'HTML'
<table><thead><tr>
<th></th>
<th><a>Order ID</a></th>
<th><a>Store</a></th>
<th><a>Customer</a></th>
<th><a>Status</a></th>
<th class="text-end d-none d-lg-table-cell"><a>Total</a></th>
</tr></thead><tbody><tr>
<td class="text-center"><input type="checkbox" name="selected[]" value="713" class="form-check-input"/></td>
<td class="text-end">713</td>
<td>Default</td>
<td>Test</td>
<td>Pending</td>
<td class="text-end d-none d-lg-table-cell">10.00</td>
</tr>
<tr>
<td class="text-center"><input type="checkbox" name="selected[]" value="1" class="form-check-input"/></td>
<td class="text-end">1</td>
<td>Default</td>
<td>COD</td>
<td>Pending</td>
<td class="text-end d-none d-lg-table-cell">5.00</td>
</tr></tbody></table>
HTML;
        $columnLabel = 'UniCredit статус';
        $output = preg_replace(
            '/(<th[^>]*>\s*<a[^>]*>[^<]*<\/a>\s*<\/th>)(\s*<th[^>]*class="[^"]*text-end[^"]*d-none d-lg-table-cell")/u',
            '$1<th>' . $columnLabel . '</th>$2',
            $output,
            1
        );
        self::assertStringContainsString('<th>UniCredit статус</th>', (string) $output);

        foreach ([713 => BankStatus::LABEL_SENT_PROCESS2, 1 => ''] as $orderId => $label) {
            $cell = $label !== '' ? $label : '—';
            $pattern = '/(name="selected\[\]" value="' . preg_quote((string) $orderId, '/')
                . '"[\s\S]*?)(<td class="text-end d-none d-lg-table-cell">)/u';
            $output = preg_replace(
                $pattern,
                '$1<td class="mt-uni-credit-admin-bank-status">' . $cell . '</td>$2',
                (string) $output,
                1
            );
        }
        self::assertStringContainsString(
            'value="713"',
            (string) $output
        );
        self::assertStringContainsString(
            '<td class="mt-uni-credit-admin-bank-status">' . BankStatus::LABEL_SENT_PROCESS2 . '</td>',
            (string) $output
        );
        self::assertStringContainsString(
            '<td class="mt-uni-credit-admin-bank-status">—</td>',
            (string) $output
        );
    }

    public function testAdminPanelSensitiveDecisionFollowsPs9NotWoo(): void
    {
        // PS9 admin order uses EmailAudience::ADMIN (EGN+phone2). Woo admin_panel omits EGN.
        // OC4 ADMIN_PANEL matches PS9 authorized back-office leasing box.
        $presenter = new FinancingLeasingPresenter();
        self::assertTrue($presenter->includesEgn(FinancingPresentationAudience::ADMIN_PANEL));
        self::assertTrue($presenter->includesEgn(FinancingPresentationAudience::ADMIN_EMAIL));
        self::assertFalse($presenter->includesEgn(FinancingPresentationAudience::CUSTOMER));
    }

    public function testThankYouPlacementIsInsideBodyNotBeforeHeader(): void
    {
        $header = '<html><body>{{HEADER_MARKER}}';
        $message = '<p>Thanks</p>';
        $leasing = '<div class="mt-uni-credit-checkout-success"><div class="mt-uni-credit-leasing-block">УниКредит лизинг</div></div>';
        $continue = '<div class="text-end"><a href="/home" class="btn btn-primary">Continue</a></div>';
        $footer = '{{FOOTER_MARKER}}</body></html>';
        // Simulate view/before enrichment of text_message then twig render order.
        $textMessage = $message . $leasing;
        $page = $header
            . '<div id="common-success" class="container"><div id="content" class="col">'
            . '<h1>Success</h1>' . $textMessage . $continue
            . '</div></div>'
            . $footer;
        $headerPos = strpos($page, '{{HEADER_MARKER}}');
        $leasingPos = strpos($page, 'mt-uni-credit-leasing-block');
        $footerPos = strpos($page, '{{FOOTER_MARKER}}');
        self::assertNotFalse($headerPos);
        self::assertNotFalse($leasingPos);
        self::assertNotFalse($footerPos);
        self::assertLessThan($leasingPos, $headerPos);
        self::assertLessThan($footerPos, $leasingPos);
        self::assertFalse(str_starts_with(ltrim($page), '<div class="mt-uni-credit'));
    }

    public function testAdminOrderInfoPlacementIsInsideContentNotInHeader(): void
    {
        $block = '<div class="card mb-3 mt-uni-credit-admin-order-leasing">LEASING</div>';
        $output = '<!DOCTYPE html><body><div id="container"><header id="header" class="navbar">'
            . '<div class="container-fluid">ADMIN_HEADER</div></header>'
            . '<div id="column-left"></div>'
            . '<div id="content">'
            . '<div class="page-header"><div class="container-fluid"><h1>Order</h1></div></div>'
            . '<div class="container-fluid">'
            . '<div class="card mb-3">ORDER_FORM</div>'
            . '<div class="card mb-3"><div class="card-header"><i class="fa-solid fa-comment"></i> History</div>'
            . '<div class="card-body">hist</div></div>'
            . '</div></div>'
            . '<div id="modal-customer" class="modal"></div>'
            . '</div></body>';
        $replaced = preg_replace(
            '/(\s*)(<\/div>\s*<\/div>\s*<div id="modal-customer")/',
            '$1' . $block . '$2',
            $output,
            1,
            $count
        );
        self::assertSame(1, $count);
        self::assertIsString($replaced);
        $headerPos = strpos($replaced, 'ADMIN_HEADER');
        $formPos = strpos($replaced, 'ORDER_FORM');
        $historyPos = strpos($replaced, 'fa-comment');
        $leasingPos = strpos($replaced, 'mt-uni-credit-admin-order-leasing');
        $modalPos = strpos($replaced, 'id="modal-customer"');
        self::assertNotFalse($headerPos);
        self::assertNotFalse($formPos);
        self::assertNotFalse($historyPos);
        self::assertNotFalse($leasingPos);
        self::assertNotFalse($modalPos);
        self::assertLessThan($leasingPos, $headerPos);
        self::assertLessThan($leasingPos, $formPos);
        self::assertLessThan($leasingPos, $historyPos);
        self::assertLessThan($modalPos, $leasingPos);
        self::assertStringContainsString('card mb-3 mt-uni-credit-admin-order-leasing', $replaced);
        self::assertDoesNotMatchRegularExpression(
            '/id="header"[\s\S]*mt-uni-credit-admin-order-leasing[\s\S]*<\/header>/',
            $replaced
        );
    }

    public function testNativeAdminAlertPipelineIncludesSensitiveForProcess2(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(55, 9, true, 6, 'POS COM 50', 0, 100, 20, 120, 10, 12);
        $sensitive = new ProcessTwoSensitiveData('1990011599', '0888123456');
        $rows = $presenter->rows(
            $snapshot,
            BankStatus::LABEL_SENT_PROCESS2,
            FinancingPresentationAudience::ADMIN_EMAIL,
            $sensitive
        );
        $labels = array_column($rows, 'label');
        self::assertSame(
            [
                FinancingLeasingPresenter::LABEL_BANK_STATUS,
                FinancingLeasingPresenter::LABEL_CP_INTERNAL_ID,
                FinancingLeasingPresenter::LABEL_CP_SHOP_ORDER_ID,
                FinancingLeasingPresenter::LABEL_MONTHS,
                FinancingLeasingPresenter::LABEL_KOP,
                FinancingLeasingPresenter::LABEL_FIRST,
                FinancingLeasingPresenter::LABEL_LOAN,
                FinancingLeasingPresenter::LABEL_MONTHLY,
                FinancingLeasingPresenter::LABEL_TOTAL,
                FinancingLeasingPresenter::LABEL_GLP_GPR,
                FinancingLeasingPresenter::LABEL_EGN,
                FinancingLeasingPresenter::LABEL_PHONE2,
            ],
            $labels
        );

        // mail/order.alert → view/mail/order_alert → Mail::setHtml (br-line body).
        $mailBody = "Order received<br/>\n<br/>\nOrder ID: 55<br/>\n";
        $mailBody .= '<br/><br/>' . $presenter->renderBrHtml($rows);
        self::assertStringContainsString('УниКредит лизинг<br/>', $mailBody);
        self::assertStringContainsString('Статус към банката: ' . BankStatus::LABEL_SENT_PROCESS2 . '<br/>', $mailBody);
        self::assertStringContainsString('КП shop order_id: 55', $mailBody);
        self::assertStringContainsString('ЕГН: 1990011599', $mailBody);
        self::assertStringContainsString('Втори телефон: 0888123456', $mailBody);
        self::assertStringNotContainsString('<table', $mailBody);
        self::assertStringNotContainsString("УниКредит лизинг\nСтатус", $mailBody);
    }

    public function testNativeAdminAlertPipelineOmitsSensitiveForProcess1(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(55, 9, false, 6, 'POS COM 50', 0, 100, 20, 120, 10, 12);
        $rows = $presenter->rows(
            $snapshot,
            BankStatus::LABEL_SENT_PROCESS1,
            FinancingPresentationAudience::ADMIN_EMAIL,
            new ProcessTwoSensitiveData('1990011599', '0888123456')
        );
        $mailBody = "Order received<br/>\n<br/>\nOrder ID: 55<br/>\n";
        $mailBody .= '<br/><br/>' . $presenter->renderBrHtml($rows);
        self::assertStringContainsString('УниКредит лизинг<br/>', $mailBody);
        self::assertStringContainsString(BankStatus::LABEL_SENT_PROCESS1, $mailBody);
        self::assertStringNotContainsString('1990011599', $mailBody);
        self::assertStringNotContainsString('ЕГН', $mailBody);
        self::assertStringNotContainsString('Втори телефон', $mailBody);
    }

    public function testNativeCustomerOrderMailOmitsSensitive(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(55, 9, true, 6, 'POS COM 50', 0, 100, 20, 120, 10, 12);
        $rows = $presenter->rows(
            $snapshot,
            BankStatus::LABEL_SENT_PROCESS2,
            FinancingPresentationAudience::CUSTOMER,
            new ProcessTwoSensitiveData('1990011599', '0888123456')
        );
        $mailBody = '<html><body><p>Order 55</p></body></html>';
        $mailBody .= '<br/>' . $presenter->renderHtml($rows);
        self::assertStringContainsString('УниКредит лизинг', $mailBody);
        self::assertStringNotContainsString('1990011599', $mailBody);
        self::assertStringNotContainsString('0888123456', $mailBody);
        self::assertStringNotContainsString('ЕГН', $mailBody);
        self::assertStringNotContainsString('Втори телефон', $mailBody);
    }

    public function testPlainTextRendererUsesNewlinesWithoutHtmlTags(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(55, 9, true, 6, 'POS COM 50', 0, 100, 20, 120, 10, 12);
        $rows = $presenter->rows($snapshot, BankStatus::LABEL_SENT_PROCESS2, FinancingPresentationAudience::CUSTOMER);
        $text = $presenter->renderText($rows);
        self::assertStringStartsWith("УниКредит лизинг\n\n", $text);
        self::assertStringContainsString("\nСтатус към банката: ", $text);
        self::assertStringNotContainsString('<table', $text);
        self::assertStringNotContainsString('<div', $text);
        self::assertStringNotContainsString('<br', $text);
    }

    public function testEmptyBankStatusDoesNotInventProcessLabel(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(10, null, true, 6, 'POS COM 50', 0, 100, 20, 120, 10, 12);
        $rows = $presenter->rows($snapshot, '', FinancingPresentationAudience::CUSTOMER, null);
        $map = array_column($rows, 'value', 'label');
        self::assertArrayNotHasKey(FinancingLeasingPresenter::LABEL_BANK_STATUS, $map);
        self::assertSame('10', $map[FinancingLeasingPresenter::LABEL_CP_SHOP_ORDER_ID]);
    }

    public function testMaterializationPersistsPresentationBeforeAddHistory(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/system/library/order_materialization_service.php');
        self::assertMatchesRegularExpression(
            '/persistPresentationBeforeMail[\s\S]*ensureInterimVisibleStatus/s',
            $src
        );
        self::assertStringContainsString('Snapshot must exist before addHistory', $src);
    }

    public function testAdditionalProcess2CustomerMailOmitsSensitive(): void
    {
        $presenter = new \Opencart\System\Library\Extension\MtUniCredit\ProcessTwoLeasingMailPresenter();
        $rows = $presenter->customerRows([
            'order_id' => 55,
            'control_panel_order_id' => 9,
            'months' => 6,
            'kop_code' => 'POS COM 50',
            'first_installment' => 0,
            'financed_amount' => 100,
            'monthly_installment' => 20,
            'total_payable' => 120,
            'glp' => 10,
            'gpr' => 12,
            'bank_status_label' => BankStatus::LABEL_SENT_PROCESS2,
            'leasing_snapshot' => (new FinancingPresentationSnapshot(55, 9, true, 6, 'POS COM 50', 0, 100, 20, 120, 10, 12))->toArray(),
        ]);
        $html = $presenter->renderHtml($rows);
        self::assertStringContainsString('УниКредит лизинг', $html);
        self::assertStringNotContainsString('ЕГН', $html);
        self::assertStringNotContainsString('Втори телефон', $html);
    }

    public function testAdditionalProcess2AdminMailIncludesSensitive(): void
    {
        $presenter = new \Opencart\System\Library\Extension\MtUniCredit\ProcessTwoLeasingMailPresenter();
        $rows = $presenter->adminRows([
            'order_id' => 55,
            'control_panel_order_id' => 9,
            'months' => 6,
            'kop_code' => 'POS COM 50',
            'first_installment' => 0,
            'financed_amount' => 100,
            'monthly_installment' => 20,
            'total_payable' => 120,
            'glp' => 10,
            'gpr' => 12,
            'bank_status_label' => BankStatus::LABEL_SENT_PROCESS2,
            'leasing_snapshot' => (new FinancingPresentationSnapshot(55, 9, true, 6, 'POS COM 50', 0, 100, 20, 120, 10, 12))->toArray(),
        ], new ProcessTwoSensitiveData('1990011599', '0888123456'));
        $html = $presenter->renderHtml($rows);
        self::assertStringContainsString('ЕГН', $html);
        self::assertStringContainsString('1990011599', $html);
        self::assertStringContainsString('Втори телефон', $html);
        self::assertStringContainsString('0888123456', $html);
    }
}
