# 15 - Implementation Progress Tracker

## 1. Current Status Summary

* **Active Phase:** Phase 3A — Foundation & Core Platform
* **Current Milestone Completed:** **Milestone 1 — Project Structure, Environment Configuration & Laravel 13 API Foundation**
* **Next Planned Milestone:** Milestone 2 — Core Database Migrations, Models & Taxonomy/Location Seeders (or Next.js App Router Foundation — awaiting confirmation to proceed)
* **Stack Compliance Check:**
  * Laravel Framework: `v13.43.0` (PHP `8.3.36`)
  * Bearer Auth: `laravel/sanctum v4.5.0` (stateless Bearer mode configured)
  * Redis Client: `predis/predis v3.7.1`
  * Filament Installed: **None** (verified automatically by `ApiFoundationTest::test_composer_contains_no_filament_dependencies`)

---

## 2. Milestone Log

| Milestone | Scope | Status | Verification Evidence | Git Commit |
| :--- | :--- | :--- | :--- | :--- |
| **Phase 1** | Website Discovery & Analysis (`docs/01`–`06`) | Completed | Live probes, `robots.txt` audit, REA inspection of `webfont.min.js`, 6 docs created | `b1760e5` |
| **Phase 2** | System Architecture, ERD, API, Search, SEO, Security & Roadmap (`docs/07`–`14`) + 7 final approval updates | Completed | All 8 architecture docs updated with BFF isolation, independent Policies, English/Tamil search benchmarks, configurable privacy retention + legal exceptions, webhook idempotency/retry, SEO thin-page/sitemap parity, DB restore/RPO/RTO | `b1760e5` |
| **Phase 3A — M1** | Project structure, Docker Compose (MySQL 8.0, Redis 7, MinIO, Mailpit), `backend/` Laravel 13 REST API foundation, standardized Flutter-compatible JSON envelopes, exception renderer, request-ID & security headers middleware, configurable `config/privacy.php` & `config/directory.php`, `/api/v1/health`, `/api/v1/meta`, and custom Blade `/admin/health` foundation | **Completed & Verified** | `php artisan test`: **14 passed (106 assertions)**; `vendor/bin/pint --test`: **passed** | *(Committed below)* |
| **Phase 3A — M2** | Core Database Migrations (Identity, RBAC, Geography, Taxonomy) & Seeders (English + Tamil synonyms) | Pending | Not started | — |
| **Phase 3A — M3** | Identity Domain: Secure OTP Auth, Rate Limiting, Sanctum Bearer Tokens, Device Registration, Independent RBAC Policies | Pending | Not started | — |
| **Phase 3A — M4** | Single Next.js App Router Foundation (`frontend/`) with `(customer)` & `(business)` route groups, Tailwind CSS, and `HttpOnly` BFF Cookie Bridge | Pending | Not started | — |
| **Phase 3B** | Directory Core, MySQL 8 Spatial + FullText Search (English/Tamil), Autocomplete, Business Profiles & Media Pipeline | Pending | Not started | — |
| **Phase 3C** | Privacy-Compliant Enquiries, Reviews & Business Owner Dashboard | Pending | Not started | — |
| **Phase 3D** | Custom Laravel Blade Admin Dashboard (Session Guard, TOTP 2FA, KYC Queue, Moderation, Audit Logs) | Pending | Not started | — |
| **Phase 3E** | Payment Integration (Webhooks + Idempotency + Invoices), Sponsored Placements, Sitemaps & Production Hardening | Pending | Not started | — |

---

## 3. Milestone 1 Verification Details

### 3.1 Implemented Components (No Placeholders)
* **Root Infrastructure:** `.gitignore`, `README.md`, `docker-compose.yml` (MySQL 8.0, Redis 7, MinIO, Mailpit), `docker/mysql/my.cnf` (`utf8mb4_0900_ai_ci`, `innodb_ft_min_token_size=2`, `long_query_time=0.5`, row-based binlogs for PITR).
* **Laravel 13 Backend (`backend/`):**
  * `config/database.php`: MySQL defaults set to `utf8mb4_0900_ai_ci` and `InnoDB`.
  * `config/sanctum.php`: Stateless Bearer configuration (`stateful = []`), configurable expiration (`43200` min), token prefix (`lbd_`).
  * `config/cors.php`: Explicit origin whitelist (`CORS_ALLOWED_ORIGINS`), Bearer headers, `supports_credentials = false`.
  * `config/privacy.php`: Configurable retention windows & legal exception declarations (GST invoices, legal holds, audit logs).
  * `config/directory.php`: API version `v1`, `en`/`ta` locales, `INR`, `Asia/Kolkata`, OTP & SEO policy defaults.
  * `app/Support/Api/ApiResponse.php`: Standard JSON envelopes (`success`, `created`, `noContent`, `paginated` for both offset & cursor paginators, `error`).
  * `app/Support/Api/ApiExceptionRenderer.php`: Unified JSON exception translation (`401`, `403`, `404`, `405`, `422`, `429`, `500`) for `/api/*`.
  * `app/Http/Middleware/`: `AssignRequestId`, `SecurityHeaders`, `ForceJsonResponse`.
  * `app/Http/Controllers/Api/V1/SystemController.php`: Live DB + Cache readiness check (`GET /api/v1/health`) and Flutter-compatible platform metadata (`GET /api/v1/meta`).
  * `routes/api.php` & `routes/admin.php` + Blade foundation (`resources/views/admin/layouts/app.blade.php`, `health.blade.php`).

### 3.2 Environment Notes & Caveats
* Local host currently has **PHP 8.3.36**, **Composer 2.10.3**, **Node v24.11.1**, **npm 11.6.2**, and **Git 2.52.0**.
* **Docker, MySQL 8.0, and Redis 7 are not currently installed/running on this host** (XAMPP contains MariaDB 10.4.32, which does not support MySQL 8.0 SRID 4326 spatial semantics).
* Therefore, Milestone 1 automated tests verified the API foundation using PHPUnit's SQLite `:memory:` and `array` cache drivers. **Before executing Spatial SRID 4326 and MySQL 8.0 FULLTEXT integration tests in Phase 3A–M2 / Phase 3B**, MySQL 8.0 and Redis 7 should be provisioned locally (e.g., via Docker Desktop using the included `docker-compose.yml` or native MySQL 8.0 + Redis installation).
