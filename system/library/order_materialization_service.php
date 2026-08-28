<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Orchestrates attempt state, operation lock, recovery, attach-once and gateway dispatch.
 */
final class OrderMaterializationService
{
    public function __construct(
        private FinancingAttemptRepository $attempts,
        private OperationLockRepository $locks,
        private OpenCartOrderMaterializer $materializer,
        private CheckoutExistingOrderGateway $checkoutGateway,
        private CheckoutOrderModelPort $orders,
        private FinancingOrderStatusPolicy $statusPolicy
    ) {
    }

    public function materializeAndBind(
        ValidatedFinancingSubmission $submission,
        FinancingAttemptContext $attempt,
        string $lockOwnerToken
    ): CreatedOpenCartOrder {
        if ($attempt->storeId() !== $submission->storeId) {
            throw new OrderMaterializationException('Attempt store scope mismatch.');
        }

        if (!$this->locks->acquire(
            $submission->storeId,
            $submission->entryPoint,
            $submission->operationKeyHash,
            $lockOwnerToken
        )) {
            throw new OrderMaterializationException('Financing operation lock is held by another request.');
        }

        try {
            return $this->materializeUnderLock($submission, $attempt);
        } finally {
            $this->locks->release(
                $submission->storeId,
                $submission->entryPoint,
                $submission->operationKeyHash,
                $lockOwnerToken
            );
        }
    }

    private function materializeUnderLock(
        ValidatedFinancingSubmission $submission,
        FinancingAttemptContext $attempt
    ): CreatedOpenCartOrder {
        $attempt = $this->refreshAttempt($attempt->attemptId());

        $boundOrderId = $attempt->orderId();
        if ($boundOrderId !== null) {
            return $this->materializer->loadVerified($boundOrderId, $submission, true);
        }

        $this->advanceToOrderCreating($attempt);

        $created = $this->resolveOrCreateOrder($submission, $attempt);
        $this->attempts->attachOrder($attempt->attemptId(), $created->orderId);

        if (!$this->attempts->transitionFromStates(
            $attempt->attemptId(),
            [FinancingAttemptState::ORDER_CREATING],
            FinancingAttemptState::ORDER_CREATED
        )) {
            $this->attempts->transitionFromStates(
                $attempt->attemptId(),
                [FinancingAttemptState::ORDER_CREATED],
                FinancingAttemptState::ORDER_CREATED
            );
        }

        if ($this->statusPolicy->shouldApplyAwaitingStatus($submission->entryPoint)) {
            $statusId = $this->statusPolicy->awaitingFinancingStatusId();
            if ($statusId > 0 && $created->orderStatusId !== $statusId) {
                $this->orders->addHistory($created->orderId, $statusId, '', false);
                $created->orderStatusId = $statusId;
            }
        }

        return $created;
    }

    private function resolveOrCreateOrder(
        ValidatedFinancingSubmission $submission,
        FinancingAttemptContext $attempt
    ): CreatedOpenCartOrder {
        if ($submission->entryPoint === OperationEntryPoint::CHECKOUT) {
            return $this->checkoutGateway->materialize($submission, $attempt);
        }

        return $this->materializer->materializeNew($submission, $attempt);
    }

    private function advanceToOrderCreating(FinancingAttemptContext $attempt): void
    {
        $attemptId = $attempt->attemptId();
        if ($this->attempts->transitionFromStates(
            $attemptId,
            [FinancingAttemptState::ISSUED, FinancingAttemptState::VALIDATING],
            FinancingAttemptState::ORDER_CREATING
        )) {
            return;
        }

        if ($attempt->state() === FinancingAttemptState::ORDER_CREATING) {
            return;
        }

        if ($attempt->state() === FinancingAttemptState::ORDER_CREATED && $attempt->orderId() !== null) {
            return;
        }

        throw new OrderMaterializationException('Financing attempt is not in a materialization-ready state.');
    }

    private function refreshAttempt(int $attemptId): FinancingAttemptContext
    {
        $row = $this->attempts->findById($attemptId);
        if ($row === null) {
            throw new OrderMaterializationException('Financing attempt was not found.');
        }

        return new FinancingAttemptContext($row);
    }
}
