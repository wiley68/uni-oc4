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
| 7 Product          | `local_order_prepared` (frozen Product UI)            |
| 8 Cart             | `local_order_prepared` (frozen Cart UI)               |
| 9 Checkout payment | `local_order_prepared` on existing `session.order_id` |
| Later              | CP create, Process 1/2, SmartUCF, bank callbacks      |

## Phase 9 Checkout payment path

```text
native checkout
→ payment getMethods (CheckoutFinancingEligibility)
→ payment save (mt_uni_credit.mt_uni_credit)
→ confirm.index → session.order_id (addOrder once)
→ payment/mt_uni_credit index (scheme UI)
→ issueSubmission / confirm
→ CheckoutFinancingSubmissionService
→ CheckoutExistingOrderGateway (reuse only)
→ local_order_prepared → checkout/success
```

Authoritative financing amount at confirm = **order.total**. Eligibility before order uses cart total. Shared Phase 5 intersection unchanged.

Checkout customer: `CheckoutCustomerValidator` — primary telephone **optional** (`''` when native OC has none). Product/Cart keep required telephone via `ProductCustomerValidator`.

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
