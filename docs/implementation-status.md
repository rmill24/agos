# AGOS implementation status

## P5-T03 - Secure AI provider integration - 20 November 2026

**Verified with HTTP fakes.** Added a provider-neutral `AiExplanationProvider`
interface and one Laravel HTTP adapter for Groq Chat Completions. The default
model is `openai/gpt-oss-20b`, configurable through `GROQ_MODEL`.
`config/ai.php` reads `GROQ_API_KEY`, `AI_EXPLANATIONS_ENABLED` (false by
default) and `AI_EXPLANATIONS_PROVIDER` (groq). Credentials remain backend-only;
the local `.env` was not read, changed or displayed. `.env.example` contains
only blank credential placeholders. The application container binds the
interface to the Groq adapter; no second provider or dependency was added.

The adapter uses the fixed HTTPS endpoint
`https://api.groq.com/openai/v1/chat/completions`, Bearer authentication and
JSON-object output. Redirects and automatic retries are disabled. Connection
timeout is at most three seconds, request timeout at most ten seconds and no
longer than the remaining fifteen-second orchestration budget. Late results
are discarded. Database work is not forcibly interrupted; the overall budget
limits provider time remaining after the deterministic calculation and checks
the result before returning it. These limits are configuration targets, not
live-provider latency measurements.

`RecommendationExplanationService::forTeam(User, Team, days: 30)` generates a
fresh authorized `RecommendationService` report before any external call.
`RecommendationExplanationContext` is immutable and can only be constructed
through that authorized generation path. It projects candidate IDs, supported
actions, priorities, decimal values, dates, relevant evidence summaries,
freshness, history completeness and all documented limitations. Customer and
business names, document numbers/descriptions, raw ledger/history/daily inputs,
unrelated records and credentials are omitted. No database tools, execution
tools, financial writes or caller-provided evidence are exposed to the model.

Request/response contract:

- Input includes schema version, a SHA-256 `snapshot_id` for the projected
  context, review period, currency, candidates, evidence keyed by the existing
  AGOS references, per-action approved explanation choices and identified
  limitations. Only evidence actually referenced by candidates is sent.
- Output must contain exactly `schema_version`, `snapshot_id`,
  `summary_action_ids`, ranked `actions` and `limitation_ids`. Each action
  contains exactly `action_id`, `explanation_codes` and `evidence_references`.
- The validator requires the matching snapshot/version, every supplied action
  exactly once, valid summary IDs, approved statement codes, the exact evidence
  set belonging to each action, and all limitation IDs. Unknown fields,
  unsupported actions/references, changed cash-effect fields, free-form prose,
  missing candidates/limitations and malformed responses are rejected.
- The backend renders selected approved statements as explanations. This
  intentionally constrained explanation plan prevents the model from supplying
  new financial claims, amounts or actions inside prose. Suggested ordering
  remains separate; the deterministic report and official priorities/values
  are returned unchanged. All source limitations are retained.

Orchestration returns `report`, nullable `explanation`, `explanation_status`
and a sanitized `failure_reason`. Disabled AI, absent credentials, unsupported
configuration, no supported actions, excessive input, authentication failures,
rate limits, network/timeouts, other HTTP errors and invalid responses preserve
the deterministic report. Provider error bodies and original exceptions are
not logged, returned or attached to the sanitized exception. Limits are ten
candidates, 24 KB input JSON, 64 KB provider envelope and 20 KB plan content.
Large inputs fall back rather than silently omitting candidates. Interactive
429 handling returns fallback immediately instead of waiting or replaying a
generation request.

The Action Center, its financial services and existing UI behavior remain
unchanged. This task deliberately exposes backend orchestration only; enabling
`AI_EXPLANATIONS_ENABLED=true` permits explicit service invocations and does
not make page loads call Groq. UI integration and comprehensive fallback UX
remain for approved follow-up work. No live provider call was made, so account
credentials, model permissions and live response compliance remain unverified.
Timeouts and connection failures currently share `network_failure`; repeated
failure cooldowns and comprehensive adversarial/fallback testing remain for
P5-T04. There is no new persistence, route or migration.

