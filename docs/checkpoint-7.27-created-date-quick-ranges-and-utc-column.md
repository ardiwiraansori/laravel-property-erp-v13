# Checkpoint 7.27 — Quick Created-Date Ranges and UTC Column

## Baseline and scope

- Baseline: GitHub `main` at `6a86649843c637f663f5c6f8c1e8645d9f22a5fc` (checkpoint 7.26).
- Frontend-only change to the existing tenant-scoped Property Developments directory.
- No database schema/data migration, backend endpoint modification, role/permission change, or new package dependency.

## Features

1. Quick dates (UTC) dropdown: **Today**, **Last 7 days**, **This month**, and **Clear dates**.
2. Quick selection applies immediately; manual date inputs still require **Apply dates**.
3. Today means today's UTC calendar date. Last 7 days includes today plus six previous UTC calendar dates. This month is the first UTC date of this month through today.
4. Presets update `created_from` and `created_to` in canonical, allowlisted URL state, resetting pagination to page 1 without removing search, status, page size, or sort.
5. Directory table shows **Created (UTC)** using the UTC date of `created_at` from the existing read-only API. Missing or invalid values show an em dash, without inventing a date.
6. Existing detail deep links, URL Back/Forward, server-validated tenant permissions and CSV export continue to use the applied URL date range only.

## Date and security notes

- UTC is intentional and matches the backend created-date filter semantics (inclusive UTC days). This is not a browser-local calendar preset.
- The date range is not persisted in new storage. URL navigation determines applied filter state.
- Filters and CSV still use the existing Axios tenant context with server-side authorization. No caller-controlled `company_id` parameter is introduced.
- No access is granted to Create/Update permission or Platform Admin APIs.

## Verification on Windows CMD

Extract the ZIP into the repository root after confirming clean HEAD `6a86649843c637f663f5c6f8c1e8645d9f22a5fc`. Run:

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

Expected baseline counts: **234 Laravel tests**, **14 route-chunk tests**, and **30 directory-link tests**. Do not commit until all tests and lint/build pass. Only four repo-relative paths should change.
