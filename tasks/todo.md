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
- [ ] Add backend `subscriptions` management API for super admin (list + upsert by company).
- [ ] Support editable billing fields: plan, status, invoice limit, storage limit, included tokens, overage rates.
- [ ] Add frontend Billing settings page with search and row editing dialog.
- [ ] Wire new Billing page into settings navigation/routes.
- [ ] Verify via PHP syntax checks + frontend build.

## Verification Checklist
- [ ] `php -l` passes for new/updated backend files.
- [ ] `npm run build` passes.
- [ ] Super-admin can view all clients with billing fields on one screen.
- [ ] Super-admin can edit and save billing values per company.

## Review
- Pending
