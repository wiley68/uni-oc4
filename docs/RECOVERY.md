# Recovery — financing attempt / local order

## Crash window (Product / Cart)

```text
addOrder() creates order N
→ correlation insert (attempt_id, store_id, order_id)
→ process interrupted before client sees success
→ retry same attempt
→ recover N (no second addOrder)
```

## Duplicate click

Operation lock `(store_id, entry_point, operation_key_hash)` serializes materialization. Bound `order_id` short-circuits to replay `local_order_prepared`.

## Checkout

No `addOrder()` from UniCredit payment. Recovery = reuse `session.order_id` when status is 0, awaiting-financing, or OC4.1 void (`config_void_status_id`) **and** the stored order still matches the live cart (products/options/qty, currency, **checkout grand total** via `checkout/cart::getTotals` — not `cart->getTotal()`, which omits shipping).

### Stale `session.order_id` (Guest / logged)

Native OC4.1 `editOrder()` voids the draft (`config_void_status_id`). Cart add/edit/remove clear `payment_method` but **not** `order_id`. Confirm only `editOrder()`s when `order_status_id == 0`, so a Voided id after a cart change is neither updated nor replaced — payment modules then show the old `order.total`.

Module remediation (no core patch):

1. Cart mutation events clear `session.order_id` so confirm can `addOrder()` again.
2. `confirm/before` + UniCredit `resolveSessionOrder()` reconcile parity and clear when the order no longer matches the live cart.

Old Voided rows remain in DB/history. Old financing attempts stay bound to the old `order_id`; a materially new checkout gets a new local order / attempt lifecycle. Unchanged cart retries keep the same `session.order_id` (no duplicate draft).

### Checkout CP failure visibility

If CP submit fails while `order_status_id <= 0`, apply `addHistory(config_order_status_id)` (neutral Pending). Attempt may be `cp_failed_retryable` or `cp_outcome_unknown` (see Phase 10B). Same cart + Pending remains reuse-eligible for the local order without a second `addOrder()`; automatic CP `POST /orders` resend is allowed only from `cp_failed_retryable`, never from `cp_outcome_unknown`.

## Status visibility (Phase 10A / remediated)

Product/Cart apply `addHistory(payment_mt_uni_credit_order_status_id)` after attach.  
Source: UniCredit payment method „Състояние на поръчката“ only.  
Fallback: if that setting is missing/invalid (`<= 0`), no status update is applied (order may remain at 0 until configured).  
Checkout is not rewritten by this initializer.

## CP create crash window (Phase 10B)

```text
POST /orders → CP creates N
→ OC crashes before control_panel_order_id write
→ state cp_outcome_unknown (known cpId may be logged; persist failed)
→ DO NOT automatic re-POST
→ operator / safe manual recovery only
```

### `cp_failed_retryable`

May re-POST frozen `cp_payload` only when the failure is proven local / pre-send (create request could not have created a remote order). Production uses this narrowly for cases such as unresolved frozen payload or a failed enter-`cp_submitting` claim that is not already `cp_created` / `cp_outcome_unknown`. It must **not** describe HTTP 5xx, timeout, transport ambiguity, auth ambiguity, malformed CP responses, 429, unknown 4xx, or malformed/noncanonical 409.

### `cp_outcome_unknown`

Use when CP may have received/processed `POST /orders` but the module cannot prove the result. Preserve frozen `cp_payload` and local order identity. **Do not** automatically re-POST `/orders`, start SmartUCF, start Process 2, or write false `bank_send_failed_cp`. Operator / safe manual recovery only.

Includes: transport timeout; connection failure after submission may have occurred; authentication failure after the request was sent; malformed response / malformed success / echo mismatch; HTTP 429; **HTTP 5xx**; unknown/noncanonical 4xx; malformed/noncanonical 409; persist race with known `cpId` before durable `control_panel_order_id` write.

Never invent a second shop `order_id`. CP has no GET-by-local-order lookup. See `docs/PHASE10B.md`.

## Process 1 bank status (Phase 11A)

```text
SmartUCF success
→ durable cp_status_sync admit target (bank_sent_process1)
→ local bank_sent_process1
→ PATCH from persisted target → confirmed | pending
```

On target CONFLICT: stop — no local mutation, no PATCH.
If local write fails after target admission: retain pending target; replay recovers via local restore then `retryPending` without a second SmartUCF call or target replacement.

Replay of `smartucf_state=created`:

```text
inspect persisted CP target (state + status_id + status text)
→ require exact canonical Process 1 target
→ ensure local bank_sent_process1 (idempotent)
→ only then PATCH if pending
→ if already confirmed: restore local only (no second PATCH)
```

Ordinary replay never synthesizes a missing target from `not_needed` itself.
When production replay observes exactly `SmartUCF=created` + `cp_status_sync=not_needed`, it invokes
separately guarded `recoverMissingProcess1TargetAfterCreatedSmartUcf` which admits a target only after
proving store/order/UNICID ownership and no local Process 2 conflict.

Wrong `status_id`, wrong/empty `status` text, or `terminal_failed` → stop with no local mutation and no PATCH.

Forbidden on replay: second CP create, second SmartUCF session, PATCH while local terminal fact is still missing,
silent target synthesis without the guarded recovery branch.

## Process 2 handoff (Phase 11B)

```text
cp_created
→ claimPreparing (durable preparation lease)
→ durable cp_status_sync admit target (bank_sent_process2)
→ local bank_sent_process2
→ PATCH from persisted target → confirmed | pending | terminal_failed
→ markPrepared (even if PATCH still pending)
→ leasing mail via process2_mail_state (not_sent → sending → sent)
  with process2_mail_claim_token ownership
→ thank-you redirect
```

Invariant: durable CP target exists before the local terminal bank fact is exposed.

Stale `preparing` recovery: if a process2 sync target is already pending/confirmed, resume local/PATCH/mail without repeating preparation. Otherwise reclaim only after the preparing lease is stale; never blindly duplicate an unprovable remote handoff.

Replay of `process2_prepared`: `retryPending` only (no repeated local bank handoff if already process2). Mail skips when `process2_mail_state=sent` (legacy `process2_mail_sent=1` still honored).

Mail claim: only the matching `process2_mail_claim_token` may `markSent` / release. Stale reclaim replaces the token; the old claimant must not mutate the new lease.

Residual SMTP window (not exact-once without provider idempotency):

```text
successful SMTP send → process crash before markSent → stale reclaim → possible duplicate mail
```

Validation failure (EGN/phone2) before handoff leaves CP at `cp_sent` and does not write `bank_sent_process2`.

## SmartUCF unknown outcome (Phase 11A)

`submitting` is an atomic claim. A valid returned session is persisted as `created`; replay returns the stored trusted redirect without a second bank call. Timeout, duplicate-order evidence, invalid response after send, or stale `submitting` becomes `outcome_unknown`. Customers are told not to resubmit, and `bank_send_failed_smartucf` is not written for an ambiguous outcome. Only a definitive failure writes that status locally and to CP (via durable `cp_status_sync_*`, same admit → local → PATCH ordering).
