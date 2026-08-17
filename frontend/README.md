# EMPOWER Frontend

Quick start for the frontend development server.

Requirements
- Node.js >= 18

Install and run

```bash
cd frontend
npm install
npm run dev
```

The dev server proxies API calls to `/api/...` (configured in `vite.config.js`).

Features added
- TailwindCSS + components
- Headless UI modal for creating job requests
- Basic pages: Dashboard, Applicants, Applicant Detail, Job Requests
# EMPOWER Frontend (Minimal)

This is a minimal Vite + React frontend used to view applicant notifications and audit logs during development.

Install dependencies:

```bash
cd frontend
npm install
```

Run dev server:

```bash
npm run dev
```

The UI expects the Laravel backend to be reachable at the same host under `/api/v1` (or configure a proxy in `vite.config.js`).
