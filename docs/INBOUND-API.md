# Inbound API — CP → OpenCart 4 (Integration Bridge A)

Frozen machine-to-machine endpoints for Control Panel callbacks.
Base storefront: `https://open40.avalonbg.com`

Do **not** use SEO aliases. Always use explicit `index.php?route=…` URLs.

## Production URLs (copy into CP)

```text
https://open40.avalonbg.com/index.php?route=extension/mt_uni_credit/api/shop_cache
https://open40.avalonbg.com/index.php?route=extension/mt_uni_credit/api/order_bank_status
https://open40.avalonbg.com/index.php?route=extension/mt_uni_credit/api/smartucf_debug_log
```

CP `config/uni.php` relative paths for platform `OpenCart 4.x`:

```text
index.php?route=extension/mt_uni_credit/api/shop_cache
index.php?route=extension/mt_uni_credit/api/order_bank_status
index.php?route=extension/mt_uni_credit/api/smartucf_debug_log
```

## Controllers

| Function    | File                                            | Class                    | Action  | Route                                            |
| ----------- | ----------------------------------------------- | ------------------------ | ------- | ------------------------------------------------ |
| Shop cache  | `catalog/controller/api/shop_cache.php`         | `…\Api\ShopCache`        | `index` | `extension/mt_uni_credit/api/shop_cache`         |
| Bank status | `catalog/controller/api/order_bank_status.php`  | `…\Api\OrderBankStatus`  | `index` | `extension/mt_uni_credit/api/order_bank_status`  |
| Debug log   | `catalog/controller/api/smartucf_debug_log.php` | `…\Api\SmartucfDebugLog` | `index` | `extension/mt_uni_credit/api/smartucf_debug_log` |

Shared base: `catalog/controller/api/inbound_api_base.php`.

No extra event registration is required — OpenCart 4 resolves extension catalog controllers by route. Persistence tables are created by module install / `PersistenceSchemaInstaller`.

## HTTP method

All three: **POST only**. Other methods → HTTP **405** JSON.

Responses: `Content-Type: application/json; charset=utf-8` (no theme/layout).

## Authentication (HMAC)

Headers:

```text
X-UniPayment-Timestamp
X-UniPayment-Nonce
X-UniPayment-Signature
```

Canonical string:

```text
timestamp + "\n" + nonce + "\n" + exact_raw_body
```

Processing order (after Content-Length / bounded body gate):

1. POST only (else 405)
2. empty body → 400
3. credentials / module enabled
4. HMAC verify on **exact raw body**
5. JSON decode object
6. UNICID `hash_equals`
7. atomic nonce claim (storage failure → 500 `replay_store_failed`)
8. `operation` must match the code-bound endpoint operation
9. handler

- Algorithm: HMAC-SHA256, lowercase hex signature compare
- Secret: store-scoped module login secret (`module_mt_uni_credit_secret`, encrypted at rest)
- UNICID: body `unicid` must match store setting
- Timestamp tolerance: **±300 seconds**
- Nonce: **exactly** `[0-9a-f]{64}` (lowercase only; uppercase rejected); stored as `sha256(nonce)`; retention **900 seconds**; replay → **401**
- Body size: max **1 MiB** → **413** `payload_too_large` before auth/decode
- Invalid signature does **not** consume the nonce
- Module disabled → **403**

All JSON responses use the four-field envelope: `success`, `error`, `message`, `data` (object, never a list).

Endpoint operations:

| Route              | `operation` value    |
| ------------------ | -------------------- |
| shop_cache         | `shop-cache`         |
| order_bank_status  | `order-bank-status`  |
| smartucf_debug_log | `smartucf-debug-log` |

## 1. Shop cache

**Request JSON:**

```json
{
  "operation": "shop-cache",
  "unicid": "<shop-unicid>",
  "data": {
    /* full CP shop snapshot (JSON object, not list) */
  }
}
```

