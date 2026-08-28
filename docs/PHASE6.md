# UniCredit OpenCart 4.x — Phase 6: Shared Submission and Order Materialization

Phase 6 introduces the common validated submission boundary and the OpenCart local-order-first gateway foundation. No Product/Cart/Checkout UI, CP submission, snapshot, SmartUCF or payment controllers.

Frozen lifecycle rule:

```text
validated entry-point context
        ↓
usable local OpenCart order
        ↓
durable attempt binding
        ↓
(later CP submission — not Phase 6)
```

## Common submission boundary

`ValidatedFinancingSubmission` converges Product, Cart and Checkout after entry-specific validation:

| Field                                           | Purpose                                   |
| ----------------------------------------------- | ----------------------------------------- |
| `entryPoint`                                    | `product`, `cart`, `checkout`             |
| `submissionToken`                               | Product/Cart only (64 hex); checkout NULL |
| `operationKeyHash`                              | Phase 3 durable operation identity        |
| `storeId`, `cartId`, `existingOrderId`          | Scoped context                            |
| `customer`, `billingAddress`, `shippingAddress` | Normalized DTOs (no EGN)                  |
| `financingCalculation`                          | Authoritative `CalculationResult`         |
| `orderDraft`                                    | Server-side order draft                   |
| `selectionHash`, `cartFingerprint`              | Selection integrity                       |
| `shopUnicid`, `shopSnapshotFetchedAt`           | Shop configuration identity               |
| `submissionSource`                              | Diagnostic source tag                     |

Supporting DTOs: `FinancingCustomerData`, `FinancingAddressData`, `OrderDraft`, `FinancingAttemptContext`, `CreatedOpenCartOrder`.

## Gateway architecture

```text
ValidatedFinancingSubmission + FinancingAttemptContext
        ↓
OrderMaterializationService (lock + attempt states + attach-once)
        ↓
ProductOrderGateway ──┐
CartOrderGateway    ──┼→ OpenCartOrderMaterializer → CheckoutOrderModelPort.addOrder()
CheckoutExistingOrderGateway (reuse only, never addOrder)
```

| Gateway                        | Behavior                                                       |
| ------------------------------ | -------------------------------------------------------------- |
| `ProductOrderGateway`          | One-product draft → shared materializer                        |
| `CartOrderGateway`             | Multi-line cart draft → shared materializer                    |
| `CheckoutExistingOrderGateway` | Validates native `session.order_id` order; **no** `addOrder()` |

## Canonical payment identity

| Role               | Value                         |
| ------------------ | ----------------------------- |
| Extension code     | `mt_uni_credit`               |
| Payment code       | `mt_uni_credit`               |
| Stored option code | `mt_uni_credit.mt_uni_credit` |
| Display name       | `UniCredit`                   |

Centralized in `PaymentIdentity` / `ModuleConstants::PAYMENT_OPTION_CODE`.

## Order draft and addOrder mapping

`OrderDraft` holds authoritative server-side data. `OpenCartOrderDataBuilder` maps to native OpenCart 4.1.0.3 `addOrder()` structure (store, customer, addresses, payment/shipping JSON methods, products/options, totals, currency, IP metadata).

Product draft factory: `ProductOrderDraftFactory`  
Cart draft factory: `CartOrderDraftFactory`

## Status / history decision

| Flow         | Decision                                                                                                                                                                     |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Product/Cart | After materialization + attach, `addHistory(awaitingFinancingStatusId)` moves order off default status **0** so native checkout `editOrder(status=0)` cannot interfere later |
| Checkout     | Reuses native status-**0** order from `confirm.php`; gateway accepts status `0` or configured awaiting status                                                                |
| Setting key  | `module_mt_uni_credit_awaiting_financing_order_status_id` (admin wiring in later phase)                                                                                      |

No bank/final statuses, no customer notification in Phase 6.

## Attempt state flow (Phase 6 subset)

```text
issued → validating → order_creating → (materialize/recover) → attach order → order_created
```

Uses existing `FinancingAttemptRepository` CAS transitions and `attachOrder()` attach-once semantics.

## Crash recovery marker

**Mechanism:** OpenCart `oc_order.tracking` set atomically during `addOrder()` INSERT.

**Format:** `mtuc:s{storeId}:a{attemptId}` (≤64 chars, non-secret, module-scoped).

**Retry:** `OpenCartOrderMaterializer` queries by marker before calling `addOrder()` again.

**Why not comment:** customer-visible; **why not new table:** native field suffices and is written in same INSERT as order creation.

## Idempotency layers

1. Operation lock `(store_id, entry_point, operation_key_hash)`
2. Attempt `order_id` binding (attach-once UNIQUE)
3. Recovery marker lookup before second `addOrder()`
4. Checkout gateway never calls `addOrder()`

## Transactions

No giant transaction around OpenCart `addOrder()` internals (multi-write, not safely wrappable). Safety comes from recovery marker + atomic attach + idempotent retry.

## Product cart preservation

Product materialization uses isolated `OrderDraft` data only — **no** live cart reads/writes in Phase 6 service layer. Cart cleanup is explicitly deferred.

## Tests

| Suite                                | Coverage                                                                                |
| ------------------------------------ | --------------------------------------------------------------------------------------- |
| `Phase6ValidatedSubmissionTest`      | DTO boundary, entry points, no EGN                                                      |
| `Phase6OrderMaterializationTest`     | Materialization, attach, crash recovery, idempotency (in-memory port + real attempt DB) |
| `Phase6OpenCartOrderIntegrationTest` | Real `oc_order` rows with `mtuc:` cleanup                                               |
| `Phase6ScopeGuardTest`               | No UI/CP/snapshot                                                                       |

Run:

```bash
MT_UNI_CREDIT_INTEGRATION=1 composer test:php84
composer lint:php82
```

## Explicitly out of scope

Product/Cart modals, payment controller, CP create, snapshot table, Process 1/2, SmartUCF, emails, inbound APIs, admin financing panel.
