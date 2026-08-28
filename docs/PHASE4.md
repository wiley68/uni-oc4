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

## Token storage

- **Не** plaintext bearer в admin/logs
- Store-scoped `oc_setting` keys:
  - `module_mt_uni_credit_cp_access_token` (AES-256-GCM, prefix `enc:v1:`)
  - `module_mt_uni_credit_cp_token_type`
  - `module_mt_uni_credit_cp_token_expires_at`
- Cipher key derived from deployment CP secret (`secrets/cp-auth.php`) — същият източник като login secret

## Credentials

| Material             | Source                                             |
| -------------------- | -------------------------------------------------- |
| UNICID               | admin setting `module_mt_uni_credit_unicid`        |
| CP API secret        | deployment file `secrets/cp-auth.php` (gitignored) |
| Remote shop snapshot | CP `GET /shop` → `mt_uni_credit_shop_cache`        |

PS9 difference: PS9 encrypts secret in PrestaShop Configuration; OC4 Phase 4 keeps CP secret in deployment file (Phase 2 policy) while UNICID remains admin-editable.

## Shop cache

- TTL: **86400s** (`SecurityConstants::SHOP_CACHE_TTL_SECONDS`)
- Full snapshot validation via ported `ShopConfigurationSnapshotValidator`
- Invalid remote snapshot **does not** overwrite known-good cache
- Permanent auth/4xx/invalid payload → purge scoped cache + tokens
- Transient 5xx/network → preserve cache + tokens

## Canonical shop URL

- `CanonicalShopUrlProvider` — HTTPS, no trailing slash, prefers `config_ssl` over `config_url`

## Admin

- Safe fields: CP host, auth state, token expiry timestamp (not raw token), cache metadata
- POST actions (modify permission + user token): connect, refreshShop, disconnect
- UNICID editable; secret file presence only (yes/no)

## Credential change

- `CredentialChangeHandler` invalidates tokens + scoped cache for previous/new UNICID on admin save

## Live smoke test (optional)

1. Deploy `secrets/cp-auth.php` with dev secret
2. Set UNICID in admin
3. Connect → verify auth state + token expiry shown (not token value)
4. Refresh shop → cache metadata populated
5. Second read serves cache without extra CP call
6. Disconnect → auth state disconnected
7. Re-connect

Do **not** create financing orders.

## Out of scope (Phase 5+)

Calculator, cart/product UI, checkout payment, CP order create/update, SmartUCF, callbacks, financing snapshots.
