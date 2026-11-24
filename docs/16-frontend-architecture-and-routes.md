# 16 - Frontend Architecture, NearGrid Justdial-Style UI Specification & Milestone 8 Plan

## 1. Architectural Overview & Guardrails

The `frontend/` application is a single **Next.js 14.2.35 (App Router) + React 18.3.1 + TypeScript 5.7 + Tailwind CSS 3.4** web application delivering three strictly separated experiences via isolated Route Groups:

1. **Customer Web Application (`src/app/(customer)/*`)**: Public SEO discovery, two-field autocomplete search, canonical directory hierarchies (`/[city]/[category]`, `/[city]/[locality]/[category]`), storefront profiles (`/[city]/business/[slug]`), DPDP-compliant enquiry capture, and customer reviews.
2. **Business Owner Dashboard (`src/app/(business)/*` / `/dashboard`)**: Dedicated merchant portal with isolated shell layout, navigation, Inbound Lead CRM, split-shift hours, services, photo gallery, and KYC verification history.
3. **Super Admin Dashboard (`src/app/(admin)/*` / `/admin` — Phase 3F)**: Dedicated administrative governance experience with separate layout, navigation, and role-based access control, consuming the authoritative Laravel Admin REST APIs (`/api/v1/admin/*`). Strictly **zero Filament dependencies**.

### Location Architecture & Launch Configuration
* **Geographical Hierarchy:** Strict `State` → `City` → `Location/Locality`. Country management is intentionally omitted.
* **Deployment Modes:** Configurable **Single-Location Mode** and **Multi-Location Mode** to support focused local deployments or multi-city expansion.
* **Initial Launch Location:** **Vellore**, with Chennai seeded as reference urban center.
* **Integrated Capabilities:** Powers customer proximity search, merchant address registration, directory listing hierarchies, and canonical SEO URL generation (`/{city}/{category}`).

### Security & Architecture Invariants (Preserved Unchanged)
1. **Zero Browser Token Exposure:** Sanctum Bearer tokens remain strictly inside AES-256-GCM encrypted server-side Redis sessions (`lbd_sid` opaque `HttpOnly; SameSite=Lax` cookie). Never exposed to browser JS, `localStorage`, `sessionStorage`, React props, or client API payloads.
2. **CSRF & Origin Defense:** Every authenticated browser mutation (`POST`, `PUT`, `PATCH`, `DELETE`) passes through Next.js BFF route handlers enforcing strict `Origin`/`Referer` + constant-time double-submit CSRF token (`lbd_csrf` + `X-CSRF-Token`).
3. **Independent Laravel Policies:** All owner mutations and private KYC operations are authorized independently by Laravel Policies (`BusinessPolicy`, `BusinessServicePolicy`, `BusinessMemberPolicy`, `BusinessMediaPolicy`, `KycDocumentPolicy`, `BusinessVerificationPolicy`, `UserPolicy`, `AuditLogPolicy`).
4. **Original NearGrid Branding:** Recreates the familiar high-utility Indian local business directory UX (Justdial-style density, scannability, dual-axis search, horizontal listing cards, sticky profile tabs) using **100% original NearGrid branding, palette, Lucide icons, and copy** — zero Justdial logos, trademarks, assets, or source code.
5. **Preserved Stage 1 Scope:** Strictly no ecommerce, marketplace checkout, or consumer mobile apps are introduced into Stage 1.

---

## 2. NearGrid Justdial-Style Design System & Reusable Tokens

Rather than a sparse monochrome SaaS aesthetic, NearGrid uses a warm, high-contrast, scannable Indian local-directory visual palette designed for instant trust cues and rapid action scanning:

| Token Role | Hex / Tailwind Value | Usage |
| :--- | :--- | :--- |
| **Brand Primary (NearGrid Blue)** | `#1D4ED8` (`brand-600`) / `#1E40AF` (`brand-700`) | Primary navigation accents, business title links, active tabs, primary search button, Free Listing CTA |
| **Brand Accent (Signal Orange)** | `#EA580C` (`accent-600`) / `#C2410C` (`accent-700`) | Search submit highlight, "Enquire / Best Deal" CTA, "Free Listing" pill highlight |
| **Rating Trust Green** | `#15803D` (`emerald-700`) / `#16A34A` (`emerald-600`) | Color-coded rating pill (`4.0+` green, `3.0–3.9` amber `#D97706`, `<3.0` rose `#E11D48`), `Open Now` indicator, WhatsApp action accent |
| **Verified Badge Blue** | `#0284C7` (`sky-600`) + `#E0F2FE` bg | `NearGrid Verified` shield badge |
| **Trusted Badge Indigo** | `#4338CA` (`indigo-700`) + `#EEF2FF` bg | `NearGrid Trusted` partner badge |
| **Sponsored Pill Amber** | `#92400E` text + `#FEF3C7` bg + `#FCD34D` border | Unmistakable high-contrast `Sponsored` disclosure label |
| **Page Canvas & Borders** | `#F3F4F6` (`gray-100`) canvas / `#FFFFFF` cards / `#E5E7EB` borders | Crisp card elevation separating filters, horizontal directory cards, and sidebars |
| **Typography & Density** | `Inter` (with Tamil Unicode fallback), compact `13px–15px` directory body, bold `18px–20px` card titles | High information density without clutter across desktop (`1280px+`), tablet (`768px`), and mobile (`375px`) |

