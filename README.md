# Large-Scale Excel Import Engine

An asynchronous web application built with Laravel and Angular to upload, validate, and import `.xlsx` spreadsheets containing up to 500,000 records per upload with streaming memory efficiency, row-level validation, multi-tier duplicate prevention, idempotent failure recovery, and authenticated per-user data isolation.

---

## 1. Project Overview

* **High-Volume Asynchronous Ingestion:** Streams `.xlsx` spreadsheets up to 500,000 rows using OpenSpout without buffering complete workbook DOM trees into memory.
* **15-Field Row Validation:** Validates 15 customer attributes (11 required, 4 optional) with date serial conversion and flexible header normalization.
* **Multi-Tier Duplicate Prevention:** Detects in-file duplicates via in-memory tracking, database duplicates via indexed queries, and concurrency races via transactional sub-batch fallbacks.
* **Three-Stage Real-Time Progress:** Angular interface displays separate progress tracking for file upload, validation pipeline, and database insertion with modal close/reopen capability.
* **Idempotent Queue Processing:** Sequential chunk processing (default 1,000 rows/chunk, 500 rows/insert) with atomic chunk claims and automated stale import recovery.
* **Audit & Error Reporting:** Paginated row-level error reporting and formula-injection-protected CSV export.

---

## 2. Technology Stack

* **Backend Framework:** Laravel 13 (`laravel/framework` `^13.17`, v13.39.0 locked)
* **Frontend Framework:** Angular 22 (`@angular/core` `^22.2.0`)
* **TypeScript:** TypeScript 6 (`~6.0.2`)
* **Reactive Extensions:** RxJS (`~7.8.0`)
* **Excel Engine:** OpenSpout 5 (`openspout/openspout` `^5.0`, v5.13.0 locked) — used for streaming XLSX reading, sample template generation, and benchmark creation
* **API Authentication:** Laravel Sanctum 4 (`laravel/sanctum` `^4.3`, v4.3.2 locked)
* **Queue Engine:** Laravel Database Queue Driver (`jobs`, `failed_jobs`)
* **Database:** MySQL Server 8.0+ / 8.4 LTS (InnoDB engine)
* **API Specification:** OpenAPI 3.0.3 & Swagger UI

---

## 3. System Requirements

Ensure your environment meets the following requirements:

* **PHP:** `^8.3` (tested with PHP `8.5`)
  * Required Extensions: `pdo_mysql`, `zip`, `mbstring`, `xml`, `filter`, `openssl`, `tokenizer`, `ctype`
* **Composer:** Composer `2.x`
* **Node.js:** Node.js `20.x`, `22.x`, or `24.x` LTS
* **npm:** npm `10.x` or `11.x`
* **Database:** MySQL Server 8.0+ or 8.4 LTS (InnoDB with `utf8mb4`)

---

## 4. Project Structure

```text
import/
├── backend/                  # Laravel 13 REST API application
│   ├── app/
│   │   ├── Console/Commands/ # Stale import recovery & 500k benchmark commands
│   │   ├── Http/Controllers/ # Auth, Import, and ImportedData API controllers
│   │   ├── Jobs/             # Asynchronous streaming chunk import jobs
│   │   ├── Models/           # Eloquent models (Customer, Import, ImportChunk, etc.)
│   │   └── Services/         # OpenSpout streaming parser & row validation services
│   ├── config/               # Application configuration (including excel_import.php)
│   ├── database/             # Migrations, seeders, and factories
│   ├── routes/               # API routes (api.php)
│   └── tests/                # PHPUnit feature and unit test suites
├── frontend/                 # Angular 22 standalone client application
│   ├── src/app/
│   │   ├── components/       # Login, Imports history, Import modal, Imported data
│   │   ├── guards/           # Sanctum authentication & route guards
│   │   ├── interceptors/     # Bearer token HTTP interceptor
│   │   ├── models/           # TypeScript interfaces & types
│   │   └── services/         # API client & modal state services
│   └── dist/                 # Production compiled assets (dist/frontend/browser)
├── docs/                     # Technical documentation
│   ├── architecture.md       # Architectural deep-dive & design choices
│   ├── openapi.yaml          # OpenAPI 3.0.3 specification
│   └── 500K_BENCHMARK.md     # Measured 500,000-row performance benchmark report
└── README.md                 # Project developer setup guide
```

