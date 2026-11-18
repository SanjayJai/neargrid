<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Search;

final class NearbySearchRequest extends SearchBusinessesRequest
{
    /**
     * Nearby search strictly requires valid WGS-84 latitude and longitude coordinates.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['lat'] = ['required', 'numeric', 'between:-90,90'];
        $rules['lng'] = ['required', 'numeric', 'between:-180,180'];

        return $rules;
    }
}
