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

Process 2 EGN/phone2 live only in `process2_sensitive_enc` (encrypted). Never log them. Customer leasing email must not contain EGN. Retention redacts sensitive ciphertext after 180 days.

Process 2 encryption is **fail-closed**: if the deployment encryption secret cannot be resolved, sensitive fields are not persisted, Process 2 handoff does not claim `bank_sent_process2`, and no plaintext EGN/phone2 is written. Tests may inject an explicit secret; production never falls back to a predictable test value.

Leasing presentation JSON (`leasing_presentation_json`) is redacted after **183 days (~6 months)** from attempt `created_at`. Bounded batch cleanup runs on presentation persist. Attempt rows and operational identifiers remain.

Bank-status presentation (Thank You, mail, admin list/detail) resolves **exact** `(store_id, order_id)` only. There is no fallback from a nonzero store to `store_id = 0` for the same numeric order id (`store_id = 0` remains valid when it is the actual store).

Diagnostic debug retrieval redacts EGN, contact fields, tokens, and key material before returning to CP. Diagnostic journal retention remains **3 months**; prune deletes in bounded batches (default 100) and uses `idx_mt_uni_credit_diag_created` on `created_at`.

Thank You identity is session-only (`mt_uni_credit_success_order_id`, then native `session.order_id`). GET `order_id` is never trusted. Logged customers additionally require matching `oc_order.customer_id` + `store_id`. Missing session context renders native Thank You without UniCredit leasing.

Customer Thank You presentation excludes EGN and phone2 at the row-label level (defense-in-depth on top of audience filtering).

## Product Buy handoff cookie

Signed cookie `mtuc_pb_handoff` is resilience only (OC4 session last-writer-wins). HMAC uses the installation encryption secret. If that secret cannot be derived, the cookie is **not issued and not trusted**; session preference still works. Tests may inject an explicit secret; production never falls back to a predictable test value.

## Transient table pruning

Expired API nonces, operation locks, and shop-cache rows are pruned in small batches (limit 10) after successful nonce claim, lock acquire, and shop-cache write. Nonce retention remains 900 seconds; active locks are not deleted.

## Event registry

Admin Save / install `syncEvents()` deletes current EventRegistry codes **and** known retired UniCredit codes (`after_checkout_success`, `before_shipping_method_save_buy`), then re-inserts current definitions. Other extensions' events are not touched. Uninstall removes the same managed set and does **not** DROP data tables.

## SmartUCF Process 1 mTLS

SmartUCF is called directly over HTTPS with peer and hostname verification enabled and a 10-second timeout. Destination policy accepts only the frozen `online.ucfin.bg` / `onlinetest.ucfin.bg` paths.

When `uni_sertificat` is enabled:

- The authenticated CP client reads `/ssl/certificate` metadata and downloads `/ssl/certificate/bundle` only for missing or mismatched material.
- SHA-256 covers the exact raw PEM bytes; certificate/key validity and matching are checked locally.
- Replacement is lock-protected and staged; authoritative modes are certificate `0640`, key `0600`.
- SmartUCF receives a temporary `0600` consumer lease, removed in `finally`.
- The private-key passphrase remains exclusively in `secrets/smartucf-key.php`; CP never supplies it.
- Transient CP errors may fail open only with a complete valid local pair. Explicit CP unavailability fails closed.
- Synchronization/pre-send failures are retryable and do not write `bank_send_failed_smartucf`.
