<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Api;

use Opencart\System\Library\Extension\MtUniCredit\ModuleApiException;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationSnapshotValidator;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotValidationException;

/**
 * CP → module shop cache push.
 *
 * Route: extension/mt_uni_credit/api/shop_cache
 * Method: POST
 *
 * CP sends unicid + full shop `data` (validated replace). Does not fetch from CP.
 */
class ShopCache extends InboundApiBase
{
    public function index(): void
    {
        $this->runInbound(function (array $payload, string $unicid): array {
            $data = $payload['data'] ?? null;
            if (!is_array($data) || $data === []) {
                throw new ModuleApiException('Полето data трябва да съдържа пълна конфигурация на магазина.', 400);
            }

            if (isset($data['unicid']) && (!is_string($data['unicid']) || !hash_equals($unicid, $data['unicid']))) {
                throw new ModuleApiException('UNICID в конфигурацията не съвпада с този на магазина.', 400);
            }

            $storeId = $this->storeId();
            $cache = new ShopCacheRepository($this->dbConnection());
            $validator = new ShopConfigurationSnapshotValidator();

            try {
                $validator->validate($data, $unicid);
                $cache->replaceValidated($storeId, $unicid, $data);
            } catch (ShopSnapshotValidationException $exception) {
                throw new ModuleApiException(
                    'Конфигурацията на магазина е невалидна.',
                    422,
                    $exception->errorCode(),
                    ['violations' => $exception->violations()]
                );
            }

            return [
                'success' => true,
                'message' => 'Кешът на shop данни е обновен успешно.',
                'data' => $cache->findMetadata($storeId, $unicid),
            ];
        });
    }
}
