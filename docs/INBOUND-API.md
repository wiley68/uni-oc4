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

- Algorithm: HMAC-SHA256, lowercase hex
- Secret: store-scoped module login secret (`module_mt_uni_credit_secret`, encrypted at rest) — same secret CP uses as shop `secret_key`
- UNICID: body `unicid` must match store setting
- Timestamp tolerance: **±300 seconds**
- Nonce: 64 hex chars; stored as `sha256(nonce)`; retention **900 seconds**; replay → **401**
- Signature verified on **raw `php://input`** before JSON decode
- Invalid signature does **not** consume the nonce
- Module disabled → **403**

## 1. Shop cache

**Request JSON:**

```json
{
  "unicid": "<shop-unicid>",
  "data": {
    /* full CP shop snapshot */
  }
}
```

Behavior: **accepts pushed shop data**, validates (`ShopConfigurationSnapshotValidator`), replaces `mt_uni_credit_shop_cache` for `(store_id, unicid)`. Does **not** call CP `GET /shop` on this path.

`store_id = 0` (default store) is valid and isolated.

**Success 200:**

```json
{
  "success": true,
  "message": "Кешът на shop данни е обновен успешно.",
  "data": { "fetched_at": "...", "expires_at": "...", "is_fresh": true }
}
```

**Errors:** 400 invalid body; 401 auth; 403 disabled; 422 invalid snapshot (`error=shop_snapshot_invalid`).

## 2. Order bank status

**Request JSON:**

```json
{
  "unicid": "<shop-unicid>",
  "order_id": "<local OpenCart order_id string>",
  "status_id": "cp_sent",
  "status": "Създаден в КП Банка"
}
```

Lookup scope: `config_store_id` + local `order_id` that belongs to a UniCredit financing attempt (or UniCredit payment method on the order). No cross-store lookup.

Accepted `status_id` vocabulary:

- `cp_sent`, `smartucf_sent`
- `bank_sent_process1`, `bank_sent_process2`
- `bank_send_failed`, `bank_send_failed_cp`, `bank_send_failed_smartucf`
- SmartUCF numeric codes: `^\d{1,3}$`

Semantics (unchanged):

| status_id                   | Meaning                                        |
| --------------------------- | ---------------------------------------------- |
| `cp_sent`                   | Order exists in CP; bank handoff not completed |
| `bank_sent_process1`        | Sent to SmartUCF via Process 1                 |
| `bank_sent_process2`        | Process 2 channel                              |
| `bank_send_failed_smartucf` | CP order exists; SmartUCF send failed          |

Unsupported status → **400** `error=unsupported_status`.  
Missing order → **404**.  
Same status twice → upsert / idempotent success.  
OpenCart native order status is **not** changed (`oc_order_state_changed: false`).

**Success 200:**

```json
{
  "success": true,
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
  "unicid": "<shop-unicid>",
  "order_id": "<local OpenCart order_id string>"
}
```

Authorized only when a financing attempt exists for `(store_id, order_id)`.

If no diagnostic row: structured **404** JSON (not HTML/500).

When present, payload is redacted (`DiagnosticPayloadRedactor`) — no EGN, email, phone, address, tokens, secrets, keys.

**Success 200:**

```json
{
  "success": true,
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
| Bad JSON / validation / unsupported status                          | 400  |
| Invalid shop snapshot                                               | 422  |
| Order / debug not found                                             | 404  |
| Wrong method                                                        | 405  |
| Unexpected failure                                                  | 500  |

## Historical note

Legacy marketplace `api/refreshcache` used a different HMAC (`timestamp.body` signed with UNICID). That contract is **not** used. Bridge A freezes the shared CP/PS9/Woo protocol only.
