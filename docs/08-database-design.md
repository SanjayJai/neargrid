# 08 - Database Design & Relational Schema Specification

## 1. Executive Summary

This document defines the normalized relational database schema for **MySQL 8.0** (`InnoDB`, `utf8mb4` charset with `utf8mb4_0900_ai_ci` collation for full English + Tamil Unicode sorting/matching, `STRICT_TRANS_TABLES`). It covers every major entity across Identity, Geography, Taxonomy, Business Directory, Verification, Reviews, Privacy-Compliant Leads, Monetization/Billing, and Governance, including foreign key constraints, check rules, and indexing strategies.

> **Note:** Per Phase 2 instructions, this document is an architectural specification only. No migrations or database tables are generated until Phase 2 is approved.

---

## 2. Core Database Design Decisions & Alternatives

### Decision 1: Internal `BIGINT UNSIGNED` PKs + External `CHAR(26)` ULID `public_id`
* **Why Recommended:**
  * `InnoDB` clusters table rows physically by Primary Key. Sequential `BIGINT UNSIGNED AUTO_INCREMENT` keeps B-Tree inserts compact, fast, and small for secondary indexes (every secondary index in InnoDB appends the PK).
  * Exposing a `UNIQUE CHAR(26)` **ULID** (`public_id`, e.g., `01JCG4K8N5...`) on external-facing tables (`users`, `businesses`, `reviews`, `enquiries`, `payments`) prevents sequential ID enumeration attacks and allows clean cross-platform identifiers for Next.js and Flutter.
* **Alternatives Considered:**
  * *UUIDv4 as Primary Key:* Causes severe InnoDB page splitting and 2x secondary index bloat.
  * *Exposing raw auto-increment integer IDs:* Leaks platform volume metrics and increases IDOR enumeration risk.

### Decision 2: Unified `users` Table + RBAC (`roles` / `user_roles`)
* **Why Recommended:**
  * A local resident who registers as a customer to write reviews or submit enquiries can seamlessly register a business later ("List Your Business") using the same phone number and account without duplicate-identity friction.
  * Admin operators use dedicated roles (`super_admin`, `kyc_verifier`, `moderator`, `support`) audited through `audit_logs` and protected by TOTP MFA.
* **Alternatives Considered:**
  * *Separate `customers`, `merchants`, and `admins` tables:* Complicates multi-role phone-number login, polymorphic foreign keys (reviews/media/messages), and Sanctum token configuration.

### Decision 3: Normalized 3NF Core + Deliberate Denormalized Read Caches
* **Why Recommended:**
  * All transactional data (business hours, amenities, categories, reviews, lead assignments, payments) is strictly normalized (3NF) to avoid update anomalies.
  * High-frequency SERP display metrics (`businesses.rating_avg`, `businesses.bayesian_score`, `businesses.review_count`, `businesses.photo_count`, `businesses.completeness_score`) are stored as cached columns updated transactionally or via idempotent queued jobs, avoiding expensive `JOIN + AVG()` aggregations on every search page.
* **Alternatives Considered:**
  * *Live `AVG()` / `COUNT()` aggregation per search request:* Degrades MySQL latency rapidly once reviews surpass hundreds of thousands of rows.

### Decision 4: MySQL 8.0 Native `POINT SRID 4326` Spatial Columns
* **Why Recommended:**
  * MySQL 8.0 supports true WGS-84 ellipsoidal geometry (`SRID 4326`) with `SPATIAL INDEX` and `ST_Distance_Sphere()`, allowing accurate radius searches and distance sorting directly inside the approved MySQL 8 stack without adding PostgreSQL/PostGIS initially.
* **Alternatives Considered:**
  * *Float `latitude`/`longitude` Haversine formula without spatial index:* Forces full table scans or complex manual bounding-box math.
  * *PostgreSQL + PostGIS:* Powerful GIS capabilities, but outside the approved MySQL 8 stack.

---

## 3. Domain-by-Domain Entity & Table Specifications

### 3.1 Domain: Identity & Access

#### `users`
Unified identity store for customers, business owners, and admin staff.

