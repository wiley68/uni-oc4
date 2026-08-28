# Phase 1 — Extension skeleton and install foundation

Phase 1 adds the OpenCart 4.x **admin module shell** and install/uninstall wiring. Business/financing behavior remains in later phases. Frozen contracts: `docs/CONTRACTS.md`.

## Extension identity

| Item                  | Value                                           |
| --------------------- | ----------------------------------------------- |
| Package code          | `mt_uni_credit`                                 |
| Version               | `2.0.2`                                         |
| Setting code          | `module_mt_uni_credit`                          |
| Admin route           | `extension/mt_uni_credit/module/mt_uni_credit`  |
| Future payment route  | `extension/mt_uni_credit/payment/mt_uni_credit` |
| Future payment option | `mt_uni_credit.mt_uni_credit`                   |

Constants live in `system/library/module_constants.php` (`ModuleConstants`).

## Namespace and autoloading

OpenCart 4.1.0.3 registers extension classes at startup:

- `Opencart\Admin\Controller\Extension\MtUniCredit\` → `admin/controller/`
- `Opencart\Admin\Model\Extension\MtUniCredit\` → `admin/model/`
- `Opencart\System\Library\Extension\MtUniCredit\` → `system/library/`

File names follow OpenCart's snake_case autoloader (e.g. `OpenCartCompatibility` → `open_cart_compatibility.php`).

**Decision:** no runtime Composer autoload for production. Native OpenCart autoloading is sufficient. Composer/dev PHPUnit uses a test bootstrap autoloader mirroring the same namespace → `system/library/` mapping.

## `install.json` vs runtime install

Marketplace installer reads metadata only (`name`, `code`, `version`, `author`, `link`, …). Fields `type`, `status`, `install`, `uninstall` are **descriptive/legacy** and are not executed by OpenCart 4.1.0.3.

Actual lifecycle:

1. Extensions → Modules → Install calls `extension/mt_uni_credit/module/mt_uni_credit.install`
2. Uninstall calls `.uninstall` on the same controller
3. Core also adds/removes `access`/`modify` permissions for the installing admin group

## Install / uninstall (Phase 1)

**Install** (`admin/model/module/mt_uni_credit.php`):

- Seeds default settings (`module_mt_uni_credit_status = 0`) if none exist
- Syncs event registry (Phase 1: zero events)

**Uninstall**:

- Removes events registered by `EventRegistry` only
- Does **not** drop tables or purge financing data (none exist yet)
- Module settings removed by OpenCart core (`deleteSettingsByCode('module_mt_uni_credit')`)

Both paths are idempotent (`deleteEventByCode` before `addEvent`; defaults only when empty).

## Event compatibility

`OpenCartCompatibility` centralizes:

- Event action separator: `.` when `VERSION >= 4.0.2`, else `|`
- `eventAction(controller, method)` for `oc_event.action`
- `adminRoute(controller, method)` for admin URL links (`.` suffix)

`EventRegistry` holds deterministic event definitions. Phase 1 returns an empty list; feature phases append scoped codes prefixed with `module_mt_uni_credit_`.

## Admin configuration (Phase 1)

- Enable/disable module (`module_mt_uni_credit_status`)
- Neutral health panel (version, code, registered event count)
- No CP URL, secrets, certificates, cache or SmartUCF controls

Languages: `admin/language/bg-bg/module/mt_uni_credit.php`, `admin/language/en-gb/module/mt_uni_credit.php`.

Phase 2 extends the health panel with deployment statuses — see `docs/PHASE2.md`.

## Remediation: no stubs in scanned component directories

Manual testing on OpenCart 4.1.0.3 confirmed that `admin/controller/module/index.php` (generic exit stub) was glob-discovered as a second module with code `index`, breaking Extensions → Modules. **Never** add generic `index.php` files under `admin/controller/{type}/`.

## Security baseline

OpenCart 4.1 **glob-scans** `admin/controller/{type}/*.php` for extension discovery (Modules, Payments, etc.). Every `.php` basename becomes a component code (e.g. a stray `index.php` is discovered as module code `index` and can break Extensions → Modules).

**Do not** place generic `index.php` exit stubs in:

- `admin/controller/module/` (and other scanned `admin/controller/{type}/` dirs)
- `admin/model/module/` or `admin/language/*/module/` (companion paths for the same code)

Use instead:

- Root `.htaccess` blocks direct web access to `vendor/`, `tests/`, `docs/`, `scripts/`, `admin/`, and `system/`
- `index.php` stubs only in non-scanned paths (e.g. `docs/`, `tests/`) where they cannot be interpreted as OpenCart components
- Admin actions require OpenCart user token + `access`/`modify` on the module route

## Explicitly not in Phase 1

Database schema, CP client, calculator, Product/Cart/Checkout UI, payment submission, Process 1/2, SmartUCF, emails, inbound callbacks, admin financing diagnostics.
