<?php

declare(strict_types=1);

namespace App\Domains\Leads\Models;

use App\Domains\Directory\Models\Business;
use App\Domains\Domains\Models\Domain;
use App\Domains\Leads\Enums\EnquiryStatus;
use App\Domains\Leads\Enums\EnquiryType;
use App\Domains\Leads\Enums\EnquiryUrgency;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'target_business_id',
    'category_id',
    'city_id',
    'locality_id',
    'customer_name',
    'phone_e164',
    'email',
    'requirement',
    'urgency',
    'enquiry_type',
    'status',
    'consent_given',
    'consent_version',
    'consent_text',
    'consent_purpose',
    'consent_timestamp',
    'ip_address_hash',
    'otp_challenge_id',
    'phone_verified_at',
    'dispatched_at',
    'cancellation_reason',
    'cancelled_at',
    'cancelled_by_user_id',
])]
#[Hidden([
    'id',
    'ip_address_hash',
    'otp_challenge_id',
])]
class Enquiry extends Model
{
    use HasPublicUlid, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'urgency' => EnquiryUrgency::class,
            'enquiry_type' => EnquiryType::class,
            'status' => EnquiryStatus::class,
            'consent_given' => 'boolean',
            'consent_timestamp' => 'datetime',
            'phone_verified_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'source_domain_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function sourceDomain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'source_domain_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function targetBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'target_business_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * @return HasMany<EnquiryRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(EnquiryRecipient::class, 'enquiry_id');
    }
}
