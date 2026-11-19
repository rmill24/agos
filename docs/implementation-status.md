# AGOS implementation status

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