### Shared UI Primitives (`src/shared/ui/`)
- `NearGridLogo.tsx` — Original geometric `NG` grid mark + `NearGrid` wordmark (customer & business variants).
- `RatingPill.tsx` — Color-coded rating pill (`★ 4.7` + review count) reflecting real rating tiers.
- `TrustBadges.tsx` — Reusable `Verified`, `Trusted`, `Sponsored` (with disclosure tooltip), `Open Now / Closed`, and `Est. Year` badges.
- `Skeletons.tsx` — Reusable loading skeletons for category grid, horizontal search cards, business profile hero/tabs, and dashboard panels.
- `StateFeedback.tsx` — Accessible `EmptyState`, `ErrorState` (with Retry button), and `PendingFeatureNotice` (for honest Phase 3C disclosures).
- `FallbackImage.tsx` — Responsive WebP image component with loading placeholder and clean fallback when a business has no approved photos.

---

## 3. Page-by-Page UI Comparison & Proposed Layouts

### 3.1 Global Customer Header (`CustomerHeader.tsx`)
| Aspect | Current M4 Layout | Proposed NearGrid Justdial-Style Layout |
| :--- | :--- | :--- |
| **Top Bar Structure** | Minimal SaaS bar: Logo + City dropdown + 2 links + Log in / Business Portal | Classic directory bar: **NearGrid Logo** + **Compact Inline Two-Field Search** (on inner pages) + **Language Toggle (`EN / தமிழ்`)** + **Free Listing** CTA (accent badge) + **Log in / Account Menu** |
| **Location Selector** | Hardcoded `DEMO_CITIES` | Live `GET /api/v1/locations/cities` with popular cities + Tamil labels + **"Detect Near Me"** (`navigator.geolocation` -> `/search/nearby`) |
| **Mobile Layout** | Basic dropdown | Sticky mobile header with city selector pill, compact search trigger, and slide-down account/menu drawer |

### 3.2 Homepage (`/` — `src/app/(customer)/page.tsx`)
| Aspect | Current M4 Layout | Proposed NearGrid Justdial-Style Layout |
| :--- | :--- | :--- |
| **Above the Fold** | Large SaaS hero text + basic form + demo chips | Compact directory header banner: **Prominent Two-Field Search Bar** (`[📍 City / Locality + Near Me]` + `[🔍 Search for Plumbers, Hospitals, உணவகம், Dentists...]` with **live debounced autocomplete dropdown** grouped by Categories, Services, Localities & Businesses) |
| **Category Grid** | 8 large SaaS cards with "demo" counts | **Dense Iconic Category Grid** (2x4 mobile, 4x2 tablet, 6–8 columns desktop) powered live by `GET /api/v1/categories` with clean rounded icon tiles, English + Tamil names, and 1-click navigation to `/[city]/[category]` or `/search?city=...&category=...` |
| **Local Discovery Sections** | 4 static `DEMO_BUSINESSES` cards with `DemoBanner` | **Live Local Discovery Strips** powered by `GET /api/v1/search` & `GET /api/v1/locations/cities`: (1) **Popular Services in {City}** quick-jump vertical cards (Home Services, Healthcare, Food & Dining, Automotive, Weddings, Education), (2) **Top Verified Businesses in {City}** (real API listings; clean empty state if DB has no active listings yet — zero fake businesses), (3) **Explore by City & Popular Localities** SEO internal link mesh, (4) **Free Business Listing Banner** |
| **Footer** | 3-column minimal footer | **Directory SEO Footer** with Popular Cities, Popular Categories, NearGrid Business links, Privacy/Compliance links, and EN/TA support info |