---

## 5. Installation and Configuration

### Step 1: Clone Repository
```bash
git clone <repository-url>
cd import
```

### Step 2: Install Backend Dependencies
Navigate to the `backend/` directory and install PHP packages:
```bash
cd backend
composer install
```

### Step 3: Configure Environment
Copy the example environment file and generate the application encryption key:
```bash
cp .env.example .env
php artisan key:generate
```

Configure your database connection in `backend/.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=excel_import_db
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

QUEUE_CONNECTION=database
```

> **File Upload Configuration:** A 500,000-row `.xlsx` spreadsheet is approximately 40 MB. In `backend/.env`, `EXCEL_IMPORT_MAX_SIZE_KB` defaults to `102400` (100 MB). To allow browser uploads of large files, PHP runtime upload limits must exceed the file size (`upload_max_filesize=128M`, `post_max_size=128M`, `memory_limit=512M`).

### Step 4: Install Frontend Dependencies
In a separate terminal, navigate to the `frontend/` directory and install npm packages:
```bash
cd frontend
npm install
```

---

## 6. Database Setup

### Step 1: Create Database
Ensure your MySQL server is running, then create the database:
```sql
CREATE DATABASE IF NOT EXISTS excel_import_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

*(Optional)* If you prefer a dedicated application user for local development:
```sql
CREATE USER IF NOT EXISTS 'excel_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON excel_import_db.* TO 'excel_user'@'localhost';
FLUSH PRIVILEGES;
```

### Step 2: Run Migrations
From the `backend/` directory, run all database migrations:
```bash
cd backend
php artisan migrate
```

This creates the tables for users, cache, queue jobs (`jobs`, `failed_jobs`), imports, import chunks, import row errors, and customers.

### Step 3: Seed Initial User
Seed the authorized application user required for authentication:
```bash
php artisan db:seed
```

* **Default User Email:** `admin@example.com`
* **Default Password:** `Password123!`

> [!SECURITY]
> The seeded admin credentials above are provided solely for local development and testing evaluation. In any shared, staging, or production environment, immediately change or rotate these credentials.

---

## 7. Running the Application

Running the application locally requires three processes: the backend API server, the Laravel queue worker, and the Angular frontend.

### 1. Backend API Server
Start the Laravel development server. Pass upload configuration directives to accommodate large files:
```bash
cd backend
php -d upload_max_filesize=128M -d post_max_size=128M -d memory_limit=512M artisan serve --port=8000
```
The REST API will be available at `http://127.0.0.1:8000/api`.

> **Production Note:** When serving behind Nginx, Apache, or PHP-FPM, align web server directives accordingly (`client_max_body_size 128M;`, `upload_max_filesize = 128M`, and `post_max_size = 128M`).

### 2. Laravel Queue Worker
In a second terminal, start the queue worker to process asynchronous spreadsheet imports:
```bash
cd backend
php artisan queue:work --timeout=3600 --tries=3
```

### 3. Angular Frontend
In a third terminal, start the Angular development server:
```bash
cd frontend
npm start
```
Open your browser at `http://localhost:4200` and sign in using the seeded credentials (`admin@example.com` / `Password123!`).

---

## 8. Running Tests

### Backend Test Suite (PHPUnit)
The backend test suite covers authentication, authorization, streaming ingestion, duplicate race conditions, queue retries, and stale job recovery:
```bash
cd backend
php artisan test
```
*Snapshot: 88 passed (495 assertions).*

> **Dedicated Test Database (Optional):** To isolate tests from development data, set `DB_DATABASE=excel_import_test_db` in `backend/.env.testing` and run `php artisan migrate --env=testing`. Subsequent `php artisan test` runs will automatically target the test database.

### Frontend Test Suite (Vitest)
The frontend test suite covers components, services, route guards, interceptors, and modal progress logic:
```bash
cd frontend
npm test -- --watch=false
```
*Snapshot: 9 test files passed, 83 passed.*

### Frontend Production Build
To verify the production compilation:
```bash
cd frontend
npm run build
```
Compiled production assets are output to `frontend/dist/frontend/browser`.

---

## 9. API Documentation

