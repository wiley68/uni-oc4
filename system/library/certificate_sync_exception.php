<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CertificateSyncException extends \RuntimeException
{
    public const REASON_CP_UNAVAILABLE = 'cp_unavailable';
    public const REASON_CP_TRANSPORT = 'cp_transport';
    public const REASON_REFRESH_FAILED = 'refresh_failed';
    public const REASON_INVALID_BUNDLE = 'invalid_bundle';
    public const REASON_LOCAL_FS = 'local_fs';

    public function __construct(
        string $message,
        private string $reason = self::REASON_REFRESH_FAILED,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
