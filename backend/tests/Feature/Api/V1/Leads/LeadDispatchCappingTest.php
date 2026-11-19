<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Leads;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Leads\Jobs\DispatchEnquiryLeadsJob;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Leads\Models\EnquiryRecipient;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeadDispatchCappingTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Locality $locality;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->city = City::query()->where('slug', 'chennai')->firstOrFail();
        $this->locality = Locality::query()->where('city_id', $this->city->id)->where('slug', 't-nagar')->firstOrFail();
        $this->category = Category::query()->where('slug', 'dentists')->firstOrFail();
    }

    public function test_broadcast_enquiry_caps_distribution_to_max_four_verified_businesses(): void
    {
        // Seed 6 active businesses in T. Nagar, Dentists
        // 5 are verified, 1 is unverified
        $owner = User::factory()->create();

        for ($i = 1; $i <= 6; $i++) {
            $biz = new Business([
                'primary_category_id' => $this->category->id,
                'city_id' => $this->city->id,
                'locality_id' => $this->locality->id,
                'name' => "Clinic Number {$i}",
                'slug' => "clinic-number-{$i}-t-nagar",
                'address_line_1' => "Shop {$i}, Usman Road",
                'pincode' => '600017',
                'latitude' => 13.0418,
                'longitude' => 80.2341,
                'primary_phone_e164' => "+91984010000{$i}",
            ]);
            $biz->owner_user_id = $owner->id;
            $biz->status = BusinessStatus::Active;
            $biz->is_claimed = true;
            $biz->is_verified = $i < 6; // 1..5 verified, 6 unverified
            $biz->verified_at = $i < 6 ? now() : null;
            $biz->is_trusted = true;
            $biz->bayesian_score = 3.5 + ($i * 0.1); // Distinct rankings
            $biz->rating_avg = 4.5;
            $biz->completeness_score = 80;
            $biz->save();
        }

        $customer = User::factory()->create([
            'phone_e164' => '+919840555555',
            'phone_verified_at' => now(),
        ]);
        $token = $customer->createToken('test-token', ['customer:enquiry'])->plainTextToken;

        // Broadcast enquiry (target_business_slug is omitted)
        $payload = [
            'customer_name' => 'Broadcast Consumer',
            'phone_e164' => '+919840555555',
            'requirement' => 'Need teeth whitening price quotes from multiple dentists.',
            'urgency' => 'within_week',
            'category_slug' => 'dentists',
            'city_slug' => 'chennai',
            'locality_slug' => 't-nagar',
            'consent_given' => true,
            'consent_version' => '2026.1',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/enquiries', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.enquiry_type', 'broadcast');

        $enquiryId = Enquiry::query()->where('public_id', $response->json('data.public_id'))->value('id');

        // Check recipients count
        $recipientCount = EnquiryRecipient::query()->where('enquiry_id', $enquiryId)->count();
        $this->assertEquals(4, $recipientCount, 'Broadcast enquiry must be strictly capped at max 4 recipients.');

        // Unverified business (Clinic Number 6) must NOT be among recipients
        $unverifiedBiz = Business::query()->where('slug', 'clinic-number-6-t-nagar')->firstOrFail();
        $this->assertDatabaseMissing('enquiry_recipients', [
            'enquiry_id' => $enquiryId,
            'business_id' => $unverifiedBiz->id,
        ]);

        // Idempotency check: Running dispatch job again must NOT duplicate recipients
        DispatchEnquiryLeadsJob::dispatchSync($enquiryId);
        $this->assertEquals(4, EnquiryRecipient::query()->where('enquiry_id', $enquiryId)->count());

        // Customer transparency view check: customer can see their enquiries and recipient businesses
        $transparencyRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/account/enquiries');

        $transparencyRes->assertStatus(200)
            ->assertJsonPath('data.0.recipients_count', 4);
    }
}