Changed files: `.env.example`, `config/ai.php`,
`app/Contracts/AiExplanationProvider.php`,
`app/Data/RecommendationExplanationContext.php`,
`app/Exceptions/ExplanationUnavailable.php`,
`app/Integrations/Ai/GroqExplanationProvider.php`,
`app/Services/ExplanationResponseValidator.php`,
`app/Services/RecommendationExplanationService.php`,
`app/Providers/AppServiceProvider.php`, `phpunit.xml`,
`tests/Feature/Financial/AiExplanationTest.php`, and this document.

Verification: full default SQLite suite **279 passed, 1,228 assertions, two
expected PostgreSQL-only skips** (281 tests total). The 38 new cases cover
configuration, binding, minimized authorized requests, valid plan rendering,
unchanged records/reports, missing credentials, disabled/no-candidate paths,
provider errors, connection timeouts, malformed envelopes, basic contract and
evidence rejection, input/deadline limits, role permissions, revocation,
cross-Team attempts and incomplete onboarding. PHPUnit forces AI off and clears
the credential before individual tests opt into synthetic configuration and
HTTP fakes; automated tests never call the live provider. Existing Action
Center tests still pass with no provider requests. Full Pint `--test`, PHPStan
(zero errors), Vite build, configured PostgreSQL migration status (all applied)
and `git diff --check` passed. Vite retains its existing optional `fontaine`
notice. The two skipped concurrency cases require PostgreSQL row locking; no
separate PostgreSQL suite was run.

