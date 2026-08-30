<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfFailureClassifier
{
    public function classifyThrowable(\Throwable $exception): SmartUcfFailureClassification
    {
        if (!$exception instanceof SmartUcfSessionException) {
            return new SmartUcfFailureClassification(
                SmartUcfLifecycleStates::FAILED,
                true,
                SmartUcfFailureClassification::CLASS_PRE_SEND
            );
        }

        $kind = $exception->getFailureKind();
        $httpCode = $exception->httpCode();
        $raw = strtolower($exception->rawResponse() . ' ' . $exception->getMessage());
        if ($kind === SmartUcfSessionException::KIND_PRE_SEND) {
            return new SmartUcfFailureClassification(
                SmartUcfLifecycleStates::FAILED,
                true,
                SmartUcfFailureClassification::CLASS_PRE_SEND,
                $httpCode
            );
        }
        if ($kind === SmartUcfSessionException::KIND_DUPLICATE || $this->looksLikeDuplicate($raw)) {
            return new SmartUcfFailureClassification(
                SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
                false,
                SmartUcfFailureClassification::CLASS_DUPLICATE_ORDER_NO,
                $httpCode
            );
        }
        if ($kind === SmartUcfSessionException::KIND_TRANSPORT || $httpCode === 0 || $httpCode >= 500) {
            return new SmartUcfFailureClassification(
                SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
                false,
                SmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                $httpCode
            );
        }

        return new SmartUcfFailureClassification(
            SmartUcfLifecycleStates::FAILED,
            false,
            SmartUcfFailureClassification::CLASS_REMOTE_REJECT,
            $httpCode
        );
    }

    private function looksLikeDuplicate(string $value): bool
    {
        return (str_contains($value, 'duplicate') && str_contains($value, 'order'))
            || str_contains($value, 'already exists')
            || str_contains($value, 'order already')
            || str_contains($value, 'съществува');
    }
}
