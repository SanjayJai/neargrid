<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/health', function (): View {
        return view('admin.health', [
            'appName' => config('app.name'),
            'apiVersion' => config('directory.api_version'),
        ]);
    })->name('health');
});
