# 15 - Implementation Progress Tracker

## 1. Current Status Summary

* **Active Phase:** Phase 3A — Foundation & Core Platform
* **Current Milestone Completed:** **Milestone 3 — Identity Domain (Secure OTP Auth, Sanctum Bearer Tokens, Device/Session Management & Independent RBAC/Policies)**
* **Next Planned Milestone:** **Milestone 4 — Single Next.js App Router Foundation (`frontend/`) with `(customer)` & `(business)` Route Groups, Tailwind CSS, and `HttpOnly` BFF Cookie Bridge** (awaiting approval to proceed)
* **Stack Compliance Check:**
  * Laravel Framework: `v13.43.0` (PHP `8.3.36`)
  * Bearer Auth: `laravel/sanctum v4.5.0` (custom `PersonalAccessToken` with ULID `public_id` & `device_public_id`)
  * Redis Client: `predis/predis v3.7.1`
  * Filament Installed: **None** (verified automatically by `ApiFoundationTest::test_composer_contains_no_filament_dependencies`)

---

## 2. Milestone Log

| Milestone | Scope | Status | Verification Evidence | Git Commit |
| :--- | :--- | :--- | :--- | :--- |
| **Phase 1** | Website Discovery & Analysis (`docs/01`–`06`) | Completed | Live probes, `robots.txt` audit, REA inspection of `webfont.min.js`, 6 docs created | `b1760e5` |
| **Phase 2** | System Architecture, ERD, API, Search, SEO, Security & Roadmap (`docs/07`–`14`) + 7 final approval updates | Completed | All 8 architecture docs updated with BFF isolation, independent Policies, English/Tamil search benchmarks, configurable privacy retention + legal exceptions, webhook idempotency/retry, SEO thin-page/sitemap parity, DB restore/RPO/RTO | `b1760e5` |
| **Phase 3A — M1** | Project structure, Docker Compose (MySQL 8.0, Redis 7, MinIO, Mailpit), `backend/` Laravel 13 REST API foundation, standardized Flutter-compatible JSON envelopes, exception renderer, middleware, `config/privacy.php`, `config/directory.php`, `/api/v1/health`, `/api/v1/meta`, and custom Blade `/admin/health` foundation | **Completed & Verified** | `php artisan test` (SQLite): **14 passed (106 assertions)**; `vendor/bin/pint --test`: **passed** | `d739be7` |
| **Phase 3A — M2** | Core Database Migrations (Identity, RBAC, OTP Challenges, User Devices, Geography with English/Tamil translations, Categories & Subcategories with English/Tamil translations & Tanglish synonyms), ULID & Spatial traits, Factories, Idempotent Reference Seeders, Isolated Non-Production `DevSampleSeeder`, and Database Constraint/Relationship Tests | **Completed & Verified** | Clean `migrate:fresh --seed`, re-run `migrate`, rollback + re-migrate passed; `php artisan test` (SQLite): **23 passed (173 assertions)**; MySQL 8.0 spatial test: **1 skipped**; `vendor/bin/pint --test`: **passed** | `46fbf8e` |
| **Phase 3A — M3** | Identity Domain: CSPRNG 6-digit OTP (`random_int`), HMAC-SHA256 verification bound to `(phone_e164, purpose, otp)`, atomic single-use consumption, resend cooldown, attempt lockout, multi-dimensional rate limiting & country allowlist, Sanctum Bearer token issuance & expiration, device registration & device-scoped revocation, session listing/revocation, independent Laravel Policies (`UserPolicy`, `UserDevicePolicy`, `PersonalAccessTokenPolicy`), live DB RBAC middleware (`RequireRole`, `RequirePermission`, `EnsureActiveUser`), zero plaintext OTP logging, and zero self-elevation to admin roles | **Completed & Verified** | Clean migration + rollback passed; `php artisan test` (SQLite + mocked `FakeOtpSender`): **34 passed (321 assertions)**; MySQL 8.0 spatial test: **1 skipped**; `vendor/bin/pint --test`: **passed** | *(Milestone 3 commit)* |
| **Phase 3A — M4** | Single Next.js App Router Foundation (`frontend/`) with `(customer)` & `(business)` route groups, Tailwind CSS, and `HttpOnly` BFF Cookie Bridge | Pending | Not started | — |
| **Phase 3B** | Directory Core, MySQL 8 Spatial + FullText Search (English/Tamil), Autocomplete, Business Profiles & Media Pipeline | Pending | Not started | — |
| **Phase 3C** | Privacy-Compliant Enquiries, Reviews & Business Owner Dashboard | Pending | Not started | — |
| **Phase 3D** | Custom Laravel Blade Admin Dashboard (Session Guard, TOTP 2FA, KYC Queue, Moderation, Audit Logs) | Pending | Not started | — |
| **Phase 3E** | Payment Integration (Webhooks + Idempotency + Invoices), Sponsored Placements, Sitemaps & Production Hardening | Pending | Not started | — |

