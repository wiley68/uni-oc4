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

| Phase     | Boundary                                                           |
| --------- | ------------------------------------------------------------------ |
| 7 Product | `local_order_prepared` (frozen Product UI)                         |
| 8 Cart    | `local_order_prepared` (this phase)                                |
| Later     | CP create, Process 1/2, SmartUCF, bank callbacks, Checkout payment |

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
