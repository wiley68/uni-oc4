# Phase 9 — Checkout Payment Method

**Frozen baseline (start of Phase 9):**

```text
PHASE 7 PRODUCT: FINAL PASS
PHASE 8 CART: FINAL PASS
SHA: a561f2e4fcc51c40da8dc85b21b77e378995ad65
```

Phase 9 adds UniCredit as a native OpenCart **4.1.0.3** checkout payment method. It stops at `local_order_prepared` on the **existing** checkout order. No CP, Process 1/2, or SmartUCF.

## OpenCart 4.1 payment lifecycle (verified)

```text
checkout page
→ payment_method.getMethods  (no session.order_id)
→ payment_method.save        (session.payment_method = {code,name})
→ confirm.index
     → addOrder() when order_id absent (status 0)
     → editOrder() when status still 0 (void via history first — hazard)
     → load payment controller index()  ← session.order_id exists
→ #button-confirm → extension/{ext}/payment/{code}.confirm
→ checkout/success (clears cart + session order_id)
```

Payment discovery: `catalog/model/checkout/payment_method.php` loads `extension/{extension}/payment/{code}` and calls `getMethods($address)`.

Confirm route for UniCredit:

```text
extension/mt_uni_credit/payment/mt_uni_credit.confirm
```

## Payment identity

| Field             | Value                         |
| ----------------- | ----------------------------- |
| Extension package | `mt_uni_credit`               |
| Payment code      | `mt_uni_credit`               |
| Option code       | `mt_uni_credit.mt_uni_credit` |
| Settings group    | `payment_mt_uni_credit`       |

Stored order `payment_method` JSON must match `PaymentIdentity` / Phase 6.

## Eligibility (server-authoritative)

`CheckoutFinancingEligibility` via `MtUniCreditCheckout::isPaymentMethodEligible`:

- `module_mt_uni_credit_status` + `payment_mt_uni_credit_status`
- currency via `CurrencyGate`
- non-empty cart
- amount within shop min/max (`Calculator::isAvailableForAmount`)
- Phase 5 cart intersection yields standard and/or promo offer
- geo zone (native payment setting)
- no subscriptions

## Amount source

| Stage                           | Amount                                                  |
| ------------------------------- | ------------------------------------------------------- |
| `getMethods` eligibility        | live cart total (`cart->getTotal()` via `CartContext`)  |
| Payment panel / issue / confirm | **authoritative `order.total`** from `session.order_id` |

Scheme **intersection** always uses cart product lines (Phase 5 `type\|kopCode\|months`). Financing **calculation amount** at confirm is the order total (includes shipping/taxes applied by native checkout).

If cart lines or order total change after issue → `checkout_order_changed` / `stale_selection`.

## Existing-order boundary

```text
native confirm creates order N (status 0)
→ UniCredit panel binds to session.order_id = N
→ CheckoutFinancingSubmissionService
→ OrderMaterializationService (entry_point=checkout)
→ CheckoutExistingOrderGateway (never addOrder)
→ attempt attach + local_order_prepared
→ addHistory(payment_mt_uni_credit_order_status_id) when configured
→ redirect checkout/success
```

## Operation identity

```text
CheckoutOperationIdentity::hash(store_id, order_id)
```

Selection hash: `CheckoutSelectionHash` (order_id + order_total + fingerprint + scheme + authoritative first installment + actor).

## Customer / address source (Checkout)

Confirm does **not** trust Product-style popup POST for customer fields.

```text
session.order_id
→ order snapshot
→ CheckoutOrderCustomerAdapter
→ ProductCustomerValidator / address fields
→ FinancingCustomerData + FinancingAddressData
```

Mapping:

| Order column        | Financing field     |
| ------------------- | ------------------- |
| `payment_firstname` | firstname           |
| `payment_lastname`  | lastname            |
| `payment_address_1` | address / address_1 |
| `telephone`         | telephone (phone)   |
| `email`             | email               |

Guest `customer_id = 0` is valid. Logged-in ownership (`order.customer_id === session customer`) remains enforced in `resolveSessionOrder()`.

Consents still come from the financing panel POST (`consent[]` / `consent[n]`).

Confirm button wording: `Потвърди поръчката` / `Confirm order`.

## Stale / idempotency

- Scheme change → clear token + calculation + first installment
- Double confirm → bound attempt returns `local_order_prepared` replay
- Confirm fetch does not share AbortController with issue/calculate
- Dynamic checkout: `MutationObserver` + `data-mtuc-bound` idempotent init

## Error taxonomy

`checkout_order_missing`, `checkout_order_changed`, `unavailable_scheme`, `stale_selection`, `invalid_customer`, `invalid_consent`, `invalid_csrf` (403), `technical_failure`, …

## Install / ops

1. Admin → Extensions → Payments → install/enable **UniCredit** (`mt_uni_credit`)
2. Re-open/save **module** `mt_uni_credit` so EventRegistry syncs checkout success event
3. Configure order status + sort order + geo zone

## Deferred (Phase 10+)

CP order create, Process 1/2, SmartUCF, bank redirect/callback.
