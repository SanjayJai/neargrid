<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Directory\Models\BusinessService;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Models\PersonalAccessToken;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Identity\Services\NullOtpSender;
use App\Models\User;
use App\Policies\BusinessMemberPolicy;
use App\Policies\BusinessPolicy;
use App\Policies\BusinessServicePolicy;
use App\Policies\PersonalAccessTokenPolicy;
use App\Policies\UserDevicePolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpSender::class, NullOtpSender::class);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(UserDevice::class, UserDevicePolicy::class);
        Gate::policy(PersonalAccessToken::class, PersonalAccessTokenPolicy::class);
        Gate::policy(Business::class, BusinessPolicy::class);
        Gate::policy(BusinessService::class, BusinessServicePolicy::class);
        Gate::policy(BusinessMember::class, BusinessMemberPolicy::class);
    }
}
