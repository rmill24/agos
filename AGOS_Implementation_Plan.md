# AGOS Implementation Plan

**Version:** 3.1\
**Purpose:** Build and verify AGOS for local development and academic
demonstration.\
**Schedule:** Setup phase plus six two-week development phases.\
**Architecture:** Modular monolith with REST APIs.

## 1. Project rules

### Required project documents

-   `AGOS_Functional_Requirements_Specification.md` defines system
    behavior and acceptance criteria.
-   This file defines implementation order and technical decisions.
-   `AGENTS.md` defines shared instructions for AI coding agents.

The FRS is the functional source of truth. If a requirement is ambiguous
or conflicts with existing data, stop and report the issue. Do not
invent financial rules or provider capabilities.

### Agent workflow

1.  Inspect the repository and `git status` before editing.
2.  Identify the earliest incomplete task.
3.  Implement one task or small related task group at a time.
4.  Add tests with each feature.
5.  Run relevant tests, type checks, lint, and build.
6.  Mark tasks complete only after verification.
7.  Report changed files, commands, results, blockers, and next task.
8.  Preserve existing work. Do not commit, push, deploy AGOS, or modify
    external accounts unless explicitly authorized.

Start with **P0-T01: Repository reconnaissance**. Do not scaffold or
rewrite the project before inspection.

## 2. Scope and stack

### In scope

Authentication and roles; business data isolation; transactions;
customers and suppliers; invoices and payments; bills and payables; CSV
import; read-only Loyverse sync; cash calculations and forecasts;
scenarios; purchase-affordability analysis; rule-based and AI-assisted
recommendations; financing-readiness checks; PDF reports; audit events;
automated tests.

### Boundaries

-   AGOS frontend, backend, and PostgreSQL run locally.
-   Gotenberg is hosted on Render and called by the local backend over
    HTTPS.
-   Loyverse is read-only.
-   AI explains verified AGOS results; it does not calculate official
    financial values or modify records.
-   No production deployment of AGOS, payment execution, automatic
    messaging, loan approval prediction, or currency conversion in the
    first release.

### Technology decisions

  Area               Technology
  ------------------ ---------------------------------------------------
  Frontend           React, TypeScript, Vite
  UI                 Tailwind CSS
  Charts             Recharts
  Backend            Node.js, TypeScript, Express
  Database           PostgreSQL
  ORM/migrations     Prisma
  Money arithmetic   Decimal.js and PostgreSQL `DECIMAL`
  Backend tests      Jest, Supertest
  Frontend tests     Vitest, React Testing Library
  API testing        Postman
  POS                Loyverse API
  AI                 Groq or Gemini through a provider-neutral adapter
  PDF                Render-hosted Gotenberg
  Version control    Git

Use existing compatible dependencies where possible. Add dependencies
only for a current requirement. Do not introduce microservices, Redis,
or a separate queue unless justified and approved.

## 3. Architecture and key rules

Use one frontend, one Express backend, and one PostgreSQL database.
Organize backend code into modules for authentication, businesses/users,
transactions, customers, suppliers, invoices, bills/payments, imports,
cash flow, scenarios, recommendations, financing readiness, reports, and
audit. Keep Loyverse, AI, and Gotenberg code in separate adapters.

Suggested repository layout:

``` text
AGOS/
├── AGENTS.md
├── README.md
├── env.example
├── AGOS_Functional_Requirements_Specification.md
├── AGOS_Implementation_Plan.md
├── frontend/
├── backend/
│   └── prisma/
└── docs/
    ├── decisions/
    ├── api/
    └── test-cases/
```

Adapt to the existing repository; do not move files solely to match this
example.

### Non-negotiable domain rules

-   Store money as fixed-precision decimals. Use Decimal.js/Prisma
    Decimal; never use JavaScript `Number` for authoritative monetary
    arithmetic.
-   Serialize API money values as decimal strings.
-   Store timestamps in UTC; display business dates using `Asia/Manila`.
-   Default currency is PHP. Do not convert currencies in the first
    release.
-   An invoice or supplier bill is an obligation, not a cash movement.
-   Record each real cash event exactly once. Link customer/supplier
    payments to their corresponding ledger movements; prevent
    double-counting.
-   Do not invent opening balances. If history is incomplete, label cash
    and forecasts incomplete.
-   Separate confirmed cash, expected items, uncertain collections, and
    optional expenses.
