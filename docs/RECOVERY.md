# Recovery — financing attempt / local order

OpenCart cart/session/order recovery mechanics in this document are **implementation details**. They must not redefine business semantics for standard bank status, terminal results, emails, leasing presentation, or CP/SmartUCF outcomes. See **`docs/BANK-STATUS-AND-PRESENTATION.md`**.

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

If CP submit fails while `order_status_id <= 0`, apply `addHistory(config_order_status_id)` (neutral Pending). The same cart and Pending status allow the native order to be reused without a second `addOrder()`. CP submission can continue automatically only from an eligible pre-dispatch attempt.

## Status visibility (Phase 10A / remediated)

Product/Cart apply `addHistory(payment_mt_uni_credit_order_status_id)` after attach.  
Source: UniCredit payment method „Състояние на поръчката“ only.  
Fallback: if that setting is missing/invalid (`<= 0`), no status update is applied (order may remain at 0 until configured).  
Checkout is not rewritten by this initializer.

## CP create crash window (Phase 10B)

### Frozen CP body and first send

Under the existing operation lock, a send-eligible attempt first reserves a
body authorization in the existing `last_error_class` column. The
`cp_body_v1:` value binds the intended semantic body. The conditional
reservation checks the verified attempt and lease, and an authoritative
read-back must confirm it before a first payload write or a submission claim.
A first payload write still requires an eligible `order_created` row and SQL
NULL payload. If that write does not apply, a repeat with a NULL body can
proceed only when the newly built body matches the earlier authorization.

An already stored, unmarked historical body in an eligible pre-dispatch
state can receive its own authorization after passing the existing
stored-body checks. The stored bytes are preserved.
When an observed body conflicts with a confirmed authorization, try to record
`cp_outcome_unknown` with `cp_conflict`. If the hold is unapplied or its
acknowledgement is lost, the earlier authorization still prevents a later send
of the contradictory body. Preserve the body for technical reconciliation.
The hold does not establish that CP received a request.

The body fingerprint proves which body was authorized. While the attempt
is in an eligible `order_created` or `cp_failed_retryable` state, that
authorization can be recovered before dispatch. With a validated frozen body,
the submission claim conditionally enters `cp_submitting`, retaining the
fingerprint. It matches the exact stored bytes, consent, origin, state, and
local order identity. It also requires a live caller-owned operation lease
and a NULL CP identifier. Only the caller that made the transition and
confirmed the resulting row and lease may invoke CP.

Eligible replay uses the stored JSON without rewriting it, preserving its
original exact numeric amounts, order identity and wire version. Proven
historical principal-priced bodies remain valid, as do stored gross-priced
bodies. An already recorded CP identity continues locally under the existing
provenance and downstream claim rules. The legacy recorded-result exception
remains read-only and requires its existing evidence.

### `cp_failed_retryable`

Existing retry eligibility requires a valid frozen body. Preserve the stored
body on retry. A missing body in an attempted or retry state, a corrupt body,
or other known or suspected incomplete historical evidence requires technical
reconciliation. Automatic reconstruction and alternate-price requests are
prohibited. An entirely missing historical attempt cannot always be
distinguished from a genuine new intent. Hold suspected cases before submitting
the affected native order and reconcile the original order and shop evidence
through the owner or an authorized operator.

### Possible dispatch and recorded outcomes

An unresolved `cp_submitting` row requires technical reconciliation, including
historical rows in that state. Dispatch may have started. A claim that committed
without acknowledgement, or an interruption immediately before transport,
can require reconciliation even when CP received no request.

Outcome recording checks the dispatch snapshot and changes the state and error
classification in one write. A confirmed create records its CP ID and
`cp_created` together. A definitive rejection records `terminal_failed`
with `cp_rejected` before terminal failure processing. An ambiguous result
records `cp_outcome_unknown` with its classification. If the write does not
apply, `cp_submitting` and its fingerprint continue to block automatic
creation. A lost acknowledgement can be resolved by exact read-back of the
recorded disposition. Preserve the frozen body and local order identity.

For an unresolved dispatch, the owner or authorized operator must reconcile
before further processing. Automatic CP creation, SmartUCF, Process 2, and
`bank_send_failed_cp` are blocked while the outcome is ambiguous.

Ambiguity includes timeouts, connection or authentication failure during
dispatch, malformed responses or echo mismatches, HTTP 429 or 5xx, unknown
4xx, noncanonical 409, and loss of local persistence before a confirmed CP ID
can be recorded.

`cp_outcome_unknown` is an **internal/service lifecycle state**. It is not a public bank status and must not invent a fifth customer-facing bank-status label.

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

EGN/phone2 are no longer collected or validated. New attempts keep sensitive ciphertext SQL NULL. Handoff and historical pending merchant-mail retry do not require or decrypt ciphertext; NULL, old valid and malformed ciphertext do not change recovery admission. Consent/process identity, frozen financing hashes, ownership, durable targets and the above retry policy remain unchanged. Legacy outcomes without consent evidence remain read-only.

## SmartUCF unknown outcome (Phase 11A)

`submitting` is an atomic claim. A valid returned session is persisted as `created`; replay returns the stored trusted redirect without a second bank call. Timeout, duplicate-order evidence, invalid response after send, or stale `submitting` becomes `outcome_unknown`. Customers are told not to resubmit, and `bank_send_failed_smartucf` is not written for an ambiguous outcome. Only a definitive failure writes that status locally and to CP (via durable `cp_status_sync_*`, same admit → local → PATCH ordering).

## Consent recovery prerequisite

New-format recovery uses finalized consent conditions plus native EUR/product/option verification, including after presentation redaction. Legacy recorded outcomes are read-only; absent or malformed evidence cannot authorize new effects. See [UNI-CONSENT-001-OC4.md](UNI-CONSENT-001-OC4.md).
