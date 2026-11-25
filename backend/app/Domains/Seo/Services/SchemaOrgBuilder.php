<?php

declare(strict_types=1);

namespace App\Domains\Seo\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryCollection;

final class SchemaOrgBuilder
{
    /**
     * @param  list<array{name: string, url: string}>  $breadcrumbs
     * @return array<string, mixed>
     */
    public function buildBreadcrumbList(array $breadcrumbs): array
    {
        $items = [];
        foreach (array_values($breadcrumbs) as $idx => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $idx + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * Build CollectionPage JSON-LD for published category or collection landing pages.
     *
     * @param  list<array{name: string, url: string}>  $breadcrumbs
     * @return array<string, mixed>
     */
    public function buildCategoryLanding(
        Category|CategoryCollection $entity,
        string $canonicalUrl,
        string $title,
        string $description,
        array $breadcrumbs = [],
    ): array {
        $payload = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $title,
            'description' => $description,
            'url' => $canonicalUrl,
        ];

        if ($breadcrumbs !== []) {
            $payload['breadcrumb'] = $this->buildBreadcrumbList($breadcrumbs);
        }

        return $payload;
    }

    /**
     * Build verified, visible-data-only LocalBusiness JSON-LD.
     * Never includes owner PII, KYC records, or synthetic zero-review AggregateRating.
     *
     * @return array<string, mixed>
     */
    public function buildBusiness(Business $business, string $canonicalUrl, ?string $schemaTypeOverride = null): array
    {
        $schemaType = $schemaTypeOverride
            ?? $business->primaryCategory?->schema_org_type
            ?? 'LocalBusiness';

        $payload = [
            '@context' => 'https://schema.org',
            '@type' => $schemaType,
            'name' => $business->name,
            'url' => $canonicalUrl,
        ];

        if ($business->tagline !== null && $business->tagline !== '') {
            $payload['description'] = $business->tagline;
        }

        if ($business->city !== null) {
            $payload['address'] = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $business->address_line_1,
                'addressLocality' => $business->locality?->name ?? $business->city->name,
                'addressRegion' => $business->city->state?->name,
                'postalCode' => $business->pincode,
                'addressCountry' => 'IN',
            ], static fn ($v) => $v !== null && $v !== '');
        }

        if ($business->latitude !== null && $business->longitude !== null) {
            $payload['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $business->latitude,
                'longitude' => (float) $business->longitude,
            ];
        }

        if ((int) $business->review_count > 0 && (float) $business->rating_avg > 0) {
            $payload['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $business->rating_avg, 2),
                'reviewCount' => (int) $business->review_count,
            ];
        }

        return $payload;
    }
}
