# Checkpoint 7.16 — Frontend Route-Level Code Splitting

**Source of truth:** `ardiwiraansori/laravel-property-erp-v13`, GitHub `main`, **`18f0d596ecfbc496eaa9de91a55858c2a1ebe255`**, the user-tested and pushed Checkpoint 7.15. This HEAD was verified after the user's successful push on 2026-10-16. **Do not apply this ZIP to an older HEAD or unreviewed dirty checkout.**

## Why this checkpoint

7.15 completed the tenant-safe searchable Property Developments directory (170 tests, 969 assertions in the user's Windows environment). That verification also exposed a Vite warning: the initial minified JS bundle is 663.79 kB. Reading `frontend/src/app/router.tsx` shows that all platform and tenant feature pages are statically imported, including the large platform diagnostics screen. As Property ERP acquires more modules, one eager entry bundle becomes an increasingly costly startup dependency.

This checkpoint defers seven feature screens until the user actually navigates to them. This is **frontend delivery optimization**, not a security mechanism, a change to access rights, or a claim that route source code is confidential. There are no business-module changes, migrations, platform mutations, or new dependencies.

## Scope

`React.lazy` + `Suspense` are defined in `frontend/src/app/deferred-pages.tsx` and used by the existing router for:

1. Tenant Dashboard
2. Tenant Report Problem
3. Tenant Property Developments
4. Platform Overview
5. Platform Companies
6. Platform Diagnostics
7. Platform Users

The authentication / session / tenant company context / permission / platform MFA guard components remain **eager**, in the **same parent/child route positions**, with existing `PermissionRoute` wrappers retained. The dedicated `/platform/mfa`, `/login`, `/entry`, and fallback routes also retain their existing routing and availability. Module placeholders stay unchanged.

The loading fallback is a minimal accessible `role=status` message, rendered within the existing layout. No preloading is added to bypass route checks.

### API and security invariants

- No backend endpoints, authorization middleware, cookies, tokens, headers, role grants, form bodies, data flows or request semantics change.
- `auth:sanctum`, tenant `company` resolution, per-company Spatie permission checks, platform identity and MFA remain entirely server-authoritative.
- The `PlatformRoute` authorization check is still above the platform page routes. `ProtectedRoute` + company context are still above tenant page routes, and `PermissionRoute` still gates protected tenant screens.
- Client-side lazy-loading **does not protect proprietary source code**; it merely changes when JavaScript is fetched. All API data remains protected by the existing backend security checks.
- `PLATFORM_MUTATIONS_ENABLED=false` remains the default. No platform provisioning, write gate modification, schema change, seeding, MariaDB query/write, or ARVYA/E2E-TEST access is performed.
- A normal page-load error or unavailable JS chunk may require browser refresh; no attempt is made to change global error boundaries in this narrowly scoped checkpoint.

## Automatic verification

Adds a **zero-dependency** Node.js `node:test` verification script for seven lazily imported module chunks, the presence of core route guards in the router, and separation of Fast Refresh component exports from router configuration. It reads the **production Vite `dist/assets` output**, so it runs **after** `npm run build`:

`npm run test:route-chunks`

A successful script checks for seven separate, nonempty Vite page chunks, a separate `index` entry, unchanged eager routing guard references, and that the lazy components are exported from a separate component-only file. The exact chunk sizes and total bytes are deliberately **not** hard-coded: they depend on Vite/Rolldown output. Build warnings are not automatically suppressed or treated as passing performance targets.

Existing PHP feature tests continue to protect the server-side API isolation, MFA and RBAC. We do not add unrelated PHP tests to measure a frontend bundle. Manual browser smoke tests should additionally cover first-load navigation, refresh on a deep link, returning to visited screens, missing tenant permission, platform MFA challenge, and an expired session.

## Exact checkpoint contents (repository-relative)

The checkpoint consists of the following **five paths** after applying the original ZIP and this lint correction:

1. **REPLACE** `frontend/src/app/router.tsx`
2. **ADD** `frontend/src/app/deferred-pages.tsx` — exports React components only
3. **REPLACE** `frontend/package.json` (adds only `test:route-chunks`; no dependency changes)
4. **ADD** `frontend/scripts/route-chunks.test.mjs`
5. **ADD** `docs/checkpoint-7.16-route-level-code-splitting.md`

The **lint-fix ZIP** includes only these **four** relevant files: `router.tsx`, `deferred-pages.tsx`, `route-chunks.test.mjs`, and this documentation. It applies **on top of the original 7.16 ZIP already copied to your uncommitted working tree**. `package.json` from the original ZIP remains unchanged and should **not** be reverted. Extract to the repository root, retaining `frontend/` and `docs/` directories. There is no `package-lock.json` change or dependency installation.

## Windows CMD — preflight and testing

**Before** replacing files:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

For initial installation, confirm a clean tree at `18f0d596ecfbc496eaa9de91a55858c2a1ebe255`. For **this correction**, the local working tree is intentionally dirty with the original 7.16 changes. Do not discard them or run a reset.

**After** extracting:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test
cd frontend
npm run lint
npm run build
npm run test:route-chunks
cd ..
git diff --check
git status -sb
```

If a test or build fails, send the error output and do not commit. If successful, send results first so exact staging commands and the final commit/push can be reviewed. The repo owner alone commits/pushes. As there are no database migrations, **do not** run `php artisan migrate`, `migrate:fresh`, `db:wipe`, seeders or bootstrap commands.

## Lint-fix verification record — October 16, 2026

User Windows CMD output from the original 7.16 package: **170 Laravel tests passed (969 assertions)**, production Vite build succeeded, all **9** original route-chunk tests passed, and `git diff --check` reported no whitespace issues. Entry JS was **503.63 kB** (previous 7.15 entry: 663.79 kB), still slightly above Vite's 500 kB warning threshold. `npm run lint` failed with **8** `react-refresh/only-export-components` errors because `router.tsx` contained seven locally declared lazy React components plus `DeferredPage`, while also exporting a non-component `router` instance.

The correction moves those eight component declarations to a separate component-only module; router guards, route hierarchy, dynamic import targets, and suspense fallback are preserved. This keeps the Fast Refresh rule **enabled**. The Node test was updated to inspect the new module and adds an assertion for the separation. Expected new Node test count: **10**, to be verified on the user's local installation. No back-end files or dependencies change.

After applying the lint-fix ZIP run from the project root:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
cd frontend
npm run lint
npm run build
npm run test:route-chunks
cd ..
git diff --check
git status -sb
```

Do not commit while any lint/test fails. Do not change the Vite warning limit merely to hide the remaining bundle warning. That may be addressed in a separate checkpoint if performance metrics warrant it.

## Packaging validation limitation

The initial ZIP was checked for complete paths, JSON consistency, TypeScript/TSX syntax and JavaScript syntax, and ZIP integrity. This lint-fix package is checked separately for TS/TSX syntax, test script syntax, ZIP integrity, and simulated chunk-test results. **Full** Laravel tests, ESLint, production Vite build, actual emitted route chunks, and browser tests **must be verified on the owner's Windows environment**; they cannot be inferred from syntax alone. The 663.79 kB initial bundle was measured at 7.15, not measured again after this checkpoint in the package-building environment.