| Column | Type | Constraints | Notes |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | Internal key |
| `public_id` | `CHAR(26)` | `UNIQUE, NOT NULL` | External ULID |
| `name` | `VARCHAR(120)` | `NULL` | Display name |
| `email` | `VARCHAR(191)` | `UNIQUE, NULL` | Optional for OTP-only users; required for admins |
| `phone_e164` | `VARCHAR(20)` | `UNIQUE, NULL` | E.164 format (e.g., `+919820012345`) |
| `password` | `VARCHAR(255)` | `NULL` | Bcrypt/Argon2id hash (required for admins, optional for owners) |
| `phone_verified_at` | `TIMESTAMP` | `NULL` | Last OTP phone verification |
| `email_verified_at` | `TIMESTAMP` | `NULL` | Email verification timestamp |
| `avatar_url` | `VARCHAR(500)` | `NULL` | Public CDN avatar path |
| `status` | `ENUM('active','suspended','banned')` | `NOT NULL, DEFAULT 'active'` | Account standing |
| `totp_secret_encrypted` | `TEXT` | `NULL` | Mandatory for Admin accounts (2FA) |
| `totp_enabled_at` | `TIMESTAMP` | `NULL` | Admin 2FA activation |
| `last_login_at` | `TIMESTAMP` | `NULL` | Audit signal |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | `NULL` | Soft deletes supported |

* **Indexes:** `PRIMARY(id)`, `UNIQUE(public_id)`, `UNIQUE(phone_e164)`, `UNIQUE(email)`, `INDEX(status)`.

#### `roles` & `user_roles`
* `roles`: `id`, `name` (`UNIQUE`: `customer`, `business_owner`, `super_admin`, `kyc_verifier`, `moderator`, `support`), `label`, `created_at`.
* `user_roles`: `user_id` (`FK -> users.id ON DELETE CASCADE`), `role_id` (`FK -> roles.id ON DELETE RESTRICT`), `assigned_by_user_id` (`FK -> users.id NULL`), `created_at`. Composite `PRIMARY KEY (user_id, role_id)`.

#### `personal_access_tokens` (Laravel Sanctum)
Standard Sanctum table storing SHA-256 hashed Bearer tokens, `tokenable_type`, `tokenable_id`, `name` (e.g., `web-bff`, `flutter-android-pixel8`), `abilities` (`JSON`), `last_used_at`, `expires_at`.
* **Indexes:** `UNIQUE(token)`, `INDEX(tokenable_type, tokenable_id)`, `INDEX(expires_at)`.

#### `otp_challenges`
Ephemeral security table for OTP lifecycle (pruned nightly after 24h).

| Column | Type | Constraints | Notes |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | |
| `challenge_id` | `CHAR(26)` | `UNIQUE, NOT NULL` | ULID returned to client |
| `phone_e164` | `VARCHAR(20)` | `NOT NULL` | Target mobile number |
| `purpose` | `ENUM('login','enquiry_verify','business_register','phone_change')` | `NOT NULL` | Prevents cross-flow OTP replay |
| `code_hash` | `CHAR(64)` | `NOT NULL` | HMAC-SHA256(otp, app_pepper) — never plaintext |
| `attempts` | `TINYINT UNSIGNED` | `NOT NULL, DEFAULT 0` | Max 5 attempts |
| `max_attempts` | `TINYINT UNSIGNED` | `NOT NULL, DEFAULT 5` | |
| `request_ip_hash` | `CHAR(64)` | `NOT NULL` | Salted IP hash for abuse detection |
| `expires_at` | `TIMESTAMP` | `NOT NULL` | 5-minute TTL |
| `verified_at` | `TIMESTAMP` | `NULL` | Single-use marker |
| `created_at` | `TIMESTAMP` | `NOT NULL` | |

* **Indexes:** `UNIQUE(challenge_id)`, `INDEX(phone_e164, purpose, created_at)`, `INDEX(expires_at)`.

#### `user_devices` (Flutter Push & Multi-Device Ready)
* Columns: `id`, `user_id` (`FK -> users.id ON DELETE CASCADE`), `platform` (`ENUM('web','android','ios')`), `device_identifier` (`VARCHAR(191)`), `fcm_token` (`VARCHAR(512)`), `app_version` (`VARCHAR(32)`), `last_active_at`, `created_at`, `updated_at`.
* **Indexes:** `UNIQUE(user_id, device_identifier)`, `INDEX(fcm_token(191))`.

---

### 3.2 Domain: Geography & Locations

> **Approved Location Architecture Invariants:**
> 1. **Strict Hierarchy:** `State` → `City` → `Location/Locality`.
> 2. **No Country Management:** Country management tables and routing are intentionally omitted to keep the schema lean and hyper-focused on regional directory utility.
> 3. **Configurable Deployment Modes:** Supports **Single-Location Mode** (e.g., launching in a single municipality) and **Multi-Location Mode** (regional or multi-city expansion) via application configuration.
> 4. **Initial Launch Location:** **Vellore**, with Chennai seeded as reference urban center.
> 5. **Core Application Functions:** Existing tables and foreign key relationships (`states`, `cities`, `localities`) directly power customer proximity/spatial search (`SRID 4326`), merchant onboarding address selection, canonical directory route generation (`/[city]/[category]`), and regional SEO metadata.

