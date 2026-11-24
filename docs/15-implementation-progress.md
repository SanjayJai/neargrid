# 15 - Implementation Progress Tracker

## 1. Current Status Summary

* **Active Phase:** Stage 1 — Planning Customer Notifications & Phase 3D Monetization
* **Current Milestone Completed:** **Phase 3C (Milestones 1–4 + Audit Corrections)**
* **Latest Verified Commit:** `f62f1df` on branch `main`
* **Stack Compliance Check:**
  * Backend: Laravel `v13.43.0` (PHP `8.3.36`), `laravel/sanctum v4.5.0`, `predis/predis v3.7.1`, `league/flysystem-aws-s3-v3 v3.35.3`, **Zero Filament**
  * Frontend: Single Next.js `14.2.35` (App Router) + React `18.3.1` + TypeScript `5.7.2` + Tailwind CSS `3.4.19` + `ioredis` (All 31 routes compiled statically/dynamically with zero TypeScript errors)
  * Location Architecture: State → City → Locality (no country management; launch location: Vellore)

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
| **Phase 3B — M5** | Business Directory Backend: Businesses, multi-business ownership, categories/services/amenities/payment methods/FAQs, addresses & SRID 4326 spatial columns, split-shift & midnight opening hours, encrypted KYC verifications (admin-separated), ownership claim workflow (separated from KYC verification), staff memberships & granular capabilities, `url_redirects` (301), `audit_logs`, independent Laravel Policies, privacy-safe `PublicBusinessResource`, Owner management APIs, EN/TA reference APIs, and frontend DTO contracts (`directory-types.ts`) | **Completed & Verified** | Clean 12-migration `migrate:fresh --seed`, re-run, rollback + re-migrate passed; Backend SQLite tests: **43 passed (456 assertions)**; MySQL 8.0 integration tests skipped: **2**; Pint: **passed**; Frontend `tsc`: **0 errors**; Frontend Vitest: **8 passed** | `f00e875` |
| **Phase 3B — M6** | Secure Media Pipeline: `business_media` & `kyc_documents` migrations/models (ULIDs), `league/flysystem-aws-s3-v3` S3/MinIO disk separation (`media_public` vs `kyc_private`), magic-byte signature validation (`FileSignatureValidator`), GD WebP transcoding (`thumb`/`card`/`full`) & EXIF/GPS stripping (`ImageProcessor`), configurable original preservation (`false` default), async `ProcessBusinessMediaJob` on `media` queue with retries & failure cleanup, actor-bound short-lived signed KYC downloads (`60s` TTL), `BusinessMediaPolicy` & `KycDocumentPolicy`, `PublicBusinessResource` approved-only media integration, and frontend media/KYC DTOs | **Completed & Verified** | Clean 13-migration `migrate:fresh --seed`, rollback + re-migrate passed; Backend SQLite tests: **52 passed (613 assertions)**; Skipped: **3** (2 MySQL 8.0 + 1 live MinIO/S3); Pint: **passed**; Frontend `tsc`: **0 errors**; Frontend Vitest: **8 passed** | `3034955` |
| **Phase 3B — M7** | Search Engine: Modular `Search` domain (`SearchEngineInterface` Meilisearch seam + `MysqlSearchEngine`), `search_synonyms` / `sponsored_placements` / `search_query_logs` migration & `SearchSynonymsSeeder` (EN/TA/Tanglish), Unicode NFC + synonym resolver, MySQL 8 `FULLTEXT` + SRID 4326 `MBRContains`/`ST_Distance_Sphere` spatial retrieval, configurable composite + Bayesian damped ranking, isolated Sponsored placement selector with quality/publication gates, Redis-backed autocomplete with DB fallback & rate limiting, SEO canonical/noindex advisor, `GET /api/v1/search`, `/nearby`, `/suggestions`, `/filters`, `docs/17`, and frontend search DTOs | **Completed & Verified** | Clean 14-migration `migrate:fresh --seed`, rollback + re-migrate passed; Backend SQLite tests: **66 passed (785 assertions)**; Failed: **0**; Skipped: **5** (3 MySQL 8.0 + 1 live MinIO/S3 + 1 live Redis 7); Pint: **passed**; Frontend `tsc`: **0 errors**; Frontend Vitest: **8 passed** | `58739ec` |
| **Phase 3B — M8** | Live Frontend Integration: Wires NearGrid `(customer)` & `(business)` views to live Directory/Media/Search APIs via server-side BFF, removing demo banners; real category grids, dual-axis autocomplete search, canonical directory pages, responsive photo lightbox, and full merchant dashboard CRUD | **Completed & Verified** | Frontend Vitest: **84 passed**; `next build`: **30/30 routes**; Responsive browser verification: desktop (`1280px`), tablet (`768px`), mobile (`375px`) | `07c1b52` |
| **Phase 3C — M1** | Consented Enquiries & Lead Dispatching: Consent-mandatory `POST /api/v1/enquiries`, async `DispatchEnquiryLeadsJob`, phone reveal logging, enquiry transparency portal | **Completed & Verified** | Backend tests: **82 passed**; E2E enquiry flow verified | `a0e2cf7` |
| **Phase 3C — M2** | Business Owner CRM: Inbound leads listing, lead status pipeline updates, staff assignments, private merchant notes, read tracking, and cross-merchant IDOR isolation | **Completed & Verified** | Backend tests: **91 passed**; Owner CRM tests verified | `a0e2cf7` |
| **Phase 3C — M3** | Customer Reviews & Bayesian Ratings: Atomic Redis-locked review submission (30-day duplicate protection), verified customer badge qualification, encrypted OTP queues, owner replies, Bayesian rating recalculation | **Completed & Verified** | Backend tests: **98 passed**; Audit corrections applied | `cc0a257` |
| **Phase 3C — M4** | Super Admin APIs & Signed Realtime ISR Revalidation: `/api/v1/admin/*` endpoints (Enquiries, Reviews moderation, KYC verifications queue with 60s actor-bound signed URLs, User suspension & session revocation, Immutable audit logs), Next.js signed HMAC-SHA256 webhook `/api/revalidate`, and service/media/directory invalidation triggers | **Completed & Verified** | Backend tests: **105 passed, 5 skipped**; Frontend Vitest: **113 passed**; `next build`: **31/31 routes**; E2E workflow: **8/8 steps passed** | `f62f1df` |
| **Phase 3D** | Monetization, Subscriptions, Razorpay/Stripe, Sponsored Placements | Pending | Scheduled for Stage 1 | — |
| **Phase 3E** | Production Hardening, Automated XML Sitemaps, Backup/DR Drills | Pending | Scheduled for Stage 1 | — |
| **Phase 3F** | Next.js Super Admin Dashboard (`src/app/(admin)/*`, zero Filament) | Pending | Scheduled for Stage 1 | — |

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

