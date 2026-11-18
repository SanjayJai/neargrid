<?php

declare(strict_types=1);

namespace App\Domains\Location\Models;

use Database\Factories\StateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'country_code',
    'name',
    'slug',
    'is_active',
])]
class State extends Model
{
    /** @use HasFactory<StateFactory> */
    use HasFactory;

    protected static function newFactory(): StateFactory
    {
        return StateFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }
}
