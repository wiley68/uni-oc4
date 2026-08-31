# Phase 11C — Homepage Advertising Block + Popup Parity

**Status:** implemented (OC4 storefront homepage only).

## Scope

- Homepage floating UniCredit advertising trigger (left side) + desktop popup panel.
- Cache-only CP shop configuration (`ShopConfigurationService::getCachedOnly()`).
- Local operator toggle: `module_mt_uni_credit_advertising_enabled`.
- Parity reference: PS9 → PS8 → Woo (`HomepageAdvertisingGate` / `HomepageAdvertisingPresenter`).

Out of scope: Product/Cart/Checkout financing lifecycle, Process 1/2, CP order creation, emails, admin orders.

## Visibility rule

Show advertising **only when all** are true:

1. Route is homepage (`common/home` or default empty route).
2. Module enabled (`module_mt_uni_credit_status`).
3. Local advertising enabled (`module_mt_uni_credit_advertising_enabled`).
4. UNICID configured for `store_id`.
5. Cached CP shop has `uni_status` **and** `uni_container_status` yes-flags.

Fail closed when cache missing/stale or CP flags off.

## CP payload fields (existing contract)

| Field                  | Use                                    |
| ---------------------- | -------------------------------------- |
| `uni_status`           | Shop active gate                       |
| `uni_container_status` | Advertising container gate             |
| `uni_picturem`         | Panel image + mobile float image (CDN) |
| `uni_backurl`          | External CTA / mobile direct open      |
| `uni_container_txt1`   | Panel title (text-only)                |
| `uni_container_txt2`   | Panel body (text-only)                 |

## OpenCart integration

| Trigger                                 | Controller                                  | Purpose                                        |
| --------------------------------------- | ------------------------------------------- | ---------------------------------------------- |
| `catalog/controller/common/home/before` | `mt_uni_credit_home_controller::beforeHome` | Fonts + scoped CSS/JS (`filemtime` cache bust) |
| `catalog/view/common/footer/after`      | `mt_uni_credit_home_view::afterFooter`      | Inject Twig fragment (homepage requests only)  |

No OpenCart core template edits.

## Assets

- `catalog/view/stylesheet/mt_uni_credit_homepage_advertising.css`
- `catalog/view/javascript/mt_uni_credit_homepage_advertising.js`
- `catalog/view/template/module/mt_uni_credit_homepage_advertising.twig`
- Local Roboto Condensed via existing `mt_uni_credit_fonts.css`

## Tests

`tests/Phase11CHomepageAdvertisingTest.php` — gate matrix, presenter URL safety, homepage-only routes, event registration, asset cache busting, modal ID isolation, cache refresh, multistore resolver.

## Operator gate

1. Local advertising OFF → no trigger on homepage.
2. Local ON + valid CP advertising → left float + popup (desktop) / direct external open (mobile).
3. CP shop-cache refresh → next homepage load shows updated content.

Module version remains **2.0.2**.

## Checkout Process 2 loader (Remediation 02)

Checkout payment used incomplete `[data-mtuc-processing]` markup (title/text only). CSS for panel/spinner existed in `mt_uni_credit_checkout.css` but did not apply.

**Fix:** shared processing panel + spinner markup (cart modal parity), viewport-fixed overlay (`z-index: 100050`, dimmed backdrop), `setProcessing()` toggles `mt-uni-credit-checkout--processing` + scroll lock. Loader stays on until Thank You navigation (`redirectTerminal` guard unchanged).

## Checkout no-shipping address (Remediation 03)

**Problem:** Logged customer + virtual/no-shipping cart skips native shipping/payment address steps; UniCredit submit failed `invalid_customer` (missing address) after local order creation; CP not created.

**Root cause:** `verifiedOwnedAddressForOrder()` required explicit `payment_address_id` / `shipping_address_id` on order or session. Native OC4 confirm leaves both at 0 when `!cart->hasShipping()` and `config_checkout_payment_address` is off.

**Fix:**

- `verifiedOwnedAddressForOrder()` falls back to default/first owned address book row via `defaultOwnedAddressId()` (same source as `customerPrefillFromSession()`).
- No fake shipping; product shipping flag unchanged.
- `CheckoutFinancingSubmissionService` uses `ShippingMethodSnapshot::empty()` for no-shipping orders (Admin `cost` / `tax_class_id` parity).

**Tests:** `tests/Phase11CNoShippingCheckoutTest.php`

## SmartUCF definite failure → Thank You (Remediation 04)

**Problem:** Process 1 definite SmartUCF rejection left Product/Cart/Checkout popup open with in-modal error and retry.

**Reference:** PS9 checkout + Woo redirect to Thank You with failure presentation; `bank_send_failed_smartucf`.

**Fix:**

