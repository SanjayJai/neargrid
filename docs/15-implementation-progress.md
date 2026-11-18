# 15 - Implementation Progress Tracker

## 1. Current Status Summary

* **Active Phase:** Phase 3B — Business Directory Core, Media, Search & Live Integration
* **Current Milestone Completed:** **Milestone 5 — Business Directory Backend**
* **Next Planned Milestone:** **Milestone 6 — Media Pipeline (S3/MinIO Public + Private KYC Buckets, EXIF Stripping, Responsive WebP Transcoding)** (awaiting approval to proceed)
* **Stack Compliance Check:**
  * Backend: Laravel `v13.43.0` (PHP `8.3.36`), `laravel/sanctum v4.5.0`, `predis/predis v3.7.1`, **Zero Filament**
  * Frontend: Single Next.js `14.2.35` (App Router) + React `18.3.1` + TypeScript `5.7.2` + Tailwind CSS `3.4.19` + `ioredis` (Demo UI preserved honestly until Milestone 8; typed Directory DTO contracts prepared in `frontend/src/shared/api/directory-types.ts`)

---

## 2. Milestone Log

| Milestone | Scope | Status | Verification Evidence | Git Commit |
| :--- | :--- | :--- | :--- | :--- |
| **Phase 1** | Website Discovery & Analysis (`docs/01`–`06`) | Completed | Live probes, `robots.txt` audit, REA inspection of `webfont.min.js`, 6 docs created | `b1760e5` |
| **Phase 2** | System Architecture, ERD, API, Search, SEO, Security & Roadmap (`docs/07`–`14`) + 7 final approval updates | Completed | All 8 architecture docs updated | `b1760e5` |
| **Phase 3A — M1** | Project structure, Docker Compose (MySQL 8.0, Redis 7, MinIO, Mailpit), `backend/` Laravel 13 REST API foundation, envelopes, exception renderer, middleware, `config/privacy.php`, `/api/v1/health`, `/api/v1/meta`, Blade `/admin/health` | **Completed & Verified** | SQLite tests: **14 passed (106 assertions)**; Pint: **passed** | `d739be7` |
| **Phase 3A — M2** | Core Database Migrations (Identity, RBAC, OTP, Devices, Geography & Categories with EN/TA translations), Factories, Idempotent Reference Seeders, Isolated `DevSampleSeeder` | **Completed & Verified** | SQLite tests: **23 passed (173 assertions)**; MySQL 8.0 skipped: **1**; Pint: **passed** | `46fbf8e` |
| **Phase 3A — M3** | Identity Domain: CSPRNG OTP, HMAC-SHA256, atomic single-use consumption, cooldown/rate limits, Sanctum Bearer tokens, device & session revocation, independent Laravel Policies & live DB RBAC middleware | **Completed & Verified** | SQLite tests: **34 passed (321 assertions)**; MySQL 8.0 skipped: **1**; Pint: **passed** | `e79a2b2` |
| **Phase 3A — M4** | Single Next.js 14 + React 18 + Tailwind app (`frontend/`) with `(customer)` & `(business)` route groups, NearGrid UI, opaque HttpOnly BFF cookie + AES-256-GCM Redis session store, Origin + CSRF guards, live Auth/Settings wiring, Demo views (`docs/16`) | **Completed & Verified** | Vitest: **8 passed**; `tsc`: **0 errors**; `lint`: **0 errors**; `next build`: **17/17 routes**; live HTTP smoke test passed | `3010643` |
| **Phase 3B — M5** | Business Directory Backend: Businesses, multi-business ownership, categories/services/amenities/payment methods/FAQs, addresses & SRID 4326 spatial columns, split-shift & midnight opening hours, encrypted KYC verifications (admin-separated), ownership claim workflow (separated from KYC verification), staff memberships & granular capabilities, `url_redirects` (301), `audit_logs`, independent Laravel Policies, privacy-safe `PublicBusinessResource`, Owner management APIs, EN/TA reference APIs, and frontend DTO contracts (`directory-types.ts`) | **Completed & Verified** | Clean 12-migration `migrate:fresh --seed`, re-run, rollback + re-migrate passed; Backend SQLite tests: **43 passed (456 assertions)**; MySQL 8.0 integration tests skipped: **2**; Pint: **passed**; Frontend `tsc`: **0 errors**; Frontend Vitest: **8 passed** | *(Milestone 5 commit)* |
| **Phase 3B — M6** | Media Pipeline (MinIO/S3 public + private KYC buckets, EXIF stripping, WebP transcoding, signed URLs) | Pending | Not started | — |
| **Phase 3B — M7** | Search Engine (MySQL 8.0 Spatial + English/Tamil FullText, Bayesian ranking, Sponsored slots, Autocomplete & Benchmarks) | Pending | Not started | — |
| **Phase 3B — M8** | Live Frontend Integration (wire NearGrid `(customer)` & `(business)` views to live Directory/Media/Search APIs via BFF, removing demo banners) | Pending | Not started | — |
| **Phase 3C–3E** | Consented Enquiries, Reviews, Custom Blade Admin (2FA + KYC Queue), Payments/Webhooks & Production Hardening | Pending | Not started | — |