Behavior: sanitize (`ShopSnapshotSanitizer`) → validate (`ShopConfigurationSnapshotValidator`) → replace `mt_uni_credit_shop_cache` for `(store_id, unicid)`. Does **not** call CP `GET /shop` on this path. Empty object / JSON list for `data` → 400.

`store_id = 0` (default store) is valid and isolated.

**Success 200:**

```json
{
  "success": true,
  "error": null,
  "message": "Кешът на shop данни е обновен успешно.",
  "data": { "fetched_at": "...", "expires_at": "...", "is_fresh": true }
}
```

**Errors:** 400 invalid body; 401 auth; 403 disabled; 413 oversized; 422 `shop_snapshot_invalid`.

## 2. Order bank status

**Request JSON:**

```json
{
  "operation": "order-bank-status",
  "unicid": "<shop-unicid>",
  "order_id": "<local OpenCart order_id string max 13>",
  "status_id": "cp_sent",
  "status": "Създаден в КП Банка"
}
```

Field rules:

- `order_id`: **string only** (reject int), non-empty, max 13, digit string for OC order id
- `status_id`: string only, non-empty, max 255
- `status`: string only, required non-empty, max 255
- no `status_label` wire field

Lookup: `FinancingOrderResolver` — financing attempts for `(store_id, order_id)` **without** `LIMIT 1` as auth decision.
0 → 404; 1 → continue; 2+ → **409** `order_ambiguous`.
**No** payment-method fallback.

P1↔P2 terminal conflict on UPSERT → **409** `semantic_conflict` (existing status retained).

Accepted `status_id` vocabulary:

- `cp_sent`, `smartucf_sent`
- `bank_sent_process1`, `bank_sent_process2`
- `bank_send_failed`, `bank_send_failed_cp`, `bank_send_failed_smartucf`
- SmartUCF numeric codes: `^\d{1,3}$`

Unsupported status → **400** `unsupported_status`.
Same status twice → idempotent success.
OpenCart native order status is **not** changed (`oc_order_state_changed: false`).

**Success 200:**

```json
{
  "success": true,
  "error": null,
  "message": "Банковият статус е обновен успешно.",
  "data": {
    "order_id": "592",
    "oc_order_id": 592,
    "status": "...",
    "status_id": "cp_sent",
    "oc_order_state_changed": false
  }
}
```

## 3. SmartUCF / diagnostic debug log

**Request JSON:**

```json
{
  "operation": "smartucf-debug-log",
  "unicid": "<shop-unicid>",
  "order_id": "<local OpenCart order_id string max 13>"
}
```

Ownership via `FinancingOrderResolver` **before** any journal disclosure. All denials (missing attempt, ambiguous, missing log) → opaque **404**.

When present, payload is redacted (`DiagnosticPayloadRedactor`) — no EGN, email, phone, address, tokens, secrets, keys.

**Success 200:**

```json
{
  "success": true,
  "error": null,
  "message": "Диагностичният запис е намерен.",
  "data": {
    "order_id": "592",
    "oc_order_id": 592,
    "log": { "event_code": "...", "summary": {}, "created_at": "..." }
  }
}
```

## HTTP status summary

| Case                                                                | HTTP |
| ------------------------------------------------------------------- | ---- |
| Success                                                             | 200  |
| Bad / missing signature, replay, expired timestamp, missing headers | 401  |
| Module disabled                                                     | 403  |
| Bad JSON / validation / unsupported status / wrong operation        | 400  |
| Invalid shop snapshot                                               | 422  |
| Order / debug not found                                             | 404  |
| Ambiguous order / semantic bank conflict                            | 409  |
| Payload too large                                                   | 413  |
| Wrong method                                                        | 405  |
| Replay store failure / unexpected                                   | 500  |

## Historical note

Legacy marketplace `api/refreshcache` used a different HMAC (`timestamp.body` signed with UNICID). That contract is **not** used. Bridge A freezes the shared CP/PS9/Woo protocol only.
