# Checkpoint 7.25 — Tenant-Safe Created Date Range Filter for Directory & CSV

**Base:** `ardiwiraansori/laravel-property-erp-v13` `main` SHA `eb7bdf41297d45b28bed5495d70044d12b8979ce` (Checkpoint 7.24). Confirm this commit is the exact clean local HEAD before replacing any files. No remote GitHub writes, database migrations/seeds, or tenant permission changes are included.

## Scope

Read-only **backend API** groundwork for a date-range filter. Both existing GET endpoints accept optional `created_from` and `created_to` query arguments as **strict `YYYY-MM-DD` dates, inclusive, interpreted as UTC database calendar dates**. Either endpoint can be used without these arguments; both share the existing tenant-scoped filter helper.

- `GET /api/master-data/property-developments?created_from=2026-02-01&created_to=2026-02-28&page=1`
- `GET /api/master-data/property-developments/export?created_from=2026-02-01&created_to=2026-02-28`

The dates are validated via Laravel `date_format:Y-m-d` and `after_or_equal:created_from`. Invalid calendar dates, unsafe arrays, or reversed ranges get HTTP 422 rather than a broad query. The filter uses query builder `whereDate` clauses on the existing `created_at` column; no new schema or indexes are introduced. The endpoints preserve search, status, sort, pagination and the CSV 1,000-row cap. The legacy no-query GET response stays unpaginated; date-only queries opt into the current paginated contract.

API authorization is unchanged: `auth:sanctum`, active company membership from resolved `X-Company-ID`, and `master-data.property-developments.view`. The company ID query string is not an authorization source. No tenant or business records are altered by date filtering. Update permission remains default-deny.

**Frontend is intentionally unchanged in this checkpoint.** The UI will gain `Created From/To` controls in a subsequent checkpoint after the API contract is verified. Existing URLs, dashboard links, and CSV downloads without date filters work exactly as before.

## Files — 5 repository-relative paths

1. REPLACE `app/Http/Controllers/Api/MasterData/PropertyDevelopmentController.php`
2. REPLACE `app/Http/Controllers/Api/MasterData/PropertyDevelopmentCsvExportController.php`
3. REPLACE `app/Support/MasterData/PropertyDevelopmentDirectoryFilter.php`
4. ADD `tests/Feature/Api/MasterData/PropertyDevelopmentCreatedDateFilterTest.php` (9 test methods)
5. ADD `docs/checkpoint-7.25-created-date-filter-api.md`

## Windows CMD preflight

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

Expected clean HEAD: `eb7bdf41297d45b28bed5495d70044d12b8979ce`. Extract package to repository root, preserving relative paths, overwriting only the three existing files listed above. Run:

```bat
php artisan test --filter=PropertyDevelopmentCreatedDateFilterTest
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

Checkpoint 7.24 baseline: 225 passed Laravel tests, 1,204 assertions, 14 route chunk tests and 18 directory-link tests. This checkpoint adds 9 PHP methods, so **234 tests** are expected if there is no regression. Actual test/assertion results must come from the user's Windows environment before staging, commit or push.

**Do not run** `php artisan migrate`, `migrate:fresh`, `db:wipe`, `db:seed` or perform any operations on ARVYA/E2E-TEST or MariaDB data. Do not provision permissions, enable platform mutations, or modify platform MFA.
