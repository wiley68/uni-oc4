# UniCredit OpenCart 4 — Release notes (v2.0.4)

**Module version:** `2.0.4`
**Target:** OpenCart 4.1.0.3 · PHP 8.2–8.4

## Advertising

- Advertisement visibility is controlled centrally by the existing CP `uni_container_status`, together with the existing `uni_status` shop restriction.
- The merchant advertising checkbox and its help text are removed from module settings.
- The historical `module_mt_uni_credit_advertising_enabled` value (0, 1 or absent) no longer affects banner markup or asset inclusion. Existing records survive settings saves; no destructive cleanup or migration is added.
- Homepage context, module activation, store-scoped UNICID, advertising content, links, images, Twig, JavaScript and CSS remain unchanged.
- CP presentation-cache TTL remains 86400 seconds. The existing refresh, authenticated push, manual bank-data refresh and bounded last-known-good fallback (up to 21600 seconds after expiry under eligible conditions) are preserved. CP changes remain subject to that cache policy.

## Scope and compatibility

No financing formulas, Product/Cart/Checkout financing flows, order statuses, bank/email protocols, CP API contracts, authentication, installation/uninstallation logic, database schema or stored shop settings are changed by release preparation. BORICA and other extensions are outside this release. No new production dependencies are introduced.

The authoritative version remains `ModuleConstants::VERSION`, aligned with `install.json`. Current-version fixtures/assertions are updated to 2.0.4; historical examples and the deliberately invalid versioned ZIP filename fixture remain unchanged. Historical v2.0.2/v2.0.3 records are retained.

## Acceptance and validation

Owner browser acceptance for UNI-OC4-ADS-001: **OC4-01 through OC4-08 — 8/8 PASS**. UNI-OC4-ADS-002 completed the functional/security ReadOnly audit. Automated release checks use synthetic fixtures, no live SQL configuration or bank operations.

| Release validation | Result |
| --- | --- |
| Centralized advertising and previously audited eight-suite selection | PASS: 41 tests / 1703 assertions on PHP 8.2.35, and separately on PHP 8.4.27 |
| Safe admin UI/refresh contracts | PASS: 2 tests / 29 assertions on PHP 8.4 |
| Current-version methods selected across all 27 updated test files | 29 PASS, 1 SKIP / 95 assertions on PHP 8.4 |
| Existing packaging suite with private Git fixtures and a synthetic key | PASS: 67 tests / 1413 assertions on PHP 8.4 |
| PHP syntax | PASS: 39 changed PHP files and 3 packaging scripts on PHP 8.2/8.4; existing full production lint: 298 files on PHP 8.2 |
| Advertising JavaScript; admin/advertising Twig; metadata/manifest/JSON fixtures | PASS; Twig cache disabled |
| Composer strict validation and `git diff --check` | PASS |
| Provisional standard installer | PASS: builder and standalone verifier, 343 files; source inputs preserved |

The skipped PHPUnit method is `Phase10BCheckoutCpFailureVisibilityTest::testCheckoutModelWiresFailureVisibilityStatus`: its pre-existing setup guard skips when DB integration is disabled, although the method itself only checks source strings and the current version. That exact pure method was additionally invoked directly with DB integration disabled and without DB setup: PASS. No test guard or assertion was weakened. The commands above overlap; their counts are not a distinct-test total.

The default project PHPUnit configuration enables DB integration and was not used. Explicit safe selections used `--no-configuration`, `MT_UNI_CREDIT_INTEGRATION=0` and disabled result caching. Tools and validation logs stayed under `/tmp/uni-oc4-ads003-*` and the previously prepared `/tmp/uni-oc4-ads001-*`; temporary packaging fixtures were cleaned. Live DB integration, live install/uninstall, bank requests and credit applications were not run.

## Installer and delivery

Build with `composer package`; verify with `php scripts/verify-package.php dist/mt_uni_credit.ocmod.zip`.

