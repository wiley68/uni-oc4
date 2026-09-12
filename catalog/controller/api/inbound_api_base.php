<?php

namespace Opencart\Catalog\Controller\Extension\MtUniCredit\Api;

use Opencart\System\Library\Extension\MtUniCredit\ApiNonceRepository;
use Opencart\System\Library\Extension\MtUniCredit\BoundedRawBodyReader;
use Opencart\System\Library\Extension\MtUniCredit\DiagnosticPayloadRedactor;
use Opencart\System\Library\Extension\MtUniCredit\InboundApiDispatcher;
use Opencart\System\Library\Extension\MtUniCredit\InboundApiEnvelope;
use Opencart\System\Library\Extension\MtUniCredit\ModuleApiException;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleEncryptionKeyProvider;
use Opencart\System\Library\Extension\MtUniCredit\ModuleRequestAuthenticator;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartDbConnection;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartModuleSettingStore;

/**
 * Shared wiring for CP → OpenCart inbound JSON API controllers.
 */
abstract class InboundApiBase extends \Opencart\System\Engine\Controller
{
    /**
     * Code-defined operation binding for this endpoint (not taken from input).
     */
    abstract protected function expectedOperation(): string;

    /**
     * @param callable(array<string, mixed>, string): array<string, mixed> $handler
     */
    protected function runInbound(callable $handler): void
    {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('X-Content-Type-Options: nosniff');

        try {
            $server = is_array($this->request->server) ? $this->request->server : [];
            $bodyRead = BoundedRawBodyReader::readPhpInput($server);
            if ($bodyRead['oversized']) {
                throw new ModuleApiException(
                    'Тялото на заявката надвишава допустимия размер.',
                    413,
                    'payload_too_large'
                );
            }

            $storeId = (int) $this->config->get('config_store_id');
            $db = new OpenCartDbConnection($this->db, DB_PREFIX);
            $settings = new OpenCartModuleSettingStore($db);
            $cipher = new ModuleSettingCipher((new ModuleEncryptionKeyProvider())->resolveDerivedKey());
            $credentials = new ModuleCredentialsRepository($settings, $cipher);
            $authenticator = new ModuleRequestAuthenticator(
                $credentials,
                new ApiNonceRepository($db),
                $storeId,
                (bool) $this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_status')
            );

            $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');
            $payload = InboundApiDispatcher::dispatch(
                $handler,
                $authenticator,
                $server,
                $bodyRead['body'],
                $method,
                $this->expectedOperation()
            );
            $encoded = InboundApiDispatcher::encodeResponse($payload, 200);
        } catch (ModuleApiException $exception) {
            $encoded = InboundApiDispatcher::encodeException($exception);
        } catch (\Throwable $exception) {
            if ((bool) $this->config->get(ModuleConstants::MODULE_SETTING_CODE . '_debug_enabled')) {
                $this->log->write(
                    '[mt_uni_credit] inbound API failure: '
                    . DiagnosticPayloadRedactor::sanitizeLogMessage($exception->getMessage())
                );
            }
            $encoded = InboundApiDispatcher::encodeResponse(
                InboundApiEnvelope::failure('internal_error', 'Модулът не можа да обработи заявката.'),
                500
            );
        }

        $proto = (string) ($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
        $this->response->addHeader($proto . ' ' . InboundApiDispatcher::httpStatusLine((int) $encoded['status']));
        $this->response->setOutput($encoded['body']);
    }

    protected function storeId(): int
    {
        return (int) $this->config->get('config_store_id');
    }

    protected function dbConnection(): OpenCartDbConnection
    {
        return new OpenCartDbConnection($this->db, DB_PREFIX);
    }
}
