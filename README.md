# AGOS

AGOS is a Laravel 13 + Livewire 4 + PostgreSQL cash-flow application. The AGOS app and database run locally; PDF conversion uses Gotenberg hosted on Render.

**Team handoff, 19 November 2026.** P3’s read-only Loyverse import is complete and verified with synthetic receipts. Start **P4-T01** using the published Team ledger. The handoff identifies the cash inputs, an independently checkable example, and the remaining live-store verification. [P3 handoff to P4](docs/implementation-status.md#p3-handoff-to-p4)

## Requirements

- PHP 8.4.1+ for the locked dependencies, with `pdo_pgsql`, `mbstring`, `openssl`, and Laravel-required extensions
- Composer
- Node.js and npm
- PostgreSQL (local server; use a supported PostgreSQL release)
- Git
- Optional credentials: Loyverse, Groq/Gemini, and Render-hosted Gotenberg

## PostgreSQL setup

AGOS uses PostgreSQL for all application data. The Laravel application connects to PostgreSQL on your development computer; Gotenberg is the only service hosted externally.

### 1. Install and start PostgreSQL

Install PostgreSQL using the installer/package manager for your operating system:

- **Windows:** Use the official PostgreSQL installer. During setup, remember the password assigned to the `postgres` administrator account and keep the default port `5432` unless it is already in use. You may use pgAdmin to manage the server.
- **macOS:** Install PostgreSQL using the official installer or Homebrew. If using Homebrew, start the installed PostgreSQL service with `brew services start postgresql` (use the versioned formula name if that is what you installed).
- **Ubuntu/Debian:** Install the PostgreSQL server and client packages using your distribution's package manager, then start/enable the PostgreSQL service.

Confirm the server is running and listening on port `5432`. If you selected a different port, use that same port in the commands below and in `.env`.

### 2. Open the PostgreSQL command line

Open a terminal and connect as the PostgreSQL administrator.

A common Windows command (use the password created during installation):

```bash
psql -h 127.0.0.1 -p 5432 -U postgres
```

On macOS/Linux, the administrator connection may instead be available through your operating-system account:

```bash
psql postgres
```

If `psql` is not found, add PostgreSQL's `bin` directory to your PATH or open the SQL Shell (psql) application installed with PostgreSQL. You can also run the SQL below in pgAdmin's Query Tool.

### 3. Create a dedicated AGOS database user and database

Run these SQL statements once while connected as a PostgreSQL administrator:

```sql
CREATE ROLE agos_user
WITH LOGIN PASSWORD 'replace_with_a_strong_local_password';

CREATE DATABASE agos
OWNER agos_user;
```

Use a strong local password and remember it for the `.env` file. If the role or database already exists, do not run the creation statements again; inspect the existing objects or alter the role/database deliberately.

The `agos_user` role owns the `agos` database and is the application login. Do not configure Laravel to connect as the PostgreSQL superuser.

Exit psql:

```sql
\q
```

### 4. Verify the application database login

Connect using the new AGOS role:

```bash
psql -h 127.0.0.1 -p 5432 -U agos_user -d agos
```

Enter the password you created. After connecting, run:

```sql
SELECT current_database(), current_user, version();
```

The result should show database `agos` and user `agos_user`. Exit with `\q`.

If the connection fails, check that PostgreSQL is running, the host/port are correct, the role and database exist, and the password is correct. Check PostgreSQL's local authentication configuration if password authentication is rejected.

### 5. Configure Laravel's database connection

Copy `.env.example` to `.env` in the Laravel project root. Set the PostgreSQL values to match the database and role you created:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=agos
DB_USERNAME=agos_user
DB_PASSWORD=replace_with_a_strong_local_password
```

Do not put quotes around a simple password. If the password contains special characters that dotenv parsing may interpret, quote it according to Laravel's environment-file syntax.

Make sure PHP has the `pdo_pgsql` extension enabled. Verify with:

```bash
php -m
```

The output should include `PDO` and `pdo_pgsql`. Restart the PHP/Laravel development process after changing PHP extensions or `.env`.

## Install dependencies and initialize AGOS

From the Laravel repository root:

```bash
composer install
composer require brick/math
npm install
php artisan key:generate
php artisan config:clear
php artisan migrate
npm run build
```

Before the first migration, verify that the repository contains the required Laravel migrations for users, sessions, cache, jobs, and failed jobs, as well as the starter kit's Teams and membership tables. The implementation agent must add missing migrations rather than assuming they exist.

Check migration status:

```bash
php artisan migrate:status
```

If migrations fail, check the database connection and migration error before retrying. Do not drop or recreate the database to hide a migration problem.

## Local demonstration data

To reset a local database and load the AGOS Coffee House demonstration data, run:

```bash
php artisan migrate:fresh --seed
```

This creates the **AGOS Coffee House** business, 20 customers, 5 suppliers,
125 receivables/payables, and 75 current-month cash movements. It is intended
only for a fresh local database. The demo accounts use the password `password`:

| Role | Email |
| --- | --- |
| Business Owner (Jane Doe) | `jane.doe@agos-coffee.test` |
| Manager | `miguel.santos@agos-coffee.test` |
| Bookkeeper / Finance Staff | `aira.cruz@agos-coffee.test` |
| System Administrator | `paolo.reyes@agos-coffee.test` |

The System Administrator is intentionally not a member of the business Team.

## Run AGOS locally

Start the application:

```bash
composer run dev
```

Open <http://127.0.0.1:8000>.

In a separate terminal, run one PDF queue worker:

```bash
php artisan queue:work database --queue=pdf --tries=1 --timeout=60
```

In a separate terminal, run one queue worker:

```bash
php artisan queue:work database --queue=default
```

## AGOS conventions

### Loyverse receipt adapter

An authorized Business Owner can connect a Team from **Loyverse sync** by entering one Loyverse access token. AGOS loads the merchant, stores, and payment types server-side so the user selects names instead of entering provider IDs. Credentials are encrypted server-side and the token is never displayed in full. Existing local setups may still provide a Team connection through the untracked `.env`: list its numeric Team ID in `LOYVERSE_TEAM_IDS`, then set `LOYVERSE_TEAM_<ID>_ACCESS_TOKEN`, `LOYVERSE_TEAM_<ID>_MERCHANT_ID`, `LOYVERSE_TEAM_<ID>_STORE_ID`, and the comma-separated `LOYVERSE_TEAM_<ID>_COLLECTED_METHOD_IDS`. Database configuration takes precedence over the environment fallback.

An onboarded owner can open **Loyverse sync** in the sidebar and choose **Sync receipts**. Run a database worker for the default queue (`php artisan queue:work database --queue=default`) alongside the application. Each completed page saves a checkpoint. **Resume sync** continues an interrupted window, or replays it if the provider rejected its cursor. After the final page, AGOS publishes verified payments together and lists quarantined receipts with their reasons. The dashboard shows the last successful synchronization time in Manila time.

### Application conventions

- One Laravel Team represents one AGOS Business. Team owner = Business Owner; Team member roles = Manager or Bookkeeper. System Administrator is a separate global role. Do not create duplicate Business/Membership tables.
- Use Livewire single-file components by default; use multi-file/class-based components for complex components. Keep financial calculations in services.
- Use Laravel migrations for every persistent schema change. Never edit an already-applied migration; create a new one.
- Keep `.env` untracked. Commit `.env.example` with placeholders only.
- Gotenberg is hosted on Render. Keep its Basic Auth credentials server-side. At 512 MiB, process one PDF at a time and allow at most two pending PDF jobs.
- Loyverse is read-only. AI explains verified AGOS calculations and must not modify financial records.
