<?php

declare(strict_types=1);

namespace App\Domains\Directory\Services;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class SavedBusinessService
{
    /**
     * Retrieve paginated saved businesses for the authenticated user.
     * Strictly filters for active, published, non-deleted businesses and eager-loads storefront relations.
     *
     * @return LengthAwarePaginator<Business>
     */
    public function getSavedBusinesses(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $clampedPerPage = min(50, max(1, $perPage));

        return $user->savedBusinesses()
            ->published()
            ->whereNull('businesses.deleted_at')
            ->with([
                'primaryCategory',
                'categories',
                'city',
                'locality',
                'hours',
                'services' => fn ($query) => $query->where('is_active', true),
                'amenities',
                'paymentMethods',
                'faqs' => fn ($query) => $query->where('is_active', true),
                'media' => fn ($query) => $query->where('upload_status', 'ready')->where('moderation_status', 'approved'),
            ])
            ->paginate($clampedPerPage);
    }

    /**
     * Efficiently retrieve bookmarked business public ULIDs for the user.
     * Uses a covered index query avoiding model hydration to minimize database load.
     *
     * @return list<string>
     */
    public function getSavedBusinessPublicIds(User $user): array
    {
        return DB::table('saved_businesses')
            ->join('businesses', 'businesses.id', '=', 'saved_businesses.business_id')
            ->where('saved_businesses.user_id', $user->id)
            ->where('businesses.status', BusinessStatus::Active->value)
            ->whereNull('businesses.deleted_at')
            ->orderByDesc('saved_businesses.created_at')
            ->limit(500)
            ->pluck('businesses.public_id')
            ->all();
    }

    /**
     * Save a business for the customer idempotently.
     * Throws ModelNotFoundException (404) if the business is not found, not active, or soft-deleted.
     */
    public function saveBusiness(User $user, string $businessPublicId): Business
    {
        /** @var Business $business */
        $business = Business::query()
            ->where('public_id', $businessPublicId)
            ->published()
            ->whereNull('deleted_at')
            ->firstOrFail();

        try {
            DB::table('saved_businesses')->insertOrIgnore([
                'user_id' => $user->id,
                'business_id' => $business->id,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKeyException($e)) {
                throw $e;
            }
        }

        return $business;
    }

    /**
     * Remove a business from the customer's saved list idempotently.
     * Allows removing businesses even if they were subsequently suspended or soft-deleted.
     */
    public function unsaveBusiness(User $user, string $businessPublicId): bool
    {
        $business = Business::withTrashed()
            ->where('public_id', $businessPublicId)
            ->first();

        if ($business !== null) {
            DB::table('saved_businesses')
                ->where('user_id', $user->id)
                ->where('business_id', $business->id)
                ->delete();
        }

        return true;
    }

    /**
     * Check if an exception represents an idempotent duplicate primary key collision.
     */
    protected function isDuplicateKeyException(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, '1062 Duplicate')
            || $e->getCode() === '23000';
    }
}
