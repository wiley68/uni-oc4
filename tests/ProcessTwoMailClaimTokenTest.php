<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ProcessTwoLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\ProcessTwoMailStates;
use PHPUnit\Framework\TestCase;

/**
 * REVIEW-08 — process2 mail claim token ownership.
 */
final class ProcessTwoMailClaimTokenTest extends TestCase
{
    public function testOwnerTokenCanMarkSentNonOwnerCannot(): void
    {
        $db = new InMemoryProcess2MailDb();
        $repo = new ProcessTwoLifecycleRepository($db, new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock(fn (): int => 1_700_000_000));
        $db->rows[1] = $this->baseRow();

        $token = $repo->claimSending(1);
        self::assertNotNull($token);
        self::assertSame(64, strlen($token));
        self::assertSame(ProcessTwoMailStates::SENDING, $db->rows[1]['process2_mail_state']);

        $repo->markSent(1, '0' . substr($token, 1)); // wrong token
        self::assertSame(ProcessTwoMailStates::SENDING, $db->rows[1]['process2_mail_state']);

        $repo->markSent(1, $token);
        self::assertSame(ProcessTwoMailStates::SENT, $db->rows[1]['process2_mail_state']);
        self::assertSame(1, (int) $db->rows[1]['process2_mail_sent']);
    }

    public function testStaleReclaimIssuesNewTokenOldCannotMutate(): void
    {
        $clock = new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock(fn (): int => 1_700_000_000);
        $db = new InMemoryProcess2MailDb();
        $repo = new ProcessTwoLifecycleRepository($db, $clock);
        $db->rows[2] = $this->baseRow();

        $old = $repo->claimSending(2);
        self::assertNotNull($old);
        $db->rows[2]['process2_mail_claimed_at'] = gmdate(
            'Y-m-d H:i:s',
            $clock->now() - ProcessTwoMailStates::SENDING_STALE_SECONDS - 1
        );

        $new = $repo->claimSending(2);
        self::assertNotNull($new);
        self::assertNotSame($old, $new);

        $repo->releaseSendingOnFailure(2, $old);
        self::assertSame(ProcessTwoMailStates::SENDING, $db->rows[2]['process2_mail_state']);

        $repo->markSent(2, $old);
        self::assertSame(ProcessTwoMailStates::SENDING, $db->rows[2]['process2_mail_state']);

        $repo->markSent(2, $new);
        self::assertSame(ProcessTwoMailStates::SENT, $db->rows[2]['process2_mail_state']);
    }

    public function testSentNeverReclaimed(): void
    {
        $db = new InMemoryProcess2MailDb();
        $repo = new ProcessTwoLifecycleRepository($db, new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock(fn (): int => 1_700_000_000));
        $db->rows[3] = $this->baseRow();
        $token = $repo->claimSending(3);
        self::assertNotNull($token);
        $repo->markSent(3, $token);
        self::assertNull($repo->claimSending(3));
    }

    public function testConcurrentSecondClaimFails(): void
    {
        $db = new InMemoryProcess2MailDb();
        $repo = new ProcessTwoLifecycleRepository($db, new \Opencart\System\Library\Extension\MtUniCredit\PersistenceClock(fn (): int => 1_700_000_000));
        $db->rows[4] = $this->baseRow();
        $first = $repo->claimSending(4);
        self::assertNotNull($first);
        self::assertNull($repo->claimSending(4));
    }

    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        return [
            'attempt_id' => 0,
            'process2_state' => 'process2_prepared',
            'process2_sensitive_enc' => null,
            'process2_mail_sent' => 0,
            'process2_mail_state' => ProcessTwoMailStates::NOT_SENT,
            'process2_mail_claimed_at' => null,
            'process2_mail_claim_token' => null,
            'leasing_presentation_json' => null,
            'store_id' => 0,
            'order_id' => 1,
            'control_panel_order_id' => null,
            'state' => 'cp_created',
            'updated_at' => '2023-11-14 22:13:20',
            'cp_status_sync_state' => 'not_needed',
            'cp_status_sync_status_id' => null,
            'cp_status_sync_status' => null,
        ];
    }
}

