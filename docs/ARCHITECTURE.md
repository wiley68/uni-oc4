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
| 10B CP lifecycle   | CP create/recover → `cp_created` (no SmartUCF)        |
| Later (11+)        | SmartUCF / Process execution / bank redirect          |

## Unified local boundary (Phase 10A)

```text
Product → addOrder() → correlation → attempt order_created → local_order_prepared
Cart    → addOrder() → correlation → attempt order_created → local_order_prepared
Checkout → reuse session.order_id → correlation → local_order_prepared
```

Product/Cart post-materialization status: UniCredit payment method `payment_mt_uni_credit_order_status_id` („Състояние на поръчката“) via `FinancingOrderStatusPolicy` + `addHistory()`. Checkout keeps native confirm status. See `docs/PHASE10A.md` and `docs/RECOVERY.md`.

## Phase 10B CP boundary

```text
local_order_prepared (order_created)
→ ControlPanelOrderLifecycleService
→ frozen cp_payload → POST /orders (idempotent)
→ control_panel_order_id + cp_created
```

Shared builder/lifecycle for Product, Cart, Checkout. See `docs/PHASE10B.md`.

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

**OC4.1 status hazard:** `ModelCheckoutOrder::editOrder()` always `addHistory(config_void_status_id)` first. Active `session.order_id` therefore often has void status while payment UI runs. `FinancingOrderStatusPolicy::isCheckoutReuseAllowedStatus()` accepts 0 and configured void — not processing/complete. Checkout model wires productCart status as `0` so payment Processing is not reuse-eligible.

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