### 3.3 Search Results & Canonical Directory Pages (`/search`, `/[city]/[category]`, `/[city]/[locality]/[category]`)
| Aspect | Current M4 Layout | Proposed NearGrid Justdial-Style Layout |
| :--- | :--- | :--- |
| **URL & SEO** | Only `/search` with client-only state (not URL-synced) | Full URL synchronization on `/search` (`city`, `q`, `category`, `locality`, `lat`, `lng`, `radius_km`, `min_rating`, `verified_only`, `open_now`, `amenities[]`, `payment_methods[]`, `sort`, `page`, `locale`) **PLUS** SEO-canonical SSR routes `/[city]/[category]` and `/[city]/[locality]/[category]` using `meta.seo` (`canonical_url`, `robots`) returned by Laravel 13 |
| **Filter Controls** | Basic left panel (3 static filters) | **Quick Filter Strip** above results (`Sort By`, `Top Rated 4.0+`, `Verified`, `Open Now`, `Near Me / Radius`) **PLUS** **Left Filter Sidebar** (desktop sticky / mobile drawer) populated live from `GET /api/v1/search/filters`: Localities (with live listing counts), Category, Minimum Rating, Verified Only, Open Now, Amenities, Payment Methods, and Radius slider (when coordinates active) |
| **Business Listing Cards** | 2-column vertical SaaS cards with no images | **Traditional Single-Column Horizontal Directory Cards** (desktop) stacking cleanly on mobile: Left **Photo / Thumbnail (`card` WebP + fallback)** -> Center **Title + Verified/Trusted/Sponsored badges + Color Rating Pill + Address + Distance (`X.X km`) + Open Now status + Service tags** -> Bottom Action Strip (**Masked Call button**, **WhatsApp indicator**, **Enquire / View Profile**) |
| **Pagination & States** | None | Numbered **Pagination Bar** synced to URL `page`, result counts (`total_organic`, `sponsored_count`), **Skeleton loaders**, **Empty state with filter reset**, and **Error state with Retry** |

