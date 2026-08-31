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

## Checkout Process 1 success → Thank You (Remediation 06)

**Problem:** Checkout Process 1 SmartUCF success left the customer on Checkout showing both:

- generic error „Заявката не може да бъде обработена.“
- green success „Поръчката е изпратена към системата за финансиране.“

Bank/CP state was already `bank_sent_process1`.

**Root cause:** Checkout returned SmartUCF bank `redirect_url`. JS showed the success banner first, then failed `navigateIfTrusted` and rendered the generic error — no Thank You navigation.

**Fix:**

- Checkout-only enrichment replaces bank URL with local `checkout/success` (`terminal`, `continuation=thank_you`).
- Product/Cart keep bank application redirect unchanged.
- Checkout JS navigates terminal Thank You **before** any in-page success/error UI; loader stays on via `redirectTerminal`.

**Tests:** `tests/Phase11CCheckoutProcess1SuccessTest.php`

Module version remains **2.0.2**.
