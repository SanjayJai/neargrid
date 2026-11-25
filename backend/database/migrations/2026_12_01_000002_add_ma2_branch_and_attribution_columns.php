<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('owner_user_id')
                ->constrained('organizations')
                ->nullOnDelete();
            $table->string('branch_type', 24)
                ->default('independent')
                ->after('organization_id'); // independent, headquarters, company_owned, franchise
            $table->string('franchise_code', 60)
                ->nullable()
                ->after('branch_type');
            $table->boolean('allow_hq_lead_access')
                ->default(false)
                ->after('franchise_code');
            $table->foreignId('canonical_domain_id')
                ->nullable()
                ->after('allow_hq_lead_access')
                ->constrained('domains')
                ->nullOnDelete();

            $table->index(['organization_id', 'branch_type', 'status'], 'idx_biz_org_branch');
        });

        Schema::table('enquiries', function (Blueprint $table): void {
            $table->foreignId('source_domain_id')
                ->nullable()
                ->after('cancelled_by_user_id')
                ->constrained('domains')
                ->nullOnDelete();
            $table->string('source_host', 253)
                ->nullable()
                ->after('source_domain_id');
            $table->string('entry_path', 500)
                ->nullable()
                ->after('source_host');

            $table->index(['source_domain_id', 'created_at'], 'idx_enquiries_source_domain');
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table): void {
            $table->dropForeign(['source_domain_id']);
            $table->dropIndex('idx_enquiries_source_domain');
            $table->dropColumn(['source_domain_id', 'source_host', 'entry_path']);
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropForeign(['canonical_domain_id']);
            $table->dropForeign(['organization_id']);
            $table->dropIndex('idx_biz_org_branch');
            $table->dropColumn([
                'organization_id',
                'branch_type',
                'franchise_code',
                'allow_hq_lead_access',
                'canonical_domain_id',
            ]);
        });
    }
};
