# AGOS Implementation Plan

**Version:** 4.2 · **Environment:** local AGOS app + PostgreSQL; Render-hosted Gotenberg · **Delivery:** setup + six two-week phases.

## 1. Working agreement

- Read `AGOS_Functional_Requirements_Specification.md` (what to build) and `AGENTS.md` (how to work) before changing code.
- Inspect the repository and `git status`. Start at the earliest unverified task; do not overwrite existing work or assume a blank repository.
- Complete small task groups, add tests, verify acceptance criteria, then report files changed, commands/results, blockers, and the next task.
- Preserve FRS requirement IDs. Escalate ambiguous financial rules; do not invent accounting semantics.
- Do not commit, deploy the AGOS app, create paid infrastructure, or alter external business data without authorization.

## 2. Approved stack

| Area | Technology |
|---|---|
| Application | Laravel 13 / PHP 8.3+ (single app, modular monolith) |
| UI | Livewire 4 single-file components by default + Blade + Tailwind CSS / Flux UI |
| Charts | Chart.js (small client-side integration with Livewire) |
| Build | Vite and npm for CSS/JS assets only |
| Database | PostgreSQL, Eloquent ORM, Laravel migrations |
| Decimal arithmetic | PostgreSQL `NUMERIC`; `brick/math` `BigDecimal` or tested equivalent |
| Authentication / tenancy | Laravel Livewire starter kit with Teams enabled; Fortify, sessions, Team membership, policies |
| Jobs | Laravel **database** queue; one PDF worker, no Redis |
| Tests | Pest or PHPUnit, Laravel feature tests, Livewire component tests |
| External APIs | Laravel HTTP client; Loyverse, Groq/Gemini, Gotenberg adapters |
| PDFs | Gotenberg Chromium-only Docker image, hosted on Render (512 MiB) |
| Version control | Git |

**No React, Express, Node.js backend, Prisma, separate frontend project, or microservices.** Node/npm remain only for Laravel frontend asset compilation. Prefer the official Laravel Livewire starter kit; do not hand-build authentication scaffolding unnecessarily. Use the starter kit's existing file layout unless there is a clear reason to change it.

### Livewire component convention

Single-file components (SFCs) are the default for simple and medium-sized pages, forms, CRUD flows, and dashboard widgets. Use multi-file or class-based components when a component becomes complex or needs clearer separation. Keep financial rules and integrations in service/adapter classes.

### UI/UX design standard

AGOS should look and behave like one coherent, professional financial-management/SaaS application—not a collection of unrelated generated pages.

- **Visual direction:** modern, clean, professional fintech; prioritize clarity and trust over decoration.
- **Theme:** use a documented semantic palette with deep navy/blue as the primary brand direction, neutral surfaces, and semantic success (green), warning (amber), and danger (red) states. Select exact values once and store them in shared theme/CSS tokens; do not scatter arbitrary hex values across pages.
- **Typography and layout:** use a readable sans-serif typeface, consistent heading hierarchy, spacing scale, borders, radii, and restrained shadows.
- **Application shell:** shared sidebar/navigation, top header, active-business context, page title/breadcrumb where useful, and role-aware navigation. Users must not depend on clicking the Laravel logo to find business workflows.
- **Components:** reuse Flux UI and shared Blade/Livewire components for buttons, inputs, validation, tables, filters, dialogs, confirmations, notifications, badges/statuses, empty states, and loading indicators.
- **Financial clarity:** prioritize current cash, projected cash, thresholds, shortfalls, overdue items, and recommendations according to the page. Always label PHP values, dates, chart units, and statuses. Never communicate status by color alone.
- **Interaction states:** every data-driven page must handle loading, empty, success, validation-error, and service-failure states. Prevent accidental duplicate submissions and confirm destructive or consequential actions.
- **Responsive design:** support desktop, tablet, and mobile. Tables may use responsive columns or contained horizontal scrolling, but core actions and information must remain usable.
- **Accessibility:** target WCAG 2.2 AA principles where applicable: keyboard access, visible focus, semantic markup, accessible labels, contrast, and understandable validation feedback.
- **Charts:** Chart.js charts need titles, units, legends/labels, selected period, and a textual summary of key values.
- **Avoid:** unnecessary gradients, excessive animation, inconsistent colors, decorative clutter, dense financial tables, and one-off styling when a shared component exists.

