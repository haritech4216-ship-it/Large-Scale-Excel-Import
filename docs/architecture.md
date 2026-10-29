# Architecture Notes: Backend Excel Import Engine (Phase 3)

## 1. System Overview

The Large-Scale Excel Import solution is split into two decoupled layers:
* **Backend:** A Laravel 13 REST API responsible for file upload ingestion, asynchronous queued processing (chunked streaming), row-level validation, duplicate detection, and database bulk insertion.
* **Frontend:** An Angular 22 Single Page Application (SPA) offering an interactive progress indicator, import history dashboard, and CSV error report downloads.

---

## 2. Infrastructure & Runtime Configuration

| Component | Target Version | Active Release | Runtime Notes |
| :--- | :--- | :--- | :--- |
| **PHP** | 8.5.x | `8.5.12` (CLI) | Targeted explicitly via `/usr/bin/php8.5` |
| **Composer** | Latest 2.x | `2.10.3` | Global binary tested against PHP 8.5 |
| **Laravel** | 13.x | `13.39.0` (Framework) | Initialized in `backend/` |
| **Node.js** | 24 LTS | `24.22.2` (Krypton) | Managed via user-local NVM |
| **npm** | Matching Node 24 | `11.19.1` | User-local npm |
| **Angular** | 22.x | `22.2.3` (CLI `22.2.5`) | Initialized in `frontend/` (untouched in Phase 3) |
| **Database** | MySQL 8.4 LTS | `8.4.13` Community | Isolated user-space daemon on port `3307` |
| **Queue** | Database driver | `jobs` / `job_batches` | Database-backed queue tables configured |
| **Excel Parser** | OpenSpout | `5.13.0` | Zero-transitive-dependency streaming XLSX reader |

---

## 3. Database Schema & Models

### A. `imports`
Tracks upload lifecycle, status, counts, and duration:
* `id`: Primary key
* `filename`: Original upload filename
* `file_path`: Private storage path in `storage/app/private/imports/`
* `status`: `Queued`, `Processing`, `Completed`, `Completed with Errors`, `Failed`
* `total_rows`, `processed_rows`, `saved_rows`, `rejected_rows`: Unsigned integers
* `started_at`, `completed_at`: Timestamps
* `duration_seconds`: Total processing time
* `failure_reason`: Scrubbed exception summary for fatal unrecoverable crashes

### B. `import_chunks`
Supports idempotent chunking, recovery, and atomic worker claims:
* `id`: Primary key
* `import_id`: Foreign key referencing `imports(id)` with `CASCADE`
* `chunk_number`: Sequential chunk index (1, 2, 3...)
* `start_row`, `end_row`: Excel row boundaries for the chunk
* `status`: `pending`, `processing`, `completed`, `failed`
* `total_rows`, `processed_rows`, `saved_rows`, `rejected_rows`: Chunk-level counters
* `attempts`: Incrementing integer for retry safety
* `error_message`: Sanitized error if chunk crashed
* `claimed_at`, `completed_at`: Timestamps
* **Unique Constraint:** `(import_id, chunk_number)` prevents duplicate chunk creation

### C. `import_row_errors`
Maintains row-level rejection details for audit and CSV export:
* `id`: Primary key
* `import_id`: Foreign key referencing `imports(id)` with `CASCADE`
* `excel_row`: Physical row number from spreadsheet (Row 1 = headers, Row 2+ = data)
* `customer_id`: Candidate identifier if available
* `rejection_reason`: Explicit validation failure description
* **Indexes:** `(import_id, excel_row)` and `(import_id, id)` for efficient paginated access

### D. `customers`
Stores imported customer entities:
* `id`: Auto-incrementing primary key
* `customer_id`: `VARCHAR(100)` **UNIQUE constraint**
* `first_name`, `last_name`: `VARCHAR(150)`
* `email`: `VARCHAR(255)`, indexed
* `phone_number`: `VARCHAR(60)`
* `date_of_birth`: `DATE`
* `gender`: `VARCHAR(50)`, nullable
* `profession`: `VARCHAR(150)`
* `company_name`: `VARCHAR(150)`, nullable
* `country`, `city`: `VARCHAR(100)`
* `address`: `TEXT`, nullable
* `social_media_url`: `VARCHAR(500)`, nullable
* `registration_date`: `DATE`
* `status`: `VARCHAR(20)` (`Active` or `Inactive`)
* `import_id`: Foreign key referencing `imports(id)` with `SET NULL`

---

## 4. Excel Processing & Memory Architecture

### Streaming Ingestion via OpenSpout
Rather than loading an entire 500,000-row workbook into memory (which causes PHP memory exhaustion with traditional DOM readers), OpenSpout streams records directly from the zipped XML sheet row-by-row. Memory usage remains constant (~15–25 MB) regardless of total workbook rows.

### Configurable Chunking
* Default chunk size: `1000` rows (configured via `EXCEL_IMPORT_CHUNK_SIZE` in `.env`).
* Rows are buffered up to `chunk_size` and processed within an individual database transaction.

### Deterministic Duplicate Detection
1. **In-File Duplicates:**
   An in-memory hash set tracks `$seenCustomerIds[$id] = $firstRow`. The first occurrence of a valid Customer ID is accepted for database insertion; all subsequent occurrences in the file are rejected with reason:
   `"Duplicate Customer ID '{id}' within file (first seen at row {firstRow})."`
