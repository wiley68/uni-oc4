# Phase 3 — Persistence foundation

Phase 3 adds **four InnoDB tables**, centralized repositories, and idempotent install wiring. No CP HTTP, calculator, Product/Cart/Checkout UI, snapshots, or callbacks.

Frozen contracts: `docs/CONTRACTS.md`. Prior phases: `docs/PHASE1.md`, `docs/PHASE2.md`.

## Tables (Revision 1 — generalized model)

| Table                                     | Purpose                                                 |
| ----------------------------------------- | ------------------------------------------------------- |
| `{prefix}mt_uni_credit_shop_cache`        | Validated CP `/shop` snapshot cache (no live fetch yet) |
| `{prefix}mt_uni_credit_api_nonce`         | Replay-protection nonce **claims** (`sha256(nonce)`)    |
| `{prefix}mt_uni_credit_operation_lock`    | Short-lived mutex for product/cart/checkout             |
| `{prefix}mt_uni_credit_financing_attempt` | Generalized durable submission identity                 |

**Not created:** financing snapshot, SmartUCF log, bank status, token table, popup/checkout-specific tables.

## Keys and indexes

### `mt_uni_credit_shop_cache`

- PK: `shop_cache_id`
- UNIQUE: `(store_id, unicid)`
- INDEX: `expires_at`

### `mt_uni_credit_api_nonce`

- PK: `api_nonce_id`
- UNIQUE: `(store_id, unicid, nonce_hash)`
- INDEX: `expires_at`
- **Never stores raw nonce**

### `mt_uni_credit_operation_lock`

- PK: `operation_lock_id`
- UNIQUE: `(store_id, entry_point, operation_key_hash)`
- INDEX: `expires_at`
- Entry points: `product`, `cart`, `checkout` only (`OperationEntryPoint`)

### `mt_uni_credit_financing_attempt`

- PK: `attempt_id`
- UNIQUE: `submission_token` (nullable — multiple NULL allowed in MySQL)
- UNIQUE: `(store_id, order_id)` (nullable `order_id` — multiple NULL allowed)
- INDEX: `(store_id, entry_point, operation_key_hash, state)`
- INDEX: `(store_id, cart_id, state)`
- INDEX: `(state, updated_at)`
- INDEX: `expires_at`

## Install / uninstall

- **Install:** `PersistenceSchemaInstaller::installAll()` from `admin/model/module/mt_uni_credit.php::install()` — `CREATE TABLE IF NOT EXISTS`, safe to repeat.
- **Uninstall:** removes events/settings only — **does not DROP** persistence tables or financing rows.

## Repositories (`system/library/`)

| Class                        | Responsibility                                                                         |
| ---------------------------- | -------------------------------------------------------------------------------------- |
| `ShopCacheRepository`        | Validated replace, scoped delete, bounded expiry cleanup                               |
| `ApiNonceRepository`         | Atomic claim-once via UNIQUE insert                                                    |
| `OperationLockRepository`    | INSERT IGNORE + stale conditional UPDATE; owner-only release/heartbeat                 |
| `FinancingAttemptRepository` | Issue product/cart (token) / checkout (NULL token), CAS transitions, attach-once order |

Supporting: `DbConnection`, `OpenCartDbConnection`, `MysqliDbConnection`, `PersistenceSchemaInstaller`, `FinancingAttemptState`, `SecurityConstants`.

## Lock semantics

- Identity: `(store_id, entry_point, operation_key_hash)`
- Owner token: `bin2hex(random_bytes(16))` → 32 hex (`LockOwnerTokenGenerator`)
- TTL: **45 s** (`SecurityConstants::OPERATION_LOCK_TTL_SECONDS`)
- Acquire: `INSERT IGNORE` → if duplicate, `UPDATE … WHERE expires_at <= NOW` (atomic takeover)
- Release: `DELETE … AND owner_token = ?` (owner-only)

## Nonce semantics

- Format: 64 lowercase hex (Phase 0)
- Storage: `nonce_hash = sha256(nonce)`
- Retention: **900 s**
- Claim: plain `INSERT` — duplicate key → claim failed (replay)

## Attempt states

Centralized in `FinancingAttemptState`: `issued`, `validating`, `order_creating`, `order_created`, `cp_submitting`, `cp_created`, `cp_failed_retryable`, `cp_outcome_unknown`, `post_cp_processing`, `completed`, `terminal_failed`.

Transitions: `transition()` / `transitionFromStates()` — single atomic `UPDATE … WHERE state IN (…)`.

Submission token: `SubmissionTokenGenerator` — 64 hex, unique when present. Checkout uses `NULL`.

Order attach: `attachOrder()` — `UPDATE … WHERE order_id IS NULL OR order_id = ?`; UNIQUE `(store_id, order_id)` prevents two attempts owning one order.

## Multistore

`store_id` is an explicit non-negative OpenCart store id (`OpenCartStoreScope`):

- `0` = default store (valid scope)
- positive ids = additional stores
- negative ids are invalid and rejected
- missing/`null` scope is not silently coerced to `0` at the repository boundary — callers must pass an explicit store id from OpenCart context (`config_store_id`)

Nonces, cache, locks, and attempts are never resolved globally without store scope.

## Cleanup

Bounded `deleteExpiredBatch()` / `deleteExpiredPreOrderBatch()` — default limit 100. Failures must not corrupt active rows. No cron in Phase 3.

## Tests

- Schema contract: `Phase3SchemaContractTest`
- MySQL integration (prefix `oc_mtuni_it_`, env `MT_UNI_CREDIT_INTEGRATION=1`): nonce, lock, attempt, shop cache
- Scope guard: `Phase3ScopeGuardTest`

## Explicitly not in Phase 3

CP client, `/shop` fetch, calculator, storefront controllers, payment method, order creation, snapshot, SmartUCF, emails, inbound APIs, admin order financing panel.
