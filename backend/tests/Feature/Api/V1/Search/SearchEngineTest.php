<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Search;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Enums\VerificationDocumentType;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessHour;
use App\Domains\Directory\Models\BusinessService;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Media\Enums\BusinessMediaType;
use App\Domains\Media\Enums\MediaUploadStatus;
use App\Domains\Media\Models\BusinessMedia;
use App\Domains\Media\Models\KycDocument;
use App\Domains\Search\Models\SponsoredPlacement;
use App\Domains\Taxonomy\Models\Amenity;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Normalizer;
use Tests\TestCase;

final class SearchEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['search.autocomplete.cache_store' => 'array']);
        Storage::fake('media_public');
    }

    public function test_english_business_name_category_subcategory_and_service_search(): void
    {
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();
        $plumbers = Category::query()->where('slug', 'plumbers')->firstOrFail();

        $dental = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Apex Smile Dental Studio',
            'status' => BusinessStatus::Active,
        ]);
        BusinessService::query()->create([
            'business_id' => $dental->id,
            'name' => 'Painless Root Canal Therapy',
            'is_active' => true,
        ]);
        BusinessService::query()->create([
            'business_id' => $dental->id,
            'name' => 'Archived Secret Laser Whitening',
            'is_active' => false,
        ]);

        $plumber = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $plumbers->id,
            'name' => 'Metro Pipe Works',
            'status' => BusinessStatus::Active,
        ]);

        // 1. Direct business name search
        $this->getJson('/api/v1/search?city=chennai&q=Apex+Smile')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $dental->public_id);

        // 2. Active service keyword search
        $this->getJson('/api/v1/search?city=chennai&q=Root+Canal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $dental->public_id);

        // 3. Inactive service must NOT match
        $this->getJson('/api/v1/search?city=chennai&q=Archived+Secret+Laser')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // 4. Parent category filter ('home-services') includes child subcategory ('plumbers')
        $this->getJson('/api/v1/search?city=chennai&category=home-services')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $plumber->public_id);
    }

    public function test_tamil_unicode_and_tanglish_synonym_search_preserves_original_query(): void
    {
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $adyar = Locality::query()->where('city_id', $chennai->id)->where('slug', 'adyar')->firstOrFail();

        $plumberBiz = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $adyar->id,
            'primary_category_id' => Category::query()->where('slug', 'plumbers')->firstOrFail()->id,
            'name' => 'Adyar Quick Flow Services',
            'status' => BusinessStatus::Active,
        ]);

        $electricianBiz = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $adyar->id,
            'primary_category_id' => Category::query()->where('slug', 'electricians')->firstOrFail()->id,
            'name' => 'Bright Spark Power Care',
            'status' => BusinessStatus::Active,
        ]);

        $hospitalBiz = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $adyar->id,
            'primary_category_id' => Category::query()->where('slug', 'hospitals')->firstOrFail()->id,
            'name' => 'Cauvery Multi Speciality Centre',
            'status' => BusinessStatus::Active,
        ]);

        $hotelBiz = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $adyar->id,
            'primary_category_id' => Category::query()->where('slug', 'restaurants')->firstOrFail()->id,
            'name' => 'Madras Filter Coffee House',
            'status' => BusinessStatus::Active,
        ]);

        // Plumber: English, Tamil Unicode, and Tanglish
        foreach (['plumber', 'பிளம்பர்', 'plambar'] as $term) {
            $res = $this->getJson('/api/v1/search?city=chennai&q='.urlencode($term))
                ->assertOk()
                ->assertJsonPath('meta.query.original', $term);
            $ids = array_column($res->json('data'), 'public_id');
            $this->assertContains($plumberBiz->public_id, $ids, "Failed matching plumber for query: {$term}");
        }

        // Electrician: English, Tamil Unicode, Tanglish
        foreach (['electrician', 'எலக்ட்ரீஷியன்', 'minsaram'] as $term) {
            $res = $this->getJson('/api/v1/search?city=chennai&q='.urlencode($term))->assertOk();
            $ids = array_column($res->json('data'), 'public_id');
            $this->assertContains($electricianBiz->public_id, $ids, "Failed matching electrician for query: {$term}");
        }

        // Hospital: English, Tamil Unicode, inflected Tamil, NFD decomposed Tamil, Tanglish
        $decomposedTamil = class_exists(Normalizer::class)
            ? (string) Normalizer::normalize('மருத்துவமனை', Normalizer::FORM_D)
            : 'மருத்துவமனை';

        foreach (['hospital', 'மருத்துவமனை', 'மருத்துவமனைகள்', $decomposedTamil, 'maruthuvamanai'] as $term) {
            $res = $this->getJson('/api/v1/search?city=chennai&q='.urlencode($term))->assertOk();
            $ids = array_column($res->json('data'), 'public_id');
            $this->assertContains($hospitalBiz->public_id, $ids, "Failed matching hospital for query: {$term}");
        }

        // Hotel / Restaurant: English, Tamil Unicode, Tanglish
        foreach (['hotel', 'உணவகம்', 'unavagam'] as $term) {
            $res = $this->getJson('/api/v1/search?city=chennai&q='.urlencode($term))->assertOk();
            $ids = array_column($res->json('data'), 'public_id');
            $this->assertContains($hotelBiz->public_id, $ids, "Failed matching restaurant for query: {$term}");
        }
    }

    public function test_city_locality_verified_min_rating_amenities_and_open_now_filters(): void
    {
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $coimbatore = City::query()->where('slug', 'coimbatore')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $adyar = Locality::query()->where('city_id', $chennai->id)->where('slug', 'adyar')->firstOrFail();
        $rsPuram = Locality::query()->where('city_id', $coimbatore->id)->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();
        $parking = Amenity::query()->where('slug', 'parking-available')->firstOrFail();

        $match = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'T Nagar Verified 24x7 Dental',
            'status' => BusinessStatus::Active,
            'is_verified' => true,
            'rating_avg' => 4.8,
            'review_count' => 80,
        ]);
        $match->amenities()->attach($parking->id);
        for ($d = 0; $d <= 6; $d++) {
            BusinessHour::query()->create([
                'business_id' => $match->id,
                'day_of_week' => $d,
                'slot_index' => 1,
                'is_24_hours' => true,
                'is_closed' => false,
            ]);
        }

        // Different locality + closed
        $closedAdyar = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $adyar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Adyar Closed Dental',
            'status' => BusinessStatus::Active,
            'is_verified' => false,
            'rating_avg' => 3.4,
            'review_count' => 10,
        ]);
        for ($d = 0; $d <= 6; $d++) {
            BusinessHour::query()->create([
                'business_id' => $closedAdyar->id,
                'day_of_week' => $d,
                'slot_index' => 1,
                'is_closed' => true,
            ]);
        }

        // Different city
        Business::factory()->create([
            'city_id' => $coimbatore->id,
            'locality_id' => $rsPuram->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Coimbatore Dental',
            'status' => BusinessStatus::Active,
            'is_verified' => true,
            'rating_avg' => 4.9,
        ]);

        $res = $this->getJson(
            '/api/v1/search?city=chennai&locality=t-nagar&verified_only=1&min_rating=4.5&open_now=1&amenities[]=parking-available'
        );

        $res->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $match->public_id)
            ->assertJsonPath('data.0.is_open_now', true);
    }

    public function test_spatial_nearby_search_radius_boundaries_and_distance_sorting(): void
    {
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $adyar = Locality::query()->where('city_id', $chennai->id)->where('slug', 'adyar')->firstOrFail();
        $velachery = Locality::query()->where('city_id', $chennai->id)->where('slug', 'velachery')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        // Anchor: T. Nagar (13.0418, 80.2341)
        $atAnchor = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Anchor Point Clinic',
            'latitude' => 13.0418,
            'longitude' => 80.2341,
            'status' => BusinessStatus::Active,
        ]);

        // Adyar (~5.1 km away)
        $nearAdyar = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $adyar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Adyar Clinic',
            'latitude' => 13.0012,
            'longitude' => 80.2565,
            'status' => BusinessStatus::Active,
        ]);

        // Velachery (~8.5 km away — outside 6 km radius)
        Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $velachery->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Far Velachery Clinic',
            'latitude' => 12.9756,
            'longitude' => 80.2207,
            'status' => BusinessStatus::Active,
        ]);

        $res = $this->getJson('/api/v1/search/nearby?lat=13.0418&lng=80.2341&radius_km=6');

        $res->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.public_id', $atAnchor->public_id)
            ->assertJsonPath('data.1.public_id', $nearAdyar->public_id)
            ->assertHeader('X-Robots-Tag', 'noindex, follow');

        $this->assertEquals(0.0, $res->json('data.0.distance_km'));
        $this->assertGreaterThan(4.5, $res->json('data.1.distance_km'));
        $this->assertLessThan(5.8, $res->json('data.1.distance_km'));
    }

    public function test_bayesian_dampening_prevents_one_five_star_review_from_outranking_established_listing_and_is_stable(): void
    {
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        $oneReviewNew = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Brand New Dental',
            'latitude' => 13.0418,
            'longitude' => 80.2341,
            'status' => BusinessStatus::Active,
            'is_verified' => false,
            'is_trusted' => false,
            'rating_avg' => 5.0,
            'review_count' => 1,
            'completeness_score' => 50,
        ]);

        $established = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Established Care Dental',
            'latitude' => 13.0418,
            'longitude' => 80.2341,
            'status' => BusinessStatus::Active,
            'is_verified' => true,
            'is_trusted' => true,
            'rating_avg' => 4.7,
            'review_count' => 180,
            'completeness_score' => 95,
        ]);

        // Relevance sort: Established beats 1-review 5.0
        $rel1 = $this->getJson('/api/v1/search?city=chennai&category=dentists&sort=relevance')->assertOk();
        $this->assertSame([$established->public_id, $oneReviewNew->public_id], array_column($rel1->json('data'), 'public_id'));

        // Rating sort: Bayesian dampening ensures 4.7 (180 reviews) beats 5.0 (1 review)
        $rat = $this->getJson('/api/v1/search?city=chennai&category=dentists&sort=rating')->assertOk();
        $this->assertSame([$established->public_id, $oneReviewNew->public_id], array_column($rat->json('data'), 'public_id'));

        // Ranking stability across repeated identical requests
        $rel2 = $this->getJson('/api/v1/search?city=chennai&category=dentists&sort=relevance')->assertOk();
        $this->assertSame($rel1->json('data'), $rel2->json('data'));
    }

    public function test_sorting_pagination_and_seo_canonical_indexability_rules(): void
    {
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        for ($i = 1; $i <= 3; $i++) {
            Business::factory()->create([
                'city_id' => $chennai->id,
                'locality_id' => $tNagar->id,
                'primary_category_id' => $dentists->id,
                'name' => "Clinic {$i}",
                'status' => BusinessStatus::Active,
                'review_count' => $i * 20,
                'rating_avg' => 4.0 + ($i * 0.2),
            ]);
        }

        // Clean City+Category page 1 -> indexable, canonical without ?page=1
        $p1 = $this->getJson('/api/v1/search?city=chennai&category=dentists&page=1&per_page=2');
        $p1->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonPath('meta.pagination.has_more', true)
            ->assertJsonPath('meta.seo.is_indexable', true)
            ->assertJsonPath('meta.seo.canonical_path', '/chennai/dentists')
            ->assertHeader('X-Robots-Tag', 'index, follow');

        // Clean City+Category page 2 -> self-referencing canonical ?page=2
        $p2 = $this->getJson('/api/v1/search?city=chennai&category=dentists&page=2&per_page=2');
        $p2->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.seo.is_indexable', true)
            ->assertJsonPath('meta.seo.canonical_path', '/chennai/dentists?page=2');

        // Clean City+Locality+Category with >= 3 listings -> indexable
        $locIndexable = $this->getJson('/api/v1/search?city=chennai&locality=t-nagar&category=dentists');
        $locIndexable->assertOk()
            ->assertJsonPath('meta.seo.is_indexable', true)
            ->assertJsonPath('meta.seo.canonical_path', '/chennai/t-nagar/dentists');

        // Arbitrary filter & non-default sort -> noindex, follow; canonical strips filter/sort params
        $filtered = $this->getJson('/api/v1/search?city=chennai&category=dentists&min_rating=4.0&sort=popularity');
        $filtered->assertOk()
            ->assertJsonPath('meta.seo.is_indexable', false)
            ->assertJsonPath('meta.seo.canonical_path', '/chennai/dentists')
            ->assertHeader('X-Robots-Tag', 'noindex, follow');

        // Thin City+Locality+Category (< 3 listings) -> noindex
        $thin = $this->getJson('/api/v1/search?city=chennai&locality=adyar&category=dentists');
        $thin->assertOk()
            ->assertJsonPath('meta.seo.is_indexable', false)
            ->assertJsonPath('meta.seo.noindex_reason', 'thin_locality_category_page');

        // Pagination caps enforced (max_page=250, max_per_page=40)
        $this->getJson('/api/v1/search?city=chennai&page=251')->assertStatus(422);
        $this->getJson('/api/v1/search?city=chennai&per_page=41')->assertStatus(422);
    }

    public function test_unpublished_suspended_deleted_excluded_and_privacy_zero_leakage(): void
    {
        $owner = User::factory()->create(['name' => 'Private Owner Secret', 'phone_e164' => '+919999888877']);
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        $active = Business::factory()->create([
            'owner_user_id' => $owner->id,
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Public Active Dental',
            'primary_phone_e164' => '+919840123456',
            'status' => BusinessStatus::Active,
        ]);

        // Ready + approved public media (allowed)
        BusinessMedia::query()->create([
            'business_id' => $active->id,
            'uploaded_by_user_id' => $owner->id,
            'media_type' => BusinessMediaType::Gallery,
            'upload_status' => MediaUploadStatus::Ready,
            'moderation_status' => 'approved',
            'disk' => 'media_public',
            'storage_key' => 'businesses/x/full.webp',
            'webp_thumb_key' => 'businesses/x/thumb.webp',
            'webp_card_key' => 'businesses/x/card.webp',
            'webp_full_key' => 'businesses/x/full.webp',
            'mime_type' => 'image/webp',
            'file_size_bytes' => 1200,
        ]);

        // Rejected & pending public media (MUST be excluded)
        BusinessMedia::query()->create([
            'business_id' => $active->id,
            'uploaded_by_user_id' => $owner->id,
            'media_type' => BusinessMediaType::Gallery,
            'upload_status' => MediaUploadStatus::Ready,
            'moderation_status' => 'rejected',
            'disk' => 'media_public',
            'storage_key' => 'businesses/x/rejected.webp',
            'webp_full_key' => 'businesses/x/rejected.webp',
            'mime_type' => 'image/webp',
            'file_size_bytes' => 1200,
        ]);

        // Private KYC document (MUST NEVER leak)
        KycDocument::query()->create([
            'business_id' => $active->id,
            'uploaded_by_user_id' => $owner->id,
            'document_type' => VerificationDocumentType::Gstin,
            'upload_status' => 'ready',
            'disk' => 'kyc_private',
            'storage_key' => 'kyc/secret/gst-doc.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'sha256_checksum' => str_repeat('b', 64),
        ]);

        // Draft, pending_review, suspended, rejected, and soft-deleted businesses
        foreach ([BusinessStatus::Draft, BusinessStatus::PendingReview, BusinessStatus::Suspended, BusinessStatus::Rejected] as $status) {
            Business::factory()->create([
                'city_id' => $chennai->id,
                'locality_id' => $tNagar->id,
                'primary_category_id' => $dentists->id,
                'name' => "Hidden {$status->value} Dental",
                'status' => $status,
            ]);
        }

        $deleted = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Soft Deleted Dental',
            'status' => BusinessStatus::Active,
        ]);
        $deleted->delete();

        $res = $this->getJson('/api/v1/search?city=chennai&category=dentists');
        $res->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $active->public_id)
            ->assertJsonCount(1, 'data.0.media_preview');

        $raw = (string) $res->getContent();
        $this->assertStringNotContainsString('+919840123456', $raw);
        $this->assertStringNotContainsString('Private Owner Secret', $raw);
        $this->assertStringNotContainsString('kyc_private', $raw);
        $this->assertStringNotContainsString('gst-doc.pdf', $raw);
        $this->assertStringNotContainsString('rejected.webp', $raw);
        $this->assertStringNotContainsString('"owner_user_id"', $raw);
        $this->assertArrayNotHasKey('id', $res->json('data.0'));

        // Verify query log persisted without storing PII
        $this->assertDatabaseHas('search_query_logs', [
            'city_slug' => 'chennai',
            'category_slug' => 'dentists',
            'result_count' => 1,
        ]);
    }

    public function test_sponsored_placements_labelling_quality_floor_publication_gate_and_deduplication(): void
    {
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        $eligibleSponsored = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Sponsored Eligible Dental',
            'status' => BusinessStatus::Active,
            'completeness_score' => 85,
            'rating_avg' => 4.5,
            'review_count' => 30,
        ]);

        $suspendedSponsored = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Suspended Sponsored Dental',
            'status' => BusinessStatus::Suspended,
            'completeness_score' => 95,
            'rating_avg' => 4.9,
            'review_count' => 50,
        ]);

        $lowRatingSponsored = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Low Rating Sponsored Dental',
            'status' => BusinessStatus::Active,
            'completeness_score' => 85,
            'rating_avg' => 2.3,
            'review_count' => 12,
        ]);

        $lowCompletenessSponsored = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Incomplete Sponsored Dental',
            'status' => BusinessStatus::Active,
            'completeness_score' => 20,
            'rating_avg' => 4.8,
            'review_count' => 20,
        ]);

        $organic = Business::factory()->create([
            'city_id' => $chennai->id,
            'locality_id' => $tNagar->id,
            'primary_category_id' => $dentists->id,
            'name' => 'Organic Top Dental',
            'status' => BusinessStatus::Active,
            'completeness_score' => 95,
            'rating_avg' => 4.9,
            'review_count' => 200,
            'is_verified' => true,
            'is_trusted' => true,
        ]);

        foreach ([$eligibleSponsored, $suspendedSponsored, $lowRatingSponsored, $lowCompletenessSponsored] as $idx => $biz) {
            SponsoredPlacement::query()->create([
                'business_id' => $biz->id,
                'city_id' => $chennai->id,
                'category_id' => $dentists->id,
                'status' => 'active',
                'bid_minor' => 5000 - ($idx * 100),
                'budget_minor' => 100000,
                'spent_minor' => 10000,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(10),
            ]);
        }

        // Page 1: ONLY eligibleSponsored appears as Sponsored (top slot), deduplicated from organic hits
        $p1 = $this->getJson('/api/v1/search?city=chennai&category=dentists&per_page=10')->assertOk();
        $p1->assertJsonPath('meta.counts.sponsored_count', 1)
            ->assertJsonPath('data.0.public_id', $eligibleSponsored->public_id)
            ->assertJsonPath('data.0.is_sponsored', true)
            ->assertJsonPath('data.0.sponsor_disclosure', 'Sponsored');

        $returnedIds = array_column($p1->json('data'), 'public_id');
        // Zero duplicate occurrence of eligibleSponsored on Page 1
        $this->assertSame(1, count(array_keys($returnedIds, $eligibleSponsored->public_id, true)));
        // Suspended business never appears
        $this->assertNotContains($suspendedSponsored->public_id, $returnedIds);
        // Organic business appears clearly labelled as non-sponsored
        $organicRow = collect($p1->json('data'))->firstWhere('public_id', $organic->public_id);
        $this->assertNotNull($organicRow);
        $this->assertFalse($organicRow['is_sponsored']);
        $this->assertNull($organicRow['sponsor_disclosure']);

        // Page 2 never injects sponsored slots
        $p2 = $this->getJson('/api/v1/search?city=chennai&category=dentists&page=2&per_page=2')->assertOk();
        $p2->assertJsonPath('meta.counts.sponsored_count', 0);
    }

    public function test_sql_injection_arbitrary_sort_and_invalid_coordinates_rejected(): void
    {
        // SQL injection in free-text q executes safely via bindings and leaves DB intact
        $this->getJson('/api/v1/search?city=chennai&q='.urlencode("' OR 1=1; DROP TABLE businesses; --"))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Arbitrary raw sort expression rejected
        $this->getJson('/api/v1/search?city=chennai&sort='.urlencode('rating_avg DESC; DROP TABLE users'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Invalid characters in city slug rejected
        $this->getJson('/api/v1/search?city='.urlencode("chennai' OR '1'='1"))
            ->assertStatus(422);

        // Out-of-bounds coordinates rejected
        $this->getJson('/api/v1/search?lat=95&lng=80.2')->assertStatus(422);
        $this->getJson('/api/v1/search?lat=13.0&lng=185')->assertStatus(422);
        $this->getJson('/api/v1/search?lat=13.0')->assertStatus(422);
        $this->getJson('/api/v1/search?lat=13.0&lng=80.2&radius_km=500')->assertStatus(422);

        // Nearby requires both lat & lng
        $this->getJson('/api/v1/search/nearby')->assertStatus(422);
    }

    public function test_search_avoids_n_plus_one_queries_across_paginated_results(): void
    {
        $chennai = City::query()->where('slug', 'chennai')->firstOrFail();
        $tNagar = Locality::query()->where('city_id', $chennai->id)->where('slug', 't-nagar')->firstOrFail();
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        for ($i = 1; $i <= 15; $i++) {
            $biz = Business::factory()->create([
                'city_id' => $chennai->id,
                'locality_id' => $tNagar->id,
                'primary_category_id' => $dentists->id,
                'name' => "Bounded Query Clinic {$i}",
                'status' => BusinessStatus::Active,
            ]);
            BusinessService::query()->create([
                'business_id' => $biz->id,
                'name' => 'Consultation',
                'is_active' => true,
            ]);
            BusinessHour::query()->create([
                'business_id' => $biz->id,
                'day_of_week' => 1,
                'slot_index' => 1,
                'open_time' => '09:00:00',
                'close_time' => '18:00:00',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $res = $this->getJson('/api/v1/search?city=chennai&category=dentists&per_page=15');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $res->assertOk()->assertJsonCount(15, 'data');

        // Constant eager-loading query count (must stay well below 2x item count; no per-row N+1).
        $this->assertLessThan(25, count($queries), 'Unexpectedly high query count indicates an N+1 regression.');
    }
}