-   Only confirmed paid Loyverse amounts affect actual cash. Discounts
    reduce sales; included tax is not added again; completed refunds are
    separate negative movements; split payments are summed. Quarantine
    invalid or conflicting records. Do not import non-PHP amounts into
    verified totals.
-   Scenario execution must never modify actual financial records.
-   AGOS calculates cash, balances, forecasts, gaps, and scenario
    results. AI only explains verified results and candidate actions.
-   Recommendations must reference AGOS evidence. Invalid or unavailable
    AI output falls back to rule-based recommendations.
-   Financing readiness checks document completeness; it must not
    predict loan approval or eligibility.

Document unresolved business-rule decisions in `docs/decisions/` and
obtain approval before implementing them.

## 4. Phased implementation backlog

Complete phases in order. Keep the application runnable at each phase
gate.

### Phase 0 --- Repository and local setup

**P0-T01** Inspect repository, Git status, existing code, scripts,
tests, and project documents. Report current state and next task.\
**P0-T02** Set up or verify React/Vite frontend, Express/TypeScript
backend, and PostgreSQL.\
**P0-T03** Configure Prisma, migrations, environment loading, lint,
formatting, and type checking.\
**P0-T04** Verify frontend-to-backend and backend-to-database
connectivity.\
**P0-T05** Update `README.md`, `env.example`, and `.gitignore`.

**Gate:** A developer can run the frontend, backend, and database
locally using documented commands. Secrets are not committed.

### Phase 1 --- Foundation, authentication, and transactions

**P1-T01** Define Prisma models for users, businesses,
memberships/roles, sessions, categories, transactions, and audit
events.\
**P1-T02** Implement migrations, seed data, validation, centralized
errors, and request IDs.\
**P1-T03** Implement password hashing, server-managed sessions,
login/logout, 30-minute idle expiry, and session revocation.\
**P1-T04** Implement role-based authorization and business-scoped
backend queries.\
**P1-T05** Implement transaction create/read/update/archive and category
management.\
**P1-T06** Add audit events for authentication and financial changes.\
**P1-T07** Build login, navigation, business context, and transaction
screens.

**Gate:** Roles and cross-business isolation are tested; transaction
CRUD works; money uses decimal arithmetic.

### Phase 2 --- Financial records and CSV import

**P2-T01** Implement customer and supplier management.\
**P2-T02** Implement invoices, invoice totals, customer payments,
partial payments, and receivable balances.\
**P2-T03** Implement supplier bills, supplier payments, partial
payments, and payable balances.\
**P2-T04** Ensure invoices/bills alone do not affect actual cash;
payment events create one linked cash movement.\
**P2-T05** Implement CSV upload, column mapping, preview, validation,
duplicate review, and reviewed batch import.\
**P2-T06** Generate row-numbered CSV error reports and flag incomplete
history.\
**P2-T07** Build customer, supplier, invoice, bill, payment, receivable,
payable, and import screens.

**Gate:** Invoice/payable balances and partial payments pass tests;
invalid or duplicate CSV rows are handled safely.

### Phase 3 --- Loyverse integration

**P3-T01** Verify current Loyverse API documentation, access,
authentication, pagination, and available fields.\
**P3-T02** Define and implement a read-only Loyverse adapter.\
**P3-T03** Map and validate source records; retain source IDs and sync
timestamps.\
**P3-T04** Prevent duplicates using business ID plus source transaction
ID.\
**P3-T05** Implement user-triggered sync, checkpoints, sync history, and
incomplete/stale status.\
**P3-T06** Quarantine records with missing or conflicting required
values.\
**P3-T07** Add mocked fixtures and tests for paid, unpaid, discounted,
taxed, refunded, split-payment, duplicate, updated, invalid, and non-PHP
records.

**Gate:** No write operations to Loyverse; imported cash reconciles
against independently calculated test cases; interrupted sync is never
marked complete.

### Phase 4 --- Cash engine and scenarios

**P4-T01** Implement deterministic backend services for current cash and
cash movements.\
**P4-T02** Implement forecast inputs and classifications for confirmed,
expected, uncertain, and optional items.\
**P4-T03** Implement forecast periods, cash-safety threshold, cash gap,
and earliest shortfall date.\
**P4-T04** Identify source records contributing to forecast risks and
disclose stale/incomplete data.\
**P4-T05** Implement isolated scenario models and calculations for
payment delay, sales reduction, expense increase, supplier delay,
customer deposit, and proposed purchase.\
**P4-T06** Compare baseline and scenario forecasts.\
**P4-T07** Implement purchase-affordability analysis and supported
alternatives.\
**P4-T08** Build forecast, scenario, and purchase-analysis
forms/results.

