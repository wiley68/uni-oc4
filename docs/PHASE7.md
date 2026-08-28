# Phase 7 — Product calculator and financing modal (local order boundary)

Phase 7 adds the OpenCart 4.1 standard-theme **Product** financing flow only. It stops at successful **local OpenCart order materialization** via Phase 6 — no CP, Cart, Checkout payment, SmartUCF, snapshot, email, or callbacks.

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

All routes: `POST` + session CSRF (`csrf_token`), JSON responses, bounded validation, no stack traces.

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
