<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Account\SavedBusinessController;
use App\Http\Controllers\Api\V1\Admin\AdminAdCampaignController;
use App\Http\Controllers\Api\V1\Admin\AdminAdCreativeController;
use App\Http\Controllers\Api\V1\Admin\AdminAdPlacementController;
use App\Http\Controllers\Api\V1\Advertising\PublicAdController;
use App\Http\Controllers\Api\V1\Promotions\PublicPromoNoticeController;
use App\Http\Controllers\Api\V1\Admin\AdminAnalyticsController;
use App\Http\Controllers\Api\V1\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\AdminBootstrapController;
use App\Http\Controllers\Api\V1\Admin\AdminBusinessController;
use App\Http\Controllers\Api\V1\Admin\AdminCategoryCollectionController;
use App\Http\Controllers\Api\V1\Admin\AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\AdminDomainController;
use App\Http\Controllers\Api\V1\Admin\AdminEnquiryController;
use App\Http\Controllers\Api\V1\Admin\AdminLocationController;
use App\Http\Controllers\Api\V1\Admin\AdminOrganizationController;
use App\Http\Controllers\Api\V1\Admin\AdminReviewController;
use App\Http\Controllers\Api\V1\Admin\AdminRoleController;
use App\Http\Controllers\Api\V1\Admin\AdminSecurityController;
use App\Http\Controllers\Api\V1\Admin\AdminSeoController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AdminVerificationController;
use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\DeviceSessionController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\Directory\OwnerBusinessAttributeController;
use App\Http\Controllers\Api\V1\Directory\OwnerBusinessController;
use App\Http\Controllers\Api\V1\Directory\OwnerOrganizationController;
use App\Http\Controllers\Api\V1\Directory\OwnerReviewController;
use App\Http\Controllers\Api\V1\Directory\PublicBusinessController;
use App\Http\Controllers\Api\V1\Directory\PublicDomainController;
use App\Http\Controllers\Api\V1\Directory\PublicReviewController;
use App\Http\Controllers\Api\V1\Directory\ReferenceTaxonomyController;
use App\Http\Controllers\Api\V1\Leads\OwnerLeadController;
use App\Http\Controllers\Api\V1\Leads\PublicEnquiryController;
use App\Http\Controllers\Api\V1\Media\BusinessMediaController;
use App\Http\Controllers\Api\V1\Media\KycDocumentController;
use App\Http\Controllers\Api\V1\Notifications\CustomerNotificationController;
use App\Http\Controllers\Api\V1\Search\SearchController;
use App\Http\Controllers\Api\V1\SystemController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', [SystemController::class, 'health'])->name('health');
    Route::get('/meta', [SystemController::class, 'meta'])->name('meta');

    // Reference locations, taxonomy & attributes (English/Tamil)
    Route::get('/locations/states', [ReferenceTaxonomyController::class, 'states'])->name('locations.states');
    Route::get('/locations/cities', [ReferenceTaxonomyController::class, 'cities'])->name('locations.cities');
    Route::get('/locations/cities/{citySlug}/localities', [ReferenceTaxonomyController::class, 'localities'])->name('locations.localities');
    Route::get('/categories', [ReferenceTaxonomyController::class, 'categories'])->name('categories.index');
    Route::get('/categories/tree', [ReferenceTaxonomyController::class, 'categoryTree'])->name('categories.tree');
    Route::get('/categories/landing/{path}', [ReferenceTaxonomyController::class, 'categoryLanding'])
        ->where('path', '.*')
        ->name('categories.landing');
    Route::get('/attributes', [ReferenceTaxonomyController::class, 'attributes'])->name('attributes.index');
    Route::get('/public/domains/resolve', [PublicDomainController::class, 'resolve'])->name('public.domains.resolve');

    // Public advertising delivery & tracking (rate-limited, signed attribution)
    Route::prefix('public/ads')->name('public.ads.')->group(function (): void {
        Route::get('/deliver', [PublicAdController::class, 'deliver'])->middleware('throttle:120,1')->name('deliver');
        Route::post('/impression', [PublicAdController::class, 'recordImpression'])->middleware('throttle:120,1')->name('impression');
        Route::post('/click', [PublicAdController::class, 'recordClick'])->middleware('throttle:60,1')->name('click');
    });

    // Public promo notices & announcements delivery & tracking (M6.2)
    Route::prefix('public/notices')->name('public.notices.')->group(function (): void {
        Route::get('/deliver', [PublicPromoNoticeController::class, 'deliver'])->middleware('throttle:120,1')->name('deliver');
        Route::post('/event', [PublicPromoNoticeController::class, 'recordEvent'])->middleware('throttle:120,1')->name('event');
    });

    // Public business profiles (only status=active, privacy-safe resource)
    Route::get('/businesses/{citySlug}/{businessSlug}', [PublicBusinessController::class, 'show'])->name('businesses.show');
    Route::get('/businesses/{citySlug}/{businessSlug}/reviews', [PublicReviewController::class, 'index'])->name('businesses.reviews.index');
    Route::post('/businesses/{citySlug}/{businessSlug}/reviews', [PublicReviewController::class, 'store'])->name('businesses.reviews.store');
    Route::get('/businesses/{business}/reviews', [PublicReviewController::class, 'indexByBusiness'])->name('businesses.reviews.by-business');
    Route::post('/businesses/{business}/reviews', [PublicReviewController::class, 'storeByBusiness'])->name('businesses.reviews.store-by-business');
    Route::post('/reviews/{review}/verify-otp', [PublicReviewController::class, 'verifyOtp'])->name('reviews.verify-otp');
    Route::post('/reviews/{review}/report', [PublicReviewController::class, 'report'])->name('reviews.report');

    // Privacy-compliant customer enquiries and contact reveals
    Route::post('/enquiries', [PublicEnquiryController::class, 'store'])->name('enquiries.store');
    Route::post('/enquiries/{enquiry}/verify-otp', [PublicEnquiryController::class, 'verifyOtp'])->name('enquiries.verify-otp');
    Route::post('/businesses/{business}/contact-reveal', [PublicEnquiryController::class, 'contactReveal'])->name('businesses.contact-reveal');

    // Public search, nearby discovery, autocomplete & filters (rate-limited, active-only)
    Route::prefix('search')->name('search.')->group(function (): void {
        Route::get('/', [SearchController::class, 'search'])->middleware('throttle:search')->name('index');
        Route::get('/nearby', [SearchController::class, 'nearby'])->middleware('throttle:search')->name('nearby');
        Route::get('/suggestions', [SearchController::class, 'suggestions'])->middleware('throttle:search-suggestions')->name('suggestions');
        Route::get('/filters', [SearchController::class, 'filters'])->middleware('throttle:search')->name('filters');
    });

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

            Route::get('/{business}/attributes', [OwnerBusinessAttributeController::class, 'show'])->name('attributes.show');
            Route::put('/{business}/attributes', [OwnerBusinessAttributeController::class, 'update'])->name('attributes.update');
            Route::post('/{business}/attributes/validate', [OwnerBusinessAttributeController::class, 'validateForPublish'])->name('attributes.validate');
            Route::delete('/{business}/attributes/orphans', [OwnerBusinessAttributeController::class, 'destroyOrphans'])->name('attributes.orphans.destroy');

            Route::post('/{business}/services', [OwnerBusinessController::class, 'storeService'])->name('services.store');
            Route::put('/{business}/services/{service}', [OwnerBusinessController::class, 'updateService'])->name('services.update');
            Route::delete('/{business}/services/{service}', [OwnerBusinessController::class, 'destroyService'])->name('services.destroy');

            Route::get('/{business}/members', [OwnerBusinessController::class, 'indexMembers'])->name('members.index');
            Route::post('/{business}/members', [OwnerBusinessController::class, 'storeMember'])->name('members.store');
            Route::patch('/{business}/members/{member}', [OwnerBusinessController::class, 'updateMember'])->name('members.update');
            Route::delete('/{business}/members/{member}', [OwnerBusinessController::class, 'destroyMember'])->name('members.destroy');

            Route::get('/{business}/verifications', [OwnerBusinessController::class, 'indexVerifications'])->name('verifications.index');
            Route::post('/{business}/verifications', [OwnerBusinessController::class, 'storeVerification'])->name('verifications.store');

            // Inbound CRM lead management & staff assignments
            Route::get('/{business}/leads', [OwnerLeadController::class, 'index'])->name('leads.index');
            Route::patch('/{business}/leads/{lead}', [OwnerLeadController::class, 'update'])->name('leads.update');

            // Business owner reviews & replies
            Route::get('/{business}/reviews', [OwnerReviewController::class, 'index'])->name('reviews.index');
            Route::post('/{business}/reviews/{review}/reply', [OwnerReviewController::class, 'reply'])->name('reviews.reply');
            Route::delete('/{business}/reviews/{review}/reply', [OwnerReviewController::class, 'deleteReply'])->name('reviews.reply.delete');

            // Public business media management (logo, cover, gallery)
            Route::get('/{business}/media', [BusinessMediaController::class, 'index'])->name('media.index');
            Route::post('/{business}/media', [BusinessMediaController::class, 'store'])->name('media.store');
            Route::delete('/{business}/media/{media}', [BusinessMediaController::class, 'destroy'])->name('media.destroy');

            // Private KYC document uploads & short-lived signed URL issuance
            Route::get('/{business}/kyc-documents', [KycDocumentController::class, 'index'])->name('kyc-documents.index');
            Route::post('/{business}/kyc-documents', [KycDocumentController::class, 'store'])->name('kyc-documents.store');
            Route::post('/{business}/kyc-documents/{kycDocument}/signed-url', [KycDocumentController::class, 'signedUrl'])->name('kyc-documents.signed-url');
            Route::delete('/{business}/kyc-documents/{kycDocument}', [KycDocumentController::class, 'destroy'])->name('kyc-documents.destroy');
        });

        // Organization & company-owned/franchise branch management endpoints
        Route::prefix('owner/organizations')->name('owner.organizations.')->group(function (): void {
            Route::get('/', [OwnerOrganizationController::class, 'index'])->name('index');
            Route::post('/', [OwnerOrganizationController::class, 'store'])->name('store');
            Route::get('/{organization}', [OwnerOrganizationController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '/{organization}', [OwnerOrganizationController::class, 'update'])->name('update');

            Route::get('/{organization}/members', [OwnerOrganizationController::class, 'indexMembers'])->name('members.index');
            Route::post('/{organization}/members', [OwnerOrganizationController::class, 'upsertMember'])->name('members.upsert');

            Route::get('/{organization}/branches', [OwnerOrganizationController::class, 'indexBranches'])->name('branches.index');
            Route::post('/{organization}/branches', [OwnerOrganizationController::class, 'attachBranch'])->name('branches.attach');
            Route::post('/{organization}/branches/{business}/hq-lead-access', [OwnerOrganizationController::class, 'updateFranchiseHqLeadAccess'])
                ->name('branches.hq-lead-access');

            Route::get('/{organization}/leads', [OwnerOrganizationController::class, 'indexLeads'])->name('leads.index');
        });

        // Customer enquiry transparency portal
        Route::get('/account/enquiries', [PublicEnquiryController::class, 'customerEnquiries'])->name('account.enquiries');

        // Customer notifications centre
        Route::prefix('account/notifications')->name('account.notifications.')->group(function (): void {
            Route::get('/', [CustomerNotificationController::class, 'index'])->name('index');
            Route::get('/unread-count', [CustomerNotificationController::class, 'unreadCount'])->name('unread-count');
            Route::post('/mark-all-read', [CustomerNotificationController::class, 'markAllAsRead'])->name('mark-all-read');
            Route::patch('/{notification}/read', [CustomerNotificationController::class, 'markAsRead'])->name('read');
            Route::delete('/{notification}', [CustomerNotificationController::class, 'destroy'])->name('destroy');
        });

        // Customer saved businesses (bookmarks)
        Route::prefix('account/saved-businesses')->name('account.saved-businesses.')->group(function (): void {
            Route::get('/', [SavedBusinessController::class, 'index'])->name('index');
            Route::get('/ids', [SavedBusinessController::class, 'ids'])->name('ids');
            Route::match(['put', 'post'], '/{business}', [SavedBusinessController::class, 'save'])->name('save');
            Route::delete('/{business}', [SavedBusinessController::class, 'destroy'])->name('destroy');
        });

        // Super Admin foundation endpoints (governance, moderation & audit)
        Route::prefix('admin')->name('admin.')->group(function (): void {
            Route::get('/bootstrap', [AdminBootstrapController::class, 'show'])->name('bootstrap');
            Route::get('/analytics', [AdminAnalyticsController::class, 'index'])->name('analytics');
            Route::get('/enquiries', [AdminEnquiryController::class, 'index'])->name('enquiries.index');
            Route::get('/enquiries/{enquiry}', [AdminEnquiryController::class, 'show'])->name('enquiries.show');
            Route::patch('/enquiries/{enquiry}/cancel', [AdminEnquiryController::class, 'cancel'])->name('enquiries.cancel');

            Route::get('/reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
            Route::get('/reviews/{review}', [AdminReviewController::class, 'show'])->name('reviews.show');
            Route::patch('/reviews/{review}/moderation', [AdminReviewController::class, 'moderate'])->name('reviews.moderate');

            Route::get('/verifications', [AdminVerificationController::class, 'index'])->name('verifications.index');
            Route::get('/businesses/{business}/kyc-documents/{kycDocument}/signed-url', [AdminVerificationController::class, 'signedUrl'])->name('verifications.kyc-signed-url');
            Route::post('/verifications/{verification}/review', [AdminVerificationController::class, 'review'])->name('verifications.review');
            Route::get('/claims', [AdminVerificationController::class, 'indexClaims'])->name('claims.index');
            Route::post('/claims/{claim}/review', [AdminVerificationController::class, 'reviewClaim'])->name('claims.review');

            Route::prefix('businesses')->name('businesses.')->group(function (): void {
                Route::get('/', [AdminBusinessController::class, 'index'])->name('index');
                Route::get('/{business}', [AdminBusinessController::class, 'show'])->name('show');
                Route::patch('/{business}/status', [AdminBusinessController::class, 'updateStatus'])->name('status');
                Route::patch('/{business}/badges', [AdminBusinessController::class, 'updateBadges'])->name('badges');
            });

            Route::prefix('organizations')->name('organizations.')->group(function (): void {
                Route::get('/', [AdminOrganizationController::class, 'index'])->name('index');
                Route::post('/', [AdminOrganizationController::class, 'store'])->name('store');
                Route::get('/{organization}', [AdminOrganizationController::class, 'show'])->name('show');
                Route::patch('/{organization}', [AdminOrganizationController::class, 'update'])->name('update');
                Route::post('/{organization}/branches', [AdminOrganizationController::class, 'attachBranch'])->name('branches.attach');
                Route::patch('/{organization}/branches/{business}/hq-lead-access', [AdminOrganizationController::class, 'setFranchiseHqLeadAccess'])->name('branches.hq-lead-access');
                Route::post('/{organization}/members', [AdminOrganizationController::class, 'upsertMember'])->name('members.upsert');
            });

            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
            Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])->middleware('throttle:30,1')->name('users.status');
            Route::post('/users/{user}/verify', [AdminUserController::class, 'verifyAccount'])->middleware('throttle:30,1')->name('users.verify');
            Route::post('/users/{user}/revoke-sessions', [AdminUserController::class, 'revokeSessions'])->middleware('throttle:30,1')->name('users.revoke-sessions');
            Route::post('/users/{user}/roles', [AdminRoleController::class, 'assignUserRole'])->middleware('throttle:30,1')->name('users.roles.assign');
            Route::delete('/users/{user}/roles/{role}', [AdminRoleController::class, 'removeUserRole'])->middleware('throttle:30,1')->name('users.roles.remove');
            Route::post('/users/{user}/erasure-requests', [AdminUserController::class, 'requestErasure'])->middleware('throttle:15,1')->name('users.erasure.request');

            Route::get('/erasure-requests', [AdminUserController::class, 'indexErasureRequests'])->name('erasure.index');
            Route::post('/erasure-requests/{erasureRequest}/review', [AdminUserController::class, 'reviewErasureRequest'])->middleware('throttle:15,1')->name('erasure.review');

            Route::get('/roles', [AdminRoleController::class, 'indexRoles'])->name('roles.index');
            Route::put('/roles/{role}/permissions', [AdminRoleController::class, 'syncRolePermissions'])->middleware('throttle:20,1')->name('roles.permissions.sync');
            Route::get('/permissions', [AdminRoleController::class, 'indexPermissions'])->name('permissions.index');

            Route::get('/security/sessions', [AdminSecurityController::class, 'sessions'])->name('security.sessions.index');
            Route::delete('/security/sessions/{tokenPublicId}', [AdminSecurityController::class, 'revokeSession'])->middleware('throttle:30,1')->name('security.sessions.revoke');
            Route::get('/security/events', [AdminSecurityController::class, 'events'])->name('security.events.index');

            Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');

            // Master Super Admin Location Management (States, Cities & Localities)
            Route::prefix('locations')->name('locations.')->group(function (): void {
                Route::get('/states', [AdminLocationController::class, 'indexStates'])->name('states.index');
                Route::post('/states', [AdminLocationController::class, 'storeState'])->name('states.store');
                Route::get('/states/{state}', [AdminLocationController::class, 'showState'])->name('states.show');
                Route::match(['put', 'patch'], '/states/{state}', [AdminLocationController::class, 'updateState'])->name('states.update');
                Route::post('/states/{state}/archive', [AdminLocationController::class, 'archiveState'])->name('states.archive');
                Route::delete('/states/{state}', [AdminLocationController::class, 'destroyState'])->name('states.destroy');

                Route::get('/cities', [AdminLocationController::class, 'indexCities'])->name('cities.index');
                Route::post('/cities', [AdminLocationController::class, 'storeCity'])->name('cities.store');
                Route::get('/cities/{city}', [AdminLocationController::class, 'showCity'])->name('cities.show');
                Route::match(['put', 'patch'], '/cities/{city}', [AdminLocationController::class, 'updateCity'])->name('cities.update');
                Route::post('/cities/{city}/archive', [AdminLocationController::class, 'archiveCity'])->name('cities.archive');
                Route::delete('/cities/{city}', [AdminLocationController::class, 'destroyCity'])->name('cities.destroy');

                Route::get('/localities', [AdminLocationController::class, 'indexLocalities'])->name('localities.index');
                Route::post('/localities', [AdminLocationController::class, 'storeLocality'])->name('localities.store');
                Route::get('/localities/{locality}', [AdminLocationController::class, 'showLocality'])->name('localities.show');
                Route::match(['put', 'patch'], '/localities/{locality}', [AdminLocationController::class, 'updateLocality'])->name('localities.update');
                Route::post('/localities/{locality}/archive', [AdminLocationController::class, 'archiveLocality'])->name('localities.archive');
                Route::delete('/localities/{locality}', [AdminLocationController::class, 'destroyLocality'])->name('localities.destroy');
            });

            // Super Admin Category Engine & Global SEO Management (M-A1)
            Route::prefix('categories')->name('categories.')->group(function (): void {
                Route::get('/', [AdminCategoryController::class, 'index'])->name('index');
                Route::post('/', [AdminCategoryController::class, 'store'])->name('store');
                Route::post('/reorder', [AdminCategoryController::class, 'reorder'])->name('reorder');
                Route::get('/{category}', [AdminCategoryController::class, 'show'])->name('show');
                Route::match(['put', 'patch'], '/{category}', [AdminCategoryController::class, 'update'])->name('update');
                Route::post('/{category}/move', [AdminCategoryController::class, 'move'])->name('move');
                Route::post('/{category}/clone', [AdminCategoryController::class, 'clone'])->name('clone');
                Route::post('/{category}/publish', [AdminCategoryController::class, 'publish'])->name('publish');
                Route::post('/{category}/archive', [AdminCategoryController::class, 'archive'])->name('archive');
                Route::post('/{categoryPublicId}/restore', [AdminCategoryController::class, 'restore'])->name('restore');
                Route::delete('/{category}', [AdminCategoryController::class, 'destroy'])->name('destroy');
                Route::get('/{category}/dependencies', [AdminCategoryController::class, 'dependencies'])->name('dependencies');
                Route::put('/{category}/schemas', [AdminCategoryController::class, 'syncSchemas'])->name('schemas.sync');
                Route::post('/{category}/revisions/{revision}/rollback', [AdminCategoryController::class, 'rollback'])->name('revisions.rollback');
            });

            Route::prefix('category-collections')->name('category-collections.')->group(function (): void {
                Route::get('/', [AdminCategoryCollectionController::class, 'index'])->name('index');
                Route::post('/', [AdminCategoryCollectionController::class, 'store'])->name('store');
                Route::match(['put', 'patch'], '/{categoryCollection}', [AdminCategoryCollectionController::class, 'update'])->name('update');
                Route::delete('/{categoryCollection}', [AdminCategoryCollectionController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('seo')->name('seo.')->group(function (): void {
                Route::get('/metadata', [AdminSeoController::class, 'indexMetadata'])->name('metadata.index');
                Route::get('/redirects', [AdminSeoController::class, 'indexRedirects'])->name('redirects.index');
                Route::post('/redirects', [AdminSeoController::class, 'storeRedirect'])->name('redirects.store');
                Route::patch('/redirects/{urlRedirect}', [AdminSeoController::class, 'updateRedirect'])->name('redirects.update');
                Route::delete('/redirects/{urlRedirect}', [AdminSeoController::class, 'destroyRedirect'])->name('redirects.destroy');
                Route::get('/{entityType}/{entityIdentifier}', [AdminSeoController::class, 'show'])->name('show');
                Route::put('/{entityType}/{entityIdentifier}', [AdminSeoController::class, 'upsert'])->name('upsert');
                Route::post('/metadata/{seoMetadata}/rollback/{revision}', [AdminSeoController::class, 'rollback'])->name('rollback');
            });

            Route::prefix('advertising')->name('advertising.')->group(function (): void {
                Route::get('/campaigns', [AdminAdCampaignController::class, 'index'])->name('campaigns.index');
                Route::post('/campaigns', [AdminAdCampaignController::class, 'store'])->name('campaigns.store');
                Route::get('/campaigns/{adCampaign}', [AdminAdCampaignController::class, 'show'])->name('campaigns.show');
                Route::patch('/campaigns/{adCampaign}', [AdminAdCampaignController::class, 'update'])->name('campaigns.update');
                Route::post('/campaigns/{adCampaign}/approve', [AdminAdCampaignController::class, 'approve'])->name('campaigns.approve');
                Route::post('/campaigns/{adCampaign}/reject', [AdminAdCampaignController::class, 'reject'])->name('campaigns.reject');
                Route::patch('/campaigns/{adCampaign}/status', [AdminAdCampaignController::class, 'updateStatus'])->name('campaigns.status');
                Route::put('/campaigns/{adCampaign}/placements', [AdminAdCampaignController::class, 'syncPlacements'])->name('campaigns.placements.sync');
                Route::put('/campaigns/{adCampaign}/businesses', [AdminAdCampaignController::class, 'syncBusinesses'])->name('campaigns.businesses.sync');
                Route::put('/campaigns/{adCampaign}/targeting-rules', [AdminAdCampaignController::class, 'syncTargetingRules'])->name('campaigns.targeting.sync');
                Route::delete('/campaigns/{adCampaign}', [AdminAdCampaignController::class, 'destroy'])->name('campaigns.destroy');

                Route::get('/placements', [AdminAdPlacementController::class, 'index'])->name('placements.index');
                Route::get('/placements/conflicts', [AdminAdPlacementController::class, 'conflicts'])->name('placements.conflicts');
                Route::get('/placements/{adPlacement}', [AdminAdPlacementController::class, 'show'])->name('placements.show');
                Route::patch('/placements/{adPlacement}', [AdminAdPlacementController::class, 'update'])->name('placements.update');

                Route::get('/creatives', [AdminAdCreativeController::class, 'index'])->name('creatives.index');
                Route::post('/creatives', [AdminAdCreativeController::class, 'store'])->name('creatives.store');
                Route::patch('/creatives/{adCreative}', [AdminAdCreativeController::class, 'update'])->name('creatives.update');
                Route::post('/creatives/upload-image', [AdminAdCreativeController::class, 'uploadImage'])->middleware('throttle:20,1')->name('creatives.upload');
                Route::delete('/creatives/{adCreative}', [AdminAdCreativeController::class, 'destroy'])->name('creatives.destroy');
            });

            Route::prefix('domains')->name('domains.')->group(function (): void {
                Route::get('/', [AdminDomainController::class, 'index'])->name('index');
                Route::post('/', [AdminDomainController::class, 'store'])->name('store');
                Route::get('/{domain}', [AdminDomainController::class, 'show'])->name('show');
                Route::match(['put', 'patch'], '/{domain}', [AdminDomainController::class, 'update'])->name('update');
                Route::post('/{domain}/challenge', [AdminDomainController::class, 'issueChallenge'])->name('challenge');
                Route::post('/{domain}/verify', [AdminDomainController::class, 'verify'])->name('verify');
                Route::post('/{domain}/ssl', [AdminDomainController::class, 'checkSsl'])->name('ssl');
                Route::post('/{domain}/promote-primary', [AdminDomainController::class, 'promotePrimary'])->name('promote-primary');
                Route::post('/{domain}/suspend', [AdminDomainController::class, 'suspend'])->name('suspend');
                Route::delete('/{domain}', [AdminDomainController::class, 'destroy'])->name('destroy');
            });
        });

        // Short-lived, actor-bound, signature-verified private KYC download endpoint
        Route::get('/kyc-documents/{kycDocument}/download', [KycDocumentController::class, 'download'])
            ->middleware('signed')
            ->name('kyc.download');
    });
});