**Gate:** Financial results reconcile to independent fixtures; scenarios
cannot change actual records; forecasts disclose incomplete data.

### Phase 5 --- Dashboard and recommendations

**P5-T01** Build dashboard for current/projected cash, trends,
inflows/outflows, receivables, payables, threshold, and shortfall.\
**P5-T02** Add charts using Recharts and visible stale/incomplete-data
indicators.\
**P5-T03** Implement deterministic candidate actions and prioritization
rules.\
**P5-T04** Attach evidence references to every recommendation.\
**P5-T05** Define a provider-neutral AI request/response contract.\
**P5-T06** Implement one AI provider (Groq or Gemini) first.\
**P5-T07** Validate AI schema, evidence references, and supported
actions.\
**P5-T08** Add timeout, outage, invalid-response handling, and
rule-based fallback.\
**P5-T09** Build Action Center and recommendation detail views.

**Gate:** Dashboard matches backend calculations; recommendations are
evidence-linked; AI cannot alter records or replace deterministic
calculations; fallback works.

### Phase 6 --- Reports, financing readiness, and completion

**P6-T01** Configure the Render-hosted Gotenberg service using the
Chromium-only Gotenberg v9 image (`gotenberg/gotenberg:9-chromium`);
AGOS needs HTML-to-PDF conversion, not LibreOffice conversion.\
**P6-T02** Configure Render variables: `API_PORT_FROM_ENV=PORT`,
`API_ENABLE_BASIC_AUTH=true`, `GOTENBERG_API_BASIC_AUTH_USERNAME`,
`GOTENBERG_API_BASIC_AUTH_PASSWORD`, `CHROMIUM_MAX_CONCURRENCY=1`,
`CHROMIUM_MAX_QUEUE_SIZE=2`, and `API_TIMEOUT=20s`. Set Render's health
check path to `/health`.\
**P6-T03** Configure the local backend with `GOTENBERG_BASE_URL`,
`GOTENBERG_USERNAME`, and `GOTENBERG_PASSWORD`; call Gotenberg over
HTTPS using Basic Authentication. Never expose these values to React.\
**P6-T04** Implement a backend PDF service that allows only one active
conversion at a time and uses a bounded pending queue. Persist report
inputs/data snapshots and generated HTML so a failed request can be
previewed and retried. Do not add Redis or another queue service.\
**P6-T05** Create validated HTML templates and PDF generation for
invoices and required reports; validate PDF content type and totals.\
**P6-T06** Apply a 20-second Gotenberg request timeout and a 60-second
overall report-job limit. Retry at most once for transient 429/503
responses; do not retry validation or authentication failures.\
**P6-T07** Implement HTML preview and user-triggered retry after PDF
failure. PDF failure must not alter financial records or discard report
data.\
**P6-T08** Implement financing-readiness completeness checks and report
wording; complete audit-event views and retention behavior.\
**P6-T09** Run unit, integration, API contract, failure, and
authorization test suites; verify cross-business access for records,
imports, scenarios, reports, and audit events.\
**P6-T10** Prepare deterministic demo data, local run instructions,
Render Gotenberg configuration notes, known limitations, and a
repeatable demonstration script.

**Gate:** The 512 MiB service handles one conversion at a time; PDFs are
generated through authenticated Render-hosted Gotenberg; failures retain
the report data and HTML preview; retry works; critical tests pass; the
full application can be demonstrated locally.

## 5. API and environment conventions

Use `/api/v1` for REST endpoints. Add routes only as each feature is
implemented. Likely route groups include:

-   `/auth`, `/businesses`, `/users`
-   `/transactions`, `/categories`, `/customers`, `/suppliers`
-   `/invoices`, `/payments`, `/bills`, `/imports`
-   `/integrations/loyverse`, `/cash-position`, `/forecasts`
-   `/scenarios`, `/purchase-affordability`, `/dashboard`
-   `/recommendations`, `/reports`, `/financing-readiness`,
    `/audit-events`

Validate all request data and enforce business scope in the backend. Use
consistent error responses; do not expose stack traces, SQL details, or
secrets.

Backend environment variables should include:

