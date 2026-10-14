# Checkpoint 7.9 — Diagnostic Support Queue & Triage Insights

**Source of truth:** GitHub `main` at `1081ca72ddbdee3ba8d3e25ca615628687ad5d5a` (Checkpoint 7.8).

## Why this checkpoint

Checkpoint 7.8 provided server-enforced status transitions, eligible platform assignees,
append-only events, MFA, an opt-in platform write lock, and an optimistic concurrency version.
The next missing operational capability is to prioritize and navigate the existing queue
without opening each record or broadening the platform's access to tenant business data.

## Scope

One backwards-compatible enhancement to the existing endpoint:

`GET /api/platform/diagnostics/reports`

New optional query parameters:

| Name | Allowed values | Meaning |
| --- | --- | --- |
| `impact` | `blocked`, `partial`, `minor`, `unknown` | Narrow by allowlisted impact |
| `assignment` | `all`, `mine`, `assigned`, `unassigned` | Show the current platform admin's assignments or one assignment lane |

Existing `company_id`, `category`, `status`, `page`, and `per_page` filters remain compatible.
Unrecognized values return HTTP 422; page size remains capped at 50.
The `mine` query takes the actor's user ID from the **verified server session**, never
from a request parameter. This does not grant tenant membership or data access.

An additive `summary` property now accompanies every paginated queue response:

```json
{
  "summary": {
    "total": 5,
    "by_status": { "new": 1, "triaged": 1, "in_progress": 1, "resolved": 1, "closed": 1 },
    "unassigned": 2,
    "assigned": 3,
    "mine": 1
  }
}
```

**Semantics:** summary is computed from the selected `company_id`, `category`, and `impact`
filters across **all** statuses and assignment lanes, independently of paging. Changing
`status` or `assignment` narrows table rows without changing the workload overview.
The all-zero shape is returned when no matching records exist. The existing `meta.total`
continues to represent **filtered table rows**, not all overview records.

The React platform diagnostics screen adds impact, category, and assignment selection,
workload counts and a reset option, while retaining report inspection, workflow history,
write-lock notice, MFA requirements, assignment controls, and concurrency handling.

## Security and audit invariant

- API route remains inside `auth:sanctum`, `platform.admin`, and `platform.mfa`; browser
  session protections still apply. Explicit tenant headers remain invalid.
- All read requests use the existing `platform.diagnostics.read` audit action.
- Tenant diagnostics API is **unchanged**, still company- and reporter-scoped.
- No business records, URLs, message text, tokens, screenshots, attachments,
  request bodies, IPs, cookies, or free-text evidence are introduced.
- `PLATFORM_MUTATIONS_ENABLED=false` stays the default. All existing workflow writes
  still require `platform.write` and valid optimistic `workflow_version`.
- No new migration, seed, commands, retention policy, data mutation, or provisioning.
- Existing company data, including ARVYA and E2E-TEST, remains untouched.

## Files supplied

1. `app/Http/Controllers/Api/Platform/PlatformDiagnosticController.php` — replace.
2. `frontend/src/features/platform/platform-diagnostics-api.ts` — replace.
3. `frontend/src/features/platform/platform-diagnostics-page.tsx` — replace.
4. `tests/Feature/Api/Diagnostics/DiagnosticSupportQueueTest.php` — add.
5. `docs/checkpoint-7.9-diagnostic-support-queue.md` — add.

Extract the ZIP into the repository root; merge/replace only these exact paths. No
`composer install`, `npm install`, or schema mutation is part of this checkpoint.

## Verification (Windows CMD)

Run after copying files and before committing:

```cmd
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test
cd frontend
npm run lint
npm run build
cd ..
git add -N tests/Feature/Api/Diagnostics/DiagnosticSupportQueueTest.php docs/checkpoint-7.9-diagnostic-support-queue.md
git diff --check
git status -sb
```

`git add -N` marks the two new files as intent-to-add so `git diff --check`
checks their whitespace too; it does **not** stage their contents or make a commit.
Please share the full test, lint, build, diff and status output. Do not commit/push
until they have been reviewed. After passing, the repository owner alone performs
`git add`, `git commit` and `git push origin main`.

**Expected test delta:** 9 new feature test methods. Previous baseline was 101 passing
(476 assertions); the updated aggregate count must be **observed**, not assumed.
No migration commands are required because this checkpoint contains no migrations.
