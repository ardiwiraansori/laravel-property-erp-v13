# Checkpoint 7.19 — Tenant-Safe Property Portfolio Dashboard Summary

**Audited baseline:** `ardiwiraansori/laravel-property-erp-v13`, `main`, HEAD `a021e5b221d96a4fbd1714ccefdfb6f1fd4d1aa4` (tested and pushed Checkpoint 7.18). Apply only to that baseline with a reviewed, clean working tree. This ZIP is a continuation, not a new project.

## Scope and rationale

The tenant Dashboard currently shows session, API health, and technology information but no useful figures about business master data. Property Developments already supports create, company-scoped directory/filter/paging (7.15), and a separately protected update endpoint (7.18). This checkpoint adds a **read-only portfolio summary** on the existing Dashboard showing **total**, **active** and **inactive** development records for the current company. It does **not** imply number of residential units, houses available/sold, leads, revenue or cash flow. These three numbers count development records only.

There are no migrations, seeders, permission schema changes, new dependencies, platform API modifications or alterations to Property Development create/update/legacy listing responses.

## Backend API contract

New endpoint: `GET /api/master-data/property-developments/summary`

- Remains inside the existing `auth:sanctum` route group, `company` middleware and **existing** `can:master-data.property-developments.view` gate.
- The authenticated tenant context is resolved from the required, validated `X-Company-ID` header and the server-side `CurrentCompanyContext`. Query/body `company_id`, `status` and `page` cannot override scope or counts. An inactive company is denied.
- One aggregate database read inside `where('company_id', $context->id())`. All statuses are included. No row details, foreign identifiers, private data, names or company records are returned.
- HTTP 200 JSON response includes exactly these three integer counts:

```json
{"data":{"total":3,"active":2,"inactive":1}}
```

- An empty company returns three zeroes. Counts include inactive development records; they do not reflect client search/page filters.
- `401`: guest. `422`: authenticated request without a valid company header. `403`: non-member, inactive company or user missing the **view** permission. No new permission is created or granted.
- All operations are GET/read only. The endpoint does not unlock the 7.18 update permission and does not perform business mutations or platform administration actions.

## Frontend Dashboard

- Adds a **Property Development Portfolio** card to `/dashboard` **only** when `activeCompany.permissions` contains `master-data.property-developments.view`. Authorization is always enforced again by the backend; hiding the card is only UI convenience.
- Counts are fetched only when the card is mounted. The card uses the tenant-aware Axios instance (existing header) and shows an explicit loading state, general failure message with a manual Retry button, and zero-record empty state.
- A **View property developments** link appears only if the user additionally has `master-data.view`, which is required for the existing nested Master Data route. No new route or sidebar link is introduced.
- Async cleanup rejects obsolete responses; changing company still forces the existing full document reload. The card is keyed to the selected company ID.
- The existing Dashboard session/API/architecture cards remain intact. No platform dashboard or diagnostic screens are changed.

## Tenant and security invariants

1. No platform, tenant or Owner permission elevation; especially `master-data.property-developments.update` remains a distinct default-deny permission from 7.18.
2. No cross-tenant aggregate or query-selected company. Tenant membership, active-company status, and view permission remain server-side requirements.
3. API returns three integers only, not a list of developments, identifiers, contact data or user records. No export or audit side channel.
4. No changes to the `PLATFORM_MUTATIONS_ENABLED=false` default, platform MFA checks, or diagnostic access rules.
5. ZIP assembly must not query or modify MariaDB, ARVYA or E2E-TEST. Laravel test fixtures use only the project's existing SQLite in-memory testing environment.

## Package manifest (relative to repository root)

1. **ADD** `app/Http/Controllers/Api/MasterData/PropertyDevelopmentSummaryController.php`
2. **REPLACE** `routes/api.php` (import new controller + one permission-gated GET inside existing group)
3. **REPLACE** `frontend/src/components/dashboard/dashboard-page.tsx`
4. **ADD** `frontend/src/components/dashboard/property-development-summary-card.tsx`
5. **ADD** `tests/Feature/Api/MasterData/PropertyDevelopmentSummaryTest.php` (9 feature test methods)
6. **ADD** `docs/checkpoint-7.19-property-portfolio-dashboard.md`

No other files, schema changes or dependencies required. Extract to the repository root, retaining `app/`, `routes/`, `frontend/`, `tests/` and `docs/` directories; do not add an extra checkpoint subdirectory.

## Windows CMD — preflight and tests

**Before** copying the ZIP, ensure that the checkout has the expected HEAD and clean working tree:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

**After** extracting the ZIP to the repository root:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test --filter=PropertyDevelopmentSummaryTest
php artisan test
cd frontend
npm run lint
npm run build
npm run test:route-chunks
cd ..
git diff --check
git status -sb
```

Prior tested baseline 7.18: **182 Laravel tests / 1,005 assertions** and **12 Node route chunk tests**. This checkpoint adds 9 feature test methods, but actual totals may only be reported after running the suite on Windows.

Optional browser smoke test after automated tests: log in as a tenant member who has `dashboard.view` plus `master-data.property-developments.view`, open Dashboard, compare counters against the Property Developments directory, verify a member without the view permission does not see the card, test the Retry view with a temporarily disconnected local API, and change active company to verify full reload and correct counts. Note that these browser flows are not covered by the PHP and structural route-chunk suites.

**Do not run** `php artisan migrate`, `migrate:fresh`, `migrate:refresh`, `db:wipe`, `db:seed`, permission provisioning, manual MariaDB modifications or platform bootstrap. Do not commit or push until the actual Windows tests have been reviewed. Once they pass, stage **only the six paths** above and run `git diff --cached --check` to validate the new untracked files too. Only the repository owner commits and pushes.

## Packaging validation limitations

Package assembly checks exact source baseline, `php -l`, TypeScript/TSX parser diagnostics, ZIP content and whitespace. Without the owner's installed Laravel dependencies and frontend project toolchain, package validation cannot substitute for the full PHP test run, ESLint, actual Vite build and browser testing. No claims of those runs are made until the user supplies their Windows CMD logs.
