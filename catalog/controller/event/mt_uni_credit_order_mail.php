<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationAudience;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationService;
use Opencart\System\Library\Extension\MtUniCredit\FinancingLeasingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;

/**
 * Inject UniCredit leasing blocks into native OpenCart order emails.
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
        $this->appendLeasing($data, $output, FinancingPresentationAudience::CUSTOMER, 'html_table');
    }

    public function afterOrderAlert(string &$route, array &$data, mixed &$output): void
    {
        $this->appendLeasing($data, $output, FinancingPresentationAudience::ADMIN_EMAIL, 'html_br');
    }

    /**
     * @param array<string, mixed> $data
     * @param 'html_table'|'html_br'|'text' $format
     */
    private function appendLeasing(array $data, mixed &$output, string $audience, string $format): void
    {
        if (!is_string($output) || $output === '') {
            return;
        }
        if (
            str_contains($output, 'mt-uni-credit-leasing-block')
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
            $rows = $service->rowsForOrder($storeId, $orderId, $audience);
            if ($rows === []) {
                return;
            }
            $presenter = new FinancingLeasingPresenter();
            if ($format === 'html_br') {
                $chunk = $presenter->renderBrHtml($rows);
                if ($chunk === '' || $this->containsBlockedSensitiveMailContent($chunk, $audience)) {
                    return;
                }
                $output .= '<br/><br/>' . $chunk;

                return;
            }
            if ($format === 'text') {
                $chunk = $presenter->renderText($rows);
                if ($chunk === '' || $this->containsBlockedSensitiveMailContent($chunk, $audience)) {
                    return;
                }
                $output .= "\n\n" . $chunk;

                return;
            }
            $html = $presenter->renderHtml($rows);
            if ($html === '' || $this->containsBlockedSensitiveMailContent($html, $audience)) {
                if ($this->containsBlockedSensitiveMailContent($html, $audience)) {
                    error_log('mt_uni_credit: blocked order mail leasing HTML containing sensitive fields');
                }

                return;
            }
            $output .= '<br/>' . $html;
        } catch (\Throwable $exception) {
            error_log('mt_uni_credit: order mail leasing inject failed class=' . $exception::class);
        }
    }

    private function containsBlockedSensitiveMailContent(string $content, string $audience): bool
    {
        if ($audience !== FinancingPresentationAudience::CUSTOMER) {
            return false;
        }

        return str_contains($content, FinancingLeasingPresenter::LABEL_EGN)
            || str_contains($content, FinancingLeasingPresenter::LABEL_PHONE2);
    }
}