- `PostControlPanelLifecycleService` returns `step=smartucf_terminal_failed` + Thank You URL for non-retryable `remote_reject` only.
- `FinancingTerminalNavigationSupport` stashes `mt_uni_credit_success_order_id` for Product/Cart/Checkout.
- Shared `MtUniCreditRedirect.navigateTerminalThankYou()` — loader stays on until navigation.
- `FinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE` on Thank You (PS9 tpl wording).

Retryable pre-send, outcome unknown, and CP failures remain interactive.

**Tests:** `tests/Phase11CSmartUcfTerminalFailureTest.php`

Module version remains **2.0.2**.

## SmartUCF debug journal (Remediation 05)

**Problem:** Debug mode ON + real Process 1 SmartUCF activity produced no durable diagnostics — Admin „Изтегли журнал операции“ was disabled; CP diagnostic API returned not-found.

**Root cause:** Bridge A read path existed (`DiagnosticDebugLogRepository::findLatestByOrderId`, CP `smartucf_debug_log`) but no writer populated `mt_uni_credit_diagnostic_debug_log`; Admin UI was a placeholder.

**Fix:**

- `SmartUcfDiagnosticJournal` + `DiagnosticDebugLogRepository::insert()` gated by `module_mt_uni_credit_debug_enabled`.
- Capture wired in `SmartUcfSessionCoordinator` on success/failure (actual `raw_request` / `raw_response` from `SmartUcfSessionClient`).
- Expanded `DiagnosticPayloadRedactor` (PS9 key parity + Bearer regex).
- Admin `downloadJournal` POST export (`unipayment-smartucf-log-{timestamp}.json`), button enabled (PS9 parity).
- Retention: 3 months prune on read/write.

**Tests:** `tests/Phase11CSmartUcfDebugJournalTest.php`

Module version remains **2.0.2**.

## Checkout Process 1 success → Thank You (Remediation 06 — superseded)

Remediation 06 incorrectly sent successful Checkout P1 to local Thank You. **Remediation 06A** restores the frozen contract: Checkout P1 success → trusted SmartUCF bank redirect (Product/Cart parity).

**Original UI bug (still fixed):** Checkout JS rendered the success banner before attempting `navigateIfTrusted`, producing mixed messages when navigation failed.

## Checkout Process 1 success → SmartUCF bank redirect (Remediation 06A)

**Problem:** Remediation 06 replaced bank `redirect_url` with `checkout/success` for Checkout P1 success — wrong terminal target.

**Correct contract:**

```text
Checkout P1 SUCCESS  → trusted SmartUCF/bank URL (navigateIfTrusted)
Checkout P1 FAILURE  → local Thank You (Remediation 04)
Checkout P2 SUCCESS  → local Thank You
```

**Fix:**

- Removed Checkout-only Thank You enrichment (`isCheckoutProcess1Success`, `checkoutProcess1ToThankYou`).
- Checkout JS aligned with Product/Cart: `navigateIfTrusted` **before** success banner; shared `mt_uni_credit_redirect.js` validator.

**Tests:** `tests/Phase11CCheckoutProcess1SuccessTest.php`

Module version remains **2.0.2**.

## Checkout P1 definite SmartUCF reject — local/CP parity (Remediation 07)

**Problem:** SmartUCF business reject (`errorCode=121`, `sucfOnlineSessionID=null`, text with „съществува“) was classified as ambiguous `duplicate_order_no` / `outcome_unknown`. Local bank status could remain wrong / not `bank_send_failed_smartucf`; customer stayed on Checkout instead of terminal Thank You. CP already showed failure.

**Root cause:** `looksLikeDuplicate()` / `detectFailureKind()` treated „съществува“ wording as ambiguous duplicate **even when** a structured `errorCode` proved a conclusive business rejection (including HTTP 200).

**Fix:**

- Structured `errorCode` (non-null) + no session → `remote_reject` / FAILED (not `outcome_unknown`).
- `bank_send_failed_smartucf` local + CP; terminal Thank You (Remediation 04 path).
- Checkout: non-success non-terminal results no longer call `addHistory` or invent Thank You `redirect`.

**Tests:** `tests/Phase11CSmartUcfBusinessRejectTest.php`

Module version remains **2.0.2**.

## Checkout P1 definite reject — visible commerce + Thank You (Remediation 08)

**Problem:** Logged Checkout Process 1 + SmartUCF definite reject left the customer on Checkout with the safe failure message, while the local OpenCart order stayed **Voided / Пропуснати поръчки** (`order_status_id` from `editOrder` void). Bank status and CP were already correct (`bank_send_failed_smartucf`).

**Root causes:**

1. **Commerce Voided:** Native `editOrder()` voids the draft. Remediation 07 returned terminal failure **before** `addHistory(payment_mt_uni_credit_order_status_id)`, so the order never left Voided. Product/Cart reject already had visible status from materialization `ensureInterimVisibleStatus`.
2. **Stay on Checkout:** Payment HTML is AJAX-injected and only bootstrapped `mt_uni_credit_checkout.js` — **`mt_uni_credit_redirect.js` was not loaded**. P1 success still navigated via `json.redirect` fallback; terminal reject relied solely on `MtUniCreditRedirect.navigateTerminalThankYou` → fell through to inline error.

