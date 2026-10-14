# Checkpoint 7.12 — Diagnostic Workflow Performance Insights

**Verified baseline:** `ardiwiraansori/laravel-property-erp-v13` GitHub `main`, commit `05cb1ebbe909c59f556844b1777a6e94b05348eb` (Checkpoint 7.11). Local `HEAD`, `main`, `origin/main` were also confirmed by the repository owner to be synchronized, with a clean worktree.

## Motivation and scope

Checkpoints 7.9–7.11 introduced support queue workload, report age, and inactivity. These describe the *current state*, but do not quantify whether a recently submitted report has reached triage/resolution and whether it has been reopened.

This checkpoint adds **read-only, metadata-only workflow performance insights**, based on existing `diagnostic_reports` and append-only `diagnostic_report_events` only. No new route, column, migration, external service, scheduled job, event mutation, or platform role is introduced.

## API contract

Additive property on existing `GET /api/platform/diagnostics/reports`:

```json
{
  "workflow_performance": {
    "window_days": 30,
    "reported": 3,
    "triaged": 2,
    "resolved": 1,
    "reopened": 1,
    "awaiting_first_triage": 1,
    "average_hours_to_first_triage": 4.5,
    "average_hours_to_first_resolution": 12.25
  }
}
```

### Metric definitions

- **Cohort:** reports created from *server now minus 30 days* through server now, inclusive. Future-dated reports and reports older than 30 days are excluded from this performance cohort. All status values are included, including resolved/closed, because they represent completed historical workflow.
- **Reported:** number of reports in the cohort.
- **Triaged:** number of distinct reports having a qualifying first `status_changed` event `new → triaged` on or after report creation. Re-triage after reopening does **not** add another report.
- **Resolved:** number of distinct reports having a `status_changed` event with `to_status = resolved` on or after creation. Subsequent re-resolution is not counted twice.
- **Reopened:** distinct reports with at least one `resolved → in_progress` or `closed → triaged` status event. Multiple reopening events for the same report still count once. `in_progress → triaged` is re-triage, **not** reopening.
- **Awaiting first triage:** `reported - triaged`. This counts reports lacking a qualifying historical first triage event regardless of current status; it is not equivalent to the number currently in `new` status.
- **Average hours to first triage/resolution:** mean elapsed hours from submission to the earliest corresponding valid event across reports that reached that milestone. Rounded to two decimals. `null` when there are no qualifying events. These are **not** human response times or contractual SLA compliance.
- For a report with no historical status events (including migrated records), it remains in the reported cohort but is not presumed triaged/resolved based solely on its current status. Future or pre-creation events are ignored for elapsed-time metrics.

### Scope and compatibility

`workflow_performance` inherits the existing **company_id, category, and impact** base filters exactly like `summary`, `open_aging`, and `open_inactivity`. It deliberately ignores table-only `status`, `assignment`, `age`, `inactivity`, `sort`, `page`, and `per_page` so that pagination or queue lane selection cannot distort the 30-day overview. Existing response fields, ordering, filtering, and validation are unchanged.

Database work uses a 30-day query and batch processing (`chunkById(200)`) to bound per-chunk memory; only ID, creation time, and allowlisted status-event timestamps are read. Avoid free-text event bodies or tenant business records. Like all real-time aggregates, this adds some read work to the existing queue API. Monitor query latency with growing diagnostic history before production scale-up.

## Frontend

The existing platform diagnostics queue adds a `Workflow performance — last 30 days` section with reported, triaged, resolved, reopened, awaiting-first-triage, and the two average-hour metrics. The existing work queue filters, sort controls, status/assignment writes, detail drawer/section, history navigation, MFA, and write-lock notices remain intact.

## Security and non-destructive invariants

1. Existing `auth:sanctum`, `platform.admin`, and `platform.mfa` middleware remain unchanged. A tenant member is never a Platform Super Admin by virtue of tenant role.
2. Explicit `X-Company-ID` platform headers remain disallowed by existing middleware.
3. Tenant report intake and tenant reporter isolation remain unchanged.
4. `PLATFORM_MUTATIONS_ENABLED=false` remains the default; this feature is read-only and does not bypass write locks or `workflow_version` checks.
5. No token, cookie, password, screenshot, raw URL, IP address, form data, business data, or free-text support note is ingested or exposed.
6. Existing platform diagnostic read audit action remains in effect.
7. No migration, DDL, seed, data repair, deletion, tenant membership change, Super Admin provisioning, or change to ARVYA/E2E-TEST records. The test suite uses SQLite in-memory via existing `phpunit.xml`.

## Contents of the single ZIP

Copy/merge folders from the ZIP into the repository root, replacing only matching paths:

1. `app/Http/Controllers/Api/Platform/PlatformDiagnosticController.php` — **replace** (add read-only summary).
2. `app/Support/Diagnostics/DiagnosticPerformance.php` — **add** (cohort/funnel calculator).
3. `frontend/src/features/platform/platform-diagnostics-api.ts` — **replace** (typed response).
4. `frontend/src/features/platform/platform-diagnostics-page.tsx` — **replace** (metrics overview).
5. `tests/Feature/Api/Diagnostics/DiagnosticWorkflowPerformanceTest.php` — **add** (8 feature tests).
6. `docs/checkpoint-7.12-diagnostic-workflow-performance.md` — **add**.

Apply only to a **clean checkout of commit `05cb1eb`**. Do not install against an unreviewed, changed baseline. Do not run Composer setup, migrate/fresh/reset, `npm install`, or provisioning for this checkpoint.

## Windows CMD: mandatory local verification

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

The new PHP test is executed even before being staged. `git diff --check` inspects tracked changes; to cover new files before committing, stage only after test review and then run `git diff --cached --check`. The owner performs `git add`, `git commit`, and `git push origin main` personally *only after* the test results have been reviewed.

**Testing in the package assembly environment:** PHP syntax checks only. Full Laravel tests, ESLint and Vite build cannot be asserted until run in a checkout with the project's dependencies. Report observed counts rather than guessing. Do not apply any MariaDB migration: there are none in this checkpoint.
