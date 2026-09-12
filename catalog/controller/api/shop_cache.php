<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Api;

use Opencart\System\Library\Extension\MtUniCredit\InboundApiOperations;
use Opencart\System\Library\Extension\MtUniCredit\ModuleApiException;
use Opencart\System\Library\Extension\MtUniCredit\ModuleEncryptionKeyProvider;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher;
use Opencart\System\Library\Extension\MtUniCredit\ShopCacheRepository;
use Opencart\System\Library\Extension\MtUniCredit\ShopConfigurationSnapshotValidator;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotSanitizer;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotValidationException;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfCredentialPersistence;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfCredentialRepository;

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
    protected function expectedOperation(): string
    {
        return InboundApiOperations::SHOP_CACHE;
    }

    public function index(): void
    {
        $this->runInbound(function (array $payload, string $unicid): array {
            $data = $payload['data'] ?? null;
            if (!is_array($data) || array_is_list($data)) {
                throw new ModuleApiException('Полето data трябва да съдържа пълна конфигурация на магазина.', 400);
            }
            if ($data === []) {
                throw new ModuleApiException('Полето data трябва да съдържа пълна конфигурация на магазина.', 400);
            }

            $data = ShopSnapshotSanitizer::sanitize($data);

            if (isset($data['unicid']) && (!is_string($data['unicid']) || !hash_equals($unicid, $data['unicid']))) {
                throw new ModuleApiException('UNICID в конфигурацията не съвпада с този на магазина.', 400);
            }

            $storeId = $this->storeId();
            $db = $this->dbConnection();
            $settings = $this->moduleSettingStore();
            $cipher = new ModuleSettingCipher((new ModuleEncryptionKeyProvider())->resolveDerivedKey());
            $cache = new ShopCacheRepository($db);
            $validator = new ShopConfigurationSnapshotValidator();
            $smartUcfCredentials = new SmartUcfCredentialRepository($settings, $cipher);
            $persistence = new SmartUcfCredentialPersistence($smartUcfCredentials, $cipher, $cache, $db);

            try {
                $validator->validate($data, $unicid);
                $persistence->persistValidatedSnapshot($storeId, $unicid, $data);
            } catch (ShopSnapshotValidationException $exception) {
                throw new ModuleApiException(
                    'Конфигурацията на магазина е невалидна.',
                    422,
                    'shop_snapshot_invalid',
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
