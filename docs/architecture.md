# Architecture Notes: Phase 2 Initialization

## 1. System Overview

The Large-Scale Excel Import solution is split into two decoupled layers:
* **Backend:** A Laravel 13 REST API responsible for file upload ingestion, asynchronous queued processing (chunked streaming), row-level validation, duplicate detection, and database bulk insertion.
* **Frontend:** An Angular 22 Single Page Application (SPA) offering an interactive progress indicator, import history dashboard, and CSV error report downloads.

## 2. Infrastructure & Runtime Configuration

| Component | Target Version | Active Release | Runtime Notes |
| :--- | :--- | :--- | :--- |
| **PHP** | 8.5.x | `8.5.12` (CLI) | Targeted explicitly via `/usr/bin/php8.5` |
| **Composer** | Latest 2.x | `2.10.3` | Global binary tested against PHP 8.5 |
| **Laravel** | 13.x | `13.39.0` (Framework) | Initialized in `backend/` |
| **Node.js** | 24 LTS | `24.22.2` (Krypton) | Managed via user-local NVM |
| **npm** | Matching Node 24 | `11.19.1` | User-local npm |
| **Angular** | 22.x | `22.2.3` (CLI `22.2.5`) | Initialized in `frontend/` |
| **Database** | MySQL 8.4 LTS | `8.4.13` Community | Isolated user-space daemon on port `3307` |
| **Queue** | Database driver | `jobs` / `job_batches` | Database-backed queue tables configured |

## 3. Database Isolation Strategy

To prevent interference with the host's existing MariaDB 10.6 installation and avoid risking existing databases stored in `/var/lib/mysql`:
* An isolated MySQL 8.4 instance runs from `/home/harishankar/opt/mysql-8.4`.
* Storage resides on the native **ext4** filesystem (`/home/harishankar/opt/mysql-8.4/data`) rather than the NTFS mount, ensuring optimal `O_DIRECT` throughput and fsync performance for 500,000-record batch imports.
* Network binding is strictly `127.0.0.1:3307` with local socket `/tmp/mysql84.sock`.
* The dedicated application database is `excel_import_db`, accessed by the least-privilege user `excel_user`.

## 4. Queue Architecture

As specified in Phase 2:
* `QUEUE_CONNECTION=database` is configured in `backend/.env`.
* The necessary queue tables (`jobs`, `job_batches`, `failed_jobs`) have been created via Laravel's default migrations.
* No import jobs, workers, or Redis infrastructure are added in this phase.
