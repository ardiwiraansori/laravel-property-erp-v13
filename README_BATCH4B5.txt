Checkpoint 7.5 — React Company Switcher (Batch 4B.5)

This patch is based on checkpoint75_frontend_review.zip supplied by the user.
Backup the existing frontend source before replacing it. Extract this ZIP at the repository root.
Only files within frontend/src are included; existing files outside the list remain untouched.

Changes:
- CompanyProvider loads /api/companies and validates /api/company-context with X-Company-ID.
- Only validated active company permissions are used for navigation and route gates.
- Axios adds tenant header for business endpoints; auth/discovery endpoints are exempt.
- Switching companies reloads the dashboard to discard stale tenant UI state/in-flight work.
- The selected ID is saved only in sessionStorage, not roles or permission lists.
- Accounts without an active company see an explicit empty state.

NOTES
- Does not create, migrate, or seed business data.
- Existing backend access checks remain authoritative; frontend permissions control display only.
- This package has NOT been successfully npm lint/build tested here due to npm dependency installation timing out; run them locally.

CMD (Windows):
cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13\frontend
npm run lint
npm run build

Do not commit/push before reviewing local test results.
