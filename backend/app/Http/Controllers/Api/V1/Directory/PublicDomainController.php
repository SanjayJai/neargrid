<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Domains\Domains\Services\DomainResolutionService;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PublicDomainController extends Controller
{
    public function __construct(
        private readonly DomainResolutionService $resolutionService,
    ) {}

    public function resolve(Request $request): JsonResponse
    {
        $rawHost = $request->query('host');
        $domain = is_string($rawHost)
            ? $this->resolutionService->resolveActiveDomain($rawHost)
            : null;

        if ($domain === null) {
            return ApiResponse::error(
                'DOMAIN_NOT_FOUND',
                'Domain is not registered or not eligible for public resolution.',
                Response::HTTP_NOT_FOUND,
            )->header('Cache-Control', 'no-store');
        }

        $canonicalDomain = $domain->canonicalDomain;
        $canonicalOrigin = ($canonicalDomain !== null && $canonicalDomain->status === 'active' && $canonicalDomain->verification_status === 'verified')
            ? $canonicalDomain->origin()
            : $domain->origin();

        return ApiResponse::success([
            'public_id' => $domain->public_id,
            'hostname' => $domain->hostname,
            'domain_type' => $domain->domain_type,
            'is_primary' => $domain->is_primary,
            'visibility_mode' => $domain->visibility_mode,
            'default_locale' => $domain->default_locale,
            'default_city' => $domain->defaultCity ? [
                'public_id' => $domain->defaultCity->public_id,
                'slug' => $domain->defaultCity->slug,
                'name' => $domain->defaultCity->name,
            ] : null,
            'default_state' => $domain->defaultState ? [
                'public_id' => $domain->defaultState->public_id,
                'slug' => $domain->defaultState->slug,
                'name' => $domain->defaultState->name,
            ] : null,
            'canonical_origin' => $canonicalOrigin,
            'is_canonical_for_location' => $domain->is_canonical_for_location,
            'is_indexable' => $domain->isEffectivelyIndexable(),
        ]);
    }
}
