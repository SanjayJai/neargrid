<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->char('challenge_id', 26)->unique();
            $table->string('phone_e164', 20);
            $table->string('purpose', 32);
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->char('request_ip_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['phone_e164', 'purpose', 'created_at'], 'idx_otp_phone_purpose_created');
            $table->index('expires_at', 'idx_otp_expires_at');
        });

        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('device_identifier', 191);
            $table->string('fcm_token', 512)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_identifier'], 'uniq_user_devices_user_identifier');
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('otp_challenges');
    }
};
