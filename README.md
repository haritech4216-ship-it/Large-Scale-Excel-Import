# Large-Scale Excel Import Application

An asynchronous web application built to upload, validate, and import `.xlsx` files containing up to 500,000 records per upload without excessive memory usage or system failure.

## Target Stack

* **Backend:** Laravel 13.x (PHP 8.5.12, Composer 2.10.3)
* **Frontend:** Angular 22.x (TypeScript 6.0, Node.js 24.22.2 LTS, npm 11.19.1)
* **Database:** Isolated MySQL 8.4.13 LTS (dedicated port `3307`, local socket `/tmp/mysql84.sock`)
* **Queue:** Laravel Database Queue Driver (`jobs`, `job_batches`, `failed_jobs`)

## Workspace Directory Structure

```text
import/
├── backend/       # Laravel 13 REST API application
├── frontend/      # Angular 22 standalone client application
├── docs/          # Architecture and assignment documentation
│   └── architecture.md
├── scripts/       # MySQL 8.4 management scripts
│   ├── mysql-start.sh
│   ├── mysql-stop.sh
│   ├── mysql-status.sh
│   └── mysql-cli.sh
├── README.md      # Root project documentation
└── .gitignore     # Root Git ignore rules
```

## Running the Development Environment

### 1. MySQL 8.4 LTS Service
Manage the isolated MySQL instance using the convenience scripts in `scripts/`:
```bash
# Check status
./scripts/mysql-status.sh

# Start instance
./scripts/mysql-start.sh

# Stop instance
./scripts/mysql-stop.sh

# Open MySQL CLI
./scripts/mysql-cli.sh -u excel_user -p excel_import_db
```

### 2. Backend (Laravel 13 API)
Run using explicit PHP 8.5 with inline configuration directives to support large spreadsheet uploads up to 100 MB:
```bash
cd backend
php8.5 -d upload_max_filesize=128M -d post_max_size=128M -d memory_limit=512M artisan serve --port=8000
```
> **Note on PHP & Web Server Upload Limits:**
> Default system PHP CLI and SAPI settings (`/etc/php/8.5/cli/php.ini`) default to `upload_max_filesize = 2M` and `post_max_size = 8M`. To preserve system packages and avoid global changes, pass the `-d` directives when starting the development server. When using Nginx, Apache, or PHP-FPM in staging or production, ensure `client_max_body_size 128M;`, `upload_max_filesize = 128M`, and `post_max_size = 128M` are configured accordingly.

Run the queue worker daemon to process background import jobs:
```bash
cd backend
php8.5 artisan queue:work --queue=default --timeout=3600 --tries=3
```
Run tests:
```bash
php8.5 artisan test
```

### 3. Frontend (Angular 22)
Run using Node 24 LTS via NVM:
```bash
cd frontend
nvm use 24
npm start
```
Production build:
```bash
npm run build
```
Run tests:
```bash
npm test -- --watch=false
```

## Safety & Isolation Rules
* The system-wide MariaDB installation, existing `/var/lib/mysql` databases, and global PHP alternatives remain untouched.
* Secrets and `.env` files are excluded from version control.
