# 10 - REST API Specification & Mobile Compatibility Contract

## 1. Executive Summary

This document defines the versioned REST API contract exposed by the **Laravel 13 Backend** (`/api/v1/*`). It serves three consumers simultaneously with zero client-specific branching:

1. **Next.js Customer Portal** (`(customer)` route group)
2. **Next.js Business Owner Dashboard** (`(business)` route group)
3. **Future Flutter Mobile Applications** (iOS & Android)

All admin operations remain outside `/api/v1/*` inside the session-authenticated **Laravel Blade Admin Dashboard** (`/admin/*`).

---

## 2. API Design Decisions & Alternatives

### Decision 1: URI Path Versioning (`/api/v1/...`)
* **Why Recommended:**
  * Explicitly cacheable at CDN/Nginx layers without complex `Vary` header matrices.
  * Trivial for Flutter mobile apps (which may lag several versions behind web deployments) to pin safely to `/api/v1` while `/api/v2` rolls out breaking changes with a documented deprecation sunset header (`Sunset`, `Deprecation`).
* **Alternatives Considered:**
  * *Accept Header Versioning (`application/vnd.dir.v1+json`):* Harder to debug in browser DevTools, curl, and CDN cache keys.
  * *Query Parameter Versioning (`?version=1`):* Pollutes query strings and breaks clean OpenAPI path mapping.

### Decision 2: RESTful JSON over HTTPS vs. GraphQL
* **Why Recommended:**
  * Native fit with Laravel 13 (`FormRequest`, `JsonResource`, route middleware, HTTP caching, ETag/304 support).
  * Predictable query performance (prevents arbitrary N+1 client-crafted deep graph queries) and trivial codegen for TypeScript and Flutter (`dio`/`freezed`) via OpenAPI 3.1.
* **Alternatives Considered:**
  * *GraphQL (Lighthouse):* Flexible for ad-hoc UI queries, but complicates HTTP/CDN caching, file uploads, and rate-limiting complexity.

### Decision 3: Dual Pagination Strategy (Offset for SEO Pages, Cursor for Mobile/CRM Feeds)
* **Why Recommended:**
  * Public search/directory endpoints (`GET /api/v1/search`) use **page-number pagination** (`page=1&per_page=20`) so Next.js SEO pages produce indexable `?page=2` URLs.
  * High-churn operational feeds (`GET /api/v1/owner/leads`, `GET /api/v1/businesses/{id}/reviews`) support **cursor pagination** (`cursor=...&limit=20`) to eliminate skipped/duplicate rows on infinite scroll in Flutter and the merchant CRM.
* **Alternatives Considered:**
  * *Offset pagination everywhere:* Breaks infinite scroll consistency when new leads arrive mid-scroll and degrades on deep pages (`OFFSET 50000`).

---

## 3. Global Conventions & Envelopes

### 3.1 Required Headers
* `Accept: application/json`
* `Content-Type: application/json` (or `multipart/form-data` for media/KYC uploads)
* `Authorization: Bearer <token>` (for authenticated endpoints)
* `X-Client-Platform: web | flutter-ios | flutter-android` (optional telemetry)
* `X-Request-ID: <uuid>` (echoed in response headers and `meta.request_id` for end-to-end tracing)

### 3.2 Data Formats
* **Identifiers:** External ULID `public_id` strings (`01JCG4K8N5...`) or clean URL slugs; never expose raw auto-increment IDs for sensitive resources.
* **Timestamps:** ISO-8601 UTC (`2026-11-18T07:15:00Z`).
* **Monetary Values:** Integer minor units (`amount_minor: 149900` for ₹1,499.00) paired with ISO-4217 `currency: "INR"`.
* **Phone Numbers:** Normalized E.164 (`+919820012345`).

### 3.3 Standard Success Envelope
```json
{
  "status": "success",
  "data": { "...": "..." },
  "meta": {
    "request_id": "req_9f3c2d1a",
    "api_version": "v1",
    "timestamp": "2026-11-18T07:15:00Z"
  }
}
```

### 3.4 Standard Paginated Envelope
```json
{
  "status": "success",
  "data": [ "..." ],
  "meta": {
    "request_id": "req_9f3c2d1a",
    "pagination": {
      "type": "offset",
      "current_page": 1,
      "per_page": 20,
      "total": 248,
      "last_page": 13,
      "next_cursor": null
    }
  }
}
```

