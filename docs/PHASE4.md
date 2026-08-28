# UniCredit OpenCart 4.x — Phase 4: CP Client, Auth, Shop Cache

Phase 4 добавя outbound Control Panel HTTP transport, login/refresh/logout, криптирано store-scoped token persistence, `GET /shop`, валидиран shop cache service и admin diagnostics.

## HTTP transport

- `CurlCpHttpTransport` — cURL, TLS verify ON, connect 5s, total 15s (`CpHttpConstants`)
- `FakeCpHttpTransport` — deterministic PHPUnit double
- `CpHttpResponse` + `CpException` hierarchy (transient vs permanent vs malformed)

## Auth lifecycle

- Login: `POST /api/v1/auth/login` with `{unicid, name, secret}`
- Refresh: `POST /api/v1/auth/refresh` (Bearer), ротира токена
- Logout: `POST /api/v1/auth/logout`, винаги локална invalidation в `finally`
- 401 flow: invalidate → re-auth/login → **exactly one** retry; втори 401 → permanent auth failure + invalidation

## Credentials (operator-configured)

| Material        | Source                                             | At rest                                 |
| --------------- | -------------------------------------------------- | --------------------------------------- |
| UNICID          | admin setting `module_mt_uni_credit_unicid`        | plaintext in `oc_setting`               |
| CP login secret | admin password field `module_mt_uni_credit_secret` | AES-256-GCM (`enc:v1:`) in `oc_setting` |
| Bearer token    | automatic after login                              | AES-256-GCM (`enc:v1:`) in `oc_setting` |

Operational model matches **uni-ps9** / **Woo mtunicredit**: operator enters UNICID + shop secret in admin; secret is never re-displayed. Empty password on save keeps the existing secret.

Deployment files (`secrets/smartucf-key.php`, PEM keys) are **only** for SmartUCF mTLS — not for CP login.

## Token / setting encryption key

- `ModuleEncryptionKeyProvider` derives stable key material from OpenCart installation constants (`DB_PREFIX`, `DB_DATABASE`, `DIR_STORAGE`)
- Same key encrypts CP secret and bearer token at rest
- **Decoupled** from CP login secret value (no extra deployment file)
- Role analogous to PrestaShop `_NEW_COOKIE_KEY_` in uni-ps9

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

## Live smoke prerequisites

1. `config/environment.php` with valid `control_panel_url`
2. `keys/*.pem` + `secrets/smartucf-key.php` (Phase 2 deployment; not required for CP login alone)
3. Admin: UNICID + shop secret configured
4. Connect → verify auth state + cache metadata
5. Refresh shop → cache populated
6. Disconnect → local token cleared

Do **not** create financing orders.

## Out of scope (Phase 5+)

Calculator, cart/product UI, checkout payment, CP order create/update, SmartUCF, callbacks, financing snapshots.
