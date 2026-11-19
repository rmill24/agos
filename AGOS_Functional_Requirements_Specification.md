# AGOS Functional Requirements Specification (FRS)

**System:** AGOS — Adaptive Guidance and Operations System

**Document type:** Functional Requirements Specification

**Version:** 1.4 — Editorial revision and UI/UX requirements

**Status:** Development baseline

**Intended environment:** Local development, testing, and academic
demonstration

**Currency and locale:** Philippine peso (PHP); `Asia/Manila` for
business-facing dates and times

## Document purpose and basis

This specification defines AGOS's functional scope, user roles, business
rules, integration boundaries, safeguards, and testable acceptance
criteria.

The selected implementation stack is:

- **Application:** Laravel 13 (PHP 8.3+) as a single modular monolith
- **Interface:** Livewire 4 single-file components (default), Laravel
  Blade, Tailwind CSS, and starter-kit Flux UI components
- **Teams and tenancy:** Official Laravel Livewire starter kit with
  Teams support enabled; one Laravel Team represents one AGOS Business
- **Charts:** Chart.js integrated into Livewire pages
- **Database:** PostgreSQL
- **Persistence:** Eloquent ORM and Laravel database migrations
- **Money arithmetic:** PostgreSQL `NUMERIC` and `brick/math`
  `BigDecimal` (or equivalently tested fixed-precision arithmetic)
- **Authentication:** Laravel's official Livewire starter kit,
  Fortify/session authentication, policies, and gates
- **Testing:** Pest or PHPUnit with Laravel HTTP/Livewire feature tests
- **Assets:** Vite for compiling CSS and small JavaScript utilities (no
  separate React application)
- **Version control:** Git and GitHub
- **Integrations:** Loyverse API, Groq or Gemini API, and Gotenberg
  hosted on Render

Use single-file Livewire components by default for standard pages,
forms, CRUD workflows, and dashboard widgets. Use multi-file or
class-based components when complexity, testing needs, or reuse justify
separating the component's logic and view. Keep financial calculations
and integration logic in dedicated services and adapters, not in
component bodies.

Laravel Teams is the canonical business-tenancy foundation. Extend the
generated Team and team-membership schema for AGOS-specific business
fields and roles; do not create duplicate Business or Membership tables.

AGOS uses a **modular monolith** architecture. Livewire components call
Laravel services directly. JSON REST endpoints under `/api/v1` are added
only when required by an external consumer or an explicit API contract.
Provider-specific HTTP integrations are isolated behind server-side
adapters. The AGOS application and PostgreSQL database run locally; only
the Gotenberg PDF service is hosted on Render.

## 1. Introduction

### 1.1 Purpose

AGOS is a web-based cash-flow management and decision-support platform
for Philippine micro, small, and medium enterprises (MSMEs). It
consolidates cash-related business records and uses those records to
show current cash, forecast short-term cash flow, identify potential
cash shortages, simulate hypothetical situations, and present
evidence-supported actions.

This FRS defines what the system must do. It is intended for the
development team, project adviser, testers, and evaluators.

### 1.2 Product objectives

AGOS shall:

1.  Centralize income, expenses, invoices, payments, supplier bills, and
    related cash-flow records.
2.  Support CSV import for businesses with existing spreadsheet records.
3.  Retrieve sales transactions from Loyverse in read-only mode.
4.  Monitor receivables, payables, overdue collections, and upcoming
    expenses.
5.  Calculate current and projected cash using deterministic application
    logic.
6.  Identify when projected cash may fall below a business-defined
    cash-safety threshold.
7.  Let users test hypothetical financial events without changing actual
    financial records.
8.  Provide prioritized recommendations supported by AGOS records and
    calculations.
9.  Use AI to explain verified results, not to calculate official
    financial values or execute transactions.
10. Produce invoices and financial reports in PDF format.
11. Help businesses organize financial information for
    financing-readiness review without predicting loan approval.

### 1.3 Scope

**In scope**

- User login, sessions, roles, and business-level data isolation
- Business profile and cash-safety settings
- Income and expense transactions
- Transaction categorization and recurring financial records
- Customer and supplier records
- Invoice creation, payment recording, and receivables monitoring
- Supplier bills, payable monitoring, and payment recording
- CSV import with preview, mapping, validation, duplicate review, and
  error reporting
- Read-only Loyverse sales synchronization
- Cash position, forecast, cash-flow trends, and cash-gap calculations
- Form-based scenario simulation
- Purchase-affordability analysis
- Rule-based and AI-assisted recommendations
- Financing-readiness checklist and report
- PDF invoices and reports through Gotenberg
- Audit events and operational error handling
- Local testing and demonstration

**Out of scope**

- Production hosting, public release, or cloud deployment
- Writing to, editing, or deleting records in Loyverse
- Automatic payment execution or bank transfers
- Automatic customer or supplier messaging
- Loan approval, credit scoring, or prediction of financing approval
- AI-controlled modification of financial records
- Foreign-exchange conversion in the first release
- Microservices architecture
- Real-time Loyverse webhooks as a required first-release feature

### 1.4 Definitions

| Term                    | Meaning                                                                                                        |
|-------------------------|----------------------------------------------------------------------------------------------------------------|
| Business                | One Laravel Team representing one MSME account. Its Team ID is the business scope for financial data.          |
| Team Owner              | The owner of a Laravel Team, mapped to the AGOS Business Owner role.                                           |
| Team Member             | A user who belongs to a business Team. The membership role maps to Manager or Bookkeeper / Finance Staff.      |
| Confirmed cash          | Cash already received or paid and supported by confirmed records.                                              |
| Expected cash           | A forecast inflow or outflow supported by a known record but not yet settled.                                  |
| Uncertain collection    | An expected customer collection whose timing or receipt is uncertain.                                          |
| Optional future expense | A proposed or discretionary expense that has not been committed.                                               |
| Cash-safety threshold   | The minimum cash balance defined by the Business Owner.                                                        |
| Cash gap                | The amount by which projected cash falls below the cash-safety threshold.                                      |
| Scenario                | A hypothetical change used for simulation and kept separate from actual financial records.                     |
| Adapter                 | A module that isolates provider-specific API authentication, mapping, validation, error handling, and retries. |
| Evidence reference      | An identifier linking a recommendation or report statement to an AGOS record or calculation.                   |

