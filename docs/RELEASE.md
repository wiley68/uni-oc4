# Release — UniCredit OpenCart 4 (`mt_uni_credit`)

## Current release: v2.0.4

UNI-OC4-ADS-003 releases the accepted UNI-OC4-ADS-001 advertising change. The owner reported OC4-01 through OC4-08 **8/8 PASS**; UNI-OC4-ADS-002 completed the functional/security source audit. See [RELEASE-NOTES-v2.0.4.md](RELEASE-NOTES-v2.0.4.md).

The current release version is **2.0.4**. Keep it unchanged during subsequent audits and fixes unless the owner explicitly authorizes another release. The original development freeze applied to v2.0.2; v2.0.2 and v2.0.3 remain historical releases.

Authoritative runtime source:

```text
ModuleConstants::VERSION = '2.0.4'
```

Also aligned:

- `install.json` → `version`
- CP create payload → `version` (via `ModuleConstants::VERSION`)
- Admin health/metadata that exposes module version

### Asset cache busting (independent)

JS/CSS URLs use `filemtime(asset)` via `ModuleAssetVersion`. Do **not** bump `ModuleConstants::VERSION` to bust browser/CDN caches. Missing-file fallback may use the module version string; that is not the primary cache key.

### Release publication

The authorized v2.0.4 workflow is one release commit on `main`, a normal push, and an annotated `v2.0.4` tag. Verify the remote branch, tag object and peeled commit, then regenerate and verify `dist/mt_uni_credit.ocmod.zip` from that exact published commit. A GitHub Release and public installer upload are outside UNI-OC4-ADS-003 authorization.

### Schema / upgrades

v2.0.4 adds no schema changes, migrations or install/uninstall behavior changes. Preserve the installed shop's database, settings, keys and runtime data; do not run install/uninstall hooks as release validation.

Historical schema context: Phase 11A added idempotent `smartucf_*` columns through `PersistenceSchemaInstaller::installAll()`. Phase 11B added `process2_state` / `process2_sensitive_enc` / `process2_mail_sent` the same way. These were part of the original development line.

Phase 12B does **not** add upgrade scripts. Presentation retention, Thank You identity, and event retired-code cleanup are runtime/install-sync behavior on the existing schema.

Final Audit Remediation 01 likewise adds no upgrade scripts: Process 2 encryption fail-closed, exact store-scoped bank status, and bounded diagnostic prune (+ idempotent `created_at` index ensure) stay on install/reinstall / `PersistenceSchemaInstaller::installAll()`.

Historical notes remain in `docs/RELEASE-NOTES-v2.0.2.md`; they do not declare the current version. Current operator-facing notes are `docs/RELEASE-NOTES-v2.0.4.md`.

### Installer packaging

Run `composer package` to generate exactly `dist/mt_uni_credit.ocmod.zip`.
See [PACKAGING.md](PACKAGING.md) for the OpenCart 4.x layout, input requirements,
standalone verification and regression checks. `dist/` and the local
`secrets/smartucf-key.php` remain ignored and untracked. The ZIP contains the
SmartUCF secret and is a sensitive deployment artifact: **do not commit it**.
Packaging preserves `config/environment.php` and the local key byte-for-byte.
