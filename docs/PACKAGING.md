# OpenCart 4.x installer packaging

The canonical build command, run from the module project root, is:

```bash
composer package
```

The current local preparation is **2.0.5** (RELEASE-PREP-01). Both `install.json`
and `ModuleConstants::VERSION` must match before building. This preparation does
not imply completed OC4 browser/database/bank acceptance or a Git release.

It produces exactly `dist/mt_uni_credit.ocmod.zip`. The filename never includes a
version, date, environment or Git suffix. PHP 8.2–8.4 with `ext-zip`, Composer and
Git are required. Building does not require `composer install`: Composer is the
command runner, and this module has no Composer runtime dependencies. OpenCart
loads the extension's namespaced PHP libraries itself. Neither source `vendor/`
nor development autoload metadata is read, changed or shipped. New runtime
dependency/autoload declarations fail the build until packaging is audited.

Before building, manually select the existing `config/environment.php` and provide
the local `secrets/smartucf-key.php`. Both are copied byte-for-byte. The builder
does not execute either file, rewrite URLs, select an environment, or generate
`environment.dist.php`. The secret must be a readable regular file; a missing,
unreadable or symlinked file (including a symlinked parent directory) fails the
build. Its contents and hashes are never written to logs or a manifest.

`secrets/smartucf-key.php` remains local, ignored and untracked. `dist/` also remains
ignored and untracked. **The generated installer is a sensitive deployment
artifact because it contains the SmartUCF secret. Do not commit the ZIP or share
it publicly.** The build checks the Git inventory and both ignore rules, creates
new published files with shared mode `0664`, and copies the existing Apache deny
rules into `dist/.htaccess`. Existing directory/file ownership, modes and ACLs
are preserved; the builder never changes the existing `dist/` directory's access.
A new `dist/` uses `2775`, while staging remains private. Package protection files are preserved at `secrets/.htaccess`,
`keys/.htaccess` and the extension root. Apache installations must honor
`.htaccess`; other web servers need equivalent deployment access controls.

## Installer layout

The ZIP root contains `install.json`, `.htaccess`, `admin/`, `catalog/`, `system/`,
`config/`, `keys/` and `secrets/`. There is no enclosing `mt_uni_credit/`,
`unipayment/`, `extension/` or `upload/` directory. Upload the unchanged filename
through OpenCart's normal **Extensions → Installer** workflow, then enable the
module through OpenCart's extension management as usual. OpenCart derives the
extension directory code from `mt_uni_credit.ocmod.zip` and installs these entries
under `extension/mt_uni_credit/`. In particular, the installed deployment paths are
`extension/mt_uni_credit/config/environment.php` and
`extension/mt_uni_credit/secrets/smartucf-key.php`.