## 2. Users and access roles

### 2.1 User roles

1.  **Business Owner** --- has full access to the business and may
    manage users, settings, financial records, scenarios, reports, and
    recommendations.
2.  **Manager** --- monitors cash flow, manages daily financial
    activities, and runs scenarios for the assigned business.
3.  **Bookkeeper / Finance Staff** --- maintains transactions, invoices,
    payments, bills, and reports for the assigned business.
4.  **System Administrator** --- manages technical platform settings and
    has no default access to business financial records.

### 2.2 Business tenancy and Laravel Teams mapping

The Laravel starter kit's Teams feature shall be enabled. Each Laravel
Team represents one AGOS Business, and the Team ID is the canonical
business identifier used to scope financial records.

- Team owner maps to **Business Owner**.
- Team membership roles map to **Manager** and **Bookkeeper / Finance
  Staff**.
- System Administrator is a separate global platform role, not a
  business Team role.
- Use the starter kit's existing Team and membership/pivot tables as the
  canonical tenancy records. Extend them with new migrations for
  AGOS-specific business profile fields or role values.
- Do not create duplicate `Business` or `Membership` tables for the same
  concepts.
- Customize business onboarding so the Team name and required business
  profile details are captured. Do not treat an automatically created
  personal Team as a fully configured AGOS business until onboarding is
  complete.
- A user may belong to multiple business Teams where authorized. The
  current Team is a navigation context only; every query, Livewire
  action, job, import, and report must independently verify membership
  and authorization.
- Team switching must not permit access to another business's records
  without membership and permission checks.

### 2.3 Permission matrix

| Capability                         | Business Owner | Manager                        | Bookkeeper / Finance Staff                       | System Administrator                                       |
|------------------------------------|----------------|--------------------------------|--------------------------------------------------|------------------------------------------------------------|
| Read business records and reports  | Yes            | Yes, for the assigned business | Yes, for the assigned business                   | No by default                                              |
| Create or edit financial records   | Yes            | Yes, for the assigned business | Yes, for the assigned business                   | No                                                         |
| Run scenarios                      | Yes            | Yes                            | No by default; subject to business configuration | No                                                         |
| View recommendations               | Yes            | Yes                            | Yes                                              | No                                                         |
| Manage business users and settings | Yes            | No                             | No                                               | Platform settings only                                     |
| Manage technical platform settings | No             | No                             | No                                               | Yes                                                        |
| View business audit events         | Yes            | No by default                  | No by default                                    | Operational metadata only; no financial content by default |

The implementation must enforce permissions on the backend. Hiding a
button in the frontend is not sufficient authorization.

## 3. Functional requirements

Priority definitions: **Must** = required for the first complete
demonstration; **Should** = included where feasible within the project
schedule; **Could** = optional enhancement.

### 3.1 Authentication, users, and business isolation

| ID         | Priority | Requirement                                                                                                                 | Acceptance criteria                                                                                                            |
|------------|----------|-----------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------|
| FR-AUTH-01 | Must     | The system shall allow registered users to sign in with valid credentials.                                                  | Valid credentials create an authenticated session; invalid credentials are rejected with a safe message.                       |
| FR-AUTH-02 | Must     | The system shall allow users to log out.                                                                                    | Logout revokes the active session and prevents its reuse.                                                                      |
| FR-AUTH-03 | Must     | The system shall support password resets and authorized administrator-assisted password changes.                            | Password replacement invalidates existing active sessions.                                                                     |
| FR-AUTH-04 | Must     | The system shall use server-managed sessions and secure cookie attributes.                                                  | Session identifiers are rotated on login; cookies are HttpOnly and SameSite; deployed HTTPS configuration uses Secure cookies. |
| FR-AUTH-05 | Must     | The system shall expire sessions after 30 minutes of inactivity.                                                            | A session idle beyond the configured limit requires authentication again.                                                      |
| FR-AUTH-06 | Must     | The system shall enforce role-based access control.                                                                         | Each protected operation checks the user's role and business assignment on the backend.                                        |
| FR-AUTH-07 | Must     | The system shall isolate data by business.                                                                                  | A user cannot read, modify, import, or report on another business's records by changing an identifier in a request.            |
| FR-AUTH-08 | Must     | The System Administrator shall have no default access to business financial records.                                        | Platform administration endpoints expose operational settings only unless a separately approved access process is defined.     |
| FR-AUTH-09 | Must     | The system shall rate-limit login attempts and protect state-changing session requests against CSRF.                        | Repeated login attempts are limited; state-changing requests without valid CSRF protection are rejected.                       |
| FR-USER-01 | Must     | The Business Owner shall be able to manage business users, including creating, updating, deactivating, and assigning roles. | Only an authorized owner can manage users for that business; changes are auditable.                                            |
| FR-USER-02 | Must     | The Business Owner shall be able to maintain the business profile and cash-safety settings.                                 | Saved settings are applied to that business only.                                                                              |

### 3.2 Dashboard and cash overview

| ID         | Priority | Requirement                                                                                                         | Acceptance criteria                                                                                                                          |
|------------|----------|---------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------|
| FR-DASH-01 | Must     | The dashboard shall display the business's current cash position.                                                   | The displayed amount is calculated from confirmed cash movements and reconciles with the underlying records.                                 |
| FR-DASH-02 | Must     | The dashboard shall display projected cash for the selected forecast period.                                        | The projection is generated by the deterministic AGOS calculation engine.                                                                    |
| FR-DASH-03 | Must     | The dashboard shall display cash-flow trends.                                                                       | Users can view inflows, outflows, and projected balance over the supported period.                                                           |
| FR-DASH-04 | Must     | The dashboard shall show upcoming inflows and outflows.                                                             | Each item displays its date, amount, type, status or certainty classification, and source record where available.                            |
| FR-DASH-05 | Must     | The dashboard shall identify overdue customer collections.                                                          | Overdue invoices are listed with customer, due date, outstanding amount, and days overdue.                                                   |
| FR-DASH-06 | Must     | The dashboard shall identify upcoming expenses and supplier obligations.                                            | Upcoming bills and scheduled expenses are listed with due dates and outstanding amounts.                                                     |
| FR-DASH-07 | Must     | The dashboard shall display the business's cash-safety threshold.                                                   | The configured threshold is visible and its effect on projected cash is shown.                                                               |
| FR-DASH-08 | Must     | The dashboard shall identify the earliest date projected cash may fall below the threshold.                         | The date is shown when a forecast shortfall exists; otherwise the dashboard indicates that no shortfall was detected in the selected period. |
| FR-DASH-09 | Must     | The dashboard shall distinguish confirmed cash, expected cash, uncertain collections, and optional future expenses. | The categories are visibly distinguishable and are not silently combined into confirmed cash.                                                |
| FR-DASH-10 | Should   | The dashboard shall show the last successful Loyverse synchronization time and stale-data status.                   | The timestamp is shown; failed or incomplete synchronization is not presented as current data.                                               |

