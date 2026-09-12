<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\RecordingStatusPort;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderStatusPort;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncService;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncStates;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelStatusSyncStoreInterface;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpException;
use Opencart\System\Library\Extension\MtUniCredit\CpTimeoutException;
use PHPUnit\Framework\TestCase;

final class ControlPanelStatusSyncServiceTest extends TestCase
{
    public function testSynchronizeAfterHandoffAdmitsPendingThenConfirms(): void
    {
        $store = new InMemoryStatusSyncStore();
        $store->rows[1] = [
            'attempt_id' => 1,
            'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
            'cp_status_sync_status_id' => null,
            'cp_status_sync_status' => null,
        ];
        $client = new RecordingStatusPort();
        $service = new ControlPanelStatusSyncService($store, $client);
        $status = BankStatus::process1Sent();

        $state = $service->synchronizeAfterHandoff(1, '12345', $status);
        self::assertSame(ControlPanelStatusSyncStates::CONFIRMED, $state);
        self::assertSame(1, $client->calls);
        self::assertSame(ControlPanelStatusSyncStates::CONFIRMED, $store->rows[1]['cp_status_sync_state']);
    }

    public function testRetryPendingLeavesPendingOnTimeout(): void
    {
        $store = new InMemoryStatusSyncStore();
        $status = BankStatus::process2Sent();
        $store->rows[2] = [
            'attempt_id' => 2,
            'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
            'cp_status_sync_status_id' => $status['status_id'],
            'cp_status_sync_status' => $status['status_label'],
        ];
        $client = new class implements ControlPanelOrderStatusPort {
            public function updateOrderStatus(string $shopOrderId, string $statusLabel, string $statusId): array
            {
                throw new CpTimeoutException('timeout');
            }
        };
        $service = new ControlPanelStatusSyncService($store, $client);
        $state = $service->retryPending(2, '99');
        self::assertSame(ControlPanelStatusSyncStates::PENDING, $state);
        self::assertSame('cp_status_transport_ambiguous', $store->rows[2]['cp_status_sync_error_class']);
    }

    public function testIncompatibleTerminalTargetsConflictWithoutPatch(): void
    {
        $store = new InMemoryStatusSyncStore();
        $store->rows[3] = [
            'attempt_id' => 3,
            'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
            'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
            'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
        ];
        $client = new RecordingStatusPort();
        $service = new ControlPanelStatusSyncService($store, $client);
        $state = $service->synchronizeAfterHandoff(3, '77', BankStatus::process2Sent());
        self::assertSame(ControlPanelStatusSyncStates::PENDING, $state);
        self::assertSame(0, $client->calls);
    }

    public function testTerminalMachineCodeMarksTerminalFailed(): void
    {
        $store = new InMemoryStatusSyncStore();
        $status = BankStatus::process1Sent();
        $store->rows[4] = [
            'attempt_id' => 4,
            'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
            'cp_status_sync_status_id' => $status['status_id'],
            'cp_status_sync_status' => $status['status_label'],
        ];
        $client = new class implements ControlPanelOrderStatusPort {
            public function updateOrderStatus(string $shopOrderId, string $statusLabel, string $statusId): array
            {
                throw new CpHttpException(422, ['error' => 'semantic_conflict']);
            }
        };
        $service = new ControlPanelStatusSyncService($store, $client);
        $state = $service->retryPending(4, '55');
        self::assertSame(ControlPanelStatusSyncStates::TERMINAL_FAILED, $state);
        self::assertSame('cp_status_semantic_conflict', $store->rows[4]['cp_status_sync_error_class']);
    }

    /**
     * @dataProvider incompatibleTargetMatrix
     */
    public function testIncompatibleTargetMatrixNeverPatches(
        string $currentState,
        string $currentStatusId,
        string $currentStatus,
        array $incoming
    ): void {
        $store = new InMemoryStatusSyncStore();
        $store->rows[10] = [
            'attempt_id' => 10,
            'cp_status_sync_state' => $currentState,
            'cp_status_sync_status_id' => $currentStatusId,
            'cp_status_sync_status' => $currentStatus,
        ];
        $client = new RecordingStatusPort();
        $service = new ControlPanelStatusSyncService($store, $client);
        $state = $service->synchronizeAfterHandoff(10, '100', $incoming);
        self::assertSame($currentState, $state);
        self::assertSame($currentStatusId, $store->rows[10]['cp_status_sync_status_id']);
        self::assertSame(0, $client->calls);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: array{status_id: string, status_label: string}}> */
    public static function incompatibleTargetMatrix(): array
    {
        $p1 = BankStatus::process1Sent();
        $p2 = BankStatus::process2Sent();

        return [
            [ControlPanelStatusSyncStates::CONFIRMED, $p1['status_id'], $p1['status_label'], $p2],
            [ControlPanelStatusSyncStates::CONFIRMED, $p2['status_id'], $p2['status_label'], $p1],
            [ControlPanelStatusSyncStates::PENDING, $p1['status_id'], $p1['status_label'], $p2],
            [ControlPanelStatusSyncStates::PENDING, $p2['status_id'], $p2['status_label'], $p1],
            [ControlPanelStatusSyncStates::TERMINAL_FAILED, $p1['status_id'], $p1['status_label'], $p2],
            [ControlPanelStatusSyncStates::TERMINAL_FAILED, $p2['status_id'], $p2['status_label'], $p1],
        ];
    }