Compatibility was checked against the local OpenCart installer at
`admin4/controller/marketplace/installer.php` and the
[official OpenCart 4.1.0.3 installer source](https://github.com/opencart/opencart/blob/4.1.0.3/upload/admin/controller/marketplace/installer.php).
This extension uses event registrations and install/uninstall controller methods;
the source has no OCMOD XML to ship. Existing certificate PEM files, certificate
state and locks are deployment/runtime data and are excluded; only `keys/`'s
tracked protection files are included. The font license files are packaged
alongside the runtime fonts.

## Staging, inventory and verification

`scripts/package-files.json` is the audited, sorted runtime source manifest. It
contains paths only, with exactly one local-file exception:
`secrets/smartucf-key.php`. Explicitly reviewed manifest entries may be unstaged
new runtime files, allowing a package to be reviewed without staging or committing.
Adding a tracked runtime file without updating the manifest fails the build;
missing manifest files also fail. A tracked runtime file deleted from the working
tree and explicitly removed from the manifest may remain unstaged; it is excluded
without changing the Git index. A file still listed in the manifest must exist.
Tracked secrets or dist artifacts remain forbidden even when deleted locally. Unrelated untracked files are never selected.
When adding runtime files, review and update this manifest explicitly. Do not
automatically collect arbitrary local files or broaden secret inclusion.

Bulgarian translations have one authoritative source: `admin/language/bg-bg/`
and `catalog/language/bg-bg/`. Keep only these Bulgarian paths in the source
manifest. For every canonical file, the builder generates exact copies under
both `bg/` and `bulgaria/` in the staging tree and final ZIP. The resulting
installer supports all three OpenCart language codes without directory renaming.
Aliases are not stored in the source tree or maintained separately, and alias
paths in the source manifest are rejected. Newly audited `bg-bg` files, including
nested paths, automatically receive both aliases. English files are copied
unchanged.

The builder creates a private temporary staging tree under ignored `dist/`, copies
the audited files and generates the aliases, builds the ZIP there, verifies it,
and publishes the verified bytes under an exclusive file lock. Existing file
inodes are retained to preserve owner/group and named ACLs. Readers should take a
shared lock during publication; an unlocked reader or process termination during
publication can observe incomplete bytes. Reported write failures restore the
previous bytes. It removes the staging tree on success and ordinary exceptions.
A failed build before publication leaves the previous installer unchanged. A forcibly killed process
may leave a private `.build-*` directory that must be removed manually.

The verifier independently reopens the archive, checks its exact filename and
inventory, rejects unsafe paths, unexpected entries, ZIP symlinks and malformed
archives, and compares **every packaged file** byte-for-byte with the current
source (generated aliases are compared with their corresponding `bg-bg` source).
The expected ZIP inventory includes both aliases for every canonical Bulgarian
file. Missing alias directories or files, different bytes, renamed files and
unexpected alias entries fail verification and prevent publication of the build.
Secret comparisons report only pass/fail. No generated archive manifest
is needed: the reviewed source inventory and exact byte comparisons provide
manifest/source parity without adding installer metadata or exposing secret
hashes. Verification therefore requires the matching source checkout and local
deployment inputs; it is not an authenticity signature for arbitrary downloaded
archives.

Run standalone verification with:

```bash
php scripts/verify-package.php dist/mt_uni_credit.ocmod.zip
```

## Build timestamps and update verification

Entries have a sorted order, normalized Unix modes, and explicit compression.
The builder captures the current time once per package and gives every entry
that timestamp, including generated language aliases. It sets the process
`TZ` to UTC while writing and closing the archive, then restores the caller's
setting. The fixed January 2000 default and the `SOURCE_DATE_EPOCH` override
are retired. Source bytes, modes, and mtimes are preserved.

The builder checks the raw DOS timestamps in the staged archive's central
directory and local headers before publication. ZIP DOS timestamps carry no
timezone and represent seconds in two-second steps. Builds within one interval
can have identical entry timestamps. Use a reliable build clock and allow a
different two-second interval between changed packages for the same shop.
For an operator check, read entry times with `TZ=UTC unzip -l`. Archive tools
and installers can interpret the timestamps using the destination timezone or
assign new timestamps when placing files.

After updating, verify the installed PHP bytes and compare their filesystem
modification times with the prior installation. Confirm that changed PHP
files have distinguishable installed mtimes. Synthetic ZIP extraction and
file placement do not establish what an installed OpenCart instance will do.
Source-to-archive byte parity remains required. Record and verify the SHA-256
of each installer individually, for example with
`sha256sum dist/mt_uni_credit.ocmod.zip`, and retain the exact tested installer
with its checksum. Separate builds can have different hashes even when all
entry bytes match.

The owner archives only the standard DEV installer locally. The owner
personally unpacks it, changes the approved CP endpoint in
`config/environment.php`, repacks it, and delivers the bank-test and
production installers.

### Web PHP OPcache

Current archive timestamps reduce the stale-opcode risk. Automatic invalidation
still depends on how files are installed and on the web PHP configuration.
With `opcache.validate_timestamps=1`, allow for the configured
`opcache.revalidate_freq` interval before verifying the updated code. With
`opcache.validate_timestamps=0`, use the hosting provider's supported web PHP
cache invalidation or worker reload procedure. Verify using the serving web
PHP SAPI and its workers because CLI OPcache runs in a separate context.

## Regression checks

After installing development test dependencies, run the packaging regression tests:

```bash
MT_UNI_CREDIT_INTEGRATION=0 vendor/bin/phpunit --do-not-cache-result tests/DistributionPackageTest.php
```

Run the existing safe suite with database integration disabled:

```bash
MT_UNI_CREDIT_INTEGRATION=0 composer test
```

The packaging tests use private temporary Git fixtures and a synthetic key. They
cover the mandatory filename, root layout, complete runtime inventory, independent
extraction, deployment parity, missing/unreadable/symlinked inputs, protection,
excluded local/dev files, malformed/tampered archives, nonzero CLI failures,
cleanup, publication rollback, and current UTC timestamps. The tests
independently read the central-directory and local-header DOS timestamps, check
CRCs and permissions, and observe extraction and file placement across changed
builds.
Bulgarian regressions cover all three language variants in both admin and catalog,
identical relative file sets and bytes, rejection of missing/divergent/extra alias
files, automatic aliases for new canonical files, and preservation of English.

Also run `composer validate --strict`, PHP 8.2 lint for the packaging PHP files,
`composer lint:php82`, standalone verification and `git diff --check` before
distributing an installer. JavaScript assets are copied unchanged and participate
in full-file parity verification.

## UNI-CONSENT-001 controlled replacement

No package is built during Phase 6B. Later release verification must prove source bytes = ZIP bytes = installed bytes = browser-served assets. OC4 installer execution may leave existing files unchanged: use the quiesced, complete controlled replacement procedure in [UNI-CONSENT-001-OC4.md](UNI-CONSENT-001-OC4.md), preserving local secrets/keys/runtime data. Remember the OC3 stale-ZIP regression.