### 3.3 Transactions and recurring records

| ID        | Priority | Requirement                                                                                   | Acceptance criteria                                                                                                |
|-----------|----------|-----------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------|
| FR-TRX-01 | Must     | Authorized users shall be able to create income and expense transactions.                     | Required fields are validated; a saved transaction is associated with the authenticated business.                  |
| FR-TRX-02 | Must     | Authorized users shall be able to view, search, filter, and open transaction details.         | Results are business-scoped and filters return matching records.                                                   |
| FR-TRX-03 | Must     | Authorized users shall be able to edit or archive transactions according to role permissions. | Changes are validated and recorded in the audit trail.                                                             |
| FR-TRX-04 | Must     | Transactions shall support categories.                                                        | A transaction can be assigned a configured category; unmapped imported categories are marked for review.           |
| FR-TRX-05 | Should   | The system shall support recurring financial records.                                         | Users can define recurrence details and review generated or upcoming entries before they affect cash calculations. |
| FR-TRX-06 | Must     | Monetary amounts shall be stored and calculated using fixed-precision decimal values.         | No financial total relies on binary floating-point arithmetic.                                                     |
| FR-TRX-07 | Must     | Each transaction shall retain its source and relevant reference identifiers.                  | Manually entered, CSV-imported, and Loyverse-imported records can be distinguished.                                |

### 3.4 Customers, suppliers, invoices, and payments

| ID          | Priority | Requirement                                                                                           | Acceptance criteria                                                                                                                             |
|-------------|----------|-------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| FR-CRM-01   | Must     | Authorized users shall be able to create, view, update, and archive customer records.                 | Customer records are business-scoped and can be linked to invoices and receivables.                                                             |
| FR-CRM-02   | Must     | Authorized users shall be able to create, view, update, and archive supplier records.                 | Supplier records are business-scoped and can be linked to bills and payables.                                                                   |
| FR-AR-01    | Must     | Authorized users shall be able to create customer invoices.                                           | Invoice number, customer, issue date, due date, line items or description, and totals are validated and saved.                                  |
| FR-AR-02    | Must     | The system shall calculate invoice totals and outstanding balances.                                   | Full and partial payments update the balance correctly; paid, partially paid, overdue, and unpaid states are consistent with dates and amounts. |
| FR-AR-03    | Must     | Authorized users shall be able to record full or partial customer payments.                           | A payment is linked to the invoice and customer; the outstanding balance is recalculated.                                                       |
| FR-AR-04    | Must     | The system shall identify unpaid and overdue invoices.                                                | Invoice status and due date determine overdue status; the outstanding amount is shown.                                                          |
| FR-AP-01    | Must     | Authorized users shall be able to record supplier bills and obligations.                              | Supplier, amount, issue date, due date, and payment status are stored and business-scoped.                                                      |
| FR-AP-02    | Must     | Authorized users shall be able to record full or partial supplier payments.                           | The remaining payable balance is recalculated and reflected in cash-flow calculations.                                                          |
| FR-AP-03    | Must     | The system shall identify upcoming and overdue supplier obligations.                                  | Lists show supplier, due date, outstanding amount, and status.                                                                                  |
| FR-AR-AP-01 | Must     | The system shall preserve the distinction between an invoice or bill and the actual payment movement. | Creating an invoice or bill alone does not incorrectly increase or decrease confirmed cash.                                                     |
| FR-AR-AP-02 | Should   | The system shall generate an invoice PDF.                                                             | The generated PDF reflects the saved invoice data and totals; PDF failure does not alter the invoice.                                           |

### 3.5 CSV import

| ID        | Priority | Requirement                                                                                                   | Acceptance criteria                                                                                                                             |
|-----------|----------|---------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| FR-CSV-01 | Must     | The system shall accept UTF-8 CSV files with a header row.                                                    | Invalid file encodings or missing headers are reported before import.                                                                           |
| FR-CSV-02 | Must     | The system shall provide a column-mapping preview.                                                            | Users can map required and optional source columns to AGOS fields before committing.                                                            |
| FR-CSV-03 | Must     | The importer shall require date, type, amount, currency, and a description or source reference.               | Rows missing required values are rejected with a row-specific reason.                                                                           |
| FR-CSV-04 | Must     | The importer shall accept ISO 8601 dates or an explicitly selected day-month-year format.                     | Ambiguous dates are rejected rather than guessed.                                                                                               |
| FR-CSV-05 | Must     | The importer shall validate amounts and currency.                                                             | Amounts are valid nonnegative decimal strings with at most two fractional digits; non-PHP currency is rejected for review in the first release. |
| FR-CSV-06 | Must     | The importer shall report exact and probable duplicates before import.                                        | Duplicate external IDs and probable duplicate rows are identified for user review.                                                              |
| FR-CSV-07 | Must     | The importer shall allow valid rows to be committed as a reviewed batch.                                      | Invalid rows do not silently enter the database; valid rows can be committed with an import summary.                                            |
| FR-CSV-08 | Must     | The importer shall provide a downloadable row-numbered error report.                                          | Each rejected row includes its source row number and reason.                                                                                    |
| FR-CSV-09 | Must     | The system shall identify forecast history as incomplete when the imported transaction history is incomplete. | The application does not invent opening balances or represent incomplete history as complete.                                                   |

### 3.6 Loyverse POS integration

