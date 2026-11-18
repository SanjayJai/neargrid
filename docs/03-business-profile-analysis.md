# 03 - Business Profile Analysis Specification

## 1. Executive Summary

This document specifies the architecture and layout of the Business Profile (Detail) Page, modeled after Justdial's high-conversion storefront pages. It documents hero branding, trust badges, the sticky interaction action bar, multi-tab content architecture (Overview, Reviews, Photos, Products/Services, Timings, Map/Location), and cross-sell modules. It outlines the implementation using **Next.js (React 18 + TypeScript)** with **Incremental Static Regeneration (ISR)**, **Tailwind CSS**, and **Laravel 13 REST API**.

---

## 2. Business Profile Layout & Component Architecture

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│ Breadcrumb: Home > Mumbai > Andheri West > Dentists > Apex Dental Care                  │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│ Hero Showcase Banner & Photo Grid (1 Large Image + 4 Tiled Thumbnails + View All Button)│
├─────────────────────────────────────────────────────────────────────────────────────────┤
│ Business Title: Apex Dental Care & Implant Center                                       │
│ Badges: [✔ JD Verified] [★ JD Trust] [Established 2012] [Claimed Profile]              │
│ Rating: ★ 4.9 (486 Reviews) | Status: [Open Now] 9:00 AM – 9:00 PM                    │
│ Locality: Andheri West, Mumbai (1.2 km away) | [Get Directions]                         │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│ Sticky Action Bar:                                                                      │
│ [📞 Show Number / Call] [💬 WhatsApp] [⚡ Get Best Price / Enquire] [⭐ Rate Business] │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│ Tab Navigation: [Overview] [Photos (42)] [Reviews (486)] [Services & Pricing] [FAQs]   │
├───────────────────────────────────────────┬─────────────────────────────────────────────┤
│ Left Column: Content Panels               │ Right Column: Quick Lead & Map Card         │
│                                           │                                             │
│ [Overview Section]                        │ ┌─────────────────────────────────────────┐ │
│ - About the Business / Description        │ │ Send Enquiry to Business                │ │
│ - Key Highlights / Specialties            │ │ [Select Service Needed   ▼]             │ │
│ - Accepted Payment Modes & Amenities      │ │ [Full Name              ]               │ │
│                                           │ │ [Mobile Number          ]               │ │
│ [Operating Hours & Timings]               │ │ [ [Get Best Deal Button] ]              │ │
│ - Mon–Sat: 09:00 AM – 09:00 PM            │ └─────────────────────────────────────────┘ │
│ - Sun: 10:00 AM – 02:00 PM                │ ┌─────────────────────────────────────────┐ │
│                                           │ │ Interactive Map Preview                 │ │
│ [Photo Gallery / Media Wall]              │ │ Exact Address & Landmark                │ │
│ - Categorized: All, Interior, Exterior,   │ │ [Directions Link]                       │ │
│   Certificates, Equipment                 │ └─────────────────────────────────────────┘ │
│                                           │                                             │
│ [Ratings & Reviews Section]               │                                             │
│ - 5-Star Histogram Distribution           │                                             │
│ - Verified Customer Review Cards          │                                             │
│ - Owner Response Blocks                   │                                             │
│                                           │                                             │
│ [Similar Businesses in Andheri West]      │                                             │
└───────────────────────────────────────────┴─────────────────────────────────────────────┘
```

---

## 3. Profile Page Core Modules

### 3.1 Hero Branding & Gallery Mosaic
* **Layout**: Modern 5-photo mosaic grid (similar to Airbnb/Google Maps). One prominent hero photo on the left, four secondary tiles on the right with a `"+N More Photos"` overlay.
* **Photo Categories**: Categorized into `All`, `Interior`, `Exterior`, `Products/Services`, and `Certificates/Licenses`.
* **Lightbox Dialog**: Fullscreen lightbox supporting touch swiping on mobile and keyboard navigation on desktop.

### 3.2 Trust & Authenticity Badges
* **Verified Badge**: Signals that physical premises and ownership documentation (e.g., electricity bill, trade license) were audited.
* **Trust Badge**: Awarded to businesses with high ratings (>4.2), low dispute rates, and sustained longevity (>2 years).
* **Claimed Listing Status**: Differentiates unclaimed directory records from owner-managed profiles, offering an *"Own this business? Claim it now"* prompt.

### 3.3 Sticky Conversion Action Bar
* On mobile and desktop scroll, an action bar sticks to the viewport edge:
  * **Call Now / Show Number**: Dispatches a telephone dialer trigger and sends an SMS/push notification to the business owner with the caller's lead details.
  * **WhatsApp Chat**: Opens WhatsApp with pre-populated text: *"Hi Apex Dental Care, I found your listing on LocalBiz and would like to enquire about your services."*
  * **Enquire Now**: Launches the multi-field lead capture modal.
  * **Write a Review**: Opens the authenticated rating and review composition modal.
  * **Share & Bookmark**: Social sharing sheet (WhatsApp, Facebook, Twitter, Link copy) and user bookmark toggle.

### 3.4 Ratings & Reviews Engine
* **Aggregate Metrics**:
  * Overall score (e.g. `4.9` out of `5.0`).
  * Total rating count vs. text review count.
  * Rating distribution histogram (percentage of 5-star, 4-star, 3-star, 2-star, and 1-star reviews).
* **Individual Review Cards**:
  * Reviewer profile avatar, verified buyer badge, review timestamp.
  * Star rating given and optional sub-ratings (e.g. Cleanliness, Value for Money, Punctuality).
  * Review body text with user-uploaded photo thumbnails.
  * Merchant reply block ("Response from Business Owner").
  * Helpful vote button ("Was this review helpful?").

### 3.5 Operating Hours & Real-Time Status
* Weekly schedule table covering Monday through Sunday.
* Supports split shifts (e.g., Morning: 9:00 AM – 1:00 PM; Evening: 5:00 PM – 9:00 PM).
* Dynamic indicator displaying `Open Now (Closes at 9:00 PM)` or `Closed (Opens tomorrow at 9:00 AM)`.

### 3.6 Location & Map Integration
* Interactive map using Leaflet / OpenStreetMap or Google Maps JavaScript API.
* Exact address format: Building name, shop/office number, street/cross road, landmark, locality, city, state, postal pincode.
* "Get Directions" button routing to native navigation apps (Google Maps / Apple Maps).

---

## 4. Approved Architecture Translation (Cleanroom Implementation)

### 4.1 Frontend Component Architecture (Next.js + TypeScript + Tailwind CSS)

```
apps/web/
├── app/
│   └── [city]/
│       └── [slug]/
│           ├── page.tsx               # Server Component with ISR (revalidate: 3600)
│           └── components/
│               ├── ProfileHeader.tsx   # Title, badges, address summary
│               ├── MediaGallery.tsx    # 5-photo grid + fullscreen viewer
│               ├── StickyActionBar.tsx # Sticky bottom/top conversion actions
│               ├── ProfileTabs.tsx     # Overview, Reviews, Photos, FAQs tabs
│               ├── AboutSection.tsx    # Amenities, payment options, description
│               ├── TimingsTable.tsx    # Opening hours table & status check
│               ├── ReviewSection.tsx   # Review cards + composition modal
│               ├── EnquireSidebar.tsx  # Sticky desktop lead capture card
│               └── SimilarBizCarousel.tsx # Cross-selling recommendations
```

### 4.2 Backend REST API Specification (Laravel 13)

#### 1. Retrieve Business Profile: `GET /api/v1/businesses/{slug}`
* **Response Payload**:
```json
{
  "status": "success",
  "data": {
    "id": "biz_89234",
    "name": "Apex Dental Care & Implant Center",
    "slug": "apex-dental-care-andheri-west",
    "tagline": "Specialized Cosmetic & Implant Dentistry",
    "description": "Comprehensive dental clinic equipped with digital X-rays and sterilization protocols...",
    "year_established": 2012,
    "is_verified": true,
    "is_trusted": true,
    "is_claimed": true,
    "rating_summary": {
      "average_rating": 4.9,
      "total_ratings": 486,
      "total_reviews": 128,
      "distribution": { "5": 420, "4": 50, "3": 10, "2": 4, "1": 2 }
    },
    "contact": {
      "phone_masked": "+91-98200XXXXX",
      "has_whatsapp": true,
      "email": "contact@apexdental.com",
      "website": "https://www.apexdental.com"
    },
    "address": {
      "street": "Shop 4, Ground Floor, Crystal Plaza, New Link Road",
      "landmark": "Opposite Infinity Mall",
      "locality": "Andheri West",
      "city": "Mumbai",
      "state": "Maharashtra",
      "pincode": "400053",
      "coordinates": { "lat": 19.1415, "lng": 72.8311 }
    },
    "timings": [
      { "day": "Monday", "is_closed": false, "slots": [{"open": "09:00", "close": "21:00"}] },
      { "day": "Sunday", "is_closed": false, "slots": [{"open": "10:00", "close": "14:00"}] }
    ],
    "amenities": ["Air Conditioned", "Credit Cards Accepted", "Parking Available", "Wheelchair Accessible"],
    "photos": [
      { "id": 1, "url": "https://cdn.example.com/p1.webp", "category": "interior" },
      { "id": 2, "url": "https://cdn.example.com/p2.webp", "category": "exterior" }
    ],
    "services": [
      { "id": 101, "name": "Dental Implants", "price_starting": 15000 },
      { "id": 102, "name": "Root Canal Treatment", "price_starting": 3500 }
    ]
  }
}
```

#### 2. Retrieve Reviews: `GET /api/v1/businesses/{id}/reviews?page=1&sort=recent`
#### 3. Submit Review: `POST /api/v1/businesses/{id}/reviews` (Requires Bearer Token)

### 4.3 Database Schema (MySQL)
* **`businesses`**: `id`, `user_id` (owner), `name`, `slug`, `tagline`, `description`, `year_established`, `is_verified`, `is_trusted`, `is_claimed`, `phone`, `whatsapp`, `email`, `website`, `address_street`, `landmark`, `locality_id`, `city_id`, `pincode`, `location_coordinates` (Point), `rating_cache`, `review_count_cache`, `is_active`.
* **`business_hours`**: `id`, `business_id`, `day_of_week` (0-6), `open_time_1`, `close_time_1`, `open_time_2`, `close_time_2`, `is_closed`.
* **`business_photos`**: `id`, `business_id`, `image_url`, `thumbnail_url`, `category`, `sort_order`.
* **`business_amenities`**: `business_id`, `amenity_id`.
* **`business_services`**: `id`, `business_id`, `name`, `description`, `price_starting`, `is_active`.
* **`reviews`**: `id`, `business_id`, `user_id`, `rating` (1-5), `review_text`, `owner_response`, `owner_responded_at`, `is_approved`, `created_at`.
* **`review_photos`**: `id`, `review_id`, `image_url`.

---

## 5. Unknowns & Recommendations

1. **Unknown**: Proprietary click-to-call telephony bridge provider used by Justdial.
   * **Recommendation**: Integrate Twilio or Exotel via a webhook handler in Laravel to mask phone numbers and bridge calls while protecting business owner privacy.
2. **Unknown**: Frequency of business hour cache updates for dynamic "Open Now" calculations.
   * **Recommendation**: Store operating hours in local client state or calculate using UTC-converted server offsets to allow instant client-side calculation without querying the database repeatedly.
