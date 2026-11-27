<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\ConsentFakeDb;
use MtUniCredit\Tests\Support\ConsentFixture;
use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness as Orders;
use MtUniCredit\Tests\Support\Phase4TestHarness as Cp;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness as Product;
use Opencart\System\Library\Extension\MtUniCredit as Uni;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CpGrossPriceTest extends TestCase
{
    private const ORDER = 123;

    public static function combinations(): iterable
    {
        foreach (['product', 'cart', 'checkout'] as $entry) {
            foreach ([1, 2] as $process) {
                foreach (['manual', 'mandatory', 'zero'] as $policy) {
                    yield "$entry process $process $policy" => [$entry, $process, $policy];
                }
            }
        }
    }

    #[DataProvider('combinations')]
    public function testNewOrderSendsGrossAndPreservesTerms(string $entry, int $process, string $policy): void
    {
        [$db, $submission, $shop, $transport, $lifecycle] = $this->fixture($entry, $process, $policy);
        $calculation = clone $submission->financingCalculation;
        $native = $db->orders[self::ORDER];
        $draft = clone $submission->orderDraft;
        $presentation = Uni\FinancingPresentationSnapshot::fromSubmission($submission, self::ORDER, $process === 2);
        $bank = (new Uni\SmartUcfPayloadBuilder())->build($submission, $shop, self::ORDER);
        $result = $this->submit($lifecycle, $submission, $shop, $db);

        self::assertTrue($result->success);
        self::assertSame(1, $db->payloadWrites);
        self::assertSame(1, $transport->countOrderCreates());
        $body = $this->sent($transport);
        $gross = $entry === 'checkout' ? 510.01 : 499.01;
        $down = match ($policy) {
            'manual' => 100.0,
            'mandatory' => $entry === 'checkout' ? 42.5 : 41.58,
            'zero' => 0.0,
        };
        self::assertSame($gross, $body['price']);
        self::assertSame($down, (float) $body['parva']);
        self::assertSame(round($gross - $down, 2), $calculation->financedAmount);
        self::assertSame($calculation->monthlyInstallment, $body['vnoska']);
        self::assertSame($calculation->gpr, (float) $body['gpr']);
        self::assertSame(['123', '42', '2', 'EUR', '2.0.5'], [
            $body['order_id'], $body['products_id'], $body['products_q'], $body['currency'], $body['version'],
        ]);
        self::assertSame($native, $db->orders[self::ORDER]);
        self::assertEquals($draft, $submission->orderDraft);
        self::assertEquals($calculation, $submission->financingCalculation);
        self::assertEquals($presentation, Uni\FinancingPresentationSnapshot::fromSubmission($submission, self::ORDER, $process === 2));
        self::assertSame($bank, (new Uni\SmartUcfPayloadBuilder())->build($submission, $shop, self::ORDER));
        self::assertSame($body, $this->decode($db->rows[1]['cp_payload']));
        self::assertTrue($this->submit($lifecycle, $submission, $shop, $db)->success);
        self::assertSame(1, $transport->countOrderCreates());
        self::assertSame(1, $db->payloadWrites);
    }

    public static function writeOutcomes(): iterable
    {
        yield 'write and lose acknowledgement' => ['lost', 1];
        yield 'write fails' => ['failed', 0];
        yield 'acknowledgement without a row' => ['absent', 0];
        yield 'same body wins competing write' => ['same', 1];
        yield 'same numeric values with different representations' => ['equivalent', 1];
        yield 'different body wins competing write' => ['conflict', 0];
        yield 'sub-cent body wins competing write' => ['subcent', 0];
        yield 'malformed body wins competing write' => ['malformed', 0];
        yield 'conflict and lost acknowledgement' => ['lost-conflict', 0];
    }

    #[DataProvider('writeOutcomes')]
    public function testWriteReadBackControlsPost(string $outcome, int $posts): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $body = (new Uni\ControlPanelOrderPayloadBuilder())->build($s, self::ORDER, $shop);
        $db->losePayloadAck = in_array($outcome, ['lost', 'lost-conflict'], true);
        $before = null;
        if ($outcome !== 'lost') {
            $db->beforePayloadWrite = function (ConsentFakeDb $db, int $id) use ($outcome, $body, &$before): void {
                if ($outcome === 'failed') {
                    throw new \RuntimeException('Simulated failed write');
                }
                if ($outcome === 'absent') {
                    unset($db->rows[$id]);
                    throw new \RuntimeException('Simulated unavailable row after write');
                }
                $override = match ($outcome) {
                    'conflict', 'lost-conflict' => ['version' => '2.0.4'],
                    'equivalent' => ['price' => '499.0100'],
                    'subcent' => ['price' => 499.011],
                    default => [],
                };
                $before = $outcome === 'malformed' ? '{' : json_encode($override + $body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                $db->rows[$id]['cp_payload'] = $before;
            };
        }

        self::assertSame($posts === 1, $this->submit($service, $s, $shop, $db)->success);
        self::assertSame($posts, $transport->countOrderCreates());
        self::assertSame(1, $db->payloadWrites);
        if ($before !== null) {
            self::assertSame($before, $db->rows[1]['cp_payload']);
        }
    }

    public function testDetectedPrincipalConflictStaysOnHoldAcrossInvocations(): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $historical = ['price' => 399.01] + (new Uni\ControlPanelOrderPayloadBuilder())->build($s, self::ORDER, $shop);
        $raw = json_encode($historical, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $db->beforePayloadWrite = static function (ConsentFakeDb $db, int $id) use ($raw): void {
            $db->rows[$id]['cp_payload'] = $raw;
        };
        foreach ([1, 2] as $invocation) {
            $result = $this->submit($service, $s, $shop, $db);
            self::assertFalse($result->success, "Invocation {$invocation}");
            self::assertFalse($result->recoverable);
            self::assertSame(Uni\ControlPanelErrorClass::CONFLICT, $result->errorCode);
            self::assertSame('cp_outcome_unknown', $db->rows[1]['state']);
            self::assertSame('cp_conflict', $db->rows[1]['last_error_class']);
            self::assertSame($raw, $db->rows[1]['cp_payload']);
        }
        self::assertSame(1, $db->payloadWrites);
        self::assertSame(0, $transport->countOrderCreates());
    }

    public static function holdFaults(): iterable
    {
        yield 'committed hold' => ['', true];
        yield 'hold write threw' => ['throw', false];
        yield 'hold affected zero rows' => ['zero', false];
        yield 'committed hold lost acknowledgement' => ['lost-ack', true];
    }

    #[DataProvider('holdFaults')]
    public function testIndependentLifecycleRejectsConflictEvenWhenHoldCannotBeWritten(string $fault, bool $held): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $expected = (new Uni\ControlPanelOrderPayloadBuilder())->build($s, self::ORDER, $shop);
        $authorization = Uni\FinancingAttemptRepository::cpBodyAuthorization($expected);
        $raw = json_encode(['price' => 399.01] + $expected, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $db->holdWriteFault = $fault;
        $db->beforePayloadWrite = static function (ConsentFakeDb $db, int $id) use ($raw, $authorization): void {
            self::assertSame($authorization, $db->rows[$id]['last_error_class']);
            $db->rows[$id]['cp_payload'] = $raw;
        };

        foreach ([$service, $this->newLifecycle($db, $s->storeId, $transport)] as $instance) {
            $result = $this->submit($instance, $s, $shop, $db);
            self::assertSame(Uni\ControlPanelErrorClass::CONFLICT, $result->errorCode);
            self::assertFalse($result->recoverable);
            self::assertSame($raw, $db->rows[1]['cp_payload']);
            self::assertSame($held ? 'cp_outcome_unknown' : 'order_created', $db->rows[1]['state']);
            self::assertSame($held ? 'cp_conflict' : $authorization, $db->rows[1]['last_error_class']);
        }
        self::assertSame(1, $db->authorizationWrites);
        self::assertSame(1, $db->payloadWrites);
        self::assertSame($held ? 1 : 2, $db->holdWrites);
        self::assertSame(0, $transport->countOrderCreates());
    }

    public static function authorizationFaults(): iterable
    {
        yield 'unapplied exception' => ['throw', false];
        yield 'zero affected rows' => ['zero', false];
        yield 'lost acknowledgement after commit' => ['lost-ack', true];
    }

    #[DataProvider('authorizationFaults')]
    public function testReservationMustBeConfirmedBeforeFirstPayloadWrite(string $fault, bool $reserved): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $db->authorizationWriteFault = $fault;
        self::assertSame($reserved, $this->submit($service, $s, $shop, $db)->success);
        self::assertSame((int) $reserved, $db->payloadWrites);
        self::assertSame((int) $reserved, $transport->countOrderCreates());
        $db->authorizationWriteFault = '';
        self::assertTrue($this->submit($this->newLifecycle($db, $s->storeId, $transport), $s, $shop, $db)->success);
        self::assertSame(1, $db->payloadWrites);
        self::assertSame(1, $transport->countOrderCreates());
    }

    public function testIndependentLifecycleRejectsUnconfirmedConflictingReadBack(): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $expected = (new Uni\ControlPanelOrderPayloadBuilder())->build($s, self::ORDER, $shop);
        $raw = json_encode(['price' => 399.01] + $expected, JSON_THROW_ON_ERROR);
        $db->beforePayloadWrite = static function (ConsentFakeDb $db, int $id) use ($raw): void {
            $db->rows[$id]['cp_payload'] = $raw;
        };
        $db->failPayloadReadBack = true;
        try {
            $this->submit($service, $s, $shop, $db);
            self::fail('Expected a read-back exception');
        } catch (\RuntimeException $e) {
            self::assertSame('CP payload read-back failed', $e->getMessage());
        }
        self::assertSame(0, $db->holdWrites);
        self::assertSame(Uni\FinancingAttemptRepository::cpBodyAuthorization($expected), $db->rows[1]['last_error_class']);
        $db->holdWriteFault = 'zero';
        self::assertSame(Uni\ControlPanelErrorClass::CONFLICT, $this->submit(
            $this->newLifecycle($db, $s->storeId, $transport), $s, $shop, $db
        )->errorCode);
        self::assertSame($raw, $db->rows[1]['cp_payload']);
        self::assertSame(1, $db->payloadWrites);
        self::assertSame(0, $transport->countOrderCreates());
    }

    public function testIndependentLifecycleRecoversAuthorizedHistoricalBodyAndRecordedResult(): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $historical = ['price' => '399.0100', 'version' => '2.0.4']
            + (new Uni\ControlPanelOrderPayloadBuilder())->build($s, self::ORDER, $shop);
        $raw = json_encode($historical, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $db->rows[1]['cp_payload'] = $raw;
        $db->rows[1]['state'] = 'cp_failed_retryable';
        $db->failCpClaim = true;
        self::assertFalse($this->submit($service, $s, $shop, $db)->success);
        self::assertSame(Uni\FinancingAttemptRepository::cpBodyAuthorization($historical), $db->rows[1]['last_error_class']);
        self::assertSame(0, $transport->countOrderCreates());
        $db->failCpClaim = false;
        $result = $this->submit($this->newLifecycle($db, $s->storeId, $transport), $s, $shop, $db);
        self::assertTrue($result->success);
        self::assertSame($this->decode($raw), $this->sent($transport));
        $replay = $this->submit($this->newLifecycle($db, $s->storeId, $transport), $s, $shop, $db);
        self::assertTrue($replay->replay);
        self::assertSame($result->cpOrderId, $replay->cpOrderId);
        self::assertSame($raw, $db->rows[1]['cp_payload']);
        self::assertSame(0, $db->payloadWrites);
        self::assertSame(1, $transport->countOrderCreates());
    }

    public function testReadBackExceptionReleasesLeaseWithoutPosting(): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $db->failPayloadReadBack = true;
        try {
            $this->submit($service, $s, $shop, $db);
            self::fail('Expected a read-back exception');
        } catch (\RuntimeException $e) {
            self::assertSame('CP payload read-back failed', $e->getMessage());
        }
        self::assertSame(0, $transport->countOrderCreates());
        self::assertSame([], $db->locks);
        $raw = $db->rows[1]['cp_payload'];
        self::assertTrue($this->submit($service, $s, $shop, $db)->success);
        self::assertSame($raw, $db->rows[1]['cp_payload']);
        self::assertSame(1, $db->payloadWrites);
        self::assertSame(1, $transport->countOrderCreates());
    }

    public static function concurrentClaimChanges(): iterable
    {
        yield 'CP identity appears' => ['control_panel_order_id', 901];
        yield 'state becomes unknown' => ['state', 'cp_outcome_unknown'];
        yield 'state changes to another potentially eligible state' => ['state', 'cp_failed_retryable'];
        yield 'order identity changes' => ['order_id', 124];
        yield 'store identity changes' => ['store_id', 1234];
        yield 'body bytes change' => ['cp_payload', '{}'];
        yield 'origin changes' => ['cp_origin', 'https://other.example'];
        yield 'consent bytes change' => ['consent_context_json', '{}'];
        yield 'error changes' => ['last_error_class', 'cp_conflict'];
        yield 'lease ownership changes' => ['lock', 'another-owner'];
        yield 'lease expires' => ['expiry', '2000-01-01 00:00:00'];
    }

    #[DataProvider('concurrentClaimChanges')]
    public function testClaimRequiresThePreviouslyValidatedRowAndLease(string $field, mixed $value): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $db->beforeCpClaim = static function (ConsentFakeDb $db, int $id) use ($field, $value): void {
            if ($field === 'lock') {
                $db->locks[array_key_first($db->locks)] = $value;
            } elseif ($field === 'expiry') {
                $db->lockExpiry[array_key_first($db->locks)] = $value;
            } else {
                $db->rows[$id][$field] = $value;
            }
        };
        try {
            self::assertFalse($this->submit($service, $s, $shop, $db)->success);
        } catch (Uni\ProductFinancingFlowException $e) {
            self::assertSame('cp_origin', $field);
            self::assertSame(Uni\CpOriginGuard::RECONCILIATION_REQUIRED, $e->errorCode());
        }
        self::assertSame(1, $db->payloadWrites);
        self::assertSame(0, $transport->countOrderCreates());
    }

    public function testZeroRowClaimAllowsSameBodyPreDispatchRecovery(): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $db->failCpClaim = true;
        self::assertFalse($this->submit($service, $s, $shop, $db)->success);
        self::assertSame(0, $transport->countOrderCreates());
        self::assertSame('order_created', $db->rows[1]['state']);
        $raw = $db->rows[1]['cp_payload'];
        $db->failCpClaim = false;
        $nextTransport = new FakeCpHttpTransport();
        $nextTransport->enableAutoAuthAndCreate();
        $next = $this->newLifecycle($db, $s->storeId, $nextTransport);
        self::assertTrue($this->submit($next, $s, $shop, $db)->success);
        self::assertSame(0, $transport->countOrderCreates());
        self::assertSame(1, $nextTransport->countOrderCreates());
        self::assertSame($this->decode($raw), $this->sent($nextTransport));
        self::assertSame(1, $db->payloadWrites);
        self::assertSame($raw, $db->rows[1]['cp_payload']);
    }

    public function testClaimConfirmationReturnsNewlyRecordedResultWithoutPosting(): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $db->beforeCpClaimConfirmation = static function (ConsentFakeDb $db, int $id): void {
            self::assertSame('cp_submitting', $db->rows[$id]['state']);
            $db->rows[$id]['state'] = 'cp_created';
            $db->rows[$id]['control_panel_order_id'] = 901;
        };
        $result = $this->submit($service, $s, $shop, $db);
        self::assertTrue($result->success);
        self::assertSame(901, $result->cpOrderId);
        self::assertSame(0, $transport->countOrderCreates());
        self::assertSame(1, $db->payloadWrites);
    }

    public static function dispositionCases(): iterable
    {
        foreach (['success', 'rejection', 'timeout'] as $outcome) {
            foreach (['', 'zero', 'throw', 'lost-ack'] as $fault) {
                yield $outcome . ' / ' . ($fault ?: 'confirmed') => [$outcome, $fault, $fault === '' || $fault === 'lost-ack'];
            }
        }
    }

    #[DataProvider('dispositionCases')]
    public function testDispatchRemainsClosedAcrossDispositionWriteOutcomes(string $outcome, string $fault, bool $recorded): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        if ($outcome !== 'success') {
            $transport->enqueueJson(200, Cp::loginSuccessPayload());
            if ($outcome === 'timeout') {
                $transport->enqueueTimeout();
            } else {
                $transport->enqueueJson(422, [
                    'success' => false, 'error' => 'invalid_payload', 'message' => 'synthetic rejection', 'data' => new \stdClass(),
                ]);
            }
        }
        $db->cpDispositionWriteFault = $fault;
        $first = $this->submit($service, $s, $shop, $db);
        $claimed = $db->rows[1];
        $raw = $claimed['cp_payload'];
        $body = $this->decode($raw);
        $authorization = Uni\FinancingAttemptRepository::cpBodyAuthorization($body);
        [$state, $error] = match ($outcome) {
            'success' => ['cp_created', null],
            'rejection' => ['terminal_failed', Uni\ControlPanelErrorClass::REJECTED],
            'timeout' => ['cp_outcome_unknown', Uni\ControlPanelErrorClass::TIMEOUT],
        };
        $success = $outcome === 'success' && $recorded;
        self::assertSame(1, $transport->countOrderCreates());
        self::assertSame(499.01, $this->sent($transport)['price']);
        self::assertSame($body, $this->sent($transport));
        self::assertSame($recorded ? $state : 'cp_submitting', $claimed['state']);
        self::assertSame($recorded ? $error : $authorization, $claimed['last_error_class']);
        self::assertSame($success ? 901 : null, $claimed['control_panel_order_id'] ?? null);
        self::assertSame($success, $first->success);
        self::assertSame($outcome === 'rejection' && $recorded, $first->definitiveFailure);
        self::assertFalse($first->recoverable);
        self::assertSame($outcome === 'success' && !$recorded ? Uni\ControlPanelErrorClass::RECOVERY_FAILED : $error, $first->errorCode);

        $nextTransport = new FakeCpHttpTransport();
        $nextTransport->enableAutoAuthAndCreate();
        $next = $this->newLifecycle($db, $s->storeId, $nextTransport);
        $repeat = $this->submit($next, $s, $shop, $db);
        self::assertSame($success, $repeat->success);
        self::assertSame($success, $repeat->replay);
        self::assertFalse($repeat->recoverable);
        self::assertSame([], $nextTransport->requests);
        self::assertSame($claimed, $db->rows[1]);
        self::assertSame(1, $db->payloadWrites);

        if ($outcome === 'timeout' && !$recorded) {
            $contradictory = json_encode(['price' => 399.01] + $body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $db->rows[1]['cp_payload'] = $contradictory;
            $blocked = $this->submit($this->newLifecycle($db, $s->storeId, $nextTransport), $s, $shop, $db);
            self::assertFalse($blocked->success);
            self::assertFalse($blocked->recoverable);
            self::assertSame('cp_submitting', $db->rows[1]['state']);
            self::assertSame($authorization, $db->rows[1]['last_error_class']);
            self::assertSame($contradictory, $db->rows[1]['cp_payload']);
            self::assertSame([], $nextTransport->requests);
        }

        if ($outcome === 'success' && !$recorded) {
            // A subsequently verified CP ID can be recorded against the original dispatch snapshot.
            $db->cpDispositionWriteFault = '';
            self::assertTrue((new Uni\FinancingAttemptRepository($db))->recordCpCreated($claimed, 901));
            $reconciled = $this->submit($this->newLifecycle($db, $s->storeId, $nextTransport), $s, $shop, $db);
            self::assertTrue($reconciled->replay);
            self::assertSame(901, $reconciled->cpOrderId);
            self::assertSame($raw, $db->rows[1]['cp_payload']);
            self::assertNull($db->rows[1]['last_error_class']);
            self::assertSame([], $nextTransport->requests);
        }
    }

    public static function dispatchClaimFaults(): iterable
    {
        yield 'unapplied claim exception permits pre-dispatch recovery' => ['throw', false];
        yield 'applied claim without acknowledgement requires reconciliation' => ['lost-ack', true];
    }

    #[DataProvider('dispatchClaimFaults')]
    public function testDispatchClaimMustBeAcknowledgedAndConfirmed(string $fault, bool $claimed): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $db->cpClaimWriteFault = $fault;
        $first = $this->submit($service, $s, $shop, $db);
        $raw = $db->rows[1]['cp_payload'];
        self::assertFalse($first->success);
        self::assertSame(!$claimed, $first->recoverable);
        self::assertSame([], $transport->requests);
        self::assertSame($claimed ? 'cp_submitting' : 'order_created', $db->rows[1]['state']);
        self::assertSame(Uni\FinancingAttemptRepository::cpBodyAuthorization($this->decode($raw)), $db->rows[1]['last_error_class']);

        $db->cpClaimWriteFault = '';
        $nextTransport = new FakeCpHttpTransport();
        $nextTransport->enableAutoAuthAndCreate();
        $next = $this->newLifecycle($db, $s->storeId, $nextTransport);
        self::assertSame(!$claimed, $this->submit($next, $s, $shop, $db)->success);
        self::assertSame($claimed ? 0 : 1, $nextTransport->countOrderCreates());
        self::assertSame($raw, $db->rows[1]['cp_payload']);
        self::assertSame(1, $db->payloadWrites);
    }

    public function testInterruptionAfterConfirmedDispatchStopsEvenWithContradictoryBody(): void
    {
        [$db, $s, $shop, $transport] = $this->fixture();
        $service = $this->newLifecycle($db, $s->storeId, $transport, static function (array $event): void {
            if ($event['event'] === 'cp_submitting') {
                throw new \RuntimeException('Interrupted before transport invocation');
            }
        });
        try {
            $this->submit($service, $s, $shop, $db);
            self::fail('Expected an interruption between dispatch confirmation and transport');
        } catch (\RuntimeException $exception) {
            self::assertSame('Interrupted before transport invocation', $exception->getMessage());
        }
        $original = $db->rows[1]['cp_payload'];
        $body = $this->decode($original);
        $marker = Uni\FinancingAttemptRepository::cpBodyAuthorization($body);
        self::assertSame('cp_submitting', $db->rows[1]['state']);
        self::assertSame($marker, $db->rows[1]['last_error_class']);
        self::assertSame([], $transport->requests);
        self::assertSame([], $db->locks);

        $nextTransport = new FakeCpHttpTransport();
        $nextTransport->enableAutoAuthAndCreate();
        foreach ([$original, json_encode(['price' => 399.01] + $body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)] as $raw) {
            $db->rows[1]['cp_payload'] = $raw;
            $result = $this->submit($this->newLifecycle($db, $s->storeId, $nextTransport), $s, $shop, $db);
            self::assertSame(Uni\ControlPanelErrorClass::RECOVERY_FAILED, $result->errorCode);
            self::assertFalse($result->recoverable);
            self::assertSame('cp_submitting', $db->rows[1]['state']);
            self::assertSame($raw, $db->rows[1]['cp_payload']);
            self::assertSame($marker, $db->rows[1]['last_error_class']);
        }
        self::assertSame([], $nextTransport->requests);
        self::assertSame(1, $db->payloadWrites);
    }

    public function testPreviouslyRecordedTimeoutCannotReauthorizeHistoricalBody(): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $historical = ['price' => '399.0100', 'version' => '2.0.4']
            + (new Uni\ControlPanelOrderPayloadBuilder())->build($s, self::ORDER, $shop);
        $raw = json_encode($historical, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $db->rows[1]['cp_payload'] = $raw;
        $db->rows[1]['state'] = 'cp_submitting';
        $db->rows[1]['last_error_class'] = Uni\ControlPanelErrorClass::TIMEOUT;
        $result = $this->submit($service, $s, $shop, $db);
        self::assertSame(Uni\ControlPanelErrorClass::TIMEOUT, $result->errorCode);
        self::assertFalse($result->recoverable);
        self::assertSame([], $transport->requests);
        self::assertSame($raw, $db->rows[1]['cp_payload']);
        self::assertSame(Uni\ControlPanelErrorClass::TIMEOUT, $db->rows[1]['last_error_class']);
        self::assertSame(0, $db->authorizationWrites);
        self::assertSame(0, $db->payloadWrites);
    }

    public static function storedCases(): iterable
    {
        yield 'historical principal' => ['manual', ['price' => '399.0100'], 1];
        yield 'gross' => ['manual', ['price' => 499.01], 1];
        yield 'historical zero' => ['zero', ['price' => 499.01], 1];
        yield 'sub-cent price' => ['manual', ['price' => 399.011], 0];
        yield 'tampered price' => ['manual', ['price' => 399.02], 0];
        yield 'tampered down' => ['manual', ['parva' => 100.001], 0];
        yield 'tampered monthly' => ['manual', ['vnoska' => 1], 0];
        yield 'tampered APR' => ['manual', ['gpr' => 99], 0];
        yield 'unsupported amount' => ['manual', ['price' => 'INF'], 0];
        yield 'wrong currency' => ['manual', ['currency' => 'BGN'], 0];
        yield 'wrong order' => ['manual', ['order_id' => '321'], 0];
        yield 'wrong product' => ['manual', ['products_id' => '999'], 0];
        yield 'wrong product name' => ['manual', ['products_name' => 'Different product'], 0];
        yield 'wrong quantity' => ['manual', ['products_q' => '3'], 0];
        yield 'missing version' => ['manual', ['version' => null], 0];
    }

    #[DataProvider('storedCases')]
    public function testStoredBodyIsNeverRewritten(string $policy, array $patch, int $posts): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture(policy: $policy);
        $body = $patch + ['version' => '2.0.4'] + (new Uni\ControlPanelOrderPayloadBuilder())->build($s, self::ORDER, $shop);
        $raw = json_encode($body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $db->rows[1]['cp_payload'] = $raw;
        $db->rows[1]['state'] = Uni\FinancingAttemptState::CP_FAILED_RETRYABLE;
        try {
            $result = $this->submit($service, $s, $shop, $db);
            self::assertSame($posts === 1, $result->success);
        } catch (Uni\ProductFinancingFlowException $e) {
            self::assertSame(0, $posts, $e->getMessage());
        }
        self::assertSame($posts, $transport->countOrderCreates());
        self::assertSame(0, $db->payloadWrites);
        self::assertSame($raw, $db->rows[1]['cp_payload']);
        if ($posts) {
            self::assertSame($this->decode($raw), $this->sent($transport));
        }
    }

    public static function incompleteCases(): iterable
    {
        yield 'missing during prior send' => ['cp_submitting', null];
        yield 'missing during retry' => ['cp_failed_retryable', null];
        yield 'ambiguous outcome' => ['cp_outcome_unknown', null];
        yield 'malformed' => ['order_created', '{'];
        yield 'empty' => ['order_created', ''];
        yield 'partial object' => ['order_created', '{"order_id":"123"}'];
    }

    #[DataProvider('incompleteCases')]
    public function testIncompleteEvidenceCannotTriggerCreate(string $state, ?string $raw): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $db->rows[1]['state'] = $state;
        $db->rows[1]['cp_payload'] = $raw;
        try {
            self::assertFalse($this->submit($service, $s, $shop, $db)->success);
        } catch (Uni\ProductFinancingFlowException $e) {
            self::assertContains($e->errorCode(), ['invalid_consent', 'currency_unavailable']);
        }
        self::assertSame(0, $transport->countOrderCreates());
        self::assertSame(0, $db->payloadWrites);
        self::assertSame($raw, $db->rows[1]['cp_payload']);
    }

    public static function driftCases(): iterable
    {
        yield 'within existing tolerance' => [499.02, 1];
        yield 'outside existing tolerance' => [499.04, 0];
    }

    #[DataProvider('driftCases')]
    public function testResumeUsesFinalizedGross(float $nativeEur, int $posts): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $s->submissionSource = 'resume';
        $db->orders[self::ORDER] += (new Uni\OpenCartOrderDataBuilder())->build($s->orderDraft);
        $db->orders[self::ORDER]['total'] = $nativeEur / 0.5;
        try {
            $proof = (new Uni\DurableEurOrderGuard($db))->prove(1, $s->storeId, self::ORDER);
            (new Uni\DurableEurResumeHydrator($db))->hydrate(1, $s, $proof);
            self::assertSame($nativeEur, $s->financingCalculation->price);
            self::assertSame($posts === 1, $this->submit($service, $s, $shop, $db)->success);
        } catch (Uni\ProductFinancingFlowException $e) {
            self::assertSame(0, $posts, $e->getMessage());
        }
        self::assertSame($posts, $transport->countOrderCreates());
        if ($posts) {
            self::assertSame(499.01, $this->sent($transport)['price']);
            self::assertSame($nativeEur, $s->financingCalculation->price);
        }
    }

    public static function upstreamToleranceCases(): iterable
    {
        foreach (['fresh', 'resume'] as $source) {
            foreach ([-3, -2, -1, 0, 1, 2, 3] as $cents) {
                yield "{$source} drift {$cents} cents from 400.02" => [$source, 40002, $cents];
            }
            yield "{$source} drift -2 cents from 400.04" => [$source, 40004, -2];
        }
    }

    #[DataProvider('upstreamToleranceCases')]
    public function testCheckoutUpstreamTolerancePreservesAuthorizedTerms(string $source, int $grossCents, int $driftCents): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture('checkout', 2);
        $gross = $grossCents / 100.0;
        $savedGross = ($grossCents + $driftCents) / 100.0;
        $calculation = (new Uni\Calculator())->calculateScheme($shop, $gross, $s->financingCalculation->scheme, 100.0);
        $s->financingCalculation = clone $calculation;
        // Keep native base-total parity exact so the normalized EUR comparison is the only boundary under test.
        $s->orderDraft->orderTotal = $savedGross / 0.5;
        $db->orders[self::ORDER]['total'] = $s->orderDraft->orderTotal;
        $db->orders[self::ORDER] += (new Uni\OpenCartOrderDataBuilder())->build($s->orderDraft);
        // Stub the downstream Process 2 outcome and use recording mail so only CP creation is exercised.
        $db->rows[1] = ConsentFixture::row($s, [
            'order_id' => self::ORDER,
            'state' => 'order_created',
            'process2_state' => Uni\ProcessTwoLifecycleStates::PREPARED,
            'process2_mail_state' => Uni\ProcessTwoMailStates::SENT,
        ], true, $shop);
        $consent = $db->rows[1]['consent_context_json'];
        $mailer = new Uni\RecordingProcessTwoMailer();

        $result = null;
        try {
            if ($source === 'resume') {
                $s->submissionSource = 'resume';
                $proof = (new Uni\DurableEurOrderGuard($db))->prove(1, $s->storeId, self::ORDER);
                (new Uni\DurableEurResumeHydrator($db))->hydrate(1, $s, $proof, true);
                self::assertSame($savedGross, $s->financingCalculation->price);
            }
            $result = Uni\FinancingControlPanelCompletion::apply(
                $service,
                new Uni\FinancingAttemptContext($db->rows[1]),
                $s,
                self::ORDER,
                $shop,
                Uni\LockOwnerTokenGenerator::generate(),
                process2Mailer: $mailer
            );
        } catch (Uni\ProductFinancingFlowException $e) {
            self::assertGreaterThan(2, abs($driftCents), $e->errorCode());
            self::assertSame('currency_unavailable', $e->errorCode());
        }

        self::assertSame($consent, $db->rows[1]['consent_context_json']);
        self::assertSame([], $mailer->sent);
        if (abs($driftCents) > 2) {
            self::assertNull($result);
            self::assertSame(0, $db->payloadWrites);
            self::assertSame(0, $transport->countOrderCreates());

            return;
        }

        self::assertTrue($result->success);
        self::assertSame('process2_prepared', $result->step);
        self::assertSame(1, $db->payloadWrites);
        self::assertSame(1, $transport->countOrderCreates());
        self::assertSame($savedGross, $s->eurOrderProof->eurTotal);
        self::assertSame($source === 'resume' ? $savedGross : $gross, $s->financingCalculation->price);
        self::assertSame(round($gross - 100.0, 2), $s->financingCalculation->financedAmount);
        self::assertSame($calculation->monthlyInstallment, $s->financingCalculation->monthlyInstallment);
        self::assertSame($calculation->gpr, $s->financingCalculation->gpr);
        $body = $this->sent($transport);
        self::assertSame($gross, (float) $body['price']);
        self::assertSame(100.0, (float) $body['parva']);
        self::assertSame($calculation->monthlyInstallment, (float) $body['vnoska']);
        self::assertSame($calculation->gpr, (float) $body['gpr']);
    }

    public static function smartUcfToleranceCases(): iterable
    {
        foreach (['manual', 'mandatory', 'zero'] as $policy) {
            foreach ([-3, -2, -1, 0, 1, 2, 3] as $cents) {
                yield "{$policy} drift {$cents} cents from 400.02" => [$policy, 40002, $cents];
            }
        }
        yield 'manual drift -2 cents from 400.04' => ['manual', 40004, -2];
    }

    #[DataProvider('smartUcfToleranceCases')]
    public function testSmartUcfBuilderTolerancePreservesWireAmounts(string $policy, int $grossCents, int $driftCents): void
    {
        [, $submission, $shop, $transport] = $this->processOneFixture($policy, $grossCents, $driftCents);
        $builder = new Uni\SmartUcfPayloadBuilder();
        if (abs($driftCents) > 2) {
            try {
                $builder->build($submission, $shop, self::ORDER);
                self::fail('SmartUCF accepted a three-cent difference');
            } catch (Uni\ProductFinancingFlowException $exception) {
                self::assertSame('currency_unavailable', $exception->errorCode());
            }

            self::assertSame([], $transport->requests);
            return;
        }

        $exact = clone $submission;
        $exact->orderDraft = clone $submission->orderDraft;
        $exact->orderDraft->orderTotal = $grossCents / 50;
        $exact->eurOrderProof = new Uni\DurableEurOrderProof(self::ORDER, $submission->storeId, 1, 0.5, $exact->orderDraft->orderTotal, $grossCents / 100.0);
        $body = $builder->build($submission, $shop, self::ORDER);
        self::assertSame($builder->build($exact, $shop, self::ORDER), $body);
        [$initial, $monthly] = match ($policy) {
            'mandatory' => ['33.34', '34.83'],
            'zero' => ['0.00', '38.00'],
            default => ['100.00', '28.50'],
        };
        self::assertSame(number_format($grossCents / 100.0, 2, '.', ''), $body['totalPrice']);
        self::assertSame($initial, $body['initialPayment']);
        self::assertSame($monthly, $body['monthlyPayment']);
        self::assertSame('KOP1', $body['onlineProductCode']);
        self::assertSame(12, $body['installmentCount']);
        self::assertSame([[42, 2, '195.00']], array_map(
            static fn(array $item): array => [$item['code'], $item['count'], $item['singlePrice']],
            $body['items']
        ));
        self::assertSame([], $transport->requests);
    }

    public static function processOneSources(): iterable
    {
        yield 'fresh' => ['fresh', '400.02'];
        yield 'resumed' => ['resume', '400.04'];
    }

    #[DataProvider('processOneSources')]
    public function testProcessOneCompletesAtTwoCentsAndReplaysWithoutAnotherSession(string $source, string $bankGross): void
    {
        [$db, $submission, $shop, $transport, $service] = $this->processOneFixture('manual', 40002, 2);
        if ($source === 'resume') {
            $submission->submissionSource = 'resume';
            (new Uni\DurableEurResumeHydrator($db))->hydrate(1, $submission, $submission->eurOrderProof);
        }

        $bankClient = new class {
            public array $payloads = [];

            public function createSession(array $shop, Uni\ValidatedFinancingSubmission $submission, int $orderId): array
            {
                $this->payloads[] = (new Uni\SmartUcfPayloadBuilder())->build($submission, $shop, $orderId);

                return [
                    'session_id' => 'recorded-fixture-session',
                    'redirect_url' => 'https://bank.example.test/session',
                    'http_code' => 200,
                ];
            }
        };
        $coordinator = new Uni\SmartUcfSessionCoordinator(
            new Uni\SmartUcfLifecycleRepository($db),
            $bankClient,
            new Uni\SmartUcfFailureClassifier(),
            new Uni\OrderBankStatusRepository($db),
            $service->client(),
            new Uni\ControlPanelStatusSyncService(
                new Uni\ControlPanelStatusSyncRepository($db),
                $service->client(),
                new Uni\DurableConsentAuthority($db)
            )
        );
        $postCp = new Uni\PostControlPanelLifecycleService($coordinator);
        $consent = $db->rows[1]['consent_context_json'];
        $owner = Uni\LockOwnerTokenGenerator::generate();
        $result = Uni\FinancingControlPanelCompletion::apply(
            $service, new Uni\FinancingAttemptContext($db->rows[1]), $submission, self::ORDER, $shop, $owner, $postCp
        );
        self::assertTrue($result->success);
        self::assertSame('bank_redirect', $result->step);
        self::assertSame(901, $result->controlPanelOrderId);
        self::assertSame('created', $db->rows[1]['smartucf_state']);
        self::assertSame('recorded-fixture-session', $db->rows[1]['smartucf_session_id']);
        self::assertSame('confirmed', $db->rows[1]['cp_status_sync_state']);
        self::assertSame(Uni\BankStatus::SENT_PROCESS1, $db->bankStatuses[$submission->storeId][self::ORDER]['status_id']);

        $cpBody = $this->sent($transport);
        self::assertSame([400.02, 100.0, 28.50, 27.94], array_map(
            static fn(string $key): float => (float) $cpBody[$key], ['price', 'parva', 'vnoska', 'gpr']
        ));
        self::assertCount(1, $bankClient->payloads);
        $bankBody = $bankClient->payloads[0];
        self::assertSame([$bankGross, '100.00', '28.50'], [$bankBody['totalPrice'], $bankBody['initialPayment'], $bankBody['monthlyPayment']]);
        self::assertSame(300.02, $submission->financingCalculation->financedAmount);
        self::assertSame($consent, $db->rows[1]['consent_context_json']);
        $frozenBody = $db->rows[1]['cp_payload'];

        $replay = Uni\FinancingControlPanelCompletion::apply(
            $service, new Uni\FinancingAttemptContext($db->rows[1]), $submission, self::ORDER, $shop, $owner, $postCp
        );
        self::assertTrue($replay->replay);
        self::assertSame($frozenBody, $db->rows[1]['cp_payload']);
        self::assertSame(1, $db->payloadWrites);
        self::assertSame(1, $transport->countOrderCreates());
        self::assertSame(1, $transport->countStatusPatches());
        self::assertCount(1, $bankClient->payloads);
    }

    private function processOneFixture(string $policy, int $grossCents, int $driftCents): array
    {
        [$db, $submission, $shop, $transport, $service] = $this->fixture('checkout', 1, $policy);
        $submission->submissionSource = 'checkout_payment';
        $savedBase = ($grossCents + $driftCents) / 50;
        $submission->orderDraft->orderTotal = $savedBase;
        $submission->orderDraft->products[0] = array_replace(
            $submission->orderDraft->products[0], ['price' => 300.0, 'total' => 600.0, 'tax' => 90.0]
        );
        $submission->financingCalculation = (new Uni\Calculator())->calculateScheme(
            $shop, $grossCents / 100.0, $submission->financingCalculation->scheme, 100.0
        );
        $db->rows[1] = ConsentFixture::row($submission, [
            'order_id' => self::ORDER,
            'state' => 'order_created',
            'smartucf_state' => Uni\SmartUcfLifecycleStates::NOT_STARTED,
            'cp_status_sync_state' => Uni\ControlPanelStatusSyncStates::NOT_NEEDED,
        ], true, $shop);
        $db->simulateSmartUcfLifecycle = true;
        $db->orders[self::ORDER]['total'] = $savedBase;
        $db->orders[self::ORDER] += (new Uni\OpenCartOrderDataBuilder())->build($submission->orderDraft);
        $db->orderLines[self::ORDER] = $submission->orderDraft->products;
        $submission->eurOrderProof = (new Uni\DurableEurOrderGuard($db))->prove(1, $submission->storeId, self::ORDER);

        return [$db, $submission, $shop, $transport, $service];
    }

    public static function historicalToleranceCases(): iterable
    {
        foreach ([-3, -2, -1, 0, 1, 2, 3] as $cents) {
            yield "saved gross drift {$cents} cents" => [$cents];
        }
    }

    #[DataProvider('historicalToleranceCases')]
    public function testHistoricalRecordedOrderIncludesBothTwoCentBoundaries(int $cents): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $s->financingCalculation = (new Uni\Calculator())->calculateScheme($shop, 400.03, $s->financingCalculation->scheme, 99.99);
        $s->orderDraft->orderTotal = 800.06;
        $s->eurOrderProof = new Uni\DurableEurOrderProof(self::ORDER, $s->storeId, 1, 0.5, 800.06, 400.03);
        self::assertSame(300.04, $s->financingCalculation->financedAmount);
        $body = ['price' => 300.04, 'version' => '2.0.4'] + (new Uni\ControlPanelOrderPayloadBuilder())->build($s, self::ORDER, $shop);
        $raw = json_encode($body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $db->rows[1] = ConsentFixture::row($s, [
            'order_id' => self::ORDER, 'state' => 'cp_created', 'control_panel_order_id' => 901, 'cp_payload' => $raw,
        ], true, $shop);
        $db->orders[self::ORDER]['total'] = (40003 + $cents) / 50;
        try {
            $result = $this->submit($service, $s, $shop, $db);
            self::assertLessThanOrEqual(2, abs($cents));
            self::assertTrue($result->success);
            self::assertSame(901, $result->cpOrderId);
        } catch (Uni\ProductFinancingFlowException $e) {
            self::assertGreaterThan(2, abs($cents));
            self::assertSame('currency_unavailable', $e->errorCode());
        }
        self::assertSame($raw, $db->rows[1]['cp_payload']);
        self::assertSame(0, $db->payloadWrites);
        self::assertSame(0, $transport->countOrderCreates());
    }

    public static function rejectionCases(): iterable
    {
        yield 'canonical conflict' => [409, '{"success":false,"error":"semantic_conflict","message":"Conflict","data":{}}', 'terminal_failed'];
        yield 'bare conflict' => [409, '{}', 'cp_outcome_unknown'];
        yield 'service failure' => [503, '{}', 'cp_outcome_unknown'];
    }

    #[DataProvider('rejectionCases')]
    public function testFailureNeverTriggersAlternativeCreate(int $status, string $response, string $state): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $transport->enqueueJson(200, Cp::loginSuccessPayload());
        $transport->enqueue($status, $response);
        self::assertFalse($this->submit($service, $s, $shop, $db)->success);
        self::assertSame($state, $db->rows[1]['state']);
        self::assertFalse($this->submit($service, $s, $shop, $db)->success);
        self::assertSame(1, $transport->countOrderCreates());
        self::assertSame(1, $db->payloadWrites);
    }

    public function testLegacyRecordedResultStillRequiresHistoricalProof(): void
    {
        [$db, $s, $shop, $transport, $service] = $this->fixture();
        $body = (new Uni\ControlPanelOrderPayloadBuilder())->build($s, self::ORDER, $shop);
        $body['price'] = $s->financingCalculation->financedAmount;
        $body['version'] = '2.0.4';
        $db->rows[1] = array_replace($db->rows[1], [
            'consent_context_json' => null,
            'leasing_presentation_json' => json_encode(Uni\FinancingPresentationSnapshot::fromSubmission($s, self::ORDER, false, 901)->toArray(), JSON_THROW_ON_ERROR),
            'cp_payload' => json_encode($body, JSON_THROW_ON_ERROR),
            'state' => 'cp_created',
            'control_panel_order_id' => 901,
            'smartucf_state' => Uni\SmartUcfLifecycleStates::CREATED,
        ]);
        self::assertSame(self::ORDER, (new Uni\DurableEurOrderGuard($db))->prove(1, $s->storeId, self::ORDER, true)->orderId);
        self::assertSame(901, (new Uni\DurableConsentAuthority($db))->legacyRecorded(1)['control_panel_order_id']);
        self::assertSame(0, $transport->countOrderCreates());
        $body['gpr'] += 0.001;
        $db->rows[1]['cp_payload'] = json_encode($body, JSON_THROW_ON_ERROR);
        $this->expectException(Uni\ProductFinancingFlowException::class);
        (new Uni\DurableEurOrderGuard($db))->prove(1, $s->storeId, self::ORDER, true);
    }

    private function fixture(string $entry = 'product', int $process = 1, string $policy = 'manual'): array
    {
        $s = Orders::productSubmission($entry, $entry === 'checkout' ? self::ORDER : null);
        $s->orderDraft->currencyValue = 0.5;
        $nativeTotal = $entry === 'checkout' ? 1020.018 : 998.018;
        $s->orderDraft->orderTotal = $nativeTotal;
        $s->orderDraft->products[0]['price'] = 399.009;
        $s->orderDraft->products[0]['total'] = 798.018;
        $s->orderDraft->products[0]['tax'] = 100.0;
        if ($entry === 'checkout') {
            $s->orderDraft->totals[] = ['code' => 'shipping', 'value' => 22.0];
        }
        $shop = ['uni_proces' => $process === 2 ? 1 : 0, 'uni_first_vnoska' => $policy === 'zero' ? 0 : 1] + Product::shop();
        $scheme = new Uni\AvailableScheme('standard', 'KOP1', 12, 1, ['uni_parva' => $policy === 'mandatory' ? 1 : 0], ['coeff' => 0.095]);
        $gross = Uni\EurFinancingAmount::fromOrder($nativeTotal, 'EUR', 1, 0.5);
        $s->financingCalculation = (new Uni\Calculator())->calculateScheme($shop, $gross, $scheme, 100.0);
        $s->eurOrderProof = new Uni\DurableEurOrderProof(self::ORDER, $s->storeId, 1, 0.5, $nativeTotal, $gross);
        $db = new ConsentFakeDb();
        $db->simulateCpLifecycle = true;
        $db->rows[1] = ConsentFixture::row($s, ['order_id' => self::ORDER, 'state' => 'order_created'], true, $shop);
        $db->orders[self::ORDER] = ['order_id' => self::ORDER, 'store_id' => $s->storeId, 'total' => $nativeTotal, 'currency_code' => 'EUR', 'currency_id' => 1, 'currency_value' => 0.5];
        $db->orderLines[self::ORDER] = $s->orderDraft->products;

        $transport = new FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();

        return [$db, $s, $shop, $transport, $this->newLifecycle($db, $s->storeId, $transport)];
    }

    private function newLifecycle(ConsentFakeDb $db, int $storeId, FakeCpHttpTransport $transport, ?callable $logger = null): Uni\ControlPanelOrderLifecycleService
    {
        $settings = Cp::settings();
        Cp::prepareCredentials($settings, $storeId);
        $cipher = Cp::cipher();
        $client = new Uni\ControlPanelClient(new Uni\ModuleCredentialsRepository($settings, $cipher), new Uni\CpTokenRepository($settings, $cipher, $storeId), $transport, Cp::TEST_SHOP_URL, $storeId);

        return new Uni\ControlPanelOrderLifecycleService(new Uni\FinancingAttemptRepository($db), new Uni\OperationLockRepository($db), $client, new Uni\ControlPanelOrderPayloadBuilder(), $logger);
    }

    private function submit(Uni\ControlPanelOrderLifecycleService $service, Uni\ValidatedFinancingSubmission $s, array $shop, ConsentFakeDb $db): Uni\ControlPanelOrderSubmissionResult
    {
        return $service->submitOrRecover(new Uni\FinancingAttemptContext($db->rows[1]), $s, self::ORDER, $shop, Uni\LockOwnerTokenGenerator::generate());
    }

    private function sent(FakeCpHttpTransport $transport): array
    {
        foreach ($transport->requests as $request) {
            if ($request['method'] === 'POST' && str_ends_with($request['url'], '/orders')) {
                return $request['payload'];
            }
        }
        self::fail('No captured CP create');
    }

    private function decode(string $raw): array
    {
        // JSON does not preserve a distinction between integral decimal values and integers.
        return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    }
}
