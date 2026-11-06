# UNI-CONSENT-001 / Phase 6B implementation report

## A. Baseline and worktree

- Repository: `wiley68/uni-oc4`, origin `git@github.com:wiley68/uni-oc4.git`.
- Branch: `main`; baseline and final HEAD: `7b271104a8bac98f2e6d7fc84af537b3125e87d1`.
- Initial worktree: clean. Implementation is left uncommitted; no reset, commit,
  push, merge, tag, deployment action or release build was performed.
- Module version remains `2.0.3`; dependency declarations/lockfile are unchanged.
- Source edits are confined to the authorized repository. Offline logs, temporary
  scripts and isolated fake packaging/runtime fixtures use `/tmp`; other application
  source and live customer/database data were not edited.

## B. Implemented architecture

Three new runtime libraries provide one canonical evidence codec (`ConsentContext`),
independent all-matching-row interest authority (`ConsentInterestAuthority`), and
shared durable effect guards/finalization (`DurableConsentAuthority`). Existing
issuers, submission services, order/CP/bank coordinators, recovery and frontend
flows consume that authority. A separate commerce hash binds actual product,
quantity, option and native amount identity without changing existing operation
or selection hashes.

Detailed architecture, invariants and rollout procedure:
[UNI-CONSENT-001-OC4.md](UNI-CONSENT-001-OC4.md).

## C. Schema changes — code only

One nullable `MEDIUMTEXT consent_context_json` column on the existing financing
attempt table. Existing install/upgrade mechanisms add it idempotently and verify
exact name/type/nullability. Incompatible storage fails closed without conversion.
No backfill, new production table, new state or live schema execution occurred.
New issuance requires an evidence seed; even the older Checkout issuance wrapper
now issues a token and v1 evidence. SQL NULL remains a historical classification.

## D. Issued/finalized lifecycle

`UNI-CONSENT-001/oc4`, version `1`, with deterministic canonical JSON/hashes and
strict nested types/version rejection. Issued context is in the original attempt
INSERT and represents no acceptance. A short transaction performs conditional,
write-once finalization against exact issued bytes/state/bindings before order,
sensitive preparation, CP or bank effects. The finalized receipt binds numeric
attempt ID, accepted mandatory IDs, applicable IDs and validation time. Stored A
is not rewritten by POST B. Uncertain writes reload/reconcile persisted evidence.
Scoped issued contexts revalidate raw authority before finalization; all-only and
empty contexts gain no raw-interest-specific block. Finalized continuation uses A,
including the finalization-before-order crash window.

## E. Historical compatibility

Issued v1, finalized v1, legacy SQL NULL and malformed/unsupported evidence are
explicitly distinguished. Legacy unbound issuance requires a fresh v1 attempt.
Bound/CP-created legacy attempts cannot use current acceptance to create new
financial effects. Authorized recorded SmartUCF-created and Process 2-prepared
outcomes remain retrievable through read-only branches. Those branches perform no
new create/PATCH/mail/native-history repair, including Checkout's final status
application. Malformed/wrongly bound A never becomes submitted B. No historical
accepted IDs or new re-consent workflow were fabricated.

## F. Concurrency and retry protection

Existing operation leases plus short locking transactions serialize receipt
finalization. Product/Cart competition is actor-scoped over overlapping issuance
windows; unresolved winners survive expiry. Resolved winners do not impose a
permanent global Product/Cart winner on later non-overlapping windows. Checkout
competition protects its native order. Same-token/CAS losers cannot replace the
first receipt. The lease is released before materialization's separate acquisition.
Existing CP ambiguity, correlation/attach and SmartUCF claim/idempotency controls
remain. Native addOrder/correlation and SMTP send/mark-sent crash windows remain;
complete exactly-once behavior is not claimed.

## G. Product / Cart / Checkout frontend

Each flow independently applies issued descriptors/fingerprint, hides entire
non-applicable rows, clears/disables hidden checkboxes and preserves informational
links. All-scope acceptance stays within the form; real scheme/KOP/month changes
reset scoped acceptance, including A→B→A. First-installment changes invalidate
readiness immediately and preserve acceptance only after a matching response.
Revision/sequence checks ignore stale successes, failures and completion paths.
Timers are cancelled on close/teardown, remounts start without authority, and
detached Checkout roots cannot trigger old actions. Pending/error/stale/unknown
scoped contexts block Apply/submit. Missing mandatory DOM rows require refresh.
Preview cancellation never aborts irreversible submits.