| ID        | Priority | Requirement                                                                                              | Acceptance criteria                                                                                          |
|-----------|----------|----------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------|
| FR-LYV-01 | Must     | The system shall allow an authorized Business Owner to configure Loyverse credentials for a business.    | Credentials are stored server-side and are not exposed to the browser or committed to source control.        |
| FR-LYV-02 | Must     | The Loyverse adapter shall retrieve sales transactions using the Loyverse API.                           | Requests use the configured credentials and supported API fields.                                            |
| FR-LYV-03 | Must     | The first-release integration shall be read-only.                                                        | AGOS does not create, edit, or delete Loyverse records.                                                      |
| FR-LYV-04 | Must     | Retrieved transactions shall be mapped into the AGOS transaction format.                                 | Mapped records retain `source_system = loyverse`, external transaction ID, and synchronization timestamp.    |
| FR-LYV-05 | Must     | The adapter shall validate required source fields and their financial meaning before accepting a record. | Missing or conflicting records are quarantined with a reason and are excluded from verified cash totals.     |
| FR-LYV-06 | Must     | The adapter shall prevent duplicate imports.                                                             | Business ID plus external transaction ID identifies an imported transaction; updates do not double-count it. |
| FR-LYV-07 | Must     | The system shall support user-triggered synchronization.                                                 | The user can start a sync and view its status, summary, and last successful synchronization time.            |
| FR-LYV-08 | Should   | The system may support scheduled synchronization.                                                        | Scheduled sync is enabled only after API access and local execution behavior are verified.                   |
| FR-LYV-09 | Must     | The system shall preserve successful pages and checkpoints when a multi-page sync is interrupted.        | An incomplete sync is reported as incomplete and is not marked fully successful.                             |
| FR-LYV-10 | Must     | The system shall show a clear error and preserve existing data when Loyverse is unavailable.             | Previously imported records remain available and are labeled with their last successful sync time.           |

**Loyverse financial interpretation rules**

- Only the amount actually paid contributes to confirmed cash; an unpaid
  order total does not.
- Discounts reduce the paid sales amount.
- Included tax is part of the receipt total and is not added again.
- A completed refund is a separate negative cash movement on its
  effective refund date and references the original receipt where
  possible.
- Split payments are summed to the collected amount and retain their
  payment methods.
- Unpaid, void, pending, or unconfirmed payments do not increase current
  cash. Expected but uncertain collections remain separately labeled in
  forecasts.
- If required status, amount, currency, source ID, or transaction date
  is missing, or values conflict beyond PHP 0.01 rounding tolerance, the
  record is quarantined.
- Non-PHP amounts are rejected for review; the first release performs no
  currency conversion.
- A source ID plus business ID prevents duplicate cash movements.
  Updated source records replace the normalized representation without
  double-counting.

### 3.7 Cash-flow calculation and forecasting

| ID       | Priority | Requirement                                                                                       | Acceptance criteria                                                                                                               |
|----------|----------|---------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------|
| FR-CF-01 | Must     | The backend shall calculate current cash from verified, confirmed cash movements.                 | Results reconcile with independently prepared test cases.                                                                         |
| FR-CF-02 | Must     | The backend shall calculate forecast inflows and outflows from applicable financial records.      | Forecast inputs and their source records can be inspected.                                                                        |
| FR-CF-03 | Must     | The forecast shall distinguish confirmed, expected, uncertain, and optional items.                | Each item is classified and displayed according to its status; uncertain or optional amounts are not misrepresented as confirmed. |
| FR-CF-04 | Must     | The system shall calculate projected cash over a selected short-term forecast period.             | The calculation is deterministic for the same input records and settings.                                                         |
| FR-CF-05 | Must     | The system shall compare projected cash against the business's cash-safety threshold.             | Threshold crossings are identified with date and amount.                                                                          |
| FR-CF-06 | Must     | The system shall calculate the cash gap when projected cash falls below the threshold.            | The gap equals the shortfall relative to the configured threshold at the relevant forecast point.                                 |
| FR-CF-07 | Must     | The system shall identify the earliest projected shortfall date.                                  | The earliest crossing date is returned when a shortfall occurs.                                                                   |
| FR-CF-08 | Must     | The calculation engine shall run in backend services, not in frontend components or AI providers. | Frontend displays returned calculation results; the AI receives verified outputs only.                                            |
| FR-CF-09 | Must     | The system shall disclose incomplete or stale source data that affects a forecast.                | Forecasts identify missing history, stale POS sync, or other relevant data limitations.                                           |

### 3.8 Form-based Scenario Simulator

| ID        | Priority | Requirement                                                                            | Acceptance criteria                                                                                      |
|-----------|----------|----------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------|
| FR-SIM-01 | Must     | The system shall provide a form-based Scenario Simulator.                              | Users can enter hypothetical values and submit a scenario without editing actual records.                |
| FR-SIM-02 | Must     | The simulator shall support a customer payment delay.                                  | The user can select an invoice and delay its expected payment date by a specified number of days.        |
| FR-SIM-03 | Must     | The simulator shall support a reduction in sales.                                      | The user can enter a sales reduction as an amount or percentage and select a forecast period.            |
| FR-SIM-04 | Must     | The simulator shall support an increase in expenses.                                   | The user can enter the hypothetical expense amount and date.                                             |
| FR-SIM-05 | Must     | The simulator shall support a delayed supplier payment.                                | The user can select an obligation and change its hypothetical payment date.                              |
| FR-SIM-06 | Must     | The simulator shall support a customer deposit.                                        | The user can enter a hypothetical deposit amount and date.                                               |
| FR-SIM-07 | Must     | The simulator shall support a proposed purchase.                                       | The user can enter the purchase amount, proposed date, payment terms, and expected related income.       |
| FR-SIM-08 | Must     | The simulator shall compare the normal forecast with the hypothetical forecast.        | Results show projected cash, threshold impact, cash gap, and earliest shortfall date for both forecasts. |
| FR-SIM-09 | Must     | Scenario records and calculations shall remain separate from actual financial records. | Running, editing, or deleting a scenario does not change transactions, invoices, bills, or actual cash.  |
| FR-SIM-10 | Should   | The system shall allow users to retain and revisit scenario results.                   | Saved scenarios are business-scoped and display their inputs and calculated outputs.                     |

### 3.9 Purchase-affordability analysis

