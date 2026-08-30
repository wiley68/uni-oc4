<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Event;

use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationAudience;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationService;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;

/**
 * Inject non-sensitive UniCredit leasing block into native OpenCart order emails.
 */
class MtUniCreditOrderMail extends \Opencart\System\Engine\Controller
{
    public function afterOrderAdd(string &$route, array &$data, mixed &$output): void
    {
        $this->appendLeasing($data, $output, FinancingPresentationAudience::CUSTOMER);
    }

    public function afterOrderAlert(string &$route, array &$data, mixed &$output): void
    {
        // Native admin alert uses the same non-sensitive customer presentation (no EGN).
        $this->appendLeasing($data, $output, FinancingPresentationAudience::CUSTOMER);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function appendLeasing(array $data, mixed &$output, string $audience): void
    {
        if (!is_string($output) || $output === '') {
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
            $html = $service->htmlForOrder($storeId, $orderId, $audience);
            if ($html === '') {
                return;
            }
            if (str_contains($html, 'ЕГН')) {
                error_log('mt_uni_credit: blocked order mail leasing HTML containing EGN');

                return;
            }
            $output .= '<br/>' . $html;
        } catch (\Throwable $exception) {
            error_log('mt_uni_credit: order mail leasing inject failed class=' . $exception::class);
        }
    }
}
