# AGENTS.md --- AGOS Coding Instructions

These shared instructions apply to any coding agent working in this
repository.

## 1. Read before coding

Read these files when present:

1.  `AGOS_Functional_Requirements_Specification.md` --- functional
    requirements and acceptance criteria.
2.  `AGOS_Implementation_Plan.md` --- implementation phases, task IDs,
    and technical decisions.
3.  `README.md` --- local setup.

The FRS defines **what** the system must do. The implementation plan
defines **what to build next**. This file defines **how to work**.

If requirements conflict or a financial rule is unclear, stop and report
the issue. Do not invent requirements or silently choose a business
rule.

## 2. Start with repository inspection

Before editing:

-   Inspect the repository, package scripts, environment files, Prisma
    schema, migrations, tests, and `git status`.
-   Identify existing functionality and the earliest incomplete task in
    the implementation plan.
-   Verify task checkboxes against code and test evidence.
-   Preserve existing work; do not scaffold over or rewrite a working
    project just to match an example structure.

Start with repository reconnaissance. Report the current state and next
task before making substantial changes.

## 3. Work incrementally

For each task or small related task group:

1.  State the task ID and intended result.
2.  Inspect relevant code before editing.
3.  Implement the smallest complete change.
4.  Add or update tests.
5.  Run relevant tests, type checks, lint, and build.
6.  Review the diff for unrelated changes, secrets, and generated files.
7.  Report changed files, verification results, blockers, and next task.
8.  Mark tasks complete only after acceptance criteria are verified.

Do not claim tests passed if they were not run. Do not begin a later
phase while mandatory phase criteria remain unmet unless the user
approves.

## 4. Project scope and stack

AGOS is a local-development and academic-demonstration application.

Use the selected stack unless the user approves a change:

-   Frontend: React, TypeScript, Vite, Tailwind CSS, Recharts
-   Backend: Node.js, TypeScript, Express
-   Database: PostgreSQL
-   ORM/migrations: Prisma
-   Financial arithmetic: Decimal.js / Prisma Decimal
-   Tests: Jest or the test tools already configured
-   POS: Loyverse API, read-only
-   AI: Groq or Gemini through a provider-neutral adapter
-   PDF: Gotenberg hosted on Render

Keep a modular monolith with REST APIs and one PostgreSQL database. Do
not introduce microservices, Redis, queues, or extra infrastructure
without a demonstrated requirement and user approval.

The AGOS frontend, backend, and database run locally. The local backend
calls the Render-hosted Gotenberg service over HTTPS. Keep the Gotenberg
URL and any required service credential on the backend; never expose
them to the browser. Verify the Render service's actual access-control
configuration rather than assuming Gotenberg provides authentication
itself.

Do not deploy AGOS to production, create paid resources, or change
external accounts without explicit authorization.

## 5. Critical financial rules

-   Store monetary values as fixed-precision decimals. Never use
    JavaScript `Number` for authoritative money calculations.
-   Serialize API monetary values as decimal strings.
-   Calculate cash, invoice balances, forecasts, cash gaps, and
    scenarios in deterministic backend services---not in React or an AI
    provider.
-   Use PHP as the default currency; do not add currency conversion
    unless specified.
-   Store timestamps in UTC and display business dates using
    `Asia/Manila`.
-   An invoice or supplier bill is an obligation, not a cash movement.
    Record actual payments separately and prevent double-counting.
-   Do not invent opening balances. Mark incomplete financial history
    clearly.
-   Keep confirmed cash, expected inflows/outflows, uncertain
    collections, and optional expenses distinct.
-   Follow the FRS's Loyverse rules for paid amounts, discounts,
    included tax, refunds, split payments, duplicates, and quarantined
    records.
-   Keep hypothetical scenarios separate from actual financial records.
    Scenario execution must never modify real transactions, invoices,
    bills, or payments.
-   Every recommendation must reference supporting AGOS records or
    calculations.

If a financial rule is ambiguous, document the question and ask for a
decision before implementing it.

## 6. Security and data isolation

-   Enforce authentication, role permissions, and business-level data
    isolation in backend operations.
