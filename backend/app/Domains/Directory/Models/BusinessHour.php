<?php

declare(strict_types=1);

namespace App\Domains\Directory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'day_of_week',
    'slot_index',
    'open_time',
    'close_time',
    'is_24_hours',
    'is_closed',
])]
#[Hidden(['id', 'business_id'])]
class BusinessHour extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'slot_index' => 'integer',
            'is_24_hours' => 'boolean',
            'is_closed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
