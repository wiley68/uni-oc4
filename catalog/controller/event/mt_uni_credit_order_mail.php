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
 * - customer: mail/order.add → view/mail/order_add → Mail::setHtml (full HTML)
 * - admin alert: mail/order.alert → view/mail/order_alert → Mail::setHtml (br-line HTML)
 *
 * order_alert is NOT Mail::setText — it is setHtml with &lt;br/&gt; markup.
 * Appending plain "\\n" text there flattens in HTML clients.
 */
class MtUniCreditOrderMail extends \Opencart\System\Engine\Controller
{
    public function afterOrderAdd(string &$route, array &$data, mixed &$output): void
    {
        $this->appendLeasing($data, $output, 'html_table');
    }

    public function afterOrderAlert(string &$route, array &$data, mixed &$output): void
    {
        // Native admin alert: non-sensitive audience (no EGN) — separate from Process 2 admin mail.
        $this->appendLeasing($data, $output, 'html_br');
    }

    /**
     * @param array<string, mixed> $data
     * @param 'html_table'|'html_br'|'text' $format
     */
    private function appendLeasing(array $data, mixed &$output, string $format): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }
        if (str_contains($output, 'mt-uni-credit-leasing-block')
            || str_contains($output, 'УниКредит лизинг<br')
            || str_contains($output, "УниКредит лизинг\n")
        ) {
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
            if ($format === 'html_br') {
                $chunk = $presenter->renderBrHtml($rows);
                if ($chunk === '' || str_contains($chunk, 'ЕГН')) {
                    return;
                }
                $output .= '<br/><br/>' . $chunk;

                return;
            }
            if ($format === 'text') {
                $chunk = $presenter->renderText($rows);
                if ($chunk === '' || str_contains($chunk, 'ЕГН')) {
                    return;
                }
                $output .= "\n\n" . $chunk;

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