#### `states` & `state_translations`
* `states`: `id`, `public_id` (`CHAR(26) UNIQUE`), `country_code` (`CHAR(2)`, default `'IN'`), `name` (`VARCHAR(100)`), `slug` (`VARCHAR(120)`), `sort_order` (`SMALLINT UNSIGNED DEFAULT 0`), `is_active` (`BOOLEAN`).
* **Indexes:** `UNIQUE(public_id)`, `UNIQUE(country_code, slug)`, `UNIQUE(country_code, name)`, `INDEX(is_active, sort_order)`.
* `state_translations`: `id`, `state_id` (`FK -> states.id ON DELETE CASCADE`), `locale` (`VARCHAR(10)`), `name` (`VARCHAR(140)`), `UNIQUE(state_id, locale)`.

#### `cities`
* Columns: `id`, `public_id` (`CHAR(26) UNIQUE`), `state_id` (`FK -> states.id ON DELETE RESTRICT`), `name` (`VARCHAR(100)`), `slug` (`VARCHAR(120)`, `UNIQUE`), `latitude` (`DECIMAL(10,7)`), `longitude` (`DECIMAL(10,7)`), `center_point` (`POINT NOT NULL SRID 4326`), `timezone` (`VARCHAR(64)`, default `'Asia/Kolkata'`), `is_popular` (`BOOLEAN DEFAULT 0`), `sort_order` (`SMALLINT UNSIGNED DEFAULT 0`), `seo_title` (`VARCHAR(160)`), `seo_description` (`VARCHAR(320)`), `is_active` (`BOOLEAN DEFAULT 1`).
* **Indexes:** `UNIQUE(public_id)`, `UNIQUE(slug)`, `SPATIAL INDEX(center_point)`, `INDEX(is_active, is_popular, sort_order)`.

#### `localities`
* Columns: `id`, `public_id` (`CHAR(26) UNIQUE`), `city_id` (`FK -> cities.id ON DELETE RESTRICT`), `name` (`VARCHAR(120)`), `slug` (`VARCHAR(140)`), `pincode` (`VARCHAR(12)`, `NULL`), `latitude` (`DECIMAL(10,7)`), `longitude` (`DECIMAL(10,7)`), `center_point` (`POINT NOT NULL SRID 4326`), `is_popular` (`BOOLEAN DEFAULT 0`), `sort_order` (`SMALLINT UNSIGNED DEFAULT 0`), `is_active` (`BOOLEAN DEFAULT 1`).
* **Indexes:** `UNIQUE(public_id)`, `UNIQUE(city_id, slug)`, `SPATIAL INDEX(center_point)`, `INDEX(city_id, pincode)`, `INDEX(city_id, is_active, sort_order)`.

---

### 3.3 Domain: Taxonomy & Attributes

#### `categories`
Hierarchical taxonomy supporting 2–3 levels (e.g., `Healthcare` -> `Dentists` -> `Cosmetic Dentists`).

| Column | Type | Constraints | Notes |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | |
| `parent_id` | `BIGINT UNSIGNED` | `FK -> categories.id ON DELETE RESTRICT, NULL` | Root if NULL |
| `name` | `VARCHAR(120)` | `NOT NULL` | Display label |
| `slug` | `VARCHAR(140)` | `UNIQUE, NOT NULL` | Clean SEO slug |
| `schema_org_type` | `VARCHAR(80)` | `DEFAULT 'LocalBusiness'` | Maps to JSON-LD subtype (e.g., `Dentist`, `Restaurant`) |
| `icon_name` | `VARCHAR(80)` | `NULL` | Tailwind/SVG icon key |
| `image_url` | `VARCHAR(500)` | `NULL` | Optional category tile graphic |
| `synonyms` | `TEXT` | `NULL` | Space/comma separated search synonyms (e.g., "tooth doctor, orthodontist") |
| `depth` | `TINYINT UNSIGNED` | `NOT NULL, DEFAULT 0` | Hierarchy depth |
| `is_featured` | `BOOLEAN` | `NOT NULL, DEFAULT 0` | Shown on homepage grid |
| `sort_order` | `SMALLINT UNSIGNED` | `NOT NULL, DEFAULT 0` | Display ordering |
| `seo_title_template` | `VARCHAR(180)` | `NULL` | e.g., `Best {category} in {city}` |
| `seo_description_template` | `VARCHAR(320)` | `NULL` | |
| `is_active` | `BOOLEAN` | `NOT NULL, DEFAULT 1` | |

