<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Directory\Models\BusinessService;
use App\Domains\Directory\Models\BusinessVerification;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Models\PersonalAccessToken;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Identity\Services\NullOtpSender;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Domains\Media\Models\BusinessMedia;
use App\Domains\Media\Models\KycDocument;
use App\Domains\Notifications\Channels\CustomDatabaseChannel;
use App\Domains\Search\Contracts\SearchEngineInterface;
use App\Domains\Search\Engines\MysqlSearchEngine;
use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryCollection;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\BusinessMediaPolicy;
use App\Policies\BusinessMemberPolicy;
use App\Policies\BusinessPolicy;
use App\Policies\BusinessServicePolicy;
use App\Policies\BusinessVerificationPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\EnquiryPolicy;
use App\Policies\KycDocumentPolicy;
use App\Policies\LocationPolicy;
use App\Policies\PersonalAccessTokenPolicy;
use App\Policies\UserDevicePolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpSender::class, NullOtpSender::class);
        $this->app->bind(SearchEngineInterface::class, MysqlSearchEngine::class);
        $this->app->bind(DatabaseChannel::class, CustomDatabaseChannel::class);
    }

    public function boot(): void
    {
        if ($this->app->isProduction()) {
            $cacheStore = (string) config('cache.default');
            if ($cacheStore !== 'redis') {
                throw new \RuntimeException(
                    "Production configuration violation: CACHE_STORE must be set to 'redis' (currently '{$cacheStore}'). Database, file, or array stores are not permitted in production."
                );
            }

            $queueConnection = (string) config('queue.default');
            if ($queueConnection !== 'redis') {
                throw new \RuntimeException(
                    "Production configuration violation: QUEUE_CONNECTION must be set to 'redis' (currently '{$queueConnection}'). Synchronous or database queue connections are not permitted in production."
                );
            }

            if ($this->app->make(OtpSender::class) instanceof NullOtpSender) {
                throw new \RuntimeException(
                    'Production configuration violation: NullOtpSender is not permitted in production. Configure a production OtpSender implementation.'
                );
            }
        }

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(UserDevice::class, UserDevicePolicy::class);
        Gate::policy(PersonalAccessToken::class, PersonalAccessTokenPolicy::class);
        Gate::policy(Business::class, BusinessPolicy::class);
        Gate::policy(BusinessService::class, BusinessServicePolicy::class);
        Gate::policy(BusinessMember::class, BusinessMemberPolicy::class);
        Gate::policy(BusinessMedia::class, BusinessMediaPolicy::class);
        Gate::policy(BusinessVerification::class, BusinessVerificationPolicy::class);
        Gate::policy(KycDocument::class, KycDocumentPolicy::class);
        Gate::policy(Enquiry::class, EnquiryPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(State::class, LocationPolicy::class);
        Gate::policy(City::class, LocationPolicy::class);
        Gate::policy(Locality::class, LocationPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(CategoryCollection::class, CategoryPolicy::class);
        Gate::policy(SeoMetadata::class, CategoryPolicy::class);

        RateLimiter::for('search', static function (Request $request): Limit {
            $perMinute = max(10, (int) config('search.rate_limit_per_minute', 120));

            return Limit::perMinute($perMinute)->by((string) $request->ip());
        });

        RateLimiter::for('search-suggestions', static function (Request $request): Limit {
            $perMinute = max(10, (int) config('search.autocomplete.rate_limit_per_minute', 120));

            return Limit::perMinute($perMinute)->by((string) $request->ip());
        });
    }
}
