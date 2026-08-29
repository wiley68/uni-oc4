# Security operations — Phase 10B notes

## Outbound CP create

- Uses existing Phase 4 `ControlPanelClient` (encrypted DB secret, Bearer token, one 401 retry).
- Does **not** use SmartUCF mTLS certificate paths for CP HTTP.
- Does **not** add a deployment file for CP login secret.

## Logging

Server-side only when `module_mt_uni_credit_debug_enabled=1`:

- Safe: attempt_id, entry_point, store_id, local order_id, cp order id, state event, error class, HTTP status
- Forbidden: secret, Bearer token, EGN, email, telephone, address, full customer/CP payload

## Inbound HMAC (unchanged)

`X-UniPayment-Timestamp` / `Nonce` / `Signature` — not used for outbound CP create.

## PII

Frozen `cp_payload` on the attempt row is required for idempotent recovery. Do not add a second permanent customer payload table for CP retry.