---

## 4. Milestone 6 Implementation & Verification Summary

### 4.1 Backend Components Delivered
* **Storage Configuration & Dependency:**
  * Installed `league/flysystem-aws-s3-v3` (`^3.35`) for MinIO/S3-compatible object storage.
  * Configured separate `media_public` and `kyc_private` disks in `backend/config/filesystems.php` and centralized pipeline limits/variants/queues in `backend/config/media.php` and `backend/.env.example`.
* **Schema & Models:**
  * `2026_11_18_110001_create_media_tables.php`: `business_media` (ULID `public_id`, `media_type`, `upload_status`, `moderation_status`, `disk`, staging/original/variant keys, MIME, size, dimensions, `exif_stripped`, `failure_reason`, `processed_at`) and `kyc_documents` (ULID `public_id`, optional FK to `business_verifications`, private `disk`/`storage_key`, MIME, size, dimensions, `sha256_checksum`).
  * `BusinessMedia` & `KycDocument` models hide all internal integer IDs, disk names, and raw storage keys from default serialization.
* **Upload Security & Image Pipeline:**
  * `FileSignatureValidator`: Strictly validates raw magic bytes (JPEG/PNG/WebP; PDF for KYC), rejects executables/scripts/SVG/HTML/ZIP (`MZ`, `ELF`, `#!`, `<?php`, `<script`, `<svg`, `<!doctype`, `<html`, `PK\x03\x04`), cross-checks `finfo`, verifies GD decode + pixel bounds or PDF `%%EOF` structure, and enforces configurable byte (`413 FILE_TOO_LARGE`) and count limits without ever trusting client filenames, extensions, or MIME headers.
  * `ImageProcessor`: Decodes through GD, auto-applies EXIF orientation before stripping 100% of EXIF/GPS/XMP metadata, and encodes clean `thumb` (`<=320w`), `card` (`<=640w`), and `full` (`<=1280w`) WebP variants without upscaling (preserving PNG/WebP transparency).
  * `ProcessBusinessMediaJob`: Queued on `media` queue (`$tries = 4`, backoff `[30, 120, 600]`, `$timeout = 120`), cleans up partial variant writes on transient storage errors, removes staged originals unless `media.preserve_originals=true`, marks permanent failures with sanitized `failure_reason` + staging cleanup, and updates `businesses.photo_count` and `completeness_score`.