| ID        | Priority | Requirement                                                                                                           | Acceptance criteria                                                                                                     |
|-----------|----------|-----------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------|
| FR-PUR-01 | Must     | The system shall accept a proposed purchase amount, date, payment terms, and expected related income.                 | All required inputs are validated before analysis.                                                                      |
| FR-PUR-02 | Must     | The system shall determine whether the proposed purchase may cause projected cash to fall below the safety threshold. | The result identifies the relevant projected date, cash gap, and contributing purchase assumptions.                     |
| FR-PUR-03 | Should   | The system shall allow comparison of alternatives.                                                                    | Users can compare purchasing immediately, delaying the purchase, reducing the amount, or requesting a customer deposit. |
| FR-PUR-04 | Must     | Purchase analysis shall be hypothetical until the user separately records an actual transaction.                      | The analysis does not create a real expense or payment.                                                                 |

### 3.10 Action Center and recommendations

| ID        | Priority | Requirement                                                                                                           | Acceptance criteria                                                                                                                     |
|-----------|----------|-----------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| FR-REC-01 | Must     | The system shall generate rule-based candidate actions from verified financial data.                                  | At least one applicable action is produced for supported risk conditions; otherwise the system indicates that no action was identified. |
| FR-REC-02 | Must     | The Action Center shall prioritize recommendations.                                                                   | Recommendations show priority, reason, related record, and relevant amount or date when available.                                      |
| FR-REC-03 | Must     | A recommendation shall identify the financial records or calculations supporting it.                                  | Each displayed recommendation includes evidence references resolvable to AGOS records or calculation results.                           |
| FR-REC-04 | Must     | The system shall support an AI-provider adapter for Groq or Gemini.                                                   | The application uses a common internal request and response contract independent of the selected provider.                              |
| FR-REC-05 | Must     | The AI shall receive only verified AGOS results and candidate actions needed to explain the situation.                | The AI request does not provide unrestricted database access.                                                                           |
| FR-REC-06 | Must     | The AI shall explain financial risk, prioritize candidate actions, and identify missing information.                  | The response is understandable and refers to supplied evidence and limitations.                                                         |
| FR-REC-07 | Must     | The AI shall not calculate official cash balances, invoice balances, forecasts, or scenario results.                  | Those values are calculated by AGOS and passed to the AI as verified inputs.                                                            |
| FR-REC-08 | Must     | The AI shall not modify financial records, approve loans, send messages automatically, or execute payments.           | No such action is exposed through the AI integration.                                                                                   |
| FR-REC-09 | Must     | The system shall validate AI output against a defined response schema and evidence references.                        | Missing required fields, malformed output, or unsupported actions are rejected.                                                         |
| FR-REC-10 | Must     | The system shall fall back to rule-based recommendations if the AI provider is unavailable or returns invalid output. | The Action Center remains usable and indicates that the rule-based result is being shown.                                               |
| FR-REC-11 | Must     | The system shall disclose when recommendations use incomplete or stale information.                                   | The user can identify the relevant data limitation.                                                                                     |

### 3.11 Financing readiness

| ID        | Priority | Requirement                                                                             | Acceptance criteria                                                                                                                                  |
|-----------|----------|-----------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------|
| FR-FIN-01 | Must     | The system shall assess whether selected financial information is available and recent. | The checklist covers transaction records, income and expenses, invoices, supplier obligations, cash-flow reports, and selected supporting documents. |
| FR-FIN-02 | Must     | The system shall identify missing or incomplete records.                                | Each missing item is listed with a clear explanation.                                                                                                |
| FR-FIN-03 | Should   | The system shall generate a financing-readiness report.                                 | The report summarizes available information, missing items, and the assessment date.                                                                 |
| FR-FIN-04 | Must     | The system shall not predict loan approval or determine financing eligibility.          | UI labels and reports describe record readiness only, not approval likelihood or qualification.                                                      |

### 3.12 Reports and PDF generation

| ID        | Priority | Requirement                                                                                 | Acceptance criteria                                                                            |
|-----------|----------|---------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------|
| FR-RPT-01 | Must     | The system shall generate cash-flow summary reports.                                        | Report totals match the approved AGOS report preview.                                          |
| FR-RPT-02 | Must     | The system shall generate accounts receivable and accounts payable reports.                 | Report balances match the underlying invoice, bill, and payment records.                       |
| FR-RPT-03 | Must     | The system shall generate scenario result reports.                                          | Report shows scenario inputs and normal-versus-scenario results.                               |
| FR-RPT-04 | Should   | The system shall generate financing-readiness and recommendation evidence reports.          | Reports show their source data, limitations, and generation date.                              |
| FR-RPT-05 | Must     | The system shall use Gotenberg to convert AGOS-prepared HTML into PDF.                      | Gotenberg returns a PDF with `application/pdf` content type.                                   |
| FR-RPT-06 | Must     | The system shall validate report data before sending it to Gotenberg.                       | Financial values are calculated and authorized by AGOS before document rendering.              |
| FR-RPT-07 | Must     | The system shall preserve report data and provide a retry option when PDF generation fails. | Failure does not alter financial records; an HTML preview remains available where implemented. |
| FR-RPT-08 | Must     | The system shall generate PDFs only for records the current user is authorized to access.   | Cross-business report requests are rejected.                                                   |

### 3.13 Audit events and operational errors

| ID        | Priority | Requirement                                                                                                                                                                               | Acceptance criteria                                                                                                           |
|-----------|----------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------|
| FR-AUD-01 | Must     | The system shall record audit events for sign-in attempts, permission changes, imports and syncs, financial record changes, payment recording, report generation, and manual corrections. | Each event includes actor ID, business ID where applicable, event type, target type and ID, UTC time, result, and request ID. |
| FR-AUD-02 | Must     | The audit record shall capture before-and-after values for changes to monetary amounts.                                                                                                   | The change can be reviewed by an authorized business owner.                                                                   |
| FR-AUD-03 | Must     | Audit logs shall not store passwords, API tokens, full provider payloads, or unnecessary personal information.                                                                            | Inspection of logs confirms sensitive secrets and unnecessary payloads are absent.                                            |
| FR-AUD-04 | Must     | Business owners shall be able to view their business audit events.                                                                                                                        | Audit queries are business-scoped and owner-authorized.                                                                       |
| FR-AUD-05 | Must     | Operational logs shall support troubleshooting without exposing business financial content to platform operators by default.                                                              | Operational entries use request IDs and error metadata without unnecessary financial values.                                  |
| FR-AUD-06 | Must     | The initial audit-log retention period shall be 90 days.                                                                                                                                  | A documented retention/deletion process is applied to the local implementation.                                               |