The output remains **`dist/mt_uni_credit.ocmod.zip`**, with root `install.json`, `.htaccess`, `admin/`, `catalog/`, `system/`, `config/`, `keys/` and `secrets/`, without an enclosing module/extension/upload directory. The unchanged manifest has 331 source files; generated `bg` and `bulgaria` aliases produce 343 archive entries. Tests, docs, Git/IDE files, Composer development tooling and local certificate/runtime state are excluded.

`config/environment.php` and the local `secrets/smartucf-key.php` are preserved byte-for-byte by the builder. The installer contains the deployment secret: keep the ZIP out of Git and public uploads. Final packaging follows normal publication of one release commit and the annotated `v2.0.4` tag; no GitHub Release is created.

Do not install this package into the active DEV shop as part of release validation. OpenCart 4's installer can skip existing files; any separately approved deployment must follow the controlled replacement and source/ZIP/installed-byte verification described in [PACKAGING.md](PACKAGING.md).

## Environment limitation

Direct SQL installer/module/event state remains unverified because the execution environment denies DB socket access with EPERM. No credentials, DB permissions, caches, modifications or services are changed to work around this limitation.

## Release file inventory

48 files relative to baseline `faf1c83cd31810abd08e95c53e3a266be6b96796`: 12 production/installer metadata files, 29 PHP test files, 2 JSON fixtures and 5 documentation files. All accepted runtime files are byte-identical to the owner-approved ADS-001 implementation; release preparation changes production only in the two authorized version declarations.

```text
admin/controller/module/mt_uni_credit.php
admin/language/bg-bg/module/mt_uni_credit.php
admin/language/en-gb/module/mt_uni_credit.php
admin/model/module/mt_uni_credit.php
admin/view/template/module/mt_uni_credit.twig
catalog/controller/event/mt_uni_credit_home_controller.php
catalog/controller/event/mt_uni_credit_home_view.php
docs/CONTRACTS.md
docs/PHASE11C.md
docs/RELEASE-NOTES-v2.0.4.md
docs/RELEASE.md
docs/UNI-OC4-ADS-001.md
install.json
system/library/homepage_advertising_context_resolver.php
system/library/homepage_advertising_gate.php
system/library/module_constants.php
system/library/module_local_settings.php
tests/BridgeAInboundApiContractTest.php
tests/CentralizedAdvertisingControlTest.php
tests/CpOrderPayloadContractTest.php
tests/DefinitiveCpCreateFailureRemediationTest.php
tests/ExtensionMetadataTest.php
tests/FinalAuditRemediation01Test.php
tests/ModuleVersionFreezeTest.php
tests/Phase10BCheckoutCpFailureVisibilityTest.php
tests/Phase10BLoggedCheckoutPaymentRenderTest.php
tests/Phase10BStaleCheckoutSessionOrderTest.php
tests/Phase11AFinalizationRemediationTest.php
tests/Phase11AProcess1ContractTest.php
tests/Phase11ARuntimeRemediationTest.php
tests/Phase11BPresentationParityTest.php
tests/Phase11BProcess2ContractTest.php
tests/Phase11CCheckoutLoaderParityTest.php
tests/Phase11CCheckoutProcess1RejectTest.php
tests/Phase11CCheckoutProcess1SuccessTest.php
tests/Phase11CHomepageAdvertisingTest.php
tests/Phase11CNoShippingCheckoutTest.php
tests/Phase11CProductBuyCheckoutHandoffTest.php
tests/Phase11CProductBuyCheckoutRerenderHandoffTest.php
tests/Phase11CProductBuySchemeCheckoutIntegrationTest.php
tests/Phase11CProductBuySchemePreselectTest.php
tests/Phase11CSmartUcfBusinessRejectTest.php
tests/Phase11CSmartUcfDebugJournalTest.php
tests/Phase11CSmartUcfTerminalFailureTest.php
tests/Phase1InstallContractTest.php
tests/Phase7AdminFinalizationTest.php
tests/fixtures/cp_order_payload.json
tests/fixtures/extension_metadata.json
```
