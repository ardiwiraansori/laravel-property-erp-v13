# Checkpoint 7.20 — Tenant-Safe Property Development Detail & Deep Link

**Source of truth:** GitHub `ardiwiraansori/laravel-property-erp-v13`, `main` commit `fcce1fcf2de5d221841adc865eb2fa843184b831` (Checkpoint 7.19). Verified October 16, 2026. Install only after confirming this exact local HEAD and a clean working tree. No GitHub writes, migrations, seeders, role grants, or operations on ARVYA/E2E-TEST databases are part of this package.

## Reason and scope

7.15 brought a bounded tenant directory; 7.18 brought explicitly permission-gated editing; 7.19 brought a scoped portfolio summary on the dashboard. A directory row currently has no direct-linkable read-only detail view, which complicates internal navigation and later references to developments from ERP modules.

This checkpoint introduces one read-only detail API and one React deep-link page; the **name** in the existing directory links to the detail page. Detail fields are allowlisted: `id`, `code`, `name`, `is_active`, `created_at`, `updated_at`. `company_id`, membership information, and business/private metadata are not exposed. `updated_at` is **detail-only**, preserving the exact legacy and paginated list contracts, POST, PATCH and summary response shapes.

## API

`GET /api/master-data/property-developments/{development}` where `{development}` is a numeric ID. Sample response:

```json
{
  "data": {
    "id": 12,
    "code": "GRIYA-01",
    "name": "Griya Residence",
    "is_active": true,
    "created_at": "2026-10-16T00:00:00.000000Z",
    "updated_at": "2026-10-16T00:00:00.000000Z"
  }
}
```

The new API route remains under `auth:sanctum`, the active-company membership validating `company` middleware, and `can:master-data.property-developments.view`. The query always starts from `where(company_id, CurrentCompanyContext::id())` before record ID selection. A record in another company and a nonexistent ID both return 404; a missing permission returns 403, guest 401, and missing/malformed company header 422. Caller-supplied `company_id` query parameters do **not** override the header's server-resolved tenant. `/summary` is defined **before** the numeric detail route; nonnumeric IDs are not matched by the detail route.

The endpoint performs no writes, adds no permission grants, and cannot change status or code. Tenant Owner gains no platform administration authority. `master-data.property-developments.update` remains default-deny as introduced in 7.18; this checkpoint does **not** register it in the Owner auto-grant seeder.

## React

Existing directory names become accessible links to `/master-data/property-developments/{id}`. The new page is lazy-loaded as a distinct production chunk; it is nested under the same `ProtectedRoute` + `CompanyProvider` + `PermissionRoute(propertyDevelopmentsView)` boundaries as the directory. The server is authoritative for every read.

The page displays code, name, status and both timestamps, along with a back link. It supports initial loading, 404/not-found (also used for foreign-company records), transient errors with an explicit Try again button, and stale-response discarding on route or active company changes. Invalid/non-positive/non-numeric deep-link IDs do not trigger a fetch. Refreshing a deep link is expected to work under the existing SPA fallback configuration; browser smoke testing is still required. Changing company using the existing switcher reloads the SPA and drops tenant state.

These are **display-only** additions: editing still happens through 7.18's separately permissioned directory form. This feature does not disclose database schema, tenant ID, or grant permissions by following a link. Lazy loading is a performance feature, not authorization.

## Files included (11 paths; repository-relative)

1. REPLACE `app/Http/Controllers/Api/MasterData/PropertyDevelopmentController.php`
2. ADD `app/Http/Resources/MasterData/PropertyDevelopmentDetailResource.php`
3. REPLACE `routes/api.php`
4. REPLACE `frontend/src/features/master-data/property-developments/property-development-api.ts`
5. REPLACE `frontend/src/features/master-data/property-developments/property-developments-page.tsx`
6. ADD `frontend/src/features/master-data/property-developments/property-development-detail-page.tsx`
7. REPLACE `frontend/src/app/deferred-pages.tsx`
8. REPLACE `frontend/src/app/router.tsx`
9. REPLACE `frontend/scripts/route-chunks.test.mjs`
10. ADD `tests/Feature/Api/MasterData/PropertyDevelopmentDetailTest.php` (10 new test methods)
11. ADD `docs/checkpoint-7.20-property-development-detail-deep-link.md`

No dependency, migration, build configuration, permission enum or seeder changes.

## Windows CMD verification

Preflight **before** copying ZIP into repository root:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

Must be clean, with SHA `fcce1fcf2de5d221841adc865eb2fa843184b831`. If not, stop and review before extracting. Extract ZIP preserving the `app/`, `routes/`, `frontend/`, `tests/`, and `docs/` paths; do not place under an extra checkpoint subfolder.

After extracting:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test --filter=PropertyDevelopmentDetailTest
php artisan test
cd frontend
npm run lint
npm run build
npm run test:route-chunks
cd ..
git diff --check
git status -sb
```

7.19 baseline had **191 Laravel tests / 1,029 assertions** and **12 route-chunk tests** in the user-reported Windows log. This checkpoint adds 10 PHP test methods and extends Node chunk tests for the new page. Do not claim final totals until actual Windows output is received. No migration/seeders or MariaDB modification is necessary to test this code on the configured disposable SQLite in-memory test database.

**Manual browser smoke test** (non-destructive): with an account granted Property Developments View, open the directory and click a name, reload the deep link, verify Created / Last updated values and Back link. Confirm absence of the detail route for a user without View and that another tenant's known ID results in a safe Not found message. These tests do not require permission provisioning beyond already authorized read-only access.

## Security and deployment limits

- The API does not accept tenant ID from URL/query/payload as an authorization source. Company and permission checks run independently of the React guard.
- No new `master-data.property-developments.update` grant, Platform Admin or MFA change, platform write-lock change (`PLATFORM_MUTATIONS_ENABLED=false`), new export, new log, or schema modification.
- No access to / modification of MariaDB, ARVYA/E2E-TEST, existing tenant data or production GitHub branch is performed in packaging.
- Developer validation here covers source file matching to GitHub baseline blobs, PHP lint, TS/TSX parse, Node test syntax, ZIP integrity, staged-like whitespace. **Full PHPUnit, ESLint, Vite, and browser behavior require Windows owner testing**. Do not commit/push until all automated tests are reviewed and staging passes `git diff --cached --check` with exactly 11 paths.
