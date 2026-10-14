# Checkpoint 7.14 — Tenant Diagnostic Progress Timeline

**Source of truth:** `ardiwiraansori/laravel-property-erp-v13` GitHub `main`, commit `131276d73a25ac9bab8f8e3e803de0ad01893132` (Checkpoint 7.13). The repository owner confirmed `HEAD`, `origin/main` and a clean local working tree after committing/pushing this baseline. Do not install on a different HEAD or an unreviewed dirty working tree.

## Problem and scope

7.8 introduced support workflow status transitions and an internal platform event history. 7.9–7.13 added platform-only queue and performance intelligence. The tenant reporter, however, can only view the current status on **Laporan saya**, without a history of status changes.

This checkpoint lets **only the original reporter in the active company** inspect a sanitized, paginated status timeline for their own diagnostic report. No platform operator details, assignments, identity IDs, internal audit trail, comments, or business data become tenant-visible.

## API contract

Add **only** the new read-only route to the existing tenant-scoped group under `auth:sanctum` and `company`:

`GET /api/diagnostics/reports/{publicId}?page=1`

- `{publicId}` must be a 26-character uppercase Crockford ULID, consistent with existing platform routes. Unknown or malformed public IDs return HTTP 404.
- The report is queried with **all three** server-defined predicates: `public_id`, authenticated `CurrentCompanyContext::id()`, and the authenticated user's ID. A different reporter **or company** receives 404, even if the ULID is known; disabled companies, absent headers, nonmembers, and guests retain existing middleware responses.
- `page` is optional, integer >=1, else HTTP 422. Server page size is fixed at **20** events, ordered by event ID descending (stable pagination). There is no user-controlled event filter, company/reporter override or SQL sort column.
- Event query reasserts both `diagnostic_report_id` and `company_id`, restricts `action=status_changed`, and only returns `from_status` and `to_status` from the fixed `DiagnosticWorkflow::STATUSES` allowlist. Assignment events and malformed historical status events are not displayed.
- The response contains only the report's `public_id`, category, module, impact, status, created/updated timestamps and status-only event pairs with timestamps. No platform audit, admin/reporter/assignee IDs, notes, free-form messages, IP, URL or other sensitive fields are returned.

Representative response:

```json
{
  "data": {
    "public_id": "01K7D6F1FQPW3Y0AFCWVBRWTQ8",
    "category": "page_error",
    "module": "dashboard",
    "impact": "partial",
    "status": "triaged",
    "created_at": "2026-10-14T08:00:00+07:00",
    "updated_at": "2026-10-14T09:00:00+07:00"
  },
  "history": [
    { "from_status": "new", "to_status": "triaged", "created_at": "2026-10-14 09:00:00" }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

The example timestamps illustrate shapes; event timestamps use the existing database timestamp serialization. No synthetic status event is created for reports without any history, even if their current status is terminal.

## React UX

- The current **Laporkan Masalah → Laporan saya** page gains a **Lihat progres** / **Tutup detail** action per report.
- Expanded details show the current status, created/updated timestamps and sanitized status transitions. Display includes empty, loading, error/retry and server-paginated history states.
- The selected report is tied to the **active company**, so switching tenants hides the old tenant's detail. Stale responses are ignored after switching report/company or page (effect cleanup). Existing diagnostic submission and listing behavior remain intact.
- No new dependencies, routes in React, screenshots, text fields, messages or file uploads.
- `updated_at` is a general report modification timestamp: it does not guarantee a human reply, an SLA or a resolution deadline.

## Security invariants

1. The new API remains behind `auth:sanctum` and membership-resolved active `company` middleware. No platform MFA bypass or tenant-to-platform access is granted.
2. Tenant Owner is not a Platform Super Admin. A platform identity without company membership cannot use the tenant route.
3. 404 for records that belong to another reporter or tenant minimizes enumeration. The client cannot supply `company_id` or `reporter_user_id` to elevate access.
4. No platform assignment details, actor ID, recovery codes, tokens, cookies, passwords, business data, attachments or support notes are exposed. Existing platform diagnostics endpoint and platform MFA/writes are unchanged.
5. `PLATFORM_MUTATIONS_ENABLED=false` stays the default. The new action is GET-only and never inserts, updates or deletes diagnostic reports/events.
6. No Super Admin provisioning. No migration, schema change, seeding, command, retention/deletion job or MariaDB data modification; ARVYA and E2E-TEST remain untouched. Existing test suite runs SQLite in-memory.

## Single ZIP contents

All files are relative to repository root. Extract under Downloads, then manually merge/copy to the repository root, replacing only the four tracked paths and adding the two new files:

1. **REPLACE** `app/Http/Controllers/Api/Diagnostics/DiagnosticReportController.php`
2. **REPLACE** `routes/api.php`
3. **REPLACE** `frontend/src/features/diagnostics/diagnostic-api.ts`
4. **REPLACE** `frontend/src/features/diagnostics/report-problem-page.tsx`
5. **ADD** `tests/Feature/Api/Diagnostics/DiagnosticTenantProgressTest.php` — eleven feature tests
6. **ADD** `docs/checkpoint-7.14-tenant-diagnostic-progress-timeline.md`

## Windows CMD verification, before any commit

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test --filter=DiagnosticTenantProgressTest
php artisan test
cd frontend
npm run lint
npm run build
cd ..
git diff --check
git status -sb
```

Do **not** run `php artisan migrate`, `migrate:fresh`, `migrate:refresh`, `db:wipe` or any provisioning. No migration is present, so there is no migration status/pretend step for this checkpoint.

**Expected delta:** 11 new test methods relative to user-verified Checkpoint 7.13 baseline **148 passing / 860 assertions**. Actual totals must be observed rather than assumed. Full PHPUnit/ESLint/Vite were **not** run in package assembly, which lacks the project's Composer and npm dependencies; preliminary verification comprises PHP syntax checks, TypeScript/TSX parsing and ZIP integrity only.

After reviewing local test results, stage exactly these six paths and run `git diff --cached --check` (includes the new untracked files) before deciding to commit. Repository owner alone performs `git add`, `git commit`, and `git push origin main`. Assistant never pushes, commits or changes the user's development database.
