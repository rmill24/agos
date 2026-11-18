# AGOS implementation status

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
