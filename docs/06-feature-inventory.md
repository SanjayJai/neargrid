# 06 - Comprehensive Feature Inventory & System Architecture

## 1. Executive Summary

This document synthesizes the findings from the Phase 1 Discovery and Analysis of Justdial. It provides an exhaustive feature matrix categorized by user persona (End Customer, Business Owner, Platform Admin), outlines the complete cleanroom system architecture following the approved technology stack, and details our verified evidence, identified unknowns, and recommendations for Phase 2.

---

## 2. Comprehensive Feature Matrix

### 2.1 Customer-Facing Features (Next.js Web & Future Flutter App)

| Feature Module | Feature Description | Priority |
| :--- | :--- | :--- |
| **Location Detection** | Automatic HTML5 GPS detection, reverse geocoding, top metro selector, and manual locality input | P0 (Must Have) |
| **Dual-Axis Search** | Combined keyword/category and city/locality search bar with debounced typeahead suggestions | P0 (Must Have) |
| **Category Browsing** | 60+ primary category hotkeys, nested subcategories, and SEO-friendly slug routes | P0 (Must Have) |
| **SERP Listing Stream** | Fast paginated results displaying business cards with ratings, verified badges, and addresses | P0 (Must Have) |
| **Multi-Facet Filters** | Radius slider, locality checkboxes, rating threshold (4.0+, 4.5+), Open Now, Verified Only | P0 (Must Have) |
| **Sorting Engine** | Sort by Relevance (weighted), Rating (high to low), Distance (nearest), and Popularity | P0 (Must Have) |
| **Business Storefront** | Dedicated business profile with 5-photo mosaic, working hours, services, map, and FAQs | P0 (Must Have) |
| **Phone Number Reveal** | "Show Number" action with intent logging, rate limiting, and native tel: dialer trigger | P0 (Must Have) |
| **Direct & Broadcast Enquiry**| "Get Best Deal" / "Enquire Now" modal capturing service needs with instant OTP verification | P0 (Must Have) |
| **Hot Lead on Blur** | Real-time lead capture trigger when customer enters mobile number and moves focus | P1 (High) |
| **WhatsApp Direct Connect** | 1-click WhatsApp web/app link pre-filled with business inquiry context | P1 (High) |
| **Reviews & Ratings** | Star rating, multi-attribute scoring, written reviews, photo attachments, helpful votes | P1 (High) |
| **User Authentication** | Passwordless mobile OTP login returning Laravel Sanctum Bearer tokens | P0 (Must Have) |
| **Bookmarks & History** | Save favorite businesses and review recently browsed listings | P2 (Medium) |

---

### 2.2 Business Owner Features (Next.js Dashboard: `apps/business`)

| Feature Module | Feature Description | Priority |
| :--- | :--- | :--- |
| **Free Listing Registration** | Multi-step onboarding: Company info, contact person, mobile OTP, address, category, and timings | P0 (Must Have) |
| **Inbound Lead CRM** | Real-time pipeline to view customer enquiries with status updates (New, Contacted, Converted) | P0 (Must Have) |
| **One-Click Lead Actions** | Direct "Call Lead" and "WhatsApp Lead" actions from within the dashboard | P0 (Must Have) |
| **Storefront Profile Editor** | Manage business description, services offered, starting prices, and operational timings | P0 (Must Have) |
| **Media Uploader** | Upload storefront facade, interior, menu, service, and certificate images | P1 (High) |
| **Reputation Management** | View incoming customer reviews and submit official merchant responses | P1 (High) |
| **KYC & Verification** | Upload trade licenses, GSTIN certificates, and electricity bills for verification badge audits | P1 (High) |
| **Performance Analytics** | Track profile impressions, phone reveals, and customer enquiries over 7/30-day windows | P2 (Medium) |

---

### 2.3 Super Admin Features (Next.js Dashboard in Phase 3F via Laravel Admin APIs)
> **Note**: In strict accordance with architecture instructions, this panel is developed in **Next.js** (`src/app/(admin)/*`, zero Filament) consuming the authoritative Laravel Admin REST APIs (`/api/v1/admin/*`).

| Feature Module | Feature Description | Priority |
| :--- | :--- | :--- |
| **Admin Authentication & RBAC**| Secure server-side BFF login with roles: Super Admin, Verifier, Moderator, Support | P0 (Must Have) |
| **Merchant Verification Queue**| Audit submitted business verifications, inspect private KYC documents via 60s signed URLs, approve/reject | P0 (Must Have) |
| **Badge Management** | Grant or revoke **"Verified"** and **"Trust"** status badges based on verified interaction and KYC audits | P0 (Must Have) |
| **Location & City Manager** | Manage State → City → Locality hierarchy (no country management; single/multi-location modes; launch location: Vellore) | P0 (Must Have) |
| **Review & Media Moderation** | Inspect flagged/suspicious reviews and photos, hide/approve content, with automated Bayesian rating recalculation | P1 (High) |
| **User & Session Eviction** | Suspend abusive users with instant Sanctum token revocation and active database session termination | P0 (Must Have) |
| **Enquiries Abuse Management**| Inspect lead dispatches and administratively cancel abusive enquiries | P1 (High) |
| **System Audit Logs Feed** | Filterable immutable logs feed capturing all governance actions | P0 (Must Have) |

