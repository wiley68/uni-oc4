# UNI-CONSENT-001 / OpenCart 4 / Phase 6B

Implementation baseline: `wiley68/uni-oc4`, branch `main`, HEAD
`7b271104a8bac98f2e6d7fc84af537b3125e87d1`, initially clean worktree.
Module version stays `2.0.3`. Changes remain uncommitted for independent review.
This document describes source changes; it is not a deployment or release approval.
Earlier phase notes describing tokenless new Checkout attempts or evidence-free
submission are historical; new issuance uses the contract below.

## Persistence and codec

The sole schema addition is nullable `MEDIUMTEXT consent_context_json` on the
existing module-owned financing-attempt table. The existing installer adds it
idempotently and verifies the exact column name, type and nullability. An
incompatible existing column fails the postcondition; it is never converted.
There is no backfill, new table, new state, or change to existing operation or
selection hash algorithms. No schema command was executed against the shop.

`ConsentContext` is the single canonical codec for contract
`UNI-CONSENT-001/oc4`, integer version `1`. It sorts object keys recursively,
preserves numeric types, rejects noncanonical JSON, unsupported versions,
incorrect nested keys/types, invalid dates, invalid authority, and inconsistent
hashes. SHA-256 hashes detect accidental corruption and bind context/acceptance;
they are not a signature against a malicious database administrator.

A server seed contains frozen scheme type, KOP, months, filter, financing amounts,
first installment, GLP/GPR, raw interest classification/normalized matching rows,
applicable descriptors, mandatory/applicable IDs, existing Checkout order ID,
process choice, and a separate commerce hash. The commerce hash binds product
IDs, quantities, native prices/totals/taxes and canonical option identities
(including hashed option text). It is checked against the actual handoff draft
and the bound native order's product/option rows. Customer names, addresses,
contact data, EGN, telephone2 and banking credentials are not duplicated in the
evidence. Merchant consent descriptions/URLs are legal descriptors, not customer
fields.

Issued binding contains token, actor hash, store, entry point, operation and
selection hashes, UNICID, issue time and expiry. The unique token binds the issued
envelope to exactly one attempt row before its auto-increment ID is available;
finalization additionally binds the numeric attempt ID. The envelope is included
in the original INSERT. New issuance without a seed is rejected, including the
older `issueCheckoutAttempt` wrapper; that wrapper now also issues a token.
The schema remains nullable solely for historical rows.

## Classification and acceptance

`interest_scope` accepts `all`, `zero_interest`, `nonzero_interest`; other or
non-string values normalize to `all`. Mandatory descriptors have checkboxes;
informational descriptors remain links. Empty configuration adds no checkbox.

Consent classification independently inspects every raw coefficient matching
KOP/months. Both raw interest and coefficient must be finite numeric values;
the coefficient must be positive. Zero means `abs(interestPercent) <= 0.00001`.
Missing/invalid authority or conflicting zero/nonzero rows yields `unknown`,
independent of row order. Existing financial coefficient selection and formulas
are unchanged. Any scoped descriptor, including informational descriptors,
refuses unknown classification. All-only/empty configurations introduce no
interest-specific refusal.

Accepted IDs must be a list of positive integers or canonical decimal integer
strings. Malformed, duplicate, unknown, hidden/non-applicable or informational
IDs are refused. The accepted set must equal the applicable mandatory set.
Checkout's form adapter preserves these checks instead of ignoring malformed
consent fields.

## Issued → finalized

An issued envelope is not acceptance. After normal request validation and before
sensitive preparation, order materialization or banking effects:

1. Acquire the existing operation lease.
2. Start a short InnoDB transaction; inspect operation competitors using a
   locking read and verify the issued binding and exact actual handoff values.
3. For an issued scoped context, verify that current authoritative raw conditions
   still match the issued context, even when rounded GLP has not changed. Validate
   acceptance against persisted issued descriptors. All-only/empty contexts do
   not acquire a raw-interest drift block.
4. CAS-update the exact issued bytes with required state, unbound order, token,
   actor/store/operation/selection/UNICID and issue-window predicates.
5. Commit, then release the lease before the materializer acquires its own lease.