``` dotenv
NODE_ENV=development
PORT=3000
FRONTEND_ORIGIN=http://localhost:5173
DATABASE_URL=
SESSION_SECRET=
LOYVERSE_ACCESS_TOKEN=
AI_PROVIDER=groq
GROQ_API_KEY=
GEMINI_API_KEY=
GOTENBERG_BASE_URL=https://your-render-gotenberg-service.onrender.com
GOTENBERG_USERNAME=
GOTENBERG_PASSWORD=
```

Configure the Render Gotenberg service separately with:

| Render variable | Value / purpose |
|---|---|
| `API_PORT_FROM_ENV` | `PORT` — use Render's assigned port |
| `API_ENABLE_BASIC_AUTH` | `true` |
| `GOTENBERG_API_BASIC_AUTH_USERNAME` | Strong, unique username |
| `GOTENBERG_API_BASIC_AUTH_PASSWORD` | Strong, randomly generated password |
| `CHROMIUM_MAX_CONCURRENCY` | `1` — one active conversion |
| `CHROMIUM_MAX_QUEUE_SIZE` | `2` — bounded waiting queue |
| `API_TIMEOUT` | `20s` — per-request limit |

Use the Chromium-only Gotenberg v9 image
(`gotenberg/gotenberg:9-chromium`) because AGOS sends HTML and needs
Chromium conversion, not LibreOffice document conversion. Set Render's
health check path to `/health`; Gotenberg's health endpoint does not
require Basic Authentication.

The 512 MiB allocation is Gotenberg's documented minimum, not a
guarantee for large or concurrent workloads. Keep conversions
sequential, avoid large images and unnecessarily long reports, and
monitor Render memory and service logs. If conversions repeatedly fail
from memory pressure, reduce report complexity or increase the service
memory.

The backend must serialize PDF requests (one active conversion) and use
a bounded queue. Persist the report data snapshot and generated HTML so
the user can preview and retry after a failure. Use a 20-second
Gotenberg request timeout and a 60-second overall report-job limit.
Retry at most once for transient 429/503 responses; do not retry 400,
401, or 403.

Keep Render credentials in Render's secret environment settings and
backend credentials in the local untracked `.env`. Never expose them to
React or commit real `.env` files.

## 6. Testing and quality gates

For each task, add tests for relevant success and failure cases.

Required test categories:

-   Unit tests: money arithmetic, balances, forecasting, scenarios,
    validation, and permissions.
-   Integration tests: database-backed transaction, invoice, payment,
    import, and report workflows.
-   Contract tests: Loyverse, AI, and Gotenberg adapters using verified
    schemas and fixtures.
-   Failure tests: timeouts, unavailable services, rate limits,
    malformed responses, and interrupted sync.
-   PDF tests: one-at-a-time conversion, bounded queue behavior,
    20-second provider timeout, 60-second job deadline, 429/503 retry,
    HTML preview, retry after failure, and no financial-record mutation.
-   Authorization tests: role boundaries and cross-business isolation.
-   Acceptance tests: login through core financial workflows, scenarios,
    recommendations, and reports.

A task is complete only when its acceptance criteria are implemented,
tests pass, authorization is enforced, errors are handled, and
documentation is updated. Never mark unrun tests as passing or remove a
failing test just to obtain a green result.

Suggested root scripts (or documented equivalents):

``` bash
npm run dev
npm run build
npm run test
npm run lint
npm run typecheck
npm run db:migrate
npm run db:seed
```

### Performance targets

Measure and report results; do not claim success without testing.

  Operation                                    Target
  ----------------------------------- ---------------
  Dashboard/scenario calculation        3 seconds p95
  Recommendation including fallback        15 seconds
  Sync of 500 POS receipts                120 seconds

Record test hardware, dataset size, and concurrency with results.

## 7. Schedule and deferrals

  Phase                       Sprint Outcome
  --------------------- ------------ -------------------------------------
  0\. Setup               Pre-sprint Local app and database run
  1\. Foundation                   1 Authentication, roles, transactions
  2\. Records/imports              2 Customers, invoices, bills, CSV
  3\. Loyverse                     3 Read-only POS synchronization
  4\. Cash engine                  4 Forecasts and scenarios
  5\. Dashboard/AI                 5 Action Center and recommendations
  6\. Reports/testing              6 PDFs, financing readiness, demo

Estimated duration: 12 weeks plus 2--3 setup days.

If time is limited, defer scheduled POS sync, a second AI provider,
advanced recurring automation, extra report layouts, and nonessential UI
polish. Do not defer financial correctness, authorization, business
isolation, duplicate prevention, scenario isolation, evidence
validation, or safe fallbacks.
