# UniCredit OpenCart 4.x — Phase 5: Shared Financing Domain and Calculator Parity

Phase 5 implements the shared financing calculator domain ported from uni-ps9 (`2026-08-17` oracle). Product, Cart and Checkout adapters arrive in Phase 6; this phase is pure PHP domain code under `system/library/`.

## Architecture

```text
ProductContext / CartContext (normalized DTOs)
        ↓
Calculator (single orchestrator)
        ↓
scheme resolution (by_default | by_schema.filters)
        ↓
MonthResolver + CoefficientResolver + SchemaFilterMatcher
        ↓
FirstInstallmentResolver → FinancialCalculator
        ↓
OfferFactory (preview/button)  |  calculateScheme (authoritative)
        ↓
PreferredOfferSelector + SchemePresentationCategory ordering
        ↓
Offer / CalculationResult DTOs
```

Cart uses `CartSchemeResolver` on top of the same `Calculator` — no second financial engine.

## Domain classes

| Class                                           | Role                                         |
| ----------------------------------------------- | -------------------------------------------- |
| `Calculator`                                    | Orchestrator for product/cart/checkout math  |
| `ProductContext`                                | Product id, categories, authoritative amount |
| `CartContext` / `CartLine`                      | Normalized cart lines + cart total           |
| `CartSchemeResolver`                            | Line-wise scheme sets → intersection         |
| `MonthResolver`                                 | Enabled months 3–36, promo month operators   |
| `CoefficientResolver`                           | KOP + month coefficient lookup               |
| `SchemaFilterMatcher`                           | Product/category/price/date filters          |
| `FirstInstallmentResolver`                      | Locked `uni_parva` vs user-requested         |
| `FinancialCalculator`                           | GPR iterative math                           |
| `OfferFactory`                                  | Button/preview offers                        |
| `PreferredOfferSelector`                        | Preferred month → highest → lowest monthly   |
| `SchemePresentationCategory`                    | Deterministic ordering                       |
| `CurrencyGate` / `AmountDisplayFormatter`       | BGN/EUR gate + dual display                  |
| `Offer`, `AvailableScheme`, `CalculationResult` | Immutable DTOs                               |

No OpenCart Registry, Controller, Session, DB, HTTP or Twig dependencies.

## Standard schemes (`uni_typekop = 0`)

- KOP from `kop.by_default.uni_kop_default`
- Months from shop `uni_meseci_*` flags (3–36 range enforced)
- Preferred month: `uni_shema_current`
- Coefficient from `coeff_list` by `onlineProductCode` + `installmentCount`

## Promotional schemes

- Default mode: `uni_kop_promo`, `uni_promo_price` minimum, `uni_promo_meseci_znak` (`eq` | `greateq`)
- Promo type requires zero interest (`interestPercent ≈ 0`)
- Schema mode: filters with `uni_promo = 1`, same zero-interest rule
- `uni_parva = 1` locks first installment to `round(price / months, 2)`

## Cart intersection

- **Identity key:** `type|kopCode|months` (filter id is metadata only)
- Each line keeps product/category identity; **price rules use cart total**
- Intersection across lines; LCM expansion for Woo parity
- Conflicting `uni_parva` → `firstInstallmentAmbiguous` (listed but not calculable)
- Agreeing filters → lowest `filterId` wins

## Ordering

Within same month ascending:

1. standard (`uni_kop_default`)
2. non-zero promo (other KOP, non-zero interest)
3. zero-interest promo

Then `filterId ASC`, `kopCode`, `type`.

## Preferred offer

1. Offers matching `uni_shema_current` months
2. Else highest available month
3. Tie-break: lowest monthly installment

Cart standard button excludes `zero_promo` schemes.

## Monetary model

- **Units:** decimal `float` currency values (not integer cents)
- **Rounding:** `round(..., 2)` at installment, financed amount, totals, GPR/GLP display
- `monthly = round(financed * coeff, 2)`
- `totalPayable = round(monthly * months, 2)`
- `glp = round(abs(interestPercent), 2)` in `calculateScheme`; `round(interestPercent, 2)` in `OfferFactory`

## GPR 0% discrepancy (preserved intentionally)

Two paths exist in uni-ps9 and are **both** reproduced:

| Path                              | GPR rule                                                  | Typical 0% promo @ 1000 BGN / 12m |
| --------------------------------- | --------------------------------------------------------- | --------------------------------- |
| `OfferFactory` / preferred offers | `round(raw, 2)` **no floor**                              | **0.01**                          |
| `Calculator::calculateScheme`     | `raw <= 0.1 ? 0.0 : round(raw, 2)` on **financed** amount | **0.0**                           |

**Why:** coeff `0.083333 × 1000` ≠ exact `1000/12`, so iterative GPR is a small positive value. OfferFactory shows `0.01` on buttons/previews; popup/checkout/cart button path uses `calculateScheme` → `0.0`. CP order submission uses `calculateScheme` values (same as checkout).

This is documented in `tests/fixtures/calculator_golden.json` case `promo_0_percent` — not a fixture error.

## Currency

- Supported ISO: **BGN**, **EUR** only
- `uni_eur ∈ {2,3}` → EUR expected; otherwise BGN
- Dual display rate: **1.95583** (`AmountDisplayFormatter::DISPLAY_RATE`)
- No currency conversion in calculator eligibility

## Parity evidence

- Frozen vectors: `tests/fixtures/calculator_golden.json`
- Executable gates: `Phase5GoldenParityTest`, `Phase5CartSchemeResolverTest`, `Phase5CalculatorDomainTest`
- Oracle: uni-ps9 `src/Calculator/*`, `tests/Calculator/*`, `tests/Cart/CartSchemeResolverTest.php`

## Out of scope (Phase 6+)

OpenCart adapters, product/cart/checkout UI, payment method, order creation, CP orders, SmartUCF, callbacks, financing snapshots, admin order diagnostics.