The finalized receipt contains accepted mandatory IDs, applicable IDs, validation
UTC time, numeric attempt ID, issued-context hash and acceptance integrity hash.
It is never rewritten. A losing/replayed request uses valid persisted A rather
than replacing it with POST B. A mismatched actual handoff is refused. An
uncertain update/COMMIT result is rolled back where possible, then reloaded and
reconciled against valid finalized evidence and competition. No transaction spans
native `addOrder`, HTTP, SmartUCF or mail. The operation lease is not reacquired
inside the same held operation.

## Competition and residual crash windows

Product/Cart competitors are scoped by store, entry point, unchanged operation
hash and actor. Overlapping issuance windows cannot both finalize. A finalized
unresolved winner remains protected after token expiry. Once a winner has a
recorded successful outcome or definitive terminal rejection, a genuinely later,
non-overlapping Product/Cart window can proceed. Different actors do not inherit
another customer's winner. Existing operation locks still briefly serialize
identical operation hashes across actors.

Checkout competition is order-scoped through its existing operation identity;
another token cannot finalize against that native order. Same-token replay and
cross-token losers cannot overwrite the first receipt. Malformed evidence and
unresolved historical bound/order-creating competitors fail closed. No permanent
global Product/Cart winner is introduced.

This does not provide complete exactly-once creation across the preexisting
native `addOrder` → correlation crash window. Existing correlation/attach,
45-second lease, CP idempotency/ambiguous-outcome rules and SmartUCF claims remain.
Remote outcome ambiguity still requires existing operator recovery; consent does
not authorize blind CP or bank retries. SMTP's send → mark-sent crash window also
remains. Deterministic fake interleavings do not prove MySQL lock/isolation
behavior under live concurrency.

## Historical compatibility and recovery

Evidence is explicitly classified as issued v1, finalized v1, SQL NULL legacy,
or malformed/unsupported. A legacy unbound issued attempt needs fresh issuance;
current POST acceptance cannot upgrade a historical bound or CP-created attempt.
Malformed or wrongly bound persisted A never falls back to POST B.

After existing actor/store/token/origin and native EUR/order checks, recorded
legacy SmartUCF-created redirects and Process 2-prepared outcomes remain readable.
Those legacy retrieval branches return without CP create, SmartUCF create, status
PATCH, local bank-status repair, or mail. This implementation does not automatically
admit a missing legacy target or add a re-consent workflow. No historical accepted
IDs are invented. Expiry does not erase the authorization checks for a bound
attempt; it does not free its unresolved winner for a new token.

New-format finalized continuation, including the finalization-before-order crash
window, uses frozen financing/process conditions, not
current POST selections or current consent configuration. Native-order EUR and
commerce verification remain mandatory. The resume hydrator uses finalized
consent conditions and native products/options/customer data after presentation
redaction; it no longer needs presentation JSON for new-format recovery.

## Actual effect boundaries

`DurableConsentAuthority` guards shared Product/Cart materialization, direct
materializers and Checkout reuse, CP create/retry and frozen payload comparison,
CP-created continuation, direct Process 1 coordinator/client, Process 2 sensitive
preparation and coordinator, production status admission/PATCH, and Process 2 mail.
The visible-order history boundary also requires evidence before native email
triggers. Consent refusals propagate before broad banking/mail error classifiers
and do not become bank failure statuses or visibility/mail repairs.

Financial comparisons include scheme type, KOP, months, filter, first installment,
all frozen amounts and GLP/GPR. Same-total/different-KOP/month/type substitutions
are refused. CP payload fields are compared before payload persistence/send;
status synchronization cannot target another order or the opposite frozen
process. Public bank payload schemas and financial formulas are unchanged.

## Product / Cart / Checkout UI

Each of the three JavaScript flows owns independent consent state. Server-issued
context/fingerprint determines applicability. Initial rows are hidden and their
checkboxes disabled. Non-applicable rows stay wholly hidden; their checkboxes
are cleared/disabled. Applicable informational rows remain links.

All-scope acceptance is preserved within the form. Real scheme/KOP/month changes
clear scoped acceptance immediately, including A→B→A. First-installment-only
changes invalidate readiness immediately but preserve acceptance after a matching
successful response. Applicable missing/mismatched mandatory DOM rows require a
refresh. Unknown-scoped, pending, failed and stale contexts cannot Apply/submit;
Product no longer silently reissues during a blocked submit.

