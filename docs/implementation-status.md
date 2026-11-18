# AGOS implementation status

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
