<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Leads;

use App\Domains\Directory\Enums\BusinessMemberRole;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Leads\Enums\EnquiryStatus;
use App\Domains\Leads\Enums\LeadStatus;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Leads\Models\EnquiryRecipient;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OwnerLeadManagementTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private User $ownerA;

    private User $ownerB;

    private User $leadAgentA;

    private User $editorA;

    private Category $category;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->city = City::query()->where('slug', 'chennai')->firstOrFail();
        $this->category = Category::query()->where('slug', 'dentists')->firstOrFail();
        $locality = Locality::query()->where('city_id', $this->city->id)->firstOrFail();

        $this->ownerA = User::factory()->create();
        $this->businessA = new Business([
            'primary_category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $locality->id,
            'name' => 'Apex Dental Studio',
            'slug' => 'apex-dental-studio',
            'address_line_1' => '10 Usman Road',
            'pincode' => '600017',
            'latitude' => 13.0418,
            'longitude' => 80.2341,
            'primary_phone_e164' => '+919840111222',
        ]);
        $this->businessA->owner_user_id = $this->ownerA->id;
        $this->businessA->status = BusinessStatus::Active;
        $this->businessA->is_claimed = true;
        $this->businessA->is_verified = true;
        $this->businessA->save();

        $this->ownerB = User::factory()->create();
        $this->businessB = new Business([
            'primary_category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'locality_id' => $locality->id,
            'name' => 'Bright Smile Clinic',
            'slug' => 'bright-smile-clinic',
            'address_line_1' => '45 Anna Salai',
            'pincode' => '600002',
            'latitude' => 13.0604,
            'longitude' => 80.2496,
            'primary_phone_e164' => '+919840333444',
        ]);
        $this->businessB->owner_user_id = $this->ownerB->id;
        $this->businessB->status = BusinessStatus::Active;
        $this->businessB->is_claimed = true;
        $this->businessB->is_verified = true;
        $this->businessB->save();

        // Staff members for Business A
        $this->leadAgentA = User::factory()->create(['name' => 'Agent Priya']);
        BusinessMember::query()->create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->leadAgentA->id,
            'role' => BusinessMemberRole::LeadAgent,
            'status' => 'active',
            'permissions' => BusinessMemberRole::LeadAgent->defaultCapabilities(),
        ]);

        $this->editorA = User::factory()->create(['name' => 'Editor Vikram']);
        BusinessMember::query()->create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->editorA->id,
            'role' => BusinessMemberRole::Editor,
            'status' => 'active',
            'permissions' => BusinessMemberRole::Editor->defaultCapabilities(),
        ]);
    }

    private function createLead(Business $business, array $enquiryOverrides = [], array $recipientOverrides = []): EnquiryRecipient
    {
        $enquiry = Enquiry::query()->create(array_merge([
            'category_id' => $this->category->id,
            'city_id' => $this->city->id,
            'target_business_id' => $business->id,
            'customer_name' => 'Meera Krishnan',
            'phone_e164' => '+919840999888',
            'email' => 'meera@example.in',
            'requirement' => 'Need teeth alignment quote and consultation.',
            'urgency' => 'immediate',
            'enquiry_type' => 'direct',
            'status' => EnquiryStatus::Active,
            'consent_given' => true,
            'consent_version' => '2026.1',
            'consent_text' => 'Direct consent disclosure text',
            'consent_timestamp' => now(),
            'ip_address_hash' => hash('sha256', '127.0.0.1'),
            'phone_verified_at' => now(),
        ], $enquiryOverrides));

        return EnquiryRecipient::query()->create(array_merge([
            'enquiry_id' => $enquiry->id,
            'business_id' => $business->id,
            'lead_status' => LeadStatus::New,
            'delivery_channel' => 'in_app',
            'delivery_status' => 'delivered',
            'delivery_timestamp' => now(),
            'is_read' => false,
        ], $recipientOverrides));
    }

    public function test_owner_can_list_leads_with_cursor_pagination_and_status_filter(): void
    {
        $lead1 = $this->createLead($this->businessA, ['customer_name' => 'Lead One'], ['lead_status' => LeadStatus::New]);
        $lead2 = $this->createLead($this->businessA, ['customer_name' => 'Lead Two'], ['lead_status' => LeadStatus::Contacted]);
        $lead3 = $this->createLead($this->businessA, ['customer_name' => 'Lead Three'], ['lead_status' => LeadStatus::New]);

        $token = $this->ownerA->createToken('owner-token')->plainTextToken;

        // 1. Unfiltered query
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads?per_page=2");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.pagination.type', 'cursor')
            ->assertJsonCount(2, 'data');

        $this->assertEquals($lead3->public_id, $response->json('data.0.public_id'));
        $this->assertEquals($lead2->public_id, $response->json('data.1.public_id'));

        $nextCursor = $response->json('meta.pagination.next_cursor');
        $this->assertNotNull($nextCursor);

        // 2. Next cursor page
        $nextResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads?per_page=2&cursor={$nextCursor}");

        $nextResponse->assertStatus(200)
            ->assertJsonCount(1, 'data');
        $this->assertEquals($lead1->public_id, $nextResponse->json('data.0.public_id'));

        // 3. Status filter = new
        $filteredResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads?status=new");

        $filteredResponse->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_authorized_staff_can_view_and_manage_leads_while_unauthorized_staff_is_forbidden(): void
    {
        $lead = $this->createLead($this->businessA);

        $agentToken = $this->leadAgentA->createToken('agent-token')->plainTextToken;
        $editorToken = $this->editorA->createToken('editor-token')->plainTextToken;

        // Lead Agent (has leads.manage capability) -> Allowed
        $agentRes = $this->withHeader('Authorization', "Bearer {$agentToken}")
            ->getJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads");
        $agentRes->assertStatus(200);

        $this->app['auth']->forgetGuards();

        // Editor (does not have leads.manage capability) -> Forbidden
        $editorRes = $this->withHeader('Authorization', "Bearer {$editorToken}")
            ->getJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads");
        $editorRes->assertStatus(403);
    }

    public function test_cross_business_idor_is_strictly_blocked(): void
    {
        $leadB = $this->createLead($this->businessB);
        $tokenA = $this->ownerA->createToken('owner-a-token')->plainTextToken;

        // Owner A attempts to access Business B's leads list
        $listRes = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson("/api/v1/owner/businesses/{$this->businessB->public_id}/leads");
        $listRes->assertStatus(403);

        // Owner A attempts to update Lead B using Business A's URL parameter
        $updateRes = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->patchJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads/{$leadB->public_id}", [
                'lead_status' => 'contacted',
            ]);
        $updateRes->assertStatus(404)
            ->assertJsonPath('error.code', 'LEAD_NOT_FOUND');
    }

    public function test_owner_can_update_lead_status_notes_and_assign_staff(): void
    {
        $lead = $this->createLead($this->businessA);
        $token = $this->ownerA->createToken('owner-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads/{$lead->public_id}", [
                'lead_status' => 'contacted',
                'merchant_notes' => 'Called customer at 2 PM, shared pricing estimate.',
                'assigned_user_public_id' => $this->leadAgentA->public_id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.lead_status', 'contacted')
            ->assertJsonPath('data.merchant_notes', 'Called customer at 2 PM, shared pricing estimate.')
            ->assertJsonPath('data.assigned_user.public_id', $this->leadAgentA->public_id)
            ->assertJsonPath('data.is_read', true);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Contacted, $lead->lead_status);
        $this->assertNotNull($lead->contacted_at);
        $this->assertTrue($lead->is_read);
        $this->assertEquals($this->leadAgentA->id, $lead->assigned_user_id);
    }

    public function test_assigning_unauthorized_user_is_rejected(): void
    {
        $lead = $this->createLead($this->businessA);
        $token = $this->ownerA->createToken('owner-token')->plainTextToken;
        $unaffiliatedUser = User::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads/{$lead->public_id}", [
                'assigned_user_public_id' => $unaffiliatedUser->public_id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_STAFF_MEMBER');
    }

    public function test_editor_without_leads_manage_cannot_update_lead_status_notes_or_read_state(): void
    {
        $lead = $this->createLead($this->businessA);
        $editorToken = $this->editorA->createToken('editor-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$editorToken}")
            ->patchJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads/{$lead->public_id}", [
                'lead_status' => 'contacted',
                'merchant_notes' => 'Unauthorized editor note',
                'is_read' => true,
            ]);

        $response->assertStatus(403);

        $lead->refresh();
        $this->assertEquals(LeadStatus::New, $lead->lead_status);
        $this->assertNull($lead->merchant_notes);
        $this->assertFalse($lead->is_read);
        $this->assertNull($lead->read_at);
    }

    public function test_staff_from_another_business_cannot_access_or_mark_leads_read(): void
    {
        $leadA = $this->createLead($this->businessA);

        $staffB = User::factory()->create(['name' => 'Agent Suresh']);
        BusinessMember::query()->create([
            'business_id' => $this->businessB->id,
            'user_id' => $staffB->id,
            'role' => BusinessMemberRole::LeadAgent,
            'status' => 'active',
            'permissions' => BusinessMemberRole::LeadAgent->defaultCapabilities(),
        ]);
        $staffBToken = $staffB->createToken('staff-b-token')->plainTextToken;

        // Staff B attempts to list Business A's leads -> 403
        $listRes = $this->withHeader('Authorization', "Bearer {$staffBToken}")
            ->getJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads");
        $listRes->assertStatus(403);

        $this->app['auth']->forgetGuards();

        // Staff B attempts to update Business A's lead via Business A endpoint -> 403
        $updateViaARes = $this->withHeader('Authorization', "Bearer {$staffBToken}")
            ->patchJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads/{$leadA->public_id}", [
                'is_read' => true,
            ]);
        $updateViaARes->assertStatus(403);

        $this->app['auth']->forgetGuards();

        // Staff B attempts to update Business A's lead via Business B endpoint -> 404 (IDOR blocked)
        $updateViaBRes = $this->withHeader('Authorization', "Bearer {$staffBToken}")
            ->patchJson("/api/v1/owner/businesses/{$this->businessB->public_id}/leads/{$leadA->public_id}", [
                'is_read' => true,
            ]);
        $updateViaBRes->assertStatus(404)
            ->assertJsonPath('error.code', 'LEAD_NOT_FOUND');

        // Confirm database state untouched
        $leadA->refresh();
        $this->assertFalse($leadA->is_read);
        $this->assertNull($leadA->read_at);
    }

    public function test_customer_contact_details_and_merchant_notes_are_strictly_isolated(): void
    {
        $customer = User::factory()->create();
        $customerToken = $customer->createToken('customer-token')->plainTextToken;

        $leadA = $this->createLead($this->businessA, [
            'user_id' => $customer->id,
            'customer_name' => 'Kavitha Raman',
            'phone_e164' => '+919840555666',
            'email' => 'kavitha@example.in',
            'requirement' => 'Dental implants consultation required.',
        ]);

        $ownerAToken = $this->ownerA->createToken('owner-a-token')->plainTextToken;
        $ownerBToken = $this->ownerB->createToken('owner-b-token')->plainTextToken;

        // Owner A updates internal merchant notes
        $updateRes = $this->withHeader('Authorization', "Bearer {$ownerAToken}")
            ->patchJson("/api/v1/owner/businesses/{$this->businessA->public_id}/leads/{$leadA->public_id}", [
                'merchant_notes' => 'Confidential internal note: agreed 15% discount for full procedure.',
            ]);
        $updateRes->assertStatus(200);

        $this->app['auth']->forgetGuards();

        // 1. Business B lists their leads: zero leads returned, cannot see Business A's lead or contact info
        $bizBListRes = $this->withHeader('Authorization', "Bearer {$ownerBToken}")
            ->getJson("/api/v1/owner/businesses/{$this->businessB->public_id}/leads");
        $bizBListRes->assertStatus(200)
            ->assertJsonCount(0, 'data');
        $this->assertStringNotContainsString('+919840555666', $bizBListRes->getContent());
        $this->assertStringNotContainsString('kavitha@example.in', $bizBListRes->getContent());
        $this->assertStringNotContainsString('Confidential internal note', $bizBListRes->getContent());

        $this->app['auth']->forgetGuards();

        // 2. Business B attempts to fetch/update Lead A via Business B: 404
        $bizBUpdateRes = $this->withHeader('Authorization', "Bearer {$ownerBToken}")
            ->patchJson("/api/v1/owner/businesses/{$this->businessB->public_id}/leads/{$leadA->public_id}", [
                'merchant_notes' => 'Malicious overwrite attempt',
            ]);
        $bizBUpdateRes->assertStatus(404);

        $this->app['auth']->forgetGuards();

        // 3. Customer fetches their own enquiry via /api/v1/account/enquiries: merchant_notes are NEVER exposed
        $customerEnquiriesRes = $this->withHeader('Authorization', "Bearer {$customerToken}")
            ->getJson('/api/v1/account/enquiries');
        $customerEnquiriesRes->assertStatus(200);
        $this->assertStringNotContainsString('Confidential internal note', $customerEnquiriesRes->getContent());
        $this->assertStringNotContainsString('merchant_notes', $customerEnquiriesRes->getContent());

        // 4. Public storefront profile: never exposes customer contact info or merchant notes
        $publicProfileRes = $this->getJson("/api/v1/businesses/{$this->city->slug}/{$this->businessA->slug}");
        $publicProfileRes->assertStatus(200);
        $this->assertStringNotContainsString('+919840555666', $publicProfileRes->getContent());
        $this->assertStringNotContainsString('kavitha@example.in', $publicProfileRes->getContent());
        $this->assertStringNotContainsString('Confidential internal note', $publicProfileRes->getContent());
        $this->assertStringNotContainsString('merchant_notes', $publicProfileRes->getContent());
    }
}
