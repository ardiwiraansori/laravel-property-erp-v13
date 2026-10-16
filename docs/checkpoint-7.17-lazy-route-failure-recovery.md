# Checkpoint 7.17 — Lazy Route Failure Recovery

**Verified source of truth:** `ardiwiraansori/laravel-property-erp-v13` GitHub `main`, commit **`bb4d6902c5ae2f321f6c32c03c8af34bdb977ec9`**. The user has verified a clean Windows working tree after pushing the 7.16 whitespace-only follow-up. Install only on this clean baseline, not an older or dirty checkout.

## Why this checkpoint

Checkpoint 7.16 split seven tenant and platform screens into lazy-loaded chunks and successfully passed 170 Laravel tests, ESLint, Vite build, and 10 route-chunk tests on the user's Windows environment. Lazy imports introduce a new availability failure mode: an open browser tab may reference a missing chunk after a deployment, or a module can fail while loading. The existing `Suspense` fallback covers loading **but not a rejected import**, which otherwise bubbles to a generic React error.

This checkpoint adds a **scoped lazy-route error boundary** and an explicit **Reload page** button, rather than auto-reloading indefinitely or changing the platform/tenant routing hierarchy. The 503.63 kB entry-chunk warning remains a separate optimization topic; this checkpoint does not hide or change the warning threshold.

## Exact implementation

- `LazyRouteErrorBoundary` is a React class error boundary with `getDerivedStateFromError`, placed around the existing `Suspense` in `DeferredPage`. It catches rejected lazy imports and other errors in a deferred page subtree.
- The visible error state is a generic message with `role="alert"` and a user-controlled `Reload page` button. It does **not** render the exception, stack trace, raw URL, report, user identity, cookie, request, or other diagnostic evidence; no telemetry or logging is added.
- React can cache a rejected `lazy()` import; only an explicitly clicked full reload attempts recovery with the latest deployed entry. There is **no automatic reload**, retry loop, or hidden mutation.
- The boundary is keyed by `useLocation().pathname`, so switching to another route creates a fresh boundary state. A route's guards stay in the existing `router.tsx`; navigation and reload still run their standard authentication, company, permission, platform identity, and MFA checks.
- No changes to `router.tsx`, the seven lazy import targets, backend routes, React permissions, platform writes, authentication, tenancy middleware, or application data.

## Security and compatibility invariants

1. **Frontend-only availability UX.** The boundary is not an authorization mechanism. Backend checks (Sanctum, `company`, Spatie Teams, `platform.admin`, `platform.mfa`, `platform.write`) remain the security boundaries.
2. The user cannot obtain privileged data or bypass MFA by invoking reload. Reauthentications and access checks remain server-controlled.
3. Tenant Owner is not treated as Platform Admin, no admin identity is provisioned, and `PLATFORM_MUTATIONS_ENABLED=false` stays unchanged.
4. No screenshots, passwords, tokens, cookies, URL strings, exception details, request/response bodies, free-text diagnostic evidence, or business records are stored or transmitted.
5. No migrations, database schema changes, seeding, MariaDB access/writes, ARVYA/E2E-TEST data access, new dependencies, or network integrations.
6. The existing 10 route-chunk tests remain intact and two extra **static** checks validate the new boundary location, safe recovery button, and fallback nesting. These checks are not a substitute for browser smoke testing of a genuinely failed asset.

## ZIP contents (repository-relative)

1. **REPLACE** `frontend/src/app/deferred-pages.tsx`
2. **ADD** `frontend/src/app/lazy-route-error-boundary.tsx`
3. **REPLACE** `frontend/scripts/route-chunks.test.mjs`
4. **ADD** `docs/checkpoint-7.17-lazy-route-failure-recovery.md`

No other file must change. Extract into the project root retaining the `frontend/` and `docs/` directories. There is no `package.json`, lockfile, migration, or configuration change.

## Windows CMD preflight (BEFORE applying the ZIP)

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

Require a clean working tree and HEAD `bb4d6902c5ae2f321f6c32c03c8af34bdb977ec9`.

## Windows CMD testing (AFTER extracting the ZIP)

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

The original 170 PHPUnit tests should remain intact, and the extended Node route-chunk suite contains **12 tests**; observe actual results, do not assume pass. Do not run `php artisan migrate`, `migrate:fresh`, `db:wipe`, `db:seed`, or bootstrap/provisioning commands.

Recommended manual browser smoke checks: navigate between tenant and platform pages normally; verify a lazy-loading screen displays the loading fallback; with a missing/stale chunk the app displays a generic recovery message rather than a blank screen; verify clicking Reload page refreshes; verify lack of tenant permission and expired MFA still block access. Never disable server guards to simulate an error.

## Verification and Git finalization

Package author may check file syntax, Node script syntax, ZIP integrity, and a *simulated* route chunk fixture, but cannot claim real Laravel/ESLint/Vite execution without the user's local project dependencies. No commit or push is performed by the assistant. After all real Windows tests pass, stage only the four named files, run `git diff --cached --check` and review the staged diff before the repository owner commits and pushes.
