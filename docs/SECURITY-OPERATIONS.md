# Security operations — Phase 10B notes

## Outbound CP create

- Uses existing Phase 4 `ControlPanelClient` (encrypted DB secret, Bearer token, one 401 retry).
- Does **not** use SmartUCF mTLS certificate paths for CP HTTP.
- Does **not** add a deployment file for CP login secret.

## Logging

Server-side only when `module_mt_uni_credit_debug_enabled=1`:

- Safe: attempt_id, entry_point, store_id, local order_id, cp order id, state event, error class, HTTP status
- Forbidden: secret, Bearer token, EGN, email, telephone, address, full customer/CP payload

## Inbound HMAC (Bridge A)

CP → module callbacks use `X-UniPayment-Timestamp` / `Nonce` / `Signature`.

- Canonical: `timestamp + "\n" + nonce + "\n" + raw_body`
- Secret: store-scoped `module_mt_uni_credit_secret` (encrypted); matches CP shop `secret_key`
- ±300s timestamp window; 900s nonce retention; replay → 401
- Not used for outbound CP create (Bearer auth)

Exact URLs and payloads: `docs/INBOUND-API.md`.

## PII

Frozen `cp_payload` on the attempt row is required for idempotent recovery. Do not add a second permanent customer payload table for CP retry.

Diagnostic debug retrieval redacts EGN, contact fields, tokens, and key material before returning to CP.