* **Authorization, Private KYC Signed Access & Public Storefront Integration:**
  * Added `media.manage` capability (`Owner`, `Manager`, `Editor`; denied to `LeadAgent`), `BusinessPolicy::manageMedia`, `BusinessMediaPolicy`, and `KycDocumentPolicy` (independent of Sanctum token abilities).
  * REST Endpoints (`/api/v1/*`):
    * Public Business Media: `GET/POST /owner/businesses/{business}/media`, `DELETE /owner/businesses/{business}/media/{media}`.
    * Private KYC Documents: `GET/POST /owner/businesses/{business}/kyc-documents`, `POST /owner/businesses/{business}/kyc-documents/{kycDocument}/signed-url`, `DELETE /owner/businesses/{business}/kyc-documents/{kycDocument}`.
    * Signed Private Download: `GET /kyc-documents/{kycDocument}/download` (`auth:sanctum`, `active`, `signed`, actor-bound `?actor={user_public_id}`, live `KycDocumentPolicy::download`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`, audited).
  * Updated `PublicBusinessResource` and `PublicBusinessController::show()` to expose ONLY `ready` + `approved` public media URLs and zero private KYC fields or raw storage keys.
* **Frontend DTO Preparation:** Added `PublicBusinessMediaDto`, `OwnerBusinessMediaDto`, `KycDocumentDto`, and `KycSignedDownloadUrlDto` in `frontend/src/shared/api/directory-types.ts`.

### 4.2 Test Counts & Known Local Limitations
* **Backend Automated Tests (`php artisan test` on SQLite + `Storage::fake`):** **52 passed (613 assertions)**.
  * New Milestone 6 tests: `BusinessMediaPipelineTest` (6) + `KycDocumentSecurityTest` (3).
* **Skipped Live Infrastructure Integration Tests (3):**
  1. `MySql8SpatialIntegrationTest::test_mysql8_srid_4326_spatial_distance_and_fulltext_indexes` (no local MySQL 8.0/Docker).
  2. `MySql8DirectoryIntegrationTest::test_mysql8_business_location_point_srid_4326_and_fulltext_index` (no local MySQL 8.0/Docker).
  3. `MinioS3IntegrationTest::test_live_minio_s3_public_and_private_bucket_separation` (no local MinIO/Docker running; `MEDIA_PUBLIC_DRIVER` and `MEDIA_KYC_DRIVER` default to `local` outside Docker/CI).
* **Migrations, Style & Frontend Checks:**
  * Clean 13-migration `migrate:fresh --seed`, `migrate:rollback --step=1`, and re-`migrate` passed.
  * `vendor/bin/pint --test` passed; Filament check passed (`0` Filament dependencies).
  * Frontend `tsc --noEmit` (`0` errors) and Vitest (`8 passed`).

---

## 5. Milestone 7 Implementation & Verification Summary

### 5.1 Backend Components Delivered
* **Architecture & Upgrade Seam:** `SearchEngineInterface` bound to `MysqlSearchEngine` in `AppServiceProvider`, allowing Stage 2 Meilisearch introduction with zero API contract changes.
* **Schema, Indexes & Reference Seeding:**
  * `2026_11_18_120001_create_search_engine_tables.php`: `search_synonyms`, `sponsored_placements` (ULID), `search_query_logs` (PII-free), `businesses` composite index `idx_businesses_search_active_city_name`, and MySQL 8 `FULLTEXT INDEX idx_biz_services_fulltext (name, description)`.
  * `SearchSynonymsSeeder`: Idempotent English, Tamil Unicode, and Tanglish synonym mappings wired into `DatabaseSeeder`.
* **Modular Domain Services (`backend/app/Domains/Search/`):**
  * `QueryNormalizerAndSynonymResolver`: Unicode NFC normalization, control-char stripping, original display query preservation, Tamil inflection stem handling, and DB-driven EN/TA/Tanglish + parent-category expansion.
  * `GeoSpatialHelper`: Exact WGS-84 Haversine distance and MySQL 8 SRID 4326 lat-first WKT point/bounding-polygon builders.
  * `SearchRankingService`: Configurable composite scoring (`text`, `category_service`, `geo`, `rating`, `trust`, `completeness`, `recency`), Bayesian damped rating (`m=10`, `C=3.5`), strict `SearchSortOption` allowlist, and deterministic tie-breaking.
  * `SponsoredPlacementSelector`: Isolated Page-1 paid placement query enforcing `status=active`, active schedule, remaining budget, `businesses.status=active`, `completeness_score >= 40`, rating floor (`>= 3.0` when `>= 5` reviews), mandatory `"Sponsored"` disclosure, and Page 1 organic deduplication.
  * `AutocompleteService` & `FilterOptionsService`: Redis-backed caching with automatic DB fallback on Redis unavailability, minimum query length (`2`), active-only privacy enforcement, and rate limiting (`throttle:search`, `throttle:search-suggestions`).
  * `SearchSeoAdvisor` & `SearchPerformanceLogger`: Canonical path/URL rules, thin-page/filtered `X-Robots-Tag: noindex, follow` headers, cache headers, and privacy-safe `search_query_logs` + slow-query warning logs.
* **Public Search API (`/api/v1/search*`):** `GET /search`, `GET /search/nearby`, `GET /search/suggestions`, `GET /search/filters`, documented in `docs/17-search-engine-implementation-and-api.md`, with matching TypeScript DTOs in `frontend/src/shared/api/directory-types.ts`.

### 5.2 Test Counts & Infrastructure Limitations
* **Backend Automated Tests (`php artisan test` on SQLite + `array` cache):** **66 passed (785 assertions)**, **0 failed**.
  * New Milestone 7 tests: `SearchEngineTest` (10) + `AutocompleteAndFiltersTest` (4).
* **Skipped Live Infrastructure Integration Tests (5):**
  1. `MySql8SpatialIntegrationTest::test_mysql8_srid_4326_spatial_distance_and_fulltext_indexes` (no local MySQL 8.0 / Docker).
  2. `MySql8DirectoryIntegrationTest::test_mysql8_business_location_point_srid_4326_and_fulltext_index` (no local MySQL 8.0 / Docker).
  3. `MySql8SearchEngineIntegrationTest::test_mysql8_fulltext_tamil_tanglish_and_spatial_index_execution` (no local MySQL 8.0 / Docker — SQLite tests do NOT prove MySQL 8 `FULLTEXT` or `SRID 4326` `EXPLAIN` plans).
  4. `MinioS3IntegrationTest::test_live_minio_s3_public_and_private_bucket_separation` (no local MinIO / Docker).
  5. `RedisAutocompleteIntegrationTest::test_live_redis_autocomplete_caching` (no local Redis 7 server running at `127.0.0.1:6379`).
* **Migrations, Style & Frontend Checks:**
  * Clean 14-migration `migrate:fresh --seed`, `migrate:rollback --step=1`, and re-`migrate` passed.
  * `vendor/bin/pint --test` passed; Filament check passed (`0` Filament dependencies).
  * Frontend `tsc --noEmit` (`0` errors) and Vitest (`8 passed`).
