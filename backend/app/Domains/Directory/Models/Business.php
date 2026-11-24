<?php

declare(strict_types=1);

namespace App\Domains\Directory\Models;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Media\Models\BusinessMedia;
use App\Domains\Media\Models\KycDocument;
use App\Domains\Reviews\Models\Review;
use App\Domains\Taxonomy\Models\Amenity;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\PaymentMethod;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use App\Support\Concerns\HasSpatialCenterPoint;
use Carbon\CarbonImmutable;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'primary_category_id',
    'city_id',
    'locality_id',
    'name',
    'slug',
    'tagline',
    'description',
    'search_keywords',
    'year_established',
    'address_line_1',
    'address_line_2',
    'landmark',
    'pincode',
    'latitude',
    'longitude',
    'primary_phone_e164',
    'secondary_phone_e164',
    'whatsapp_e164',
    'email',
    'website_url',
])]
#[Hidden([
    'id',
    'owner_user_id',
    'location_point',
])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory, HasPublicUlid, HasSpatialCenterPoint, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_claimed' => false,
        'is_verified' => false,
        'rating_avg' => 0.00,
        'review_count' => 0,
        'bayesian_score' => 3.500,
    ];

    protected static function newFactory(): BusinessFactory
    {
        return BusinessFactory::new();
    }

    public function spatialPointColumn(): string
    {
        return 'location_point';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BusinessStatus::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'year_established' => 'integer',
            'is_claimed' => 'boolean',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'is_trusted' => 'boolean',
            'trusted_at' => 'datetime',
            'rating_avg' => 'float',
            'bayesian_score' => 'float',
            'review_count' => 'integer',
            'photo_count' => 'integer',
            'completeness_score' => 'integer',
            'view_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'business_categories')
            ->withPivot('is_primary');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<Locality, $this>
     */
    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }

    /**
     * @return HasMany<BusinessHour, $this>
     */
    public function hours(): HasMany
    {
        return $this->hasMany(BusinessHour::class)
            ->orderBy('day_of_week')
            ->orderBy('slot_index');
    }

    /**
     * @return HasMany<BusinessService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(BusinessService::class)->orderBy('sort_order');
    }

    /**
     * @return BelongsToMany<Amenity, $this>
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'business_amenities');
    }

    /**
     * @return BelongsToMany<PaymentMethod, $this>
     */
    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'business_payment_methods');
    }

    /**
     * @return HasMany<BusinessFaq, $this>
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(BusinessFaq::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<BusinessMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(BusinessMember::class);
    }

    /**
     * @return HasMany<BusinessVerification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(BusinessVerification::class)->latest();
    }

    /**
     * @return HasMany<BusinessClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(BusinessClaim::class)->latest();
    }

    /**
     * @return HasMany<BusinessMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(BusinessMedia::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<KycDocument, $this>
     */
    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class)->latest();
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->latest();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function savedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_businesses')
            ->withPivot('created_at');
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === BusinessStatus::Active && ! $this->trashed();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_user_id !== null && (int) $this->owner_user_id === (int) $user->id;
    }

    public function activeMemberFor(User $user): ?BusinessMember
    {
        if ($this->relationLoaded('members')) {
            return $this->members->first(
                static fn (BusinessMember $member): bool => (int) $member->user_id === (int) $user->id
                    && $member->status === 'active'
            );
        }

        return $this->members()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }

    public function maskedPhone(): string
    {
        $phone = $this->primary_phone_e164;
        if (strlen($phone) <= 6) {
            return 'XXXXX';
        }

        return substr($phone, 0, 8).'XXXXX';
    }

    public function isOpenNow(?CarbonImmutable $at = null): bool
    {
        $timezone = $this->city?->timezone ?? (string) config('directory.default_timezone', 'Asia/Kolkata');
        $now = ($at ?? CarbonImmutable::now())->setTimezone($timezone);
        $today = (int) $now->dayOfWeek; // 0 = Sun .. 6 = Sat
        $yesterday = ($today + 6) % 7;
        $currentTime = $now->format('H:i:s');

        $hours = $this->relationLoaded('hours') ? $this->hours : $this->hours()->get();

        foreach ($hours as $slot) {
            if ($slot->is_closed) {
                continue;
            }

            if ($slot->day_of_week === $today && $slot->is_24_hours) {
                return true;
            }

            if ($slot->open_time === null || $slot->close_time === null) {
                continue;
            }

            // Normal same-day window
            if ($slot->day_of_week === $today && $slot->open_time <= $slot->close_time) {
                if ($currentTime >= $slot->open_time && $currentTime <= $slot->close_time) {
                    return true;
                }
            }

            // Midnight-crossing shift started today
            if ($slot->day_of_week === $today && $slot->open_time > $slot->close_time) {
                if ($currentTime >= $slot->open_time) {
                    return true;
                }
            }

            // Midnight-crossing shift started yesterday
            if ($slot->day_of_week === $yesterday && $slot->open_time > $slot->close_time) {
                if ($currentTime <= $slot->close_time) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), BusinessStatus::Active->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeManagedBy(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            $q->where('owner_user_id', $user->id)
                ->orWhereHas('members', function (Builder $m) use ($user): void {
                    $m->where('user_id', $user->id)->where('status', 'active');
                });
        });
    }
}
