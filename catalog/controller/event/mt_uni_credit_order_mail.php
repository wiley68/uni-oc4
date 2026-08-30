<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationAudience;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationService;
use Opencart\System\Library\Extension\MtUniCredit\FinancingLeasingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;

/**
 * Inject non-sensitive UniCredit leasing block into native OpenCart order emails.
 *
 * Runtime path (OC 4.1.0.3):
 * model/checkout/order.addHistory → mail/order(.alert)
 * → load->view('mail/order_add'|'mail/order_alert')
 * → view/mail/.../after → Mail::setHtml($output) → send.
 *
 * Core mail templates are not patched; leasing is appended to the rendered body.
 * Snapshot must already be persisted before addHistory (OrderMaterializationService).
 */
class MtUniCreditOrderMail extends \Opencart\System\Engine\Controller
{
    public function afterOrderAdd(string &$route, array &$data, mixed &$output): void
    {
        $this->appendLeasing($data, $output);
    }

    public function afterOrderAlert(string &$route, array &$data, mixed &$output): void
    {
        // Native admin alert: non-sensitive audience (no EGN) — separate from Process 2 admin mail.
        $this->appendLeasing($data, $output);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function appendLeasing(array $data, mixed &$output): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }
        if (str_contains($output, 'mt-uni-credit-leasing-block')) {
            return;
        }
        $orderId = (int) ($data['order_id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }
        try {
            $db = new OpenCartDbConnection($this->db, DB_PREFIX);
            $service = new FinancingPresentationService(new FinancingPresentationRepository($db));
            $storeId = (int) ($this->config->get('config_store_id') ?? 0);
            $rows = $service->rowsForOrder($storeId, $orderId, FinancingPresentationAudience::CUSTOMER);
            if ($rows === []) {
                return;
            }
            $presenter = new FinancingLeasingPresenter();
            // order_alert.twig is plain-text style (no <html>/<table>); order_add is HTML.
            $plain = !str_contains($output, '<html') && !str_contains($output, '<table');
            if ($plain) {
                $text = $presenter->renderText($rows);
                if ($text === '' || str_contains($text, 'ЕГН')) {
                    return;
                }
                $output .= "\n\n" . $text;

                return;
            }
            $html = $presenter->renderHtml($rows);
            if ($html === '' || str_contains($html, 'ЕГН')) {
                if (str_contains($html, 'ЕГН')) {
                    error_log('mt_uni_credit: blocked order mail leasing HTML containing EGN');
                }

                return;
            }
            $output .= '<br/>' . $html;
        } catch (\Throwable $exception) {
            error_log('mt_uni_credit: order mail leasing inject failed class=' . $exception::class);
        }
    }
}
