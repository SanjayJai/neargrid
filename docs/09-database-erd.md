# 09 - Database Entity-Relationship Diagrams (ERD)

## 1. Executive Summary

This document visualizes the complete MySQL 8.0 relational data model defined in [`08-database-design.md`](file:///C:/Users/sanja/.gemini/antigravity/scratch/local-biz-directory/docs/08-database-design.md). It includes a **Master Domain Overview ERD**, followed by three detailed sub-diagrams covering **Identity & Geography**, **Business Directory & Verification**, and **Reviews, Leads & Monetization**, plus a **Referential Integrity Matrix**.

---

## 2. Master System ERD (Core Relationships)

```mermaid
erDiagram
    USERS ||--o{ USER_ROLES : has
    ROLES ||--o{ USER_ROLES : grants
    USERS ||--o{ BUSINESSES : owns
    USERS ||--o{ REVIEWS : authors
    USERS ||--o{ ENQUIRIES : submits
    USERS ||--o{ USER_BOOKMARKS : saves

    STATES ||--o{ CITIES : contains
    CITIES ||--o{ LOCALITIES : contains
    CITIES ||--o{ BUSINESSES : hosts
    LOCALITIES ||--o{ BUSINESSES : locates

    CATEGORIES ||--o{ CATEGORIES : parent_of
    CATEGORIES ||--o{ BUSINESSES : primary_for
    BUSINESSES ||--o{ BUSINESS_CATEGORIES : tagged_in
    CATEGORIES ||--o{ BUSINESS_CATEGORIES : includes

    BUSINESSES ||--o{ BUSINESS_HOURS : operates
    BUSINESSES ||--o{ BUSINESS_MEDIA : displays
    BUSINESSES ||--o{ BUSINESS_SERVICES : offers
    BUSINESSES ||--o{ BUSINESS_VERIFICATIONS : audited_by
    BUSINESSES ||--o{ REVIEWS : receives
    BUSINESSES ||--o{ LEAD_ASSIGNMENTS : receives
    BUSINESSES ||--o{ SPONSORED_CAMPAIGNS : promotes
    BUSINESSES ||--o{ BUSINESS_SUBSCRIPTIONS : subscribes

    ENQUIRIES ||--o{ LEAD_ASSIGNMENTS : distributed_as
    PLANS ||--o{ BUSINESS_SUBSCRIPTIONS : defines
    BUSINESSES ||--o{ PAYMENTS : pays
    PAYMENTS ||--o| INVOICES : generates
```

---

## 3. Sub-ERD 1: Identity, RBAC & Geography

```mermaid
erDiagram
    USERS {
        bigint id PK
        char public_id UK
        varchar name
        varchar email UK
        varchar phone_e164 UK
        varchar status
        timestamp phone_verified_at
        timestamp totp_enabled_at
        timestamp deleted_at
    }

    ROLES {
        bigint id PK
        varchar name UK
        varchar label
    }

    USER_ROLES {
        bigint user_id PK,FK
        bigint role_id PK,FK
        bigint assigned_by_user_id FK
        timestamp created_at
    }

    PERSONAL_ACCESS_TOKENS {
        bigint id PK
        varchar tokenable_type
        bigint tokenable_id FK
        varchar name
        varchar token UK
        json abilities
        timestamp expires_at
    }

    OTP_CHALLENGES {
        bigint id PK
        char challenge_id UK
        varchar phone_e164
        varchar purpose
        char code_hash
        tinyint attempts
        timestamp expires_at
        timestamp verified_at
    }

    USER_DEVICES {
        bigint id PK
        bigint user_id FK
        varchar platform
        varchar device_identifier
        varchar fcm_token
        timestamp last_active_at
    }

    STATES {
        bigint id PK
        char country_code
        varchar name
        varchar slug
        boolean is_active
    }

    CITIES {
        bigint id PK
        bigint state_id FK
        varchar name
        varchar slug UK
        point center_point
        varchar timezone
        boolean is_popular
        boolean is_active
    }

    LOCALITIES {
        bigint id PK
        bigint city_id FK
        varchar name
        varchar slug
        varchar pincode
        point center_point
        boolean is_active
    }

    USERS ||--o{ USER_ROLES : assigned
    ROLES ||--o{ USER_ROLES : maps
    USERS ||--o{ PERSONAL_ACCESS_TOKENS : authenticates
    USERS ||--o{ USER_DEVICES : registers
    STATES ||--o{ CITIES : contains
    CITIES ||--o{ LOCALITIES : divides_into
```

---

## 4. Sub-ERD 2: Business Directory, Taxonomy & Verification

```mermaid
erDiagram
    CATEGORIES {
        bigint id PK
        bigint parent_id FK
        varchar name
        varchar slug UK
        varchar schema_org_type
        tinyint depth
        boolean is_featured
        smallint sort_order
        boolean is_active
    }

    BUSINESSES {
        bigint id PK
        char public_id UK
        bigint owner_user_id FK
        bigint primary_category_id FK
        bigint city_id FK
        bigint locality_id FK
        varchar name
        varchar slug
        point location_point
        varchar primary_phone_e164
        varchar status
        boolean is_claimed
        boolean is_verified
        boolean is_trusted
        decimal rating_avg
        decimal bayesian_score
        int review_count
        tinyint completeness_score
    }

    BUSINESS_CATEGORIES {
        bigint business_id PK,FK
        bigint category_id PK,FK
        boolean is_primary
    }

    BUSINESS_HOURS {
        bigint id PK
        bigint business_id FK
        tinyint day_of_week
        tinyint slot_index
        time open_time
        time close_time
        boolean is_24_hours
        boolean is_closed
    }

    BUSINESS_MEDIA {
        bigint id PK
        char public_id UK
        bigint business_id FK
        varchar media_type
        varchar webp_full_path
        varchar webp_thumb_path
        varchar moderation_status
        smallint sort_order
    }

    BUSINESS_SERVICES {
        bigint id PK
        bigint business_id FK
        varchar name
        int price_min_minor
        int price_max_minor
        char currency
        boolean is_active
    }

    AMENITIES {
        bigint id PK
        varchar name
        varchar slug UK
        varchar group_name
    }

    BUSINESS_AMENITIES {
        bigint business_id PK,FK
        bigint amenity_id PK,FK
    }

    PAYMENT_METHODS {
        bigint id PK
        varchar name
        varchar slug UK
    }

    BUSINESS_PAYMENT_METHODS {
        bigint business_id PK,FK
        bigint payment_method_id PK,FK
    }

    BUSINESS_FAQS {
        bigint id PK
        bigint business_id FK
        varchar question
        text answer
        smallint sort_order
    }

    BUSINESS_VERIFICATIONS {
        bigint id PK
        char public_id UK
        bigint business_id FK
        bigint submitted_by_user_id FK
        varchar document_type
        text document_number_encrypted
        varchar private_file_path
        varchar status
        bigint reviewed_by_admin_id FK
    }

    BUSINESS_CLAIMS {
        bigint id PK
        bigint business_id FK
        bigint claimant_user_id FK
        varchar proof_file_path_private
        varchar status
        bigint reviewed_by_admin_id FK
    }

    CATEGORIES ||--o{ CATEGORIES : sub_category
    CATEGORIES ||--o{ BUSINESSES : primary_vertical
    BUSINESSES ||--o{ BUSINESS_CATEGORIES : links
    CATEGORIES ||--o{ BUSINESS_CATEGORIES : includes
    BUSINESSES ||--o{ BUSINESS_HOURS : schedules
    BUSINESSES ||--o{ BUSINESS_MEDIA : hosts
    BUSINESSES ||--o{ BUSINESS_SERVICES : lists
    BUSINESSES ||--o{ BUSINESS_AMENITIES : provides
    AMENITIES ||--o{ BUSINESS_AMENITIES : offered_by
    BUSINESSES ||--o{ BUSINESS_PAYMENT_METHODS : accepts
    PAYMENT_METHODS ||--o{ BUSINESS_PAYMENT_METHODS : accepted_by
    BUSINESSES ||--o{ BUSINESS_FAQS : answers
    BUSINESSES ||--o{ BUSINESS_VERIFICATIONS : kyc_records
    BUSINESSES ||--o{ BUSINESS_CLAIMS : claim_requests
```

---

## 5. Sub-ERD 3: Reviews, Privacy-Compliant Leads, Monetization & Governance

```mermaid
erDiagram
    REVIEWS {
        bigint id PK
        char public_id UK
        bigint business_id FK
        bigint user_id FK
        tinyint rating
        varchar title
        text body
        varchar moderation_status
        int helpful_count
        text owner_reply
        timestamp created_at
    }

    REVIEW_MEDIA {
        bigint id PK
        bigint review_id FK
        varchar webp_full_path
        varchar moderation_status
    }

    REVIEW_VOTES {
        bigint review_id PK,FK
        bigint user_id PK,FK
        varchar vote_type PK
        varchar report_reason
    }

    USER_BOOKMARKS {
        bigint user_id PK,FK
        bigint business_id PK,FK
        timestamp created_at
    }

    ENQUIRIES {
        bigint id PK
        char public_id UK
        bigint customer_user_id FK
        varchar enquiry_type
        bigint target_business_id FK
        bigint category_id FK
        bigint city_id FK
        bigint locality_id FK
        varchar customer_name
        varchar customer_phone_e164
        varchar urgency
        boolean consent_given
        varchar consent_version
        timestamp consent_at
        timestamp phone_verified_at
        varchar status
    }

    LEAD_ASSIGNMENTS {
        bigint id PK
        char public_id UK
        bigint enquiry_id FK
        bigint business_id FK
        varchar assignment_channel
        varchar status
        text merchant_notes
        timestamp viewed_at
        timestamp first_contacted_at
    }

    CONTACT_REVEAL_LOGS {
        bigint id PK
        bigint business_id FK
        bigint user_id FK
        varchar channel
        char client_ip_hash
        timestamp created_at
    }

    PLANS {
        bigint id PK
        varchar code UK
        varchar name
        varchar plan_type
        int price_minor
        char currency
        smallint duration_days
        int lead_credits
    }

    BUSINESS_SUBSCRIPTIONS {
        bigint id PK
        bigint business_id FK
        bigint plan_id FK
        varchar status
        timestamp starts_at
        timestamp ends_at
    }

    SPONSORED_CAMPAIGNS {
        bigint id PK
        char public_id UK
        bigint business_id FK
        bigint city_id FK
        bigint category_id FK
        bigint locality_id FK
        varchar placement_type
        smallint priority_weight
        int budget_minor
        int spent_minor
        varchar status
        varchar disclosure_label
    }

    PAYMENTS {
        bigint id PK
        char public_id UK
        bigint business_id FK
        bigint user_id FK
        varchar gateway
        varchar gateway_order_id
        char idempotency_key UK
        int amount_minor
        int tax_minor
        varchar status
        timestamp paid_at
    }

    INVOICES {
        bigint id PK
        varchar invoice_number UK
        bigint payment_id FK,UK
        bigint business_id FK
        varchar billing_gstin
        int total_minor
        varchar private_pdf_path
    }

    AUDIT_LOGS {
        bigint id PK
        bigint actor_user_id FK
        varchar actor_role
        varchar action
        varchar auditable_type
        bigint auditable_id
        timestamp created_at
    }

    REVIEWS ||--o{ REVIEW_MEDIA : attaches
    REVIEWS ||--o{ REVIEW_VOTES : receives
    ENQUIRIES ||--o{ LEAD_ASSIGNMENTS : routes_to
    PLANS ||--o{ BUSINESS_SUBSCRIPTIONS : instantiates
    PAYMENTS ||--o| INVOICES : issues
```

---

## 6. Referential Integrity & Cascade Policy Matrix

| Parent Table | Child Table | Cardinality | `ON DELETE` Rule | Rationale |
| :--- | :--- | :--- | :--- | :--- |
| `states` | `cities` | `1:N` | `RESTRICT` | Prevents accidental deletion of active geographic hierarchies |
| `cities` | `localities` / `businesses` | `1:N` | `RESTRICT` | Protects active listings and SEO URLs |
| `categories` | `businesses` (`primary_category_id`) | `1:N` | `RESTRICT` | Categories must be archived (`is_active=0`), not hard-deleted |
| `users` | `businesses` (`owner_user_id`) | `1:N` | `SET NULL` | Preserves verified directory listing if user account is removed (reverts to unclaimed or admin-reviewed) |
| `users` | `user_roles` / `user_devices` / `user_bookmarks` | `1:N` | `CASCADE` | Ephemeral/personal preference bindings cleanly removed with user |
| `businesses` | `business_hours` / `business_media` / `business_services` / `business_faqs` | `1:N` | `CASCADE` | Child storefront metadata belongs exclusively to the business |
| `businesses` | `payments` / `invoices` | `1:N` | `RESTRICT` | Financial & GST tax records must remain immutable for 7+ years |
| `enquiries` | `lead_assignments` | `1:N` | `CASCADE` | Assignment rows have no meaning independent of parent enquiry |
| `reviews` | `review_media` / `review_votes` | `1:N` | `CASCADE` | Attachments and votes follow parent review lifecycle |
| `payments` | `invoices` | `1:0..1` | `RESTRICT` | Protects sequential GST invoice numbering integrity |
