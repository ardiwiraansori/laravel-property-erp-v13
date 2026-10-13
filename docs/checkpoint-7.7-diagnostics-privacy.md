# Checkpoint 7.7 — Real User Diagnostics (privacy contract)

Baseline: GitHub `main` commit `ba4573c81a5c158bb6db19d1face4c03897db7f4`.

## What is collected

A tenant user explicitly presses **Laporkan Masalah**, selects a predefined issue category,
module, impact level, and error kind. The client maps the browser to a coarse family and the
viewport to mobile/tablet/desktop. The server generates a random ULID and timestamps the
report. Membership middleware supplies the `company_id` and authentication supplies the
`reporter_user_id` independently of user-submitted fields.

No automatic background error or network interception is enabled.

## What is deliberately not collected

No free-form descriptions, screenshots, image attachments, customer information,
accounting data, form contents, request/response bodies, raw paths or query parameters,
full referrer, raw User-Agent, exact device dimensions, IP address, session IDs,
cookies, credentials, tokens, stack traces, browser storage, or impersonation.
Extra client-submitted fields are not saved; the database is an explicit allowlist.

## Authorization

`GET /api/diagnostics/reports`: only the authenticated user's own reports within their
current active, member-authorized tenant. Tenant Owner access does not override this.
`POST /api/diagnostics/reports`: same tenant check plus anti-spam rate limiting.
`GET /api/platform/diagnostics/reports`: platform-only identity AND valid 10-minute MFA.
Only a fixed list of non-business report metadata is returned; reads are audited.
There is no platform write, status mutation, deletion, or impersonation API in 7.7.

## Operations & retention

The migration only creates `diagnostic_reports`; it must not be applied to the development
MariaDB database without explicit review and approval. It preserves records on rollback.
Support status updates, retention schedules, expiry/anonymization, audit review, and escalation
belong to Checkpoint 7.8. This version does not claim automatic deletion or anonymization.
Do not enable `PLATFORM_MUTATIONS_ENABLED` or provision platform accounts to test this feature.