* **Indexes:** `UNIQUE(slug)`, `INDEX(parent_id, is_active, sort_order)`, `INDEX(is_featured, is_active, sort_order)`, `FULLTEXT INDEX idx_cat_ft (name, synonyms)`.

#### `amenities` & `payment_methods`
* `amenities`: `id`, `name` (`VARCHAR(80)`), `slug` (`UNIQUE`), `icon_name`, `group_name` (`general|accessibility|parking|dining`), `is_active`.
* `payment_methods`: `id`, `name` (`VARCHAR(60)`), `slug` (`UNIQUE`), `icon_name`, `is_active`.

---

### 3.4 Domain: Business Directory Core

#### `businesses`

| Column | Type | Constraints | Notes |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | Internal PK |
| `public_id` | `CHAR(26)` | `UNIQUE, NOT NULL` | External ULID |
| `owner_user_id` | `BIGINT UNSIGNED` | `FK -> users.id ON DELETE SET NULL, NULL` | NULL if unclaimed directory seed |
| `primary_category_id` | `BIGINT UNSIGNED` | `FK -> categories.id ON DELETE RESTRICT, NOT NULL` | Primary listing vertical |
| `city_id` | `BIGINT UNSIGNED` | `FK -> cities.id ON DELETE RESTRICT, NOT NULL` | City scope |
| `locality_id` | `BIGINT UNSIGNED` | `FK -> localities.id ON DELETE RESTRICT, NOT NULL` | Micro-market scope |
| `name` | `VARCHAR(180)` | `NOT NULL` | Trading name |
| `slug` | `VARCHAR(220)` | `NOT NULL` | Unique per city: `UNIQUE(city_id, slug)` |
| `tagline` | `VARCHAR(200)` | `NULL` | Short pitch |
| `description` | `TEXT` | `NULL` | Detailed overview |
| `search_keywords` | `TEXT` | `NULL` | Normalized tags/services for FULLTEXT |
| `year_established` | `YEAR` | `NULL` | e.g., `2012` |
| `address_line_1` | `VARCHAR(255)` | `NOT NULL` | Shop/building/street |
| `address_line_2` | `VARCHAR(255)` | `NULL` | Area/floor |
| `landmark` | `VARCHAR(180)` | `NULL` | Nearby landmark |
| `pincode` | `VARCHAR(12)` | `NOT NULL` | Postal code |
| `latitude` | `DECIMAL(10,7)` | `NOT NULL` | WGS-84 Latitude |
| `longitude` | `DECIMAL(10,7)` | `NOT NULL` | WGS-84 Longitude |
| `location_point` | `POINT` | `NOT NULL SRID 4326` | Indexed spatial point |
| `primary_phone_e164` | `VARCHAR(20)` | `NOT NULL` | Primary phone |
| `secondary_phone_e164` | `VARCHAR(20)` | `NULL` | Landline/secondary |
| `whatsapp_e164` | `VARCHAR(20)` | `NULL` | WhatsApp contact |
| `email` | `VARCHAR(191)` | `NULL` | Business enquiry email |
| `website_url` | `VARCHAR(255)` | `NULL` | |
| `status` | `ENUM('draft','pending_review','active','suspended','rejected')` | `NOT NULL, DEFAULT 'pending_review'` | Publishing lifecycle |
| `is_claimed` | `BOOLEAN` | `NOT NULL, DEFAULT 0` | Owner-managed flag |
| `is_verified` | `BOOLEAN` | `NOT NULL, DEFAULT 0` | KYC verified badge |
| `verified_at` | `TIMESTAMP` | `NULL` | |
| `is_trusted` | `BOOLEAN` | `NOT NULL, DEFAULT 0` | High-trust badge |
| `trusted_at` | `TIMESTAMP` | `NULL` | |
| `rating_avg` | `DECIMAL(3,2)` | `NOT NULL, DEFAULT 0.00` | Cached raw average |
| `bayesian_score` | `DECIMAL(4,3)` | `NOT NULL, DEFAULT 0.000` | Cached damped ranking score |
| `review_count` | `INT UNSIGNED` | `NOT NULL, DEFAULT 0` | Approved reviews count |
| `photo_count` | `SMALLINT UNSIGNED` | `NOT NULL, DEFAULT 0` | Approved media count |
| `completeness_score` | `TINYINT UNSIGNED` | `NOT NULL, DEFAULT 0` | `0..100` profile quality |
| `view_count` | `BIGINT UNSIGNED` | `NOT NULL, DEFAULT 0` | Aggregated impressions |
| `created_at` / `updated_at` / `deleted_at` | `TIMESTAMP` | `NULL` | Soft deletes enabled |