### 3.14 User experience and interface behavior

| ID       | Priority | Requirement                                                                                                                                                             | Acceptance criteria                                                                                                                                                                                                                                                                            |
|----------|----------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| FR-UI-01 | Must     | The system shall use a consistent application shell across authenticated business pages.                                                                                | Pages share consistent navigation, page headers, business context, content spacing, and responsive behavior.                                                                                                                                                                                   |
| FR-UI-02 | Must     | The system shall show navigation items according to the authenticated user's role and business permissions.                                                             | Users do not see links to unavailable functions, and direct URL/action access is still denied by backend authorization.                                                                                                                                                                        |
| FR-UI-03 | Must     | The system shall use shared design tokens and reusable interface patterns for colors, typography, spacing, borders, buttons, forms, tables, dialogs, and notifications. | Equivalent controls look and behave consistently across modules; one-off styling is avoided where a shared component exists.                                                                                                                                                                   |
| FR-UI-04 | Must     | Forms shall provide clear labels, required-field indicators, input guidance, and field-level validation feedback.                                                       | Invalid input is identified next to the relevant field; errors are understandable and do not expose technical details.                                                                                                                                                                         |
| FR-UI-05 | Must     | Data tables shall provide appropriate search, filtering, sorting, pagination, and empty states based on the type and volume of data displayed.                          | Users can identify active filters, clear them, and understand when no records match.                                                                                                                                                                                                           |
| FR-UI-06 | Must     | The interface shall provide loading, success, validation-error, empty, and failure states for asynchronous and data-dependent actions.                                  | Users receive visible feedback during Livewire requests and after successful or failed operations; duplicate submissions are prevented where necessary.                                                                                                                                        |
| FR-UI-07 | Must     | Destructive or consequential actions shall require an appropriate confirmation or clear review step.                                                                    | Archiving, deleting, removing a team member, or committing an import cannot happen accidentally; confirmation identifies the affected item/action.                                                                                                                                             |
| FR-UI-08 | Must     | Financial values and statuses shall be presented consistently and clearly.                                                                                              | PHP amounts use consistent currency formatting; dates use the business timezone; status is not communicated by color alone and includes text or an accessible icon/label.                                                                                                                      |
| FR-UI-09 | Must     | The interface shall prioritize important financial information and distinguish confirmed, expected, uncertain, and optional cash-flow items.                            | Labels, visual hierarchy, legends, and supporting text make the meaning of each amount understandable without relying on color alone.                                                                                                                                                          |
| FR-UI-10 | Must     | The application shall adapt to desktop, tablet, and mobile screen sizes.                                                                                                | Core navigation, forms, tables, dashboards, and actions remain usable without unintended horizontal overflow at supported viewport sizes.                                                                                                                                                      |
| FR-UI-11 | Should   | The interface shall follow WCAG 2.2 AA accessibility principles where applicable.                                                                                       | Keyboard navigation, visible focus, semantic structure, accessible names/labels, sufficient text and control contrast, and understandable validation feedback are tested.                                                                                                                      |
| FR-UI-12 | Should   | Charts and visual summaries shall include titles, units, legends or labels, and an accessible textual explanation of the data.                                          | Users can understand the chart's subject, time range, units, and series; key values are not available only through color or hover.                                                                                                                                                             |
| FR-UI-13 | Must     | The interface shall maintain consistent navigation and feedback across business onboarding, business profile settings, financial modules, and reports.                  | Users can identify their active business, understand their current location, and return to relevant sections without relying on the Laravel logo or browser history.                                                                                                                           |
| FR-UI-14 | Must     | The system shall provide an AGOS-branded public landing page for guests.                                                                                                | The page explains AGOS accurately, provides clear login and registration actions, does not expose business data, and distinguishes available features from planned features. Authenticated users are directed to the appropriate dashboard or onboarding flow.                                 |
| FR-UI-15 | Must     | Public and authentication pages shall follow the AGOS visual identity and shared usability standards.                                                                   | The landing page, login, registration, password recovery, email verification, two-factor authentication, and passkey pages where enabled use consistent branding, responsive layouts, accessible controls, and clear validation and status feedback without weakening authentication security. |

The visual direction shall be a modern, clean, professional
financial-management/SaaS interface. The implementation shall use the
existing Laravel Livewire starter kit, Blade, Tailwind CSS, and Flux UI
components consistently. The design system shall define semantic colors,
typography, spacing, borders, radii, and elevation. Exact theme values
may be selected during implementation, but must be documented and
reused.

The application shell should provide role-aware navigation, a clear
active-business context, page titles, and consistent content layout.
Financial information should be visually prioritized without excessive
decoration, animation, gradients, or dense presentation.

### 3.15 UI/UX quality acceptance

A UI feature is acceptable only when its core workflow has been reviewed
for:

- Visual consistency with the shared AGOS design system.
- Responsive usability on desktop, tablet, and mobile.
- Clear loading, empty, success, validation, and failure states.
- Keyboard operation and visible focus.
- Readable financial values, dates, and status labels.
- Role-aware navigation plus backend authorization.
- Appropriate confirmation for destructive or consequential actions.
- No reliance on color alone to communicate financial meaning.

## 4. External interface requirements

### 4.1 User interface

The Laravel Blade and Livewire interface shall provide responsive pages
or views for:

- Public AGOS landing page for guests
- Sign-in, registration, password recovery and reset, email
  verification, two-factor authentication, passkey flows where enabled,
  and business onboarding
- Dashboard and cash overview
- Transactions and categories
- Customers and suppliers
- Invoices, payments, bills, receivables, and payables
- CSV import, mapping, preview, and import results
- Loyverse connection and synchronization status
- Scenario Simulator and purchase-affordability analysis
- Action Center and recommendation evidence
- Financing-readiness checklist
- Reports and PDF preview/download
- Business profile, Team users, settings, team invitation acceptance,
  and audit events according to the user's role

The public root route (`/`) shall display the AGOS landing page to
guests. Authenticated users whose active business has completed
onboarding shall be directed to the dashboard. Authenticated users whose
active business has not completed onboarding shall be directed to the
onboarding page.

