<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\Organization;
use App\Domains\Directory\Models\OrganizationMember;
use App\Domains\Domains\Exceptions\DomainException;
use App\Domains\Domains\Models\Domain;
use App\Domains\Domains\Services\DomainResolutionService;
use App\Domains\Domains\Services\DomainVerificationService;
use App\Domains\Governance\Models\UrlRedirect;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Seo\Models\SeoRevision;
use App\Domains\Seo\Services\RedirectLifecycleService;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MultiDomainAndOrgSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ma2_tables_columns_and_primary_domain_bootstrap_exist(): void
    {
        $this->assertTrue(Schema::hasTable('organizations'));
        $this->assertTrue(Schema::hasTable('organization_members'));
        $this->assertTrue(Schema::hasTable('domains'));

        $this->assertTrue(Schema::hasColumns('businesses', [
            'organization_id',
            'branch_type',
            'franchise_code',
            'allow_hq_lead_access',
            'canonical_domain_id',
        ]));

        $this->assertTrue(Schema::hasColumns('enquiries', [
            'source_domain_id',
            'source_host',
            'entry_path',
        ]));

        $this->assertTrue(Schema::hasColumns('url_redirects', [
            'domain_id',
            'scope_key',
        ]));

        $primary = Domain::query()->where('primary_slot', 1)->first();
        $this->assertNotNull($primary);
        $this->assertTrue($primary->is_primary);
        $this->assertSame('primary', $primary->domain_type);
        // Bootstrapped primary domain must not falsely claim DNS verification or active SSL
        $this->assertSame('unverified', $primary->verification_status);
        $this->assertNull($primary->verified_at);
        $this->assertSame('not_started', $primary->ssl_status);
        $this->assertNull($primary->ssl_checked_at);
        $this->assertFalse($primary->isEffectivelyIndexable());
    }

    public function test_primary_slot_enforces_single_active_primary_domain_and_atomic_promotion(): void
    {
        $actor = User::factory()->create();

        $second = Domain::query()->create([
            'hostname' => 'india.neargrid.in',
            'domain_type' => 'city_portal',
            'is_primary' => false,
            'primary_slot' => null,
            'status' => 'active',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'is_indexable' => true,
            'created_by_user_id' => $actor->id,
        ]);

        // Direct duplicate primary_slot = 1 must fail at DB layer
        $this->expectException(QueryException::class);
        Domain::query()->create([
            'hostname' => 'dup.neargrid.in',
            'domain_type' => 'primary',
            'is_primary' => true,
            'primary_slot' => 1,
            'status' => 'active',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function test_atomic_primary_promotion_switches_primary_slot_cleanly(): void
    {
        $actor = User::factory()->create();
        $resolver = app(DomainResolutionService::class);

        $newPrimary = Domain::query()->create([
            'hostname' => 'neargrid.co.in',
            'domain_type' => 'city_portal',
            'is_primary' => false,
            'primary_slot' => null,
            'status' => 'active',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'is_indexable' => true,
            'created_by_user_id' => $actor->id,
        ]);

        $promoted = $resolver->promotePrimaryDomain($newPrimary);

        $this->assertSame(1, $promoted->primary_slot);
        $this->assertTrue($promoted->is_primary);
        $this->assertSame(1, Domain::query()->where('primary_slot', 1)->count());
        $this->assertSame($promoted->id, $resolver->resolvePrimaryDomain()?->id);
    }

    public function test_domain_verification_hashes_tokens_prevents_replay_and_keeps_ssl_separate(): void
    {
        $actor = User::factory()->create();
        $verifier = app(DomainVerificationService::class);

        $domain = Domain::query()->create([
            'hostname' => 'vellore.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'draft',
            'verification_method' => 'dns_txt',
            'verification_status' => 'unverified',
            'ssl_status' => 'not_started',
            'is_indexable' => true,
            'created_by_user_id' => $actor->id,
        ]);

        // Before verification & SSL, domain MUST NOT be indexable
        $this->assertFalse($domain->isEffectivelyIndexable());

        $token = $verifier->issueChallenge($domain, $actor);
        $domain->refresh();

        $this->assertNotNull($domain->challenge_hash);
        $this->assertNotSame($token, $domain->challenge_hash);
        $this->assertSame(64, strlen((string) $domain->challenge_hash));

        // Mismatch increments attempts and throws
        try {
            $verifier->verifyChallenge($domain, fn (): array => ['wrong-token'], $actor);
            $this->fail('Expected CHALLENGE_MISMATCH exception.');
        } catch (DomainException $e) {
            $this->assertSame('CHALLENGE_MISMATCH', $e->errorCode);
        }

        $domain->refresh();
        $this->assertSame(1, $domain->verification_attempts);

        // Valid verification succeeds, activates domain, keeps ssl_status = not_started
        $verified = $verifier->verifyChallenge($domain, fn (): array => [$token], $actor);
        $this->assertSame('verified', $verified->verification_status);
        $this->assertSame('active', $verified->status);
        $this->assertSame('not_started', $verified->ssl_status);
        $this->assertFalse($verified->isEffectivelyIndexable());

        // Replay attempt with same challenge must fail
        try {
            $verifier->verifyChallenge($verified, fn (): array => [$token], $actor);
            $this->fail('Expected replay protection failure.');
        } catch (DomainException $e) {
            $this->assertSame('CHALLENGE_NOT_AVAILABLE', $e->errorCode);
        }

        // Audit logs must never contain raw plaintext challenge token
        $auditDump = DB::table('audit_logs')->pluck('new_values')->implode(' ');
        $this->assertStringNotContainsString($token, $auditDump);

        // Expired challenge must be rejected and marked expired
        $expiringDomain = Domain::query()->create([
            'hostname' => 'salem.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'pending_verification',
            'verification_status' => 'pending',
            'created_by_user_id' => $actor->id,
        ]);
        $expToken = $verifier->issueChallenge($expiringDomain, $actor);
        DB::table('domains')->where('id', $expiringDomain->id)->update([
            'challenge_expires_at' => now()->subMinute(),
        ]);

        try {
            $verifier->verifyChallenge($expiringDomain, fn (): array => [$expToken], $actor);
            $this->fail('Expected CHALLENGE_EXPIRED exception.');
        } catch (DomainException $e) {
            $this->assertSame('CHALLENGE_EXPIRED', $e->errorCode);
        }
        $this->assertSame('expired', $expiringDomain->fresh()?->verification_status);

        // Cannot mark SSL active without validation=true
        try {
            $verifier->updateSslStatus($verified, 'active', false, $actor);
            $this->fail('Expected SSL_VALIDATION_REQUIRED exception.');
        } catch (DomainException $e) {
            $this->assertSame('SSL_VALIDATION_REQUIRED', $e->errorCode);
        }

        $sslReady = $verifier->updateSslStatus($verified, 'active', true, $actor);
        $this->assertTrue($sslReady->isEffectivelyIndexable());
    }

    public function test_domain_resolution_allowlists_active_domains_and_rejects_unknown_or_suspended(): void
    {
        $resolver = app(DomainResolutionService::class);
        $actor = User::factory()->create();

        Domain::query()->create([
            'hostname' => 'vellore.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'created_by_user_id' => $actor->id,
        ]);

        // Active-but-unverified domain must be rejected
        Domain::query()->create([
            'hostname' => 'unverified.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'unverified',
            'ssl_status' => 'not_started',
            'created_by_user_id' => $actor->id,
        ]);

        Domain::query()->create([
            'hostname' => 'pending.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'pending_verification',
            'verification_status' => 'pending',
            'created_by_user_id' => $actor->id,
        ]);

        Domain::query()->create([
            'hostname' => 'madurai.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'suspended',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'created_by_user_id' => $actor->id,
        ]);

        $this->assertNotNull($resolver->resolveActiveDomain('VELLORE.neargrid.in:443'));
        $this->assertNull($resolver->resolveActiveDomain('unverified.neargrid.in'));
        $this->assertNull($resolver->resolveActiveDomain('pending.neargrid.in'));
        $this->assertNull($resolver->resolveActiveDomain('madurai.neargrid.in'));
        $this->assertNull($resolver->resolveActiveDomain('evil-unregistered.example.com'));
        $this->assertNull($resolver->resolveActiveDomain('bad host/path'));
    }

    public function test_domain_foreign_keys_null_on_delete_without_losing_domain_row(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = User::factory()->create();
        $state = State::query()->create([
            'country_code' => 'IN',
            'name' => 'Test State FK',
            'slug' => 'test-state-fk',
        ]);
        $city = City::query()->create([
            'state_id' => $state->id,
            'name' => 'Test City FK',
            'slug' => 'test-city-fk',
            'latitude' => 12.9,
            'longitude' => 79.1,
        ]);
        $org = Organization::query()->create([
            'name' => 'Test Org FK',
            'slug' => 'test-org-fk',
            'owner_user_id' => $actor->id,
        ]);
        $canonical = Domain::query()->create([
            'hostname' => 'canon-fk.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'verified',
            'created_by_user_id' => $actor->id,
        ]);

        $domain = Domain::query()->create([
            'hostname' => 'branch-fk.neargrid.in',
            'domain_type' => 'brand_microsite',
            'status' => 'active',
            'verification_status' => 'verified',
            'organization_id' => $org->id,
            'default_city_id' => $city->id,
            'default_state_id' => $state->id,
            'canonical_domain_id' => $canonical->id,
            'created_by_user_id' => $actor->id,
        ]);

        $org->forceDelete();
        $city->delete();
        $state->delete();
        $canonical->forceDelete();

        $domain->refresh();
        $this->assertNull($domain->organization_id);
        $this->assertNull($domain->default_city_id);
        $this->assertNull($domain->default_state_id);
        $this->assertNull($domain->canonical_domain_id);
    }

    public function test_organizations_branches_and_defaults_for_existing_businesses(): void
    {
        $this->seed(DatabaseSeeder::class);

        $owner = User::factory()->create();
        $city = City::query()->firstOrFail();
        $locality = Locality::query()->where('city_id', $city->id)->firstOrFail();
        $category = Category::query()->where('is_assignable', true)->firstOrFail();

        $org = Organization::query()->create([
            'name' => 'Apollo Clinics Group',
            'slug' => 'apollo-clinics-group',
            'owner_user_id' => $owner->id,
        ]);

        OrganizationMember::query()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'role' => 'org_owner',
            'scope_type' => 'company_owned_only',
            'status' => 'active',
        ]);

        $independent = Business::query()->create([
            'primary_category_id' => $category->id,
            'city_id' => $city->id,
            'locality_id' => $locality->id,
            'name' => 'Standalone Clinic',
            'slug' => 'standalone-clinic-' . Str::lower(Str::random(6)),
            'address_line_1' => '10 Main Road',
            'pincode' => '632001',
            'latitude' => 12.9165,
            'longitude' => 79.1325,
            'primary_phone_e164' => '+919840011111',
        ]);
        $independent->refresh();

        $this->assertNull($independent->organization_id);
        $this->assertSame('independent', $independent->branch_type);
        $this->assertFalse($independent->allow_hq_lead_access);
        $this->assertCount(1, $org->members);
    }

    public function test_domain_scoped_redirects_and_seo_metadata_preservation(): void
    {
        $actor = User::factory()->create();
        $redirectService = app(RedirectLifecycleService::class);

        $domainA = Domain::query()->create([
            'hostname' => 'vellore.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'created_by_user_id' => $actor->id,
        ]);

        $domainB = Domain::query()->create([
            'hostname' => 'chennai.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'created_by_user_id' => $actor->id,
        ]);

        // Global redirect and same source_path on two different domains coexist cleanly
        $redirectService->registerRedirect('/old-clinics', '/c/healthcare');
        $redirectService->registerRedirect('/old-clinics', '/vellore/healthcare', domainId: $domainA->id);
        $redirectService->registerRedirect('/old-clinics', '/chennai/healthcare', domainId: $domainB->id);

        $this->assertSame('/vellore/healthcare', $redirectService->resolvePath('/old-clinics', $domainA->id));
        $this->assertSame('/chennai/healthcare', $redirectService->resolvePath('/old-clinics', $domainB->id));
        $this->assertSame('/c/healthcare', $redirectService->resolvePath('/old-clinics'));

        // Domain-only redirect MUST NOT leak into global resolution or another domain
        $redirectService->registerRedirect('/domain-a-only', '/vellore/dentists', domainId: $domainA->id);
        $this->assertSame('/vellore/dentists', $redirectService->resolvePath('/domain-a-only', $domainA->id));
        $this->assertNull($redirectService->resolvePath('/domain-a-only'));
        $this->assertNull($redirectService->resolvePath('/domain-a-only', $domainB->id));

        // SEO metadata + revision preserved if linked domain is deleted (nullOnDelete)
        $seo = SeoMetadata::query()->create([
            'entity_type' => 'category',
            'entity_id' => 1,
            'domain_id' => $domainA->id,
            'scope_key' => SeoMetadata::scopeKeyFor($domainA->id),
            'locale' => 'en',
            'meta_title' => 'Vellore Healthcare',
        ]);

        $rev = SeoRevision::query()->create([
            'seo_metadata_id' => $seo->id,
            'version_number' => 1,
            'snapshot_json' => ['meta_title' => 'Vellore Healthcare'],
            'created_by_user_id' => $actor->id,
            'created_at' => now(),
        ]);

        $domainA->forceDelete();

        $seo->refresh();
        $this->assertNull($seo->domain_id);
        $this->assertNotNull(SeoRevision::query()->find($rev->id));
    }
}