* **Constraints & Indexes:**
  * `UNIQUE(public_id)`, `UNIQUE(city_id, slug)`.
  * `SPATIAL INDEX idx_biz_location (location_point)`.
  * `FULLTEXT INDEX idx_biz_fulltext (name, tagline, description, search_keywords)`.
  * `INDEX idx_biz_serp (status, city_id, primary_category_id, bayesian_score DESC)`.
  * `INDEX idx_biz_locality (status, city_id, locality_id, is_verified, rating_avg DESC)`.
  * `INDEX idx_biz_owner (owner_user_id, status)`.
  * `CHECK (rating_avg >= 0.00 AND rating_avg <= 5.00)` and `CHECK (completeness_score <= 100)`.

#### Supporting Business Child Tables
* **`business_categories`**: `business_id` (`FK CASCADE`), `category_id` (`FK RESTRICT`), `is_primary` (`BOOLEAN`). `PK(business_id, category_id)`, `INDEX(category_id, business_id)`.
* **`business_hours`**: `id`, `business_id` (`FK CASCADE`), `day_of_week` (`TINYINT UNSIGNED`, `0=Sun..6=Sat`), `slot_index` (`TINYINT UNSIGNED`, `1` or `2` for split shifts), `open_time` (`TIME NULL`), `close_time` (`TIME NULL`), `is_24_hours` (`BOOLEAN DEFAULT 0`), `is_closed` (`BOOLEAN DEFAULT 0`). `UNIQUE(business_id, day_of_week, slot_index)`, `INDEX(day_of_week, is_closed, open_time, close_time)`.
* **`business_media`**: `id`, `public_id` (`CHAR(26) UNIQUE`), `business_id` (`FK CASCADE`), `uploaded_by_user_id` (`FK SET NULL`), `media_type` (`ENUM('logo','cover','interior','exterior','product','menu','certificate')`), `original_path`, `webp_full_path`, `webp_card_path`, `webp_thumb_path`, `width`, `height`, `alt_text`, `sort_order`, `moderation_status` (`ENUM('pending','approved','rejected')`), `created_at`. `INDEX(business_id, moderation_status, media_type, sort_order)`.
* **`business_services`**: `id`, `business_id` (`FK CASCADE`), `name` (`VARCHAR(150)`), `description` (`VARCHAR(500) NULL`), `price_min_minor` (`INT UNSIGNED NULL`), `price_max_minor` (`INT UNSIGNED NULL`), `currency` (`CHAR(3) DEFAULT 'INR'`), `price_unit` (`VARCHAR(40) NULL`), `sort_order`, `is_active`. `INDEX(business_id, is_active, sort_order)`.
* **`business_amenities`**: `business_id` (`FK CASCADE`), `amenity_id` (`FK RESTRICT`). `PK(business_id, amenity_id)`, `INDEX(amenity_id, business_id)`.
* **`business_payment_methods`**: `business_id` (`FK CASCADE`), `payment_method_id` (`FK RESTRICT`). `PK(business_id, payment_method_id)`.
* **`business_faqs`**: `id`, `business_id` (`FK CASCADE`), `question` (`VARCHAR(300)`), `answer` (`TEXT`), `sort_order`, `is_active`. `INDEX(business_id, is_active, sort_order)`.

#### `business_verifications` (KYC Audit Trail)
* Columns: `id`, `public_id` (`CHAR(26) UNIQUE`), `business_id` (`FK CASCADE`), `submitted_by_user_id` (`FK RESTRICT`), `document_type` (`ENUM('gstin','pan','trade_license','shop_act','utility_bill','incorporation_cert')`), `document_number_encrypted` (`TEXT NOT NULL`), `private_file_path` (`VARCHAR(500) NOT NULL`), `status` (`ENUM('pending','under_review','approved','rejected')`), `reviewed_by_admin_id` (`FK -> users.id SET NULL`), `rejection_reason` (`TEXT NULL`), `reviewed_at` (`TIMESTAMP NULL`), `created_at`, `updated_at`.
* **Indexes:** `INDEX(status, created_at)`, `INDEX(business_id, status)`.

#### `business_claims`
* Columns: `id`, `business_id` (`FK CASCADE`), `claimant_user_id` (`FK RESTRICT`), `proof_file_path_private` (`VARCHAR(500)`), `status` (`ENUM('pending','approved','rejected')`), `reviewed_by_admin_id` (`FK SET NULL`), `notes` (`TEXT NULL`), `created_at`, `updated_at`.

---

### 3.5 Domain: Ratings, Reviews & Bookmarks

