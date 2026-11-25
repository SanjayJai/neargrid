<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Seo;

use App\Domains\Directory\Models\Business;
use App\Domains\Domains\Models\Domain;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Seo\Services\RedirectLifecycleService;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CrossDomainSeoAndAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveDomain(User $actor, string $hostname, array $overrides = []): Domain
    {
        return Domain::query()->create(array_merge([
            'hostname' => $hostname,
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'is_indexable' => true,
            'created_by_user_id' => $actor->id,
        ], $overrides));
    }

    public function test_enquiry_attribution_uses_server_resolved_domain_and_ignores_spoofed_client_fields(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = User::factory()->create(['status' => 'active']);
        $customer = User::factory()->create(['status' => 'active']);
        $city = City::query()->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $velloreDomain = $this->createActiveDomain($actor, 'vellore.neargrid.in', [
            'default_city_id' => $city->id,
        ]);
        $otherDomain = $this->createActiveDomain($actor, 'chennai.neargrid.in');

        Sanctum::actingAs($customer);

        $res = $this->postJson('https://vellore.neargrid.in/api/v1/enquiries', [
            'category_slug' => $category->slug,
            'city_slug' => $city->slug,
            'customer_name' => 'Karthik',
            'phone_e164' => '+919840012345',
            'requirement' => 'Need dental consultation soon',
            'consent_given' => true,
            'consent_version' => '2026.1',
            // Spoofed attribution fields must be ignored
            'source_domain_id' => $otherDomain->id,
            'source_host' => 'evil.example.com',
            'entry_path' => '/../../etc/passwd',
        ], [
            'Referer' => 'https://vellore.neargrid.in/c/healthcare/dentists?utm_source=test',
        ])->assertStatus(201);

        $enquiry = Enquiry::query()->where('public_id', $res->json('data.public_id'))->firstOrFail();
        $this->assertSame($velloreDomain->id, $enquiry->source_domain_id);
        $this->assertSame('vellore.neargrid.in', $enquiry->source_host);
        $this->assertSame('/c/healthcare/dentists', $enquiry->entry_path);

        // Direct public caller spoofing X-NearGrid-Host / X-Entry-Path WITHOUT BFF secret is ignored
        $spoofedRes = $this->postJson('http://127.0.0.1/api/v1/enquiries', [
            'category_slug' => $category->slug,
            'city_slug' => $city->slug,
            'customer_name' => 'Spoofer',
            'phone_e164' => '+919840012347',
            'requirement' => 'Direct public spoofed headers',
            'consent_given' => true,
            'consent_version' => '2026.1',
        ], [
            'X-NearGrid-Host' => 'vellore.neargrid.in',
            'X-NearGrid-Bff-Secret' => 'wrong-secret',
            'X-Entry-Path' => '/c/spoofed',
        ])->assertStatus(201);

        $spoofedEnquiry = Enquiry::query()->where('public_id', $spoofedRes->json('data.public_id'))->firstOrFail();
        $this->assertNull($spoofedEnquiry->source_domain_id);
        $this->assertSame('127.0.0.1', $spoofedEnquiry->source_host);
        $this->assertNull($spoofedEnquiry->entry_path);

        // Authenticated BFF request WITH matching BFF secret resolves X-NearGrid-Host and X-Entry-Path
        config()->set('app.bff_internal_secret', 'test-bff-shared-secret-xyz');
        $bffRes = $this->postJson('http://127.0.0.1/api/v1/enquiries', [
            'category_slug' => $category->slug,
            'city_slug' => $city->slug,
            'customer_name' => 'Trusted BFF Customer',
            'phone_e164' => '+919840012348',
            'requirement' => 'Trusted BFF forwarded host test',
            'consent_given' => true,
            'consent_version' => '2026.1',
        ], [
            'X-NearGrid-Host' => 'vellore.neargrid.in',
            'X-NearGrid-Bff-Secret' => 'test-bff-shared-secret-xyz',
            'X-Entry-Path' => '/c/healthcare/dentists',
        ])->assertStatus(201);

        $bffEnquiry = Enquiry::query()->where('public_id', $bffRes->json('data.public_id'))->firstOrFail();
        $this->assertSame($velloreDomain->id, $bffEnquiry->source_domain_id);
        $this->assertSame('vellore.neargrid.in', $bffEnquiry->source_host);
        $this->assertSame('/c/healthcare/dentists', $bffEnquiry->entry_path);

        // Path traversal in X-Entry-Path is rejected/sanitized to null
        $res2 = $this->postJson('https://vellore.neargrid.in/api/v1/enquiries', [
            'category_slug' => $category->slug,
            'city_slug' => $city->slug,
            'customer_name' => 'Karthik',
            'phone_e164' => '+919840012346',
            'requirement' => 'Second enquiry requirement',
            'consent_given' => true,
            'consent_version' => '2026.1',
        ], [
            'X-NearGrid-Bff-Secret' => 'test-bff-shared-secret-xyz',
            'X-Entry-Path' => '/../../etc/passwd',
        ])->assertStatus(201);

        $enquiry2 = Enquiry::query()->where('public_id', $res2->json('data.public_id'))->firstOrFail();
        $this->assertNull($enquiry2->entry_path);
    }

    public function test_cross_domain_canonical_and_noindex_precedence(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = User::factory()->create(['status' => 'active']);
        $city = City::query()->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        // Make primary domain verified + active SSL so it acts as primary canonical origin
        $primary = Domain::query()->where('primary_slot', 1)->firstOrFail();
        $primary->update([
            'hostname' => 'neargrid.in',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'is_indexable' => true,
        ]);

        $cityPortal = $this->createActiveDomain($actor, 'vellore.neargrid.in', [
            'domain_type' => 'city_portal',
            'default_city_id' => $city->id,
            'is_canonical_for_location' => true,
            'is_indexable' => true,
        ]);

        $aliasDomain = $this->createActiveDomain($actor, 'mirror.neargrid.in', [
            'domain_type' => 'city_portal',
            'canonical_domain_id' => $primary->id,
            'is_indexable' => true,
        ]);

        $brandDomain = $this->createActiveDomain($actor, 'apollo.example.com', [
            'domain_type' => 'brand_microsite',
            'is_indexable' => true,
        ]);

        $businessDefault = Business::query()->forceCreate([
            'public_id' => (string) Str::ulid(),
            'owner_user_id' => $actor->id,
            'primary_category_id' => $category->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'name' => 'Vellore Heart Care',
            'slug' => 'vellore-heart-care',
            'address_line_1' => '10 Gandhi Rd',
            'pincode' => '632001',
            'latitude' => 12.91,
            'longitude' => 79.13,
            'primary_phone_e164' => '+919840055555',
            'status' => 'active',
        ]);

        $businessBranded = Business::query()->forceCreate([
            'public_id' => (string) Str::ulid(),
            'owner_user_id' => $actor->id,
            'primary_category_id' => $category->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'canonical_domain_id' => $brandDomain->id,
            'name' => 'Apollo Brand Branch',
            'slug' => 'apollo-brand-branch',
            'address_line_1' => '20 Anna Salai',
            'pincode' => '632001',
            'latitude' => 12.91,
            'longitude' => 79.13,
            'primary_phone_e164' => '+919840055556',
            'status' => 'active',
        ]);

        // 1. City portal self-canonicalizes city business profile
        $this->getJson("https://vellore.neargrid.in/api/v1/businesses/{$city->slug}/{$businessDefault->slug}")
            ->assertStatus(200)
            ->assertJsonPath('data.seo.canonical_url', "https://vellore.neargrid.in/{$city->slug}/business/vellore-heart-care")
            ->assertJsonPath('data.seo.indexable', true);

        // 2. National category page on city portal canonicalizes to primary domain
        $this->getJson("https://vellore.neargrid.in/api/v1/categories/landing/{$category->slug}?domain_id={$cityPortal->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.seo.canonical_url', "https://neargrid.in/c/{$category->slug}");

        // 3. Business canonical domain takes precedence
        $this->getJson("https://vellore.neargrid.in/api/v1/businesses/{$city->slug}/{$businessBranded->slug}")
            ->assertStatus(200)
            ->assertJsonPath('data.seo.canonical_url', "https://apollo.example.com/{$city->slug}/business/apollo-brand-branch");

        // 4. Alias domain points canonical to target and forces noindex,nofollow
        $this->getJson("https://mirror.neargrid.in/api/v1/categories/landing/{$category->slug}")
            ->assertStatus(200)
            ->assertJsonPath('data.seo.canonical_url', "https://neargrid.in/c/{$category->slug}")
            ->assertJsonPath('data.seo.robots', 'noindex, nofollow')
            ->assertJsonPath('data.seo.indexable', false);
    }

    public function test_domain_scoped_redirects_isolate_domains_and_preserve_global_fallback(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = User::factory()->create(['status' => 'active']);
        $city = City::query()->firstOrFail();

        $domainA = $this->createActiveDomain($actor, 'vellore.neargrid.in');
        $domainB = $this->createActiveDomain($actor, 'chennai.neargrid.in');

        /** @var RedirectLifecycleService $redirects */
        $redirects = app(RedirectLifecycleService::class);
        $redirects->registerRedirect("/{$city->slug}/business/old-domain-a", "/{$city->slug}/business/new-domain-a", domainId: $domainA->id);
        $redirects->registerRedirect("/{$city->slug}/business/old-global", "/{$city->slug}/business/new-global");

        // Domain A resolves its own redirect
        $this->getJson("https://vellore.neargrid.in/api/v1/businesses/{$city->slug}/old-domain-a")
            ->assertStatus(301)
            ->assertJsonPath('error.details.target_path', "/{$city->slug}/business/new-domain-a");

        // Domain B and unknown/global host MUST NOT see Domain A's redirect
        $this->getJson("https://chennai.neargrid.in/api/v1/businesses/{$city->slug}/old-domain-a")
            ->assertStatus(404);
        $this->getJson("http://localhost/api/v1/businesses/{$city->slug}/old-domain-a")
            ->assertStatus(404);

        // Global redirect resolves on both Domain A and Domain B
        $this->getJson("https://chennai.neargrid.in/api/v1/businesses/{$city->slug}/old-global")
            ->assertStatus(301)
            ->assertJsonPath('error.details.target_path', "/{$city->slug}/business/new-global");
    }
}
