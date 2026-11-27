<?php

declare(strict_types=1);

namespace App\Domains\Promotions\Models;

use App\Domains\Directory\Models\Business;
use App\Domains\Domains\Models\Domain;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Domains\Taxonomy\Models\Category;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'promo_notice_id',
    'target_type',
    'category_id',
    'category_depth',
    'include_descendants',
    'state_id',
    'city_id',
    'locality_id',
    'domain_id',
    'business_id',
    'target_value',
    'is_exclude',
])]
#[Hidden(['id', 'promo_notice_id'])]
class PromoNoticeTargetingRule extends Model
{
    use HasPublicUlid;

    public const VALID_TARGET_TYPES = [
        'category',
        'state',
        'city',
        'locality',
        'domain',
        'device',
        'audience',
        'route_pattern',
        'business',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $rule): void {
            if (! in_array($rule->target_type, self::VALID_TARGET_TYPES, true)) {
                throw new InvalidArgumentException("Invalid promo targeting type: {$rule->target_type}");
            }

            if ($rule->target_type === 'category') {
                if ($rule->category_id === null || $rule->state_id !== null || $rule->city_id !== null || $rule->locality_id !== null || $rule->domain_id !== null || $rule->business_id !== null) {
                    throw new InvalidArgumentException('Category targeting requires category_id and forbids geo/domain/business FKs.');
                }

                $category = Category::query()->find($rule->category_id);
                if ($category === null) {
                    throw new InvalidArgumentException('Referenced category does not exist.');
                }
                $depth = (int) $category->depth;
                if ($depth < 0 || $depth > 4) {
                    throw new InvalidArgumentException("Category depth {$depth} must be between 0 and 4.");
                }
                if ($rule->category_depth !== null && (int) $rule->category_depth !== $depth) {
                    throw new InvalidArgumentException('category_depth must match the referenced category depth.');
                }
                $rule->category_depth = $depth;

                return;
            }

            if ($rule->category_id !== null || $rule->category_depth !== null) {
                throw new InvalidArgumentException('category_id and category_depth are only allowed for category targeting.');
            }

            match ($rule->target_type) {
                'state' => self::assertStateRule($rule),
                'city' => self::assertCityRule($rule),
                'locality' => self::assertLocalityRule($rule),
                'domain' => self::assertDomainRule($rule),
                'business' => self::assertBusinessRule($rule),
                'device' => self::assertEnumValue($rule->target_value, ['desktop', 'mobile', 'tablet'], 'device'),
                'audience' => self::assertEnumValue($rule->target_value, ['guest', 'customer', 'merchant'], 'audience'),
                'route_pattern' => self::assertRoutePattern($rule->target_value),
            };
        });
    }

    private static function assertStateRule(self $rule): void
    {
        if ($rule->state_id === null || $rule->city_id !== null || $rule->locality_id !== null || $rule->domain_id !== null || $rule->business_id !== null) {
            throw new InvalidArgumentException('State targeting requires state_id only.');
        }
    }

    private static function assertCityRule(self $rule): void
    {
        if ($rule->city_id === null || $rule->locality_id !== null || $rule->domain_id !== null || $rule->business_id !== null) {
            throw new InvalidArgumentException('City targeting requires city_id without locality_id, domain_id, or business_id.');
        }
        $city = City::query()->find($rule->city_id);
        if ($city === null) {
            throw new InvalidArgumentException('Referenced city does not exist.');
        }
        if ($rule->state_id !== null && (int) $city->state_id !== (int) $rule->state_id) {
            throw new InvalidArgumentException('City does not belong to the specified state_id.');
        }
    }

    private static function assertLocalityRule(self $rule): void
    {
        if ($rule->locality_id === null || $rule->domain_id !== null || $rule->business_id !== null) {
            throw new InvalidArgumentException('Locality targeting requires locality_id without domain_id or business_id.');
        }
        $locality = Locality::query()->with('city:id,state_id')->find($rule->locality_id);
        if ($locality === null) {
            throw new InvalidArgumentException('Referenced locality does not exist.');
        }
        if ($rule->city_id !== null && (int) $locality->city_id !== (int) $rule->city_id) {
            throw new InvalidArgumentException('Locality does not belong to the specified city_id.');
        }
        if ($rule->state_id !== null && (int) ($locality->city?->state_id) !== (int) $rule->state_id) {
            throw new InvalidArgumentException('Locality does not belong to the specified state_id.');
        }
    }

    private static function assertDomainRule(self $rule): void
    {
        if ($rule->domain_id === null || $rule->state_id !== null || $rule->city_id !== null || $rule->locality_id !== null || $rule->business_id !== null) {
            throw new InvalidArgumentException('Domain targeting requires domain_id only.');
        }
    }

    private static function assertBusinessRule(self $rule): void
    {
        if ($rule->business_id === null || $rule->state_id !== null || $rule->city_id !== null || $rule->locality_id !== null || $rule->domain_id !== null) {
            throw new InvalidArgumentException('Business targeting requires business_id only.');
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function assertEnumValue(?string $value, array $allowed, string $type): void
    {
        if ($value === null || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Invalid {$type} target_value.");
        }
    }

    private static function assertRoutePattern(?string $value): void
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '' || (! str_starts_with($trimmed, '/') && $trimmed !== '*')) {
            throw new InvalidArgumentException('Route pattern target_value must start with / or be *.');
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_depth' => 'integer',
            'include_descendants' => 'boolean',
            'is_exclude' => 'boolean',
        ];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(PromoNotice::class, 'promo_notice_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class, 'locality_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }
}