Review every new page for visual consistency, responsiveness, accessibility, and usability as part of that feature's acceptance criteria. Do not defer core usability until the final polish phase.

### Core boundaries

- A Laravel web application runs on `http://127.0.0.1:8000` with PostgreSQL running locally.
- Livewire actions invoke Laravel application/domain services directly; do not build duplicate JSON endpoints for every page. Add `/api/v1` routes only for explicit API consumers/contract tests.
- Organize domain logic in `app/Services/`, providers in `app/Integrations/`, authorization in policies, and UI in Blade/Livewire.
- **Tenancy:** one Laravel Team represents one AGOS Business. The Team owner is Business Owner; Team membership roles map to Manager and Bookkeeper. System Administrator is a separate global role. Use the generated Team and membership tables as canonical; do not create duplicate Business or Membership tables. Extend generated tables through new migrations for AGOS profile fields and roles.
- Customize Team/business onboarding so business profile details are captured. An automatically created personal Team is not a complete AGOS business until onboarding is complete. Every Livewire action, query, job, import, and report must verify Team membership and role; current Team selection alone is not authorization.
- All external calls and credentials remain server-side.

### Mandatory financial and security rules

- Money: `NUMERIC` columns + decimal strings + `BigDecimal`; **never PHP float for official totals**. Default PHP currency (Philippine pesos); no FX conversion.
- Store timestamps in UTC; display business dates in `Asia/Manila`.
- Invoices/bills are obligations, not confirmed cash. Payment cash movements must be linked, idempotent, and never double-counted.
- Never invent opening balances; label incomplete history. Separate confirmed, expected, uncertain, and optional cash flows.
- Loyverse is read-only; correctly handle paid vs unpaid, discounts, included taxes, split payments, refunds, duplicates, updated receipts, and quarantine.
- Scenarios never modify actual financial records. Only deterministic Laravel services calculate official balances, forecasts, cash gaps, and scenarios.
- AI explains verified data and evidence-linked actions; validate its output and fall back to rules when invalid or unavailable.
- Enforce business isolation and role permissions in Laravel policies/scopes and **each Livewire action**, not just page navigation. System Admin has no default access to business finance records.
- Use Laravel CSRF protection, password hashing, session rotation, 30-minute idle expiration, login throttling, audit records, and untracked `.env` secrets.

## 3. Implementation phases

### P0 — Repository and environment

- **P0-T01:** Inspect Git status, repository, documents, packages, migrations, and test baseline; report existing work before editing.
- **P0-T02:** Verify PHP 8.3+, Composer, Node/npm, PostgreSQL, and required extensions (`pdo_pgsql`, `mbstring`, `openssl`). If the repository contains only project documents, create a temporary Laravel 13 app with the official Livewire starter kit; select **Teams support: Yes**, **single-file Livewire components: Yes/default**, built-in Laravel authentication, PostgreSQL, and Pest. Merge the scaffold into the existing repository without replacing `.git`, AGOS documents, `.env.example`, or existing code.
- **P0-T03:** Verify the generated Team and membership migrations are present. Configure PostgreSQL in `.env`, generate `APP_KEY`, run migrations, install npm assets, and confirm local `composer run dev` works.
- **P0-T04:** Set up Pest, Laravel Pint, `.gitignore`, `README.md`, and environment templates. Add `brick/math` to Composer dependencies.

**Gate:** Laravel login page loads locally; PostgreSQL migrations succeed; no secret is committed.

### P1 — Identity, tenancy, and transactions