## H. CSS applicability and visibility

Both actual root-scoped stylesheets now override consent-row flex display with
`[hidden] { display: none !important; }`. Initial hidden checkboxes are disabled.
The deterministic Node harness verifies the existing flex declaration and the
root-specific important hidden rule as well as lifecycle/applicability behavior.
Actual browser/theme rendering is SKIP in this phase.

## I. Direct service and Process 1/2 guards

Guards cover Product/Cart materialization, direct materializers, Checkout reuse
and native history/mail application, CP create/retry/CP_CREATED continuation,
Process 1 coordinator/client, Process 2 sensitive preparation/claims/coordinator,
production status admission/PATCH and mail. Actual frozen scheme type, KOP,
months/filter, amounts and commerce must agree; same-total substitutions refuse.
Native EUR verification remains required. Status PATCH cannot target another
order or the opposite frozen process. Refusals escape broad banking/mail failure
classifiers and visibility repairs. Financial formulas and bank payload builders
remain byte-unchanged.

## J. Retention

Finalized consent evidence stays with its owning attempt during development.
Presentation redaction (183 days) and Process 2 ciphertext redaction (180 days)
do not erase it. Ordinary pre-order cleanup retains finalized, malformed,
unsupported and wrongly bound evidence; abandoned issued-only rows use exact-byte
CAS pruning to survive a racing finalization. Frozen evidence plus native EUR and
product/option rows supports new-format recovery after presentation redaction.
No legal consent retention duration is asserted. No live cleanup was run.

## K. Exact files changed/created

79 modified files; 12 new files; 91 total. `M` = modified, `A` = new.

```text
M catalog/controller/module/mt_uni_credit_cart.php
M catalog/controller/module/mt_uni_credit_product.php
M catalog/controller/payment/mt_uni_credit.php
M catalog/model/module/mt_uni_credit_checkout.php
M catalog/view/javascript/mt_uni_credit_cart.js
M catalog/view/javascript/mt_uni_credit_checkout.js
M catalog/view/javascript/mt_uni_credit_product.js
M catalog/view/stylesheet/mt_uni_credit_checkout.css
M catalog/view/stylesheet/mt_uni_credit_product.css
M catalog/view/template/module/mt_uni_credit_cart_modal.twig
M catalog/view/template/module/mt_uni_credit_product_modal.twig
M catalog/view/template/payment/mt_uni_credit.twig
M docs/ARCHITECTURE.md
M docs/PACKAGING.md
M docs/RECOVERY.md
M docs/SECURITY-OPERATIONS.md
A docs/UNI-CONSENT-001-OC4.md
A docs/UNI-CONSENT-001-REPORT.md
M scripts/package-files.json
M system/library/cart_financing_submission_service.php
M system/library/cart_submission_issuer.php
M system/library/checkout_existing_order_gateway.php
M system/library/checkout_financing_submission_service.php
M system/library/checkout_order_customer_adapter.php
M system/library/checkout_submission_issuer.php
A system/library/consent_context.php
A system/library/consent_interest_authority.php
M system/library/consent_resolver.php
M system/library/control_panel_order_lifecycle_service.php
M system/library/control_panel_status_sync_repository.php
M system/library/control_panel_status_sync_service.php
A system/library/durable_consent_authority.php
M system/library/durable_eur_order_guard.php
M system/library/durable_eur_resume_hydrator.php
M system/library/financing_attempt_repository.php
M system/library/financing_control_panel_completion.php
M system/library/open_cart_order_materializer.php
M system/library/order_materialization_service.php
M system/library/persistence_schema_installer.php
M system/library/post_control_panel_lifecycle_service.php
M system/library/process_two_lifecycle_coordinator.php
M system/library/process_two_lifecycle_repository.php
M system/library/process_two_submission_support.php
M system/library/product_financing_submission_service.php
M system/library/product_submission_issuer.php
M system/library/smart_ucf_session_client.php
M system/library/smart_ucf_session_coordinator.php
M system/library/validated_financing_submission.php
M tests/CanonicalCreate401NoReplayTest.php
A tests/ConsentBoundaryTest.php
A tests/ConsentDomainTest.php
A tests/ConsentFrontendTest.php
A tests/ConsentPersistenceTest.php
M tests/DefinitiveCpCreateFailureRemediationTest.php
M tests/EurDurableProvenanceTest.php
M tests/EurRemediationBehaviorTest.php
M tests/FinalAuditRemediation01Test.php
M tests/Phase10AProductOrderCreationIntegrationTest.php
M tests/Phase10BCheckoutCpFailureVisibilityTest.php
M tests/Phase10BControlPanelLifecycleTest.php
M tests/Phase10BOrderStatusSourceTest.php
M tests/Phase11ACertificateSyncTest.php
M tests/Phase11AProcess1ContractTest.php
M tests/Phase11BProcess2ContractTest.php
M tests/Phase11CCheckoutProcess1RejectTest.php
M tests/Phase11CCheckoutProcess1SuccessTest.php
M tests/Phase11CNoShippingCheckoutTest.php
M tests/Phase11CSmartUcfBusinessRejectTest.php
M tests/Phase11CSmartUcfDebugJournalTest.php
M tests/Phase11CSmartUcfTerminalFailureTest.php
M tests/Phase3FinancingAttemptRepositoryIntegrationTest.php
M tests/Phase3StoreScopeZeroIntegrationTest.php
M tests/Phase6OpenCartOrderIntegrationTest.php
M tests/Phase6OrderMaterializationIntegrationTest.php
M tests/Phase6OrderMaterializationTest.php
M tests/Phase6ValidatedSubmissionTest.php
M tests/Phase78ProductStep2LifecycleContractTest.php
M tests/Phase7AttemptAndSubmitTest.php
M tests/Phase7ProductStep2UxContractTest.php
M tests/Process1ReplayLocalBeforePatchTest.php
M tests/ProcessTwoMailClaimTokenTest.php
M tests/ProductPopupRedirectRegressionTest.php
M tests/SchemaPostconditionsRemediationTest.php
M tests/SmartUcfCredentialHardeningTest.php
A tests/Support/ConsentFakeDb.php
A tests/Support/ConsentFixture.php
M tests/Support/OrderMaterializationTestHarness.php
M tests/Support/PersistenceIntegrationHarness.php
M tests/Support/ProductFinancingTestHarness.php
M tests/Support/ScopeGuardAllowlist.php
A tests/frontend/consent-lifecycle.cjs
```