### 3.5 Standard Error Envelope
```json
{
  "status": "error",
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "details": {
      "phone_e164": ["Please enter a valid 10-digit Indian mobile number."],
      "consent_given": ["You must accept the enquiry sharing consent notice."]
    }
  },
  "meta": {
    "request_id": "req_9f3c2d1a"
  }
}
```

### 3.6 HTTP Status Code Semantics
| Code | Meaning | Usage |
| :--- | :--- | :--- |
| `200 OK` | Success | Reads, updates, OTP verification |
| `201 Created` | Resource Created | New enquiry, review, business registration, media upload |
| `204 No Content` | Success (Empty) | Logout, bookmark removal |
| `400 Bad Request` | Malformed Request | Invalid JSON or unsupported query operator |
| `401 Unauthorized` | Unauthenticated | Missing, expired, or revoked Bearer token |
| `403 Forbidden` | Unauthorized | Valid token lacking required role/ability or failing ownership Policy |
| `404 Not Found` | Missing Resource | Unknown slug/ULID (also used to mask unauthorized resource existence) |
| `409 Conflict` | State Conflict | Duplicate review within 30 days, duplicate active business claim |
| `422 Unprocessable Entity` | Validation Error | Field validation failures (returns `error.details` field map) |
| `429 Too Many Requests` | Rate Limited | Exceeded OTP, search, or contact-reveal quotas (`Retry-After` header included) |

---

## 4. Authentication, Authorization & Rate Limiting

### 4.1 Sanctum Bearer Token Isolation (Next.js BFF vs. Flutter)
* **Web Clients (Next.js App Router):** Browser JavaScript **never** receives, reads, or stores Sanctum Bearer tokens (no `localStorage`, no `sessionStorage`, no client-readable cookie, no serialization into React Server Component props). Next.js BFF Route Handlers (`/api/auth/*`, `/api/proxy/*`) store the Sanctum token strictly inside an `HttpOnly; Secure; SameSite=Lax` cookie, enforce Origin/CSRF validation on mutations, and inject `Authorization: Bearer <token>` server-side when forwarding to Laravel 13.
* **Mobile Clients (Future Flutter Apps):** Store the Sanctum Bearer token directly in OS hardware-backed secure storage (`flutter_secure_storage` — iOS Keychain / Android Keystore) and attach `Authorization: Bearer <token>` on `/api/v1/*` requests.

### 4.2 Token Scopes (Coarse Capability Gates Only)
* **Customer Token (`customer`):** `customer:read`, `customer:review`, `customer:enquiry`, `customer:bookmark`.
* **Business Owner Token (`business_owner`):** All `customer:*` abilities + `owner:business:manage`, `owner:leads:manage`, `owner:reviews:reply`, `owner:billing:manage`.
* **Admin Accounts:** Never receive `/api/v1/*` admin privileges; admin actions execute exclusively via `/admin/*` Blade session guard + TOTP 2FA.

