<?php

declare(strict_types=1);

namespace App\Domains\Leads\Jobs;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Leads\Enums\EnquiryStatus;
use App\Domains\Leads\Enums\EnquiryType;
use App\Domains\Leads\Enums\LeadStatus;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Leads\Models\EnquiryRecipient;
use App\Domains\Notifications\Notifications\EnquiryMatchedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DispatchEnquiryLeadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $enquiryId,
    ) {}

    public function handle(): void
    {
        /** @var Enquiry|null $enquiry */
        $enquiry = Enquiry::query()->with(['targetBusiness', 'category', 'city', 'locality'])->find($this->enquiryId);

        if ($enquiry === null || $enquiry->status !== EnquiryStatus::Active) {
            return;
        }

        DB::transaction(function () use ($enquiry): void {
            if ($enquiry->enquiry_type === EnquiryType::Direct) {
                $this->dispatchDirect($enquiry);
            } else {
                $this->dispatchBroadcast($enquiry);
            }

            $enquiry->dispatched_at = now();
            $enquiry->save();
        });

        // Notify customer only when recipients were successfully matched and dispatched
        $enquiry->loadMissing(['user', 'locality', 'city']);
        $recipientCount = $enquiry->recipients()->count();

        if ($recipientCount > 0 && $enquiry->user !== null) {
            $localityOrCity = $enquiry->locality?->name ?? $enquiry->city?->name ?? 'your area';
            $enquiry->user->notify(new EnquiryMatchedNotification(
                enquiryId: $enquiry->id,
                enquiryUlid: $enquiry->public_id,
                localityOrCity: $localityOrCity,
                matchedCount: $recipientCount,
            ));
        }
    }

    private function dispatchDirect(Enquiry $enquiry): void
    {
        $target = $enquiry->targetBusiness;
        if ($target === null || $target->status !== BusinessStatus::Active) {
            return;
        }

        EnquiryRecipient::query()->firstOrCreate(
            [
                'enquiry_id' => $enquiry->id,
                'business_id' => $target->id,
            ],
            [
                'lead_status' => LeadStatus::New,
                'delivery_channel' => 'in_app',
                'delivery_status' => 'delivered',
                'delivery_timestamp' => now(),
            ]
        );
    }

    private function dispatchBroadcast(Enquiry $enquiry): void
    {
        $maxRecipients = (int) config('directory.enquiries.max_broadcast_recipients', 4);

        // 1. Query verified, active, claimed businesses matching category & locality first
        $query = Business::query()
            ->where('status', BusinessStatus::Active)
            ->where('is_verified', true)
            ->where('is_claimed', true)
            ->where('city_id', $enquiry->city_id)
            ->where('primary_category_id', $enquiry->category_id);

        if ($enquiry->locality_id !== null) {
            $localityMatches = (clone $query)
                ->where('locality_id', $enquiry->locality_id)
                ->orderByDesc('bayesian_score')
                ->orderByDesc('rating_avg')
                ->limit($maxRecipients)
                ->get();

            if ($localityMatches->isNotEmpty()) {
                foreach ($localityMatches as $biz) {
                    EnquiryRecipient::query()->firstOrCreate(
                        ['enquiry_id' => $enquiry->id, 'business_id' => $biz->id],
                        [
                            'lead_status' => LeadStatus::New,
                            'delivery_channel' => 'in_app',
                            'delivery_status' => 'delivered',
                            'delivery_timestamp' => now(),
                        ]
                    );
                }

                return;
            }
        }

        // 2. Fall back to city-wide top verified businesses in category
        $cityMatches = $query
            ->orderByDesc('bayesian_score')
            ->orderByDesc('rating_avg')
            ->limit($maxRecipients)
            ->get();

        foreach ($cityMatches as $biz) {
            EnquiryRecipient::query()->firstOrCreate(
                ['enquiry_id' => $enquiry->id, 'business_id' => $biz->id],
                [
                    'lead_status' => LeadStatus::New,
                    'delivery_channel' => 'in_app',
                    'delivery_status' => 'delivered',
                    'delivery_timestamp' => now(),
                ]
            );
        }
    }
}
