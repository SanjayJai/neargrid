<?php

declare(strict_types=1);

namespace App\Domains\Location\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Governance\Models\UrlRedirect;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Location\Exceptions\LocationException;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\CityTranslation;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\LocalityTranslation;
use App\Domains\Location\Models\State;
use App\Domains\Location\Models\StateTranslation;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class LocationManagementService
{
    public const SUPPORTED_LOCALES = ['en', 'ta'];

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array<string, int>
     */
    public function stateDependencies(State $state): array
    {
        return [
            'cities_count' => $state->cities()->count(),
            'localities_count' => Locality::query()
                ->whereIn('city_id', $state->cities()->select('id'))
                ->count(),
            'businesses_count' => $state->businesses()->withTrashed()->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function cityDependencies(City $city): array
    {
        $deps = [
            'localities_count' => $city->localities()->count(),
            'businesses_count' => $city->businesses()->withTrashed()->count(),
            'enquiries_count' => $city->enquiries()->withTrashed()->count(),
        ];

        if (Schema::hasTable('branches')) {
            $deps['branches_count'] = DB::table('branches')->where('city_id', $city->id)->count();
        }

        return $deps;
    }

    /**
     * @return array<string, int>
     */
    public function localityDependencies(Locality $locality): array
    {
        return [
            'businesses_count' => $locality->businesses()->withTrashed()->count(),
            'enquiries_count' => $locality->enquiries()->withTrashed()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createState(User $actor, array $data): State
    {
        $countryCode = strtoupper(trim((string) ($data['country_code'] ?? 'IN')));
        $name = trim((string) $data['name']);
        $slug = $this->normalizeSlug((string) ($data['slug'] ?? $name));
        $translations = $this->prepareTranslations($data['translations'] ?? null, $name);

        return DB::transaction(function () use ($actor, $countryCode, $name, $slug, $data, $translations): State {
            if (State::query()->where('country_code', $countryCode)->where('slug', $slug)->exists()) {
                throw new LocationException(
                    'DUPLICATE_STATE_SLUG',
                    "A state with slug '{$slug}' already exists in country '{$countryCode}'.",
                    Response::HTTP_CONFLICT
                );
            }

            if (State::query()->where('country_code', $countryCode)->where('name', $name)->exists()) {
                throw new LocationException(
                    'DUPLICATE_STATE_NAME',
                    "A state named '{$name}' already exists in country '{$countryCode}'.",
                    Response::HTTP_CONFLICT
                );
            }

            $state = State::query()->create([
                'country_code' => $countryCode,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->syncStateTranslations($state, $translations);

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.state.created',
                auditable: $state,
                oldValues: null,
                newValues: [
                    'public_id' => $state->public_id,
                    'country_code' => $state->country_code,
                    'name' => $state->name,
                    'slug' => $state->slug,
                    'is_active' => $state->is_active,
                ],
                actorRole: 'super_admin'
            );

            return $state->load('translations');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateState(User $actor, State $state, array $data): State
    {
        $translations = array_key_exists('translations', $data)
            ? $this->prepareTranslations($data['translations'], null)
            : null;

        return DB::transaction(function () use ($actor, $state, $data, $translations): State {
            $oldValues = [
                'country_code' => $state->country_code,
                'name' => $state->name,
                'slug' => $state->slug,
                'sort_order' => $state->sort_order,
                'is_active' => $state->is_active,
            ];

            $countryCode = isset($data['country_code'])
                ? strtoupper(trim((string) $data['country_code']))
                : $state->country_code;
            $name = isset($data['name']) ? trim((string) $data['name']) : $state->name;
            $slug = isset($data['slug'])
                ? $this->normalizeSlug((string) $data['slug'])
                : $state->slug;

            if (
                State::query()
                    ->where('country_code', $countryCode)
                    ->where('slug', $slug)
                    ->whereKeyNot($state->id)
                    ->exists()
            ) {
                throw new LocationException(
                    'DUPLICATE_STATE_SLUG',
                    "A state with slug '{$slug}' already exists in country '{$countryCode}'.",
                    Response::HTTP_CONFLICT
                );
            }

            if (
                State::query()
                    ->where('country_code', $countryCode)
                    ->where('name', $name)
                    ->whereKeyNot($state->id)
                    ->exists()
            ) {
                throw new LocationException(
                    'DUPLICATE_STATE_NAME',
                    "A state named '{$name}' already exists in country '{$countryCode}'.",
                    Response::HTTP_CONFLICT
                );
            }

            $state->fill([
                'country_code' => $countryCode,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : $state->sort_order,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $state->is_active,
            ]);
            $state->save();

            if ($translations !== null) {
                $this->syncStateTranslations($state, $translations);
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.state.updated',
                auditable: $state,
                oldValues: $oldValues,
                newValues: [
                    'country_code' => $state->country_code,
                    'name' => $state->name,
                    'slug' => $state->slug,
                    'sort_order' => $state->sort_order,
                    'is_active' => $state->is_active,
                ],
                actorRole: 'super_admin'
            );

            return $state->load('translations');
        });
    }

    public function archiveState(User $actor, State $state): State
    {
        return DB::transaction(function () use ($actor, $state): State {
            $oldActive = $state->is_active;
            $state->is_active = false;
            $state->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.state.archived',
                auditable: $state,
                oldValues: ['is_active' => $oldActive],
                newValues: ['is_active' => false],
                actorRole: 'super_admin'
            );

            return $state->load('translations');
        });
    }

    public function deleteState(User $actor, State $state): void
    {
        $deps = $this->stateDependencies($state);
        if ($deps['cities_count'] > 0) {
            throw new LocationException(
                'LOCATION_HAS_DEPENDENCIES',
                'Cannot delete a state that has dependent cities. Archive the state instead.',
                Response::HTTP_CONFLICT,
                $deps
            );
        }

        DB::transaction(function () use ($actor, $state): void {
            $snapshot = [
                'public_id' => $state->public_id,
                'name' => $state->name,
                'slug' => $state->slug,
            ];

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.state.deleted',
                auditable: $state,
                oldValues: $snapshot,
                newValues: null,
                actorRole: 'super_admin'
            );

            $state->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCity(User $actor, array $data): City
    {
        $state = $this->resolveState($data);
        $name = trim((string) $data['name']);
        $slug = $this->normalizeSlug((string) ($data['slug'] ?? $name));
        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];
        $this->assertValidCoordinates($lat, $lng);

        $translations = $this->prepareTranslations($data['translations'] ?? null, $name);

        return DB::transaction(function () use ($actor, $state, $name, $slug, $lat, $lng, $data, $translations): City {
            if (City::query()->where('slug', $slug)->exists()) {
                throw new LocationException(
                    'DUPLICATE_CITY_SLUG',
                    "A city with slug '{$slug}' already exists.",
                    Response::HTTP_CONFLICT
                );
            }

            if (City::query()->where('state_id', $state->id)->where('name', $name)->exists()) {
                throw new LocationException(
                    'DUPLICATE_CITY_NAME',
                    "A city named '{$name}' already exists in state '{$state->name}'.",
                    Response::HTTP_CONFLICT
                );
            }

            $city = City::query()->create([
                'state_id' => $state->id,
                'name' => $name,
                'slug' => $slug,
                'latitude' => $lat,
                'longitude' => $lng,
                'timezone' => (string) ($data['timezone'] ?? 'Asia/Kolkata'),
                'is_popular' => (bool) ($data['is_popular'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'seo_title' => isset($data['seo_title']) ? (string) $data['seo_title'] : null,
                'seo_description' => isset($data['seo_description']) ? (string) $data['seo_description'] : null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->syncCityTranslations($city, $translations);

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.city.created',
                auditable: $city,
                oldValues: null,
                newValues: [
                    'public_id' => $city->public_id,
                    'state_public_id' => $state->public_id,
                    'name' => $city->name,
                    'slug' => $city->slug,
                    'latitude' => $lat,
                    'longitude' => $lng,
                ],
                actorRole: 'super_admin'
            );

            return $city->load(['state', 'translations']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCity(User $actor, City $city, array $data): City
    {
        $translations = array_key_exists('translations', $data)
            ? $this->prepareTranslations($data['translations'], null)
            : null;

        return DB::transaction(function () use ($actor, $city, $data, $translations): City {
            $oldSlug = $city->slug;
            $oldValues = [
                'state_id' => $city->state_id,
                'name' => $city->name,
                'slug' => $oldSlug,
                'latitude' => $city->latitude,
                'longitude' => $city->longitude,
                'is_popular' => $city->is_popular,
                'sort_order' => $city->sort_order,
                'is_active' => $city->is_active,
            ];

            $targetStateId = $city->state_id;
            if (isset($data['state_public_id']) || isset($data['state_slug'])) {
                $targetState = $this->resolveState($data);
                if ($targetState->id !== $city->state_id) {
                    $deps = $this->cityDependencies($city);
                    if ($deps['localities_count'] > 0 || $deps['businesses_count'] > 0 || $deps['enquiries_count'] > 0) {
                        throw new LocationException(
                            'LOCATION_MOVE_REQUIRES_MIGRATION',
                            'Cannot move a city with dependent localities, businesses, or enquiries to another state without an explicit migration plan.',
                            Response::HTTP_UNPROCESSABLE_ENTITY,
                            $deps
                        );
                    }
                    $targetStateId = $targetState->id;
                }
            }

            $name = isset($data['name']) ? trim((string) $data['name']) : $city->name;
            $slug = isset($data['slug']) ? $this->normalizeSlug((string) $data['slug']) : $city->slug;
            $lat = array_key_exists('latitude', $data) ? (float) $data['latitude'] : (float) $city->latitude;
            $lng = array_key_exists('longitude', $data) ? (float) $data['longitude'] : (float) $city->longitude;
            $this->assertValidCoordinates($lat, $lng);

            if (City::query()->where('slug', $slug)->whereKeyNot($city->id)->exists()) {
                throw new LocationException(
                    'DUPLICATE_CITY_SLUG',
                    "A city with slug '{$slug}' already exists.",
                    Response::HTTP_CONFLICT
                );
            }

            if (City::query()->where('state_id', $targetStateId)->where('name', $name)->whereKeyNot($city->id)->exists()) {
                throw new LocationException(
                    'DUPLICATE_CITY_NAME',
                    "A city named '{$name}' already exists in the selected state.",
                    Response::HTTP_CONFLICT
                );
            }

            $city->fill([
                'state_id' => $targetStateId,
                'name' => $name,
                'slug' => $slug,
                'latitude' => $lat,
                'longitude' => $lng,
                'timezone' => array_key_exists('timezone', $data) ? (string) $data['timezone'] : $city->timezone,
                'is_popular' => array_key_exists('is_popular', $data) ? (bool) $data['is_popular'] : $city->is_popular,
                'sort_order' => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : $city->sort_order,
                'seo_title' => array_key_exists('seo_title', $data) ? $data['seo_title'] : $city->seo_title,
                'seo_description' => array_key_exists('seo_description', $data) ? $data['seo_description'] : $city->seo_description,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $city->is_active,
            ]);
            $city->save();

            if ($oldSlug !== $city->slug) {
                $this->recordCitySlugRedirects($city, $oldSlug, $city->slug);
            }

            if ($translations !== null) {
                $this->syncCityTranslations($city, $translations);
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.city.updated',
                auditable: $city,
                oldValues: $oldValues,
                newValues: [
                    'state_id' => $city->state_id,
                    'name' => $city->name,
                    'slug' => $city->slug,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'is_popular' => $city->is_popular,
                    'sort_order' => $city->sort_order,
                    'is_active' => $city->is_active,
                ],
                actorRole: 'super_admin'
            );

            return $city->load(['state', 'translations']);
        });
    }

    public function archiveCity(User $actor, City $city): City
    {
        return DB::transaction(function () use ($actor, $city): City {
            $oldActive = $city->is_active;
            $city->is_active = false;
            $city->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.city.archived',
                auditable: $city,
                oldValues: ['is_active' => $oldActive],
                newValues: ['is_active' => false],
                actorRole: 'super_admin'
            );

            return $city->load(['state', 'translations']);
        });
    }

    public function deleteCity(User $actor, City $city): void
    {
        $deps = $this->cityDependencies($city);
        $hasDeps = $deps['localities_count'] > 0
            || $deps['businesses_count'] > 0
            || $deps['enquiries_count'] > 0
            || (($deps['branches_count'] ?? 0) > 0);

        if ($hasDeps) {
            throw new LocationException(
                'LOCATION_HAS_DEPENDENCIES',
                'Cannot delete a city that has dependent localities, businesses, or enquiries. Archive the city instead.',
                Response::HTTP_CONFLICT,
                $deps
            );
        }

        DB::transaction(function () use ($actor, $city): void {
            $snapshot = [
                'public_id' => $city->public_id,
                'name' => $city->name,
                'slug' => $city->slug,
            ];

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.city.deleted',
                auditable: $city,
                oldValues: $snapshot,
                newValues: null,
                actorRole: 'super_admin'
            );

            $city->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createLocality(User $actor, array $data): Locality
    {
        $city = $this->resolveCity($data);
        $name = trim((string) $data['name']);
        $slug = $this->normalizeSlug((string) ($data['slug'] ?? $name));
        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];
        $this->assertValidCoordinates($lat, $lng);

        $translations = $this->prepareTranslations($data['translations'] ?? null, $name);

        return DB::transaction(function () use ($actor, $city, $name, $slug, $lat, $lng, $data, $translations): Locality {
            if (Locality::query()->where('city_id', $city->id)->where('slug', $slug)->exists()) {
                throw new LocationException(
                    'DUPLICATE_LOCALITY_SLUG',
                    "A locality with slug '{$slug}' already exists in city '{$city->name}'.",
                    Response::HTTP_CONFLICT
                );
            }

            if (Locality::query()->where('city_id', $city->id)->where('name', $name)->exists()) {
                throw new LocationException(
                    'DUPLICATE_LOCALITY_NAME',
                    "A locality named '{$name}' already exists in city '{$city->name}'.",
                    Response::HTTP_CONFLICT
                );
            }

            $locality = Locality::query()->create([
                'city_id' => $city->id,
                'name' => $name,
                'slug' => $slug,
                'pincode' => isset($data['pincode']) ? (string) $data['pincode'] : null,
                'latitude' => $lat,
                'longitude' => $lng,
                'is_popular' => (bool) ($data['is_popular'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->syncLocalityTranslations($locality, $translations);

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.locality.created',
                auditable: $locality,
                oldValues: null,
                newValues: [
                    'public_id' => $locality->public_id,
                    'city_public_id' => $city->public_id,
                    'name' => $locality->name,
                    'slug' => $locality->slug,
                    'latitude' => $lat,
                    'longitude' => $lng,
                ],
                actorRole: 'super_admin'
            );

            return $locality->load(['city.state', 'translations']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLocality(User $actor, Locality $locality, array $data): Locality
    {
        $translations = array_key_exists('translations', $data)
            ? $this->prepareTranslations($data['translations'], null)
            : null;

        return DB::transaction(function () use ($actor, $locality, $data, $translations): Locality {
            $locality->loadMissing('city');
            $oldSlug = $locality->slug;
            $oldValues = [
                'city_id' => $locality->city_id,
                'name' => $locality->name,
                'slug' => $oldSlug,
                'pincode' => $locality->pincode,
                'latitude' => $locality->latitude,
                'longitude' => $locality->longitude,
                'is_popular' => $locality->is_popular,
                'sort_order' => $locality->sort_order,
                'is_active' => $locality->is_active,
            ];

            $targetCityId = $locality->city_id;
            $targetCitySlug = $locality->city->slug;
            if (isset($data['city_public_id']) || isset($data['city_slug'])) {
                $targetCity = $this->resolveCity($data);
                if ($targetCity->id !== $locality->city_id) {
                    $deps = $this->localityDependencies($locality);
                    if ($deps['businesses_count'] > 0 || $deps['enquiries_count'] > 0) {
                        throw new LocationException(
                            'LOCATION_MOVE_REQUIRES_MIGRATION',
                            'Cannot move a locality with dependent businesses or enquiries to another city without an explicit migration plan.',
                            Response::HTTP_UNPROCESSABLE_ENTITY,
                            $deps
                        );
                    }
                    $targetCityId = $targetCity->id;
                    $targetCitySlug = $targetCity->slug;
                }
            }

            $name = isset($data['name']) ? trim((string) $data['name']) : $locality->name;
            $slug = isset($data['slug']) ? $this->normalizeSlug((string) $data['slug']) : $locality->slug;
            $lat = array_key_exists('latitude', $data) ? (float) $data['latitude'] : (float) $locality->latitude;
            $lng = array_key_exists('longitude', $data) ? (float) $data['longitude'] : (float) $locality->longitude;
            $this->assertValidCoordinates($lat, $lng);

            if (Locality::query()->where('city_id', $targetCityId)->where('slug', $slug)->whereKeyNot($locality->id)->exists()) {
                throw new LocationException(
                    'DUPLICATE_LOCALITY_SLUG',
                    "A locality with slug '{$slug}' already exists in the selected city.",
                    Response::HTTP_CONFLICT
                );
            }

            if (Locality::query()->where('city_id', $targetCityId)->where('name', $name)->whereKeyNot($locality->id)->exists()) {
                throw new LocationException(
                    'DUPLICATE_LOCALITY_NAME',
                    "A locality named '{$name}' already exists in the selected city.",
                    Response::HTTP_CONFLICT
                );
            }

            $locality->fill([
                'city_id' => $targetCityId,
                'name' => $name,
                'slug' => $slug,
                'pincode' => array_key_exists('pincode', $data) ? $data['pincode'] : $locality->pincode,
                'latitude' => $lat,
                'longitude' => $lng,
                'is_popular' => array_key_exists('is_popular', $data) ? (bool) $data['is_popular'] : $locality->is_popular,
                'sort_order' => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : $locality->sort_order,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $locality->is_active,
            ]);
            $locality->save();

            if ($oldSlug !== $locality->slug) {
                UrlRedirect::query()->updateOrCreate(
                    ['source_path' => "/{$targetCitySlug}/{$oldSlug}"],
                    ['target_path' => "/{$targetCitySlug}/{$locality->slug}", 'http_status' => 301]
                );
            }

            if ($translations !== null) {
                $this->syncLocalityTranslations($locality, $translations);
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.locality.updated',
                auditable: $locality,
                oldValues: $oldValues,
                newValues: [
                    'city_id' => $locality->city_id,
                    'name' => $locality->name,
                    'slug' => $locality->slug,
                    'pincode' => $locality->pincode,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'is_popular' => $locality->is_popular,
                    'sort_order' => $locality->sort_order,
                    'is_active' => $locality->is_active,
                ],
                actorRole: 'super_admin'
            );

            return $locality->load(['city.state', 'translations']);
        });
    }

    public function archiveLocality(User $actor, Locality $locality): Locality
    {
        return DB::transaction(function () use ($actor, $locality): Locality {
            $oldActive = $locality->is_active;
            $locality->is_active = false;
            $locality->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.locality.archived',
                auditable: $locality,
                oldValues: ['is_active' => $oldActive],
                newValues: ['is_active' => false],
                actorRole: 'super_admin'
            );

            return $locality->load(['city.state', 'translations']);
        });
    }

    public function deleteLocality(User $actor, Locality $locality): void
    {
        $deps = $this->localityDependencies($locality);
        if ($deps['businesses_count'] > 0 || $deps['enquiries_count'] > 0) {
            throw new LocationException(
                'LOCATION_HAS_DEPENDENCIES',
                'Cannot delete a locality that has dependent businesses or enquiries. Archive the locality instead.',
                Response::HTTP_CONFLICT,
                $deps
            );
        }

        DB::transaction(function () use ($actor, $locality): void {
            $snapshot = [
                'public_id' => $locality->public_id,
                'name' => $locality->name,
                'slug' => $locality->slug,
            ];

            $this->auditLogger->record(
                actor: $actor,
                action: 'location.locality.deleted',
                auditable: $locality,
                oldValues: $snapshot,
                newValues: null,
                actorRole: 'super_admin'
            );

            $locality->delete();
        });
    }

    private function normalizeSlug(string $raw): string
    {
        $slug = Str::slug(trim($raw));
        if ($slug === '') {
            throw new LocationException(
                'INVALID_SLUG',
                'A valid alphanumeric slug is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $slug;
    }

    private function assertValidCoordinates(float $lat, float $lng): void
    {
        if (! is_finite($lat) || ! is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            throw new LocationException(
                'INVALID_COORDINATES',
                'Latitude must be between -90 and 90 and longitude between -180 and 180.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveState(array $data): State
    {
        $query = State::query();

        if (! empty($data['state_public_id'])) {
            $state = $query->where('public_id', (string) $data['state_public_id'])->first();
        } elseif (! empty($data['state_slug'])) {
            $state = $query->where('slug', (string) $data['state_slug'])->first();
        } else {
            throw new LocationException(
                'INVALID_STATE',
                'A valid state reference (state_public_id or state_slug) is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($state === null) {
            throw new LocationException(
                'STATE_NOT_FOUND',
                'The specified parent state was not found.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCity(array $data): City
    {
        $query = City::query();

        if (! empty($data['city_public_id'])) {
            $city = $query->where('public_id', (string) $data['city_public_id'])->first();
        } elseif (! empty($data['city_slug'])) {
            $city = $query->where('slug', (string) $data['city_slug'])->first();
        } else {
            throw new LocationException(
                'INVALID_CITY',
                'A valid city reference (city_public_id or city_slug) is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($city === null) {
            throw new LocationException(
                'CITY_NOT_FOUND',
                'The specified parent city was not found.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $city;
    }

    /**
     * Validate translation array for unsupported or duplicate locales.
     *
     * @return list<array{locale: string, name: string, seo_title?: ?string, seo_description?: ?string}>
     */
    private function prepareTranslations(mixed $rawTranslations, ?string $defaultEnName): array
    {
        if (! is_array($rawTranslations)) {
            return $defaultEnName !== null
                ? [['locale' => 'en', 'name' => $defaultEnName]]
                : [];
        }

        $seen = [];
        $prepared = [];

        foreach ($rawTranslations as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $locale = strtolower(trim((string) ($entry['locale'] ?? '')));
            if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
                throw new LocationException(
                    'INVALID_LOCALE',
                    "Unsupported translation locale '{$locale}'. Supported locales: en, ta.",
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            if (isset($seen[$locale])) {
                throw new LocationException(
                    'DUPLICATE_LOCALE',
                    "Duplicate translation provided for locale '{$locale}'.",
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $seen[$locale] = true;
            $prepared[] = [
                'locale' => $locale,
                'name' => trim((string) ($entry['name'] ?? '')),
                'seo_title' => isset($entry['seo_title']) ? (string) $entry['seo_title'] : null,
                'seo_description' => isset($entry['seo_description']) ? (string) $entry['seo_description'] : null,
            ];
        }

        if ($defaultEnName !== null && ! isset($seen['en'])) {
            $prepared[] = ['locale' => 'en', 'name' => $defaultEnName];
        }

        return $prepared;
    }

    /**
     * @param  list<array{locale: string, name: string}>  $translations
     */
    private function syncStateTranslations(State $state, array $translations): void
    {
        foreach ($translations as $item) {
            StateTranslation::query()->updateOrCreate(
                ['state_id' => $state->id, 'locale' => $item['locale']],
                ['name' => $item['name']]
            );
        }
    }

    /**
     * @param  list<array{locale: string, name: string, seo_title?: ?string, seo_description?: ?string}>  $translations
     */
    private function syncCityTranslations(City $city, array $translations): void
    {
        foreach ($translations as $item) {
            CityTranslation::query()->updateOrCreate(
                ['city_id' => $city->id, 'locale' => $item['locale']],
                [
                    'name' => $item['name'],
                    'seo_title' => $item['seo_title'] ?? null,
                    'seo_description' => $item['seo_description'] ?? null,
                ]
            );
        }
    }

    /**
     * @param  list<array{locale: string, name: string}>  $translations
     */
    private function syncLocalityTranslations(Locality $locality, array $translations): void
    {
        foreach ($translations as $item) {
            LocalityTranslation::query()->updateOrCreate(
                ['locality_id' => $locality->id, 'locale' => $item['locale']],
                ['name' => $item['name']]
            );
        }
    }

    private function recordCitySlugRedirects(City $city, string $oldSlug, string $newSlug): void
    {
        UrlRedirect::query()->updateOrCreate(
            ['source_path' => "/{$oldSlug}"],
            ['target_path' => "/{$newSlug}", 'http_status' => 301]
        );

        Business::query()
            ->where('city_id', $city->id)
            ->published()
            ->select(['id', 'slug'])
            ->chunkById(200, function ($businesses) use ($oldSlug, $newSlug): void {
                foreach ($businesses as $business) {
                    UrlRedirect::query()->updateOrCreate(
                        ['source_path' => "/{$oldSlug}/business/{$business->slug}"],
                        ['target_path' => "/{$newSlug}/business/{$business->slug}", 'http_status' => 301]
                    );
                }
            });
    }
}
