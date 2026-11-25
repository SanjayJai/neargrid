<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 180);
            $table->string('slug', 200)->unique();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_members', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32); // org_owner, corporate_admin, regional_manager
            $table->string('scope_type', 32)->default('company_owned_only'); // all_branches, company_owned_only, assigned_cities
            $table->json('scope_city_ids')->nullable();
            $table->json('permissions')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id'], 'uniq_org_members_org_user');
            $table->index(['user_id', 'status'], 'idx_org_members_user_status');
        });

        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('hostname', 253)->unique();
            $table->string('domain_type', 24)->default('city_portal'); // primary, city_portal, brand_microsite
            $table->boolean('is_primary')->default(false);
            // Nullable unique sentinel enforces at most one active primary domain across SQLite, MariaDB 10.4, and MySQL 8.
            $table->unsignedTinyInteger('primary_slot')->nullable()->unique('uniq_domains_primary_slot');
            $table->string('status', 24)->default('draft'); // draft, pending_verification, active, suspended
            $table->string('verification_method', 24)->default('dns_txt'); // dns_txt, http_file
            $table->string('verification_status', 24)->default('unverified'); // unverified, pending, verified, failed, expired
            $table->char('challenge_hash', 64)->nullable();
            $table->timestamp('challenge_expires_at')->nullable();
            $table->timestamp('challenge_consumed_at')->nullable();
            $table->unsignedSmallInteger('verification_attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->string('ssl_provider', 32)->default('cloudflare');
            $table->string('ssl_status', 24)->default('not_started'); // not_started, provisioning, active, renewing, failed
            $table->timestamp('ssl_checked_at')->nullable();
            $table->string('visibility_mode', 24)->default('global'); // global, location_scoped, organization_scoped
            $table->foreignId('default_city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('default_state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->string('default_locale', 10)->default('en');
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->unsignedBigInteger('canonical_domain_id')->nullable();
            $table->boolean('is_canonical_for_location')->default(false);
            $table->boolean('is_indexable')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'verification_status', 'ssl_status'], 'idx_domains_status_verif');
            $table->index(['default_city_id', 'status'], 'idx_domains_default_city');
        });

        Schema::table('domains', function (Blueprint $table): void {
            $table->foreign('canonical_domain_id', 'fk_domains_canonical_domain')
                ->references('id')
                ->on('domains')
                ->nullOnDelete();
        });

        $this->bootstrapPrimaryDomain();
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropForeign('fk_domains_canonical_domain');
        });

        Schema::dropIfExists('domains');
        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organizations');
    }

    private function bootstrapPrimaryDomain(): void
    {
        if (DB::table('domains')->exists()) {
            return;
        }

        $rawUrl = (string) config('app.url', 'http://localhost:3000');
        $parsedHost = parse_url($rawUrl, PHP_URL_HOST);
        $hostname = is_string($parsedHost) && $parsedHost !== ''
            ? strtolower(trim($parsedHost))
            : 'localhost';

        $now = now();

        DB::table('domains')->insert([
            'public_id' => (string) Str::ulid(),
            'hostname' => $hostname,
            'domain_type' => 'primary',
            'is_primary' => true,
            'primary_slot' => 1,
            'status' => 'active',
            'verification_method' => 'dns_txt',
            'verification_status' => 'unverified',
            'verified_at' => null,
            'ssl_provider' => 'cloudflare',
            'ssl_status' => 'not_started',
            'ssl_checked_at' => null,
            'visibility_mode' => 'global',
            'default_locale' => 'en',
            'is_canonical_for_location' => false,
            'is_indexable' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
