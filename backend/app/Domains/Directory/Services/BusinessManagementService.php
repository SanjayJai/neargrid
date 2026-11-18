<?php

declare(strict_types=1);

namespace App\Domains\Directory\Services;

use App\Domains\Directory\Enums\BusinessMemberRole;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Exceptions\DirectoryException;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessHour;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Governance\Models\UrlRedirect;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Support\PhoneNormalizer;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BusinessManagementService
{
    public function __construct(
        private readonly BusinessCompletenessCalculator $completeness,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBusiness(User $owner, array $data): Business
    {
        $city = City::query()->where('slug', (string) $data['city_slug'])->where('is_active', true)->first();
        if ($city === null) {
            throw new DirectoryException('INVALID_CITY', 'Selected city is invalid or inactive.');
        }

        $locality = Locality::query()
            ->where('city_id', $city->id)
            ->where('slug', (string) $data['locality_slug'])
            ->where('is_active', true)
            ->first();
        if ($locality === null) {
            throw new DirectoryException('INVALID_LOCALITY', 'Selected locality does not belong to the chosen city.');
        }

        $primaryCategory = Category::query()
            ->where('slug', (string) $data['primary_category_slug'])
            ->where('is_active', true)
            ->first();
        if ($primaryCategory === null) {
            throw new DirectoryException('INVALID_CATEGORY', 'Selected primary category is invalid or inactive.');
        }

        $primaryPhone = PhoneNormalizer::normalizeAndValidate((string) $data['primary_phone_e164']);
        $whatsapp = ! empty($data['whatsapp_e164'])
            ? PhoneNormalizer::normalizeAndValidate((string) $data['whatsapp_e164'])
            : null;

        return DB::transaction(function () use ($owner, $data, $city, $locality, $primaryCategory, $primaryPhone, $whatsapp): Business {
            $slug = $this->generateUniqueCitySlug($city->id, (string) $data['name'], $locality->name);

            $business = new Business([
                'primary_category_id' => $primaryCategory->id,
                'city_id' => $city->id,
                'locality_id' => $locality->id,
                'name' => trim((string) $data['name']),
                'slug' => $slug,
                'tagline' => isset($data['tagline']) ? trim((string) $data['tagline']) : null,
                'description' => isset($data['description']) ? trim((string) $data['description']) : null,
                'search_keywords' => isset($data['search_keywords']) ? trim((string) $data['search_keywords']) : null,
                'year_established' => $data['year_established'] ?? null,
                'address_line_1' => trim((string) $data['address_line_1']),
                'address_line_2' => isset($data['address_line_2']) ? trim((string) $data['address_line_2']) : null,
                'landmark' => isset($data['landmark']) ? trim((string) $data['landmark']) : null,
                'pincode' => trim((string) $data['pincode']),
                'latitude' => (float) ($data['latitude'] ?? $locality->latitude),
                'longitude' => (float) ($data['longitude'] ?? $locality->longitude),
                'primary_phone_e164' => $primaryPhone,
                'secondary_phone_e164' => $data['secondary_phone_e164'] ?? null,
                'whatsapp_e164' => $whatsapp,
                'email' => $data['email'] ?? null,
                'website_url' => $data['website_url'] ?? null,
            ]);

            // System-enforced ownership and publication defaults (never mass-assigned).
            $business->owner_user_id = $owner->id;
            $business->status = BusinessStatus::PendingReview;
            $business->is_claimed = true;
            $business->is_verified = false;
            $business->is_trusted = false;
            $business->save();

            $this->syncCategories(
                $business,
                $primaryCategory->id,
                is_array($data['secondary_category_slugs'] ?? null) ? $data['secondary_category_slugs'] : []
            );

            if (is_array($data['amenity_slugs'] ?? null)) {
                $this->syncAmenities($business, $data['amenity_slugs']);
            }

            if (is_array($data['payment_method_slugs'] ?? null)) {
                $this->syncPaymentMethods($business, $data['payment_method_slugs']);
            }

            BusinessMember::query()->create([
                'business_id' => $business->id,
                'user_id' => $owner->id,
                'role' => BusinessMemberRole::Owner,
                'status' => 'active',
                'invited_by_user_id' => $owner->id,
                'accepted_at' => now(),
            ]);

            $ownerRole = Role::query()->firstOrCreate(
                ['name' => 'business_owner'],
                ['label' => 'Business Owner']
            );
            $owner->roles()->syncWithoutDetaching([$ownerRole->id]);

            $business->completeness_score = $this->completeness->calculate($business);
            $business->save();

            $this->auditLogger->record(
                actor: $owner,
                action: 'business.created',
                auditable: $business,
                newValues: [
                    'public_id' => $business->public_id,
                    'status' => $business->status->value,
                    'city_slug' => $city->slug,
                    'slug' => $business->slug,
                ],
                actorRole: 'business_owner',
            );

            return $business->load(['primaryCategory', 'categories', 'city', 'locality', 'amenities', 'paymentMethods', 'hours', 'services']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBusiness(User $actor, Business $business, array $data): Business
    {
        return DB::transaction(function () use ($actor, $business, $data): Business {
            $oldCitySlug = $business->city->slug;
            $oldSlug = $business->slug;
            $oldSnapshot = [
                'name' => $business->name,
                'slug' => $business->slug,
                'city_id' => $business->city_id,
                'locality_id' => $business->locality_id,
            ];

            if (isset($data['city_slug']) || isset($data['locality_slug'])) {
                $city = isset($data['city_slug'])
                    ? City::query()->where('slug', (string) $data['city_slug'])->where('is_active', true)->first()
                    : $business->city;

                if ($city === null) {
                    throw new DirectoryException('INVALID_CITY', 'Selected city is invalid or inactive.');
                }

                $localitySlug = (string) ($data['locality_slug'] ?? $business->locality->slug);
                $locality = Locality::query()
                    ->where('city_id', $city->id)
                    ->where('slug', $localitySlug)
                    ->where('is_active', true)
                    ->first();

                if ($locality === null) {
                    throw new DirectoryException('INVALID_LOCALITY', 'Selected locality does not belong to the chosen city.');
                }

                $business->city_id = $city->id;
                $business->locality_id = $locality->id;
            }

            if (isset($data['primary_category_slug'])) {
                $primary = Category::query()
                    ->where('slug', (string) $data['primary_category_slug'])
                    ->where('is_active', true)
                    ->first();
                if ($primary === null) {
                    throw new DirectoryException('INVALID_CATEGORY', 'Selected primary category is invalid or inactive.');
                }
                $business->primary_category_id = $primary->id;
            }

            if (isset($data['primary_phone_e164'])) {
                $business->primary_phone_e164 = PhoneNormalizer::normalizeAndValidate((string) $data['primary_phone_e164']);
            }
            if (array_key_exists('whatsapp_e164', $data)) {
                $business->whatsapp_e164 = $data['whatsapp_e164'] !== null && $data['whatsapp_e164'] !== ''
                    ? PhoneNormalizer::normalizeAndValidate((string) $data['whatsapp_e164'])
                    : null;
            }

            foreach ([
                'name', 'tagline', 'description', 'search_keywords', 'year_established',
                'address_line_1', 'address_line_2', 'landmark', 'pincode',
                'latitude', 'longitude', 'secondary_phone_e164', 'email', 'website_url',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $business->setAttribute($field, $data[$field]);
                }
            }

            if (isset($data['slug']) && (string) $data['slug'] !== $oldSlug) {
                $candidateSlug = Str::slug((string) $data['slug']);
                $exists = Business::query()
                    ->where('city_id', $business->city_id)
                    ->where('slug', $candidateSlug)
                    ->whereKeyNot($business->id)
                    ->exists();
                if ($exists) {
                    throw new DirectoryException('SLUG_ALREADY_TAKEN', 'That business URL slug is already in use in this city.', Response::HTTP_CONFLICT);
                }
                $business->slug = $candidateSlug;
            }

            $business->save();
            $business->load('city');

            // Preserve SEO equity via 301 redirect if a published listing changes city or slug.
            if ($business->status === BusinessStatus::Active && ($oldCitySlug !== $business->city->slug || $oldSlug !== $business->slug)) {
                UrlRedirect::query()->updateOrCreate(
                    ['source_path' => "/{$oldCitySlug}/business/{$oldSlug}"],
                    ['target_path' => "/{$business->city->slug}/business/{$business->slug}", 'http_status' => 301]
                );
            }

            if (isset($data['primary_category_slug']) || array_key_exists('secondary_category_slugs', $data)) {
                $this->syncCategories(
                    $business,
                    $business->primary_category_id,
                    is_array($data['secondary_category_slugs'] ?? null) ? $data['secondary_category_slugs'] : []
                );
            }

            if (array_key_exists('amenity_slugs', $data) && is_array($data['amenity_slugs'])) {
                $this->syncAmenities($business, $data['amenity_slugs']);
            }

            if (array_key_exists('payment_method_slugs', $data) && is_array($data['payment_method_slugs'])) {
                $this->syncPaymentMethods($business, $data['payment_method_slugs']);
            }

            $business->completeness_score = $this->completeness->calculate($business);
            $business->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'business.updated',
                auditable: $business,
                oldValues: $oldSnapshot,
                newValues: [
                    'name' => $business->name,
                    'slug' => $business->slug,
                    'city_id' => $business->city_id,
                    'locality_id' => $business->locality_id,
                ],
            );

            return $business->load(['primaryCategory', 'categories', 'city', 'locality', 'amenities', 'paymentMethods', 'hours', 'services']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     */
    public function replaceOpeningHours(User $actor, Business $business, array $slots): Business
    {
        return DB::transaction(function () use ($actor, $business, $slots): Business {
            BusinessHour::query()->where('business_id', $business->id)->delete();

            foreach ($slots as $slot) {
                $isClosed = (bool) ($slot['is_closed'] ?? false);
                $is24h = ! $isClosed && (bool) ($slot['is_24_hours'] ?? false);

                BusinessHour::query()->create([
                    'business_id' => $business->id,
                    'day_of_week' => (int) $slot['day_of_week'],
                    'slot_index' => (int) ($slot['slot_index'] ?? 1),
                    'open_time' => ($isClosed || $is24h) ? null : $this->normalizeTime((string) $slot['open_time']),
                    'close_time' => ($isClosed || $is24h) ? null : $this->normalizeTime((string) $slot['close_time']),
                    'is_24_hours' => $is24h,
                    'is_closed' => $isClosed,
                ]);
            }

            $business->completeness_score = $this->completeness->calculate($business);
            $business->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'business.hours_updated',
                auditable: $business,
                newValues: ['slots_count' => count($slots)],
            );

            return $business->load('hours');
        });
    }

    /**
     * @param  list<string>|null  $permissions
     */
    public function addStaffMember(
        User $owner,
        Business $business,
        string $memberUserPublicId,
        BusinessMemberRole $role,
        ?array $permissions = null,
    ): BusinessMember {
        if ($role === BusinessMemberRole::Owner) {
            throw new DirectoryException(
                'INVALID_STAFF_ROLE',
                'Cannot assign owner role through staff management.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $targetUser = User::findByPublicId($memberUserPublicId);
        if ($targetUser === null || $targetUser->status !== 'active') {
            throw new DirectoryException('USER_NOT_FOUND', 'Target user account was not found.', Response::HTTP_NOT_FOUND);
        }

        if ((int) $targetUser->id === (int) $business->owner_user_id) {
            throw new DirectoryException('ALREADY_OWNER', 'Business owner already has full access.', Response::HTTP_CONFLICT);
        }

        return DB::transaction(function () use ($owner, $business, $targetUser, $role, $permissions): BusinessMember {
            $member = BusinessMember::query()->updateOrCreate(
                ['business_id' => $business->id, 'user_id' => $targetUser->id],
                [
                    'role' => $role,
                    'permissions' => $permissions,
                    'status' => 'active',
                    'invited_by_user_id' => $owner->id,
                    'accepted_at' => now(),
                ]
            );

            $this->auditLogger->record(
                actor: $owner,
                action: 'business.staff_added',
                auditable: $business,
                newValues: [
                    'member_public_id' => $member->public_id,
                    'user_public_id' => $targetUser->public_id,
                    'role' => $role->value,
                ],
                actorRole: 'business_owner',
            );

            return $member->load('user');
        });
    }

    /**
     * @param  list<string>|null  $permissions
     */
    public function updateStaffMember(
        User $owner,
        Business $business,
        BusinessMember $member,
        BusinessMemberRole $role,
        ?array $permissions = null,
        string $status = 'active',
    ): BusinessMember {
        if ($role === BusinessMemberRole::Owner) {
            throw new DirectoryException('INVALID_STAFF_ROLE', 'Cannot promote staff to owner via staff update.');
        }

        $old = ['role' => $member->role->value, 'status' => $member->status];
        $member->role = $role;
        $member->permissions = $permissions;
        $member->status = $status;
        $member->save();

        $this->auditLogger->record(
            actor: $owner,
            action: 'business.staff_updated',
            auditable: $business,
            oldValues: $old,
            newValues: ['member_public_id' => $member->public_id, 'role' => $role->value, 'status' => $status],
            actorRole: 'business_owner',
        );

        return $member->load('user');
    }

    public function removeStaffMember(User $owner, Business $business, BusinessMember $member): void
    {
        $snapshot = ['member_public_id' => $member->public_id, 'role' => $member->role->value];
        $member->delete();

        $this->auditLogger->record(
            actor: $owner,
            action: 'business.staff_removed',
            auditable: $business,
            oldValues: $snapshot,
            actorRole: 'business_owner',
        );
    }

    /**
     * @param  list<string>  $secondarySlugs
     */
    private function syncCategories(Business $business, int $primaryCategoryId, array $secondarySlugs): void
    {
        $syncPayload = [$primaryCategoryId => ['is_primary' => true]];

        if ($secondarySlugs !== []) {
            $secondaryIds = Category::query()
                ->whereIn('slug', $secondarySlugs)
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            foreach ($secondaryIds as $catId) {
                if ((int) $catId !== $primaryCategoryId) {
                    $syncPayload[(int) $catId] = ['is_primary' => false];
                }
            }
        }

        $business->categories()->sync($syncPayload);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function syncAmenities(Business $business, array $slugs): void
    {
        $ids = DB::table('amenities')->whereIn('slug', $slugs)->where('is_active', true)->pluck('id')->all();
        $business->amenities()->sync($ids);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function syncPaymentMethods(Business $business, array $slugs): void
    {
        $ids = DB::table('payment_methods')->whereIn('slug', $slugs)->where('is_active', true)->pluck('id')->all();
        $business->paymentMethods()->sync($ids);
    }

    private function generateUniqueCitySlug(int $cityId, string $name, string $localityName): string
    {
        $base = Str::slug("{$name} {$localityName}");
        if ($base === '') {
            $base = 'business';
        }

        $candidate = $base;
        $suffix = 2;
        while (Business::query()->where('city_id', $cityId)->where('slug', $candidate)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? "{$time}:00" : $time;
    }
}
