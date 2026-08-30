# Release — UniCredit OpenCart 4 (`mt_uni_credit`)

## Development version freeze

```text
The UniCredit OpenCart module version is frozen at 2.0.2 for the entire current development cycle.

Do not increment it during implementation phases, bug fixes, audits, remediation, testing, or release preparation.

The first completed release of this development line will be tagged v2.0.2.

Only after tag/release v2.0.2 may subsequent development increment the module version.
```

Authoritative runtime source:

```text
ModuleConstants::VERSION = '2.0.2'
```

Also aligned:

- `install.json` → `version`
- CP create payload → `version` (via `ModuleConstants::VERSION`)
- Admin health/metadata that exposes module version

### Asset cache busting (independent)

JS/CSS URLs use `filemtime(asset)` via `ModuleAssetVersion`. Do **not** bump `ModuleConstants::VERSION` to bust browser/CDN caches. Missing-file fallback may use the module version string; that is not the primary cache key.

### After v2.0.2 release

Future increments (examples only, not scheduled):

```text
2.0.3
2.0.4
2.1.0
```

are allowed only after the `v2.0.2` tag/release exists.

### Schema / upgrades

Before first production release, schema changes use uninstall/reinstall. Do not add `upgrade-2.0.2.php` (or similar) solely because the version string is frozen.

Phase 11A adds idempotent `smartucf_*` columns through `PersistenceSchemaInstaller::installAll()`. This does not change the frozen module version. The release boundary includes Process 1 SmartUCF only; Process 2 execution remains unimplemented.
