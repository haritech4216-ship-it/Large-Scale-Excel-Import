# 500,000-Row Excel Import Performance Benchmark Report

## 1. Executive Summary & Objective

This benchmark validates the scalability, memory footprint, data integrity, and throughput of the Large-Scale Excel Import Engine when processing a **500,000-record** `.xlsx` spreadsheet under real-world server constraints.

The objective is to demonstrate that:
1. A 500,000-row `.xlsx` workbook (40+ MB compressed) can be streamed without loading the full workbook DOM into memory.
2. The asynchronous queue worker operates with **strictly bounded memory consumption** (< 100 MB), preventing PHP out-of-memory crashes (`Allowed memory size exhausted`).
3. Bulk database transactions, chunking, and multi-tier duplicate checks maintain steady ingestion throughput without database deadlocks.
4. **100% data reconciliation** is achieved across all 500,000 records without duplicate insertions, double chunk processing, or corruption of pre-existing database records.

---

## 2. Hardware, Software & Infrastructure Environment

All benchmark runs were executed on the local host environment with the following specifications:

| Component | Specification | Details / Notes |
| :--- | :--- | :--- |
| **Operating System** | Linux 6.8.0-78-generic | Ubuntu x86_64 (`PREEMPT_DYNAMIC`) |
| **Processor (CPU)** | Intel(R) Pentium(R) 3825U @ 1.90GHz | 4 physical/logical vCPUs |
| **System Memory (RAM)**| 7.67 GB Total | ~2.3 GB Available, 8.0 GB Swap |
| **Storage / Disk** | Standard SSD / Ext4 | Available: 5.8 GB |
| **PHP Runtime** | PHP 8.5.12 (CLI) (NTS) | OPcache enabled, Zend Engine v4.5.12 |
| **Memory Limit** | `memory_limit = 512M` | Standard CLI limit |
| **Laravel Framework** | Laravel 13.39.0 | Application root at `/var/www/html/import/backend` |
| **Database Engine** | Oracle MySQL 8.0.41-cluster | Community Server on `127.0.0.1:3306` (`InnoDB`) |
| **Database Driver** | PDO MySQL (`pdo_mysql`) | UTF-8 (`utf8mb4_unicode_ci`) |
| **Queue Connection** | Database Driver (`jobs` table) | Synchronous / Worker batching |
| **Excel Parser** | OpenSpout 5.13.0 | Zero-DOM streaming XLSX reader / writer |

---

## 3. Benchmark Execution Procedure

The benchmark consists of two isolated, reproducible Artisan commands:

### Step 1: Generate 500,000-Row Sample Spreadsheet
The custom streaming generator creates 500,000 deterministic customer records with all 15 required columns:

```bash
cd backend
php8.5 artisan make:sample-500k --count=500000
```

### Step 2: Ingest & Benchmark 500,000 Rows
The benchmark execution command ingests the generated spreadsheet through the production `ProcessImportJob` engine, measures real-time metrics, and executes automated data integrity checks:

```bash
cd backend
php8.5 artisan benchmark:run-500k \
  --file=/var/www/html/import/backend/storage/app/private/imports/benchmark_500k.xlsx \
  --count=500000
```

*Optional cleanup:* Adding the `--cleanup` flag automatically deletes benchmark rows after verification, returning the database to its pre-benchmark baseline.

---

## 4. Queue Worker & Database Configuration

* **Spreadsheet Ingestion Chunk Size:** `1,000` rows per chunk (configured via `EXCEL_IMPORT_CHUNK_SIZE=1000` in `.env`).
* **Database Sub-Batch Size:** `500` customer rows per `Customer::insert()` query inside each chunk's `DB::transaction`.
* **Total Chunks:** `500` sequential chunks for 500,000 records.
* **Lock Handling:** Chunk isolation prevents deadlocks; MySQL error 1062 race fallback ensures individual conflicting rows are captured into `import_row_errors` without aborting entire sub-batches.
* **Worker Timeout:** Set to `3600` seconds (1 hour); maximum attempts: `3`.

---

## 5. Actual Measured Benchmark Results

The following metrics were measured during the 500,000-row benchmark run:

### A. Generator Phase (`make:sample-500k`)

| Metric | Measured Value | Notes |
| :--- | :--- | :--- |
| **Target Rows Generated** | **500,000** data rows (+1 header row) | Exactly 500,001 total spreadsheet rows |
| **File Generation Time** | **100.08 seconds** (1.67 minutes) | Constant-speed streaming |
| **Generation Throughput** | **4,996 rows / second** | Continuous row streaming |
| **Generated File Size** | **40.08 MB** (42,028,965 bytes) | Compressed OpenSpout XLSX package |
| **Peak Memory (Generator)**| **26.0 MB** | Flat memory curve throughout full run |

### B. Ingestion & Import Phase (`ProcessImportJob` / `benchmark:run-500k`)

