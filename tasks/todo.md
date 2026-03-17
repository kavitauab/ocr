# Iteration 1 - OCR Admin & Billing Foundation

## Plan
- [x] Align workflow rules for this project and capture them in `tasks/lessons.md`.
- [x] Backend: add OCR lifecycle/tokens/cost tracking in schema and API.
- [x] Backend: enrich super-admin stats API with client billing/usage status fields.
- [x] Frontend: redesign dashboard for super-admin client management with search.
- [x] Frontend: add searchable document lifecycle fields in invoice list.
- [x] Verify by running build and checking core behavior paths.

## Verification Checklist
- [x] `npm run build` passes.
- [x] No TypeScript compile errors.
- [x] Dashboard can show super-admin global client table with billing/token/cost data.
- [x] Invoices table shows and filters lifecycle timestamps (sent/returned).

## Review
- Implemented Iteration 1 foundation for super-admin operations and lifecycle visibility.
- Backend now aggregates per-company billing/plan + token/cost usage + OCR sent/returned timestamps in `/invoices/stats`.
- Invoice listing now supports server-side lifecycle/date filters (`lifecycle`, `sentFrom`, `sentTo`, `returnedFrom`, `returnedTo`) and exposes sent/returned columns in UI.
- OCR extraction flow records document type and lifecycle usage in both upload and email ingestion paths, with backward-compatible fallbacks for partially migrated DBs.
- Verification run:
  - `php -l api/resources/Invoice.php`
  - `php -l api/lib/email_processor.php`
  - `php -l api/lib/usage.php`
  - `php -l api/lib/claude.php`
  - `npm run build`

---

# Iteration 2 - Billing Management Module

## Plan
- [x] Add backend `subscriptions` management API for super admin (list + upsert by company).
- [x] Support editable billing fields: plan, status, invoice limit, storage limit, included tokens, overage rates.
- [x] Add frontend Billing settings page with search and row editing dialog.
- [x] Wire new Billing page into settings navigation/routes.
- [x] Verify via PHP syntax checks + frontend build.

## Verification Checklist
- [x] `php -l` passes for new/updated backend files.
- [x] `npm run build` passes.
- [x] Super-admin can view all clients with billing fields on one screen.
- [x] Super-admin can edit and save billing values per company.

## Review
- Added dedicated `subscriptions` API resource for super-admin billing management:
  - `GET /api/subscriptions`
  - `GET /api/subscriptions/{companyId}`
  - `PATCH /api/subscriptions/{companyId}` (upsert by company)
- Added `Billing` settings page with search, table view, and edit dialog for plan/status/limits/overage fields.
- Wired billing route and sidebar entry (`/settings/billing`) for superadmins.
- Added schema columns for advanced overage configuration in `subscriptions`.
- Compatibility: backend safely tolerates missing newer subscription columns and falls back without hard failures.
- Verification run:
  - `php -l api/resources/Subscriptions.php`
  - `php -l api/resources/Invoice.php`
  - `npm run build`

---

# Iteration 3 - Lifecycle Timeline + Export

## Plan
- [ ] Add backend invoice export endpoint supporting existing filters (status/company/search/lifecycle/date ranges).
- [ ] Ensure export includes lifecycle fields (OCR sent, OCR returned, processing duration where available).
- [ ] Add frontend export action in invoice list using active filters.
- [ ] Improve invoice detail with explicit lifecycle timeline block for operations visibility.
- [ ] Verify via PHP syntax checks + frontend build.

## Verification Checklist
- [ ] `php -l` passes for changed backend files.
- [ ] `npm run build` passes.
- [ ] Invoices page can export filtered data.
- [ ] Invoice detail shows lifecycle milestones clearly.

## Review
- Pending
