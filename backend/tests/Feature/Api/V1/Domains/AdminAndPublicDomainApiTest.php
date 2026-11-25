<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Domains;

use App\Domains\Domains\Contracts\DomainChallengeProber;
use App\Domains\Domains\Exceptions\DomainException;
use App\Domains\Domains\Models\Domain;
use App\Domains\Domains\Services\DomainVerificationService;
use App\Domains\Domains\Services\NativeDomainChallengeProber;
use App\Domains\Location\Models\City;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminAndPublicDomainApiTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['status' => 'active']);
        $roleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        DB::table('user_roles')->insert([
            'user_id' => $admin->id,
            'role_id' => $roleId,
        ]);

        return $admin;
    }

    public function test_unauthenticated_and_non_super_admin_cannot_access_admin_domain_endpoints(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->getJson('/api/v1/admin/domains')->assertStatus(401);
        $this->postJson('/api/v1/admin/domains', ['hostname' => 'vellore.neargrid.in', 'domain_type' => 'city_portal'])
            ->assertStatus(401);

        $customer = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/admin/domains')->assertStatus(403);
        $this->postJson('/api/v1/admin/domains', ['hostname' => 'vellore.neargrid.in', 'domain_type' => 'city_portal'])
            ->assertStatus(403);
    }

    public function test_super_admin_domain_lifecycle_uses_server_validation_and_never_trusts_client_flags(): void
    {
        $admin = $this->createSuperAdmin();
        $city = City::query()->firstOrFail();

        $observedTokens = [];
        $sslValid = false;

        $this->app->instance(DomainChallengeProber::class, new class($observedTokens, $sslValid) implements DomainChallengeProber {
            /** @var list<string> */
            public array $tokens;
            public bool $sslValid;

            public function __construct(array &$tokens, bool &$sslValid)
            {
                $this->tokens = &$tokens;
                $this->sslValid = &$sslValid;
            }

            public function fetchChallengeTokens(Domain $domain): array
            {
                return $this->tokens;
            }

            public function probeSslCertificate(Domain $domain): bool
            {
                return $this->sslValid;
            }
        });

        Sanctum::actingAs($admin);

        // Even if client sends verification_status/ssl_status/status, server starts draft + unverified + not_started
        $createRes = $this->postJson('/api/v1/admin/domains', [
            'hostname' => 'VELLORE.NearGrid.IN:443',
            'domain_type' => 'city_portal',
            'visibility_mode' => 'location_scoped',
            'default_city_public_id' => $city->public_id,
            'is_canonical_for_location' => true,
            'is_indexable' => true,
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'status' => 'active',
        ])->assertStatus(201);

        $publicId = $createRes->json('data.public_id');
        $this->assertSame('vellore.neargrid.in', $createRes->json('data.hostname'));
        $this->assertSame('draft', $createRes->json('data.status'));
        $this->assertSame('unverified', $createRes->json('data.verification_status'));
        $this->assertSame('not_started', $createRes->json('data.ssl_status'));
        $this->assertFalse($createRes->json('data.effectively_indexable'));

        // Issue verification challenge
        $challengeRes = $this->postJson("/api/v1/admin/domains/{$publicId}/challenge")->assertStatus(200);
        $token = (string) $challengeRes->json('data.challenge_token');
        $this->assertNotEmpty($token);

        // Verify with wrong observed token fails even if client passes verified=true
        $observedTokens = ['wrong-token'];
        $this->postJson("/api/v1/admin/domains/{$publicId}/verify", ['verified' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CHALLENGE_MISMATCH');

        // Verify with matching DNS TXT token activates domain, keeps ssl_status = not_started
        $observedTokens = [$token];
        $verifyRes = $this->postJson("/api/v1/admin/domains/{$publicId}/verify")->assertStatus(200);
        $this->assertSame('active', $verifyRes->json('data.status'));
        $this->assertSame('verified', $verifyRes->json('data.verification_status'));
        $this->assertSame('not_started', $verifyRes->json('data.ssl_status'));
        $this->assertFalse($verifyRes->json('data.effectively_indexable'));

        // SSL probe failed -> ssl_status = failed, still not indexable
        $sslValid = false;
        $this->postJson("/api/v1/admin/domains/{$publicId}/ssl", ['ssl_status' => 'active'])
            ->assertStatus(200)
            ->assertJsonPath('data.ssl_status', 'failed')
            ->assertJsonPath('data.effectively_indexable', false);

        // SSL probe passed -> ssl_status = active, now effectively indexable
        $sslValid = true;
        $this->postJson("/api/v1/admin/domains/{$publicId}/ssl")
            ->assertStatus(200)
            ->assertJsonPath('data.ssl_status', 'active')
            ->assertJsonPath('data.effectively_indexable', true);

        // Promote to primary succeeds cleanly; cannot suspend or delete active primary domain
        $this->postJson("/api/v1/admin/domains/{$publicId}/promote-primary")
            ->assertStatus(200)
            ->assertJsonPath('data.is_primary', true);

        $this->postJson("/api/v1/admin/domains/{$publicId}/suspend")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CANNOT_SUSPEND_PRIMARY_DOMAIN');

        $this->deleteJson("/api/v1/admin/domains/{$publicId}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CANNOT_DELETE_PRIMARY_DOMAIN');
    }

    public function test_public_domain_resolve_enforces_active_verified_allowlist(): void
    {
        $admin = $this->createSuperAdmin();
        $city = City::query()->firstOrFail();

        Domain::query()->create([
            'hostname' => 'vellore.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'visibility_mode' => 'location_scoped',
            'default_city_id' => $city->id,
            'is_canonical_for_location' => true,
            'is_indexable' => true,
            'created_by_user_id' => $admin->id,
        ]);

        Domain::query()->create([
            'hostname' => 'unverified.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'unverified',
            'ssl_status' => 'not_started',
            'created_by_user_id' => $admin->id,
        ]);

        Domain::query()->create([
            'hostname' => 'suspended.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'suspended',
            'verification_status' => 'verified',
            'ssl_status' => 'active',
            'created_by_user_id' => $admin->id,
        ]);

        // Eligible domain resolves cleanly without exposing challenge_hash or internal ids
        $ok = $this->getJson('/api/v1/public/domains/resolve?host=VELLORE.neargrid.in')
            ->assertStatus(200)
            ->assertJsonPath('data.hostname', 'vellore.neargrid.in')
            ->assertJsonPath('data.default_city.slug', $city->slug)
            ->assertJsonPath('data.is_indexable', true);

        $this->assertArrayNotHasKey('id', $ok->json('data'));
        $this->assertArrayNotHasKey('challenge_hash', $ok->json('data'));

        // Unverified, suspended, and unknown hosts return 404
        $this->getJson('/api/v1/public/domains/resolve?host=unverified.neargrid.in')->assertStatus(404);
        $this->getJson('/api/v1/public/domains/resolve?host=suspended.neargrid.in')->assertStatus(404);
        $this->getJson('/api/v1/public/domains/resolve?host=unknown.example.com')->assertStatus(404);
    }

    public function test_native_prober_rejects_unsafe_ipv4_ipv6_mixed_records_redirects_and_oversized_bodies(): void
    {
        $unsafeCases = [
            ['127.0.0.1'],
            ['::1'],
            ['10.0.0.5'],
            ['169.254.169.254'],
            ['fe80::1'],
            ['fc00::1'],
            ['93.184.216.34', '127.0.0.1'],
            ['93.184.216.34', '::1'],
        ];

        foreach ($unsafeCases as $ips) {
            $prober = new NativeDomainChallengeProber(static fn (): array => $ips);
            $this->assertNull($prober->resolveValidatedPublicIp('evil.example.com'));
        }

        $safeProber = new NativeDomainChallengeProber(static fn (): array => ['93.184.216.34']);
        $this->assertSame('93.184.216.34', $safeProber->resolveValidatedPublicIp('safe.example.com'));

        $domain = new Domain([
            'hostname' => 'safe.example.com',
            'verification_method' => 'http_file',
        ]);

        // Redirects are not followed (302 -> empty tokens) and oversized bodies (>4KB) are rejected
        Http::fake([
            'http://safe.example.com/.well-known/neargrid-verify.txt' => Http::sequence()
                ->push('', 302, ['Location' => 'http://127.0.0.1/secret'])
                ->push(str_repeat('A', 5000), 200)
                ->push('ngv_valid_token', 200),
        ]);

        $this->assertSame([], $safeProber->fetchChallengeTokens($domain));
        $this->assertSame([], $safeProber->fetchChallengeTokens($domain));
        $this->assertSame(['ngv_valid_token'], $safeProber->fetchChallengeTokens($domain));
    }

    public function test_domain_verification_enforces_attempt_limits_and_resets_on_new_challenge(): void
    {
        $admin = $this->createSuperAdmin();
        $verifier = app(DomainVerificationService::class);

        $domain = Domain::query()->create([
            'hostname' => 'attempts.neargrid.in',
            'domain_type' => 'city_portal',
            'status' => 'draft',
            'verification_status' => 'unverified',
            'created_by_user_id' => $admin->id,
        ]);

        $token = $verifier->issueChallenge($domain, $admin);
        DB::table('domains')->where('id', $domain->id)->update([
            'verification_attempts' => DomainVerificationService::MAX_VERIFICATION_ATTEMPTS,
        ]);

        try {
            $verifier->verifyChallenge($domain, static fn (): array => [$token], $admin);
            $this->fail('Expected CHALLENGE_ATTEMPTS_EXCEEDED exception.');
        } catch (DomainException $e) {
            $this->assertSame('CHALLENGE_ATTEMPTS_EXCEEDED', $e->errorCode);
        }

        // Issuing a fresh challenge resets attempt counter
        $freshToken = $verifier->issueChallenge($domain, $admin);
        $this->assertSame(0, $domain->fresh()?->verification_attempts);
        $verified = $verifier->verifyChallenge($domain, static fn (): array => [$freshToken], $admin);
        $this->assertSame('verified', $verified->verification_status);
    }
}
