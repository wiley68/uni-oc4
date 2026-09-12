<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\RecordingStatusPort;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncService;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncStates;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * REVIEW-07 — second CAS miss must never return ADMIT unless CAS succeeded.
 */
final class StatusSyncSecondCasMissTest extends TestCase
{
    public function testSecondCasMissReloadsAuthoritativeDecision(): void
    {
        $store = new FlakySecondCasStore();
        $store->rows[1] = [
            'attempt_id' => 1,
            'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'cp_status_sync_status_id' => null,
            'cp_status_sync_status' => null,
        ];
        $client = new RecordingStatusPort();
        $service = new ControlPanelStatusSyncService($store, $client);

        $decision = $service->admitTarget(1, BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1);
        self::assertSame(ControlPanelStatusSyncService::SAME, $decision);
        self::assertSame(2, $store->casAttempts);
        self::assertSame(BankStatus::SENT_PROCESS1, $store->rows[1]['cp_status_sync_status_id']);
    }

    public function testSecondCasMissConflictDoesNotAdmit(): void
    {
        $store = new FlakySecondCasConflictStore();
        $store->rows[2] = [
            'attempt_id' => 2,
            'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'cp_status_sync_status_id' => null,
            'cp_status_sync_status' => null,
        ];
        $service = new ControlPanelStatusSyncService($store, new RecordingStatusPort());
        $decision = $service->admitTarget(2, BankStatus::SENT_PROCESS2, BankStatus::LABEL_SENT_PROCESS2);
        self::assertSame(ControlPanelStatusSyncService::CONFLICT, $decision);
        self::assertNotSame(ControlPanelStatusSyncService::ADMIT, $decision);
    }
}

/**
 * First CAS fails; concurrent writer admits same target; second CAS also fails → SAME.
 */
final class FlakySecondCasStore implements ControlPanelStatusSyncStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $casAttempts = 0;

    public function findByAttempt(int $attemptId): ?array
    {
        return $this->rows[$attemptId] ?? null;
    }

    public function compareAndSetPendingTarget(
        int $attemptId,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus,
        string $newStatusId,
        string $newStatus
    ): bool {
        $this->casAttempts++;
        if ($this->casAttempts === 1) {
            // First CAS lost the race without changing our snapshot yet.
            return false;
        }

        // Concurrent writer admitted the same target between attempts.
        $this->rows[$attemptId]['cp_status_sync_state'] = ControlPanelStatusSyncStates::PENDING;
        $this->rows[$attemptId]['cp_status_sync_status_id'] = $newStatusId;
        $this->rows[$attemptId]['cp_status_sync_status'] = $newStatus;

        return false;
    }

    public function compareAndSetConfirmed(int $attemptId, string $expectedStatusId, string $expectedStatus): bool
    {
        return false;
    }

    public function compareAndSetFailure(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus,
        string $newState,
        string $errorClass
    ): bool {
        return false;
    }
}

/**
 * First CAS fails; concurrent writer admits incompatible P1; second CAS fails → CONFLICT.
 */
final class FlakySecondCasConflictStore implements ControlPanelStatusSyncStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $casAttempts = 0;

    public function findByAttempt(int $attemptId): ?array
    {
        return $this->rows[$attemptId] ?? null;
    }

    public function compareAndSetPendingTarget(
        int $attemptId,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus,
        string $newStatusId,
        string $newStatus
    ): bool {
        $this->casAttempts++;
        if ($this->casAttempts === 1) {
            $this->rows[$attemptId]['cp_status_sync_state'] = ControlPanelStatusSyncStates::PENDING;
            $this->rows[$attemptId]['cp_status_sync_status_id'] = BankStatus::SENT_PROCESS1;
            $this->rows[$attemptId]['cp_status_sync_status'] = BankStatus::LABEL_SENT_PROCESS1;

            return false;
        }

        return false;
    }

    public function compareAndSetConfirmed(int $attemptId, string $expectedStatusId, string $expectedStatus): bool
    {
        return false;
    }

    public function compareAndSetFailure(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus,
        string $newState,
        string $errorClass
    ): bool {
        return false;
    }
}
