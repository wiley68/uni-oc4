<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Normalized CP create/recover outcome for application services (Phase 10B). */
final class ControlPanelOrderSubmissionResult
{
    public function __construct(
        public bool $success,
        public ?int $cpOrderId,
        public ?string $errorCode,
        public bool $recoverable,
        public ?int $httpStatus = null,
        public bool $replay = false
    ) {
    }

    public static function ok(int $cpOrderId, bool $replay = false): self
    {
        return new self(true, $cpOrderId, null, false, 200, $replay);
    }

    public static function fail(string $errorCode, bool $recoverable, ?int $httpStatus = null): self
    {
        return new self(false, null, $errorCode, $recoverable, $httpStatus, false);
    }
}
