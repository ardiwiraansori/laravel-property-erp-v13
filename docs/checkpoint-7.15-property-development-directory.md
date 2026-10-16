# Checkpoint 7.15 — Tenant-Safe Property Development Directory

**Audited GitHub source:** `ardiwiraansori/laravel-property-erp-v13`, branch `main`, commit `ce52d13b295af0f9e046050fb1aa529a91ea065f` (Checkpoint 7.14). Verified on 2026-10-16. Apply this ZIP **only** to that commit with a reviewed, clean Windows working tree. This checkpoint is not a new project and does not redo 7.8–7.14.

## Why this priority

The application currently has a functioning Property Developments master-data module, while CRM, Sales, Accounting and Reports remain placeholders. The existing GET for property developments returns **all** rows for the active tenant and the React page cannot search or filter them. Before extending transactional ERP features, strengthen the usable master-data directory with a bounded read option, keeping legacy clients and RBAC intact.

This checkpoint adds **search by code/name**, **active/inactive/all filters**, **stable server pagination**, and React filtering/paging, without changing existing create semantics. It adds **no** update, delete, bulk actions, archive, new role, new permission, platform endpoint, migration, or dependency.

## API contract

Existing route and guards remain unchanged:

`GET /api/master-data/property-developments`

- **Legacy**: no `search`, `status`, `page` or `per_page` parameter means the exact existing unpaginated `data` resource collection, sorted by `name`, then `id`. No `meta` is added to legacy requests.
- **Opt-in directory**: supplying any of those four query parameters returns `data` plus a bounded `meta` object (`current_page`, `last_page`, `per_page`, `total`). Default `page=1`, `per_page=10`, maximum `per_page=50`.
- `search`: optional string, at most 100 characters, trimmed; matches `code` OR `name` case-insensitively. `!`, `%` and `_` are escaped and treated as literal user characters, not SQL pattern operators. Uses bound SQL parameters and an explicit `ESCAPE '!'`, supported by MariaDB and SQLite.
- `status`: `all` (default), `active`, `inactive`. Results and counts honor the search and status filters **inside the server-resolved company**.
- Invalid page, page size, search type/length, or status yields HTTP 422; response order is always `name ASC, id ASC` with no client-controlled SQL identifier/sort expression.
- No user-provided company ID, owner ID, or permission is accepted. `X-Company-ID` continues to require an active membership through the existing `company` middleware; `can:master-data.property-developments.view` is unchanged.
- This opt-in design avoids breaking other integrations that rely on the original no-parameter unpaginated response. Future API versioning can separately deprecate that legacy shape if desired.

Example: `GET /api/master-data/property-developments?page=1&per_page=10&search=Griya&status=active`

```json
{
  "data": [
    {"id": 5, "code": "GRIYA-01", "name": "Griya Residence", "is_active": true, "created_at": "2026-10-16T00:00:00.000000Z"}
  ],
  "meta": {"current_page": 1, "last_page": 1, "per_page": 10, "total": 1}
}
```

## React behavior

- Property Developments page now opts into bounded, paginated GET with server-side search and status filter.
- Search is applied when the user submits, not for every keystroke. Clear resets search, status, and page. Changing status resets to page 1.
- Previous/Next are disabled at the appropriate bounds. The UI shows total results, empty, loading, error/retry, and creation feedback.
- Existing Add Development flow remains permission-gated and uses the same POST, request, server validation, and resource. Successful creation resets filters and reloads the directory.
- Responses from a no-longer-current page/search/tenant are discarded using React effect cleanup. Existing company switcher already forces a full document reload and discards tenant state.
- No router, schema, database, platform diagnostics, tenant diagnostics or platform mutation changes.

## Security invariants

1. The query begins with `where(company_id, CurrentCompanyContext::id())` and **never** accepts a `company_id` query override. Counts and filters are within the current company only.
2. Existing `auth:sanctum`, `company`, active-company membership, and read permission remain mandatory. Tenant `Owner` does not gain platform identity or platform API access.
3. No platform administrator provisioning, MFA bypass, platform write flag change or cross-company read is added. `PLATFORM_MUTATIONS_ENABLED=false` remains the default.
4. Queries use a fixed field allowlist, parameterized search, server-defined sort and bounded page sizes. User input cannot specify raw SQL columns.
5. No new request-body collection, screenshot, cookie, token, password, business diagnostics, logging, export, or read audit side channel.
6. Existing POST creation is not changed. No migration, seeding, MariaDB write, ARVYA/E2E-TEST data access or change is performed by package assembly. Tests create disposable data on SQLite in-memory only.

## Exact ZIP contents (relative to repository root)

1. **REPLACE** `app/Http/Controllers/Api/MasterData/PropertyDevelopmentController.php`
2. **REPLACE** `frontend/src/features/master-data/property-developments/property-development-api.ts`
3. **REPLACE** `frontend/src/features/master-data/property-developments/property-developments-page.tsx`
4. **ADD** `tests/Feature/Api/MasterData/PropertyDevelopmentDirectoryTest.php` (11 test methods)
5. **ADD** `docs/checkpoint-7.15-property-development-directory.md`

No other files or dependencies are required. Extract the archive contents directly into the root, retaining the `app/`, `frontend/`, `tests/`, and `docs/` structure. Do not copy them under an extra `checkpoint-7.15/` subdirectory.

## Windows CMD preflight and verification

Run against the verified baseline with a clean working tree **before** copying the ZIP:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
git status -sb
git rev-parse HEAD
```

After extracting files to the project root:

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test --filter=PropertyDevelopmentDirectoryTest
php artisan test
cd frontend
npm run lint
npm run build
cd ..
git diff --check
git status -sb
```

Do **not** run `php artisan migrate`, `migrate:fresh`, `migrate:refresh`, `db:wipe`, seeders, or platform bootstrap commands. No migration has been introduced and there is no `migrate:status`/`migrate --pretend` requirement for this checkpoint.

After reporting local test results for review, the repository owner can separately stage only the five paths and run `git diff --cached --check` prior to commit. The assistant does **not** commit, push, provision administrator accounts or alter MariaDB. `git diff --check` does not cover new untracked files until they are staged.

## Package validation limitations

Package assembly checked PHP syntax and archive integrity. Laravel PHPUnit, ESLint, TypeScript project build, and Vite build are not claimed to have been executed against the user's local dependency tree; the user must send actual results. The verified previous baseline had **159 passed, 903 assertions** according to the user's checkpoint record; a new expected total is **not** claimed until the user's Windows tests run.
