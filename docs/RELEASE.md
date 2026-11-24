# Release — UniCredit OpenCart 4 (`mt_uni_credit`)

## Prepared version: v2.0.5

UNI-OC4-PROCESS2-FIELDS-001 / RELEASE-PREP-01 prepares the approved IMP-01 implementation and the official local installer. The current working-tree version is **2.0.5**. Source changes remain uncommitted and unstaged; no commit, push, tag, GitHub Release or deployment is authorized by this preparation.

Process 2 Product, Cart and native Checkout no longer collect EGN or secondary phone. New attempts leave sensitive ciphertext SQL NULL; handoff, historical presentation and mail retry do not decrypt retired values. Merchant/Admin presentation omits them for every audience, and diagnostic write/read/export redacts legacy aliases. Process 1 and the CP protocol remain unchanged apart from the declared module version sent to CP. See [PHASE11B.md](PHASE11B.md).

IMP-01 automated evidence is reused: 788 PASS, 76 SKIP, 0 FAIL; 29 new regression tests / 627 assertions; frontend harnesses, syntax, source inventory and 12-scenario CP payload parity passed at version 2.0.4. This is not OC4 browser, database, mail-delivery or bank acceptance. PHP 8.2/8.4 and the real OC4 runtime passes remain outstanding in this environment. Preparation-specific version and distribution checks are reported separately.

The prior published release remains v2.0.4 (UNI-OC4-ADS-003). Its historical owner acceptance and release record are preserved in [RELEASE-NOTES-v2.0.4.md](RELEASE-NOTES-v2.0.4.md).

Authoritative runtime source:

```text
ModuleConstants::VERSION = '2.0.5'
```

Also aligned:

- `install.json` → `version`
- CP create payload → `version` (via `ModuleConstants::VERSION`)
- Admin health/metadata that exposes module version

### Asset cache busting (independent)

JS/CSS URLs use `filemtime(asset)` via `ModuleAssetVersion`. Do **not** bump `ModuleConstants::VERSION` to bust browser/CDN caches. Missing-file fallback may use the module version string; that is not the primary cache key.

### Release publication

No Git publication or deployment is performed in RELEASE-PREP-01. A future release requires a separate explicit instruction. The local installer is generated from the reviewed working tree, not claimed to originate from a published v2.0.5 commit/tag.

### Schema / upgrades

v2.0.5 adds no schema changes, migrations or install/uninstall behavior changes. Preserve the installed shop's database, settings, keys and runtime data; do not run install/uninstall hooks as release validation.

Historical schema context: Phase 11A added idempotent `smartucf_*` columns through `PersistenceSchemaInstaller::installAll()`. Phase 11B added `process2_state` / `process2_sensitive_enc` / `process2_mail_sent` the same way. These were part of the original development line.

Phase 12B does **not** add upgrade scripts. Presentation retention, Thank You identity, and event retired-code cleanup are runtime/install-sync behavior on the existing schema.

Historical Final Audit Remediation 01 added no upgrade scripts. The v2.0.5 preparation preserves schema/install hooks and historical ciphertext; new Process 2 records do not populate that ciphertext. Existing retention remains unchanged.

Historical release notes for v2.0.2 and v2.0.4 remain intact; the current preparation status is documented here.

### Installer packaging

Run `composer package` to generate exactly `dist/mt_uni_credit.ocmod.zip`.
See [PACKAGING.md](PACKAGING.md) for the OpenCart 4.x layout, input requirements,
standalone verification and regression checks. `dist/` and the local
`secrets/smartucf-key.php` remain ignored and untracked. The ZIP contains the
SmartUCF secret and is a sensitive deployment artifact: **do not commit it**.
Packaging preserves `config/environment.php` and the local key byte-for-byte.
