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
