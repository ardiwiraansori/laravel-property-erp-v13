# Checkpoint 7.10 — Support Queue Aging & Prioritization

**Verified source of truth:** `ardiwiraansori/laravel-property-erp-v13`, GitHub `main` at `1d2adc49c166469fa350071bcacc268313128d86` (Checkpoint 7.9).

## Rationale

Checkpoint 7.9 established the metadata-only support queue with company/category/impact/status/assignment filtering and unfiltered workload overview. It did not show **how long unresolved reports have been waiting**, or let operators list the oldest open reports first. This checkpoint adds a read-only age lens to support manual triage, without creating a policy-driven SLA or privileged tenant data access.

## API contract — additive, backwards compatible

`GET /api/platform/diagnostics/reports` retains the existing authentication (`auth:sanctum`), platform identity, browser session and MFA protections. No route or database schema changes.

New optional query parameters:

| Parameter | Values | Semantics |
|---|---|---|
| `age` | `under_24h`, `day_1_3`, `day_3_7`, `over_7d` | Filter **table rows** to open statuses (`new`, `triaged`, `in_progress`) in the selected age range |
| `sort` | `newest` (default), `oldest` | Sort rows by `created_at` and then `id` for deterministic pagination |

Age means *time elapsed since the report's `created_at`*, not time since assignment or status change. Boundaries are evaluated using the server's current time for each request:

- `under_24h`: `< 24 hours` old (a future-dated record is also treated as newest, never exposed as a new data category).
- `day_1_3`: `>= 24 hours` and `< 72 hours`.
- `day_3_7`: `>= 72 hours` and `< 168 hours`.
- `over_7d`: `>= 168 hours`.

**Open status** means `new`, `triaged`, or `in_progress`. The two terminal/near-terminal states `resolved` and `closed` are excluded from the open age buckets and every age-filtered table lane, even when a status filter also requests one of them.

Every successful API response now includes an additional `open_aging` property:

```json
{
  "open_aging": {
    "total": 15,
    "under_24h": 2,
    "day_1_3": 3,
    "day_3_7": 4,
    "over_7d": 6
  }
}
```

The summary is independently derived from the same **company, category, and impact** base scope used by Checkpoint 7.9. It intentionally ignores `status`, `assignment`, `age`, `sort`, `page`, and `per_page` so an operator can compare lanes against the same global open workload. `summary` from Checkpoint 7.9 is **unchanged**. `meta.total` still counts the rows matching *all* selected table filters. Empty scopes return all-zero aging counts.

New values are strictly validated and invalid values return HTTP 422. No arbitrary SQL column names, free-text queries, user IDs, or untrusted expressions are accepted as sorting/filtering instructions.

## Frontend

The existing platform diagnostic queue page gains:

- An **Open report age** filter; its selection limits table rows to open reports matching that age range.
- A **Sort by reported time** selector (`Newest first`, `Oldest first`).
- A read-only **Open report aging** breakdown, clearly marked as *not an SLA*.
- A `Reported at` table column to explain age and sort order.

No support write button, workflow transition, assignment validation, MFA proof, or optimistic locking path is altered. Existing filter reset, result loading/error states, navigation and audit continue to operate.

## Security requirements retained

- Cross-company read access exists **only** behind `platform.admin`, `platform.mfa`, and the existing browser session protections; a tenant user and an unverified admin still cannot access any cross-company summary.
- Platform diagnostic reads remain recorded through the existing `platform.diagnostics.read` audit action.
- A request with `X-Company-ID` remains rejected on platform routes.
- The read-only API cannot modify reports, events, tenant membership or tenant business records.
- No new credentials, tokens, cookies, screenshots, raw URLs, IPs, user-agent strings, free-form notes, business records or customer details are collected.
- `PLATFORM_MUTATIONS_ENABLED=false` remains the default and is not modified, and no provisioning is introduced.
- No migration, database update, seed, retention/deletion job, environment change or destructive operation is part of the checkpoint. Existing data (including ARVYA and E2E-TEST) is untouched.

## Files to paste into the local repository

These **five** paths are the entire Checkpoint 7.10 patch (replace existing for the first three, add last two):

1. `app/Http/Controllers/Api/Platform/PlatformDiagnosticController.php` — replace.
2. `frontend/src/features/platform/platform-diagnostics-api.ts` — replace.
3. `frontend/src/features/platform/platform-diagnostics-page.tsx` — replace.
4. `tests/Feature/Api/Diagnostics/DiagnosticSupportAgingTest.php` — add.
5. `docs/checkpoint-7.10-support-queue-aging-and-prioritization.md` — add.

Extract the ZIP in **Downloads**. Then copy its `app`, `frontend`, `tests` and `docs` folders to your existing local repository root, merging folder structures and replacing the three matching files. Do not extract the ZIP directly into the repository if that is not your usual workflow.

## Windows CMD verification after copying

```bat
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
php artisan test
cd frontend
npm run lint
npm run build
cd ..
git diff --check
git status -sb
```

Do not use `git add -N` for this extraction/copy workflow. Note that `git diff --check` checks tracked changes only until newly created files are staged. The new PHP test file is exercised by `php artisan test` even when it is untracked. Verify the complete output before deciding whether to commit. The repository owner alone performs staging, commit and push after the tests pass.

**Expected test delta:** 9 additional feature test methods relative to the local Checkpoint 7.9 baseline of **110 passing / 555 assertions**; the actual totals must be observed from your machine. No migration commands are needed because there is no migration.

## Explicit non-goals

- No configured SLA thresholds, alert notifications, due dates, escalation policies, retention jobs, exports, or automated assignments.
- No platform write unlock or Super Admin provisioning.
- No change to tenant-facing diagnostic report intake or visibility.