The interface shall use a shared authenticated application shell with
role-aware navigation, active-business context, consistent page headers,
and reusable layout patterns. Use Tailwind CSS and the installed Flux UI
components consistently rather than introducing competing component
libraries without approval.

Forms, tables, dialogs, notifications, buttons, status indicators,
loading states, and empty states shall follow shared patterns. Financial
amounts shall use consistent PHP formatting; dates shall use the
business timezone. Charts shall identify their units, periods, and
series and provide a textual explanation of key information.

Responsive behavior is required for desktop, tablet, and mobile.
Accessibility should follow WCAG 2.2 AA principles where applicable,
including keyboard access, visible focus, semantic markup, accessible
labels, and sufficient contrast. Do not communicate financial status
through color alone.

The visual hierarchy shall prioritize current cash, projected cash,
cash-safety threshold, shortfall warnings, overdue obligations, and
recommended actions according to the page's purpose. Avoid excessive
decoration, animation, gradients, and unnecessary visual density.

### 4.2 Web routes, Livewire actions, and optional REST API

- Authenticated users access AGOS through Laravel `web.php` routes,
  Blade layouts, and Livewire components.
- Livewire actions call authorized Laravel application services; the UI
  does not need duplicate JSON endpoints for every CRUD operation.
- A versioned JSON API (`/api/v1`) may be exposed where required for an
  external client or a documented integration contract. Example
  resources include `/api/v1/transactions`, `/api/v1/invoices`,
  `/api/v1/scenarios`, `/api/v1/recommendations`,
  `/api/v1/integrations/loyverse`, and `/api/v1/reports`.
- When a JSON API is provided, it shall use documented schemas, standard
  methods and status codes, business-scoped authorization, validation,
  predictable error responses, and appropriate authentication.
- External provider HTTP calls shall use backend adapters and never
  expose provider credentials to the browser.
- The deterministic calculation and business services shall be shared by
  Livewire views, scheduled commands/jobs, and any optional API
  endpoints; no duplicate business-rule implementations are permitted.

### 4.3 Loyverse interface

- Use the official Loyverse API and its supported authentication
  mechanism.
- Retrieve only the data needed for sales and cash-flow records.
- Operate in read-only mode.
- Store source transaction IDs and synchronization timestamps.
- Validate provider responses before normalizing and saving records.
- Preserve checkpoints for multi-page synchronization.

### 4.4 AI interface

- Use either Groq API or Google Gemini API through a provider-neutral
  adapter.
- Send structured, verified financial values and candidate actions.
- Require a structured response containing an explanation, ranked
  actions, reasons, estimated cash effects where supported, evidence
  references, and limitations.
- Validate output before display.
- Use rule-based fallback when the provider is unavailable or its
  response is invalid.
- Keep provider API keys on the backend.

### 4.5 Gotenberg interface

- AGOS shall render authorized, validated report data into HTML using
  Laravel Blade templates.
- The Laravel backend shall submit the HTML via HTTPS and server-side
  Basic Authentication to **Render-hosted Gotenberg** (Chromium-only
  image), never directly from the browser.
- Gotenberg shall return a PDF response with `application/pdf` content
  type; AGOS shall verify the response and report totals.
- The Gotenberg instance has a 512 MiB RAM allocation; configure one
  active Chromium conversion and at most two queued requests.
- For AGOS, generate PDFs sequentially through one local Laravel
  **database-queue worker**, with a maximum of two pending report jobs
  and no Redis dependency. Reject or defer additional work safely.
- Use a 20-second Gotenberg request timeout and a 60-second report job
  limit. Retry at most once on transient 429/503 responses; do not retry
  authentication or validation failures.
- Preserve the validated report snapshot and HTML preview for manual
  retry after failure. A failed PDF conversion shall never modify
  financial records.
- Configure service URL and Basic Authentication values only through
  backend environment settings; the Render service has separate secret
  variables.

### 4.6 File interfaces

**CSV input**

- UTF-8 encoding and header row
- Required fields: date, type, amount, currency, and description or
  source reference
- Optional fields: customer, supplier, category, payment status, and
  external ID
- ISO 8601 dates or explicitly selected day-month-year format
- Invalid rows reported with row numbers and reasons

**PDF output**

- MIME type: `application/pdf`
- Generated from HTML using Gotenberg
- Contains only data calculated and authorized by AGOS

## 5. Data and business rules

### 5.1 Data ownership and isolation

Every business-owned record shall be associated with a business
identifier. Backend queries and file access shall be scoped to the
authenticated user's permitted business. The application shall not rely
on client-supplied business IDs as proof of authorization.

### 5.2 Monetary values and dates

- The default currency is PHP.
- Monetary values shall use fixed-precision decimal storage and
  arithmetic.
- Do not use PHP `float` or JavaScript `Number` for authoritative
  financial totals; use fixed-precision decimal strings and
  `BigDecimal`.
- Store timestamps in UTC.
- Display business-facing dates and times using `Asia/Manila`.
- The first release shall not perform currency conversion.

### 5.3 Actual records and hypothetical records

Actual transactions, invoices, bills, and payments shall be stored
separately from scenario inputs and scenario results. Scenario execution
shall never create or modify actual financial records.

### 5.4 CSV import rules

- Reject ambiguous dates rather than guessing.
- Reject malformed or negative amount values where the import format
  requires nonnegative amount strings.
- Treat income or expense type as the direction of the movement.
- Keep unknown categories unmapped for review.
- Detect duplicate external IDs and probable duplicate rows before
  committing.
- Do not invent opening balances when imported history is incomplete.

### 5.5 Loyverse reconciliation rules

Apply the financial interpretation rules in FR-LYV-01 through FR-LYV-10.
In particular, only confirmed paid amounts affect current cash; refunds
are negative movements; included taxes are not added twice; and
quarantined records are excluded from verified totals.

### 5.6 Recommendation evidence

Every displayed recommendation shall reference the AGOS records or
calculation results that support it. An AI-generated explanation is not
itself evidence that a financial event exists. The backend shall
validate evidence references before display.

## 6. Error handling and resilience requirements

