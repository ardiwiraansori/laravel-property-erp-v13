# Checkpoint 7.23 — Directory Page Size & Stale-Page Recovery

**Verified baseline:** GitHub `ardiwiraansori/laravel-property-erp-v13` `main` at `5e248308c58a4d2ee152b3b6512611a21ce6f6a3` (user-tested and pushed 7.22). Apply only after checking local HEAD and a clean working tree.

## Scope

The Property Developments directory already supports server-side `per_page` between 1 and 50, but the React directory always requests 10 rows per page. This checkpoint adds a user-selectable, **allowlisted page size: 10, 25, or 50 rows**. The selected value lives in the URL as `per_page=25|50` (10 is the default and omitted); browser refresh, Back/Forward, and navigation from list to detail and back retain it. Changing page size resets to page 1 while preserving applied search and status. Search and status changes also reset to page 1 while preserving page size; Clear resets all to default. A non-default page size changes pagination only, **not** the matching-record CSV export.

If a shared URL or changed data selects a page beyond `meta.last_page`, the directory replaces that URL with the last valid page (page 1 for an empty result) and fetches again. This avoids incorrectly showing an empty directory for a page that has merely become out of range. The fallback is bounded by the server pagination metadata for the authenticated company, never by an untrusted tenant ID. `replace: true` prevents a broken page becoming a history loop. Old responses are still discarded with effect cleanup and the loading key includes all query dimensions.

## Security & compatibility

- This is a frontend-only navigation improvement and uses existing `GET /api/master-data/property-developments?page=...&per_page=...&search=...&status=...`, under the existing tenant membership and permission checks. No route or API contract change, migrations, new permissions or grants.
- URL parser accepts only exact `per_page` strings `25` or `50`, with `10` as default; values like `0`, `51`, `025`, `25.0`, array syntax, company ID, and any other unknown params are never copied into API calls. URL serialization always omits defaults and unknown keys.
- The status filter, 1,000-record CSV bound, `master-data.property-developments.update` default-deny, and platform security/write lock remain unchanged. CSV still exports all matching rows regardless of page or page size.
- No changes to MariaDB, ARVYA/E2E-TEST, seeders, files of other tenants, or GitHub remote are performed. Tests run only locally and do not access external services.

## Exact files (4 repo-relative paths)

1. REPLACE `frontend/src/features/master-data/property-developments/directory-url.ts`
2. REPLACE `frontend/src/features/master-data/property-developments/property-developments-page.tsx`
3. REPLACE `frontend/scripts/directory-links.test.mjs` (existing eight tests extended with five focused tests; no new package script)
4. ADD `docs/checkpoint-7.23-directory-page-size-and-stale-page-recovery.md`

## Windows CMD verification

Before ZIP extraction, verify a clean local checkout at `5e248308c58a4d2ee152b3b6512611a21ce6f6a3`:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

Extract ZIP into repository root, preserving `frontend/` and `docs/`. Then:

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

Baseline 7.22: 216 Laravel tests / 1,150 assertions, 14 route-chunk tests, and eight directory-link tests. Expect directory-link count to rise to 13 if all five new tests pass. Do not commit or push before the owner confirms Windows results and staged `git diff --cached --check` is clean.

**Manual smoke checks:** open `?search=Rumah&status=active&per_page=25&page=2` under an already permitted tenant account. Verify page size 25, detail Back link retains parameters, browser history and refresh preserve the page size, and export still ignores pagination. Test a stale `?page=999999&per_page=50` URL redirects to a valid final page, showing the active tenant's result (or genuine zero state). Switching active company must continue using existing company-context controls.

## Validation limits

Package preparation can check exact baseline blob hashes, TypeScript parsing, Node test behavior and ZIP integrity. Full TypeScript project build, ESLint, Vite and browser smoke testing require the owner's Windows environment. No backend or database command is executed during package preparation.
