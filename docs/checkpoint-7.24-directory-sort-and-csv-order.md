# Checkpoint 7.24 — Tenant-Safe Directory Sort & Matching CSV Order

**Exact baseline:** GitHub `ardiwiraansori/laravel-property-erp-v13`, `main` SHA `482a4b833425c307d1194249e1c8961303b9dec8`, verified October 16, 2026. Install only on this clean checkout. No GitHub writes or application/database mutations are part of the package process.

## Scope and behavior

Users can now sort the Property Developments directory by **Name A–Z / Name Z–A, Code A–Z / Code Z–A, Newest first / Oldest first**. A dropdown selects an allowlisted sort key; changing sort resets to page 1, while retaining the applied search, status, and page-size filters. Sorting is server-side across all matching records **before pagination**, not a client-only sort of one page. A stable ascending `id` tie-breaker keeps page boundaries deterministic for records with the same sort value.

The URL uses `sort=name_desc|code_asc|code_desc|created_desc|created_asc`; default `name_asc` is omitted. Reload, Back/Forward, detail navigation and stale-page recovery preserve the selected sort. The same `sort` is passed to the CSV export so the exported **complete** filtered result follows the visible directory order, while still enforcing the 1,000-record export limit independently of the page size.

## API and authorization

- Existing paginated `GET /api/master-data/property-developments` now optionally accepts `sort`; if `sort` is supplied without other query parameters, this opts into the paginated contract. Calls with **no query parameters** retain the previous unpaginated name-ascending contract. Existing response field shapes are unchanged.
- Existing `GET /api/master-data/property-developments/export` optionally accepts the same `sort`. The two endpoints validate `sort` against `PropertyDevelopmentDirectoryFilter::SORTS`; unknown, array, malicious, or SQL-like values return HTTP 422, rather than becoming SQL identifiers.
- Both use the same static column/direction mapping and stable ascending `id` tie-breaker. No dynamic SQL identifier is ever formed from user-supplied input. `search`/`status` filters remain shared, literal LIKE wildcard escaping remains unchanged, and company scoping still comes exclusively from `CurrentCompanyContext::id()`.
- The same tenant membership middleware and `master-data.property-developments.view` permission protect every request. The `master-data.property-developments.update` permission remains default-deny; Owner/Platform boundaries, MFA and the default platform write lock are unchanged.
- No migrations, seeders, permission grants, schema changes, new columns, API routes, dependencies or backend writes.

## Files included (9 repo-relative paths)

1. REPLACE `app/Http/Controllers/Api/MasterData/PropertyDevelopmentController.php`
2. REPLACE `app/Http/Controllers/Api/MasterData/PropertyDevelopmentCsvExportController.php`
3. REPLACE `app/Support/MasterData/PropertyDevelopmentDirectoryFilter.php`
4. REPLACE `frontend/src/features/master-data/property-developments/property-development-api.ts`
5. REPLACE `frontend/src/features/master-data/property-developments/directory-url.ts`
6. REPLACE `frontend/src/features/master-data/property-developments/property-developments-page.tsx`
7. REPLACE `frontend/scripts/directory-links.test.mjs`
8. ADD `tests/Feature/Api/MasterData/PropertyDevelopmentSortTest.php` (9 feature tests)
9. ADD `docs/checkpoint-7.24-directory-sort-and-csv-order.md`

## Windows CMD verification

Before extraction verify `git status -sb` is clean and `git rev-parse HEAD` returns `482a4b833425c307d1194249e1c8961303b9dec8`. Extract the ZIP at the **repository root** preserving `app/`, `frontend/`, `tests/`, `docs/` folders.

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test --filter=PropertyDevelopmentSortTest
php artisan test
cd frontend
npm run lint
npm run build
npm run test:route-chunks
npm run test:directory-links
cd ..
git diff --check
git status -sb
```

Expected baseline: 216 Laravel tests / 1,150 assertions, 14 route chunk tests, 13 URL/directory link tests; this package adds nine PHP tests and five JS tests, for **225 Laravel tests and 18 URL tests** if all pass. Do not presume assertion totals or full test success until the Windows owner provides logs. `npm run test:route-chunks` must run after a successful `npm run build`.

Manual browser smoke check (authorized tenant only): sort Code descending, navigate page 2, open a development detail and return, refresh the directory, then export CSV; verify ordering agrees, and check that switching tenant does not show data from the previous company. No real database access is required to execute the automated tests; configured Laravel test environment uses disposable SQLite in-memory.

No `php artisan migrate`, seed, `db:wipe`, `migrate:fresh`, platform capability toggle or MariaDB operations. Never operate on ARVYA/E2E-TEST; only the owner performs Git commit/push after reviewing tests, staged files and `git diff --cached --check`.
