# Large-Scale Excel Import Engine

An asynchronous web application built to upload, validate, and import `.xlsx` spreadsheets containing up to 500,000 records per upload with bounded memory consumption, row-level validation, multi-tier duplicate prevention, controlled failure recovery, and authenticated per-user authorization.

---

## 1. Technology Stack

* **Backend:** Laravel 13.x (PHP 8.5.12, Composer 2.10.3)
* **Frontend:** Angular 22.x (TypeScript 6.0, Node.js 24.22.2 LTS, npm 11.19.1)
* **Database:** Oracle MySQL 8.x (Standard port `3306` or custom configured port)
* **Queue Driver:** Laravel Database Queue (`jobs`, `job_batches`, `failed_jobs`)
* **Excel Streaming:** OpenSpout 5 (zero-DOM row-by-row streaming)
* **Authentication:** Laravel Sanctum 4.x (Bearer personal access tokens)
* **API Documentation:** OpenAPI 3.0.3 & Swagger UI

---

## 2. Workspace Directory Structure

```text
import/
├── backend/       # Laravel 13 REST API application
├── frontend/      # Angular 22 standalone client application
├── docs/          # Architecture notes and OpenAPI specification
│   ├── architecture.md
│   └── openapi.yaml
├── scripts/       # Optional local MySQL helper scripts
│   ├── mysql-start.sh
│   ├── mysql-stop.sh
│   ├── mysql-status.sh
│   └── mysql-cli.sh
├── README.md      # Root project documentation
└── .gitignore     # Root Git ignore rules
```

---

## 3. System Prerequisites

* **PHP:** `^8.3` (tested with PHP `8.5.12`) with extensions: `pdo_mysql`, `zip`, `mbstring`, `xml`
* **Node.js:** Node.js 24 LTS (tested with `v24.22.2`) & npm `11.19.1`
* **Composer:** Composer `2.x` (tested with `2.10.3`)
* **Database:** Oracle MySQL Server 8.0+ or 8.4 LTS

---

## 4. Database Setup & Configuration (Standard Oracle MySQL 8.x)

The application uses standard Oracle MySQL. Follow these steps to set up the dedicated database and application user.

### Step A: Verify or Install MySQL Server
Check if MySQL is already installed and running:
```bash
# Verify daemon status
mysqladmin -u root -p ping 2>/dev/null || sudo systemctl status mysql
```
If MySQL is not yet installed on your system:
* **Ubuntu / Debian:**
  ```bash
  sudo apt update && sudo apt install -y mysql-server
  sudo systemctl enable --now mysql
  ```
* **macOS (Homebrew):**
  ```bash
  brew install mysql && brew services start mysql
  ```

### Step B: Create Dedicated Database and Application User
Connect to MySQL as administrative user (`root` or privileged account) and create the dedicated assignment database and user:
```sql
-- Create dedicated database with full UTF-8 Unicode support
CREATE DATABASE IF NOT EXISTS excel_import_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Note on MySQL Host Accounts:
-- MySQL treats '127.0.0.1' (TCP loopback) and 'localhost' (Unix socket) as distinct accounts.
-- Create both accounts explicitly to support both TCP connections (used by Laravel's DB_HOST=127.0.0.1)
-- and local socket-based CLI connections.

CREATE USER IF NOT EXISTS 'excel_user'@'127.0.0.1' IDENTIFIED BY 'your_secure_password';
CREATE USER IF NOT EXISTS 'excel_user'@'localhost' IDENTIFIED BY 'your_secure_password';

-- Grant required operational privileges strictly to the assignment database
GRANT ALL PRIVILEGES ON excel_import_db.* TO 'excel_user'@'127.0.0.1';
GRANT ALL PRIVILEGES ON excel_import_db.* TO 'excel_user'@'localhost';
FLUSH PRIVILEGES;
```

### Step C: Configure `backend/.env`
1. If `.env` does not exist in `backend/`, copy `.env.example`:
   ```bash
   cp backend/.env.example backend/.env
   ```
2. Configure database settings to match your MySQL server:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=excel_import_db
   DB_USERNAME=excel_user
   DB_PASSWORD=your_secure_password
   ```
   > **Custom Port / Socket:** If your development setup uses a non-default port (e.g. `3307`) or a custom unix socket, set `DB_PORT` and `DB_SOCKET` accordingly.

### Step D: Run Database Migrations & Initial Seed
Run the 9 database migrations to establish all required tables, indexes, constraints, and queue tables:
```bash
cd backend
php8.5 artisan migrate
```
Seed the initial authorized application user (required for login and authenticated import workflows):
```bash
php8.5 artisan db:seed
```

### Optional: Local User-Space Daemon Scripts (`scripts/`)
The `scripts/` directory contains optional convenience wrappers (`mysql-start.sh`, `mysql-status.sh`, `mysql-cli.sh`, `mysql-stop.sh`) intended for environments utilizing an isolated user-space MySQL daemon (e.g. to coexist with a separate system service without port collisions). Developers using a standard system-wide MySQL service do not need these scripts.

---

## 5. Backend & Queue Worker Setup

### Run Backend API Server
Because default system PHP CLI configurations (`php.ini`) default to `upload_max_filesize = 2M` and `post_max_size = 8M`, pass the following inline configuration directives when launching the development server to accept uploads up to the configured 100 MB limit:
```bash
cd backend
php8.5 -d upload_max_filesize=128M -d post_max_size=128M -d memory_limit=512M artisan serve --port=8000
```

> **Web Server Limits (Production / Staging):** When serving via Nginx or Apache/PHP-FPM, align web server thresholds to avoid HTTP 413: `client_max_body_size 128M;`, `upload_max_filesize = 128M`, and `post_max_size = 128M`.

### Run Queue Worker Daemon
Run the background queue worker to process asynchronous spreadsheet chunk ingestion:
```bash
cd backend
php8.5 artisan queue:work --queue=default --timeout=3600 --tries=3
```

---

## 6. Frontend Setup & Startup

Run using Node 24 LTS via NVM:
```bash
cd frontend
export PATH="/home/harishankar/.nvm/versions/node/v24.22.2/bin:$PATH" # or: nvm use 24

