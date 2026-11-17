# AGENTS.md — AGOS coding-agent instructions

Applicable to any repository-aware coding agent.

## Authority and workflow

1. Read `AGOS_Functional_Requirements_Specification.md`, then `AGOS_Implementation_Plan.md`, then `README.md`.
2. Inspect files, migrations, tests, package manifests, and `git status` **before editing**. Find the earliest unverified implementation-plan task.
3. Implement one small task group, add tests, run checks, review the diff, then report task IDs, files changed, verification results, blockers, and next task.
4. Check off a task only after its acceptance criteria pass. Do not claim unrun tests passed, silently change financial rules, delete tests for green results, or overwrite user work.
5. Do not commit, push, deploy AGOS, create cloud services, or modify external business records without user authorization. If interrupted, leave `docs/implementation-status.md` with the last verified task.

## Stack and structure

- **Laravel 13 (PHP 8.3+) + Livewire 4 + Blade + Tailwind/Flux UI + PostgreSQL**; Chart.js for charts, Vite for assets only.
- Use the official Laravel Livewire starter kit with **Teams support enabled**. Use **single-file Livewire components by default** for simple/medium components; use multi-file/class-based components when complexity warrants it.
- Eloquent + Laravel migrations, policies and gates, built-in starter-kit/Fortify authentication.
- Financial arithmetic: PostgreSQL `NUMERIC` with `brick/math` BigDecimal (or rigorously tested decimal-safe equivalent); never PHP float for official monetary totals.
- Laravel HTTP client for provider adapters; Pest/PHPUnit and Laravel feature/Livewire tests.
- **Single Laravel modular monolith.** No React, Express, Prisma, separate frontend/backend apps, microservices, or Redis. Use existing starter-kit structure; no unnecessary refactors.
- **Tenancy mapping:** one Laravel Team is one AGOS Business. Team owner = Business Owner; Team membership roles = Manager or Bookkeeper. System Administrator is a separate global role. Use the starter kit's Team and membership tables as canonical; do not create duplicate Business or Membership tables. Extend existing tables through new migrations for AGOS-specific fields/roles.
- Customize business onboarding to capture AGOS business profile details; an auto-created personal Team is not a fully configured business until onboarding is completed.
- Livewire web actions call services directly. Add `/api/v1` REST routes only for explicit external consumers or contracts, sharing the same application services.

## Database migrations and persistence

- Every phase introducing new persistent data **must** include its Laravel migrations, Eloquent models, relationships, indexes, foreign keys, and database tests. Reuse and extend the starter-kit Team/membership schema for tenancy; do not create duplicate Business/Membership tables.
- Do not edit migrations that have already been applied. Create a new migration for each subsequent schema change.
- Verify migrations against PostgreSQL and test the relevant constraints and relationships before marking tasks complete.

## Financial, tenancy, and security invariants

- Currency PHP (pesos), no FX conversion. Decimal strings at boundaries. UTC timestamps; `Asia/Manila` presentation.
- Invoice/bill creation does not move confirmed cash. Link each real payment to exactly one cash movement. Never invent an opening balance; label incomplete history.
- Distinguish confirmed, expected, uncertain, and optional cash flows. Implement deterministic cash, invoice, forecast, cash-gap, and scenario services on the server.
- Hypothetical scenarios **never** modify actual financial records. AI only explains verified, evidence-linked calculations and candidate actions; reject unsupported output and fall back to rules.
- Loyverse is **read-only**. Verify API fields; validate paid/unpaid, discounts, included taxes, refunds, split payments; quarantine invalid data; prevent duplicates and incomplete-sync publication.
- Enforce Team membership, role permissions, and Team-scoped Eloquent queries in **every Livewire action, server request, queued job, import, and report**. The current Team selector is context, not authorization. System Admin has no default financial access.
- **Mandatory financial-module protection:** Every new business-facing financial route must apply `EnsureBusinessOnboarding` (or the approved equivalent middleware) and role-specific authorization. Every Livewire action must independently authorize the requested operation. Every service, queued job, import, and report must verify the authorized Team and record ownership. Middleware alone is not sufficient.
- Onboarding routes must remain accessible to authorized Team Owners while onboarding is incomplete; do not create redirect loops.
- Every new financial module must include tests for incomplete onboarding, unauthorized roles, direct Livewire action invocation, and cross-Team access attempts.
- Apply these protections when each module is implemented. Do not defer security enforcement to a later phase.
- Use Laravel password hashing, session rotation/invalidation, 30-minute idle timeout, CSRF middleware, authorization policies, authentication throttling, and audit events. Store secrets only in untracked `.env`; do not log tokens or unnecessary financial data.

## Gotenberg on Render (512 MiB)

- Gotenberg runs on Render, **not locally**, using `gotenberg/gotenberg:9-chromium`; AGOS connects from Laravel via HTTPS and HTTP Basic Authentication.
- Render configuration: `API_PORT_FROM_ENV=PORT`, `API_ENABLE_BASIC_AUTH=true`, `GOTENBERG_API_BASIC_AUTH_USERNAME`, `GOTENBERG_API_BASIC_AUTH_PASSWORD`, `CHROMIUM_MAX_CONCURRENCY=1`, `CHROMIUM_MAX_QUEUE_SIZE=2`, `API_TIMEOUT=20s`; health check `/health`.
- Laravel environment: `GOTENBERG_BASE_URL`, `GOTENBERG_USERNAME`, `GOTENBERG_PASSWORD` (backend only, never in browser source).
- Use Laravel **database queue** with **one `pdf` worker**, a limit of **two pending reports**, and persisted report snapshots/HTML. No Redis needed.
- Provider request timeout 20 seconds; job limit 60 seconds; at most one retry for transient 429/503. Reject or defer excess jobs safely. Confirm response is a PDF and matches report data.
- Provide HTML preview and manual retry after failure. Never modify financial records because PDF generation fails. Monitor 512 MiB memory usage and avoid oversized HTML/images.

## Completion

Run applicable `php artisan test`, Pint, migration checks, and `npm run build`. Test financial reconciliation, cross-business access, scenarios, import idempotency, adapter timeouts, and PDF outages. A running server or passing build alone does not prove task completion.
