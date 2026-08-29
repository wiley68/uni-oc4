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

## Existing order reuse

`CheckoutExistingOrderGateway` never calls `addOrder()`. It validates `session.order_id` payment identity (`mt_uni_credit.mt_uni_credit`) and status via `FinancingOrderStatusPolicy`.

Allowed checkout reuse statuses:

- `0` (fresh addOrder)
- awaiting-financing status (module setting, when configured)
- `config_void_status_id` — OpenCart 4.1 `editOrder()` voids the same row before rewriting; active checkout orders commonly sit here

Rejected examples: processing / complete (e.g. payment order status after success).

Retry: confirm may resume attempts left in `validating` / `order_creating` after a prior `technical_failure` (no duplicate order).

## Customer / address source (Checkout)

Confirm does **not** trust Product-style popup POST for customer fields.

OpenCart 4.1.0.3 timing: `checkout/confirm.index` may persist **empty** `payment_*` when `config_checkout_payment_address` is off, while `firstname`/`lastname`/`email` come from `session.customer` and the delivery address lives in `shipping_*` / `session.shipping_address`. `telephone` may also be empty on the order row at payment-controller time.

```text
session.order_id
→ order snapshot
→ session.customer / payment_address / shipping_address
→ optional verified Address::getAddress(customer_id, address_id) when logged-in + owned
→ CheckoutOrderCustomerAdapter::fromCheckoutContext()
→ CheckoutCustomerValidator (telephone optional)
→ FinancingCustomerData + FinancingAddressData
```

**Checkout required:** `firstname`, `lastname`, `address`, `email`.  
**Checkout optional:** primary `telephone` (native value or `''` — never fabricated).

Product/Cart continue to use `ProductCustomerValidator` where telephone remains **required**.

Precedence (first non-empty wins per field):

1. order `payment_*`
2. order `shipping_*` (name/address when payment address unused)
3. order customer columns (`firstname` / `lastname` / `email` / `telephone`)
4. `session.payment_address`
5. `session.shipping_address`
6. `session.customer`
7. verified owned address row (logged-in only; never `getAddress(0, …)`)

| Financing field      | Typical sources                                                        |
| -------------------- | ---------------------------------------------------------------------- |
| firstname / lastname | order payment/shipping/customer → session addresses/customer           |
| address              | order payment/shipping address_1 → session payment/shipping → verified |
| telephone            | order.telephone → session.customer → verified (optional; else `''`)    |
| email                | order.email → session.customer.email                                   |

Guest `customer_id = 0` is first-class. Logged-in ownership (`order.customer_id === session customer`) remains enforced in `resolveSessionOrder()`. Stale/malicious POST customer fields cannot override native Checkout identity (no customer inputs in payment twig — display-only summary + consents).

True missing **required** fields after all sources → `invalid_customer` + diagnostic `checkout_customer_missing_fields`. Missing telephone alone must **not** produce that error.

Consents still come from the financing panel POST (`consent[]` / `consent[n]`).

Confirm button wording: `Потвърди поръчката` / `Confirm order`.

### Frozen future contracts (not implemented in Phase 9)

**Process 2 Checkout UI** — only additional UniCredit panel fields allowed later:

```text
egn
phone2
```

Do **not** add firstname / lastname / primary telephone / email / address to the Checkout financing panel; those stay native OpenCart.

**Future CP create** (Phase 10+), when Checkout has no native primary telephone:

```text
customer.phone = ''
```

**Future SmartUCF Process 1** (same case):

```text
clientPhone = ''
```

Mirrors `wiley68/uni-oc4-old`. No placeholders (`N/A`, `000…`, `-`). Bank acceptance of empty phone must be re-verified against live CP/SmartUCF later.

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
