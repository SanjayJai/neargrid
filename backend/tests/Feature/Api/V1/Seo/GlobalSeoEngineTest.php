<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Seo;

use App\Domains\Directory\Models\Business;
use App\Domains\Governance\Models\UrlRedirect;
use App\Domains\Identity\Models\Role;
use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Seo\Services\IndexabilityGate;
use App\Domains\Seo\Services\RedirectLifecycleService;
use App\Domains\Seo\Services\SchemaOrgBuilder;
use App\Domains\Seo\Services\SeoEntityRegistry;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GlobalSeoEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->roles()->syncWithoutDetaching([
            Role::query()->where('name', 'super_admin')->firstOrFail()->id,
        ]);

        $this->customer = User::factory()->create();
        $this->customer->roles()->syncWithoutDetaching([
            Role::query()->where('name', 'customer')->firstOrFail()->id,
        ]);
    }

    public function test_admin_seo_rbac_upsert_revisions_and_rollback(): void
    {
        $dentists = Category::query()->where('slug', 'dentists')->firstOrFail();

        $this->getJson("/api/v1/admin/seo/category/{$dentists->public_id}")->assertUnauthorized();
        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/admin/seo/category/{$dentists->public_id}")->assertForbidden();

        Sanctum::actingAs($this->superAdmin);
        $this->putJson("/api/v1/admin/seo/category/{$dentists->public_id}", [
            'locale' => 'en',
            'meta_title' => 'Dentists v1',
            'meta_description' => 'Version 1 desc',
        ])->assertOk()->assertJsonPath('data.published_revision_version', 1);

        $this->putJson("/api/v1/admin/seo/category/{$dentists->public_id}", [
            'locale' => 'en',
            'meta_title' => 'Dentists v2',
            'meta_description' => 'Version 2 desc',
        ])->assertOk()->assertJsonPath('data.published_revision_version', 2);

        $list = $this->getJson("/api/v1/admin/seo/category/{$dentists->public_id}")->assertOk()->json('data');
        $metaPublicId = $list[0]['public_id'];
        $v1 = collect($list[0]['revisions'])->firstWhere('version_number', 1);
        $this->assertNotNull($v1);

        $this->postJson("/api/v1/admin/seo/metadata/{$metaPublicId}/rollback/{$v1['public_id']}")
            ->assertOk()
            ->assertJsonPath('data.meta_title', 'Dentists v1')
            ->assertJsonPath('data.published_revision_version', 3);
    }

    public function test_seo_metadata_preserved_on_soft_delete_and_cleaned_on_force_delete(): void
    {
        $cat = Category::factory()->create(['slug' => 'temp-seo-cat', 'node_type' => 'subcategory']);
        $seo = SeoMetadata::query()->create([
            'entity_type' => 'category',
            'entity_id' => $cat->id,
            'scope_key' => 'global',
            'locale' => 'en',
            'meta_title' => 'Temp Cat',
        ]);

        // Soft delete preserves seo_metadata even when orphan pruning runs
        $cat->delete();
        $pruned = app(SeoEntityRegistry::class)->pruneOrphanedMetadata();
        $this->assertSame(0, $pruned);
        $this->assertDatabaseHas('seo_metadata', ['id' => $seo->id]);

        // Permanent deletion cleans up seo_metadata
        $cat->forceDelete();
        $this->assertDatabaseMissing('seo_metadata', ['id' => $seo->id]);
    }

    public function test_indexability_gate_enforces_deterministic_precedence(): void
    {
        $gate = app(IndexabilityGate::class);

        // Draft cannot be forced indexable by admin override
        $draft = $gate->evaluate([
            'lifecycle_status' => 'draft',
            'override_robots_index' => true,
        ]);
        $this->assertFalse($draft['index']);
        $this->assertSame('noindex, nofollow', $draft['robots']);

        // Non-canonical host is noindex, follow
        $nonCanonical = $gate->evaluate([
            'lifecycle_status' => 'published',
            'is_canonical_host' => false,
            'override_robots_index' => true,
        ]);
        $this->assertFalse($nonCanonical['index']);
        $this->assertSame('noindex, follow', $nonCanonical['robots']);

        // Eligible page can be restricted by admin override
        $restricted = $gate->evaluate([
            'lifecycle_status' => 'published',
            'listing_count' => 10,
            'override_robots_index' => false,
        ]);
        $this->assertFalse($restricted['index']);
        $this->assertSame('admin_override_noindex', $restricted['reason']);
    }

    public function test_redirect_lifecycle_collapses_chains_and_prevents_loops(): void
    {
        $service = app(RedirectLifecycleService::class);
        $service->registerRedirect('/c/old-a', '/c/old-b', 'category', 1);
        $service->registerRedirect('/c/old-b', '/c/final-c', 'category', 1);

        $this->assertSame('/c/final-c', $service->resolvePath('/c/old-a'));
        $this->assertDatabaseHas('url_redirects', [
            'source_path' => '/c/old-a',
            'target_path' => '/c/final-c',
        ]);

        // Reverse redirect removes 2-hop loop
        $service->registerRedirect('/c/final-c', '/c/old-b', 'category', 1);
        $this->assertNull(UrlRedirect::query()->where('source_path', '/c/old-b')->where('target_path', '/c/final-c')->first());
    }

    public function test_schema_org_builder_omits_zero_review_aggregate_rating(): void
    {
        $biz = Business::factory()->create([
            'name' => 'No Reviews Clinic',
            'review_count' => 0,
            'rating_avg' => 0,
        ]);

        $schema = app(SchemaOrgBuilder::class)->buildBusiness($biz, 'https://neargrid.in/chennai/business/no-reviews');
        $this->assertSame('No Reviews Clinic', $schema['name']);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
    }
}