# Start Angular development server (http://localhost:4200)
npm start

# Run production build
npm run build
```

---

## 7. Automated Testing

### Backend Automated Test Suite
Run the 47 automated tests (feature tests, validator unit tests, authentication, and authorization tests). Tests automatically inherit database connection settings from `.env` and utilize `DatabaseTransactions` to roll back all test mutations:
```bash
cd backend
php8.5 artisan test
```

> **Optional: Dedicated Test Database Isolation:**
> By default, tests connect to the database defined in `backend/.env` and roll back transaction changes at test completion. If you want automated tests physically isolated in a separate database from your development data:
> 1. Create a dedicated test database in MySQL:
>    ```sql
>    CREATE DATABASE IF NOT EXISTS excel_import_test_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
>    GRANT ALL PRIVILEGES ON excel_import_test_db.* TO 'excel_user'@'127.0.0.1';
>    GRANT ALL PRIVILEGES ON excel_import_test_db.* TO 'excel_user'@'localhost';
>    FLUSH PRIVILEGES;
>    ```
> 2. Create `backend/.env.testing` containing:
>    ```env
>    DB_DATABASE=excel_import_test_db
>    ```
> 3. Run migrations on the test database:
>    ```bash
>    php8.5 artisan migrate --env=testing
>    ```
> Once configured, running `php8.5 artisan test` automatically targets `excel_import_test_db`.

### Frontend Automated Test Suite
Run the 29 Angular component, service, and interceptor unit tests via Vitest:
```bash
cd frontend
export PATH="/home/harishankar/.nvm/versions/node/v24.22.2/bin:$PATH"
npm test -- --watch=false
```

---

## 8. Interactive API Documentation (Swagger UI)

Interactive Swagger UI and the OpenAPI 3.0.3 specification are integrated directly into the Laravel application:

* **Interactive Swagger UI:** [`http://127.0.0.1:8000/docs`](http://127.0.0.1:8000/docs)
* **OpenAPI 3.0.3 Specification (HTTP):** [`http://127.0.0.1:8000/docs/openapi.yaml`](http://127.0.0.1:8000/docs/openapi.yaml)
* **OpenAPI 3.0.3 Specification (Local File):** [`docs/openapi.yaml`](docs/openapi.yaml)

### Authenticating in Swagger UI
All import endpoints require a Sanctum Bearer token:
1. Open [`http://127.0.0.1:8000/docs`](http://127.0.0.1:8000/docs) in your browser.
2. Under **Authentication**, expand `POST /auth/login`, click **Try it out**, enter registered user credentials, and click **Execute**.
3. Copy the plain-text `token` string returned in the JSON response.
4. Click the green **Authorize** button at the top-right of the page.
5. In the `BearerAuth` value field, paste the token and click **Authorize**, then **Close**.
6. All subsequent requests issued via **Try it out** will automatically include the `Authorization: Bearer <token>` header.

> **Caution:** The **Try it out** feature issues real HTTP requests against the backend database. Executing `POST /imports` will store files and dispatch actual background import jobs.

### Offline & Alternative Tools
* **CDN Requirement:** The `/docs` Swagger UI page loads pinned official bundles from jsdelivr (`swagger-ui-dist@5.18.2`). Interactive browser viewing requires internet access from the host machine.
* **Offline Alternative:** If running in an environment without internet access, import `docs/openapi.yaml` directly into **Postman** (File → Import) or view it in VS Code using standard OpenAPI viewer extensions.

---

## 9. Security & Architecture Invariants

* **Isolated Database Daemon / Port:** Supports standard MySQL on port `3306` while also cleanly supporting isolated daemons on port `3307`. System MariaDB and `/var/lib/mysql` remain untouched.
* **Per-User Ownership Isolation:** All import history, status lookups, validation errors, and CSV downloads are strictly scoped to the authenticated user. Cross-user access returns HTTP 403.
* **Formula Injection Sanitization:** Downloaded error CSV files prefix formula trigger characters (`=`, `+`, `-`, `@`, `\t`, `\r`) with a single quote to prevent spreadsheet injection.
* **Error Sanitization:** Database credentials and internal file paths are stripped from error messages before storage or API serialization.
* **Secrets Protection:** Environment files (`.env`) and credentials are systematically excluded from version control.