---

## 3. Milestone 5 Implementation & Verification Summary

### 3.1 Backend Components Delivered
* **Migrations:**
  * `2026_11_18_100001_create_attributes_and_governance_tables.php`: `amenities`, `payment_methods`, `url_redirects`, `audit_logs`.
  * `2026_11_18_100002_create_businesses_core_tables.php`: `businesses` (ULID `public_id`, `UNIQUE(city_id, slug)`, MySQL 8.0 `location_point POINT NOT NULL SRID 4326` + `SPATIAL INDEX` + `FULLTEXT INDEX`), `business_categories`, `business_hours` (`UNIQUE(business_id, day_of_week, slot_index)`), `business_services` (ULID), `business_amenities`, `business_payment_methods`, `business_faqs`.
  * `2026_11_18_100003_create_business_members_verifications_and_claims_tables.php`: `business_members` (ULID, `UNIQUE(business_id, user_id)`), `business_verifications` (ULID, encrypted `document_number_encrypted`), `business_claims` (ULID).
* **Reference Seeder Added:** `AttributesSeeder` (amenities & payment methods) wired idempotently into `DatabaseSeeder`.
* **Domain Services & Audit Logging:** `AuditLogger`, `BusinessCompletenessCalculator`, `BusinessManagementService`, `BusinessVerificationAndClaimService` (admin-only `reviewVerification`, `reviewClaim`, `changePublicationStatus` enforcing strict separation between ownership claim approval and KYC verification).
* **Independent Policies:** `BusinessPolicy`, `BusinessServicePolicy`, `BusinessMemberPolicy` enforced independently of Sanctum token abilities.
* **REST Endpoints (`/api/v1/*`):**
  * Public Reference: `GET /locations/cities`, `GET /locations/cities/{citySlug}/localities`, `GET /categories`, `GET /attributes` (supporting `?locale=en|ta`).
  * Public Storefront: `GET /businesses/{citySlug}/{businessSlug}` (active-only, `301` redirect lookup, `PublicBusinessResource` stripping all private owner/KYC/staff/raw-phone fields).
  * Authenticated Claim & Owner/Staff APIs: `POST /businesses/{business}/claims`, `GET/POST /owner/businesses`, `GET/PUT /owner/businesses/{business}`, `PUT /owner/businesses/{business}/hours`, `POST/PUT/DELETE /owner/businesses/{business}/services/{service}`, `GET/POST/PATCH/DELETE /owner/businesses/{business}/members/{member}`, `GET/POST /owner/businesses/{business}/verifications`.
* **Frontend Preparation:** Added `frontend/src/shared/api/directory-types.ts` without removing or falsifying demo data on existing UI screens.

### 3.2 Test Counts & Skipped MySQL 8.0 Integration Tests
* **Backend Automated Tests (`php artisan test` on SQLite):** **43 passed (456 assertions)** in `3.74s`.
  * New Milestone 5 tests: `BusinessDirectoryManagementTest` (5) + `PublicProfileVerificationClaimTest` (4).
* **Backend MySQL 8.0 Integration Tests Skipped (2):**
  1. `MySql8SpatialIntegrationTest::test_mysql8_srid_4326_spatial_distance_and_fulltext_indexes`
  2. `MySql8DirectoryIntegrationTest::test_mysql8_business_location_point_srid_4326_and_fulltext_index`
  * **Known Limitation:** Actual MySQL 8.0 and Docker are not installed on this local Windows machine (XAMPP has MariaDB 10.4.32, which is explicitly rejected by `MySql8DirectoryIntegrationTest`). Actual MySQL 8.0 `SRID 4326` spatial, `SPATIAL INDEX`, and `FULLTEXT` execution remains unverified until MySQL 8.0 is provisioned locally or in CI.
* **Backend Code Style (`vendor/bin/pint --test`):** **Passed**.
* **Frontend Checks:** `tsc --noEmit` (**0 errors**), `vitest run` (**8 passed**).
