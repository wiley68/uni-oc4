# Phase 7 — Product calculator and financing modal (local order boundary)

Phase 7 adds the OpenCart 4.1 standard-theme **Product** financing flow only. It stops at successful **local OpenCart order materialization** via Phase 6 — no CP, Cart, Checkout payment, SmartUCF, snapshot, email, or callbacks.

## Runtime remediation — Product `Изпрати` no-action (2026-08-29)

See Phase 8 doc § “Product/Cart final submit”. Product submit now binds `[data-mtuc-submit]` click, isolates submit fetch from refresh abort, and uses authoritative calculated first installment in selection hash (shared with Cart).

## Placement

| Piece             | Route / file                                                                                |
| ----------------- | ------------------------------------------------------------------------------------------- |
| Assets event      | `catalog/controller/product/product/before` → `event/mt_uni_credit_product_controller.init` |
| View injection    | `catalog/view/product/product/after` → `event/mt_uni_credit_product_view.init`              |
| Placement adapter | `StandardThemeProductPlacement` — inserts after `#button-cart` block (jet-oc4 hook parity)  |

Events are registered through `EventRegistry` on install/save (`syncEvents()`).

### OpenCart 4.1.0.3 callback signatures

| Trigger family    | DB trigger example                          | Arguments supplied by loader                    |
| ----------------- | ------------------------------------------- | ----------------------------------------------- |
| controller before | `catalog/controller/product/product/before` | `string &$route, array &$args`                  |
| view after        | `catalog/view/product/product/after`        | `string &$route, array &$data, string &$output` |

`MtUniCreditProductController::init` must use the **2-argument** controller-before contract (no `$output`). Covered by `Phase7EventCallbackContractTest`.

## AJAX routes (catalog)

| Action       | Route                                                                  | Creates attempt?              |
| ------------ | ---------------------------------------------------------------------- | ----------------------------- |
| Recalculate  | `extension/mt_uni_credit/module/mt_uni_credit_product.calculate`       | **No**                        |
| Issue token  | `extension/mt_uni_credit/module/mt_uni_credit_product.issueSubmission` | **Yes** (Phase 3)             |
| Final submit | `extension/mt_uni_credit/module/mt_uni_credit_product.submit`          | Uses issued attempt → Phase 6 |

All routes: `POST` + module-owned storefront CSRF (`mt_uni_credit_csrf_token` via `ProductStorefrontCsrf`), JSON responses, bounded validation, no stack traces. Product AJAX URLs use `url->link(..., true)` so JavaScript receives raw `&`, not HTML `&amp;`.

## Authoritative ProductContext

- `OpenCartCatalogProductResolver` / `OpenCartProductContextFactory`
- Reloads product from catalog model (special/discount/base, tax, currency conversion, options)
- Does **not** trust client price/installments
- `ProductOptionNormalizer` validates option ids from POST

## Calculator presentation

- `ProductCalculatorPresenter` + shared Phase 5 `Calculator`
- Scheme ordering via `SchemePresentationCategory` (server authoritative)
- Preferred offer + standard/promo/0% flags in JSON for Twig/JS display only

## Modal UX

- `ProductModalPresenter` — banner, customer prefill, consents from shop snapshot
- `ProductCustomerValidator` / `ProductAddressValidator` / `OpenCartCatalogAddressResolver`
- EGN **not** in `FinancingCustomerData`
- Guest + logged-in flows; owned address ids verified server-side

## Attempt issuance (Phase 3)

- `entry_point = product`
- 64-hex `submission_token` (`SubmissionTokenGenerator`)
- `ProductActorBinding` — domain-separated SHA-256 (customer id or session fingerprint)
- `ProductOperationIdentity` — product/options/qty/currency
- `ProductSelectionHash` — financing amount, scheme, months, first installment, actor binding
- `ProductSubmissionIssuer` — issue or reuse active `issued` attempt

## Final validation → Phase 6

`ProductFinancingSubmissionService`:

1. Resolve attempt by token + store
2. Verify actor binding + expiry
3. Rebuild `OpenCartProductLine` + calculator
4. Verify `selection_hash`
5. Validate customer/address/consents
6. CAS `issued → validating`
7. Build `ValidatedFinancingSubmission` + `OrderDraft`
8. `OrderMaterializationService.materializeAndBind()`
9. Return `ProductFinancingResult` (`local_order_prepared`, `bank_submitted: false`)

## Stale / tamper handling

Mismatch on price, options, quantity, scheme, or selection hash → `409 stale_selection` **before** `addOrder()`.

## Active cart preservation

Product flow never calls `$this->cart->add()` / `clear()`. Submit response includes `cart_unchanged` contract; covered by `Phase7ActiveCartTest`.