**Fix:**

- Terminal reject: `applyCheckoutUniCreditOrderStatus()` → same `payment_mt_uni_credit_order_status_id` as success / Product/Cart interim (not payment-complete semantics).
- Enrich Thank You `redirect_url` + OC-style `redirect`; stash `order_id` / `mt_uni_credit_success_order_id`.
- Bootstrap loads `redirect.js` before `checkout.js`; JS `navigateCheckoutTerminalThankYou` before banners / `setProcessing(false)`, with local `checkout/success` fallback.
- Stale-cart void path (`CheckoutSessionOrderGuard`) unchanged.

**Tests:** `tests/Phase11CCheckoutProcess1RejectTest.php`

Module version remains **2.0.2**.

## Product popup „Купи“ → native cart → Checkout handoff (Remediation 09)

**Problem:** With `Бутон купи = Купи`, Product popup secondary action only did `location = checkout/checkout` — **no** `checkout/cart.add`. Empty cart → Checkout bounced to empty Cart.

**Root cause:** Buy path skipped native OpenCart cart API; no payment/scheme preference store.

**Fix (PS9/Woo parity, native OC4 cart):**

1. Buy → `#button-cart` → `checkout/cart.add` (same product/qty/options as native Add to Cart).
2. On success → `stashBuyPreference` session handoff (`mt_uni_credit_product_buy_preference`: scheme_type/kop/months/filter_id + prefer_payment).
3. Redirect → `checkout/checkout` (not cart).
4. Checkout: UniCredit payment preferred when available; scheme preselected if still valid against cart offers (promo≠standard by months alone).
5. Cleanup: other payment save, Thank You, store/TTL mismatch; never force unavailable payment/scheme.
6. No local order / CP / SmartUCF / bank status on Buy.

**Tests:** `tests/Phase11CProductBuyCheckoutHandoffTest.php`

Module version remains **2.0.2**.

## Product Buy exact scheme preselection (Remediation 09A)

**Problem:** After Rem 09, Product „Купи“ correctly added to cart, opened Checkout, and preselected UniCredit payment — but Checkout still showed the **automatic PreferredOffer default** instead of the Product-selected scheme (e.g. 12m / promo).

**Root causes:**

1. Preference stored component identity without durable `scheme_key`; brittle matching could miss Checkout offer rows (kop encoding / type casing / filter coercion).
2. Only `offers.standard.preferred_scheme_key` was overwritten — promo matches were weaker in JS fallback paths.
3. No user-override signal — re-renders could re-assert Product preference after a manual scheme change.

**Fix:**

- Canonical matcher `FinancingSchemeIdentity` (key-first, then type+kop+months, prefer filter_id; normalize type/kop/filter/months).
- `stashBuyPreference` persists Product `scheme.key` + normalized components.
- Checkout presenter sets `buy_preference_scheme_key` and both offer buckets' `preferred_scheme_key` on successful match; marks `scheme_matched` without clearing.
- Checkout scheme `<select>` change → `markBuySchemeOverride` (stop re-forcing).
- Invalid / missing offer → normal PreferredOffer default (unchanged).

**Tests:** `tests/Phase11CProductBuySchemePreselectTest.php`

Module version remains **2.0.2**.

## Product Buy scheme runtime trace + server-selected Checkout fix (Remediation 09B)

**Problem:** Rem 09A matcher/tests passed, but live Checkout still showed PreferredOffer default after Product „Купи“ with a non-default scheme.

**Proven category:** **E** — correct Product preference reached the presenter/JSON, but Checkout JS rebuilt an empty `<select>` and `resolvePreferredSchemeKey()` treated `offers.standard.preferred_scheme_key` (default) as equivalent to Product Buy when `buy_preference_scheme_key` was absent from the first init path; fallback to `schemes[0]` then locked the default before async init completed on AJAX-injected payment panels.

**Fix:**

- Single PHP precedence helper `ProductBuyCheckoutPreference::resolveInitialSchemeSelection()` (override → Product Buy → default).
- Server-render `<option selected>` in Checkout Twig from `buildCheckoutSchemeOptions()`; `markSchemeMatched` only when Product Buy key is server-selected (visible before JS).
- Checkout JS `resolveInitialSchemeKey()` + `readServerSelectedSchemeKey()` — Product Buy key is never conflated with default `preferred_scheme_key`.
- Product Buy stash reads `syncSelectedSchemeFromDom()`; calculator refresh preserves a valid user-selected scheme key.

**Tests:** `tests/Phase11CProductBuySchemeCheckoutIntegrationTest.php` (+ updated Rem 09A contract tests)

Module version remains **2.0.2**.
