# Checkpoint 7.8 — Diagnostic Support Workflow

Source baseline: GitHub `main` commit `d9cc095d9ff8bb19aab89e55ccb3235912c0e9f2`.

## Scope and security

This adds a **metadata-only** workflow to previously existing `diagnostic_reports`.
Only an explicitly provisioned active, tenant-free platform administrator with a valid
browser-session MFA proof can read the cross-company queue and activity history.

Mutations also require the **existing** `platform.write` middleware. The global flag
`PLATFORM_MUTATIONS_ENABLED` remains **false** by default. Do not enable it, bootstrap
admin identities, or bypass MFA for this checkpoint. Support assignment is limited to
active, non-revoked platform administrators who have **zero tenant memberships**.
An existing assignment does not grant the assignee any tenant business access.

Tenant reporters continue to see only their own reports under their active company;
only updated `status` is shared with them, not support actions or assignee identity.

## Structured state machine

`new → triaged → in_progress → resolved → closed`

An in-progress ticket can return to `triaged`, a resolved ticket can return to
`in_progress`, and a closed ticket can reopen as `triaged`.
All other transitions, including no-op transitions, are rejected.

Every accepted change increments `workflow_version`. Clients must supply their most
recent version. Stale updates receive HTTP 409 rather than silently overwriting
a change by another administrator.

## Append-only audit

`diagnostic_report_events` contains only an allowlisted action, status and assignee
user IDs before/after, actor user ID, company ID, and time. Each mutation inserts
both a support event and `platform_audit_logs` event atomically in the same DB
transaction. The history and assignee directories are read-only and their access
is audited. No free-form note text or file upload is accepted.

No screenshots, IPs, raw URL, cookies, auth tokens, request/response bodies, customer
names, or other business data are stored.

## Routes

- `GET /api/platform/diagnostics/reports` — paginated queue, updated status and assignee ID.
- `GET /api/platform/diagnostics/reports/{publicId}` — report summary and paginated history.
- `GET /api/platform/diagnostics/assignees` — active eligible platform identities.
- `PATCH /api/platform/diagnostics/reports/{publicId}/status` — guarded state transition.
- `PATCH /api/platform/diagnostics/reports/{publicId}/assignment` — guarded assignment/unassignment.

## Migrations, rollout and retention

Two additive migrations: `2026_10_13_000006` adds nullable assignee and version
columns to `diagnostic_reports`; `2026_10_13_000007` creates
`diagnostic_report_events`. Both are designed to preserve existing records.
Review `php artisan migrate --pretend` and migration status on the development
MariaDB database before granting explicit approval to execute. Never run `fresh`,
`rollback` or schema drops automatically.

The workflow **does not implement automatic deletion, anonymization, or purging**.
No retention interval has been approved; retention, archival, export, and legal
holds need a separate explicit policy/approval before any data is removed.

There is no user impersonation and no access to tenant business tables.
