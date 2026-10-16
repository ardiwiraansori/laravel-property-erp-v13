# Checkpoint 7.26 — Created Date Range Filters in Property Developments UI

**Baseline:** `ardiwiraansori/laravel-property-erp-v13` GitHub `main` SHA `3e06a42b72c8ca4df88c9a3bffc7e58d9c19f2a9` (Checkpoint 7.25, October 16, 2026). Apply only to a clean checkout of this commit. The four replacement file blobs were checked against the baseline SHA. This is a frontend-only change.

## Scope and UX

Two calendar controls (`Created From (UTC)` and `Created To (UTC)`) and an **Apply dates** button are added to the Property Developments directory. Either date can be blank (open-ended range). Values use strict `YYYY-MM-DD` and **UTC** database calendar dates, consistent with the 7.25 API. The backend applies date boundaries inclusively. Date fields are drafts until **Apply dates** is clicked; the page then resets to page 1 while preserving applied search/status/sort/page size. Reversed dates or impossible dates are rejected by frontend before triggering API navigation; users receive an accessible error.

Applied `created_from`/`created_to` values are serialized in the URL, alongside existing search/status/page/per_page/sort, for browser history, refresh and safe deep links. The detail page's existing URL parser/serializer automatically preserves the new filters when navigating back. Pagination, changes of sort/status/page size, search, stale-page recovery and CSV export all propagate the *applied* date range. **Unapplied draft changes never affect the request or CSV export.** Clear resets dates and all other filters.

The parser validates ISO calendar dates using a round-trip in UTC, dropping invalid individual bounds. A reversed range in an untrusted link drops both date bounds rather than sending a 422 or silently broadening the query to an unrelated one-sided interval. Unsupported URL keys including `company_id` are never serialized, submitted to API, or used as tenant authorization. No browser-local timezone conversion is made. The server remains authoritative for validation and membership/permission checks.

## Exact package contents — 5 repository-relative files

1. REPLACE `frontend/src/features/master-data/property-developments/directory-url.ts`
2. REPLACE `frontend/src/features/master-data/property-developments/property-development-api.ts`
3. REPLACE `frontend/src/features/master-data/property-developments/property-developments-page.tsx`
4. REPLACE `frontend/scripts/directory-links.test.mjs` (18 existing + 6 new test cases)
5. ADD `docs/checkpoint-7.26-created-date-directory-ui.md`

No backend routes, controllers, migrations, seeds, database writes, new dependencies, role grants, platform MFA or platform write lock changes.

## Verification on Windows CMD

Before extracting ZIP at repo root:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

Expect clean `main` SHA `3e06a42b72c8ca4df88c9a3bffc7e58d9c19f2a9`. Extract while preserving `frontend/` and `docs/` folders. Then:

```bat
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

Target unchanged **234 Laravel tests**, **14 route-chunk tests**, **24 directory-link tests**, lint and build successful. A full real-world React/browser smoke test can additionally verify combined filters, refresh/back navigation, detail return, exporting only the applied date window, and user-friendly validation of inverted dates. Actual Windows results must be reviewed before staging, commit, or push.

**Forbidden:** migrations, `db:seed`, `migrate:fresh`, `db:wipe`, writes to MariaDB/ARVYA/E2E-TEST, permission bootstrap, platform MFA changes, toggling `PLATFORM_MUTATIONS_ENABLED`, automatic repository commit or push. Tenant selection continues to be made only by the existing authenticated company context; frontend URL filters never authorize a tenant.