---

## 3. System Architecture & Separation of Concerns

```mermaid
flowchart LR
    subgraph Frontend Tier (Next.js App Router)
        W["Customer Web Portal<br/>(src/app/(customer))"]
        B["Business Owner CRM<br/>(src/app/(business))"]
        ADM["Super Admin Dashboard<br/>(Phase 3F: src/app/(admin))"]
    end

    subgraph Backend & Admin Tier (Laravel 13)
        API["Laravel 13 REST API<br/>(/api/v1/* & /api/v1/admin/*)"]
    end

    subgraph Data & Storage Tier
        DB[("MySQL 8.0<br/>Spatial + FullText")]
        RD[("Redis<br/>Cache & Suggestions")]
        S3[("S3 / Cloud Storage<br/>Optimized Images")]
    end

    W -->|BFF /api/*| API
    B -->|BFF /api/owner/*| API
    ADM -->|BFF /api/admin/*| API
    API --> DB
    API --> RD
    API --> S3
```
    B -->|REST JSON + Bearer| API
    M -->|REST JSON + Bearer| API
    ADM -->|Eloquent ORM| DB
    API --> DB
    API --> RD
    API --> S3
    ADM --> S3
```

---

## 4. Phase 1 Discovery Findings & Evidence Summary

### 4.1 Verified Evidence (Observed from Live Discovery)
1. **Next.js SSR Frontend**: Verified via `robots.txt` path `Disallow: /_next/` indicating Next.js route structures and server-rendered components.
2. **Dual-Axis Search UX**: Verified presence of dual input controls (`#city` / `#insrch` and `#srchbx`) separating geographical filtering from service intent.
3. **Category Hierarchy**: Extracted 60 active contextual categories and 24 featured SEO verticals from live page configuration objects (`ContextualHotkey` and `HomePageSeoLink`).
4. **"Hot Lead on Blur" Optimization**: Discovered real-time `hotLeadApi('mobile_inputfield_onblur')` event listeners on phone inputs capturing abandoned leads before form submission.
5. **Multi-Step Merchant Onboarding**: Discovered specific form parameters on `/free-listing` (`cn`, `flcity`, `fname`, `lname`, `fmb0`, `fph0`, and 6-digit OTP fields `pin_0` to `pin_5`).
6. **Binary & Asset Inspection (via REA)**: Local analysis of CDN JavaScript (`webfont.min.js`) via REA MCP server confirmed production stripping of source maps (`sourceMappingURL`), validating that production assets are heavily bundled and minified.

### 4.2 Known Unknowns
1. **Telephony Call Masking**: Specific telecommunication bridge provider and pricing structures used for virtual number routing.
2. **Dynamic Lead Bidding Algorithm**: Internal ranking weight formula between paid sponsored merchants and high-rated organic listings.
3. **SMS / WhatsApp Gateway Fallback**: Exact retry timing thresholds for OTP delivery across SMS and WhatsApp channels.

### 4.3 Recommendations for Implementation
1. **Frontend Architecture**: Single Next.js 14+ application with three distinct experiences:
   * `(customer)`: Customer discovery, search, directory listings, and DPDP-compliant enquiry capture.
   * `(business)`: Business owner dashboard, lead CRM, opening hours, services, and photo management.
   * `(admin)`: Super Admin governance dashboard (Phase 3F), consuming Laravel Admin REST APIs.
2. **Authentication Architecture**: Implement **Laravel Sanctum** providing personal access Bearer tokens bridged through server-side Next.js BFF route handlers with `HttpOnly` cookies. Sanctum tokens are strictly prevented from reaching browser `localStorage`.
3. **Location Architecture**: State → City → Locality hierarchy without country management, supporting single/multi-location deployment modes, launching initially in **Vellore**.
4. **Database Optimization**:
   * Leverage MySQL 8.0 `POINT` spatial columns with spatial indexes for fast geolocation distance queries (`ST_Distance_Sphere`).
   * Leverage MySQL FullText indexing for natural language category and business keyword searches.
5. **Lead Engine Design**: Build a decoupled Laravel Job / Queue architecture (`DispatchEnquiryLeadsJob`) to match verified merchants asynchronously without blocking customer HTTP requests.
