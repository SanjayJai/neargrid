# 17 - Search Engine Implementation & API Reference (Phase 3B — Milestone 7)

## 1. Architecture Overview

The Search Engine lives in `backend/app/Domains/Search/` with strict separation between query parsing, retrieval, ranking, sponsored selection, autocomplete, SEO canonicalization, and response formatting:

```text
GET /api/v1/search | /search/nearby | /search/suggestions | /search/filters
  └── SearchController + Typed FormRequests (strict validation, zero raw SQL)
       └── SearchEngineInterface (AppServiceProvider binding; Stage 2 Meilisearch seam)
            └── MysqlSearchEngine (Stage 1 MySQL 8.0 Native Engine)
                 ├── QueryNormalizerAndSynonymResolver (Unicode NFC + DB search_synonyms + EN/TA/Tanglish)
                 ├── GeoSpatialHelper (WGS-84 Haversine + SRID 4326 lat-first WKT polygon/point)
                 ├── SearchRankingService (Configurable composite + Bayesian damped rating + deterministic tie-break)
                 ├── SponsoredPlacementSelector (Isolated from organic ranking + active/quality/budget gates)
                 ├── AutocompleteService (Redis-backed grouped suggestions + automatic DB fallback)
                 ├── FilterOptionsService (Localized EN/TA facet & count options)
                 ├── SearchSeoAdvisor (Canonical URL normalization + thin-page/filter noindex rules)
                 └── SearchPerformanceLogger (Privacy-safe search_query_logs + slow-query warnings)
```

### Stage 2 Upgrade Seam (`SearchEngineInterface`)
All controllers depend exclusively on `App\Domains\Search\Contracts\SearchEngineInterface`. Swapping in a future `MeilisearchSearchEngine` driver requires changing only the container binding in `AppServiceProvider` without altering any `/api/v1/search*` request or response contracts for Next.js or Flutter clients.

---

## 2. Database Schema & Indexes (`2026_11_18_120001_create_search_engine_tables.php`)

1. **`search_synonyms`**: Database-driven multilingual synonym table (`term`, `normalized_term`, `canonical_term`, `locale` [`en` | `ta` | `ta-Latn`], `category_id`, `weight`, `is_active`). Seeded idempotently via `SearchSynonymsSeeder` (covering English, Tamil Unicode, and Tanglish mappings such as `plumber` / `பிளம்பர்` / `plambar`, `electrician` / `எலக்ட்ரீஷியன்` / `minsaram`, `hospital` / `மருத்துவமனை` / `maruthuvamanai`, `hotel` / `உணவகம்` / `unavagam`).
2. **`sponsored_placements`**: Dedicated paid placement table (`public_id` ULID, `business_id`, `city_id`, `category_id`, `locality_id`, `status`, `bid_minor`, `budget_minor`, `spent_minor`, `starts_at`, `ends_at`) with composite lookup index `idx_sponsored_active_lookup`.
3. **`search_query_logs`**: Privacy-safe performance telemetry (`normalized_query`, `display_query`, `city_slug`, `category_slug`, `locality_slug`, `locale`, `has_geo`, `sort_by`, `result_count`, `duration_ms`, `engine_driver`, `created_at`). Stores **zero** IP addresses, user IDs, phone numbers, or precise user coordinates.
4. **Search Indexes Added:**
   - `businesses`: `idx_businesses_search_active_city_name (status, city_id, name)` (alongside existing `idx_biz_fulltext`, `idx_biz_location` SRID 4326 `SPATIAL INDEX`, `idx_biz_serp`, `idx_biz_locality`).
   - `business_services`: MySQL 8 `FULLTEXT INDEX idx_biz_services_fulltext (name, description)`.

---

## 3. Ranking & Sponsored Eligibility

### Organic Composite Score (`sort=relevance`)
Configurable in `backend/config/search.php`:

$$S_{\text{total}} = 0.32 \cdot S_{\text{text}} + 0.13 \cdot S_{\text{cat/svc}} + 0.20 \cdot S_{\text{geo}} + 0.15 \cdot S_{\text{rating}} + 0.10 \cdot S_{\text{trust}} + 0.06 \cdot S_{\text{completeness}} + 0.04 \cdot S_{\text{recency}}$$

- **Bayesian Rating Dampening:** $R_{\text{bayes}} = \frac{v \cdot R + m \cdot C}{v + m}$ with configurable prior mean $C = 3.5$ and weight $m = 10$. A new business with 1 five-star review scores `3.636`, preventing it from unfairly outranking an established `4.7` business with 180 reviews (`4.637`).
- **Deterministic Tie-Breaking:** Ties break on `relevanceScore DESC`, then `business.id ASC`.
- **Allowed Explicit Sorts:** `relevance`, `rating`, `distance`, `popularity`, `newest` (strict enum allowlist).