2. **Database Duplicates:**
   Before inserting valid candidates, existing IDs are queried:
   `Customer::whereIn('customer_id', $candidateIds)->pluck('customer_id')`.
   Matches are recorded as row errors: `"Customer ID '{id}' already exists in database."`
3. **Database Race Protection & Sub-Batch Isolation:**
   The `customers.customer_id` unique constraint guarantees zero database-level duplicates even under concurrent worker executions. To adhere to SQL parameter boundaries, candidate rows are grouped into 500-row sub-batches. If a concurrent worker inserts a customer ID that collides with a record in a later sub-batch (MySQL error 1062), only that specific conflicting sub-batch falls back to row-by-row insertion. Earlier successful sub-batches remain safely persisted in the transaction without re-execution or false rejections. Every recorded conflict preserves its exact 1-based physical Excel row number (never row 0).

### Idempotency & Retry Safety
* Chunks are tracked in `import_chunks` with a unique index on `(import_id, chunk_number)`.
* If a worker is retried after a partial failure, already completed chunks (`status === 'completed'`) are recognized and bypassed immediately, preventing duplicate customer inserts or duplicate progress counter increments.
* Uncommitted chunks rollback completely upon transaction failure, leaving the database state pristine for the next retry attempt.
* **Queue Lifecycle Handling:** Non-terminal retryable exceptions caught during worker attempts leave the import in `Processing` so status does not flutter prematurely to `Failed`. Terminal transitions to `Failed` occur only after all `$tries` (3) are exhausted via the `failed()` lifecycle hook or upon non-retryable fatal faults.

---

## 5. API Endpoints

### 1. Upload Spreadsheet
* **Endpoint:** `POST /api/imports`
* **Content-Type:** `multipart/form-data`
* **Parameters:** `file` (`.xlsx`, max 100 MB)
* **Response (201 Created):**
  ```json
  {
    "id": 1,
    "filename": "customers_sample.xlsx",
    "status": "Queued",
    "message": "File uploaded and import queued successfully."
  }
  ```

### 2. Import Status & Progress
* **Endpoint:** `GET /api/imports/{id}`
* **Response (200 OK):**
  ```json
  {
    "id": 1,
    "filename": "customers_sample.xlsx",
    "status": "Completed with Errors",
    "total_rows": 1000,
    "processed_rows": 1000,
    "saved_rows": 950,
    "rejected_rows": 50,
    "progress_percentage": 100.0,
    "started_at": "2026-10-29T12:00:00.000000Z",
    "completed_at": "2026-10-29T12:00:15.000000Z",
    "duration_seconds": 15.2,
    "failure_reason": null,
    "created_at": "2026-10-29T11:59:58.000000Z",
    "updated_at": "2026-10-29T12:00:15.000000Z"
  }
  ```

### 3. Import History
* **Endpoint:** `GET /api/imports?per_page=15`
* **Response (200 OK):** Paginated collection of import records ordered by `created_at desc`.

### 4. Paginated Row Errors
* **Endpoint:** `GET /api/imports/{id}/errors?per_page=25`
* **Response (200 OK):** Paginated row errors containing `excel_row`, `customer_id`, and `rejection_reason`.

### 5. Download Errors CSV
* **Endpoint:** `GET /api/imports/{id}/errors.csv`
* **Response (200 OK):** Streamed CSV download (`Content-Type: text/csv; charset=UTF-8`) with UTF-8 BOM and spreadsheet formula injection protection.

---

## 6. Runtime & Worker Commands

### Development Server with Inline Directives
Because system-level PHP ini settings default to `upload_max_filesize = 2M` and `post_max_size = 8M`, pass the following inline configuration directives when launching the development server to accept the configured 100 MB upload limit:
```bash
cd backend
php8.5 -d upload_max_filesize=128M -d post_max_size=128M -d memory_limit=512M artisan serve --port=8000
```

### Production / Staging Web Server Limits
When serving via Nginx, Apache, or PHP-FPM, align web server thresholds to avoid HTTP 413 or payload truncation before Laravel receives the request:
* **Nginx:** `client_max_body_size 128M;`
* **PHP-FPM / `php.ini`:** `upload_max_filesize = 128M`, `post_max_size = 128M`, `memory_limit = 512M`

### Queue Worker Daemon
Run the database queue worker for processing imports:
```bash
cd backend
php8.5 artisan queue:work --queue=default --timeout=3600 --tries=3
```

---

## 7. Security & Crash Recovery Notes

### API Authentication & Authorization (Phase 6)
* All `/api/imports/*` endpoints are protected by `auth:sanctum` middleware requiring a valid Bearer token.
* Strict user ownership is enforced on all import operations (`index`, `show`, `errors`, `downloadErrorsCsv`): users can only view, query, or download their own imports.
* Public endpoints are limited to authentication: `POST /api/auth/login`. Authenticated session endpoints include `POST /api/auth/logout` and `GET /api/auth/me`.
* A complete OpenAPI 3.0 specification is available at `docs/openapi.yaml`.

### Crash Recovery Strategy (Proposed)
* If a queue worker process terminates abnormally (`SIGKILL`, host reboot, or OOM killer), userland exception handlers do not run, leaving in-flight imports in `Processing`.
* **Minimal Proposed Solution for Approval:** A lightweight scheduled artisan command (e.g. `php artisan import:recover-stale`) that queries imports with `status = 'Processing'` whose `updated_at` exceeds the job timeout (3600s) and whose associated job ID is no longer active in the `jobs` table, marking them `Failed` with reason `"Worker process terminated unexpectedly."`