Interactive Swagger UI and the OpenAPI 3.0.3 specification are integrated into the application:

* **Interactive Swagger UI:** `http://127.0.0.1:8000/docs`
* **OpenAPI 3.0.3 Specification (HTTP):** `http://127.0.0.1:8000/docs/openapi.yaml`
* **OpenAPI 3.0.3 Specification (File):** [`docs/openapi.yaml`](docs/openapi.yaml)

### Authenticating in Swagger UI
1. Open `http://127.0.0.1:8000/docs`.
2. Under **Authentication**, expand `POST /auth/login`, click **Try it out**, enter your credentials, and click **Execute**.
3. Copy the plain-text `token` string returned in the response.
4. Click the **Authorize** button at the top-right.
5. In the `BearerAuth` field, enter the token and click **Authorize**. Subsequent requests will include the Bearer token header.

---

## 10. 500K Benchmark Execution & Memory Dynamics

The repository provides Artisan commands for generating and benchmarking a 500,000-record Excel spreadsheet using streaming ingestion.

### Step 1: Generate 500,000-Row Sample Spreadsheet
Streams 500,000 deterministic customer records with all 15 spreadsheet columns (11 required, 4 optional):
```bash
cd backend
php artisan make:sample-500k --count=500000
```
*Generation performance: 500,000 rows in ~100 seconds (4,996 rows/sec), file size 40.08 MB, peak generator memory: 26 MB.*

### Step 2: Execute Import Benchmark & Verification
Ingest the generated spreadsheet through the production `ProcessImportJob` engine and execute automated data integrity verification:
```bash
cd backend
php artisan benchmark:run-500k --count=500000
```
*Pass `--cleanup` to automatically remove benchmark customer and import records after verification.*

### Measured Results Summary
* **Records Processed:** 500,000 records
* **Processing Duration:** 723.62 seconds (~12.06 minutes)
* **Processing Throughput:** 691.0 rows / second
* **Peak Engine Memory:** 82.0 MB (measured total peak worker memory)
* **Data Reconciliation:** 500,000 valid / 500,000 saved / 0 rejected / 0 duplicates
* **Integrity Invariants:** All 8 automated integrity checks passed

### Memory Dynamics Explained
* **Zero-DOM Streaming:** OpenSpout reads spreadsheet rows sequentially directly from XML tokens without building an in-memory DOM tree. Reader memory remains small and bounded (~15–20 MB).
* **In-File Duplicate Tracking:** To satisfy the requirement of detecting duplicate Customer IDs within the file, the worker maintains an in-memory hash map (`$seenFileCustomerIds[$customerId] = $rowNumber`).
* **Memory Scaling:** Because of this tracking map, memory is not completely flat—it scales linearly with the number of unique Customer IDs in the file. For 500,000 records, the hash map accounts for an estimated ~35–45 MB.
* **Total Worker Peak:** Across the entire 500,000-record benchmark, total peak worker memory reached **82.0 MB**, staying well within the standard 512 MB CLI memory limit.

For full benchmark methodology, hardware specs, and throughput analysis, refer to [`docs/500K_BENCHMARK.md`](docs/500K_BENCHMARK.md).

---

## 11. Architecture & Security Invariants

* **Streaming Ingestion:** OpenSpout 5 streams `.xlsx` files row-by-row, preventing workbook DOM memory bloat.
* **Per-User Ownership Isolation:** All import history, statuses, error logs, and customer records are strictly scoped to the authenticated user. Cross-user access returns HTTP 403.
* **Transactional Chunking:** Rows are buffered into configurable chunks (default 1,000 rows) and inserted in 500-record sub-batches within individual database transactions.
* **Formula Injection Mitigation:** Streamed CSV error exports prefix formula trigger characters (`=`, `+`, `-`, `@`, `\t`, `\r`) with a single quote (`'`) to neutralize spreadsheet execution exploits.
* **Credential Scrubbing:** Database credentials and internal server paths are scrubbed from error logs before database persistence or API output.
* **Stale Worker Recovery:** The `php artisan import:recover-stale` command detects stuck imports caused by unexpected worker terminations and safely re-dispatches or marks them failed without duplicate records.

For architectural design decisions and trade-offs, refer to [`docs/architecture.md`](docs/architecture.md).