### 3.4 Business Profile Page (`/[city]/business/[slug]`)
| Aspect | Current M4 Layout | Proposed NearGrid Justdial-Style Layout |
| :--- | :--- | :--- |
| **Data Source** | Hardcoded `DEMO_BUSINESSES` | Server-rendered live `GET /api/v1/businesses/{citySlug}/{businessSlug}` (handling `301` redirect hints via Next.js `permanentRedirect()` and `404` via `notFound()`), with SEO `generateMetadata` + Schema.org JSON-LD (`LocalBusiness` / specific `schema_org_type`) |
| **Top Storefront Card** | Plain text box + fake colored gradient mosaic | **Real Approved Public Media Gallery** (hero + thumbnail strip + fullscreen lightbox for `logo`/`cover`/`gallery` WebP variants; graceful fallback when empty) + **Storefront Header** (Name, Verified/Trusted/Claimed/Est. badges, Color Rating Pill, Open Now + today's hours, Full Address, **Action Bar**: Masked Phone, WhatsApp badge, Website link, Copy/Share URL, Claim Listing modal for unclaimed listings) |
| **Navigation Tabs** | Long unorganized vertical scroll | **Sticky Directory Tab Bar**: `Overview` · `Services & Pricing` · `Photos` · `Opening Hours` · `FAQs` · `Reviews` (with smooth section navigation / tab views) |
| **Right Contact Sidebar** | Fake `EnquiryConsentPreview` | Sticky right column with: (1) **Contact & Directions Card** (masked phone, WhatsApp status, website, full formatted address, landmark, pincode, Google Maps directions link using real `lat/lng`), (2) **Enquiry Card** (honestly notes that Consented Enquiry API launches in Phase 3C — never fabricates submission), (3) **Claim This Business** CTA (live-connected to `POST /api/v1/businesses/{business}/claims` via BFF if `is_claimed === false`) |

### 3.5 Business Owner Dashboard (`/(business)/dashboard/*`)
| Route | Current M4 Status | Proposed Milestone 8 NearGrid Dashboard |
| :--- | :--- | :--- |
| **Shell (`BusinessShell.tsx`)** | Static sidebar, no business switcher | Upgraded NearGrid Business shell with **Active Business Switcher** (for multi-business owners via `GET /api/owner/businesses`), **"+ Add New Business"** modal/flow (`POST /api/owner/businesses`), live verification/status pill, and updated nav: `Overview`, `Business Profile`, `Photos & Services`, `Opening Hours`, `Team & Staff` *(new)*, `KYC & Verification` *(new)*, `Enquiries (Phase 3C)`, `Account & Devices` |
| `/dashboard` | Demo KPIs & demo leads | **Live Owned Businesses Overview**: real business cards, `status`, `completeness_score` progress bar, `is_verified`, `photo_count`, `services` count, quick links to edit profile/hours/media/KYC, and "+ Register Business" CTA (zero fake view/lead counts!) |
| `/dashboard/profile` | Local state preview | **Live Create/Edit Profile**: wired to `GET/POST/PUT /api/owner/businesses` + live `/locations/cities`, `/localities`, `/categories`, `/attributes` (editing name, tagline, description, category, city, locality, address, pincode, coordinates, contact info, amenities, payment methods) |
| `/dashboard/media-services` | Disabled photo upload + local services | **Live Media Pipeline + Services CRUD**: (1) **Public Media Manager** (`GET/POST/DELETE /api/owner/businesses/[id]/media` via multipart BFF proxy — upload logo/cover/gallery, show processing/ready/failed status, EXIF-stripped badge, preview WebP variants, delete), (2) **Services & Pricing CRUD** (`POST/PUT/DELETE /api/owner/businesses/[id]/services`) |
| `/dashboard/hours` | Local 7-day state | **Live Split-Shift & 24h Schedule Editor**: wired to `PUT /api/owner/businesses/[id]/hours` supporting Mon–Sun, closed toggle, 24h toggle, and optional 2nd shift (`slot_index = 2`) |
| `/dashboard/staff` *(new)* | Not present | **Live Team & Staff Membership Management**: wired to `GET/POST/PATCH/DELETE /api/owner/businesses/[id]/members` (Owner-only RBAC enforced by Laravel Policy; invite by phone, update role `manager`/`editor`/`lead_agent`, suspend/remove) |
| `/dashboard/verification` *(new)* | Not present | **Live KYC Document Upload & Verification History**: wired to `GET/POST/DELETE /api/owner/businesses/[id]/kyc-documents`, `GET /api/owner/businesses/[id]/verifications`, and **BFF-proxied secure signed download** (`GET /api/owner/businesses/[id]/kyc-documents/[docId]/download` — mints 60s signed URL & streams bytes server-side so zero Bearer token or raw S3 path ever touches browser JS!) |
| `/dashboard/enquiries` | Demo leads | **Honest Pending Module Notice**: removes fabricated demo leads and clearly documents that Consented Lead CRM (`GET/PATCH /api/v1/owner/leads`) is scheduled for Phase 3C |
| `/dashboard/settings` | Live Identity API | Preserved live connection to `/api/auth/me`, `/devices`, `/sessions`, `/logout-all` |

---

## 4. Complete Live Backend API vs. Missing API Matrix

### 4.1 Live Laravel 13 APIs Integrated in Milestone 8
- **Public Read Proxy / Direct Server Fetch (`src/lib/api/public-directory.ts` + public BFF routes `/api/public/*` for client-side debounced search/autocomplete/filters):**
  - `GET /api/v1/locations/cities`, `GET /api/v1/locations/cities/{citySlug}/localities`
  - `GET /api/v1/categories`, `GET /api/v1/attributes`
  - `GET /api/v1/search`, `GET /api/v1/search/nearby`, `GET /api/v1/search/suggestions`, `GET /api/v1/search/filters`
  - `GET /api/v1/businesses/{citySlug}/{businessSlug}`
- **Authenticated Owner & Claim BFF Routes (`src/app/api/owner/*` & `src/app/api/businesses/*` — all enforcing `lbd_sid` + Origin + CSRF on mutations):**
  - `POST /api/businesses/[businessId]/claims` -> `POST /api/v1/businesses/{business}/claims`
  - `GET / POST /api/owner/businesses` -> `GET / POST /api/v1/owner/businesses`
  - `GET / PUT /api/owner/businesses/[businessId]` -> `GET / PUT /api/v1/owner/businesses/{business}`
  - `PUT /api/owner/businesses/[businessId]/hours` -> `PUT /api/v1/owner/businesses/{business}/hours`
  - `POST /api/owner/businesses/[businessId]/services` & `PUT / DELETE /api/owner/businesses/[businessId]/services/[serviceId]`
  - `GET / POST /api/owner/businesses/[businessId]/members` & `PATCH / DELETE /api/owner/businesses/[businessId]/members/[memberId]`
  - `GET / POST /api/owner/businesses/[businessId]/media` (multipart `FormData` streaming) & `DELETE /api/owner/businesses/[businessId]/media/[mediaId]`
  - `GET / POST /api/owner/businesses/[businessId]/verifications`
  - `GET / POST /api/owner/businesses/[businessId]/kyc-documents` (multipart `FormData` streaming), `DELETE .../kyc-documents/[docId]`, & `GET .../kyc-documents/[docId]/download` (server-side signed-URL mint + private byte stream proxy)

### 4.2 Explicitly Missing Backend APIs (Phase 3C — NOT Fabricated)
| Feature | Missing Backend Endpoint(s) | Milestone 8 UI Handling |
| :--- | :--- | :--- |
| **Customer Enquiries & Owner Lead CRM** | `POST /api/v1/enquiries`, `GET / PATCH /api/v1/owner/leads` | Business profile enquiry card & `/dashboard/enquiries` show a clear *"Phase 3C — Consented Enquiry API not yet enabled"* notice (zero fake submission success or dummy leads). |
| **Individual Customer Reviews & Replies** | `GET / POST /api/v1/businesses/{id}/reviews`, `POST /api/v1/reviews/{id}/vote` | Business profile shows real aggregate `rating_avg` & `review_count` from `GET /api/v1/businesses/{city}/{slug}`; Reviews tab notes individual review list/submission opens in Phase 3C. |
| **Phone Reveal Logging** | `POST /api/v1/businesses/{id}/contact-reveal` | Listings & profiles show real `phone_masked` (`+9198401XXXXX`) cleanly with zero unmasked phone leakage. |
| **Merchant Traffic Analytics** | `GET /api/v1/owner/analytics` | Dashboard Overview shows real live business health metrics (`completeness_score`, `status`, `is_verified`, `photo_count`, active services count) instead of dummy 30-day view numbers. |

---

## 5. Incremental Implementation Plan (Awaiting Approval)

To avoid rewriting all pages in a single uncontrolled operation, Milestone 8 will execute in **6 incremental steps**:

1. **Step 1 — Design Tokens, Shared UI Primitives & BFF Proxy Layer:**
   - Update `tailwind.config.ts` / `globals.css` with NearGrid Justdial-style palette tokens.
   - Build reusable `src/shared/ui/` primitives (`NearGridLogo`, `RatingPill`, `TrustBadges`, `Skeletons`, `StateFeedback`, `FallbackImage`).
   - Implement public API client + authenticated `/api/owner/*` & `/api/businesses/*` BFF route handlers (including multipart upload & server-side private KYC stream proxy) preserving 100% of existing CSRF/Origin/HttpOnly cookie invariants.
2. **Step 2 — Customer Header, Two-Field Live Autocomplete Search Bar & Homepage (`/`):**
   - Build Justdial-style NearGrid header (live cities, `EN/தமிழ்` locale toggle, Free Listing CTA, account actions) and debounced, AbortController-cancelling two-field search bar (`GET /api/v1/search/suggestions`).
   - Rebuild `/` with dense iconic category grid (`GET /api/v1/categories`), local discovery strips (`GET /api/v1/search`), city/locality SEO links, and zero hardcoded demo listings.
3. **Step 3 — Search Results & Canonical Directory Routes (`/search`, `/[city]/[category]`, `/[city]/[locality]/[category]`):**
   - Implement URL-synchronized live search (`GET /api/v1/search`, `/nearby`, `/filters`), quick filter strip + left filter sidebar, traditional horizontal business listing cards with WebP photos, rating pills, distance, Sponsored labels, pagination, and SEO canonical/noindex metadata.
4. **Step 4 — Business Profile Page (`/[city]/business/[slug]`):**
   - Connect SSR profile page to live `GET /api/v1/businesses/{citySlug}/{businessSlug}` (with `301` redirect handling, JSON-LD Schema.org, real approved WebP photo gallery + lightbox, sticky tab bar, live services/hours/FAQs, right contact/directions sidebar, and live unclaimed business claim flow).
5. **Step 5 — Business Owner Dashboard (`/dashboard/*`):**
   - Connect Overview, Business Profile create/edit, Photos & Services (real multipart upload/delete + services CRUD), Opening Hours (split shifts & 24h), Team & Staff (`/dashboard/staff`), KYC & Verification (`/dashboard/verification` with secure BFF download), honest Phase 3C Enquiries notice, and existing live Account & Devices settings.
6. **Step 6 — Full Test Suite & Responsive Browser Smoke Verification:**
   - Add frontend Vitest integration/component tests covering homepage API loading, autocomplete debounce/abort, URL filter sync, business profile, dashboard CRUD/media/KYC, expired sessions, CSRF protection, cross-business 403 handling, and empty/error states.
   - Run backend PHPUnit, frontend Vitest, `tsc --noEmit`, `npm run lint`, `npm run build`, and live browser verification at **desktop (`1280px`)**, **tablet (`768px`)**, and **mobile (`375px`)** widths, then update `docs/15-implementation-progress.md` and commit Milestone 8 to `main`.