- **P1-T01:** Inspect the actual starter-kit Team and membership models/migrations. Use Team as the canonical AGOS Business and the generated membership/pivot as canonical membership; extend these tables with new migrations for business profile fields and AGOS role values. Do not create duplicate Business or Membership tables.
- **P1-T02:** Customize onboarding so the Team owner maps to Business Owner and required business profile details are captured. Map Team member roles to Manager and Bookkeeper. Keep System Administrator as a separate global role. Configure registration/invitations and Team management; use the starter kit's current Team switching features.
- **P1-T03:** Install `brick/math`; implement Transaction, Category, and AuditEvent models/migrations. Use Team ID as the business scope for all business-owned records.
- **P1-T04:** Implement transactions and categories with single-file Livewire components by default, validation, policies, and audit events. Keep calculations in services.
- **P1-T05:** Test Team ownership, membership roles, cross-Team denial, monetary precision, and transaction lifecycle.

**Gate:** Authorized users manage only their business's records; money values retain correct decimal precision.

### P2 — Receivables, payables, and CSV

- **P2-T00 — UI/UX foundation:** Review existing onboarding, business profile, dashboard, category, and transaction screens. Establish shared design tokens and the authenticated application shell; align navigation, active-business context, page headers, typography, spacing, and Flux UI usage. Standardize reusable form, table, filter, dialog, notification, loading, empty, and error patterns. Check desktop, tablet, and mobile layouts and applicable WCAG 2.2 AA basics. Refactor existing pages only where needed for consistency; preserve working workflows.
- **P2-T01:** Implement Customer and Supplier screens and business-scoped models using the shared AGOS layout and UI patterns.
- **P2-T02:** Add invoices, invoice totals, outstanding balances, customer payments, and overdue detection.
- **P2-T03:** Add supplier bills, payments, balances, and due-date monitoring.
- **P2-T04:** Implement CSV preview/mapping/validation/duplicate review, reviewed commit, and row-numbered error report.
- **P2-T05:** Test partial payments, no cash movement on invoice/bill creation, CSV duplicates, and incomplete history labels.

**Gate:** Receivables/payables reconcile; invalid imports cannot enter verified records.

### P3 — Loyverse read-only import

- **P3-T01:** Verify current Loyverse fields, pagination, status meanings, and API permissions.
- **P3-T02:** Implement read-only Laravel HTTP adapter, secure token config, source mappings, and import upsert keys.
- **P3-T03:** Add manual synchronization, checkpointing, quarantine, and stale/incomplete status UI.
- **P3-T04:** Test discounts, included taxes, split payments, refunds, paid/unpaid states, duplicates, updates, and provider failures with fixtures.

**Gate:** Only verified paid cash contributes to totals; imports do not duplicate or fabricate data.

### P4 — Cash engine and scenarios

- **P4-T01:** Implement deterministic cash position, forecast classification, threshold crossing, cash gap, and earliest shortfall calculations using fixed precision.
- **P4-T02:** Build Livewire dashboard summaries and Chart.js forecast views; show data freshness and completeness.
- **P4-T03:** Implement isolated scenarios (payment delay, sales change, expense increase, supplier delay, deposit, purchase).
- **P4-T04:** Add baseline-vs-scenario comparisons and purchase-affordability alternatives.
- **P4-T05:** Test calculations against independent fixtures; assert scenarios do not modify actual financial records.
- **P4-T06:** Add an Owner-controlled verified opening-balance and cash-history completeness workflow. Persist the effective business date, decimal-safe opening balance, verification metadata, and audit event; expose it through onboarding and Business Profile settings; feed it into dashboard, scenario, threshold, cash-gap, and affordability calculations; and preserve explicit unavailable states when history remains incomplete.

**Gate:** Cash math reconciles and scenario isolation is proven by tests.

### P5 — Action Center and AI

- **P5-T01:** Implement deterministic, prioritized candidate recommendations with AGOS evidence references.
- **P5-T02:** Add Action Center Livewire UI, with source records and amounts.
- **P5-T03:** Implement one provider-neutral Groq/Gemini adapter (one live provider initially), structured response validation, and deadline limits.
- **P5-T04:** Reject unsupported AI actions and invalid evidence; fall back to rule-based results during outages.

