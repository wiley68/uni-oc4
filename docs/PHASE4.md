# UniCredit OpenCart 4.x — Phase 4: CP Client, Auth, Shop Cache

Phase 4 добавя outbound Control Panel HTTP transport, login/refresh/logout, криптирано store-scoped token persistence, `GET /shop`, валидиран shop cache service и admin diagnostics.

## HTTP transport

- `CurlCpHttpTransport` — cURL, TLS verify ON, connect 5s, total 15s (`CpHttpConstants`)
- `FakeCpHttpTransport` — deterministic PHPUnit double
- `CpHttpResponse` + `CpException` hierarchy (transient vs permanent vs malformed)

## Auth lifecycle (internal infrastructure)

- Login: `POST /api/v1/auth/login` with `{unicid, name, secret}`
- Refresh: `POST /api/v1/auth/refresh` (Bearer), ротира токена
- Logout: `POST /api/v1/auth/logout`, винаги локална invalidation в `finally`
- 401 flow: invalidate → re-auth/login → **exactly one** retry; втори 401 → permanent auth failure + invalidation

**Operator workflow (admin UI):** Configure → native OpenCart **Save** → **Обнови данните от банката**.  
There are no Connect / Login / Logout buttons. CP auth is transparent inside `refreshBankData` / `ShopConfigurationService::refreshRemote()`.

## Operator admin actions

| Button (BG)               | Action                                                                                                    |
| ------------------------- | --------------------------------------------------------------------------------------------------------- |
| Native OpenCart Save      | Save status / UNICID / encrypted secret / local module settings (no automatic bank refresh)               |
| Обнови данните от банката | Validate credentials → ensure CP token → `GET /shop` → validate → replace store-scoped cache              |
| Изтегли журнал операции   | Visible, **disabled** until bank-request diagnostic journal (later phase; PS9 equivalent is debug export) |

Catalog shop URL for CP login `name` falls back to `HTTPS_CATALOG` / `HTTP_CATALOG` when store `config_ssl`/`config_url` are empty (default store).

## Credentials (operator-configured)

| Material        | Source                                             | At rest                                 |
| --------------- | -------------------------------------------------- | --------------------------------------- |
| UNICID          | admin setting `module_mt_uni_credit_unicid`        | plaintext in `oc_setting`               |
| CP login secret | admin password field `module_mt_uni_credit_secret` | AES-256-GCM (`enc:v1:`) in `oc_setting` |
| Bearer token    | automatic after login                              | AES-256-GCM (`enc:v1:`) in `oc_setting` |

Operational model matches **uni-ps9** / **Woo mtunicredit**: operator enters UNICID + shop secret in admin; secret is never re-displayed. Empty password on save keeps the existing secret.

Deployment files (`secrets/smartucf-key.php`, PEM keys) are **only** for SmartUCF mTLS — not for CP login.

## Token / setting encryption key

- **Source:** `DB_PASSWORD` from OpenCart `config.php` (standard deployment credential, not stored in `oc_setting`)
- **Provider:** `ModuleEncryptionKeyProvider::resolveSecretInput()` → `resolveDerivedKey()`
- **Derivation:** `hash_hkdf('sha256', DB_PASSWORD, 32, 'mt_uni_credit/settings-encryption/v1')`
- Same derived key encrypts CP secret and bearer token at rest (`enc:v1:` via `ModuleSettingCipher`)
- **Decoupled** from CP login secret value and from predictable metadata (`DB_PREFIX`, `DB_DATABASE`, `DIR_STORAGE`, URLs, UNICID)
- No extra UniCredit deployment secret file; role analogous to PrestaShop `_NEW_COOKIE_KEY_` (installation secret outside module settings)

### At-rest protection model

| Attacker has                 | Can decrypt CP secret / token? |
| ---------------------------- | ------------------------------ |
| Database dump only           | **No** (needs `config.php`)    |
| Database dump + `config.php` | Yes (same as full app access)  |
| Admin UI / health presenter  | **No** (never rendered)        |

### Development invalidation (pre-release)

Earlier Phase 4 development builds derived keys from `DB_PREFIX|DB_DATABASE|DIR_STORAGE` metadata. That scheme is **removed**. Existing `enc:v1:` CP secret and bearer token values from those builds **will not decrypt** after this remediation. Re-enter the CP secret in admin and reconnect; no `enc:v0` migration path.

## Shop cache

- TTL: **86400s** (`SecurityConstants::SHOP_CACHE_TTL_SECONDS`)
- Full snapshot validation via `ShopConfigurationSnapshotValidator`
- Invalid remote snapshot **does not** overwrite known-good cache
- Permanent auth/4xx/invalid payload → purge scoped cache + tokens
- Transient 5xx/network → preserve cache + tokens

## Canonical shop URL

- `CanonicalShopUrlProvider` — HTTPS, без trailing slash, prefer `config_ssl` → `config_url`

## Admin

- UNICID + secret password fields (secret never shown after save)
- Safe health: CP host, auth state, token expiry timestamp, cache metadata
- POST actions: connect, refreshShop, disconnect

## Credential change

- UNICID or secret change → `CredentialChangeHandler` invalidates tokens + scoped cache

## Store scope

Admin CP services use `config_store_id` (OpenCart default store is **`0`**). Token and shop cache rows are scoped with `OpenCartStoreScope` (`store_id >= 0`). Credential invalidation for store `0` must not touch store `1` and vice versa.

## Live smoke prerequisites

1. `config/environment.php` with valid `control_panel_url`
2. `keys/*.pem` + `secrets/smartucf-key.php` (Phase 2 deployment; not required for CP login alone)
3. Admin: UNICID + shop secret → native OpenCart **Save**
4. **Обнови данните от банката** → success alert + `mt_uni_credit_shop_cache` row for `store_id = 0`
5. Diagnostics panel shows cache timestamps / connection state
6. Storefront Product calculator can consume the refreshed cache

Do **not** create financing orders.

## Out of scope (Phase 5+)

Calculator implementation: see `docs/PHASE5.md`. Still out of scope here: cart/product UI, checkout payment, CP orders, SmartUCF, callbacks.
