<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\DeviceSessionController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\SystemController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', [SystemController::class, 'health'])->name('health');
    Route::get('/meta', [SystemController::class, 'meta'])->name('meta');

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
});