## L. Offline validation

| Check | Result |
| --- | --- |
| Full offline PHPUnit, PHP 8.4.1 | **PASS** — 1174 tests, 14140 assertions, 0 failures/errors; 1002 executed passes, **172 SKIP** |
| PHPUnit deprecations | 5 baseline deprecations remain; no new count increase |
| PHP 8.2 syntax | **PASS** — all 75 changed/new PHP files |
| PHP 8.4 syntax | **PASS** — all 75 changed/new PHP files |
| JavaScript syntax | **PASS** — Product, Cart, Checkout |
| Deterministic frontend/CSS harness | **PASS** — all 3 flows, also included through `ConsentFrontendTest` |
| Explicit production manifest | **PASS** — 3 new runtime files listed, sorted/unique inventory; no ZIP built |
| Finance formulas / bank payload builders / operation and selection hash files | **PASS** — unchanged from baseline |
| Version / dependency files | **PASS** — unchanged, version 2.0.3; no installation |
| `git diff --check` | **PASS** |
| Real DB schema / integration / concurrent transactions | **SKIP** — not authorized for execution here |
| Browser/theme, real orders, mail and bank E2E | **SKIP** |
| Source = ZIP = installed = browser asset bytes | **SKIP** — required in a later release phase |

Executed full-suite command:

```bash
MT_UNI_CREDIT_INTEGRATION=0 php vendor/bin/phpunit --no-progress --log-junit /tmp/uni-consent-final.xml
```

`php` resolved to PHP 8.4.1. Syntax checks invoked `/usr/bin/php8.2 -l` and
`/usr/bin/php8.4 -l` for every changed/new PHP file and `node --check` for each
of the three modified flows. The frontend harness ran with
`node tests/frontend/consent-lifecycle.cjs`. No plain `composer test`, release
packaging command, live DB or bank-facing test was run.


Baseline comparison: offline PHPUnit initially reported 1106 tests, 13685
assertions, 172 SKIP and 5 PHPUnit deprecations. Existing success fixtures were
updated to carry explicit issued/finalized evidence; isolated native-order
fixtures also mirror product/option identity. No integration fixture was executed
against a database in this phase.