### Sponsored Placement Rules
- Evaluated via a separate query on Page 1 only (`max_slots_page_1 = 2`).
- Must satisfy: `sponsored_placements.status = 'active'`, within `starts_at..ends_at`, `spent_minor < budget_minor`, `businesses.status = 'active'`, `deleted_at IS NULL`, `completeness_score >= 40`, and rating floor (`>= 3.0` when `review_count >= 5`).
- Always returns `"is_sponsored": true` and `"sponsor_disclosure": "Sponsored"`, and suppresses organic duplicates on Page 1.

---

## 4. API Endpoints & Examples

All search endpoints are public, rate-limited (`throttle:search` / `throttle:search-suggestions`), return only `status = 'active'` businesses and `ready + approved` public media, and never expose internal IDs, owner details, raw phone numbers, storage keys, or KYC data.

### 4.1 `GET /api/v1/search`
Supports `q`, `city`, `category`, `locality`, `localities[]`, `lat`, `lng`, `radius_km`, `min_rating`, `verified_only`, `open_now`, `amenities[]`, `payment_methods[]`, `sort`, `page` (`1..250`), `per_page` (`1..40`), `locale` (`en|ta`).

**Example Request (Tamil / Tanglish):**
```http
GET /api/v1/search?city=chennai&q=%E0%AE%AA%E0%AE%BF%E0%AE%B3%E0%AE%AE%E0%AF%8D%E0%AE%AA%E0%AE%B0%E0%AF%8D&verified_only=1&page=1&per_page=20
Accept: application/json
```

**Example Response (`200 OK`, `X-Robots-Tag: noindex, follow`):**
```json
{
  "status": "success",
  "data": [
    {
      "public_id": "01J...",
      "name": "Adyar Quick Flow Services",
      "slug": "adyar-quick-flow-services",
      "tagline": "24/7 residential plumbing",
      "city_slug": "chennai",
      "city_name": "Chennai",
      "locality_slug": "adyar",
      "locality_name": "Adyar",
      "primary_category": { "public_id": "01J...", "slug": "plumbers", "name": "Plumbers" },
      "is_sponsored": false,
      "sponsor_disclosure": null,
      "is_verified": true,
      "is_trusted": false,
      "is_claimed": true,
      "rating_avg": 4.7,
      "review_count": 48,
      "completeness_score": 85,
      "distance_km": 4.92,
      "is_open_now": true,
      "address_short": "12 Gandhi Nagar, Adyar, 600020",
      "phone_masked": "+9198401XXXXX",
      "has_whatsapp": true,
      "services_preview": ["Pipe Leak Repair", "Motor Fitting"],
      "media_preview": [
        {
          "public_id": "01J...",
          "media_type": "gallery",
          "alt_text": "Service van",
          "urls": { "thumb": "...", "card": "...", "full": "..." }
        }
      ]
    }
  ],
  "meta": {
    "request_id": "req_...",
    "api_version": "v1",
    "timestamp": "2026-11-18T09:15:00Z",
    "pagination": {
      "type": "offset",
      "current_page": 1,
      "per_page": 20,
      "total": 1,
      "last_page": 1,
      "has_more": false,
      "sponsored_count": 0,
      "returned_count": 1
    },
    "applied_filters": { "q": "பிளம்பர்", "city": "chennai", "verified_only": true, "sort": "relevance" },
    "counts": { "total_organic": 1, "sponsored_count": 0, "returned_count": 1 },
    "query": { "original": "பிளம்பர்", "normalized": "பிளம்பர்" },
    "seo": {
      "canonical_path": "/chennai",
      "canonical_url": "http://localhost:3000/chennai",
      "robots": "noindex, follow",
      "is_indexable": false,
      "noindex_reason": "free_text_search_query"
    },
    "performance": { "duration_ms": 14, "driver": "mysql" }
  }
}
```

### 4.2 `GET /api/v1/search/nearby`
Requires `lat` (`-90..90`) and `lng` (`-180..180`). Defaults to `radius_km=10` (configurable `0.5..50`) and `sort=distance`. Always emits `Cache-Control: private, no-store` and `X-Robots-Tag: noindex, follow`.

```http
GET /api/v1/search/nearby?lat=13.0418&lng=80.2341&radius_km=6&category=dentists
```

### 4.3 `GET /api/v1/search/suggestions`
Debounce-friendly grouped autocomplete (`q`, optional `city`, `locale`). Returns `categories`, `services`, `cities`, `localities`, and `businesses` (active-only). Enforces `min_query_length = 2`, caches in Redis (`SEARCH_SUGGESTIONS_CACHE_TTL=120`), and gracefully falls back to DB queries if Redis is unavailable.

```http
GET /api/v1/search/suggestions?city=chennai&q=dent&locale=en
```

### 4.4 `GET /api/v1/search/filters`
Returns localized cities, localities (with active listing counts when `city` is specified), categories, amenities, payment methods, rating brackets, allowed sort options, radius limits, and active/verified totals.

```http
GET /api/v1/search/filters?city=chennai&category=dentists&locale=ta
```
