<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\DeviceSessionController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\Directory\OwnerBusinessController;
use App\Http\Controllers\Api\V1\Directory\PublicBusinessController;
use App\Http\Controllers\Api\V1\Directory\ReferenceTaxonomyController;
use App\Http\Controllers\Api\V1\SystemController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', [SystemController::class, 'health'])->name('health');
    Route::get('/meta', [SystemController::class, 'meta'])->name('meta');

    // Reference locations, taxonomy & attributes (English/Tamil)
    Route::get('/locations/cities', [ReferenceTaxonomyController::class, 'cities'])->name('locations.cities');
    Route::get('/locations/cities/{citySlug}/localities', [ReferenceTaxonomyController::class, 'localities'])->name('locations.localities');
    Route::get('/categories', [ReferenceTaxonomyController::class, 'categories'])->name('categories.index');
    Route::get('/attributes', [ReferenceTaxonomyController::class, 'attributes'])->name('attributes.index');

    // Public business profiles (only status=active, privacy-safe resource)
    Route::get('/businesses/{citySlug}/{businessSlug}', [PublicBusinessController::class, 'show'])->name('businesses.show');

    // Authentication & sessions
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/otp/request', [OtpAuthController::class, 'requestOtp'])->name('otp.request');
        Route::post('/otp/resend', [OtpAuthController::class, 'resendOtp'])->name('otp.resend');
        Route::post('/otp/verify', [OtpAuthController::class, 'verifyOtp'])->name('otp.verify');

        Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
            Route::get('/me', [AccountController::class, 'me'])->name('me');
            Route::patch('/me', [AccountController::class, 'updateMe'])->name('me.update');
            Route::post('/logout', [AccountController::class, 'logout'])->name('logout');
            Route::post('/logout-all', [AccountController::class, 'logoutAll'])->name('logout-all');

            Route::get('/devices', [DeviceSessionController::class, 'indexDevices'])->name('devices.index');
            Route::post('/devices', [DeviceSessionController::class, 'storeDevice'])->name('devices.store');
            Route::delete('/devices/{userDevice}', [DeviceSessionController::class, 'destroyDevice'])->name('devices.destroy');

            Route::get('/sessions', [DeviceSessionController::class, 'indexSessions'])->name('sessions.index');
            Route::delete('/sessions/{session}', [DeviceSessionController::class, 'destroySession'])->name('sessions.destroy');
        });
    });

    // Authenticated business claim & owner/staff management endpoints
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('/businesses/{business}/claims', [PublicBusinessController::class, 'submitClaim'])
            ->name('businesses.claims.store');

        Route::prefix('owner/businesses')->name('owner.businesses.')->group(function (): void {
            Route::get('/', [OwnerBusinessController::class, 'index'])->name('index');
            Route::post('/', [OwnerBusinessController::class, 'store'])->name('store');
            Route::get('/{business}', [OwnerBusinessController::class, 'show'])->name('show');
            Route::put('/{business}', [OwnerBusinessController::class, 'update'])->name('update');
            Route::put('/{business}/hours', [OwnerBusinessController::class, 'updateHours'])->name('hours.update');

            Route::post('/{business}/services', [OwnerBusinessController::class, 'storeService'])->name('services.store');
            Route::put('/{business}/services/{service}', [OwnerBusinessController::class, 'updateService'])->name('services.update');
            Route::delete('/{business}/services/{service}', [OwnerBusinessController::class, 'destroyService'])->name('services.destroy');

            Route::get('/{business}/members', [OwnerBusinessController::class, 'indexMembers'])->name('members.index');
            Route::post('/{business}/members', [OwnerBusinessController::class, 'storeMember'])->name('members.store');
            Route::patch('/{business}/members/{member}', [OwnerBusinessController::class, 'updateMember'])->name('members.update');
            Route::delete('/{business}/members/{member}', [OwnerBusinessController::class, 'destroyMember'])->name('members.destroy');

            Route::get('/{business}/verifications', [OwnerBusinessController::class, 'indexVerifications'])->name('verifications.index');
            Route::post('/{business}/verifications', [OwnerBusinessController::class, 'storeVerification'])->name('verifications.store');
        });
    });
});