Negative assertions were preserved or strengthened. In particular, corrupt
UNICID/unbound placeholder cases now assert an early consent refusal plus zero
bank effects; obsolete submit-time reissuance expectations became pending-context
refusal checks; irreversible-submit cancellation protection remains asserted.
The mail non-owner token fixture now deterministically changes a token character
instead of occasionally producing the original token.

## M. Remaining risks and runtime requirements

- **SKIP:** live/isolated MySQL integration and real concurrency. Fake transactions,
  lost acknowledgements and deterministic interleavings do not prove actual
  InnoDB isolation/lease behavior. Separately authorize isolated storage execution.
- **SKIP:** browser/theme/OC4 remount rendering, native order/mail integration and
  bank-facing E2E. No real order, mail or bank request was made.
- Source and additive schema must be rolled out together in a controlled later
  phase. The development database intentionally has not been migrated here.
- Historical rows without evidence intentionally cannot start new banking work.
  This can require operator handling of unresolved old attempts; no replacement
  acceptance is inferred from present POST/configuration.
- Custom integrations calling issuance/services must supply the new server seed
  and durable evidence. Updated DB integration fixtures still need runtime review
  and execution; SKIP is not evidence that those suites pass.
- Existing native addOrder/correlation and SMTP crash windows remain. Integrity
  hashes do not protect against a malicious DB writer. Five baseline PHPUnit
  deprecations remain; they are not new test failures.
- OC4 installer overwrite behavior requires complete controlled file replacement,
  cache handling and later source/ZIP/installed/browser byte equality. No release
  ZIP or deployment verification is included in this phase.

## N. Readiness recommendation

Ready for **independent source/security review** after the offline checks below.
Not a release/deployment approval. Complete the separately authorized isolated
DB/concurrency, native order/mail, browser/theme and controlled banking matrix
before considering a release. Stop here with uncommitted implementation changes.


## Phase 6D — targeted audit remediation addendum

Baseline: Phase 6B's uncommitted 91 files (79 modified, 12 new), main at
`7b271104a8bac98f2e6d7fc84af537b3125e87d1`; module version 2.0.3 unchanged.
All original work is retained. This addendum supersedes the earlier broad test
coverage claims where the limitations below are narrower.

- **F01:** mandatory fresh persisted authority in both direct order gateways;
  caller snapshots alone cannot authorize. Actor/attempt/store/operation/selection,
  financing and EUR draft checks precede creation. No lease reacquisition.
- **F02:** mandatory authority independent of the CP status store's concrete class,
  checked before target admission and again before PATCH. Alternate-store positive
  fixtures use valid persisted receipts/native proofs; negative tests assert zero
  mutations/PATCH. Existing recorded-target retry semantics remain guarded; legacy
  SQL NULL outcomes remain read-only and acquire no invented acceptance.
- **F03:** malformed containers and mandatory descriptors refuse. No synthetic ID;
  optional unusable rows retain snapshot-validator skip semantics; scope fallback
  and strict accepted-ID rules remain intact.
- **F04:** explicit context-correct Twig escaping; all three complete templates are
  rendered with autoescape=false, parsed into DOMDocument, and checked for exact
  data-* values, link text/href and injection safety (36 render/parse combinations).
- **F05:** the two named integration suites finalize their actual issued contexts
  through production authority with matching DTO/process bindings. Order-status
  fixtures additionally mirror products/options into isolated native proof tables.
  Missing-secret assertions remain unchanged; an offline Process 2 test confirms
  that valid consent reaches encryption refusal with no writes or plaintext data.
- **F06:** production handlers now run with controlled timers and selection changes;
  modal close/reopen functions execute. The complete Checkout script runs against
  an EventTarget/DOM facade, including actual request payload, render/readiness,
  A→B→A, stale rejection/finally, detached response and root replacement. Product/Cart
  full-page mounting and browser CSS/theme rendering are **NOT RUN**; their other
  UI collaborators remain stubs. Fake DB CAS checks all significant predicates and
  exposes per-update affected-row results. It does not simulate/certify MySQL locks.

### Exact files changed beyond Phase 6B

34 files: 33 existing Phase 6D inputs adjusted, 1 new test file. Of these, six
previously baseline-clean tracked files now change; combined worktree inventory
is 98 files (85 modified, 13 new relative to Git HEAD). M/A below refer to the
Phase 6D starting snapshot, not Git HEAD.

