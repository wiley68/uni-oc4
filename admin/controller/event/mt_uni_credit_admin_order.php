<?php

namespace Opencart\Admin\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationAudience;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationService;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;

/**
 * Admin Orders list + order info leasing presentation (no core template edits).
 */
class MtUniCreditAdminOrder extends \Opencart\System\Engine\Controller
{
    /**
     * @param array<string, mixed> $data
     */
    public function beforeOrderList(string &$route, array &$data, string &$code, mixed &$output): void
    {
        if (($data['orders'] ?? null) === null || !is_array($data['orders']) || $data['orders'] === []) {
            return;
        }
        $ids = [];
        foreach ($data['orders'] as $order) {
            $id = (int) ($order['order_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return;
        }
        try {
            $db = new OpenCartDbConnection($this->db, DB_PREFIX);
            $repo = new FinancingPresentationRepository($db);
            $storeId = (int) ($this->config->get('config_store_id') ?? 0);
            $labels = $repo->batchBankStatusLabels($storeId, $ids);
            // Also resolve statuses for attempts that may be store_id=0 while admin filter uses another store.
            if ($storeId !== 0) {
                $labels += $repo->batchBankStatusLabels(0, $ids);
            }
            foreach ($data['orders'] as $index => $order) {
                $id = (int) ($order['order_id'] ?? 0);
                $data['orders'][$index]['mt_uni_credit_bank_status'] = $labels[$id] ?? '';
            }
        } catch (\Throwable $exception) {
            error_log('mt_uni_credit: admin order list enrichment failed class=' . $exception::class);
        }
    }

    public function afterOrderList(string &$route, array &$data, mixed &$output): void
    {
        if (!is_string($output) || $output === '' || empty($data['orders']) || !is_array($data['orders'])) {
            return;
        }
        $columnLabel = 'UniCredit статус';
        // Insert header after commerce status column (5th th after checkbox).
        $output = preg_replace(
            '/(<th[^>]*>\s*<a[^>]*>[^<]*<\/a>\s*<\/th>)(\s*<th[^>]*class="[^"]*text-end[^"]*d-none d-lg-table-cell")/u',
            '$1<th>' . htmlspecialchars($columnLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>$2',
            $output,
            1,
            $count
        );
        if (!$count) {
            // Fallback: after 5th </th> in thead.
            $output = preg_replace(
                '/(<\/th>)(\s*<th class="text-end d-none d-lg-table-cell")/u',
                '$1<th>' . htmlspecialchars($columnLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>$2',
                $output,
                1
            );
        }

        foreach ($data['orders'] as $order) {
            $orderId = (int) ($order['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $label = trim((string) ($order['mt_uni_credit_bank_status'] ?? ''));
            $cell = $label !== ''
                ? htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : '—';
            // OC4 order_list: insert UniCredit column before Total (text-end d-none d-lg-table-cell).
            $pattern = '/(name="selected\[\]" value="' . preg_quote((string) $orderId, '/')
                . '"[\s\S]*?)(<td class="text-end d-none d-lg-table-cell">)/u';
            $output = preg_replace(
                $pattern,
                '$1<td class="mt-uni-credit-admin-bank-status">' . $cell . '</td>$2',
                $output,
                1
            );
        }

        // colspan for empty state
        $output = str_replace('colspan="9"', 'colspan="10"', $output);
    }

    public function afterOrderInfo(string &$route, array &$data, mixed &$output): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }
        $orderId = (int) ($data['order_id'] ?? ($this->request->get['order_id'] ?? 0));
        if ($orderId <= 0) {
            return;
        }
        try {
            $db = new OpenCartDbConnection($this->db, DB_PREFIX);
            $service = new FinancingPresentationService(new FinancingPresentationRepository($db));
            $storeId = (int) ($data['store_id'] ?? $this->config->get('config_store_id') ?? 0);
            $html = $service->htmlForOrder(
                $storeId,
                $orderId,
                FinancingPresentationAudience::ADMIN_PANEL,
                ''
            );
            if ($html === '') {
                return;
            }
            $block = '<div class="card mb-3 mt-uni-credit-admin-order-leasing"><div class="card-header">'
                . '<i class="fa-solid fa-university"></i> УниКредит — кредитна заявка</div>'
                . '<div class="card-body">' . $html . '</div></div>';
            // Place last inside main #content > .container-fluid (after history card),
            // immediately before the two closings that precede #modal-customer.
            $replaced = preg_replace(
                '/(\s*)(<\/div>\s*<\/div>\s*<div id="modal-customer")/',
                '$1' . $block . '$2',
                $output,
                1,
                $count
            );
            if (is_string($replaced) && $count > 0) {
                $output = $replaced;
            } elseif (preg_match('/(<div class="card mb-3">\s*<div class="card-header">[\s\S]*?fa-solid fa-comment[\s\S]*?<\/div>\s*<\/div>)(\s*<\/div>\s*<\/div>)/', $output)) {
                $output = preg_replace(
                    '/(<div class="card mb-3">\s*<div class="card-header">[\s\S]*?fa-solid fa-comment[\s\S]*?<\/div>\s*<\/div>)(\s*<\/div>\s*<\/div>)/',
                    '$1' . $block . '$2',
                    $output,
                    1
                ) ?? ($output . $block);
            } else {
                // Last resort: still inside #content if present.
                if (preg_match('/(<div id="content">[\s\S]*)(<\/div>\s*\{\{?\s*footer)/', $output)) {
                    $output = preg_replace(
                        '/(<div id="content">[\s\S]*)(<\/div>\s*)(\{\{?\s*footer|<\/body)/',
                        '$1' . $block . '$2$3',
                        $output,
                        1
                    ) ?? ($output . $block);
                } else {
                    $output .= $block;
                }
            }
        } catch (\Throwable $exception) {
            error_log('mt_uni_credit: admin order info leasing failed class=' . $exception::class);
        }
    }
}
