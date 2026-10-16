# Checkpoint 7.22 — Shareable Directory Filters & Dashboard Drill-Down

**Verified baseline:** GitHub `ardiwiraansori/laravel-property-erp-v13`, `main`, commit `661c209a4e24205c78a0a05b1e05f0b65d0b691a` (user-tested and pushed Checkpoint 7.21). Apply **only** to a clean checkout at this exact SHA.

## Why

Property Developments currently has a filterable/paginated directory, detail deep links, a read-only portfolio summary and tenant-safe CSV export. The directory stores search/status/page only in ephemeral React state: refreshing or sharing the directory URL drops them, and the detail page's Back link loses the previous directory context. The dashboard's total/active/inactive numbers also lack direct filtered drill-down links. Checkpoint 7.22 improves navigation rather than adding another backend endpoint or new database objects.

## Behavior

- `/master-data/property-developments?search=Garden&status=inactive&page=2` loads the selected company directory with the applied search, status and page. Browser reload and Back/Forward navigation retain those **navigational filters**. This is not cross-tenant state persistence.
- Search text stays a draft until the user clicks Search; entering a new search starts at page 1. Changing status also starts at page 1. Pagination updates only the page query parameter. Clear restores the default URL.
- A development name link carries the current allowlisted directory query into the detail URL. The Back link reconstructs the same filtered directory URL, including after a direct page reload.
- Dashboard now provides View all, View active and View inactive links **only** when the existing master-data parent permission permits opening the directory. Summary visibility still requires the existing Property Developments View permission, as in 7.19.
- URL parser accepts only a trimmed search of at most 100 characters, statuses `all` (default), `active`, `inactive`, and a page number 1..999999 with simple decimal syntax (no leading zeros). Oversized/invalid values revert to defaults. URL serialization is canonical (omits defaults); unknown input keys and `company_id` never propagate to the server. URL filters are **not** an authorization source.
- The applied filter, not unsubmitted draft input, continues to drive the 7.21 CSV export. The existing 1,000-row bound and server-side tenant authorization are unchanged. A directory response for an older location is not shown while a URL navigation's next response is pending; the existing useEffect cleanup still discards obsolete responses.
- Pagination and filter links only change browser URL state, not roles, tenant membership or company context. Company switching continues to trigger the existing full SPA reload. No data is persisted in browser storage or transmitted to external services.

## Files (7 repository-relative paths)

1. ADD `frontend/src/features/master-data/property-developments/directory-url.ts` — canonical allowlist parser/serializer.
2. REPLACE `frontend/src/features/master-data/property-developments/property-developments-page.tsx` — URL-backed applied filters and contextual detail links.
3. REPLACE `frontend/src/features/master-data/property-developments/property-development-detail-page.tsx` — contextual Back link.
4. REPLACE `frontend/src/components/dashboard/property-development-summary-card.tsx` — status drill-down links.
5. ADD `frontend/scripts/directory-links.test.mjs` — 8 zero-network Node unit/integration-source checks (compiles actual utility using already-declared TypeScript dev dependency).
6. REPLACE `frontend/package.json` — adds `test:directory-links` script only; no dependency change or lockfile edits.
7. ADD `docs/checkpoint-7.22-directory-url-state-and-dashboard-drilldown.md` — scope, safety and Windows verification.

No backend/API/PHP file, SQL migration, seeder, role provisioning, tenant grant, production data, or platform configuration is modified. `master-data.property-developments.update` stays explicitly default-deny.

## Windows CMD verification

Before extracting the ZIP into the repository root:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

Require a clean tree at `661c209a4e24205c78a0a05b1e05f0b65d0b691a`. Extract to repo root, retaining the full `frontend/` and `docs/` paths. After extraction:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
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

Baseline from Windows after Checkpoint 7.21: 216 Laravel tests, 1,150 assertions, and 14 route-chunk tests. All must still pass. Frontend lint/build and new URL state test results must be checked in the user's own dependency tree. Do not commit or push until staged `git diff --cached --check` is clean and exactly seven files are staged.

**Manual browser verification:** With existing permitted tenant account, open Dashboard → View inactive. Verify URL and only inactive records. Enter a search but do not submit: results and export selection must remain based on applied filters. Apply search, page forward, open a detail, reload and use Back to developments. Verify browser Back/Forward changes filters without exposing rows from another company; changing tenant via the company selector should reload the SPA and query against the selected company only. An unauthorized user must still not gain access by typing a filtered URL.

**Out of scope / forbidden:** migrations, seeding, MariaDB writes/queries on live ARVYA or E2E-TEST, permission changes, platform MFA/write-lock modifications, GitHub push by the assistant. This ZIP has source/syntax/Node unit validations only; full ESLint/Vite/browser tests are the user's Windows verification.