```
M catalog/model/module/mt_uni_credit_cart.php
M catalog/model/module/mt_uni_credit_checkout.php
M catalog/model/module/mt_uni_credit_product.php
M catalog/view/template/module/mt_uni_credit_cart_modal.twig
M catalog/view/template/module/mt_uni_credit_product_modal.twig
M catalog/view/template/payment/mt_uni_credit.twig
M docs/UNI-CONSENT-001-OC4.md
M docs/UNI-CONSENT-001-REPORT.md
M system/library/checkout_existing_order_gateway.php
M system/library/consent_resolver.php
M system/library/control_panel_status_sync_repository.php
M system/library/control_panel_status_sync_service.php
M system/library/durable_consent_authority.php
M system/library/financing_control_panel_completion.php
M system/library/open_cart_order_materializer.php
M tests/ConsentBoundaryTest.php
M tests/ConsentFrontendTest.php
A tests/ConsentRemediationTest.php
M tests/ControlPanelStatusSyncServiceTest.php
M tests/CpOriginSwitchRemediationTest.php
M tests/FinalAuditRemediation01Test.php
M tests/Phase10BOrderStatusSourceTest.php
M tests/Phase11BProcess2ContractTest.php
M tests/Phase6OpenCartOrderIntegrationTest.php
M tests/Phase6OrderMaterializationTest.php
M tests/Process1ReplayLocalBeforePatchTest.php
M tests/SmartUcfCredentialHardeningTest.php
M tests/StatusSyncSecondCasMissTest.php
M tests/Support/ConsentFakeDb.php
M tests/Support/ConsentFixture.php
M tests/Support/OrderMaterializationTestHarness.php
M tests/Support/ProductFinancingTestHarness.php
M tests/Support/StatusSyncTestFactory.php
M tests/frontend/consent-lifecycle.cjs
```

### Validation

| Check | Final result |
| --- | --- |
| Broad reviewed offline suite, PHP 8.4.1 | 1140 total: **968 PASS, 172 SKIP**, 13317 assertions, zero errors/failures, 5 existing deprecations |
| Targeted suites, PHP 8.2 | 141 total: **133 PASS, 8 SKIP**, 920 assertions, zero errors/failures, 1 existing deprecation |
| Financial golden suites | 24 PASS, 132 assertions; fixtures/tests/formulas unchanged |
| CP order payload contract | 5 PASS, 26 assertions; payload builders/fixtures unchanged |
| Frontend Node harness | PASS within the explicitly documented facade/stub scope |
| PHP 8.2 / 8.4 syntax | All 82 changed/new PHP files PASS |
| JavaScript syntax / git diff --check | PASS |
| Packaging tests | 67 NOT RUN: explicitly excluded because they build ZIP fixtures |
| DB integration/concurrency, browser/theme, orders/mail/bank E2E | NOT RUN; skipped tests are not passes |
| ZIP / installed / served-asset parity | NOT RUN; no ZIP build or deployment |

Commands (no default integration-enabled configuration):

```bash
OPENCART_ROOT=/var/www/open40.avalonbg.com JET_OC4_ROOT=/var/www/open40.avalonbg.com/extension/mt_jet_credit MT_UNI_CREDIT_INTEGRATION=0 php vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php --do-not-cache-result --filter '/^(?!.*DistributionPackageTest::)/' --log-junit /tmp/uni-consent-6d-offline.xml tests
MT_UNI_CREDIT_INTEGRATION=0 /usr/bin/php8.2 vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php --do-not-cache-result tests/ConsentRemediationTest.php tests/ConsentBoundaryTest.php tests/ConsentPersistenceTest.php tests/ConsentDomainTest.php tests/ConsentFrontendTest.php tests/ControlPanelStatusSyncServiceTest.php tests/StatusSyncSecondCasMissTest.php tests/Phase6OrderMaterializationTest.php tests/FinalAuditRemediation01Test.php tests/Phase10BOrderStatusSourceTest.php
node tests/frontend/consent-lifecycle.cjs
```

Source manifests, dependencies, version, financial golden data, financial selector,
operation/selection hashes and CP/SmartUCF payload builders remain unchanged by 6D.
No database/schema operation, real order/mail/bank request, dependency installation,
ZIP build, deployment, staging or commit was performed. GO for Phase 6E independent
closure review; this is not runtime certification or release approval.
