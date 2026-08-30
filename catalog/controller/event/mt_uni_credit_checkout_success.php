<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationAudience;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationService;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;

/**
 * Thank You / common/success — durable UniCredit leasing block (catalog/view/common/success/after).
 *
 * OpenCart checkout/success unsets session.order_id before the view runs; we read
 * mt_uni_credit_success_order_id stashed by MtUniCreditCheckoutSuccessOrder::before.
 */
class MtUniCreditCheckoutSuccess extends \Opencart\System\Engine\Controller
{
    public function init(string &$route, array &$data, string &$output): void
    {
        if ($route !== 'common/success') {
            return;
        }

        $orderId = (int) ($this->session->data['mt_uni_credit_success_order_id'] ?? 0);
        if ($orderId <= 0) {
            $orderId = (int) ($this->session->data['order_id'] ?? 0);
        }
        if ($orderId <= 0) {
            $orderId = (int) ($this->request->get['order_id'] ?? 0);
        }

        if ($orderId <= 0) {
            $legacy = trim((string) ($this->session->data['mt_uni_credit_checkout_success'] ?? ''));
            if ($legacy !== '') {
                unset($this->session->data['mt_uni_credit_checkout_success']);
                $safe = htmlspecialchars($legacy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $output = '<div class="alert alert-success alert-dismissible mt-uni-credit-checkout-success" role="alert">'
                    . '<i class="fa-solid fa-circle-check"></i> ' . $safe
                    . ' <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                    . $output;
            }

            return;
        }

        try {
            $db = new OpenCartDbConnection($this->db, DB_PREFIX);
            $service = new FinancingPresentationService(new FinancingPresentationRepository($db));
            $storeId = (int) ($this->config->get('config_store_id') ?? 0);
            $html = $service->htmlForOrder($storeId, $orderId, FinancingPresentationAudience::CUSTOMER);
            if ($html === '') {
                return;
            }
            if (str_contains($html, 'ЕГН')) {
                error_log('mt_uni_credit: blocked Thank You leasing HTML containing EGN');

                return;
            }
            $output = '<div class="mt-uni-credit-checkout-success card mb-3"><div class="card-body">'
                . $html
                . '</div></div>'
                . $output;
            unset($this->session->data['mt_uni_credit_checkout_success'], $this->session->data['mt_uni_credit_success_order_id']);
        } catch (\Throwable $exception) {
            error_log('mt_uni_credit: thank-you leasing block failed class=' . $exception::class);
        }
    }
}
