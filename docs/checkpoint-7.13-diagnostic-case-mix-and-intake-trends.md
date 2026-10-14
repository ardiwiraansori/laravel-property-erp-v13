# Checkpoint 7.13 — Diagnostic Case Mix & Daily Intake Trends

**Verified GitHub `main` baseline:** `876157e12fab5d75899e2eca47bf2c8bf9a5f59f` (Checkpoint 7.12). The three replacement-file Git blob hashes were checked against the exact `main` revision before packaging.

## Motivation

7.9–7.11 delivered current queue workload, aging and inactivity, and 7.12 added 30-day workflow performance. The next diagnostic insight missing is **which allowlisted modules, categories and impact levels generated those reports**, and **how submissions are distributed by day**. This is intended for manual prioritization, not automated incident-response SLA enforcement.

## Scope and API

The existing endpoint `GET /api/platform/diagnostics/reports` retains all query parameters, existing fields, validations and guards. Its existing `workflow_performance` object gains two additive properties:

```json
{
  "workflow_performance": {
    "window_days": 30,
    "reported": 3,
    "triaged": 2,
    "resolved": 1,
    "reopened": 0,
    "awaiting_first_triage": 1,
    "average_hours_to_first_triage": 4.5,
    "average_hours_to_first_resolution": 12.5,
    "case_mix": {
      "by_module": { "dashboard": 1, "crm": 2 },
      "by_category": { "page_error": 2, "slow_response": 1 },
      "by_impact": { "blocked": 1, "partial": 2 }
    },
    "daily_reported": [
      { "date": "2026-10-08", "count": 0 },
      { "date": "2026-10-09", "count": 1 }
    ]
  }
}
```

**Example only:** In real responses, `case_mix` contains **every value** from the fixed `DiagnosticFields` allowlists, including zero counts; `daily_reported` contains **exactly seven consecutive calendar dates**, oldest first, finishing with the server's current date. Example abbreviated for readability.

### Precise semantics

- `case_mix` counts reports **created in the rolling 30-day cohort** (`now()-30 days <= created_at <= now()`, inclusive), including terminal statuses. Exactly one allowed module/category/impact bucket is incremented per matching report.
- Unrecognized historical database field values remain part of `reported`, but **must not** be emitted as dynamic `case_mix` keys; all distribution keys come from server allowlists. Consequently, legacy malformed rows can make a bucket sum smaller than `reported`.
- `daily_reported` counts **newly created reports**, not status transitions or successful responses, on each of the last seven **server-calendar** dates (today and six previous dates), zero-filling dates with no intake. Today's future timestamps are excluded by the 30-day cohort's upper bound. The first date starts at 00:00:00 in the application timezone.
- Both properties use the same company/category/impact **base scope** as existing `workflow_performance`, `summary`, `open_aging`, and `open_inactivity`. `status`, `assignment`, `age`, `inactivity`, `sort`, `page`, and `per_page` affect queue rows only, and **do not change** these overview properties.
- No time-series query parameters, custom dimensions, free-text grouping, dynamic SQL column selection, report IDs, names, notes, or customer/business details are exposed through these fields.

Case mix is calculated in **the already existing 200-report chunks** used by `DiagnosticPerformance` rather than introducing a new full-dataset pass. Only module/category/impact are additionally read. Dates and counters are computed in memory; there are no schema changes. Query-time load should still be monitored if 30-day diagnostic volumes increase significantly.

## Frontend

The platform-only React diagnostics page gains a `Diagnostic intake trends and case mix` section below 7.12 performance metrics, with a simple accessible seven-date bar visualization (numeric labels) and category/module/impact breakdowns. Layout uses existing Tailwind and components; no dependencies, new route or charting service. Reporting/assignment/edit/reopen workflows and write-lock notices remain unchanged.

## Security invariants

1. Existing `auth:sanctum`, `platform.admin`, `platform.mfa`, session checks and platform request tenant-header rejection continue to guard the endpoint.
2. Tenant diagnostics intake/listing retains reporter/company isolation; no cross-tenant tenant API exists.
3. Platform data aggregation is accessible only to eligible verified platform identities, never inferred from tenant Owner role or `ardi@example.com`.
4. `PLATFORM_MUTATIONS_ENABLED=false` remains unchanged. The endpoint is read-only; state transitions retain existing optimistic concurrency and event audit protection.
5. Existing `platform.diagnostics.read` audit action remains in place.
6. No token, cookie, password, screenshot, raw URL, IP, raw UA, message, form submission, free-text description, or tenant business record is collected.
7. No Super Admin provisioning, migration, retention policy, data edit, schema modification, seed or destructive operation; ARVYA and E2E-TEST are untouched. Laravel feature tests use SQLite in-memory.

## Single ZIP contents

Copy folder trees manually from Downloads into your local repository root, replacing only the three existing paths and adding two new paths:

1. **REPLACE** `app/Support/Diagnostics/DiagnosticPerformance.php`
2. **REPLACE** `frontend/src/features/platform/platform-diagnostics-api.ts`
3. **REPLACE** `frontend/src/features/platform/platform-diagnostics-page.tsx`
4. **ADD** `tests/Feature/Api/Diagnostics/DiagnosticCaseMixTrendTest.php` (nine new feature tests)
5. **ADD** `docs/checkpoint-7.13-diagnostic-case-mix-and-intake-trends.md`

Apply to a clean checkout of **`876157e` only**. No `composer install`, `npm install`, migration, database reset or platform provisioning is required. In particular, do **not** run `php artisan migrate` or `migrate:fresh` for this checkpoint.

## Required Windows CMD verification

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test
cd frontend
npm run lint
npm run build
cd ..
git diff --check
git status -sb
```

Because `git diff --check` excludes untracked files, after you review results, stage the exact five paths and run `git diff --cached --check` before committing. The user performs every commit and push after reviewing the full test output. The assistant never pushes, commits, provisions administrators, enables platform mutations or runs database migrations.

**Preliminary package checks:** PHP syntax and TypeScript/TSX parsing only; no project dependency tree was installed, therefore full PHPUnit, ESLint, and Vite production build results must come from the user's existing Windows checkout and **must not be assumed**. Existing verified 7.12 baseline: 139 passed / 798 assertions; 7.13 includes nine additional test methods, but aggregate outcomes must be observed.