Issue revisions reject stale successes, failures and completion callbacks. Preview
refresh sequence invalidation happens before debounce. Close/remount/teardown
cancel obsolete timers; detached Checkout roots cannot start old confirmation or
scheme-preference actions. Preview cancellation does not abort irreversible submit
requests. Product/Cart forms never borrow Checkout acceptance.

The Product/Cart stylesheet and Checkout stylesheet contain their own root-scoped
`.mt-uni-credit-product-calculator__consent[hidden] { display:none !important; }`
rules, which outrank explicit flex row display. The deterministic frontend harness
checks these selectors/declarations and lifecycle behavior; actual browser CSS
and theme integration remain runtime checks.

## Retention

Finalized evidence stays with its owning attempt during development. The existing
183-day presentation cleanup and 180-day Process 2 ciphertext cleanup do not erase
it. Ordinary expired pre-order pruning validates evidence, prunes genuinely
issued-only abandoned rows (and eligible absent legacy pre-order rows), and uses
an exact-byte delete predicate so a racing finalization is protected. Finalized,
malformed, unsupported or wrongly bound evidence is retained. No legal consent
retention period has been invented.

## Controlled later rollout and package verification

The production manifest explicitly includes the three new runtime libraries.
Installer layout and module version are unchanged. No release ZIP was built or
published, and no deployment action or database update was performed in this phase.

A later separately authorized rollout must:

1. Review these source changes and test results independently. Arrange an isolated
   MySQL/runtime environment before DB integration or bank-facing tests. New success
   fixtures now explicitly seed issued/finalized evidence; skipped integration
   fixtures and runtime behavior still need execution and review.
2. Quiesce financing entry points and allow active submissions to finish; take a
   controlled backup of source/schema/evidence. Do not uninstall or clear data.
3. Run the approved additive installer/upgrade ensure, verifying exactly
   `MEDIUMTEXT NULL`. Stop on incompatibility; do not convert/backfill/delete rows.
4. Replace the complete reviewed module file set in a controlled operation.
   OpenCart 4's normal installer may not overwrite existing extension files;
   uploading an installer over an old installation is insufficient evidence of an
   update. Preserve local environment/secrets/keys and runtime data. Do not mix old
   and new PHP/JS/CSS/templates. Invalidate the relevant PHP/template/asset caches.
5. In the later release phase, verify **source bytes = ZIP bytes = installed bytes
   = browser-served assets**. Recheck manifest, language aliases and exact asset
   hashes. The OC3 stale-ZIP regression must not recur.
6. Complete isolated concurrency, Product/Cart/Checkout browser/remount, native EUR
   recovery, theme/CSS and controlled bank/mail verification before any release
   decision. This phase grants none of those live operations.

## Offline validation and review inventory

See [UNI-CONSENT-001-REPORT.md](UNI-CONSENT-001-REPORT.md) for the exact changed/new
files, executed commands, PASS/SKIP results, baseline and independent-review
recommendation. Database integration, actual browser/theme rendering and
bank-facing E2E are SKIP, not PASS.

## Phase 6D targeted remediation

Direct `OpenCartOrderMaterializer` and `CheckoutExistingOrderGateway` construction
now requires `DurableConsentAuthority`. Materialization reloads finalized evidence,
compares its binding with the caller's attempt (including actor), verifies the
actual submission and EUR draft/store/amount, and acquires no additional lease.
`ControlPanelStatusSyncService` also requires this authority independently of its
store implementation. Admission and the final pre-PATCH boundary both verify
persisted order/process authority. Existing new-format recorded-target recovery
remains guarded; SQL NULL historical outcome retrieval remains read-only. Custom
factories must provide the mandatory dependencies; no permissive fallback exists.

Malformed consent containers and mandatory names/IDs now refuse; unusable optional
informational rows are skipped without inventing IDs. Consent dataset attributes
and link/text contexts use explicit Twig escaping with autoescape disabled.

Validation includes real Twig rendering into DOMDocument; isolated production
Product/Cart selection/debounce/open-close handlers with UI collaborators stubbed;
and the complete Checkout script mounted against a small EventTarget/DOM facade,
including actual payload/render/readiness code, timers and root replacement.
The facade does not implement browser HTML parsing, layout, CSS or full page/theme
mounting. Those browser checks remain NOT RUN. Fake CAS tests evaluate every
binding predicate and affected-row outcome but do not certify MySQL locks.
See the Phase 6D addendum in UNI-CONSENT-001-REPORT.md for results and exact delta.
