# 16 - Frontend Architecture, Routes & Component Inventory

## 1. Overview

The `frontend/` application is a single **Next.js 14.2.35 (App Router) + React 18.3.1 + TypeScript 5.7 + Tailwind CSS 3.4** web application serving both public customer discovery and the business-owner dashboard via isolated Route Groups. The custom **Laravel Blade Admin Dashboard** remains strictly separate in `backend/` at `/admin`.

---

## 2. Route Inventory

### 2.1 `(customer)` Route Group — Public Discovery Website

| Route | File | Backend Connection Status | SEO / Headers |
| :--- | :--- | :--- | :--- |
| `/` | `src/app/(customer)/page.tsx` | **Demo Data** (featured listings, categories, cities) + **Live BFF** header session state | `index, follow`, `X-Frame-Options: DENY` |
| `/search` | `src/app/(customer)/search/page.tsx` | **Demo Data** (client-side filters & sort preview) | `noindex, follow` |
| `/[city]/business/[slug]` | `src/app/(customer)/[city]/business/[slug]/page.tsx` | **Demo Data** (profile, hours, services, reviews, consent enquiry preview) | `index, follow` |
| `/login` | `src/app/(customer)/login/page.tsx` | **Live Connected** to Laravel 13 `/api/v1/auth/otp/*` via Next.js BFF | `noindex, nofollow` |

### 2.2 `(business)` Route Group — Merchant Dashboard (`X-Robots-Tag: noindex, nofollow`)

| Route | File | Backend Connection Status | Description |
| :--- | :--- | :--- | :--- |
| `/dashboard` | `src/app/(business)/dashboard/page.tsx` | **Demo Data** KPIs & recent leads + **Live BFF** auth banner | Merchant workspace overview |
| `/dashboard/profile` | `src/app/(business)/dashboard/profile/page.tsx` | **Demo Data** (local preview state) | Storefront trading name, category, city/locality, address, description |
| `/dashboard/media-services` | `src/app/(business)/dashboard/media-services/page.tsx` | **Demo Data** (local preview state) | Storefront gallery slots & interactive services/pricing rows |
| `/dashboard/hours` | `src/app/(business)/dashboard/hours/page.tsx` | **Demo Data** (local preview state) | Weekly Mon–Sun hours, closed toggle, 24h toggle |
| `/dashboard/enquiries` | `src/app/(business)/dashboard/enquiries/page.tsx` | **Demo Data** (local preview state) | Consented lead pipeline (`New / Contacted / Quoted / Converted`) |
| `/dashboard/settings` | `src/app/(business)/dashboard/settings/page.tsx` | **Live Connected** to Laravel 13 `/api/v1/auth/me`, `/devices`, `/sessions`, `/logout-all` via Next.js BFF | Profile update, device registration/revocation, active Sanctum session revocation |

### 2.3 Next.js BFF Auth Route Handlers (`src/app/api/auth/*`)

All BFF routes map 1:1 to existing Laravel 13 Identity API endpoints (zero invented backend routes):

| BFF Route | Upstream Laravel Endpoint | Mutation Security |
| :--- | :--- | :--- |
| `GET /api/auth/csrf` | *(Next.js BFF local)* | Issues double-submit `lbd_csrf` cookie + token |
| `POST /api/auth/otp/request` | `POST /api/v1/auth/otp/request` | Origin + CSRF verified |
| `POST /api/auth/otp/resend` | `POST /api/v1/auth/otp/resend` | Origin + CSRF verified |
| `POST /api/auth/otp/verify` | `POST /api/v1/auth/otp/verify` | Origin + CSRF verified; stores encrypted Sanctum Bearer token in server-side Redis/session store, sets opaque HttpOnly `lbd_sid` cookie, **strips `access_token`** from browser response |
| `GET /api/auth/me` | `GET /api/v1/auth/me` | Reads opaque `lbd_sid` cookie; auto-clears session on upstream `401` |
| `PATCH /api/auth/me` | `PATCH /api/v1/auth/me` | Origin + CSRF verified + opaque `lbd_sid` |
| `POST /api/auth/logout` | `POST /api/v1/auth/logout` | Origin + CSRF verified; revokes upstream token, deletes server session, clears cookie |
| `POST /api/auth/logout-all` | `POST /api/v1/auth/logout-all` | Origin + CSRF verified; revokes all upstream tokens, deletes server session, clears cookie |
| `GET / POST /api/auth/devices` | `GET / POST /api/v1/auth/devices` | Origin + CSRF on POST + opaque `lbd_sid` |
| `DELETE /api/auth/devices/[publicId]` | `DELETE /api/v1/auth/devices/{userDevice}` | Origin + CSRF + opaque `lbd_sid` |
| `GET /api/auth/sessions` | `GET /api/v1/auth/sessions` | Opaque `lbd_sid` |
| `DELETE /api/auth/sessions/[sessionId]` | `DELETE /api/v1/auth/sessions/{session}` | Origin + CSRF + opaque `lbd_sid` |

---

## 3. Component & Module Architecture

```text
frontend/src/
├── lib/bff/                          # Server-only BFF authentication & security layer
│   ├── types.ts                      # API envelopes & strict server-vs-client DTO split
│   ├── session-store.ts              # Redis (ioredis) + AES-256-GCM encrypted token store (dev memory fallback; fail-closed in prod)
│   ├── csrf.ts                       # Origin/Referer + constant-time double-submit CSRF guard
│   ├── laravel-client.ts             # Server-only fetch attaching Authorization: Bearer
│   ├── session-cookie.ts             # HttpOnly opaque `lbd_sid` cookie + RSC `getServerAuthenticatedUser()`
│   ├── route-helpers.ts              # Shared error envelopes, 401 session invalidation & forwarding
│   └── __tests__/bff-security.test.ts# Automated Vitest security & zero-localStorage guardrails
├── modules/
│   ├── customer/components/          # CustomerHeader, CustomerFooter, HeroSearchForm, BusinessCard, SearchResultsClient, OtpLoginForm, EnquiryConsentPreview
│   └── business/components/          # BusinessShell, AccountSettingsClient
└── shared/
    ├── api/bff-client.ts             # Browser same-origin fetch with automatic CSRF token bootstrap
    ├── data/demo-directory.ts        # Clearly marked fictional demo data for Phase 3A visual preview
    └── ui/                           # Badge, DemoBanner
```

---

## 4. BFF Security Verification Summary

1. **Opaque Session Cookie:** Browser receives only a 256-bit random `lbd_sid` (`HttpOnly; Secure (prod); SameSite=Lax; Path=/`).
2. **Encrypted Server-Side Token Storage:** Sanctum Bearer tokens are encrypted via **AES-256-GCM** inside Redis (`neargrid:bff:sess:{sid}`) with TTL synchronized to token expiration. Production fails closed if `REDIS_URL` or `BFF_SESSION_SECRET` (`>= 32` chars) is absent.
3. **Zero Browser Token Exposure:** Verified via Vitest (`bff-security.test.ts`) that `access_token` is stripped before responding to the browser and zero `localStorage` calls exist anywhere in `frontend/src/`.
4. **Origin & CSRF Defense:** Verified both in unit tests and against live `next start` HTTP responses that cross-origin and missing-CSRF `POST` mutations return `403 Forbidden`.