## Store scope

Product attempt issuance, selection/operation hashes, locks, materialization, and correlation use explicit `config_store_id`. OpenCart default store **`store_id = 0`** is valid (`OpenCartStoreScope`). Covered by `Phase7AttemptAndSubmitTest::testDefaultStoreZeroIssueLockAndMaterialization`.

## Accessibility

Modal: `role="dialog"`, `aria-modal`, labelled title, focus into panel, Tab trap, Escape dismiss, focus return, `aria-busy` on calculator/submit, `inert`/`aria-hidden` on background (JS).

## Explicitly out of scope (Phase 7)

Cart calculator/UI, checkout payment, CP order create, Process 1/2, SmartUCF, financing snapshot table, emails, inbound callbacks, admin order financing panel.

## Manual OC 4.1.0.3 matrix

Admin first: native OpenCart **Save** → **Обнови данните от банката** (auth is transparent; no Login/Logout buttons; SmartUCF certs are not required for CP `/shop`). Then on staging storefront: simple/special/option products, qty changes, guest/logged customer, standard/promo/0%, preferred offer, stale option/price, keyboard-only modal, narrow viewport, unrelated active cart, double submit, refresh after local order — verify single order, correct lines/totals, cart unchanged, clean PHP/browser logs.

## STOP gate

Phase 7 passes when Product calculator/modal works on standard theme, authoritative server pricing, attempt/submit validation, exactly one local order with replay, cart untouched, result does not claim bank submission, scope guards green, PHP 8.2 lint + PHP 8.4 PHPUnit (Phase 0–7) pass.

## UX parity inventory (PS9 / PS8 / Woo → OC4)

Reference audited: PS9 `unipayment` (authoritative), PS8 `unipayment`, Woo `mtunicredit`.

| UX item                          | PS9                                                                                       | PS8  | Woo             | OC4 target                                                 |
| -------------------------------- | ----------------------------------------------------------------------------------------- | ---- | --------------- | ---------------------------------------------------------- |
| Product calculator/button layout | Pill „Купи на изплащане“ + price + logo/0%                                                | Same | Same (`mtuc-*`) | `mt-uni-credit-product-calculator__button` — same contract |
| Standard button                  | Offer pill opens modal                                                                    | Same | Same            | standard offer pill                                        |
| Promo button                     | Offer pill + 0% badge                                                                     | Same | Same            | promo offer pill + 0%                                      |
| Zero-interest button             | Promo type with 0% badge                                                                  | Same | Same            | promo offer                                                |
| Popup fields                     | Step1 calc + Step2 customer                                                               | Same | Same            | Step1 calc + Step2 customer                                |
| Field order                      | price, months, first, financed, monthly, total, GLP, GPR → first/last/address/phone/email | Same | Same            | Same order                                                 |
| Required fields                  | 5 customer + consents                                                                     | Same | Same            | 5 customer + consents (no EGN)                             |
| Address handling                 | Single address line in UI                                                                 | Same | Same            | Single `address`; mapped server-side                       |
| Consents                         | CP snapshot, mandatory checkboxes                                                         | Same | Same            | `ConsentResolver`                                          |
| Primary button                   | Step1: Кандидатствай → Step2; Step2: Изпрати                                              | Same | Same            | `data-mtuc-apply` / `data-mtuc-submit`                     |
| Secondary button                 | add_to_cart / buy via `product_button_action`                                             | Same | Same            | `data-mtuc-secondary`                                      |
| Close behavior                   | Отказ, Escape, overlay                                                                    | Same | Same            | `data-mtuc-dismiss`, Escape                                |
| Validation feedback              | Inline field errors                                                                       | Same | Same            | `data-mtuc-field-error`                                    |

### Third „Купи на изплащане“ button — resolved

- **Source:** legacy `.mt-uni-credit-open-modal` in calculator Twig + `TRIGGER_SELECTOR` in JS.
- **Intended role:** mistaken duplicate open-modal trigger using `text_button_financing`.
- **Reference:** „Купи на изплащане“ is the **title inside each offer pill**, not a separate product action.
- **Decision:** **Removed.** Offer pills are the sole modal triggers.

### `product_button_action` semantics

- `add_to_cart` → secondary „Добави в количката“ clicks native `#button-cart`.
- `buy` → secondary „Купи“ redirects to checkout URL.
- Does **not** create a third product-level financing control.

## Dynamic recalculation (Jet OC4 parity)

Product option/quantity refresh follows the proven `mt_jet_credit` OpenCart interaction pattern:

