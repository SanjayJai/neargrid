<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Domains\Location\Services\LocationManagementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Location\StoreCityRequest;
use App\Http\Requests\Api\V1\Admin\Location\StoreLocalityRequest;
use App\Http\Requests\Api\V1\Admin\Location\StoreStateRequest;
use App\Http\Requests\Api\V1\Admin\Location\UpdateCityRequest;
use App\Http\Requests\Api\V1\Admin\Location\UpdateLocalityRequest;
use App\Http\Requests\Api\V1\Admin\Location\UpdateStateRequest;
use App\Http\Resources\Api\V1\Admin\AdminCityResource;
use App\Http\Resources\Api\V1\Admin\AdminLocalityResource;
use App\Http\Resources\Api\V1\Admin\AdminStateResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class AdminLocationController extends Controller
{
    public function __construct(
        private readonly LocationManagementService $locationService,
    ) {}

    public function indexStates(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', State::class);

        $query = State::query()
            ->with('translations')
            ->withCount('cities');

        if ($request->filled('country_code')) {
            $query->where('country_code', strtoupper(trim((string) $request->query('country_code'))));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(static function ($sq) use ($search): void {
                $sq->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhereHas('translations', static fn ($tq) => $tq->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $states = $query->orderBy('sort_order')->orderBy('name')->paginate($perPage);

        return ApiResponse::paginated(
            $states,
            AdminStateResource::collection($states)->resolve($request)
        );
    }

    public function storeState(StoreStateRequest $request): JsonResponse
    {
        Gate::authorize('create', State::class);

        /** @var User $actor */
        $actor = $request->user();
        $state = $this->locationService->createState($actor, $request->validated());
        $state->loadCount('cities');

        return ApiResponse::created(
            (new AdminStateResource($state, $this->locationService->stateDependencies($state)))->resolve($request)
        );
    }

    public function showState(Request $request, State $state): JsonResponse
    {
        Gate::authorize('viewAdmin', State::class);

        $state->load('translations')->loadCount('cities');

        return ApiResponse::success(
            (new AdminStateResource($state, $this->locationService->stateDependencies($state)))->resolve($request)
        );
    }

    public function updateState(UpdateStateRequest $request, State $state): JsonResponse
    {
        Gate::authorize('update', $state);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->locationService->updateState($actor, $state, $request->validated());
        $updated->loadCount('cities');

        return ApiResponse::success(
            (new AdminStateResource($updated, $this->locationService->stateDependencies($updated)))->resolve($request)
        );
    }

    public function destroyState(Request $request, State $state): JsonResponse|Response
    {
        Gate::authorize('delete', $state);

        /** @var User $actor */
        $actor = $request->user();

        if ($request->query('mode') === 'archive') {
            $archived = $this->locationService->archiveState($actor, $state);
            $archived->loadCount('cities');

            return ApiResponse::success(
                (new AdminStateResource($archived, $this->locationService->stateDependencies($archived)))->resolve($request)
            );
        }

        $this->locationService->deleteState($actor, $state);

        return ApiResponse::noContent();
    }

    public function archiveState(Request $request, State $state): JsonResponse
    {
        Gate::authorize('update', $state);

        /** @var User $actor */
        $actor = $request->user();
        $archived = $this->locationService->archiveState($actor, $state);
        $archived->loadCount('cities');

        return ApiResponse::success(
            (new AdminStateResource($archived, $this->locationService->stateDependencies($archived)))->resolve($request)
        );
    }

    public function indexCities(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', City::class);

        $query = City::query()
            ->with(['state', 'translations'])
            ->withCount(['localities', 'businesses']);

        if ($request->filled('state')) {
            $stateFilter = trim((string) $request->query('state'));
            $query->whereHas('state', static fn ($sq) => $sq->where('slug', $stateFilter)->orWhere('public_id', $stateFilter));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('is_popular')) {
            $query->where('is_popular', filter_var($request->query('is_popular'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(static function ($sq) use ($search): void {
                $sq->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhereHas('translations', static fn ($tq) => $tq->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $cities = $query->orderBy('sort_order')->orderBy('name')->paginate($perPage);

        return ApiResponse::paginated(
            $cities,
            AdminCityResource::collection($cities)->resolve($request)
        );
    }

    public function storeCity(StoreCityRequest $request): JsonResponse
    {
        Gate::authorize('create', City::class);

        /** @var User $actor */
        $actor = $request->user();
        $city = $this->locationService->createCity($actor, $request->validated());
        $city->loadCount(['localities', 'businesses']);

        return ApiResponse::created(
            (new AdminCityResource($city, $this->locationService->cityDependencies($city)))->resolve($request)
        );
    }

    public function showCity(Request $request, City $city): JsonResponse
    {
        Gate::authorize('viewAdmin', City::class);

        $city->load(['state', 'translations'])->loadCount(['localities', 'businesses']);

        return ApiResponse::success(
            (new AdminCityResource($city, $this->locationService->cityDependencies($city)))->resolve($request)
        );
    }

    public function updateCity(UpdateCityRequest $request, City $city): JsonResponse
    {
        Gate::authorize('update', $city);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->locationService->updateCity($actor, $city, $request->validated());
        $updated->loadCount(['localities', 'businesses']);

        return ApiResponse::success(
            (new AdminCityResource($updated, $this->locationService->cityDependencies($updated)))->resolve($request)
        );
    }

    public function destroyCity(Request $request, City $city): JsonResponse|Response
    {
        Gate::authorize('delete', $city);

        /** @var User $actor */
        $actor = $request->user();

        if ($request->query('mode') === 'archive') {
            $archived = $this->locationService->archiveCity($actor, $city);
            $archived->load(['state', 'translations'])->loadCount(['localities', 'businesses']);

            return ApiResponse::success(
                (new AdminCityResource($archived, $this->locationService->cityDependencies($archived)))->resolve($request)
            );
        }

        $this->locationService->deleteCity($actor, $city);

        return ApiResponse::noContent();
    }

    public function archiveCity(Request $request, City $city): JsonResponse
    {
        Gate::authorize('update', $city);

        /** @var User $actor */
        $actor = $request->user();
        $archived = $this->locationService->archiveCity($actor, $city);
        $archived->load(['state', 'translations'])->loadCount(['localities', 'businesses']);

        return ApiResponse::success(
            (new AdminCityResource($archived, $this->locationService->cityDependencies($archived)))->resolve($request)
        );
    }

    public function indexLocalities(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', Locality::class);

        $query = Locality::query()
            ->with(['city.state', 'translations'])
            ->withCount('businesses');

        if ($request->filled('city')) {
            $cityFilter = trim((string) $request->query('city'));
            $query->whereHas('city', static fn ($cq) => $cq->where('slug', $cityFilter)->orWhere('public_id', $cityFilter));
        }

        if ($request->filled('state')) {
            $stateFilter = trim((string) $request->query('state'));
            $query->whereHas('city.state', static fn ($sq) => $sq->where('slug', $stateFilter)->orWhere('public_id', $stateFilter));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(static function ($sq) use ($search): void {
                $sq->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('pincode', 'like', "%{$search}%")
                    ->orWhereHas('translations', static fn ($tq) => $tq->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $localities = $query->orderBy('sort_order')->orderBy('name')->paginate($perPage);

        return ApiResponse::paginated(
            $localities,
            AdminLocalityResource::collection($localities)->resolve($request)
        );
    }

    public function storeLocality(StoreLocalityRequest $request): JsonResponse
    {
        Gate::authorize('create', Locality::class);

        /** @var User $actor */
        $actor = $request->user();
        $locality = $this->locationService->createLocality($actor, $request->validated());
        $locality->loadCount('businesses');

        return ApiResponse::created(
            (new AdminLocalityResource($locality, $this->locationService->localityDependencies($locality)))->resolve($request)
        );
    }

    public function showLocality(Request $request, Locality $locality): JsonResponse
    {
        Gate::authorize('viewAdmin', Locality::class);

        $locality->load(['city.state', 'translations'])->loadCount('businesses');

        return ApiResponse::success(
            (new AdminLocalityResource($locality, $this->locationService->localityDependencies($locality)))->resolve($request)
        );
    }

    public function updateLocality(UpdateLocalityRequest $request, Locality $locality): JsonResponse
    {
        Gate::authorize('update', $locality);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->locationService->updateLocality($actor, $locality, $request->validated());
        $updated->loadCount('businesses');

        return ApiResponse::success(
            (new AdminLocalityResource($updated, $this->locationService->localityDependencies($updated)))->resolve($request)
        );
    }

    public function destroyLocality(Request $request, Locality $locality): JsonResponse|Response
    {
        Gate::authorize('delete', $locality);

        /** @var User $actor */
        $actor = $request->user();

        if ($request->query('mode') === 'archive') {
            $archived = $this->locationService->archiveLocality($actor, $locality);
            $archived->load(['city.state', 'translations'])->loadCount('businesses');

            return ApiResponse::success(
                (new AdminLocalityResource($archived, $this->locationService->localityDependencies($archived)))->resolve($request)
            );
        }

        $this->locationService->deleteLocality($actor, $locality);

        return ApiResponse::noContent();
    }

    public function archiveLocality(Request $request, Locality $locality): JsonResponse
    {
        Gate::authorize('update', $locality);

        /** @var User $actor */
        $actor = $request->user();
        $archived = $this->locationService->archiveLocality($actor, $locality);
        $archived->load(['city.state', 'translations'])->loadCount('businesses');

        return ApiResponse::success(
            (new AdminLocalityResource($archived, $this->locationService->localityDependencies($archived)))->resolve($request)
        );
    }
}
