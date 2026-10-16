# Checkpoint 7.21 — Tenant-Safe Property Development CSV Export

**Verified baseline:** GitHub `ardiwiraansori/laravel-property-erp-v13` branch `main` SHA `9d9b6c9cd03e8041e917bef79965c7c0a46f1632` (user-verified and pushed Checkpoint 7.20 on October 16, 2026). Apply only on this exact, clean checkout; the file replacements have been matched to the GitHub baseline blob hashes.

## Why and scope

7.15 delivered a searchable, paginated tenant directory; 7.18 implemented separately permissioned name/status editing; 7.19 a tenant dashboard summary; 7.20 a direct-linked detail page. Teams also need a **read-only export** of their filtered perumahan master directory for offline analysis and reconciliation. This checkpoint adds a CSV button and one bounded, view-permissioned API endpoint. It does **not** create a new business record, expose inventory/financial/customer data, grant permissions, install dependencies, migrate databases or alter platform security.

## API

`GET /api/master-data/property-developments/export?search=<text>&status=all|active|inactive`

- Requires `auth:sanctum`, the existing `company` resolver validating active membership, and `can:master-data.property-developments.view`. **No new permission** is created or granted. API authorization is independent of the React button. Tenant identity is exclusively `CurrentCompanyContext::id()` from a validated `X-Company-ID`, never `company_id` in the query or form.
- `search` (max 100, optional) and `status` (all/active/inactive, default all) use exactly the same filter helper as the existing paginated directory. SQL LIKE wildcards `!`, `%`, `_` are escaped as literals; case-insensitive code/name searching matches directory semantics. Results sorted by name then id.
- `page`/`per_page` are **prohibited** because export includes all filtered rows, not just the current pagination window. 422 for invalid inputs.
- Export is **capped at 1,000 matching records**. An extra row is read to detect overflow. If more than 1,000 records match, HTTP 422 returns a brief safe error, *not* a partially truncated CSV. Narrow the search/status filters to export smaller sets. This is a deliberate bounded application limit, not an assertion that the underlying database has only 1,000 records.
- On success: `text/csv; charset=UTF-8`, UTF-8 BOM for Excel/Windows compatibility, attachment filename `property-developments.csv`, `private, no-store` caching. No tenant name/id in filename or content.
- Exact columns are `Code`, `Name`, `Status`, `Created at (UTC)`. `created_at` is ISO 8601 UTC; no extra record fields are exposed. CSV generated using native `fputcsv` with explicit empty escape for correct quote encoding. Empty company yields just the header.
- All user-editable CSV values are protected from spreadsheet formula execution (`=`, `+`, `-`, `@` after optional whitespace). ASCII control characters/newlines are replaced with spaces and dangerous values are prefixed with a literal apostrophe. The apostrophe is an intentional safety transformation and can appear in displayed/exported data; the original database value remains unchanged.
- Endpoint is strictly GET/read-only; no model mutations, seeding, SQL writes, platform write overrides or audit table creation. The existing `PATCH` update permission remains **default-deny**.

## Frontend

The current Property Developments directory gains an **Export CSV** button next to search/status actions. It uses the existing Axios instance with tenant session/headers and exports the **applied** search and status filters, not unapplied text in the search field. The download is initiated by a user click using a local browser Blob URL. UI blocks repeat downloads during request, and shows an error message on failure. User must review exported CSV before trusting it for downstream decisions. There is no automatic export, background processing, external network destination, or login context change.

## Exact ZIP contents (8 repository-relative files)

1. REPLACE `app/Http/Controllers/Api/MasterData/PropertyDevelopmentController.php` (share filter helper; legacy GET retained)
2. ADD `app/Http/Controllers/Api/MasterData/PropertyDevelopmentCsvExportController.php`
3. ADD `app/Support/MasterData/PropertyDevelopmentDirectoryFilter.php`
4. REPLACE `routes/api.php`
5. REPLACE `frontend/src/features/master-data/property-developments/property-development-api.ts`
6. REPLACE `frontend/src/features/master-data/property-developments/property-developments-page.tsx`
7. ADD `tests/Feature/Api/MasterData/PropertyDevelopmentCsvExportTest.php` (15 test methods)
8. ADD `docs/checkpoint-7.21-property-development-csv-export.md`

## Windows 10 CMD — owner-controlled verification

Preflight **before** extracting the ZIP into the repository root:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

Must show a clean `main` with HEAD `9d9b6c9cd03e8041e917bef79965c7c0a46f1632`. Preserve the `app/`, `routes/`, `frontend/`, `tests/`, and `docs/` folders on extraction; do not extract into an extra subfolder.

After extraction:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test --filter=PropertyDevelopmentCsvExportTest
php artisan test
cd frontend
npm run lint
npm run build
npm run test:route-chunks
cd ..
git diff --check
git status -sb
```

Expect **15 new PHP test methods** above the 201 user-reported Laravel tests in 7.20, and continued success on existing 14 route-chunk tests. Do not assume actual test counts, assertions or frontend build success until the Windows run. Manual browser smoke testing with an already authorized view account: apply a search and status filter, change pages, export to CSV and confirm the file contains all matching rows and Excel opens it safely. Verify an unprivileged account is denied. The export endpoint still requires server-authoritative RBAC even if the UI is manipulated.

**Forbidden as part of this checkpoint:** `php artisan migrate`, `migrate:fresh`, `db:wipe`, `db:seed`, role-grant/bootstrap, MariaDB updates, modifications to ARVYA/E2E-TEST, platform MFA or `PLATFORM_MUTATIONS_ENABLED`. No GitHub commit/push until test logs and a staged `git diff --cached --check` are reviewed. Only the repository owner does Git writes.

## Packaging limits

The preparation environment can check source blob hashes, PHP/TypeScript syntax, ZIP integrity and static security assertions but has no installed project Composer/npm dependency tree for full PHPUnit/ESLint/Vite execution. Actual automated and browser behavior needs verification in the owner's Windows environment. CSV formula protection is defense in depth and should not replace spreadsheet safety policies for further data processing.