Provider contracts were checked against the official
[Groq API reference](https://console.groq.com/docs/api-reference),
[JSON output documentation](https://console.groq.com/docs/structured-outputs)
and [error reference](https://console.groq.com/docs/errors). Backend validation
remains mandatory regardless of provider output-mode guarantees.

**Next task:** P5-T04, pending explicit approval. No P5-T04 task group was begun.

## P5-T02 - Action Center UI - 20 November 2026

**Verified.** Upgraded the existing Action Center single-file Livewire page to
consume `RecommendationService::forTeam()` and its existing evidence-reference
map. P5-T01 services and financial rules remain unchanged. The page uses the
shared AGOS shell, Flux controls, cards, semantic priority/classification
labels and decimal-string PHP formatting. It displays service ordering,
rationales, outstanding amounts or projected exposure, risk/due dates,
threshold details and per-recommendation limitations.

Invoice and bill links open the existing independently authorized Team-scoped
document details. Calculation links target a keyboard-focusable evidence
section built from the same authorized recommendation response, including
confirmed current cash, threshold, maximum gap and an expandable daily
classification table. Missing absolute cash or threshold evidence displays
Unavailable rather than a fabricated zero. Confirmed, expected, uncertain and
optional classifications are explained, and forecasted collections are never
presented as confirmed cash. There is no financial arithmetic in Livewire or
JavaScript.

Missing verified opening cash, incomplete/stale Loyverse synchronization,
last successful sync time and incomplete committed imports are disclosed.
The empty state states that no action was identified and explicitly does not
assure cash safety. Refresh/retry regenerates evidence, provides loading and
success feedback, and disables duplicate clicks. Financial-query failures show
a safe retry state without exception details. Evidence/report arrays remain
server-rendered view data, not editable Livewire properties.

The existing route already applies authentication, verification, Team
membership, onboarding and recommendation permission middleware, and the
sidebar already gates Action Center navigation. These protections were
preserved. Mount, explicit refresh and every render independently reload and
authorize the actor/Team. Team context is locked. Revoked memberships, global
System Administrators, personal workspaces, incomplete businesses, foreign
Teams and attempted property tampering are covered by tests. No AI provider
was implemented or called; UI tests assert no outgoing HTTP requests.

Changed files for this task:

- `resources/views/pages/recommendations/⚡index.blade.php`
- `resources/views/components/php-amount.blade.php`
- `tests/Feature/Financial/ActionCenterUiTest.php`
- `docs/implementation-status.md`

Verification: **241 passed, 1,076 assertions, two expected PostgreSQL-only
skips** (243 tests total). All 20 new UI cases passed, covering rendering and
order, threshold guidance, missing/stale/incomplete data, evidence links and
isolation, refresh, failure/retry, permissions, tampering and decimal formatting
at the storage limit. Existing tests were preserved. Full Pint `--test`,
PHPStan (zero errors), Vite production build, configured PostgreSQL migration
status (all applied), and `git diff --check` passed. Vite retains its existing
optional `fontaine` notice. The two concurrency cases require PostgreSQL row
locking and were skipped by the default SQLite suite; this task did not run a
separate PostgreSQL suite or introduce persistent data/migrations.

Chrome review used an isolated SQLite fixture database and production assets
at 1440, 768 and 390 CSS pixels. Cards fit without page overflow; the wide
daily table stays within its labeled horizontal scroll region. Keyboard Enter
followed calculation and invoice evidence links, the calculation anchor
received focus, Space expanded the forecast, and focused controls showed a
2px outline. Network throttling verified loading feedback and disabled
refresh. Screenshots reviewed desktop/mobile layout, dark-mode cards,
incomplete-data warnings and safe failure/retry. Browser results and
screenshots are local ignored artifacts under
`storage/logs/action-center-review/`; no development financial records were
used. A comprehensive screen-reader audit was not performed.

Limitations: this UI uses the service's default 30-day period and current,
response-local calculation evidence, not immutable historical report
snapshots. P5-T01's forecast assumptions and freshness limitations still apply.
No AI explanations, provider calls or financial write actions were added.
Earlier P5-T01 and unrelated environment-template contributions were preserved.

**Next task:** P5-T03, pending explicit approval. No P5-T03 work was begun.

## P5-T01 - Deterministic evidence-linked recommendation engine - 20 November 2026

**Verified.** Extended the existing `RecommendationService` without replacing
the earlier Action Center contribution or changing P4 financial services.
`forTeam(User, Team, days: 30)` returns a read-only deterministic snapshot for
7/14/30/60/90 days, independently reloading and authorizing the actor and Team.
Owner, Manager and Bookkeeper can read it; global System Administrators,
nonmembers, revoked memberships and incomplete businesses are denied.

Rules implemented:

- Negative current/projected cash: Critical review of the cash shortfall,
  using the first negative daily balance supplied by `CashFlowService`.
- Cash-safety threshold risk: High review of discretionary commitments and
  payment timing, using the engine's earliest crossing and maximum cash gap.
  A negative-cash candidate subsumes this warning and retains both dates and
  threshold amounts, avoiding duplicate cash-risk advice.
- Overdue invoices and supplier bills: High collection/payment review.
- Invoices due today and supplier bills due today or within the selected
  forecast period: Normal review. Settled balances are excluded, and partial
  settlements use `Obligation::balance()` without duplicating its arithmetic.

Priority sorts by severity (Critical, High, Normal), earliest relevant date,
largest decimal exposure, then stable candidate ID. Exposure is an outstanding
amount, first negative balance magnitude, or maximum threshold gap; it is not
a promised cash benefit. All candidates are advisory and have a null
`cash_effect`. The engine never executes actions or writes financial records.

The result contains `mode`, Team/currency, generation time, business as-of and
period-end dates, status/message, `recommendations`, `evidence`, `limitations`,
history completeness and synchronization freshness. Each recommendation has
an action, rule/ID, priority, rationale/reason, decimal amount(s), relevant
date(s), `evidence_references`, advisory status and limitations. Record
references use `team:{id}:{invoice|bill}:{record_id}` and resolve in the
response's evidence map to a Team-scoped record and its outstanding-balance
result. Calculation references use `team:{id}:cash_flow:{as_of}:{period_end}`
and resolve to the authorized `CashFlowService` result in that same map.
References are response-local, not immutable historical report IDs or bearer
authorization. Regenerate and reauthorize before display; no external evidence
or caller-provided financial values are accepted by this service.

Missing/unverified/not-yet-effective opening cash suppresses unsupported
balance-risk claims while preserving supported obligation reviews. Results
carry cash-engine stale-sync limitations, last successful sync timestamps,
incomplete committed CSV-history warnings and uncertain-collection warnings.
Uncertain collections remain forecast assumptions, never confirmed cash.
An empty result says no action was identified from available evidence and
explicitly avoids assuring cash safety.

Known limits: the engine preserves the existing forecast's due-date assumptions
and does not independently reschedule overdue obligations or model collection
failure. It does not certify manual-record completeness or impose an invented
sync-age freshness cutoff. No negotiated deferrals, purchase alternatives,
deposit amounts, cash savings or AI explanations are invented. ScenarioService
was inspected and remains unchanged; hypothetical scenario inputs are not
accepted as actual recommendation evidence. No new persistence or migrations
were introduced.

Changed files: `app/Services/RecommendationService.php`,
`app/Support/RecommendationPriority.php`,
`tests/Feature/Financial/RecommendationEngineTest.php`,
`tests/Unit/RecommendationPriorityTest.php`, and this status document.
The 16 new tests cover rule boundaries, partial balances, decimal priority
comparisons, cash-service delegation, evidence resolution, deterministic
ordering, no-write snapshots, uncertainty, unavailable history, freshness,
membership revocation, stale actor security state, cross-Team attempts,
incomplete onboarding and direct Livewire refresh authorization. Existing P4
reconciliation and scenario tests were preserved.

Verification: full default SQLite suite **221 passed, 955 assertions, two
expected PostgreSQL-only skips** (223 tests total). Full Pint `--test`, PHPStan
(zero errors), Vite production build, configured PostgreSQL migration status
(all applied), and `git diff --check` passed. Vite retains the existing optional
`fontaine` font-fallback notice. PostgreSQL-specific concurrency tests were not
run in this task. No UI was changed or browser review performed.

**Next task:** P5-T02, pending approval. The existing UI and dashboard retain
their due/overdue obligation-only `candidates()` compatibility projection;
P5-T02 should consume `forTeam()` to display cash risks, upcoming bills,
evidence and limitations. No P5-T02 UI or AI-provider integration was begun.

## P4-T06 - Verified opening-balance and cash-history completeness workflow - 20 November 2026

**Verified.** Teams now persist an Owner-confirmed decimal opening cash
balance, effective business date, verification timestamp, and verifying user.
Owners can configure or clear it during onboarding and from Business Profile
settings. The workflow validates the amount, date, and explicit confirmation,
records Team-scoped audit events, and denies Manager, Bookkeeper, System
Administrator, incomplete-business, and cross-Team access through existing
authorization boundaries.

CashFlowService automatically consumes only verified persisted opening cash,
scopes ledger history from its effective date, and exposes the verified source
to dashboard and scenario calculations. Unverified or missing values remain
unavailable; future effective dates are rejected; stale source warnings and
confirmed/expected/uncertain/optional classifications remain intact.

The full suite passed 205 tests with 879 assertions and two expected
PostgreSQL-only skips. Pint, PHPStan, migration status, the Vite production
build, and `git diff --check` passed. Migration
`2026_11_20_000001_add_verified_opening_cash_to_teams` is applied. Browser
automation is not installed in this repository, so browser visual checks
remain unperformed.

**Next task:** P5-T01 - deterministic prioritized candidate recommendations.
Do not begin until approved.

## P4-T05 - Independent cash-flow and scenario verification - 20 November 2026

**Verified.** Added an independently calculated mixed-ledger fixture covering
confirmed movements, partial invoice and bill payments, uncertain collection,
expected outflow, refund outflow, decimal precision, forecast boundary dates,
threshold crossing, cash gap, and earliest shortfall. The expected results are
documented beside the fixture inputs rather than derived from service output.

The verification suite also exercises all six scenario types, baseline-versus-
scenario comparison deltas, all four purchase-affordability alternatives,
stale Loyverse synchronization warnings, incomplete opening history, service-
boundary authorization, Team isolation, and persisted-record immutability.
No CashFlowService or ScenarioService calculation defect was found, so no
financial production code was changed for P4-T05.

The full suite passed 200 tests with 858 assertions and two expected
PostgreSQL-only skips. Pint, PHPStan, migration status, the Vite production
build, and `git diff --check` passed. PostgreSQL 18 is installed and running,
but no separate `agos_test` or `agos_testing` database was available; the
development database was not reset or used for concurrency verification.
Browser automation is not installed in this repository, so browser chart,
responsive, and accessibility checks remain unperformed.

**Next task:** P4-T06 - verified opening-balance and cash-history completeness workflow. This task was subsequently verified above.
Do not begin until approved.

## P4-T04 - Baseline-versus-scenario comparisons and purchase affordability - 19 November 2026

**Verified.** The transient Scenario Simulator now exposes server-calculated
baseline-versus-scenario comparisons for projected cash, cash-gap impact,
threshold crossings, and earliest shortfall dates. Purchase-affordability
analysis evaluates purchase now, delayed purchase, reduced amount, and
customer-deposit alternatives as independent hypothetical scenarios. Each
alternative includes its assumptions, dates, limitations, and an explicit
affordability result only when verified opening history and a configured cash
safety threshold support that conclusion.

Comparison summaries are rendered side by side with a responsive Chart.js
comparison and accessible semantic fallback content. Scenario calculations
continue to delegate to CashFlowService and BigDecimal arithmetic; no actual
transactions, invoices, bills, payments, or saved scenario records are
created or changed. Team, onboarding, Owner/Manager, and independent service
and Livewire authorization protections remain in place.

The full suite passed 195 tests with 798 assertions and two expected
PostgreSQL-only skips. Pint, PHPStan, migration status, the Vite production
build, and `git diff --check` passed. Browser automation is not installed in
this repository, so visual review was limited to the rendered Livewire
assertions and responsive/accessibility source checks.

**Next task:** P4-T05 - test calculations against independent fixtures and
assert scenario immutability. Do not begin until approved.

## P4-T03 — Isolated scenario simulation — 19 November 2026

**Verified.** Owner and Manager users can run transient, Team-scoped scenarios
for customer payment delays, sales reductions, expense increases, supplier
payment delays, customer deposits, and proposed purchases. `ScenarioService`
validates decimal amounts, dates, forecast periods, delays, obligation
ownership, and scenario-specific inputs before delegating forecast calculation
to `CashFlowService`. Hypothetical inputs and results remain separate from
actual transactions, invoices, bills, and payments; no scenario execution
writes financial records. Bookkeepers, System Administrators, incomplete
businesses, and cross-Team records are denied independently at the route,
Livewire, policy, and service boundaries.

The Scenario Simulator Livewire page includes validation, loading, empty,
failure, hypothetical-only, and limitation states using the AGOS shell. It
explicitly reports when percentage sales reduction has no confirmed future
sales basis or when a hypothetical event falls outside the selected period.
Scenarios are intentionally not persisted in P4-T03; revisitable saved
scenario records remain a later optional requirement and no P4-T04 comparison
or affordability recommendations were added.

The full suite passed 191 tests with 777 assertions and two expected
PostgreSQL-only skips. Pint, PHPStan, migration status, the Vite production
build, and `git diff --check` passed. The next task is P4-T04, which requires
approval before implementation.

## P4-T02 — Dashboard summaries and accessible forecast chart — 19 November 2026

**Verified.** The authenticated business dashboard now consumes the authorized
`CashFlowService`, supports validated 7/14/30/60/90-day forecast periods, and
shows current cash, projected cash, the Team cash-safety threshold, earliest
shortfall, cash gap, source limitations, and selected dates. Unknown opening
history remains visibly unavailable; forecasts are labeled separately from
confirmed ledger movement. Confirmed, expected, uncertain, and optional states
are documented in the dashboard legend and accessible table.

Chart.js is bundled through Vite for responsive cash-flow movement and balance
visualization. The chart has labeled axes, units, legend, tooltips, threshold
line, loading/error states, and an accessible server-rendered data table
fallback. All chart series are derived from server-returned decimal strings;
JavaScript only maps them to visual points and does not calculate financial
totals. Dashboard routes and the CashFlowService retain Team membership,
onboarding, role, and System Administrator protections.

The focused dashboard/cash tests passed 9 tests with 45 assertions. The full
suite passed 180 tests with 746 assertions and two expected PostgreSQL-only
skips. Pint, PHPStan, all migration checks, the Vite production build, and
`git diff --check` passed. The browser automation dependency is not installed
in this repository, so visual review was covered by responsive source/layout
checks and HTTP feature assertions; a manual Chrome review remains advisable.

**Next task:** P4-T03 — implement isolated hypothetical scenarios. Do not begin
until approved.

## P4-T01 — Deterministic cash engine — 19 November 2026

**Verified.** Team-scoped cash calculations now reconcile confirmed ledger
movements with decimal-safe `BigDecimal` arithmetic, classify future ledger
movements and outstanding obligations as confirmed, expected, or uncertain,
and calculate projected balances, threshold crossings, earliest shortfall, and
cash gap when an explicit opening balance is supplied. Missing opening history,
missing threshold settings, and incomplete Loyverse synchronization are
disclosed instead of being treated as confirmed absolute cash. The cash-safety
threshold is persisted on the canonical Team and is owner-managed through
onboarding and Business Profile settings. Payments are not double-counted:
their linked ledger transactions are the only confirmed cash input.

The focused cash/profile tests passed 9 tests with 26 assertions. The full
suite passed 177 tests with 731 assertions and two PostgreSQL-only skips.
PHPStan, Pint, migration status, the production asset build, and
`git diff --check` passed. Migration
`2026_11_19_000007_add_cash_safety_threshold_to_teams` is applied.

**Next task:** P4-T02 — integrate the verified engine into dashboard summaries
and accessible Chart.js forecast views.

## P3-T05 — Loyverse connection settings — 19 November 2026

**Verified.** Business Owners can connect a Team by entering one Loyverse access token; AGOS loads the merchant, stores, and payment types server-side and presents labeled selections instead of requiring provider IDs. Connections can be saved and cleared from the Loyverse sync page. Access tokens are encrypted in the database, masked in the UI, excluded from audit metadata, and existing `.env` connections remain a fallback. The read-only adapter verifies the merchant and PHP currency and loads metadata without importing receipts. The sync service resolves database connections first and preserves existing quarantine, checkpoint, and duplicate-protection behavior.

The sync path now accepts a null final pagination cursor, probes receipt access for the selected store before saving a new connection, and records safe actionable failure messages for token, permission, store, rate-limit, timeout, and malformed-page errors. The focused Loyverse suite passed 28 tests and 136 assertions; the full suite passed 171 tests with 709 assertions and two PostgreSQL-only skips. PHPStan, Pint, the production build, and `git diff --check` passed.

**Diagnostic follow-up verified.** Failed pages now persist a failure code and UUID reference, and write safe worker metadata to the application log without tokens or receipt payloads. Existing failed runs retain their previous message; retrying them uses the new diagnostics. Migration `2026_11_19_000004_add_failure_diagnostics_to_loyverse_syncs` is applied. The full suite now passes 171 tests with 711 assertions.

The diagnostic pass now records a sanitized exception detail, classifies HTTP 400 request errors separately from access failures, and displays the diagnostic code/reference in the sync status. The final verification passed 172 tests with 713 assertions, two PostgreSQL-only skips, PHPStan, Pint, the production build, and `git diff --check`.

**First-sync 400 follow-up verified.** A first receipt sync no longer sends the artificial Unix-epoch `updated_at_min` lower bound that Loyverse can reject as an invalid range; incremental syncs continue to send their real lower bound. Provider error codes and short details are included in sanitized diagnostics, so a rejected request now identifies errors such as `INVALID_RANGE`. The focused Loyverse suite passed 25 tests with 122 assertions, and the full suite passed 173 tests with 714 assertions and two PostgreSQL-only skips. PHPStan, Pint, the production build, and `git diff --check` passed.

The focused Loyverse suite passed 26 tests and 131 assertions. The full suite passed 169 tests with 704 assertions and two PostgreSQL-only skips. Pint, PHPStan, migration status, `git diff --check`, and the production asset build passed. Migration `2026_11_19_000003_create_loyverse_connections` is applied to the configured local database.

## Cash dashboard — P4-T02 partial

The business dashboard shows month-to-date ledger income, expenses, net movement and the five most recent movements through today's Manila date. It also links to the three highest-priority invoice or bill recommendations and shows Loyverse freshness. Personal workspaces retain setup and invitation access. P4-T01 remains the dependency for absolute cash, projected balances and shortfall dates.

The full isolated PostgreSQL suite passed 165 tests with 690 assertions. SQLite passed 163 with two PostgreSQL-only skips. Pint, PHPStan, migration status and the asset build passed. Chrome checks covered keyboard navigation to the Action Center and viewport fit at 1440, 768 and 390 pixels.

## P5 Action Center — 19 November 2026

**P5-T01 and P5-T02 verified for invoice and supplier bill recommendations.** Open Workspace → Action Center in an onboarded business. Overdue obligations appear before items due today, partial payments reduce the displayed amount, and settled obligations drop out on refresh. Each source link opens the matching Team document. The rules use Manila dates and the existing decimal-safe obligation balance.

The isolated PostgreSQL suite passed 164 tests with 678 assertions. Pint, PHPStan, migration status and the production build passed. Browser checks at 1440, 768 and 390 pixels verified viewport fit and keyboard access to invoice details. Forecast-based recommendations await P4-T01. The next planned frontend group is P4-T02 after the cash engine is verified. P5-T03 and P5-T04 cover AI explanations and fallback.

## P3 — 19 November 2026

**P3-T01 complete for the published API contract.** The [Loyverse receipt contract](loyverse-api-contract.md) maps read permissions, receipt pagination, merchant currency and payment evidence to AGOS financial rules. It includes synthetic reconciliation cases and identifies the evidence required for live store verification. No live account permissions, currency or receipts have been verified.

**P3-T02 complete with simulated provider responses.** The read-only Laravel adapter verifies the onboarded owner, merchant and store before staging receipt evidence. Each Team has a server-side token and source mapping. Staged receipts preserve decimal strings, payment methods and provider revision times. The database enforces one receipt number per Team. Newer revisions replace older ones and a failed page rolls back atomically.

The isolated PostgreSQL 17 suite passed all 151 tests (577 assertions), including 10 adapter tests. The receipt migration applied, rolled back and reapplied on the isolated database, and is applied on the configured local database. PHPStan, Pint and the asset build passed. Live credentials and reconciliation remain unverified.

**P3-T03 complete with simulated Loyverse responses.** Onboarded owners can start or resume a database-queued sync from **Loyverse sync**. Workers verify the Team and owner, save each completed page and publish reconciled movements together after the final page. The dashboard retains the last successful sync time during an outage. Owners can inspect quarantined receipt identifiers and reasons. Each store needs an explicitly verified collection-method mapping before its payments can contribute cash.

The full isolated PostgreSQL 17 suite passed **157 tests and 625 assertions**. SQLite passed 155 tests with two PostgreSQL-only skips. PHPStan, Pint and the production build passed. The new migration applied, rolled back and reapplied on isolated PostgreSQL and is applied on the configured local database. Chrome review at 1440, 768 and 390 CSS pixels verified viewport fit, visible keyboard focus, quarantine review and the retry action. During verification, the Team test factory was changed to use the model’s existing slug generator after a random slug collision.

**P3-T04 complete with synthetic receipts.** A discounted PHP 100.80 sale including VAT produces two income movements of PHP 60.00 and PHP 40.80. A linked PHP 25.00 refund posts on its own Manila date. Replaying both receipts preserves the original movements. Additional cases cover negative refund amounts, conflicting signs and the PHP 0.01 reconciliation tolerance. Existing fixtures cover missing or unverified payments, cancellation, duplicate and revised receipts, timeouts, malformed pages and interrupted syncs.

The full isolated PostgreSQL 17 suite passed **162 tests and 653 assertions**. Pint, PHPStan and the production build passed. Live store permissions, payment mappings and reconciliation against Back Office remain unverified.

### P3 handoff to P4

**Start with P4-T01.** Implement the server-side cash position and forecast calculations, including the first date projected cash falls below the business threshold and the size of that gap. P4 development can proceed with local fixtures. [P4 task sequence](../AGOS_Implementation_Plan.md#p4--cash-engine-and-scenarios)

- **Confirmed cash input.** Read the authorized Team’s active ledger transactions. P3 publishes each verified Loyverse payment there as income or expense. Staged receipts and sync entries supply evidence and review history. Adding their totals to the ledger would double-count cash. During an incomplete sync, use the previously published ledger and expose its last successful sync time.
- **First reconciliation check.** With the synthetic sale and refund as the only movements, net movement is **+PHP 100.80** on 10 October 2026 and **−PHP 25.00** on 12 October in Manila. Cumulative movement is **+PHP 75.80** after the refund, including after replay. An absolute cash balance also requires a known starting position or complete history. Label incomplete history explicitly. [Receipt fixture](../tests/Fixtures/loyverse/discounted-sale-and-refund.json) · [Reconciliation test](../tests/Feature/Financial/LoyverseSyncTest.php)
- **Forecast inputs.** Keep confirmed, expected, uncertain and optional flows distinct. Issuing an invoice or bill changes the outstanding obligation. Its linked payment changes cash. Use decimal-safe PHP amounts and Manila business dates throughout. [Financial requirements](../AGOS_Functional_Requirements_Specification.md)
- **Live-store follow-up.** Before relying on a real store’s imported totals, verify its merchant and store IDs, PHP currency, collection-method mappings, and representative sales and refunds against Back Office. [Live verification evidence](loyverse-api-contract.md#evidence-still-needed-from-a-live-store)

## P2 acceptance — 18 November 2026

| Task | Status | Verified outcome |
| --- | --- | --- |
| P2-T00 | Complete | Reviewed dashboard, onboarding, business profile, categories, and transactions in Chrome at 1440, 768, and 390 CSS pixels. The pages fit each viewport and showed visible keyboard focus. |
| P2-T01 | Complete | Reviewed both contact directories at all three widths. Keyboard activation opened the contact forms, Tab moved focus inside the dialogs, and Escape dismissed them. The existing directory and access tests pass. |
| P2-T02 | Complete | Itemized invoices reconcile to PHP line totals. Partial and full receipts settle the outstanding balance through linked income movements. Unsettled invoices become overdue after midnight in Manila. |
| P2-T03 | Complete | Supplier bills show due dates, settlement status and outstanding payables. Supplier payments create linked expenses. Archived contacts retain their document and payment history. |
| P2-T04 | Complete | Private CSV drafts support column mapping, validation, duplicate review, selected-row commit, and row-numbered downloads. The results and ledger identify incomplete history. |
| P2-T05 | Complete | PostgreSQL reconciliation, concurrent payments, idempotency, stale reviews, cross-Team access, onboarding and role checks pass. |

### Changed files

- Two additive migrations create obligations, invoice lines, payments, import batches and import rows. Composite foreign keys enforce ownership for linked contacts, payments, ledger entries and staged rows.
- New Eloquent models are `Obligation`, `InvoiceLine`, `Payment`, `ImportBatch` and `ImportRow`. `ObligationService` calculates totals and records settlements. `CsvImportService` stages, checks and posts reviewed rows.
- The Team and transaction policies, transaction service, web routes, sidebar, and transactions page integrate the workflows. New Livewire pages are under `resources/views/pages/obligations` and `resources/views/pages/imports`.
- Financial regression coverage is in `ObligationsTest.php`, `CsvImportTest.php`, `FinancialWorkflowAccessTest.php` and `PaymentConcurrencyTest.php`, with a shared business fixture in `tests/Pest.php`.

### Verification

- Full Laravel suite on isolated PostgreSQL 17: **139 tests passed, 516 assertions**. The simultaneous payment cases used two independent worker processes, both waiting on the same Team lock before release.
- Default SQLite test configuration: **137 passed, 2 skipped**. The two row-lock tests require PostgreSQL and passed there.
- Both new migrations applied, rolled back and reapplied on isolated PostgreSQL. Both are applied to the configured local AGOS PostgreSQL database.
- Pint and the Vite production build passed. Vite reported an optional font-fallback optimization dependency.
- Browser checks covered the invoice and bill validation, creation and partial-payment flows, plus CSV upload, mapping preview, held rows, confirmed commit and report download at 1440, 768 and 390 CSS pixels. The tests used a separate fixture database.

The invoice, bill and CSV import directories now reuse the contact directory table and pagination component. Their primary actions open Flux dialogs and Details opens a flyout. Browser review verified keyboard opening, focus inside the flyout, Escape dismissal and viewport fit for all five directories at all three widths. A Livewire regression test covers search and due-status filtering across invoice pages.

**Blockers:** None for P2. **Next task:** P3-T01, verify the current Loyverse fields, pagination, status meanings and API permissions.

### P2-T04 follow-up — CSV actions in Transactions

Transactions now hosts CSV upload, review, batch history and filtered ledger export. Existing team imports links redirect to Transactions. The sidebar, web routes, transaction components and financial feature tests were updated. The SQLite suite passed with 138 tests and two PostgreSQL-only skips. Pint, the production build and migration status checks passed. P3-T01 remains the next planned task.