final class InMemoryProcess2MailDb implements \Opencart\System\Library\Extension\MtUniCredit\DbConnection
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $affected = 0;

    public function query(string $sql): object
    {
        $this->affected = 0;
        if (preg_match('/WHERE `attempt_id` = (\d+)/', $sql, $m)) {
            $id = (int) $m[1];
            if (!isset($this->rows[$id])) {
                return $this->result([]);
            }
            $row = &$this->rows[$id];

            if (str_starts_with(ltrim($sql), 'SELECT')) {
                return $this->result([$row]);
            }

            if (str_contains($sql, "`process2_mail_state` = 'sending'")
                && str_contains($sql, 'process2_mail_claim_token')
                && str_contains($sql, "`process2_mail_state` = 'not_sent'")
            ) {
                $state = (string) ($row['process2_mail_state'] ?? '');
                $sent = (int) ($row['process2_mail_sent'] ?? 0);
                $claimedAt = $row['process2_mail_claimed_at'] ?? null;
                $stale = false;
                if (preg_match("/process2_mail_claimed_at` < '([^']+)'/", $sql, $sm)) {
                    $stale = $claimedAt === null || (string) $claimedAt < $sm[1];
                }
                $canClaim = $sent === 0 && (
                    $state === ProcessTwoMailStates::NOT_SENT
                    || ($state === ProcessTwoMailStates::SENDING && $stale)
                );
                if ($canClaim && preg_match("/process2_mail_claim_token` = '([a-f0-9]{64})'/", $sql, $tm)) {
                    $row['process2_mail_state'] = ProcessTwoMailStates::SENDING;
                    $row['process2_mail_claim_token'] = $tm[1];
                    if (preg_match("/process2_mail_claimed_at` = '([^']+)'/", $sql, $cm)) {
                        $row['process2_mail_claimed_at'] = $cm[1];
                    }
                    $this->affected = 1;
                }

                return $this->result([]);
            }

            if (str_contains($sql, "`process2_mail_state` = 'sent'")
                && preg_match("/process2_mail_claim_token` = '([a-f0-9]{64})'/", $sql, $tm)
            ) {
                if ((string) ($row['process2_mail_state'] ?? '') === ProcessTwoMailStates::SENDING
                    && (string) ($row['process2_mail_claim_token'] ?? '') === $tm[1]
                ) {
                    $row['process2_mail_state'] = ProcessTwoMailStates::SENT;
                    $row['process2_mail_sent'] = 1;
                    $this->affected = 1;
                }

                return $this->result([]);
            }

            if (str_contains($sql, "`process2_mail_state` = 'not_sent'")
                && preg_match("/process2_mail_claim_token` = '([a-f0-9]{64})'/", $sql, $tm)
            ) {
                if ((string) ($row['process2_mail_state'] ?? '') === ProcessTwoMailStates::SENDING
                    && (string) ($row['process2_mail_claim_token'] ?? '') === $tm[1]
                ) {
                    $row['process2_mail_state'] = ProcessTwoMailStates::NOT_SENT;
                    $row['process2_mail_claimed_at'] = null;
                    $row['process2_mail_claim_token'] = null;
                    $this->affected = 1;
                }

                return $this->result([]);
            }
        }

        return $this->result([]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function result(array $rows): object
    {
        return new class ($rows) {
            public int $num_rows;
            /** @var array<string, mixed> */
            public array $row;
            /** @var list<array<string, mixed>> */
            public array $rows;

            /** @param list<array<string, mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
                $this->num_rows = count($rows);
                $this->row = $rows[0] ?? [];
            }
        };
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function countAffected(): int
    {
        return $this->affected;
    }

    public function getLastId(): int
    {
        return 0;
    }

    public function getPrefix(): string
    {
        return 'oc_';
    }
}
