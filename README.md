# AGOS

AGOS is a web application for MSME cash-flow management and
decision support.

## Stack

-   Frontend: React, TypeScript, Vite
-   Backend: Node.js, TypeScript, Express
-   Database: PostgreSQL with Prisma
-   PDF generation: Gotenberg hosted on Render
-   External APIs: Loyverse and Groq or Google Gemini

## Prerequisites

-   Node.js and npm
-   PostgreSQL
-   Git
-   Access to the configured Render-hosted Gotenberg service
-   API credentials for integrations you want to test

## Setup

1.  Clone the repository.

2.  Create local environment files by copying `env.example` to the
    backend's `.env` file. Adjust paths if the project uses a different
    environment-file location.

3.  Create a PostgreSQL database for AGOS and set `DATABASE_URL`.

4.  Install dependencies in `backend/` and `frontend/`:

    ``` bash
    cd backend && npm install
    cd ../frontend && npm install
    ```

5.  From `backend/`, generate the Prisma client and apply migrations:

    ``` bash
    npx prisma generate
    npx prisma migrate dev
    ```

6.  Start the backend and frontend using the development scripts defined
    in their respective `package.json` files (commonly `npm run dev`).

Open the Vite URL shown in the frontend terminal, usually
`http://localhost:5173`.

## Notes

-   Configure the Render Gotenberg service URL in `GOTENBERG_BASE_URL`.
    The local backend calls this service; Gotenberg is not expected to
    run locally.
-   Keep real credentials in untracked `.env` files. Never commit `.env`
    files or API keys.
-   Loyverse integration is read-only. AI provides explanations of
    AGOS-calculated results; it must not calculate official financial
    values or modify records.
-   If an external API is unavailable, use test fixtures or mocked
    responses where supported.
