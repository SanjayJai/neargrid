# 12 - Technical SEO & Organic Growth Architecture

## 1. Executive Summary

Organic search traffic (e.g., *"dentists in andheri west mumbai"*, *"plumbers near me"*, *"[business name] reviews"*) is the primary zero-CAC acquisition channel for a local business directory.

In Phase 1, we confirmed that Justdial relies heavily on city-scoped URLs (`/Mumbai/...`), SSR HTML delivery, Schema.org markup, and curated internal link meshes (`HomePageSeoLink`), while carrying legacy URL artifacts (`_fil`, `nct-10096237`, `_BZDET`). Our cleanroom SEO architecture leverages **Next.js App Router (SSR + ISR)** and **clean semantic URLs** to maximize indexation efficiency, rich snippet eligibility, and Core Web Vitals scores without legacy baggage.

---

## 2. Canonical URL Taxonomy & Route Disambiguation

### 2.1 Approved URL Structure

| Page Type | Canonical URL Pattern | Example | Indexation Policy |
| :--- | :--- | :--- | :--- |
| **Homepage** | `/` | `https://example.com/` | `index, follow` |
| **City Hub** | `/{city-slug}` | `/mumbai` | `index, follow` |
| **City + Category** | `/{city-slug}/{category-slug}` | `/mumbai/dentists` | `index, follow` |
| **City + Locality + Category** | `/{city-slug}/{locality-slug}/{category-slug}` | `/mumbai/andheri-west/dentists` | `index, follow` (if >= 3 active listings) |
| **Business Profile** | `/{city-slug}/business/{business-slug}` | `/mumbai/business/apex-dental-care-andheri-west` | `index, follow` |
| **Dynamic / Faceted Search** | `/search?city=...&q=...&min_rating=4.5` | `/search?city=mumbai&q=root+canal&open_now=1` | `noindex, follow` |
| **Free Listing Landing** | `/list-your-business` | `/list-your-business` | `index, follow` |
| **Business Owner Dashboard** | `/dashboard/*` (route group `(business)`) | `/dashboard/leads` | `noindex, nofollow` |
| **Customer Account** | `/account/*` | `/account/bookmarks` | `noindex, nofollow` |
| **Custom Blade Admin** | `/admin/*` | `/admin/verifications` | `noindex, nofollow` + `Disallow` |

### 2.2 Architecture Decision: Explicit `/business/` Segment vs. Flat `/{city}/{slug}`

* **Decision:** Prefix business profile URLs with `/business/` (`/{city-slug}/business/{business-slug}`) rather than placing businesses directly at `/{city-slug}/{slug}`.
* **Why Recommended:**
  1. **Zero Route Collision:** Eliminates ambiguity between a category slug (`/mumbai/dentists`), a locality slug (`/mumbai/andheri-west`), and a business name slug (`/mumbai/business/mumbai-dentists`).
  2. **Deterministic Next.js App Router Resolution:** Next.js routes directly to `[city]/business/[slug]/page.tsx` without an extra database lookup to guess whether segment #2 is a category, locality, or business.
  3. **Clean Analytics & Crawl Segmentation:** Allows effortless Google Search Console and server-log segmentation (`/*/business/*`) to measure profile page crawl health independently from category listing pages.
