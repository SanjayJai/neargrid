<?php

declare(strict_types=1);

namespace App\Domains\Location\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'city_id',
    'locale',
    'name',
    'seo_title',
    'seo_description',
])]
class CityTranslation extends Model
{
    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