| Concern  | Jet                                 | UniCredit                                               |
| -------- | ----------------------------------- | ------------------------------------------------------- |
| Quantity | `$('[name=quantity]').on('change')` | `#input-quantity` / `input[name=quantity]` change+input |
| Options  | `[id^="input-option"]` change       | same selector + document backup                         |
| Debounce | none (Jet)                          | 250ms + AbortController/sequence                        |
| AJAX     | Jet-specific calculate              | `mt_uni_credit_product.calculate`                       |

Debug mode (`module_mt_uni_credit_debug_enabled`) writes **server-side** OpenCart log events only (`ProductVisibilityDebugLog`, calculate failure diagnostics). Storefront JS never emits intentional developer/debug console output.

## Asset cache busting (JS/CSS)

Module release version (`ModuleConstants::VERSION`, e.g. `2.0.x`) is **not** the asset cache key.

`ModuleAssetVersion` versions each module-owned JS/CSS URL from that file’s `filemtime()`. Conceptual result:

```text
mt_uni_credit_fonts.css?ver=1787981230
mt_uni_credit_product.js?ver=1787981234
mt_uni_credit_product.css?ver=1787981201
```

Missing/unreadable files fall back safely to the module version. Editing an asset changes its URL without a release bump and without a manual Cloudflare purge for that asset. HTML/PHP cache policy remains separate.

## Local fonts (Roboto Condensed)

Packaged under `catalog/view/fonts/roboto-condensed/` (WOFF2 400/700 Cyrillic+Latin, SIL OFL). Loaded via `mt_uni_credit_fonts.css` `@font-face` and scoped only to `#mt-uni-credit-product-root` / `#mt-uni-credit-product-modal` (and later Cart/Checkout UniCredit roots). No Google Fonts / CDN font hosts.

## Product popup Step 1 visual contract (Woo/PS9 authority)

Reference CSS: Woo `mtuc-popup.css`, PS9 `product-calculator.css`.

| Element                           | Frozen value                                                                                             |
| --------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Calc frame radius                 | `14.5px 14.5px 80px 14.5px` (TL TR BR BL — large bottom-right)                                           |
| Frame border / shadow             | `2px solid #d4d4d8`, `box-shadow: 1px 1px 1px 1px #f4f4f5`                                               |
| Popup red                         | `#ed1c24` (`--mtuc-popup-red`)                                                                           |
| Right-column values               | red, `font-size: 20px`, right-aligned                                                                    |
| Scheme select / first installment | no box border; `border-bottom: 1px solid #b0b0b0`; red text; value-like (not Bootstrap form-control)     |
| Promo / described scheme label    | `{months} месеца - {description}` (Woo `formatMonthLabel` / PS9 JS)                                      |
| Standard without description      | `{months} месеца`                                                                                        |
| Step 1 footer                     | left: Отказ + secondary (`product_button_action`); right: Кандидатствай                                  |
| Buttons                           | layered UniCredit control (`#a1a1aa` face, 6px radius, 3px inner border, primary label red, badge 40×40) |

Mobile ≤768px: frame radius becomes `14px` (no calc background image); Step 1 actions stack full-width (Woo parity).

### Secondary `Добави в количката` → close on cart success

`product_button_action=add_to_cart` still clicks native `#button-cart` (OpenCart `#form-product` → `checkout/cart.add`). UniCredit binds a **namespaced one-shot** `ajaxSuccess.mtUniCreditCart` listener and calls existing `closeModal()` only when the response JSON has `success`. Validation/`error` responses leave the popup open. Duplicate clicks while awaiting are ignored; listeners are unbound on close/Cancel/Escape. `buy` still redirects to `checkout_url` unchanged.

## Required Product options (popup entry)

Missing required OpenCart Product options (`.mb-3.required` / `option[…]` including checkbox `option[N][]`) must not open a broken modal via `issueSubmission` 422.

| Layer        | Behavior                                                                                                                                                 |
| ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Frontend     | Offer click inspects Product DOM; if incomplete → friendly message near calculator (`data-mtuc-entry-error`); **no** `issueSubmission` / attempt / modal |
| Server       | Still authoritative: `missing_required_option` → HTTP 422 + same Bulgarian message                                                                       |
| 422 fallback | JS maps `error_code` / known messages → same entry error; closes incomplete modal; clears submission token                                               |

Customer message (bg): `Моля, изберете задължителните опции на продукта.` (Jet-aligned; not raw English / HTTP codes).

## First installment on scheme change

Woo/PS: scheme `change` → reset first installment to `0` + unlock UI → clear `lastCalculation` / submission token → server recalculation returns authoritative value + locked/editable state. Never reuse the previous scheme’s input/`lastCalculation.first_installment` as the new default.

## Focus styling (scheme select + first installment)

