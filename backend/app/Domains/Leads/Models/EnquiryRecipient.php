<?php

declare(strict_types=1);

namespace App\Domains\Leads\Models;

use App\Domains\Directory\Models\Business;
use App\Domains\Leads\Enums\LeadStatus;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enquiry_id',
    'business_id',
    'lead_status',
    'delivery_channel',
    'delivery_status',
    'delivery_timestamp',
    'is_read',
    'read_at',
    'contacted_at',
    'merchant_notes',
    'assigned_user_id',
])]
#[Hidden([
    'id',
    'enquiry_id',
    'business_id',
])]
class EnquiryRecipient extends Model
{
    use HasPublicUlid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lead_status' => LeadStatus::class,
            'is_read' => 'boolean',
            'delivery_timestamp' => 'datetime',
            'read_at' => 'datetime',
            'contacted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Enquiry, $this>
     */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
