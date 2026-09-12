<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderStatusPort;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncRepository;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncService;
use Opencart\System\Library\Extension\MtUniCredit\DbConnection;

final class StatusSyncTestFactory
{
    public static function create(DbConnection $db, ControlPanelOrderStatusPort $client): ControlPanelStatusSyncService
    {
        return new ControlPanelStatusSyncService(
            new ControlPanelStatusSyncRepository($db),
            $client
        );
    }
}
