<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table): void {
            $table->char('public_id', 26)->nullable()->unique()->after('id');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->char('public_id', 26)->nullable()->unique()->after('id');
            $table->char('device_public_id', 26)->nullable()->index()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['device_public_id']);
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'device_public_id']);
        });

        Schema::table('user_devices', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
