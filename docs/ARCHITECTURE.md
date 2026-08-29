# Architecture — UniCredit OpenCart 4.x (`mt_uni_credit`)

Peer financing entry points:

```text
Product  →  Cart  →  Checkout
```

Each entry point eventually produces:

```text
validated context → usable local OC order → durable attempt/snapshot → CP lifecycle
```

## Current freeze boundaries

| Phase              | Boundary                                              |
| ------------------ | ----------------------------------------------------- |
| 7 Product          | UI + submit to local order path                       |
| 8 Cart             | UI + submit to local order path                       |
| 9 Checkout payment | `local_order_prepared` on existing `session.order_id` |
| 10A Local parity   | Product/Cart visible local orders (`addHistory`)      |
| Later (10B+)       | CP create, Process 1/2, SmartUCF, bank callbacks      |

## Unified local boundary (Phase 10A)

```text
Product → addOrder() → correlation → attempt order_created → local_order_prepared
Cart    → addOrder() → correlation → attempt order_created → local_order_prepared
Checkout → reuse session.order_id → correlation → local_order_prepared
```

Product/Cart post-materialization status: `FinancingOrderStatusPolicy` awaiting id (module setting, else payment order status). See `docs/PHASE10A.md` and `docs/RECOVERY.md`.

## Phase 9 Checkout payment path

```text
native checkout
→ payment getMethods (CheckoutFinancingEligibility)
→ payment save (mt_uni_credit.mt_uni_credit)
→ confirm.index → session.order_id (addOrder once)
→ payment/mt_uni_credit index (scheme UI)
→ issueSubmission / confirm
→ CheckoutFinancingSubmissionService
→ CheckoutExistingOrderGateway (reuse only; status 0, awaiting, or OC4.1 void after editOrder)
→ local_order_prepared → checkout/success
```

Authoritative financing amount at confirm = **order.total**. Eligibility before order uses cart total. Shared Phase 5 intersection unchanged.

Checkout customer: `CheckoutCustomerValidator` — primary telephone **optional** (`''` when native OC has none). Product/Cart keep required telephone via `ProductCustomerValidator`.

**OC4.1 status hazard:** `ModelCheckoutOrder::editOrder()` always `addHistory(config_void_status_id)` first. Active `session.order_id` therefore often has void status while payment UI runs. `FinancingOrderStatusPolicy::isCheckoutReuseAllowedStatus()` accepts 0, awaiting-financing, and configured void — not processing/complete.

Details: `docs/PHASE9.md`.

## Phase 8 Cart path

```text
checkout/cart page
→ EventRegistry cart assets + placement
→ CartContext from live cart (authoritative total)
→ CartSchemeResolver intersection
→ calculate / issueSubmission / submit
→ CartFinancingSubmissionService
→ OrderMaterializationService (entry_point=cart)
→ local_order_prepared
```

Live OpenCart cart is **not** cleared at Phase 8 (`cart_unchanged`).

Shared layers reused: Phase 5 calculator/CartContext/resolver; Phase 6 materialization/locks/correlation; Phase 7 Product visual system (CSS/modal patterns) and storefront CSRF.