#### `reviews`
* Columns: `id`, `public_id` (`CHAR(26) UNIQUE`), `business_id` (`FK CASCADE`), `user_id` (`FK RESTRICT`), `rating` (`TINYINT UNSIGNED NOT NULL`, `CHECK (rating BETWEEN 1 AND 5)`), `title` (`VARCHAR(160) NULL`), `body` (`TEXT NOT NULL`), `sub_ratings` (`JSON NULL` — e.g., `{"quality":5,"value":4}`), `is_verified_interaction` (`BOOLEAN DEFAULT 0`), `moderation_status` (`ENUM('pending','approved','flagged','rejected') DEFAULT 'approved'`), `helpful_count` (`INT UNSIGNED DEFAULT 0`), `owner_reply` (`TEXT NULL`), `owner_replied_at` (`TIMESTAMP NULL`), `moderated_by_admin_id` (`FK SET NULL`), `created_at`, `updated_at`, `deleted_at`.
* **Indexes:** `INDEX(business_id, moderation_status, created_at DESC)`, `INDEX(business_id, moderation_status, rating)`, `INDEX(user_id, business_id, created_at)` (used to enforce 1 review per business per 30 days).

#### `review_media`, `review_votes`, `saved_businesses` (formerly `user_bookmarks`)
* `review_media`: `id`, `review_id` (`FK CASCADE`), `webp_full_path`, `webp_thumb_path`, `moderation_status`, `created_at`.
* `review_votes`: `review_id` (`FK CASCADE`), `user_id` (`FK CASCADE`), `vote_type` (`ENUM('helpful','report')`), `report_reason` (`VARCHAR(255) NULL`), `created_at`. `PRIMARY KEY(review_id, user_id, vote_type)`.
* `saved_businesses`: `user_id` (`FK CASCADE`), `business_id` (`FK CASCADE`), `created_at`. `PRIMARY KEY(user_id, business_id)`, `INDEX(user_id, created_at)`, `INDEX(business_id)`. Enforces database-level uniqueness for saved bookmarks per customer.

---

### 3.6 Domain: Privacy-Compliant Leads & Enquiries

#### `enquiries`
Captures explicitly consented customer enquiries only (no silent unconsented blur capture).

| Column | Type | Constraints | Notes |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | |
| `public_id` | `CHAR(26)` | `UNIQUE, NOT NULL` | External ULID |
| `customer_user_id` | `BIGINT UNSIGNED` | `FK -> users.id SET NULL, NULL` | Linked if logged in or after OTP |
| `enquiry_type` | `ENUM('direct','broadcast')` | `NOT NULL` | Single business vs category multi-vendor |
| `target_business_id` | `BIGINT UNSIGNED` | `FK -> businesses.id SET NULL, NULL` | Set when `direct` |
| `category_id` | `BIGINT UNSIGNED` | `FK -> categories.id RESTRICT, NOT NULL` | Service category |
| `city_id` | `BIGINT UNSIGNED` | `FK -> cities.id RESTRICT, NOT NULL` | |
| `locality_id` | `BIGINT UNSIGNED` | `FK -> localities.id SET NULL, NULL` | |
| `customer_name` | `VARCHAR(120)` | `NOT NULL` | |
| `customer_phone_e164` | `VARCHAR(20)` | `NOT NULL` | Masked/anonymized after retention TTL |
| `requirement_summary` | `VARCHAR(1000)` | `NOT NULL` | Customer request details |
| `urgency` | `ENUM('immediate','within_week','planned')` | `NOT NULL, DEFAULT 'immediate'` | |
| `max_recipients` | `TINYINT UNSIGNED` | `NOT NULL, DEFAULT 4` | Disclosed in consent text (`1` for direct, `<=4` for broadcast) |
| `consent_given` | `BOOLEAN` | `NOT NULL` | Must be `1` (`CHECK (consent_given = 1)`) |
| `consent_version` | `VARCHAR(32)` | `NOT NULL` | Links to policy text version (e.g., `v1.0`) |
| `consent_ip_hash` | `CHAR(64)` | `NOT NULL` | Salted IP audit proof |
| `consent_at` | `TIMESTAMP` | `NOT NULL` | Exact consent timestamp |
| `phone_verified_at` | `TIMESTAMP` | `NULL` | Verified via OTP or existing Bearer session |
| `status` | `ENUM('pending_otp','dispatched','closed','expired','erased')` | `NOT NULL` | Lifecycle state |
| `anonymized_at` | `TIMESTAMP` | `NULL` | Privacy retention enforcement |
| `created_at` / `updated_at` | `TIMESTAMP` | `NOT NULL` | |

* **Indexes:** `UNIQUE(public_id)`, `INDEX(status, created_at)`, `INDEX(customer_user_id, created_at DESC)`, `INDEX(city_id, category_id, created_at DESC)`, `INDEX(anonymized_at, created_at)`.