* **Alternatives Considered:**
  * *Opaque Suffixes (like Justdial's `..._BZDET` or `/nct-10096237`):* Prevents collisions, but produces ugly, less-clickable URLs that leak internal database IDs.
  * *Catch-all Dispatcher (`[...segments]/page.tsx`):* Forces every incoming page request to execute a multi-table slug lookup before rendering, harming TTFB and complicating ISR caching.

---

## 3. Next.js Rendering & Revalidation Strategy (SSR vs. ISR)

| Route | Strategy | Revalidation / Cache Rule | Rationale |
| :--- | :--- | :--- | :--- |
| `/` (Homepage) | **ISR** | `revalidate: 3600` (1 hr) + On-demand tag `home` | Ultra-fast TTFB; updated immediately when admin edits banners/featured categories |
| `/{city}` (City Hub) | **ISR** | `revalidate: 3600` + On-demand tag `city:{slug}` | High-traffic regional entry point |
| `/{city}/{category}` | **ISR (Top 500 pairs) + On-demand ISR** | `revalidate: 1800` (30 min) + `Cache-Control: s-maxage=1800, stale-while-revalidate=86400` | Pre-warms top metro+category SERPs; generates long-tail pages on first crawl and caches at edge |
| `/{city}/{locality}/{category}` | **On-Demand ISR** | `revalidate: 3600` | Avoids build-time explosion across 100k+ locality×category permutations |
| `/{city}/business/{slug}` | **On-Demand ISR** | `revalidate: 3600` + On-demand tag `business:{public_id}` | Laravel fires signed `POST /api/revalidate` webhook when owner updates profile or review is approved |
| `/search?...` | **Dynamic SSR** | `no-store` (`noindex, follow`) | Interactive multi-facet queries must reflect real-time filters |
| `/dashboard/*` | **Auth SSR + Client** | `private, no-store` (`noindex, nofollow`) | Merchant private data |

* **Alternative Considered:** Full Static Site Generation (SSG) at build time. Impossible once listings exceed tens of thousands of profiles (build times would exceed hours).

---

## 4. Structured Data (JSON-LD Schema.org)

Every public page injects validated `<script type="application/ld+json">` server-side in Next.js Server Components:

1. **Homepage (`/`):**
   * `Organization` (brand identity, logo, support contact, social profiles).
   * `WebSite` with `potentialAction` (`SearchAction` pointing to `/search?q={search_term_string}`).
2. **City, Category & Locality Listing Pages:**
   * `BreadcrumbList` (`Home -> Mumbai -> Andheri West -> Dentists`).
   * `ItemList` enumerating the top 10 organic listings on Page 1 with position, name, and canonical profile URL (sponsored cards excluded from organic `ItemList` semantics).
   * `FAQPage` (optional category-level consumer guide FAQs managed in admin).
3. **Business Profile Page (`/{city}/business/{slug}`):**
   * Dynamic `@type` mapped from `categories.schema_org_type` (e.g., `Dentist`, `Restaurant`, `AutoRepair`, `Hospital`, falling back to `LocalBusiness`).
   * Properties: `name`, `url`, `image[]`, `telephone` (or public business line), `priceRange`, `address` (`PostalAddress`), `geo` (`GeoCoordinates`), `openingHoursSpecification[]`, `aggregateRating` (`AggregateRating` — only rendered when `review_count >= 1` to comply with Google Structured Data guidelines), top 5 `review` (`Review`) objects, and `FAQPage` for business FAQs.

---

## 5. Canonical Normalization, Thin-Page Eligibility & Crawl Budget Protection

Directory sites frequently suffer from **crawl traps** and thin-page penalties caused by combinatorial filter URLs and empty city/locality matrices. We enforce strict deterministic eligibility rules shared across Next.js metadata rendering and Laravel's sitemap generator:

### 5.1 Deterministic Canonical URL Normalization Rules
1. **Protocol & Host:** Always `https://` + configured primary domain (`NEXT_PUBLIC_SITE_URL`, non-www or www standardized with edge `301` redirect).
2. **Case & Trailing Slash:** Strictly lowercase ASCII hyphenated slugs (`a-z0-9-`), **no trailing slash** (except root `/`). Uppercase or trailing-slash requests receive an HTTP `301` to the canonical form.
3. **Query Parameter Stripping:** Strip all tracking (`utm_*`, `fbclid`, `gclid`, `ref`), location (`lat`, `lng`), and filter/sort (`min_rating`, `open_now`, `verified_only`, `amenities`, `sort`, `radius_km`) parameters from `<link rel="canonical">`.
4. **Pagination Canonical Rule:**
   * Page 1 canonical is ALWAYS the bare path `/{city}/{category}` (never `?page=1`; requests with `?page=1` return `301` to bare path).
   * Page $N$ ($2 \le N \le 50$) uses self-referencing canonical `/{city}/{category}?page=N`.
   * Pages beyond `page > 50` emit `<meta name="robots" content="noindex, follow" />` with canonical pointing to `/{city}/{category}`.

### 5.2 Thin-Page Indexability & Sitemap Eligibility Matrix
**Strict Sitemap Parity Rule:** An XML sitemap may **ONLY** include URLs that return HTTP `200`, are exact canonical URLs, and meet **100% of the `index, follow` eligibility rules** below. No redirected (`301`), `noindex`, filtered, or thin URL may ever appear in any sitemap shard.

| Route Pattern | Minimum Eligibility for `index, follow` | When Below Threshold (Thin Page) | Included in Sitemap? |
| :--- | :--- | :--- | :--- |
| `/` (Homepage) | Always eligible | N/A | Yes (`sitemap-static.xml`) |
| `/{city}` (City Hub) | `cities.is_active = 1` AND `>= 1` active business in city | `noindex, follow` (if 0 businesses) | Only if eligible |
| `/{city}/{category}` | `categories.is_active = 1` AND `>= 1` active business in `(city, category)` | `noindex, follow` (displays nearby/related categories) | Only if `>= 1` active business |
| `/{city}/{locality}/{category}` | `localities.is_active = 1` AND **`>= 3` active businesses** in `(city, locality, category)` | `noindex, follow` (canonical remains self; shows city fallback suggestions) | Only if `>= 3` active businesses |
| `/{city}/business/{slug}` | `status = 'active'` AND `completeness_score >= 40` (name, category, address, phone, description or hours) | `noindex, follow` if `completeness_score < 40`; `404`/`410` if suspended/deleted | Only if `status = 'active'` AND `completeness_score >= 40` |
| `/search?*` or Filtered Category URL | Never indexed | Always `noindex, follow`; filtered category URLs canonicalize to base `/{city}/{category}` | **Never** |
| `/dashboard/*`, `/account/*`, `/admin/*` | Never indexed | `X-Robots-Tag: noindex, nofollow` + `robots.txt` `Disallow` | **Never** |

### 5.3 301 Redirect Equity Preservation (`url_redirects`)
* Whenever a business changes its name or moves localities, Laravel inserts a permanent mapping (`old_path -> new_path`, HTTP `301`) into `url_redirects` and removes `old_path` from the next sitemap build. Next.js checks redirect hints on `404` resolution and emits a clean HTTP `301 Moved Permanently` preserving backlinks and rankings.

---

## 6. Dynamic Sharded XML Sitemap & `robots.txt` Architecture

```text
/sitemap.xml (Sitemap Index — regenerated nightly via Laravel low-priority queue)
├── /sitemaps/sitemap-static.xml              # Homepage, static pages
├── /sitemaps/sitemap-cities.xml              # Active City Hubs
├── /sitemaps/sitemap-categories-mumbai.xml   # Qualified City+Category & Locality+Category URLs (>=3 listings)
├── /sitemaps/sitemap-categories-delhi.xml
├── /sitemaps/sitemap-businesses-0001.xml     # Up to 40,000 active businesses per shard (limit < 50k / 50MB)
└── /sitemaps/sitemap-businesses-0002.xml
```

* Generated asynchronously by Laravel Scheduler (`GenerateSitemapsJob`), compressed (`.xml.gz` optional), uploaded to S3/CDN, and served cleanly under `/sitemaps/*`.
* **`robots.txt` Directives:**
  * `Allow: /`
  * `Disallow: /admin/`
  * `Disallow: /dashboard/`
  * `Disallow: /account/`
  * `Disallow: /api/`
  * `Disallow: /search?`
  * `Sitemap: https://example.com/sitemap.xml`

---

## 7. Core Web Vitals & Technical SEO Targets

| Metric | Target | Architectural Enforcer |
| :--- | :--- | :--- |
| **LCP (Largest Contentful Paint)** | `<= 2.0 s` (Mobile) | Next.js ISR HTML + `priority` preload on hero image + WebP/AVIF from CDN |
| **CLS (Cumulative Layout Shift)** | `<= 0.05` | Mandatory `width`/`height` or `aspect-[...]` wrappers on all business thumbnails & banners; `next/font` zero-swap fallback |
| **INP (Interaction to Next Paint)** | `<= 150 ms` | React Server Components for static content; lazy-load maps, gallery lightboxes, and review modals (`next/dynamic`) |
| **Semantic HTML & Internal Linking** | Strict | Single `<h1>` per page, semantic `<nav>/<main>/<article>`, contextual breadcrumb + "Related Categories in {City}" + "Nearby Localities" link meshes |