---

## 3. Milestone 3 Verification Details

### 3.1 Implemented Identity Components
* **Additive Migration:** `2026_11_18_090001_add_public_ids_to_tokens_and_devices.php` adds ULID `public_id` to `user_devices` and `personal_access_tokens` plus `device_public_id` link for device-scoped token revocation without exposing internal integer IDs.
* **Domain Contracts, Enums & Services (`app/Domains/Identity/`):**
  * `Enums/OtpPurpose.php`, `Contracts/OtpSender.php`, `Services/NullOtpSender.php` (masks phone, never logs OTPs), `Support/PhoneNormalizer.php` (E.164 + country prefix allowlist), `Exceptions/IdentityException.php`.
  * `Services/OtpChallengeService.php`: `random_int(100000, 999999)`, HMAC-SHA256(`phone|purpose|otp`, pepper), per-phone 10m/24h & per-IP 1h/10m rate limits, 45s resend cooldown (`Retry-After`), persisted failed attempt counters (max 5 -> `429 OTP_MAX_ATTEMPTS`), and atomic single-use consumption (`lockForUpdate()` + conditional `whereNull('verified_at')->update(...) === 1`).
  * `Services/AuthTokenService.php`: Registration/login attaching `customer` role only (never grants admin roles or admin Bearer abilities), blocks suspended accounts, binds devices to Sanctum tokens, supports device-specific & session-specific revocation.
* **Independent Authorization Layer:**
  * Policies: `UserPolicy`, `UserDevicePolicy`, `PersonalAccessTokenPolicy` registered explicitly in `AppServiceProvider` and enforced independently of Sanctum token abilities.
  * Middleware: `EnsureActiveUser` (`active`), `RequireRole` (`role`), `RequirePermission` (`permission`) querying live database roles/permissions regardless of token abilities.
* **Versioned REST Endpoints (`/api/v1/auth/*`):**
  * Public: `POST /otp/request`, `POST /otp/resend`, `POST /otp/verify`
  * Authenticated (`auth:sanctum`, `active`): `GET /me`, `PATCH /me`, `POST /logout`, `POST /logout-all`, `GET /devices`, `POST /devices`, `DELETE /devices/{userDevice}`, `GET /sessions`, `DELETE /sessions/{session}`.
* **Resources (`app/Http/Resources/Api/V1/Identity/`):** `UserResource`, `UserDeviceResource`, `SessionTokenResource` — strictly omit internal integer `id`, `password`, `code_hash`, `totp_secret_encrypted`, raw `fcm_token`, and hashed Sanctum `token`.

### 3.2 Test Results Separation (SQLite Unit/Feature vs. MySQL 8.0 Integration)
* **SQLite Unit & Feature Test Suite (`php artisan test` with mocked `FakeOtpSender` — zero real SMS sent):**
  * **34 passed (321 assertions)** across:
    * `ApiResponseTest` (2)
    * `ApiFoundationTest` (10)
    * `OtpAuthenticationTest` (7 — registration/login, anti-enumeration & privilege escalation guard, invalid/wrong-binding/expired OTP, atomic single-use & concurrent replay, resend cooldown & 5-attempt lockout, multi-dimensional rate limits & country allowlist, zero plaintext OTP logging)
    * `SessionDeviceAndAuthorizationTest` (4 — token expiration/logout/logout-all/suspended block, device registration & device-specific revocation, cross-user IDOR blocked by Policies even with wildcard `['*']` tokens, live DB `RequireRole`/`RequirePermission` middleware independently of token abilities)
    * `CoreSchemaAndConstraintsTest` (9)
    * `ExampleTest` (2)
* **MySQL 8.0 Integration Tests (Not Yet Executed Locally):**
  * **1 skipped:** `MySql8SpatialIntegrationTest::test_mysql8_srid_4326_spatial_distance_and_fulltext_indexes` (Docker / MySQL 8.0 not installed/running on this local host).
  * **Important:** MySQL 8.0-specific concurrency row-locking (`SELECT ... FOR UPDATE` under true multi-connection contention), `SRID 4326` spatial indexing, and `FULLTEXT` Tamil matching have **not** yet been verified against a live MySQL 8.0 instance and remain pending MySQL 8.0 / Docker provisioning.