#### `lead_assignments`
Maps each dispatched enquiry to receiving business owners (Merchant CRM table).
* Columns: `id`, `public_id` (`CHAR(26) UNIQUE`), `enquiry_id` (`FK -> enquiries.id CASCADE`), `business_id` (`FK -> businesses.id CASCADE`), `assignment_channel` (`ENUM('direct','organic_match','sponsored_priority')`), `status` (`ENUM('new','viewed','contacted','quoted','converted','lost','spam') DEFAULT 'new'`), `merchant_notes` (`TEXT NULL`), `viewed_at` (`TIMESTAMP NULL`), `first_contacted_at` (`TIMESTAMP NULL`), `created_at`, `updated_at`.
* **Indexes:** `UNIQUE(enquiry_id, business_id)`, `INDEX(business_id, status, created_at DESC)`.

#### `contact_reveal_logs`
* Columns: `id`, `business_id` (`FK CASCADE`), `user_id` (`FK SET NULL`), `channel` (`ENUM('phone','whatsapp','website','directions')`), `client_ip_hash` (`CHAR(64)`), `created_at`.
* **Indexes:** `INDEX(business_id, channel, created_at DESC)`, `INDEX(client_ip_hash, created_at)`.

---

### 3.7 Domain: Monetization, Sponsorships & Billing

#### `plans`
* Columns: `id`, `code` (`VARCHAR(60) UNIQUE`), `name` (`VARCHAR(120)`), `plan_type` (`ENUM('subscription','sponsored_slot','lead_pack')`), `price_minor` (`INT UNSIGNED`), `currency` (`CHAR(3) DEFAULT 'INR'`), `duration_days` (`SMALLINT UNSIGNED NULL`), `lead_credits` (`INT UNSIGNED NULL`), `features` (`JSON`), `is_active` (`BOOLEAN DEFAULT 1`).

#### `business_subscriptions`
* Columns: `id`, `business_id` (`FK CASCADE`), `plan_id` (`FK RESTRICT`), `status` (`ENUM('active','past_due','cancelled','expired')`), `starts_at`, `ends_at`, `auto_renew` (`BOOLEAN DEFAULT 0`), `created_at`, `updated_at`.
* **Indexes:** `INDEX(business_id, status, ends_at)`.

#### `sponsored_campaigns`
Controls clearly labelled sponsored placements in search and category pages.
* Columns: `id`, `public_id` (`CHAR(26) UNIQUE`), `business_id` (`FK CASCADE`), `city_id` (`FK RESTRICT`), `category_id` (`FK RESTRICT`), `locality_id` (`FK SET NULL`), `placement_type` (`ENUM('search_top','category_featured','home_banner')`), `priority_weight` (`SMALLINT UNSIGNED DEFAULT 100`), `budget_minor` (`INT UNSIGNED`), `spent_minor` (`INT UNSIGNED DEFAULT 0`), `starts_at` (`TIMESTAMP`), `ends_at` (`TIMESTAMP`), `status` (`ENUM('draft','pending_approval','active','paused','completed','rejected')`), `disclosure_label` (`VARCHAR(32) NOT NULL DEFAULT 'Sponsored'`), `created_at`, `updated_at`.
* **Indexes:** `INDEX(status, placement_type, city_id, category_id, starts_at, ends_at, priority_weight DESC)`.

#### `payments` & `invoices`
* `payments`: `id`, `public_id` (`CHAR(26) UNIQUE`), `business_id` (`FK RESTRICT`), `user_id` (`FK RESTRICT`), `payable_type` (`VARCHAR(80)`), `payable_id` (`BIGINT UNSIGNED`), `gateway` (`ENUM('razorpay','stripe')`), `gateway_order_id` (`VARCHAR(120)`), `gateway_payment_id` (`VARCHAR(120) NULL`), `idempotency_key` (`CHAR(36) UNIQUE`), `amount_minor` (`INT UNSIGNED`), `tax_minor` (`INT UNSIGNED`), `currency` (`CHAR(3) DEFAULT 'INR'`), `status` (`ENUM('initiated','authorized','captured','failed','refunded')`), `gateway_payload` (`JSON NULL`), `paid_at` (`TIMESTAMP NULL`), `created_at`, `updated_at`.
  * **Indexes:** `UNIQUE(idempotency_key)`, `UNIQUE(gateway, gateway_order_id)`, `INDEX(business_id, status, created_at DESC)`.