### 4.3 Independent Policy-Based Authorization & IDOR Protection
**Critical Security Invariant:** Sanctum token abilities act strictly as **coarse-grained capability gates** — they **never** substitute for object-level authorization.
1. Every single resource read, update, or delete endpoint independently executes a **Laravel Policy** (`$this->authorize('update', $business)`) verifying explicit ownership (`$business->owner_user_id === $user->id`) and role membership in the database (`user_roles`), even if a token carries wildcard or `owner:*` abilities.
2. External endpoints identify mutable resources via non-sequential ULID `public_id`s rather than guessable integer IDs.
3. Cross-tenant/IDOR access attempts (e.g., Business Owner A targeting Business Owner B's `lead_assignment`, `business_media`, or `invoice`) return `403 Forbidden` (or `404 Not Found` for sensitive existence masking) and emit a structured security log event.
4. Automated Pest authorization tests must verify both **missing ability** AND **valid ability targeting another user's resource** for every protected endpoint.

### 4.3 Rate Limiting Tiers (Redis Token Bucket)
| Endpoint Group | Limit | Scope |
| :--- | :--- | :--- |
| `POST /auth/otp/request` | 3 / 10 min; 10 / 24h | Per `phone_e164` AND per IP |
| `POST /auth/otp/verify` | 5 attempts per `challenge_id` | Per challenge + IP |
| `POST /businesses/{id}/contact-reveal` | 15 / hour | Per user or IP |
| `POST /enquiries` | 5 / hour | Per user or `phone_e164` |
| `GET /search`, `GET /search/suggestions` | 120 / min | Per IP |
| Authenticated Owner APIs | 180 / min | Per Bearer token |

---

## 5. Complete Endpoint Catalog (`/api/v1`)

### 5.1 Authentication & Account (`/api/v1/auth`)

| Method | Path | Auth | Description |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/auth/otp/request` | Public | Issues 6-digit OTP (`phone_e164`, `purpose`: `login` \| `business_register`). Returns `challenge_id`, `expires_in_seconds`, `resend_after_seconds`. |
| `POST` | `/api/v1/auth/otp/verify` | Public | Verifies `challenge_id` + `otp` + `device_name`. Returns Sanctum `access_token`, `token_type: "Bearer"`, `expires_at`, and `user` profile with `roles`. |
| `POST` | `/api/v1/auth/logout` | Bearer | Revokes current Bearer token (and optionally unregisters `device_identifier`). |
| `POST` | `/api/v1/auth/logout-all` | Bearer | Revokes all active tokens across all web & Flutter devices. |
| `GET` | `/api/v1/auth/me` | Bearer | Returns authenticated user profile, roles, and owned business count. |
| `PATCH` | `/api/v1/auth/me` | Bearer | Updates user `name`, `email`, or `avatar`. |
| `POST` | `/api/v1/auth/devices` | Bearer | Registers/refreshes Flutter FCM push token (`platform`, `device_identifier`, `fcm_token`, `app_version`). |
| `DELETE` | `/api/v1/auth/me` | Bearer | Initiates DPDP/GDPR account erasure & PII anonymization request. |

#### Sample: `POST /api/v1/auth/otp/verify` Response (`200 OK`)
```json
{
  "status": "success",
  "data": {
    "token_type": "Bearer",
    "access_token": "42|a9f8c7b6e5d4...",
    "expires_at": "2026-12-18T07:15:00Z",
    "user": {
      "public_id": "01JCG4K8N5P2Q7R9S1T3V5W7X9",
      "name": "Aarav Mehta",
      "phone_e164": "+919820012345",
      "roles": ["customer", "business_owner"]
    }
  }
}
```

---

### 5.2 Locations, Taxonomy & Home Discovery

| Method | Path | Auth | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/home` | Public | Aggregated homepage payload (popular cities, featured categories, active banners, trending searches for `city` param). Cached in Redis. |
| `GET` | `/api/v1/locations/cities` | Public | Lists active cities (`?popular_only=1&q=mum`). |
| `GET` | `/api/v1/locations/cities/{citySlug}/localities` | Public | Lists localities and pincodes for a city. |
| `GET` | `/api/v1/locations/resolve` | Public | Resolves `?lat=19.14&lng=72.83` to nearest city & locality using MySQL spatial index. |
| `GET` | `/api/v1/categories` | Public | Full nested category tree (`?featured_only=1`). |
| `GET` | `/api/v1/categories/{slug}` | Public | Category metadata, child subcategories, SEO templates, and schema.org type. |
| `GET` | `/api/v1/attributes` | Public | Reference lists of `amenities` and `payment_methods` for filters & owner forms. |

---

### 5.3 Search & Discovery

| Method | Path | Auth | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/search/suggestions` | Public | Fast `<60ms` autocomplete (`?city=mumbai&q=dent`) returning grouped `categories`, `localities`, and `businesses`. |
| `GET` | `/api/v1/search` | Public | Primary search engine (detailed scoring & SQL in `11-search-engine-design.md`). |

#### `GET /api/v1/search` Query Parameters & Validation
* `city` (required, string slug — e.g., `mumbai`)
* `q` (optional, string, max 120 chars)
* `category` (optional, string slug)
* `localities[]` (optional, array of locality slugs, max 10)
* `lat`, `lng` (optional, numeric WGS-84 coordinates; required together)
* `radius_km` (optional, numeric `1..50`, default `10` when `lat/lng` provided)
* `min_rating` (optional, numeric `3.0 | 3.5 | 4.0 | 4.5`)
* `verified_only` (optional, boolean)
* `open_now` (optional, boolean)
* `amenities[]` (optional, array of amenity slugs)
* `payment_methods[]` (optional, array of payment method slugs)
* `sort` (optional, enum: `relevance` [default], `rating`, `distance`, `popularity`)
* `page` (optional, integer `1..250`, default `1`)
* `per_page` (optional, integer `10..40`, default `20`)

#### Sample `GET /api/v1/search` Item Structure
```json
{
  "public_id": "01JCG4M1...",
  "name": "Apex Dental Care",
  "slug": "apex-dental-care-andheri-west",
  "city_slug": "mumbai",
  "locality_name": "Andheri West",
  "primary_category": { "name": "Dentists", "slug": "dentists" },
  "is_sponsored": true,
  "sponsor_disclosure": "Sponsored",
  "is_verified": true,
  "is_trusted": true,
  "rating_avg": 4.8,
  "review_count": 142,
  "distance_km": 1.4,
  "is_open_now": true,
  "closes_at": "21:00",
  "address_short": "Crystal Plaza, New Link Road, Andheri West",
  "phone_masked": "+91-98200-XXXXX",
  "has_whatsapp": true,
  "media_preview": [
    { "thumb": "https://cdn.example.com/.../t1.webp", "card": "https://cdn.example.com/.../c1.webp" }
  ]
}
```

---

### 5.4 Business Profile, Reviews & Bookmarks

| Method | Path | Auth | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/businesses/{citySlug}/{businessSlug}` | Public | Full business storefront payload (overview, hours, services, media gallery, amenities, payment methods, FAQs, rating histogram, nearby similar businesses). Also returns `301` redirect hint if matched in `url_redirects`. |
| `GET` | `/api/v1/businesses/{publicId}/reviews` | Public | Cursor-paginated approved reviews (`?sort=recent\|highest\|helpful&cursor=...`). |
| `POST` | `/api/v1/businesses/{publicId}/reviews` | Bearer (`customer`) | Creates review (`rating` 1..5, `title`, `body` min 20 chars, `sub_ratings`, optional photo uploads). Enforces 1 review per business per 30 days. |
| `POST` | `/api/v1/reviews/{publicId}/vote` | Bearer (`customer`) | Marks review `helpful` or submits `report` with reason. |
| `GET` | `/api/v1/account/bookmarks` | Bearer (`customer`) | Lists customer's bookmarked businesses. |
| `PUT` | `/api/v1/account/bookmarks/{businessPublicId}` | Bearer (`customer`) | Idempotently adds business to bookmarks. |
| `DELETE` | `/api/v1/account/bookmarks/{businessPublicId}` | Bearer (`customer`) | Removes bookmark (`204 No Content`). |

---

### 5.5 Privacy-Compliant Enquiries & Contact Reveals

| Method | Path | Auth | Description |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/enquiries` | Optional Bearer | Creates an enquiry **only on explicit submit** with mandatory `consent_given: true` and `consent_version`. If unauthenticated, creates `pending_otp` enquiry and returns `otp_challenge_id` (dispatches OTP). If authenticated Bearer user, marks `phone_verified_at` immediately and queues `DispatchEnquiryLeadsJob`. |
| `POST` | `/api/v1/enquiries/{publicId}/verify-otp` | Public | Verifies `otp_challenge_id` + `otp`, activates enquiry, dispatches to matched verified merchants, and optionally returns customer Bearer token. |
| `GET` | `/api/v1/account/enquiries` | Bearer (`customer`) | Lists enquiries submitted by the customer and which merchants received them (full transparency!). |
| `POST` | `/api/v1/businesses/{publicId}/contact-reveal` | Optional Bearer | Logs `channel` (`phone` \| `whatsapp` \| `website`), enforces anti-scraping rate limits, and returns unmasked contact URI (`tel:+91...`, `https://wa.me/...`). |

---

### 5.6 Business Owner Portal APIs (`/api/v1/owner/*`)
*All routes require `Authorization: Bearer <token>` with `business_owner` role (except initial registration which upgrades an OTP-verified customer to `business_owner`).*

| Method | Path | Description |
| :--- | :--- | :--- |
| `POST` | `/api/v1/owner/businesses` | Creates a new business listing (`status = pending_review`), validates category/city/locality/coordinates, assigns `business_owner` role. |
| `GET` | `/api/v1/owner/businesses` | Lists all businesses owned by the authenticated user. |
| `GET` | `/api/v1/owner/businesses/{publicId}` | Full owner view including moderation notes and completeness checklist. |
| `PUT` | `/api/v1/owner/businesses/{publicId}` | Updates business core profile, address, contact details, amenities, payment methods. Triggers ISR revalidation if active. |
| `PUT` | `/api/v1/owner/businesses/{publicId}/hours` | Replaces weekly operating hours (supports split shifts & 24h flags). |
| `POST` | `/api/v1/owner/businesses/{publicId}/services` | CRUD for services & pricing catalog (`POST`, `PUT`, `DELETE`). |
| `POST` | `/api/v1/owner/businesses/{publicId}/media` | Multipart photo upload; validates image, dispatches `ProcessBusinessMediaJob` for WebP transcoding. |
| `DELETE` | `/api/v1/owner/businesses/{publicId}/media/{mediaPublicId}` | Removes media asset from S3 and DB. |
| `POST` | `/api/v1/owner/businesses/{publicId}/verifications` | Uploads KYC document (`document_type`, `document_number`, file) to **private encrypted S3 bucket** for Blade Admin review. |
| `GET` | `/api/v1/owner/leads` | Cursor-paginated inbound `lead_assignments` across owner's businesses (`?business_id=...&status=new\|contacted\|converted`). |
| `PATCH` | `/api/v1/owner/leads/{assignmentPublicId}` | Updates lead CRM `status` and `merchant_notes` (auto-stamps `viewed_at` / `first_contacted_at`). |
| `POST` | `/api/v1/owner/reviews/{reviewPublicId}/reply` | Publishes official owner reply to a customer review on their business. |
| `GET` | `/api/v1/owner/analytics` | Time-series metrics (views, contact reveals, leads received, conversion rate over 7d / 30d / 90d). |
| `GET` | `/api/v1/owner/plans` | Lists available subscription, sponsored placement, and lead pack plans. |
| `POST` | `/api/v1/owner/billing/checkout` | Initiates payment with `idempotency_key`; returns gateway order details for Razorpay/Stripe SDK. |
| `GET` | `/api/v1/owner/billing/invoices` | Lists invoices; `GET .../invoices/{id}/download` returns 60-second signed S3 PDF URL. |

---

### 5.7 Webhooks (Signature Verification, Idempotency & Queue Recovery)
| Method | Path | Security | Description |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/webhooks/payments/{gateway}` | Raw-body HMAC-SHA256 Signature Verification | Verifies signature (`X-Razorpay-Signature` / `Stripe-Signature` with `<=300s` replay tolerance) via `hash_equals()`; returns `401` on mismatch. Deduplicates via `webhook_events(gateway, provider_event_id)` (`200 OK` immediately for duplicates). Dispatches `ProcessPaymentWebhookJob` on the `critical` queue (`tries=5`, exponential backoff `[15s, 60s, 300s, 900s, 1800s]`). Failures land in `failed_jobs` with P1 alerting and deterministic replay from `webhook_events` via Blade Admin or Artisan. |

---

## 6. Future Flutter Mobile Compatibility Contract

1. **Zero Cookie Dependency:** All authenticated endpoints accept `Authorization: Bearer <token>` natively — Flutter stores tokens in `flutter_secure_storage` (iOS Keychain / Android Keystore).
2. **Pre-sized Responsive Media URLs:** Every media item returns `thumb`, `card`, and `full` WebP URLs so Flutter `CachedNetworkImage` loads minimal bytes on cellular networks.
3. **Structured Error Codes:** Every error includes a machine-readable `error.code` (e.g., `OTP_EXPIRED`, `OTP_MAX_ATTEMPTS`, `RATE_LIMITED`, `DUPLICATE_REVIEW`) so Flutter can render localized messages cleanly.
4. **OpenAPI 3.1 Specification:** Laravel generates a versioned OpenAPI spec (`openapi-v1.yaml`) via automated schema annotations, enabling typed client generation for both TypeScript (Next.js) and Dart (Flutter).
5. **Deep Linking Alignment:** Public business slugs (`/{city}/business/{slug}`) map 1:1 to iOS Universal Links and Android App Links.