-   Never trust a client-supplied business ID as authorization.
-   Validate all user input and external API responses.
-   Use parameterized queries or Prisma's safe query methods.
-   Hash passwords using a modern salted one-way algorithm.
-   Use server-managed sessions, rotate session IDs on login, expire
    idle sessions, and revoke sessions on logout/password reset.
-   Use HttpOnly and SameSite cookies; use Secure cookies over HTTPS.
-   Apply CSRF protection to state-changing cookie-authenticated
    requests and restrict CORS to the configured frontend origin.
-   Keep secrets in untracked `.env` files. Commit placeholders only in
    `env.example`.
-   Never log passwords, tokens, API keys, full provider payloads, or
    unnecessary personal/financial information.
-   System Administrators have no default access to business financial
    records.
-   Test cross-business access for records, imports, scenarios, reports,
    and audit events.

Do not weaken security to make a local demo work.

## 7. Integrations

**Loyverse** - Read-only. Never create, edit, or delete POS records. -
Verify current API documentation, fields, and permissions. - Preserve
source IDs and sync timestamps; prevent duplicates. - Quarantine invalid
or conflicting records. - Never mark an incomplete sync as successful.

**Groq / Gemini** - Send only verified AGOS calculations and candidate
actions. - Do not provide unrestricted database access. - Validate
structured output and evidence references. - Reject malformed or
unsupported actions. - Fall back to rule-based recommendations on
timeout, outage, or invalid output. - AI must not calculate official
financial values or modify records.

### Gotenberg on Render

- Use the Chromium-only Gotenberg v9 image (`gotenberg/gotenberg:9-chromium`); AGOS needs HTML-to-PDF, not LibreOffice conversion.
- Render variables: `API_PORT_FROM_ENV=PORT`, `API_ENABLE_BASIC_AUTH=true`, `GOTENBERG_API_BASIC_AUTH_USERNAME`, `GOTENBERG_API_BASIC_AUTH_PASSWORD`, `CHROMIUM_MAX_CONCURRENCY=1`, `CHROMIUM_MAX_QUEUE_SIZE=2`, and `API_TIMEOUT=20s`.
- Set Render's health check path to `/health`. The health endpoint is unauthenticated; keep it enabled.
- Treat 512 MiB RAM as the documented minimum constrained configuration, not a guarantee for large or concurrent reports. Keep conversions sequential, avoid large images/documents, and monitor memory and logs.
- The local backend calls Gotenberg over HTTPS using Basic Authentication. Configure `GOTENBERG_BASE_URL`, `GOTENBERG_USERNAME`, and `GOTENBERG_PASSWORD` in the backend environment. Keep credentials server-side.
- Implement one active conversion at a time with a bounded pending queue; do not add Redis or another queue service for this requirement.
- Use a 20-second Gotenberg request timeout and a 60-second overall report-job limit. Retry at most once for transient 429/503 responses; do not retry 400, 401, or 403.
- Persist report input/data snapshots and generated HTML so users can preview and retry after PDF failure. PDF failure must not change financial records or discard report data.
- Validate the response content type and generated PDF; verify report totals against AGOS data.

Use mocks or fixtures when credentials or services are unavailable. Do
not describe a mocked test as a successful live integration.

## 8. Database, API, and Git practices

-   Use `/api/v1` for REST endpoints unless the project documents an
    approved change.
-   Validate request bodies, parameters, and query strings; return
    consistent errors without leaking internals.
-   Use versioned Prisma migrations. Do not edit migrations already
    applied to shared data; create a new migration.
-   Review schema changes for data loss and provide a rollback approach.
-   Inspect `git status` before changes and preserve user modifications.
-   Do not reset, clean, force-push, rewrite history, or discard user
    changes.
-   Do not commit unless asked.
-   Never commit `.env`, credentials, local database files, or private
    demo data.
-   Avoid unrelated refactors and major dependency upgrades.

## 9. Completion and handoff

A task is done only when its acceptance criteria are implemented,
relevant tests pass, authorization and error handling are present, and
documentation is updated.

At each task-group handoff, report:

-   Task IDs completed and pending
-   Files changed
-   Commands and tests run, with results
-   Migrations created/applied
-   Mocked or unverified integrations
-   Blockers and next task

If work must pause, update `docs/implementation-status.md` with the
current phase, last verified task, test status, blockers, and exact next
task.

A running server or successful build alone does not mean AGOS is
complete.
