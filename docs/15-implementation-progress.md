# 15 - Implementation Progress Tracker

## 1. Current Status Summary

* **Active Phase:** Phase 3A — Foundation & Core Platform
* **Current Milestone Completed:** **Milestone 2 — Core Database Migrations, Models & Taxonomy/Location Seeders**
* **Next Planned Milestone:** **Milestone 3 — Identity Domain: Secure OTP Authentication, Rate Limiting, Sanctum Bearer Tokens, Device Registration & Independent RBAC Policies** (awaiting approval to proceed)
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
| **Phase 3A — M1** | Project structure, Docker Compose (MySQL 8.0, Redis 7, MinIO, Mailpit), `backend/` Laravel 13 REST API foundation, standardized Flutter-compatible JSON envelopes, exception renderer, middleware, `config/privacy.php`, `config/directory.php`, `/api/v1/health`, `/api/v1/meta`, and custom Blade `/admin/health` foundation | **Completed & Verified** | `php artisan test`: **14 passed (106 assertions)**; `vendor/bin/pint --test`: **passed** | `d739be7` |
| **Phase 3A — M2** | Core Database Migrations (Identity, RBAC, OTP Challenges, User Devices, Geography with English/Tamil translations, Categories & Subcategories with English/Tamil translations & Tanglish synonyms), ULID & Spatial traits, Factories, Idempotent Reference Seeders, Isolated Non-Production `DevSampleSeeder`, and Database Constraint/Relationship Tests | **Completed & Verified** | Clean `migrate:fresh --seed`, re-run `migrate` (`Nothing to migrate`), rollback `--step=4` + re-migrate passed; `php artisan test`: **23 passed (173 assertions), 1 skipped (MySQL 8.0 unavailable locally)**; `vendor/bin/pint --test`: **passed** | *(Milestone 2 commit)* |
| **Phase 3A — M3** | Identity Domain: Secure OTP Auth, Rate Limiting, Sanctum Bearer Tokens, Device Registration, Independent RBAC Policies | Pending | Not started | — |
| **Phase 3A — M4** | Single Next.js App Router Foundation (`frontend/`) with `(customer)` & `(business)` route groups, Tailwind CSS, and `HttpOnly` BFF Cookie Bridge | Pending | Not started | — |
| **Phase 3B** | Directory Core, MySQL 8 Spatial + FullText Search (English/Tamil), Autocomplete, Business Profiles & Media Pipeline | Pending | Not started | — |
| **Phase 3C** | Privacy-Compliant Enquiries, Reviews & Business Owner Dashboard | Pending | Not started | — |
| **Phase 3D** | Custom Laravel Blade Admin Dashboard (Session Guard, TOTP 2FA, KYC Queue, Moderation, Audit Logs) | Pending | Not started | — |
| **Phase 3E** | Payment Integration (Webhooks + Idempotency + Invoices), Sponsored Placements, Sitemaps & Production Hardening | Pending | Not started | — |

---

## 3. Milestone 2 Verification Details

### 3.1 Implemented Migrations & Schema Policies
* `0001_01_01_000000_create_users_table.php`: `users` (`BIGINT UNSIGNED` PK, `public_id CHAR(26)` ULID unique, `phone_e164` unique, `email` unique nullable, `totp_secret_encrypted`, `status`, `softDeletes`), `password_reset_tokens`, `sessions` (`nullOnDelete`).
* `2026_11_18_080001_create_rbac_tables.php`: `roles`, `permissions`, `role_permissions` (`CASCADE`), `user_roles` (`user_id CASCADE`, `role_id RESTRICT`, `assigned_by_user_id SET NULL`).
* `2026_11_18_080002_create_otp_challenges_and_user_devices_tables.php`: `otp_challenges` (`challenge_id` ULID unique, `code_hash CHAR(64)`, `purpose`, `attempts`, `expires_at` indexed), `user_devices` (`user_id CASCADE`, `UNIQUE(user_id, device_identifier)`).
* `2026_11_18_080003_create_geography_tables.php`: `states`, `cities` (`state_id RESTRICT`, `UNIQUE(slug)`, `UNIQUE(state_id, name)`), `city_translations` (`CASCADE`, `UNIQUE(city_id, locale)`), `localities` (`city_id RESTRICT`, `UNIQUE(city_id, slug)`, `UNIQUE(city_id, name)`), `locality_translations` (`CASCADE`, `UNIQUE(locality_id, locale)`). Adds `POINT NOT NULL SRID 4326` + `SPATIAL INDEX` when running on `mysql`.
* `2026_11_18_080004_create_taxonomy_tables.php`: `categories` (`public_id` ULID unique, `parent_id RESTRICT`, `UNIQUE(slug)`, `UNIQUE(parent_id, name)`), `category_translations` (`CASCADE`, `UNIQUE(category_id, locale)`, English/Tamil names, synonyms, SEO templates). Adds `FULLTEXT INDEX` on `(name, synonyms)` when running on `mysql`.

### 3.2 Domain Models, Factories & Seeders
* **Traits:** `HasPublicUlid` (auto-generates 26-char ULIDs; hides internal `id` on public models `User`, `OtpChallenge`, `Category`), `HasSpatialCenterPoint` (automatically populates MySQL 8.0 SRID 4326 `POINT(lat lng)` from coordinates).
* **Models:** `User`, `Role`, `Permission`, `OtpChallenge`, `UserDevice`, `State`, `City`, `CityTranslation`, `Locality`, `LocalityTranslation`, `Category`, `CategoryTranslation`.
* **Factories:** Complete factories for all 9 core entities (`User`, `Role`, `Permission`, `OtpChallenge`, `UserDevice`, `State`, `City`, `Locality`, `Category`).
* **Seeders Separation:**
  * `DatabaseSeeder` invokes **only** idempotent production-safe reference seeders (`RolesAndPermissionsSeeder`, `LocationsSeeder`, `CategoriesSeeder`) with English (`en`) and Tamil (`ta`) translations + Tanglish search synonyms — zero synthetic users and zero fabricated real businesses.
  * `Development\DevSampleSeeder` is isolated from `DatabaseSeeder` and throws `RuntimeException` if invoked in `production`.

### 3.3 Integration Tests Skipped Due to Local Environment
* **Skipped Test (1):** `Tests\Feature\Database\MySql8SpatialIntegrationTest::test_mysql8_srid_4326_spatial_distance_and_fulltext_indexes`
  * **Reason:** Docker and MySQL 8.0 are not currently installed/running on this Windows host (XAMPP provides MariaDB 10.4.32, which lacks MySQL 8.0 SRID 4326 spatial semantics).
  * **Action Required:** Start MySQL 8.0 via `docker compose up -d mysql` (or configure a local MySQL 8.0 test database) and re-run `php artisan test --filter=MySql8SpatialIntegrationTest` to execute the live SRID 4326 `ST_Distance_Sphere` and Tamil `FULLTEXT` integration assertions.