    public function testConfirmedSameTargetIsNoOp(): void
    {
        $store = new InMemoryStatusSyncStore();
        $status = BankStatus::process1Sent();
        $store->rows[11] = [
            'attempt_id' => 11,
            'cp_status_sync_state' => ControlPanelStatusSyncStates::CONFIRMED,
            'cp_status_sync_status_id' => $status['status_id'],
            'cp_status_sync_status' => $status['status_label'],
        ];
        $client = new RecordingStatusPort();
        $service = new ControlPanelStatusSyncService($store, $client);
        $state = $service->synchronizeAfterHandoff(11, '11', $status);
        self::assertSame(ControlPanelStatusSyncStates::CONFIRMED, $state);
        self::assertSame(0, $client->calls);
    }

    public function testUnknown4xxRemainsPending(): void
    {
        $store = new InMemoryStatusSyncStore();
        $status = BankStatus::process1Sent();
        $store->rows[12] = [
            'attempt_id' => 12,
            'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
            'cp_status_sync_status_id' => $status['status_id'],
            'cp_status_sync_status' => $status['status_label'],
        ];
        $client = new class implements ControlPanelOrderStatusPort {
            public function updateOrderStatus(string $shopOrderId, string $statusLabel, string $statusId): array
            {
                throw new CpHttpException(418, ['error' => 'teapot']);
            }
        };
        $service = new ControlPanelStatusSyncService($store, $client);
        self::assertSame(ControlPanelStatusSyncStates::PENDING, $service->retryPending(12, '12'));
    }
}

final class InMemoryStatusSyncStore implements ControlPanelStatusSyncStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

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
        $row = $this->rows[$attemptId] ?? null;
        if ($row === null || !$this->matches($row, $expectedState, $expectedStatusId, $expectedStatus)) {
            return false;
        }
        $this->rows[$attemptId]['cp_status_sync_state'] = ControlPanelStatusSyncStates::PENDING;
        $this->rows[$attemptId]['cp_status_sync_status_id'] = $newStatusId;
        $this->rows[$attemptId]['cp_status_sync_status'] = $newStatus;
        $this->rows[$attemptId]['cp_status_sync_error_class'] = null;

        return true;
    }

    public function compareAndSetConfirmed(int $attemptId, string $expectedStatusId, string $expectedStatus): bool
    {
        $row = $this->rows[$attemptId] ?? null;
        if ($row === null || !$this->matches($row, ControlPanelStatusSyncStates::PENDING, $expectedStatusId, $expectedStatus)) {
            return false;
        }
        $this->rows[$attemptId]['cp_status_sync_state'] = ControlPanelStatusSyncStates::CONFIRMED;
        $this->rows[$attemptId]['cp_status_sync_error_class'] = null;

        return true;
    }

    public function compareAndSetFailure(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus,
        string $newState,
        string $errorClass
    ): bool {
        $row = $this->rows[$attemptId] ?? null;
        if ($row === null || !$this->matches($row, ControlPanelStatusSyncStates::PENDING, $expectedStatusId, $expectedStatus)) {
            return false;
        }
        $this->rows[$attemptId]['cp_status_sync_state'] = $newState;
        $this->rows[$attemptId]['cp_status_sync_error_class'] = $errorClass;

        return true;
    }

    /** @param array<string, mixed> $row */
    private function matches(array $row, string $state, ?string $statusId, ?string $status): bool
    {
        if ((string) ($row['cp_status_sync_state'] ?? '') !== $state) {
            return false;
        }
        $actualId = $row['cp_status_sync_status_id'] ?? null;
        $actualStatus = $row['cp_status_sync_status'] ?? null;
        $norm = static fn ($v) => ($v === null || $v === '') ? null : (string) $v;

        return $norm($actualId) === $norm($statusId) && $norm($actualStatus) === $norm($status);
    }
}
