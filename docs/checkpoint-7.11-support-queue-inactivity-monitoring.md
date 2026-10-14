# Checkpoint 7.11 — Diagnostic Support Queue Inactivity Monitoring

**Source of truth:** `ardiwiraansori/laravel-property-erp-v13` — GitHub `main` commit `3a43e4609aa7fa177956d081ebfa1cbde953106f` (Checkpoint 7.10). The three replaced files have been checked against that commit's Git blob hashes.

## Rationale

Checkpoint 7.10 measures how long an open ticket has existed based on `created_at`. That alone cannot distinguish an old incident that has just been triaged from one that has never been updated. This checkpoint provides a separate **read-only inactivity lens** based on the already-existing report `updated_at` timestamp. It does not set response deadlines, guarantee support contact, or introduce an SLA.

Existing workflow status/assignment actions in `DiagnosticWorkflow` already update `diagnostic_reports.updated_at`. On initial submission, `created_at` and `updated_at` are equal. The new lens uses `COALESCE(updated_at, created_at)` so legacy rows without an update timestamp still belong to a bucket. This timestamp is a general report modification timestamp, **not proof of communication or resolution**.

## Scope: additive API behavior

`GET /api/platform/diagnostics/reports` gains:

| Field | Type | Meaning |
| --- | --- | --- |
| Query `inactivity` | `under_24h`, `day_1_3`, `day_3_7`, `over_7d` | Restrict table rows to open reports with time since last report update within the selected bucket |
| Query `sort` | Two additions: `stale_first`, `recent_activity` | Sort on last report update ascending or descending with a deterministic `id` tie-breaker |
| Response `open_inactivity` | Aggregate object | Counts *open* reports across four inactivity intervals plus total |
| Row `updated_at` | ISO 8601 timestamp or null | Last report update (fallback to `created_at` when necessary) |

Other `sort` values (`newest`, `oldest`) and all Checkpoint 7.9/7.10 query filters and response properties remain compatible.

### Exact inactivity buckets

- `under_24h`: less than 24 hours since last report update.
- `day_1_3`: from 24 hours (inclusive) up to 72 hours (exclusive).
- `day_3_7`: from 72 hours (inclusive) up to 168 hours (exclusive).
- `over_7d`: at least 168 hours since last update.

Cutoffs come from `now()` on the server for each request; bound SQL parameters are used, with no query-supplied SQL fragments. Future-dated rows fall in the newest bucket. Reports with status `resolved` and `closed` are not included in these **open** buckets or in a table lane constrained by `inactivity`.

### Aggregation semantics

`open_inactivity` is calculated from the **company, category and impact** filter scope only; it deliberately ignores `status`, `assignment`, `age`, `inactivity`, `sort`, `page`, and `per_page`. This matches existing `summary` and `open_aging` behavior. Selecting an inactivity bucket affects table rows (`meta.total`) but not the overview. When `age` and `inactivity` are both specified, both conditions must match; neither overrides a specified status filter.

Example new property:

```json
{
  "open_inactivity": {
    "total": 6,
    "under_24h": 2,
    "day_1_3": 1,
    "day_3_7": 1,
    "over_7d": 2
  }
}
```

## Frontend

The existing React support queue adds the **Open report inactivity** filter, a read-only open inactivity breakdown, a `Last updated` column, and two activity-based ordering choices. Existing report detail, status/assignment mutation UI, audit history pagination, write-lock notices, optimistic locking and error handling remain in place. No new pages, routes, dependencies, or external integrations are introduced.

## Security invariants retained

- Only the existing `auth:sanctum`, `platform.admin` and `platform.mfa` gated route can return cross-company overview. Tenant members, guests, unverified platform identities, and requests with an `X-Company-ID` header remain blocked.
- Platform directory reads are still audited under the same allowlisted `platform.diagnostics.read` action.
- **No** tenant diagnostic intake/listing changes. All tenant endpoints retain their current company and reporter isolation.
- No business fields, screenshots, URL paths, form values, tokens, cookies, IPs, raw browser UA, free-text notes, messages, or attachments are added.
- The global default `PLATFORM_MUTATIONS_ENABLED=false` is untouched. Existing workflow mutations still require the write gate, verified MFA, and `workflow_version` concurrency protection.
- No platform admin provisioning or tenant role changes.
- No database migration, schema change, insert, update, deletion, retention job, or any contact with the MariaDB development database is included. ARVYA and E2E-TEST remain untouched.

## Supplied files (one ZIP)

The ZIP preserves repository-relative paths. Extract it **under Downloads**, then manually copy/merge the folders into `C:\Users\ASUS\Projects\laravel-property-erp-v13`:

1. `app/Http/Controllers/Api/Platform/PlatformDiagnosticController.php` — **replace**.
2. `frontend/src/features/platform/platform-diagnostics-api.ts` — **replace**.
3. `frontend/src/features/platform/platform-diagnostics-page.tsx` — **replace**.
4. `tests/Feature/Api/Diagnostics/DiagnosticSupportInactivityTest.php` — **add**.
5. `docs/checkpoint-7.11-support-queue-inactivity-monitoring.md` — **add**.

No `composer install`, `npm install`, `migrate`, `migrate --pretend`, `migrate:status`, or platform account provisioning is necessary for this checkpoint.

## Verification — Windows CMD

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

`git diff --check` only checks changes already tracked by Git; the PHP test file is run by `php artisan test` even before it is tracked. After all tests pass, the owner can stage their changes and run `git diff --cached --check` to cover both newly added files, then personally commit and push. **The assistant never commits or pushes.**

There are **12 new feature tests** for inactivity boundaries, opened/closed semantics, fallback timestamp, filter intersections, cross-company scope, status/assignment workflow refresh, sorting, pagination, validation, MFA/access restrictions, and empty scopes. The previous user-observed baseline was **119 tests passed / 638 assertions**. Updated aggregate numbers must be observed from the user's own machine, not assumed.

## Explicit exclusions

No SLA thresholds, alerts, notification emails, reminders, automated assignments, report purging, migration, or changes to tenant-visible support history. Neither `updated_at` nor `open_inactivity` establishes that a report was responded to by a human.