| Operation                           | Initial limit                                                    | Required behavior on failure                                                                                             |
|-------------------------------------|------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------|
| Loyverse page request               | 8 seconds per request; 120 seconds per synchronization job       | Preserve completed pages and checkpoints. Report the synchronization as incomplete and do not publish unverified totals. |
| AI recommendation                   | 10 seconds for the provider request; 15 seconds overall          | Show a verified rule-based recommendation if the provider times out or returns invalid output.                           |
| Gotenberg conversion                | 20 seconds per request; 60 seconds per background report job     | Preserve the source report data, retain the HTML preview where available, and allow a retry.                             |
| Dashboard and scenario calculations | 3 seconds at the 95th percentile under the initial test workload | Display the relevant status and retain inputs while the performance issue is investigated.                               |

Retry behavior:

- For safe, idempotent reads, make at most three total attempts: the
  initial call and two retries.
- Use approximately 1-second and 2-second backoff plus jitter.
- Retry transient network errors and HTTP 500, 502, 503, and 504.
- For HTTP 429, a background job shall honor `Retry-After` up to 30
  seconds before re-queuing; an interactive request shall show status or
  fallback.
- Do not automatically retry HTTP 400, 401, 403, or 422.
- Do not automatically retry writes or PDF requests unless an
  idempotency key or equivalent duplicate safeguard exists.
- After repeated provider failures, pause calls for 60 seconds and
  perform a single recovery probe.
- A failed synchronization page shall not mark the entire sync complete.

For a local academic implementation, these values are initial
configuration targets and must be verified through tests. They are not
provider guarantees.

## 7. Quality and security constraints relevant to functionality

Although this document focuses on functional requirements, the following
constraints affect whether the functions are acceptable:

1.  Use Eloquent ORM or parameter-bound Laravel query builder
    statements.
2.  Validate all user input and external API responses.
3.  Store passwords using a modern salted one-way password-hashing
    algorithm.
4.  Store API credentials outside source code and do not commit `.env`
    files.
5.  Use server-side authorization for every protected operation.
6.  Apply CSRF protection to state-changing session requests and use
    Laravel CSRF middleware for web/Livewire requests. Restrict CORS
    only if a separate JSON API is exposed.
7.  Keep secrets and unnecessary personal or financial information out
    of logs.
8.  Use versioned Laravel migrations and test migration/rollback
    procedures.
9.  Keep the Laravel service-layer calculation engine authoritative for
    all official financial values.
10. Use separate adapters for Loyverse, AI, and Gotenberg.

## 8. Acceptance and verification

The system shall be evaluated using unit, integration,
interface/contract, failure, authorization, and acceptance tests.

### 8.1 Required functional test groups

- Authentication, idle timeout, logout, password reset, and session
  invalidation
- Role permissions and cross-business access rejection
- Transaction create, update, archive, and category behavior
- Invoice totals, partial payments, outstanding balances, and overdue
  detection
- Supplier bills, partial payments, and payable balances
- CSV mapping, ambiguous dates, invalid amounts, duplicates, partial
  batches, and error reports
- Loyverse paid, refunded, discounted, taxed, split-payment,
  missing-field, duplicate, and updated receipt cases
- Cash calculations, forecast classifications, threshold crossing, cash
  gap, and earliest shortfall date
- Scenario calculations and proof that actual records remain unchanged
- Purchase-affordability alternatives
- Rule-based recommendations, evidence references, AI schema validation,
  and fallback behavior
- Financing-readiness completeness checks and correct non-approval
  wording
- PDF totals, generated content, timeout, and retry behavior
- Audit event completeness and secret redaction
- UI/UX consistency, role-aware navigation, form validation and
  feedback, loading/empty/error states, destructive-action confirmation,
  responsive layouts, keyboard navigation, and chart accessibility

### 8.2 Initial performance acceptance targets

For initial performance testing, use a representative workload of 50
registered businesses and 20 concurrent users, with 30-day forecasts and
imports of up to 500 POS receipts.

| Operation                                               | Initial performance target       |
|---------------------------------------------------------|----------------------------------|
| Dashboard or scenario results using locally stored data | 3 seconds at the 95th percentile |
| User-requested recommendation, including fallback       | 15 seconds                       |
| Background synchronization of 500 POS receipts          | 120 seconds                      |

These are proposed acceptance targets to test on the development
computer, not measured results or service commitments.

### 8.3 Definition of functional completion

A requirement is complete when:

- Its expected behavior is implemented.
- Its input validation and authorization are implemented.
- Relevant unit and integration tests pass.
- External contracts and failure behavior are tested where applicable.
- Financial results reconcile with independently prepared expected
  values.
- The UI communicates errors, stale data, incomplete data, and fallback
  states clearly.
- The UI meets applicable FR-UI-01 through FR-UI-15 requirements,
  including consistency, responsive behavior, accessibility, and
  financial information clarity.
- The feature does not violate business-level data isolation or the
  separation between actual and hypothetical records.

## 9. Implementation baseline and traceability

The AGOS business functions, user roles, financial calculation rules,
external integrations, audit requirements, and acceptance criteria are
defined in this FRS. The implementation baseline is **Laravel 13 +
Livewire 4 + PostgreSQL** for local development.

- One Laravel modular monolith provides the application, UI, and
  server-side business logic.
- Livewire single-file components and Blade provide the UI; Chart.js
  provides charts.
- Eloquent models and Laravel database migrations manage persistence.
- `brick/math` `BigDecimal` (or equivalently tested decimal-safe
  arithmetic) provides fixed-precision application arithmetic.
- Laravel's official Livewire starter kit is configured with Teams
  support. The generated Team is the AGOS Business; its owner and
  membership roles are mapped to AGOS roles without duplicate tenancy
  tables.
- Single-file Livewire components are the default for simple and
  medium-sized UI components; complex components may use multi-file or
  class-based formats.
- Laravel session authentication, policies, authorization middleware,
  and Team membership checks supply the application foundation.
- Laravel HTTP client and dedicated adapters implement read-only
  Loyverse sync, AI explanations, and PDF generation.
- **Gotenberg remains hosted on Render**, with Basic Authentication and
  512 MiB resource constraints. AGOS and its database stay local.
- The UI uses server-side Livewire actions; `/api/v1` REST endpoints are
  built only where needed by a documented contract, without duplicating
  business services.

All functional requirement identifiers and acceptance criteria remain in
effect. Provider-specific API fields and account access must be verified
from current documentation during implementation; unsupported
capabilities must not be assumed.