**Gate:** AI never calculates official totals or writes records; fallback and evidence checks pass.

### P6 — Financing readiness, Render Gotenberg, tests

- [x] **P6-T01:** Implement financing-readiness checklist and report without predicting loan approval.
- **P6-T02:** Configure Render Gotenberg v9 **Chromium-only** (`gotenberg/gotenberg:9-chromium`) with the environment variables below. Verify `/health` and authenticated HTTPS calls.
- **P6-T03:** Implement Blade report templates, authorized HTML preview, and immutable report data snapshots.
- **P6-T04:** Use Laravel **database queue** with **one PDF worker**, at most **two pending reports**, and no Redis; issue sequential HTML-to-PDF requests from the backend.
- **P6-T05:** Apply a 20-second Gotenberg request timeout and 60-second job limit, with at most one safe retry for transient 429/503. Verify PDF MIME type, file content, and financial totals.
- **P6-T06:** Keep failed report snapshots and HTML available; allow user-triggered retry without modifying financial data.
- **P6-T07:** Test all FRS modules, cross-business access, adapter contracts, interruptions, audit logs, and Render outage behavior; write demo fixtures and local instructions.

**Gate:** End-to-end local demonstration works, including authenticated PDFs from Render; failure preserves records and permits retry.

## 4. Render Gotenberg configuration (512 MiB)

Set these variables **on the Render Gotenberg Web Service** (not in the Laravel `.env`):

| Render variable | Value |
|---|---|
| `API_PORT_FROM_ENV` | `PORT` |
| `API_ENABLE_BASIC_AUTH` | `true` |
| `GOTENBERG_API_BASIC_AUTH_USERNAME` | Unique username (secret) |
| `GOTENBERG_API_BASIC_AUTH_PASSWORD` | Long random password (secret) |
| `CHROMIUM_MAX_CONCURRENCY` | `1` |
| `CHROMIUM_MAX_QUEUE_SIZE` | `2` |
| `API_TIMEOUT` | `20s` |

Set Render health check to `/health`. 512 MiB is a constrained minimum: keep reports compact and avoid heavy images. If memory failures recur, lower document complexity or increase RAM. **Basic Authentication does not make a public service private**: keep credentials private, do not expose an unrestricted public conversion feature, and consider an access proxy if appropriate. Use the current official Gotenberg v9 configuration reference when provisioning.

In local Laravel `.env` set:

```dotenv
GOTENBERG_BASE_URL=https://your-gotenberg-service.onrender.com
GOTENBERG_USERNAME=
GOTENBERG_PASSWORD=
```

Laravel must send Basic Auth through its HTTP client (not the browser). Use `QUEUE_CONNECTION=database` and one worker for the `pdf` queue. Queue job statuses and snapshots should survive app restarts.

## 5. Verification and handoff

Test the relevant unit, feature/Livewire, database, authorization, provider-contract, and failure paths for each task. For every UI task, review consistency with shared tokens/components, role-aware navigation, loading/empty/success/error states, responsive behavior at desktop/tablet/mobile sizes, keyboard access, visible focus, and clear financial labels. Add browser/manual acceptance checks where automated tests cannot reliably assess visual usability. Suggested commands:

```bash
php artisan test
./vendor/bin/pint --test
php artisan migrate:status
npm run build
```

Check that the documented PDF worker is runnable (`php artisan queue:work database --queue=pdf --tries=1 --timeout=60`). Report tests *not run* instead of claiming they pass. Update task status only after verification. When blocked, write the last verified task and next step to `docs/implementation-status.md`.

**If time is limited:** Defer scheduled Loyverse sync, second AI provider, decorative dashboard refinements, and extra report layouts—not authorization, financial accuracy, reconciliation, scenario isolation, or evidence-based fallback.
