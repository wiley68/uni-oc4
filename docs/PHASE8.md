# Phase 8 — Cart Financing Flow

**Working baseline HEAD (start of Phase 8):** `b4141144491a5fe6e4a891b0dc644cfe05baa9d3`  
**Phase 7 Product FINAL PASS checkpoint:** `d7eefb96b7a9e1320ec9ec350f2a936627fa1cd4`  
**Phase 8 implementation complete (code on working tree; re-install/save module to sync EventRegistry cart events).**

Verification (this environment):

- PHP 8.2 `php -l` — clean on `system`/`catalog`/`admin`
- PHP 8.4 PHPUnit — **354 tests, 8687 assertions, OK**

Phase 8 adds the OpenCart 4.1 standard-theme **Cart** financing entry point. It stops at successful **local OpenCart order materialization** via Phase 6 — no CP, Checkout payment, Process 1/2, SmartUCF, emails, or callbacks.

## Runtime remediation — Product nested form / activeFormPresent=false (2026-08-29)

**Proven operands at `Изпрати` (no recovery POST, no product.submit):**

```text
initialHasContext=true
schemePresent=true
buttonPresent=true
activeFormPresent=false
finalHasContext=true
```

**Root cause:** `StandardThemeProductPlacement` inserted the calculator **inside** `#form-product`. HTML5 ignores nested `<form>` start tags → `#mt-uni-credit-product-form` never exists → `activeForm` null. Same customer message was used for scheme/context and DOM failures. Modal→body move is unrelated (form was never created).

**Fix (Product only; Cart untouched):** insert fragment after `</form>` of `#form-product`; live `activeProductFinancingForm()`; split DOM vs scheme/context customer messages.

**Evidence:** Operator click showed the scheme/calculation guard message; access log had **no** `product.submit` and **no** recovery `issueSubmission`. Previous recovery called `recalculateSelection()` which returned early on `calcBusy` / missing scheme without a boolean result — silent no-op.

**Fix (Product only):**

- `recalculateSelection({ force, abort })` → `Promise<boolean>`; single-flight `issueFlight`; requires `submission_token` from issue response.
- Submit recovery: `{ force: true, abort: false }` and abort POST if `recovered === false`.
- `isInsideUniCreditUi()` — popup/root controls never call `scheduleRefreshCalculator`.
- Active form resolved from modal at submit time.

## Runtime remediation — Product lost Step 2 context + Cart Address::getAddress (2026-08-29)

**Product:** Enabled `Изпрати` could still hit frontend guard (`!scheme || !lastCalculation`) after calculator rebuild cleared financing context while Step 2 stayed visible; readiness previously only required `lastCalculation` (not token/scheme). Fix: `hasAuthoritativeCalculation` requires calculation+token+scheme; `invalidateIssuedSelection` on rebuild; Step 2 refresh always re-issues; submit recovers once via `recalculateSelection` before POST.

**Cart `technical_failure`:** `ArgumentCountError` — OpenCart 4.1 `Account\Address::getAddress(int $customer_id, int $address_id)` called with one argument from Cart/Product address resolver closures. Fix: pass `(customerId, addressId)` in both catalog models.

## Runtime remediation — Product/Cart final submit (2026-08-29)

**Product no-action:** `Изпрати` relied only on form `submit`; shared `abortController` from Product refresh could abort the submit fetch; silent early-return when scheme/form/calculation missing; locked first installment used `disabled` (fragile for payload reads).

**Cart false stale rejection:** Operator copy matched `stale_selection` (“Избраните условия са променени…”), not `cart_changed`. `readSelectionContext` hashed raw POST `first_installment` (often `0` at issue) while submit sent the rendered mandatory amount → selection-hash mismatch with unchanged Cart.

**Fix:**

- Product + Cart: hash **authoritative** `calculation.first_installment` after scheme calculate.
- Product + Cart: explicit `[data-mtuc-submit]` click → `submitForm`; submit `postJson(..., { abort: false })`; non-silent guards; locked first installment = `readonly` only.
- Cart fingerprint: sorted categories/options, normalized money amounts.

## Runtime remediation — Cart offer A→B stale popup state (2026-08-29)

**Root cause:** Cart `openModal()` did not call Product’s `resetFirstInstallmentForSchemeChange()` (or equivalent). Closing offer A left DOM calculation values + `lastCalculation`/`submissionToken`; opening offer B briefly showed A until the new response arrived (and could reuse stale readiness).

**Fix:** Canonical `resetCartModalOfferState()` clears token, `lastCalculation`, first installment, all `[data-mtuc-display]` values, and Step 1 controls. Called on open (before recalc + processing), on close, on scheme change, and on cart invalidate. Offer click sets `selectedSchemeKey` from the clicked button only (no A key carryover).

## Runtime remediation — Cart unstyled / JS seeming absent (2026-08-29)

**Classification:** Case C — Cart before-controller registered fonts + product CSS + cart CSS/JS (`Cart assets event executed` in logs). Assets used `ModuleAssetVersion` like Product.

**Root cause:** Shared `mt_uni_credit_product.css` was scoped only to `#mt-uni-credit-product-root` / `#mt-uni-credit-product-modal`, while Cart Twig/JS use `#mt-uni-credit-cart-root` / `#mt-uni-credit-cart-modal`. CSS loaded but matched nothing → unstyled UI; behavior looked “broken”.

**Fix:** Dual-scope shared visual CSS with `:is(#mt-uni-credit-product-*, #mt-uni-credit-cart-*)`. Cart keeps thin layout CSS + footer JS.

## Runtime remediation — Cart calculator not visible (2026-08-29)

**Root cause:** `EventRegistry` defined cart hooks, but `oc_event` only had Product events. Cart `before`/`after` callbacks never ran (Admin Save not executed after Phase 8 deploy).

**Evidence:** Live `oc_event` before fix had Jet cart events + UniCredit product events only. Product-40 CartContext against store_id=0 shop cache yields standard+promo offers; placement after `#shopping-cart` works. Domain was not the failure.

**Fix:** Inserted cart events into `oc_event`; added `ensureEventsSynchronized()` on Admin module `index()` so future EventRegistry gaps self-heal without relying solely on Save.

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
