# Reliability & Operations — Implementation Plan

## Overview
Add 4 operational features: retry queue, health dashboard, rate limiting, and background job processing. These are tightly coupled — the job queue is the foundation for retries and background processing, rate limiting gates the queue, and the health dashboard visualizes it all.

## Architecture Decision

**Key insight**: PHP doesn't have persistent background workers like Node.js. The standard PHP approach is **cron-based job processing** — a cron endpoint picks up queued jobs and processes them, called every N seconds by an external scheduler (same pattern as the existing `cron/fetch-emails`).

The existing `ocr_jobs` table already tracks jobs. We'll extend it to support queuing and retries.

---

## Phase 1: Database Schema Extensions (migrate_schema.php)

**Add to `ocr_jobs` table:**
- `attempt` INT NOT NULL DEFAULT 1
- `max_attempts` INT NOT NULL DEFAULT 3
- `next_retry_at` DATETIME NULL
- `queued_at` DATETIME NULL

**Update `ocr_jobs` status ENUM**: Add `queued` and `retrying` to the existing `processing/completed/failed` enum.

**Add to `subscriptions` table (rate limiting):**
- `rate_limit_per_hour` INT NULL (invoices per hour per company, NULL = unlimited)
- `rate_limit_per_day` INT NULL (invoices per day per company, NULL = unlimited)

**New index**: `idx_ocr_jobs_queue` on `(status, next_retry_at)` for efficient queue polling.

---

## Phase 2: Background Job Processing + Retry Queue (Backend)

### 2a. Refactor Invoice::create() — Make OCR Async

Currently `create()` does: upload file → insert invoice → call Claude → update result — all synchronous.

**Change to:**
1. Upload file → insert invoice with `status='queued'`
2. Create `ocr_jobs` row with `status='queued'`, `queued_at=NOW()`
3. Return invoice immediately (frontend sees "queued" status)
4. OCR happens in background via cron worker

**New file: `api/functions/process_ocr_queue.php`**
- Cron endpoint authenticated with CRON_SECRET
- Picks up jobs: `SELECT * FROM ocr_jobs WHERE status IN ('queued','retrying') AND (next_retry_at IS NULL OR next_retry_at <= NOW()) ORDER BY queued_at ASC LIMIT 5`
- For each job:
  - Set status='processing', update invoice status='processing'
  - Load file, call Claude extractInvoiceData()
  - On success: update invoice with data, set job status='completed'
  - On failure: increment attempt, if attempt < max_attempts → set status='retrying', calculate `next_retry_at` with exponential backoff (30s, 2min, 8min), else set status='failed'
- Returns JSON summary of processed jobs

**Backoff formula**: `next_retry_at = NOW() + (30 * 4^(attempt-1))` seconds
- Attempt 1 fail → retry in 30s
- Attempt 2 fail → retry in 2min
- Attempt 3 fail → permanent failure

### 2b. Manual Retry from UI

**New endpoint: `POST /api/invoices/{id}/retry`**
- Resets failed invoice to queued
- Creates new ocr_job with attempt=1
- Available for failed invoices only

### 2c. Register Cron Route

Add to `index.php` cron routing:
```
if (($pathParts[1] ?? '') === 'process-ocr') {
    require_once __DIR__ . '/functions/process_ocr_queue.php';
    exit;
}
```

External cron calls: `curl -H "Authorization: Bearer $CRON_SECRET" https://ocr.gentrula.lt/api/cron/process-ocr` every 15 seconds.

---

## Phase 3: Rate Limiting (Backend)

**New file: `api/lib/rate_limit.php`**

Functions:
- `checkRateLimit($companyId)` — returns `['allowed' => bool, 'reason' => string, 'limits' => [...]]`
  - Queries subscriptions for company limits
  - Counts invoices uploaded in last hour/day from `invoices` table
  - Returns 429 with retry-after info if exceeded

