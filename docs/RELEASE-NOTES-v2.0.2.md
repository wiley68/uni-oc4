# UniCredit OpenCart 4 — Release notes draft (v2.0.2)

**Status:** Release Candidate draft — not tagged / not packaged yet.  
**Module version:** `2.0.2`  
**Target:** OpenCart 4.1.0.3 · PHP 8.2 (web) · PHP 8.4 (CLI/tests)

## Summary

Financing module for UniCredit with equal Product, Cart, and Checkout entry points; Process 1 (SmartUCF) and Process 2 (leasing handoff); Product Buy checkout handoff; security and privacy hardening for release.

## Functionality

- **Product / Cart / Checkout** financing calculators and submit flows
- **Process 1:** CP order → SmartUCF session → trusted bank redirect
- **Process 2:** CP order → bank_sent_process2 → leasing mail (no SmartUCF)
- **Product Buy:** native cart.add → preference stash (+ signed cookie resilience) → Checkout UniCredit + exact scheme preselect
- **Admin:** bank status on order list/detail; Process 2 admin fields; debug journal download
- **Thank You / emails:** shared leasing presentation (customer-safe)

## Hardening in this release line

- Session-only Thank You order identity (no GET `order_id` IDOR)
- Store-scoped presentation lookup (`store_id` + `order_id`; `store_id=0` valid)
- Product Buy cookie HMAC fail-closed without installation secret
- 183-day leasing presentation retention; 180-day Process 2 ciphertext redact; 3-month diagnostic journal
- Bounded prune for API nonces, operation locks, shop cache
- EventRegistry sync removes known retired UniCredit event codes
- HMAC inbound replay protection; storefront CSRF on mutating endpoints
- SmartUCF TLS verify + mTLS; unknown outcome ≠ definite bank failure

## Supported / notes

- Fresh install / uninstall-reinstall for schema (no upgrade scripts in this cycle)
- Uninstall removes module events; persistence tables are retained by policy
- Deployment: edit `config/environment.php` for Control Panel host; keep passphrase/certs out of VCS

## Validation

Automated: PHPUnit green on PHP 8.4; PHP 8.2 lint; JS syntax check.  
Operator checklist required before production (Thank You, Product Buy, Admin Save / events, multistore if used).
