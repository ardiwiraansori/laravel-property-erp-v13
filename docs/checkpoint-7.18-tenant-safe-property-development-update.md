# Checkpoint 7.18 — Tenant-Safe Property Development Update

**Verified source baseline:** GitHub `ardiwiraansori/laravel-property-erp-v13`, branch `main`, HEAD `f05be568f97604c3d07ca57871c920632ccc3d70`, the user-tested Checkpoint 7.17. Working-tree cleanliness was reported by the user after pushing 7.17; verify it locally again before applying these complete replacement files.

## Reason and scope

Property Developments already supports company-scoped create, read, search, status filter and pagination (7.15); the directory does not yet support updating a development's display name or active flag. This adds precisely **one** tenant-only `PATCH` operation and an Edit form on the existing directory. It does not create, change or delete a company, development code, relationship, inventory, sale, financial record or diagnostic report.

**No migration, seeders, platform API change, new dependency or SQL query against the user's MariaDB is performed during ZIP assembly.** No database changes should be executed during installation. Laravel tests use their existing SQLite in-memory setup.

## Security design and permission provisioning

The route remains below existing `auth:sanctum` and membership-resolved active `company` middleware and requires a separate, fine-grained **`master-data.property-developments.update`** permission. This is **not** inferred from Owner, `master-data.property-developments.view`, `master-data.property-developments.create`, or platform identity.

**Intentional default-deny rollout:** The new string is defined as `App\Support\MasterData\PropertyDevelopmentAccess::UPDATE`, rather than added to `PermissionName::cases()`, because `AuthorizationSeeder` currently grants **every** case in that enum to Owner. `AuthorizationSeeder` and MariaDB permissions/grants are deliberately unchanged. Therefore the live Edit button will remain hidden and the PATCH will be HTTP 403 unless and until the owner explicitly approves creating/granting this tenant permission through a separate reviewed RBAC change. **Do not run `db:seed`, `AuthorizationSeeder`, a provisioning command or a direct MariaDB INSERT/UPDATE as part of this checkpoint.** Feature tests create the permission and grants only in disposable SQLite testing transactions.

Platform admin eligibility/MFA/writes, tenant ownership and diagnostics reporter isolation are unchanged; `PLATFORM_MUTATIONS_ENABLED=false` remains the default.

## API contract

`PATCH /api/master-data/property-developments/{development}` (positive numeric ID)

Request JSON (all fields required):

```json
{
  "name": "Garden Residence",
  "is_active": false,
  "expected_name": "Old Garden Name",
  "expected_is_active": true
}
```

- `name`: trimmed, non-empty string, maximum 150 chars.
- `is_active` and `expected_is_active`: Laravel boolean input validation.
- `expected_name`: previous name exactly as returned in GET; string max 150 chars.
- The API looks up a record by **both** route ID and the server's `CurrentCompanyContext::id()`; a foreign-company or absent ID returns 404. Tenant ID in payload/route/query cannot switch the record's company.
- Locked transaction checks expected name and status against the current record; if a concurrent edit changed either field, return **409** and leave the row unchanged. This uses two-field snapshot comparison instead of depending on low-precision MariaDB `updated_at`. Same original state after an A→B→A sequence cannot be detected; that limitation does not create a tenant authorization bypass.
- Only `name` and `is_active` are changed. Any supplied `code`, `company_id`, `id`, `created_at`, or other fields are ignored, not applied.
- A true no-op returns HTTP 200 without modifying the row/timestamps. React displays a safe error message for 409 and refreshes the current directory.
- Successful HTTP 200 response is the unchanged existing `PropertyDevelopmentResource` shape under `data`.
- Unauthenticated 401; missing/invalid company header 422; inactive company or non-member 403; absent update permission 403; invalid fields 422.

**Important:** This checkpoint does not add a new durable, transactional tenant business-audit table. It does not weaken or modify the *existing* platform and diagnostic audit mechanisms. Introducing general-purpose business audit/undo tracking is a distinct architectural checkpoint that requires a reviewed schema/policy before any migration.

## React behavior

- Existing company-scoped directory remains the main page. The **Edit** action appears only for a user whose active company-context response includes `master-data.property-developments.update`.
- Edit panel shows immutable code, prefilled name and active/inactive selector. Saving carries the original name and status for conflict detection, not caller-selected ownership.
- During save, inputs are disabled and the form cannot submit again. Success refreshes the directory with existing filters; a stale 409 refreshes the listing and requires reopening the form. Errors are generic, without exposing other tenant data.
- Creation, filtering, sorting, paging and legacy unpaginated GET API remain compatible. There is no delete, bulk edit, code change, automatic deactivation, or approval bypass.
- Existing company switcher reloads the page; the API continues to validate tenant membership on every PATCH, independent of client UI.

## Exact ZIP files (relative to repository root)

1. **ADD** `app/Support/MasterData/PropertyDevelopmentAccess.php`
2. **ADD** `app/Http/Requests/MasterData/UpdatePropertyDevelopmentRequest.php`
3. **REPLACE** `app/Http/Controllers/Api/MasterData/PropertyDevelopmentController.php`
4. **REPLACE** `routes/api.php`
5. **REPLACE** `frontend/src/features/auth/permissions.ts`
6. **REPLACE** `frontend/src/features/master-data/property-developments/property-development-api.ts`
7. **REPLACE** `frontend/src/features/master-data/property-developments/property-developments-page.tsx`
8. **ADD** `tests/Feature/Api/MasterData/PropertyDevelopmentUpdateTest.php` — 12 test methods
9. **ADD** `docs/checkpoint-7.18-tenant-safe-property-development-update.md`

No migration/permission seeder is included. This is a **code-only** checkpoint; default-deny permission provisioning is explicitly out of scope. Install onto a verified clean checkout of `f05be568` only.

## Windows 10 CMD verification

Before copying the package:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

After extracting at repository root:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test --filter=PropertyDevelopmentUpdateTest
php artisan test
cd frontend
npm run lint
npm run build
npm run test:route-chunks
cd ..
git diff --check
git status -sb
```

Baseline at 7.17: **170 passed / 969 assertions** for Laravel and 12 route chunk tests, as reported by the user. This package adds 12 PHP test methods, but the actual full totals and all lint/build results must be reported by the user's Windows test run, not assumed.

**Do not run:** `php artisan migrate`, `migrate:fresh`, `db:wipe`, any MariaDB data-change commands, `db:seed`, owner/administration bootstrap, or any action touching ARVYA/E2E-TEST. Do not grant edit permission until explicitly approved and separately reviewed.

If all tests pass, first stage only these nine files and run `git diff --cached --check` to include new files in whitespace validation. The owner performs the commit/push; the assistant does not. Do not push until the user has reviewed testing.

## Packaging limitations

Validation in the packaging environment covers PHP syntax, TypeScript/TSX source parsing, exact ZIP paths/integrity, and base-file consistency. Laravel dependencies, MariaDB, and the user's npm dependency tree are not installed here. No PHPUnit/ESLint/full Vite build/browser test is claimed without a Windows log.