**Integration points:**
- Called in `Invoice::create()` BEFORE file upload
- Called in `process_ocr_queue.php` before processing each job (skip if rate limited, leave in queue)
- Returns remaining quota in response headers: `X-RateLimit-Remaining-Hour`, `X-RateLimit-Remaining-Day`

---

## Phase 4: Health Dashboard (Backend API)

**New endpoint: `GET /api/invoices/health`** (superadmin only)

Returns:
```json
{
  "overview": {
    "totalJobs": 1234,
    "completedJobs": 1100,
    "failedJobs": 50,
    "queuedJobs": 10,
    "processingJobs": 2,
    "retryingJobs": 5,
    "successRate": 95.6,
    "avgProcessingSeconds": 12.3
  },
  "queue": {
    "depth": 15,
    "oldestQueuedAt": "2026-03-18T10:00:00",
    "retrying": 5
  },
  "daily": [
    { "date": "2026-03-18", "completed": 45, "failed": 2, "avgSeconds": 11.5, "totalCostUsd": 0.23 },
    { "date": "2026-03-17", "completed": 52, "failed": 1, "avgSeconds": 13.1, "totalCostUsd": 0.27 },
    ...last 30 days
  ],
  "topErrors": [
    { "message": "Rate limit exceeded", "count": 12, "lastSeen": "2026-03-18T09:30:00" },
    ...top 10 error messages
  ],
  "rateLimits": [
    { "companyId": "abc", "companyName": "Acme", "hourlyUsed": 5, "hourlyLimit": 10, "dailyUsed": 45, "dailyLimit": 100 }
  ]
}
```

---

## Phase 5: Health Dashboard (Frontend)

**New file: `src/pages/Health.tsx`**
- Superadmin-only page
- **Overview cards**: Success rate, Avg processing time, Queue depth, Failed (24h)
- **Queue status**: Live count of queued/processing/retrying jobs
- **Daily trend chart**: Simple bar/line chart using CSS (no chart library) showing completed vs failed per day for last 30 days
- **Top errors table**: Most common error messages with count
- **Rate limit status**: Per-company usage vs limits table

**Route**: `/settings/health` — added to App.tsx and sidebar nav

---

## Phase 6: Frontend Updates

### 6a. Invoice status handling
- Add "queued" status to `getStatusClasses()` in `ui-utils.ts` (blue/indigo styling)
- Update Invoices.tsx status filter to include "queued"
- InvoiceDetail: show "Queued" state with estimated wait, show Retry button for failed invoices

### 6b. Upload.tsx feedback
- Upload now returns immediately with queued status
- Show "Queued for processing" instead of waiting for OCR
- Much faster upload UX

### 6c. Rate limit feedback
- If upload returns 429, show toast: "Rate limit reached. Try again in X minutes."

---

## File Changes Summary

| File | Action | Description |
|------|--------|-------------|
| `api/functions/migrate_schema.php` | Edit | Add queue columns to ocr_jobs, rate limit columns to subscriptions |
| `api/functions/process_ocr_queue.php` | **New** | Cron worker to process OCR queue |
| `api/lib/rate_limit.php` | **New** | Rate limiting logic |
| `api/resources/Invoice.php` | Edit | Make create() async, add retry() and health() endpoints |
| `api/index.php` | Edit | Add cron route for process-ocr |
| `src/pages/Health.tsx` | **New** | Health dashboard page |
| `src/App.tsx` | Edit | Add health route |
| `src/components/Layout.tsx` | Edit | Add Health nav item |
| `src/pages/InvoiceDetail.tsx` | Edit | Add retry button for failed invoices |
| `src/pages/Upload.tsx` | Edit | Handle queued status response |
| `src/pages/Invoices.tsx` | Edit | Add queued status filter |
| `src/lib/ui-utils.ts` | Edit | Add queued status classes |
| `src/pages/BillingEdit.tsx` | Edit | Add rate limit fields |

---

## Build & Deploy
- `npm run build` + `bash deploy.sh`
- Set up cron job on server: call `/api/cron/process-ocr` every 15 seconds
- Test: upload invoice → verify it queues → verify cron processes it → verify retry on failure