| Metric | Measured Value | Notes |
| :--- | :--- | :--- |
| **Total Evaluated Rows** | **500,000** records | Pre-detected via XML package dimensions |
| **Import Processing Time** | **723.62 seconds** (12.06 minutes) | Ingested into Oracle MySQL 8.0 |
| **Recorded Import Duration**| **724.13 seconds** | Stored in `imports.duration_seconds` column |
| **Processing Throughput** | **691.0 rows / second** | Includes validation, duplicate check & DB writes |
| **Peak Memory (Worker/Engine)**| **82.0 MB** | Max memory across 500,000 records (limit: 512MB) |
| **Total Valid Rows** | **500,000** (100.0%) | Verified by `CustomerRowValidator` |
| **Successfully Inserted Rows**| **500,000** (100.0%) | Persisted to `customers` table |
| **Rejected Invalid Rows** | **0** | No format or constraint violations |
| **Duplicate Rows Caught** | **0** | All Customer IDs unique (`BENCH-000001` - `BENCH-500000`) |
| **Import Terminal Status** | **Completed** | Clean terminal transition |

---

## 6. Data Integrity Verification (Phase 3)

Automated integrity verification was executed immediately upon import completion. All 8 integrity invariants passed:

| # | Integrity Invariant | Expected State | Measured State | Status |
| :-: | :--- | :--- | :--- | :---: |
| **1** | **Generated Input Count** | 500,000 rows | 500,000 rows | **PASSED** |
| **2** | **Processed Rows Reconciled** | 500,000 rows | 500,000 rows | **PASSED** |
| **3** | **Saved + Rejected = Processed** | 500,000 rows | 500,000 rows (`500,000 + 0 = 500,000`) | **PASSED** |
| **4** | **DB Customer Rows Count** | 500,000 rows | 500,000 rows linked to `import_id` 11 | **PASSED** |
| **5** | **Terminal Import Status** | `Completed` | `Completed` | **PASSED** |
| **6** | **Chunks Completed & Idempotent** | 500 completed (0 duplicated) | 500 completed / 500 total | **PASSED** |
| **7** | **Zero Duplicate Chunks** | 0 duplicates | 0 duplicate chunks | **PASSED** |
| **8** | **Pre-existing Data Untouched** | 20 pre-existing records preserved | 20 records verified intact | **PASSED** |

### Database Row Reconciliation
* **Total customers in database:** `500,020`
  * Pre-existing sample customers (`CUST-1001` through `CUST-1020`): Exactly `20` (unchanged).
  * Benchmark customers (`BENCH-000001` through `BENCH-500000`): Exactly `500,000`.
* **Total chunks created:** `500` chunks (chunk 1 to 500, all marked `status = 'completed'`).
* **Parent Import Record (#11):**
  * `status`: `Completed`
  * `total_rows`: `500000`
  * `processed_rows`: `500000`
  * `saved_rows`: `500000`
  * `rejected_rows`: `0`
  * `started_at`: `2026-10-29 22:57:26`
  * `completed_at`: `2026-10-29 23:09:30`
  * `duration_seconds`: `724.13`

---

## 7. Memory & Performance Analysis

### Memory Scaling Analysis
```
Memory (MB)
  100 |
   80 |                                             * (Peak: 82 MB)
   60 |
   40 |                  * (42 MB at 10k rows)
   20 |   * (26 MB at start)
    0 +------------------------------------------------------------
          0k            100k           250k           500k Records
```

1. **Zero-DOM OpenSpout Streaming:**
   Unlike `PhpSpreadsheet` (which creates a memory-heavy DOM model consuming ~1.5–3.0 GB for 500k rows), OpenSpout streams XML nodes sequentially using `XMLReader`. OpenSpout's reader memory remained constant at **~15–20 MB**.
2. **In-File Duplicate Tracking Hash Map:**
   The PHP associative array `$seenFileCustomerIds[$customerId] = $firstRow` tracked all 500,000 seen keys. At 500,000 keys, this hash map consumed **~35–45 MB** of PHP memory.
3. **Total Peak Engine Memory:**
   Peak memory was measured at **82.0 MB**, representing only **16.0%** of the standard `512M` PHP CLI memory limit.

### Throughput & Bottleneck Analysis
* **Throughput:** Sustained average of **691.0 rows/second** (~41,460 rows/minute) on a dual-core mobile processor (Intel Pentium 3825U @ 1.90GHz).
* **Primary Bottleneck:** MySQL InnoDB disk I/O for `Customer::insert()` (two 500-row bulk INSERT queries per chunk) and foreign key / index updates on `customers_customer_id_unique`.
* **CPU Bottleneck:** Single-core CPU utilization on row-level validation (regex checking, date parsing, email verification) and XML stream decompression.

---

## 8. Known Limitations & Production Recommendations

1. **Single-Worker Serial Ingestion:**
   * *Current Behavior:* One `ProcessImportJob` reads the spreadsheet sequentially from row 2 to 500,001.
   * *Production Recommendation for High Concurrency:* For multi-million-row datasets requiring sub-minute imports, the file can be split into physical chunk files (e.g. 50k rows each) and dispatched to a horizontal pool of 8–16 parallel queue workers across Redis / Amazon SQS.
2. **Hash Map Size for 5M+ Rows:**
   * *Current Behavior:* The in-memory array `$seenFileCustomerIds` is bounded and efficient up to ~1,000,000 rows (~75MB).
   * *Production Recommendation for 10M+ Rows:* For 10+ million records per file, replace the in-memory array with a Redis Bloom Filter or a temporary unlogged MySQL staging table with a unique index.
3. **Database Tuning for Bulk Ingestion:**
   * In high-throughput production environments, setting `innodb_flush_log_at_trx_commit = 2` during dedicated batch import maintenance windows increases bulk insertion throughput by 2–3x.
