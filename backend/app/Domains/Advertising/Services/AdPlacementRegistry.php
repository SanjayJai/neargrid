<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Services;

use App\Domains\Advertising\Models\AdPlacement;
use Illuminate\Support\Collection;

final class AdPlacementRegistry
{
    /**
     * Authoritative catalog of 32 configurable ad placements across all page types.
     * Supports 30+ slots without hardcoding 30 separate frontend components.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultDefinitions(): array
    {
        return [
            // Home (6 placements)
            ['key' => 'home.hero_banner', 'name' => 'Home Hero Banner', 'page_type' => 'home', 'route_pattern' => '/', 'slot_position' => 'hero', 'render_format' => 'banner', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 1200, 'height_px' => 320, 'sort_order' => 10],
            ['key' => 'home.below_search_strip', 'name' => 'Home Below-Search Strip', 'page_type' => 'home', 'route_pattern' => '/', 'slot_position' => 'top_strip', 'render_format' => 'strip', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 970, 'height_px' => 90, 'sort_order' => 20],
            ['key' => 'home.featured_carousel', 'name' => 'Home Featured Carousel', 'page_type' => 'home', 'route_pattern' => '/', 'slot_position' => 'mid_carousel', 'render_format' => 'carousel', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 6, 'width_px' => 360, 'height_px' => 220, 'sort_order' => 30],
            ['key' => 'home.category_spotlight_left', 'name' => 'Home Category Spotlight Left', 'page_type' => 'home', 'route_pattern' => '/', 'slot_position' => 'spotlight_left', 'render_format' => 'card', 'device_scope' => 'desktop', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 540, 'height_px' => 260, 'sort_order' => 40],
            ['key' => 'home.category_spotlight_right', 'name' => 'Home Category Spotlight Right', 'page_type' => 'home', 'route_pattern' => '/', 'slot_position' => 'spotlight_right', 'render_format' => 'card', 'device_scope' => 'desktop', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 540, 'height_px' => 260, 'sort_order' => 50],
            ['key' => 'home.pre_footer_banner', 'name' => 'Home Pre-Footer Banner', 'page_type' => 'home', 'route_pattern' => '/', 'slot_position' => 'pre_footer', 'render_format' => 'banner', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 1140, 'height_px' => 180, 'sort_order' => 60],

            // Search Results (7 placements)
            ['key' => 'search.top_leaderboard', 'name' => 'Search Top Leaderboard', 'page_type' => 'search', 'route_pattern' => '/search', 'slot_position' => 'top', 'render_format' => 'banner', 'device_scope' => 'desktop', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 970, 'height_px' => 90, 'sort_order' => 110],
            ['key' => 'search.mobile_top_banner', 'name' => 'Search Mobile Top Banner', 'page_type' => 'search', 'route_pattern' => '/search', 'slot_position' => 'top', 'render_format' => 'banner', 'device_scope' => 'mobile', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 320, 'height_px' => 100, 'sort_order' => 120],
            ['key' => 'search.native_slot_1', 'name' => 'Search Native Slot #1', 'page_type' => 'search', 'route_pattern' => '/search', 'slot_position' => 'inline_1', 'render_format' => 'native_listing', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'sort_order' => 130],
            ['key' => 'search.native_slot_2', 'name' => 'Search Native Slot #2', 'page_type' => 'search', 'route_pattern' => '/search', 'slot_position' => 'inline_2', 'render_format' => 'native_listing', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'sort_order' => 140],
            ['key' => 'search.sidebar_top', 'name' => 'Search Sidebar Top Card', 'page_type' => 'search', 'route_pattern' => '/search', 'slot_position' => 'sidebar_top', 'render_format' => 'card', 'device_scope' => 'desktop', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 300, 'height_px' => 250, 'sort_order' => 150],
            ['key' => 'search.sidebar_sticky', 'name' => 'Search Sidebar Sticky Banner', 'page_type' => 'search', 'route_pattern' => '/search', 'slot_position' => 'sidebar_sticky', 'render_format' => 'banner', 'device_scope' => 'desktop', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 300, 'height_px' => 600, 'sort_order' => 160],
            ['key' => 'search.zero_results_promo', 'name' => 'Search Zero-Results Recovery Card', 'page_type' => 'search', 'route_pattern' => '/search', 'slot_position' => 'empty_state', 'render_format' => 'card', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 2, 'sort_order' => 170],

            // Category Explorer / 5-Level Taxonomy Pages (7 placements)
            ['key' => 'category.hero_banner', 'name' => 'Category Hero Banner', 'page_type' => 'category', 'route_pattern' => '/c/*', 'slot_position' => 'hero', 'render_format' => 'banner', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 1140, 'height_px' => 220, 'sort_order' => 210],
            ['key' => 'category.top_sponsored_cards', 'name' => 'Category Top Sponsored Cards', 'page_type' => 'category', 'route_pattern' => '/c/*', 'slot_position' => 'top_grid', 'render_format' => 'card', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 3, 'sort_order' => 220],
            ['key' => 'category.inline_mid_strip', 'name' => 'Category Inline Mid Strip', 'page_type' => 'category', 'route_pattern' => '/c/*', 'slot_position' => 'inline_mid', 'render_format' => 'strip', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 728, 'height_px' => 90, 'sort_order' => 230],
            ['key' => 'category.sidebar_partner', 'name' => 'Category Sidebar Partner', 'page_type' => 'category', 'route_pattern' => '/c/*', 'slot_position' => 'sidebar_top', 'render_format' => 'card', 'device_scope' => 'desktop', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 300, 'height_px' => 250, 'sort_order' => 240],
            ['key' => 'category.speciality_spotlight', 'name' => 'Speciality & Sub-Speciality Spotlight', 'page_type' => 'category', 'route_pattern' => '/[city]/[segment]/[category]', 'slot_position' => 'speciality_top', 'render_format' => 'card', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 2, 'sort_order' => 250],
            ['key' => 'category.collection_sponsor', 'name' => 'Category Collection Sponsor', 'page_type' => 'category', 'route_pattern' => '/c/*', 'slot_position' => 'collection_header', 'render_format' => 'banner', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'sort_order' => 260],
            ['key' => 'category.bottom_banner', 'name' => 'Category Bottom Banner', 'page_type' => 'category', 'route_pattern' => '/c/*', 'slot_position' => 'bottom', 'render_format' => 'banner', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'sort_order' => 270],

            // City & Locality Hubs (4 placements)
            ['key' => 'city_hub.hero_takeover', 'name' => 'City Hub Hero Takeover', 'page_type' => 'city_hub', 'route_pattern' => '/[city]', 'slot_position' => 'hero', 'render_format' => 'banner', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'width_px' => 1200, 'height_px' => 260, 'sort_order' => 310],
            ['key' => 'city_hub.locality_featured', 'name' => 'Locality Featured Carousel', 'page_type' => 'city_hub', 'route_pattern' => '/[city]/[segment]', 'slot_position' => 'mid_carousel', 'render_format' => 'carousel', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 4, 'sort_order' => 320],
            ['key' => 'city_hub.sidebar_local_offer', 'name' => 'City Hub Sidebar Local Offer', 'page_type' => 'city_hub', 'route_pattern' => '/[city]/*', 'slot_position' => 'sidebar_top', 'render_format' => 'card', 'device_scope' => 'desktop', 'audience_scope' => 'all', 'max_ads' => 1, 'sort_order' => 330],
            ['key' => 'city_hub.mobile_sticky_strip', 'name' => 'City Hub Mobile Sticky Strip', 'page_type' => 'city_hub', 'route_pattern' => '/[city]/*', 'slot_position' => 'bottom_sticky', 'render_format' => 'strip', 'device_scope' => 'mobile', 'audience_scope' => 'all', 'max_ads' => 1, 'sort_order' => 340],

            // Business Profile Storefront (4 placements)
            ['key' => 'business.sidebar_alternatives', 'name' => 'Business Sidebar Sponsored Alternatives', 'page_type' => 'business_profile', 'route_pattern' => '/[city]/business/[slug]', 'slot_position' => 'sidebar_alternatives', 'render_format' => 'card', 'device_scope' => 'desktop', 'audience_scope' => 'all', 'max_ads' => 2, 'sort_order' => 410],
            ['key' => 'business.below_services_strip', 'name' => 'Business Below-Services Strip', 'page_type' => 'business_profile', 'route_pattern' => '/[city]/business/[slug]', 'slot_position' => 'below_services', 'render_format' => 'strip', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'sort_order' => 420],
            ['key' => 'business.above_reviews_card', 'name' => 'Business Above-Reviews Card', 'page_type' => 'business_profile', 'route_pattern' => '/[city]/business/[slug]', 'slot_position' => 'above_reviews', 'render_format' => 'card', 'device_scope' => 'all', 'audience_scope' => 'guest', 'max_ads' => 1, 'sort_order' => 430],
            ['key' => 'business.claim_upsell_banner', 'name' => 'Unclaimed Listing Claim Banner', 'page_type' => 'business_profile', 'route_pattern' => '/[city]/business/[slug]', 'slot_position' => 'claim_cta', 'render_format' => 'banner', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'sort_order' => 440],

            // Customer Account & Global Context (4 placements)
            ['key' => 'account.saved_recommendations', 'name' => 'Saved Businesses Recommendations', 'page_type' => 'account', 'route_pattern' => '/saved', 'slot_position' => 'below_saved', 'render_format' => 'carousel', 'device_scope' => 'all', 'audience_scope' => 'customer', 'max_ads' => 3, 'sort_order' => 510],
            ['key' => 'account.enquiries_partner_tip', 'name' => 'Account Enquiries Partner Card', 'page_type' => 'account', 'route_pattern' => '/account/*', 'slot_position' => 'sidebar_top', 'render_format' => 'card', 'device_scope' => 'all', 'audience_scope' => 'customer', 'max_ads' => 1, 'sort_order' => 520],
            ['key' => 'global.top_announcement_link', 'name' => 'Global Top Text Link', 'page_type' => 'global', 'route_pattern' => '*', 'slot_position' => 'top_bar', 'render_format' => 'text_link', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'sort_order' => 610],
            ['key' => 'global.footer_partner_strip', 'name' => 'Global Footer Partner Strip', 'page_type' => 'global', 'route_pattern' => '*', 'slot_position' => 'footer_top', 'render_format' => 'strip', 'device_scope' => 'all', 'audience_scope' => 'all', 'max_ads' => 1, 'sort_order' => 620],
        ];
    }

    public static function syncDefaults(): int
    {
        $definitions = self::defaultDefinitions();
        $created = 0;

        foreach ($definitions as $def) {
            if (AdPlacement::withTrashed()->where('key', $def['key'])->exists()) {
                continue;
            }

            AdPlacement::query()->create([
                'key' => $def['key'],
                'name' => $def['name'],
                'description' => $def['description'] ?? null,
                'page_type' => $def['page_type'],
                'route_pattern' => $def['route_pattern'],
                'slot_position' => $def['slot_position'],
                'render_format' => $def['render_format'],
                'device_scope' => $def['device_scope'],
                'audience_scope' => $def['audience_scope'],
                'max_ads' => $def['max_ads'],
                'width_px' => $def['width_px'] ?? null,
                'height_px' => $def['height_px'] ?? null,
                'supports_category_targeting' => $def['supports_category_targeting'] ?? true,
                'supports_location_targeting' => $def['supports_location_targeting'] ?? true,
                'supports_domain_targeting' => $def['supports_domain_targeting'] ?? true,
                'is_active' => true,
                'sort_order' => $def['sort_order'],
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @return Collection<int, AdPlacement>
     */
    public function activeForContext(string $pageType, string $device = 'desktop', string $audience = 'guest'): Collection
    {
        return AdPlacement::query()
            ->where('is_active', true)
            ->whereIn('page_type', [$pageType, 'global'])
            ->whereIn('device_scope', [$device, 'all'])
            ->whereIn('audience_scope', [$audience, 'all'])
            ->orderBy('sort_order')
            ->get();
    }
}
