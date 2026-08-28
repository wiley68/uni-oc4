<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Read-only view of a durable financing attempt row for order materialization. */
final class FinancingAttemptContext
{
    /** @param array<string, mixed> $row */
    public function __construct(private array $row)
    {
    }

    public function attemptId(): int
    {
        return (int) ($this->row['attempt_id'] ?? 0);
    }

    public function storeId(): int
    {
        return (int) ($this->row['store_id'] ?? 0);
    }

    public function entryPoint(): string
    {
        return (string) ($this->row['entry_point'] ?? '');
    }

    public function state(): string
    {
        return (string) ($this->row['state'] ?? '');
    }

    public function orderId(): ?int
    {
        $orderId = $this->row['order_id'] ?? null;

        return ($orderId !== null && (int) $orderId > 0) ? (int) $orderId : null;
    }

    public function operationKeyHash(): string
    {
        return (string) ($this->row['operation_key_hash'] ?? '');
    }

    /** @return array<string, mixed> */
    public function row(): array
    {
        return $this->row;
    }

    public function withRow(array $row): self
    {
        return new self($row);
    }
}
