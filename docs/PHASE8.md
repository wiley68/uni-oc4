# Phase 8 — Cart Financing Flow

**Working baseline HEAD (start of Phase 8):** `b4141144491a5fe6e4a891b0dc644cfe05baa9d3`  
**Phase 7 Product FINAL PASS checkpoint:** `d7eefb96b7a9e1320ec9ec350f2a936627fa1cd4`  
**Phase 8 implementation complete (code on working tree; re-install/save module to sync EventRegistry cart events).**

Verification (this environment):

- PHP 8.2 `php -l` — clean on `system`/`catalog`/`admin`
- PHP 8.4 PHPUnit — **354 tests, 8687 assertions, OK**

Phase 8 adds the OpenCart 4.1 standard-theme **Cart** financing entry point. It stops at successful **local OpenCart order materialization** via Phase 6 — no CP, Checkout payment, Process 1/2, SmartUCF, emails, or callbacks.

## Manual browser matrix (operator)

1. one eligible Cart product
2. standard/promo offers
3. multi-product eligible Cart
4. partial/no scheme intersection
5. quantity increase
6. quantity decrease
7. remove product
8. Cart total update
9. calculator updates automatically
10. popup open
11. Step 1 values
12. scheme switch
13. first-installment reset
14. Step 2 fields
15. consent/readiness
16. submit
17. exactly one local order
18. correct order lines/options/qty
19. double submit
20. stale Cart state
21. empty Cart
22. Escape/Cancel
23. mobile/narrow viewport
24. console clean
25. PHP/OpenCart logs clean

## Cart parity inventory

| Concern               | Woo                                        | PS8/PS9                                | Jet OC4                                 | UniCredit OC4 target                                                               |
| --------------------- | ------------------------------------------ | -------------------------------------- | --------------------------------------- | ---------------------------------------------------------------------------------- |
| Calculator placement  | Before proceed-to-checkout / blocks submit | Shopping cart hook                     | After `#accordion`…`<br/>` in cart list | After `#shopping-cart` (survives `cart.list` AJAX) + Jet-style fallback            |
| Cart amount source    | `get_total('edit')` payable                | `getOrderTotal(BOTH)`                  | `$cart->getTotal()`                     | `$this->cart->getTotal()` (tax-inc merchandise; shipping often unset on cart page) |
| Standard/promo offers | Intersection by type\|kop\|months          | Same + CartSchemeResolver              | N/A (Jet finance)                       | Phase 5 `CartSchemeResolver`                                                       |
| Dynamic cart refresh  | WC fragments / updated_cart_totals         | `updatedCart` + AbortController        | Form submit delay / page reload         | Listen `#shopping-cart` AJAX + sequence/AbortController                            |
| Popup Step 1          | Shared product popup, hide add-to-cart     | Shared product modal, `hide-secondary` | N/A                                     | Reuse Product Step 1 design; secondary hidden                                      |
| Popup Step 2          | first/last/address/phone/email/consent     | Same                                   | N/A                                     | Same fields; no EGN/phone2                                                         |
| Secondary action      | Hidden on cart                             | Hidden                                 | N/A                                     | Hidden (`data-hide-secondary`)                                                     |
| Local order behavior  | Empty cart only after bank accept          | `validateOrder` consumes cart          | N/A                                     | **Preserve live cart** until CP phase (`cart_unchanged`)                           |

## Lifecycle

```text
Cart page eligible
→ calculate (CartContext + intersection)
→ issueSubmission (entry_point=cart)
→ Step 2 customer/consents
→ submit → local_order_prepared
```

## Operation identity

```text
CartOperationIdentity::hash(store_id, currency, cart_fingerprint)
cart_fingerprint = sha256(sorted lines[product_id, options, qty, line_total] + cart_total + currency)
```

Lock: `(store_id, entry_point=cart, operation_key_hash)`.

## Endpoints

| Action          | Route                                                               |
| --------------- | ------------------------------------------------------------------- |
| calculate       | `extension/mt_uni_credit/module/mt_uni_credit_cart.calculate`       |
| issueSubmission | `extension/mt_uni_credit/module/mt_uni_credit_cart.issueSubmission` |
| submit          | `extension/mt_uni_credit/module/mt_uni_credit_cart.submit`          |

## Events

| Trigger                                   | Controller                                 |
| ----------------------------------------- | ------------------------------------------ |
| `catalog/controller/checkout/cart/before` | `event/mt_uni_credit_cart_controller.init` |
| `catalog/view/checkout/cart/after`        | `event/mt_uni_credit_cart_view.init`       |

## Cart preservation decision

Woo empties the cart only after successful bank submission. Phase 8 stops at `local_order_prepared`, so the live OpenCart cart is **left unchanged** (same customer-safe stance as Product). Clearing after CP/bank belongs to a later phase.

## Explicitly out of scope

Checkout payment method, CP create, Process 1/2, SmartUCF, EGN/second phone UI, bank redirects, admin financing journal.