Woo/PS reference: `:focus` / `:focus-visible` use `outline: none; box-shadow: none` (no browser blue rectangle, no Bootstrap form-control ring). Approved value-like chrome (red text, bottom border only) is unchanged.

**Accessibility compromise:** keyboard focus remains possible (Tab / Escape / modal trap), but these two Step 1 controls intentionally have **no visible focus frame**, matching Woo/PS UniCredit popups. Customer Step 2 fields retain their own treatment.

## Product popup Step 2 visual + readiness (Woo/PS authority)

Reference: Woo `mtuc-popup.css` / `mtuc-product-popup.js`; PS9 `product-calculator.css` / `product-calculator.js`.

| Element             | Frozen contract                                                                                                                  |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Customer inputs     | bottom border only (`1px solid #b0b0b0`), no full rectangle; `font-size: 20px`; text `#000`; padding `0 0 0 16px`                |
| Labels              | `16px` / weight `400` / line-height `1.2` / `#000`; required `*` in popup red                                                    |
| Focus               | `outline: none; box-shadow: none` (no Bootstrap blue ring)                                                                       |
| Invalid             | `aria-invalid` → bottom border turns popup red; inline error text                                                                |
| Consent checkbox    | native input, `18×18`, `accent-color: #ed1c24` (Woo/PS)                                                                          |
| Consent text/link   | `14px` / `#000` / underline / hover red; legal href unchanged                                                                    |
| `Изпрати` readiness | disabled until required fields valid **and** all rendered consent checkboxes checked (if any); prefill evaluated on Step 2 entry |

Client readiness reuses Woo/PS patterns (`nonEmpty`, phone/email regex). Server `ProductCustomerValidator` / consent resolver remain authoritative on submit.

### Step 2 submit runtime remediation

| Issue                             | Root cause                                                                                          | Fix                                                                                        |
| --------------------------------- | --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Invisible `Изпрати` when disabled | Disabled label color `#a1a1aa` on unchanged face background `#a1a1aa`                               | Disabled `b` uses `--mtuc-btn-disabled-bg` + readable `#52525b` text                       |
| Readiness / Process awareness     | Validate only rendered fields; Process 1 must not require EGN/`phone2`                              | `getStep2FieldErrors()` checks `egn`/`phone2` only if those inputs exist in the DOM        |
| Calculation sync                  | `lastCalculation` required for enable; Apply gated on it; option refresh re-issues while modal open | `hasAuthoritativeCalculation()` + Apply `&& lastCalculation` + Step 2 refresh recalculates |

## Calculate HTTP 500 remediation (runtime)

Root cause: `productModel(): ProductFinancingModel` rejected OpenCart’s `Engine\Proxy` wrapper (`TypeError`). Fix: return `object` (docblock documents Proxy). Unexpected failures are logged as `mt_uni_credit.product_calculate` without secrets.

## Phase 7 Product — freeze checklist status

Baseline SHA: `d7eefb96b7a9e1320ec9ec350f2a936627fa1cd4`

| Section                        | Code/test status                              | Notes                                                                            |
| ------------------------------ | --------------------------------------------- | -------------------------------------------------------------------------------- |
| A–F Product UX / runtime       | **PASS** (source + Phase 7 PHPUnit contracts) | Live OC 4.1.0.3 operator matrix still required for production claim              |
| G Process 2 EGN / second phone | **DEFERRED**                                  | Not rendered; not required for Process 1; implement with Process lifecycle later |
| H Local-order boundary         | **PASS**                                      | `local_order_prepared`; no CP / SmartUCF / Cart UI                               |
| I Security / CSRF / URLs       | **PASS**                                      | Module CSRF; `url->link(..., true)`                                              |
| J Accessibility                | **PASS**                                      | dialog / trap / Escape / inert cleanup                                           |
| K Static assets / privacy      | **PASS**                                      | Local Roboto; filemtime; no `console.*`                                          |
| L Regression                   | **PASS**                                      | PHP 8.2 lint clean; PHP 8.4 PHPUnit **339 / 7865 OK**                            |

Phase 8 Cart/Checkout/CP create: **not present** (`Phase7ScopeGuardTest`; no `docs/PHASE8.md`).

### Freeze record (code-ready)

```text
PHASE 7 PRODUCT: CODE FREEZE READY
Frozen SHA: d7eefb96b7a9e1320ec9ec350f2a936627fa1cd4
Process 2 EGN/second-phone UI: deferred to Process lifecycle phase
Next allowed work: Phase 8 Cart (only after operator A–F sign-off → FINAL PASS)
```

`FINAL PASS` requires explicit operator confirmation that sections A–F are green on Product 40 / staging.
