<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderStatusPort;

final class RecordingStatusPort implements ControlPanelOrderStatusPort
{
    public int $calls = 0;

    public function updateOrderStatus(string $shopOrderId, string $statusLabel, string $statusId): array
    {
        $this->calls++;

        return [
            'success' => true,
            'error' => null,
            'message' => 'ok',
            'data' => [
                'id' => 1,
                'shop_id' => 1,
                'order_id' => $shopOrderId,
                'status_id' => $statusId,
                'status' => $statusLabel,
                'updated_at' => '2026-01-01 00:00:00',
            ],
        ];
    }
}
