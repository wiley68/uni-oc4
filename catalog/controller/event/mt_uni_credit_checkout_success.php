<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\FinancingLeasingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationAudience;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationService;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;

/**
 * Thank You / common/success — inject leasing inside page body (not before header).
 *
 * Prefer view/before data enrichment so Twig places the block after the native
 * message and before the continue button (inside #content, after {{ header }}).
 *
 * OpenCart checkout/success unsets session.order_id before the view runs; we read
 * mt_uni_credit_success_order_id stashed by MtUniCreditCheckoutSuccessOrder::before.
 *
 * Order identity is session-only — never trust GET order_id (IDOR hardening).
 */
class MtUniCreditCheckoutSuccess extends \Opencart\System\Engine\Controller
{
    /**
     * catalog/view/common/success/before
     *
     * @param array<string, mixed> $data
     */
    public function beforeView(string &$route, array &$data, string &$code, mixed &$output): void
    {
        if ($route !== 'common/success') {
            return;
        }

        $orderId = $this->resolveSuccessOrderId();
        if ($orderId <= 0) {
            $this->applyLegacyFlash($data);

            return;
        }

        $storeId = (int) ($this->config->get('config_store_id') ?? 0);
        if (!$this->canPresentOrderToCurrentCustomer($storeId, $orderId)) {
            return;
        }

        try {
            $db = new OpenCartDbConnection($this->db, DB_PREFIX);
            $service = new FinancingPresentationService(new FinancingPresentationRepository($db));
            $rows = $service->rowsForOrder($storeId, $orderId, FinancingPresentationAudience::CUSTOMER);
            if ($rows === [] || !$this->isCustomerPresentationSafe($rows)) {
                if (!$this->isCustomerPresentationSafe($rows)) {
                    error_log('mt_uni_credit: blocked Thank You leasing rows containing Process 2 sensitive fields');
                }

                return;
            }

            $presenter = new FinancingLeasingPresenter();
            $html = $presenter->renderHtml($rows);
            if ($html === '') {
                return;
            }

            $block = '<div class="mt-uni-credit-checkout-success">' . $html . '</div>';
            $data['text_message'] = (string) ($data['text_message'] ?? '') . $block;
            unset($this->session->data['mt_uni_credit_checkout_success'], $this->session->data['mt_uni_credit_success_order_id']);
        } catch (\Throwable $exception) {
            error_log('mt_uni_credit: thank-you leasing block failed class=' . $exception::class);
        }
    }

    private function resolveSuccessOrderId(): int
    {
        $orderId = (int) ($this->session->data['mt_uni_credit_success_order_id'] ?? 0);
        if ($orderId <= 0) {
            $orderId = (int) ($this->session->data['order_id'] ?? 0);
        }

        return $orderId;
    }

    private function canPresentOrderToCurrentCustomer(int $storeId, int $orderId): bool
    {
        if (!$this->customer->isLogged()) {
            return true;
        }

        $customerId = (int) $this->customer->getId();
        if ($customerId <= 0) {
            return false;
        }

        $result = $this->db->query(
            "SELECT `customer_id`, `store_id`
             FROM `" . DB_PREFIX . "order`
             WHERE `order_id` = " . (int) $orderId . "
             LIMIT 1"
        );
        if (!is_object($result) || $result->num_rows !== 1) {
            return false;
        }

        $orderCustomerId = (int) ($result->row['customer_id'] ?? 0);
        $orderStoreId = (int) ($result->row['store_id'] ?? 0);

        return $orderCustomerId === $customerId && $orderStoreId === $storeId;
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     */
    private function isCustomerPresentationSafe(array $rows): bool
    {
        foreach ($rows as $row) {
            $label = (string) ($row['label'] ?? '');
            if ($label === FinancingLeasingPresenter::LABEL_EGN
                || $label === FinancingLeasingPresenter::LABEL_PHONE2) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyLegacyFlash(array &$data): void
    {
        $legacy = trim((string) ($this->session->data['mt_uni_credit_checkout_success'] ?? ''));
        if ($legacy === '') {
            return;
        }
        unset($this->session->data['mt_uni_credit_checkout_success']);
        $safe = htmlspecialchars($legacy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $data['text_message'] = (string) ($data['text_message'] ?? '')
            . '<div class="alert alert-success alert-dismissible mt-uni-credit-checkout-success" role="alert">'
            . '<i class="fa-solid fa-circle-check"></i> ' . $safe
            . ' <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}
