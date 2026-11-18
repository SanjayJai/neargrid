# 15 - Implementation Progress Tracker

## 1. Current Status Summary

* **Active Phase:** Phase 3A — Foundation & Core Platform (**ALL 4 MILESTONES COMPLETED**)
* **Current Milestone Completed:** **Milestone 4 — Single Next.js App Router Frontend Foundation (`frontend/`) & Secure Opaque-Session BFF**
* **Next Planned Phase:** **Phase 3B — Business Directory Core, MySQL 8.0 Spatial + English/Tamil Search Engine & Media Pipeline** (awaiting approval to proceed)
* **Stack Compliance Check:**
  * Backend: Laravel `v13.43.0` (PHP `8.3.36`), `laravel/sanctum v4.5.0`, `predis/predis v3.7.1`, **Zero Filament**
  * Frontend: Single Next.js `14.2.35` (App Router) + React `18.3.1` + TypeScript `5.7.2` + Tailwind CSS `3.4.19` + `ioredis` (`(customer)` and `(business)` route groups; custom Laravel Blade admin remains strictly at `/admin`)

---

## 2. Milestone Log

| Milestone | Scope | Status | Verification Evidence | Git Commit |
| :--- | :--- | :--- | :--- | :--- |
| **Phase 1** | Website Discovery & Analysis (`docs/01`–`06`) | Completed | Live probes, `robots.txt` audit, REA inspection of `webfont.min.js`, 6 docs created | `b1760e5` |
| **Phase 2** | System Architecture, ERD, API, Search, SEO, Security & Roadmap (`docs/07`–`14`) + 7 final approval updates | Completed | All 8 architecture docs updated with BFF isolation, independent Policies, English/Tamil search benchmarks, configurable privacy retention + legal exceptions, webhook idempotency/retry, SEO thin-page/sitemap parity, DB restore/RPO/RTO | `b1760e5` |
| **Phase 3A — M1** | Project structure, Docker Compose (MySQL 8.0, Redis 7, MinIO, Mailpit), `backend/` Laravel 13 REST API foundation, standardized Flutter-compatible JSON envelopes, exception renderer, middleware, `config/privacy.php`, `config/directory.php`, `/api/v1/health`, `/api/v1/meta`, and custom Blade `/admin/health` foundation | **Completed & Verified** | `php artisan test` (SQLite): **14 passed (106 assertions)**; `vendor/bin/pint --test`: **passed** | `d739be7` |
| **Phase 3A — M2** | Core Database Migrations (Identity, RBAC, OTP Challenges, User Devices, Geography with EN/TA translations, Categories & Subcategories with EN/TA translations & Tanglish synonyms), ULID & Spatial traits, Factories, Idempotent Reference Seeders, Isolated Non-Production `DevSampleSeeder`, and Database Constraint Tests | **Completed & Verified** | Clean `migrate:fresh --seed`, re-run `migrate`, rollback + re-migrate passed; `php artisan test` (SQLite): **23 passed (173 assertions)**; MySQL 8.0 spatial test: **1 skipped**; `vendor/bin/pint --test`: **passed** | `46fbf8e` |
| **Phase 3A — M3** | Identity Domain: CSPRNG 6-digit OTP, HMAC-SHA256 bound to `(phone, purpose, otp)`, atomic single-use consumption, resend cooldown, attempt lockout, multi-dimensional rate limits & country allowlist, Sanctum Bearer tokens, device registration & device-scoped revocation, session management, independent Laravel Policies & live DB RBAC middleware | **Completed & Verified** | Clean migration + rollback passed; `php artisan test` (SQLite + mocked `FakeOtpSender`): **34 passed (321 assertions)**; MySQL 8.0 spatial test: **1 skipped**; `vendor/bin/pint --test`: **passed** | `e79a2b2` |
| **Phase 3A — M4** | Single Next.js 14 (App Router) + React 18 + TypeScript + Tailwind CSS application in `frontend/` with isolated `(customer)` and `(business)` route groups, original NearGrid design system, opaque HttpOnly session cookie (`lbd_sid`) + AES-256-GCM encrypted Redis server-side session store, Origin + double-submit CSRF guards on all BFF mutations, live wiring to Laravel 13 `/api/v1/auth/*` (OTP login, account profile, devices, sessions, logout), and clearly labelled Demo Data for upcoming Directory/Search/Enquiry modules (`docs/16-frontend-architecture-and-routes.md`) | **Completed & Verified** | `npm test` (Vitest): **8 passed**; `npm run typecheck`: **0 errors**; `npm run lint`: **0 warnings/errors**; `npm run build`: **compiled 17/17 static & dynamic routes cleanly**; Live `next start` smoke test verified all 11 page routes (`200 OK`, `X-Frame-Options: DENY`, `noindex` on `/dashboard/*`) & BFF CSRF/Origin rejection (`403`) & unauth `/api/auth/me` (`401`) | *(Milestone 4 commit)* |
| **Phase 3B** | Directory Core, MySQL 8 Spatial + FullText Search (English/Tamil), Autocomplete, Business Profiles & Media Pipeline | Pending | Not started | — |
| **Phase 3C** | Privacy-Compliant Enquiries, Reviews & Business Owner Dashboard live API integration | Pending | Not started | — |
| **Phase 3D** | Custom Laravel Blade Admin Dashboard (Session Guard, TOTP 2FA, KYC Queue, Moderation, Audit Logs) | Pending | Not started | — |
| **Phase 3E** | Payment Integration (Webhooks + Idempotency + Invoices), Sponsored Placements, Sitemaps & Production Hardening | Pending | Not started | — |

---

## 3. Milestone 4 Verification & Honesty Matrix

### 3.1 Live Connected vs. Demo Data Boundaries
* **Live Connected (to existing Laravel 13 Identity API via Next.js BFF):**
  * `/login` (`POST /api/auth/otp/request`, `/resend`, `/verify`)
  * `/dashboard/settings` (`GET/PATCH /api/auth/me`, `GET/POST/DELETE /api/auth/devices`, `GET/DELETE /api/auth/sessions`, `POST /api/auth/logout-all`)
  * Header & Sidebar auth status + `POST /api/auth/logout`
* **Clearly Labelled Fictional Demo Data (Phase 3B/3C backend modules not yet built):**
  * Homepage featured businesses & category counts (`/`)
  * Client-side search & filter preview (`/search`)
  * Business profile showcase, services, hours, reviews & consent-first enquiry preview (`/[city]/business/[slug]`)
  * Business Dashboard Overview KPIs, Profile editor, Photos & Services, Opening Hours, and Enquiries CRM (`/dashboard`, `/dashboard/profile`, `/dashboard/media-services`, `/dashboard/hours`, `/dashboard/enquiries`)

### 3.2 Environment & Integration Notes
* **Frontend Automated Verification:** All 8 Vitest security tests, TypeScript check (`tsc --noEmit`), ESLint (`next lint`), Next.js production build (`next build`), and live HTTP smoke test (`next start`) passed locally on Node `v24.11.1`.
* **Redis & MySQL 8.0 Live Integration Pending:** Because Docker, MySQL 8.0, and Redis 7 are not yet installed/running on this local machine, `RedisBffSessionStore` (implemented with `ioredis` + AES-256-GCM encryption and fail-closed production check) and `MySql8SpatialIntegrationTest` remain pending live Docker/MySQL 8.0/Redis 7 runtime execution.