* `invoices`: `id`, `invoice_number` (`VARCHAR(40) UNIQUE`), `payment_id` (`FK -> payments.id RESTRICT UNIQUE`), `business_id` (`FK RESTRICT`), `billing_name`, `billing_gstin` (`VARCHAR(20) NULL`), `subtotal_minor`, `tax_minor`, `total_minor`, `private_pdf_path`, `issued_at`.
* `webhook_events`: `id`, `gateway` (`ENUM('razorpay','stripe')`), `provider_event_id` (`VARCHAR(140)`), `event_type` (`VARCHAR(100)`), `signature_verified` (`BOOLEAN NOT NULL DEFAULT 0`), `payload` (`JSON NOT NULL`), `status` (`ENUM('received','processing','processed','failed') DEFAULT 'received'`), `attempts` (`TINYINT UNSIGNED DEFAULT 0`), `last_error` (`TEXT NULL`), `processed_at` (`TIMESTAMP NULL`), `created_at`, `updated_at`.
  * **Indexes:** `UNIQUE(gateway, provider_event_id)` (guarantees strict idempotent deduplication), `INDEX(status, created_at)`.

---

### 3.8 Domain: Governance, SEO & Telemetry

* **`promotional_banners`**: `id`, `title`, `image_desktop_url`, `image_mobile_url`, `target_url`, `city_id` (`FK SET NULL`), `placement` (`ENUM('home_hero','home_mid','category_top')`), `sort_order`, `starts_at`, `ends_at`, `is_active`.
* **`url_redirects`**: `id`, `source_path` (`VARCHAR(300) UNIQUE`), `target_path` (`VARCHAR(300)`), `http_status` (`SMALLINT UNSIGNED DEFAULT 301`), `created_at`. (Preserves SEO equity when a business changes slug or locality).
* **`search_query_logs`**: `id`, `normalized_query` (`VARCHAR(160)`), `city_id` (`FK SET NULL`), `category_id` (`FK SET NULL`), `results_count` (`INT UNSIGNED`), `created_at`. `INDEX(city_id, created_at, normalized_query)`.
* **`audit_logs`**: `id`, `actor_user_id` (`FK SET NULL`), `actor_role`, `action` (`VARCHAR(100)`), `auditable_type`, `auditable_id`, `old_values` (`JSON NULL`), `new_values` (`JSON NULL`), `ip_address` (`VARCHAR(45)`), `created_at`. `INDEX(auditable_type, auditable_id)`, `INDEX(actor_user_id, created_at DESC)`.
* **`notification_logs`**: `id`, `user_id` (`FK SET NULL`), `channel` (`ENUM('sms','whatsapp','email','fcm')`), `template_key`, `recipient_masked`, `status` (`ENUM('queued','sent','delivered','failed')`), `provider_ref`, `error_message`, `created_at`.

---

## 4. Configurable Privacy Retention Policies & Legal Exceptions

All operational retention periods are driven by `config/privacy.php` (backed by environment variables) rather than hardcoded SQL intervals:

| Data Category / Table | Config Key (Default) | Automated Action | Legal / Compliance Retention Exceptions |
| :--- | :--- | :--- | :--- |
| **`otp_challenges`** | `PRIVACY_OTP_RETENTION_HOURS` (`24`) | Hard-delete expired challenges nightly | None (ephemeral security tokens) |
| **`enquiries` (Customer PII)** | `PRIVACY_ENQUIRY_PII_RETENTION_DAYS` (`90`) | Anonymize `customer_phone_e164` (`+91-REDACTED`) & scrub free-text PII on closed/expired enquiries; retain consent metadata | Exempt while `legal_hold = 1`, active customer/merchant dispute, or fraud investigation |
| **`contact_reveal_logs`** | `PRIVACY_CONTACT_LOG_RETENTION_DAYS` (`180`) | Aggregate into daily business analytics counters and purge raw IP-hashed logs | Extended if flagged for scraping/abuse investigation |
| **`search_query_logs`** | `PRIVACY_SEARCH_LOG_RETENTION_DAYS` (`90`) | Aggregate trending terms and prune raw rows | Contains no user PII (anonymized queries only) |
| **`business_verifications` (KYC Docs)** | `PRIVACY_KYC_RETENTION_YEARS` (`5`) after business closure | Purge private S3 documents & encrypted numbers once statutory window lapses | Retained while business is active + statutory anti-fraud / KYC audit period |
| **`payments` & `invoices`** | `PRIVACY_TAX_RECORD_RETENTION_YEARS` (`8`) | **Exempt from user erasure**; immutable archive | Mandatory under **Indian GST Act (Section 36)** & Income Tax Act (72–96 months) |
| **`audit_logs`** | `PRIVACY_AUDIT_LOG_RETENTION_YEARS` (`5`) | Append-only cold archive after 12 months | Required for CERT-In / security forensics & regulatory defense |
