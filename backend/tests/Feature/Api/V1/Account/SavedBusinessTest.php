<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Account;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SavedBusinessTest extends TestCase
{
    use RefreshDatabase;

    private User $customerA;

    private User $customerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->customerA = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919876543210',
        ]);

        $this->customerB = User::factory()->create([
            'status' => 'active',
            'phone_e164' => '+919876543211',
        ]);
    }

    public function test_unauthenticated_requests_to_all_saved_businesses_endpoints_return_401(): void
    {
        $this->getJson('/api/v1/account/saved-businesses')->assertStatus(401);
        $this->getJson('/api/v1/account/saved-businesses/ids')->assertStatus(401);
        $this->putJson('/api/v1/account/saved-businesses/01HXYZ00000000000000000000')->assertStatus(401);
        $this->postJson('/api/v1/account/saved-businesses/01HXYZ00000000000000000000')->assertStatus(401);
        $this->deleteJson('/api/v1/account/saved-businesses/01HXYZ00000000000000000000')->assertStatus(401);
    }

    public function test_suspended_user_is_blocked_by_active_middleware(): void
    {
        $suspendedUser = User::factory()->create(['status' => 'suspended']);
        Sanctum::actingAs($suspendedUser);

        $response = $this->getJson('/api/v1/account/saved-businesses');
        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');

        $business = Business::factory()->create(['status' => BusinessStatus::Active]);
        $saveResponse = $this->putJson("/api/v1/account/saved-businesses/{$business->public_id}");
        $saveResponse->assertStatus(403);
        $saveResponse->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    }

    public function test_customer_can_list_paginated_saved_businesses_with_resource_structure_and_meta(): void
    {
        Sanctum::actingAs($this->customerA);

        $business1 = Business::factory()->create([
            'name' => 'Alpha Motors',
            'status' => BusinessStatus::Active,
        ]);
        $business2 = Business::factory()->create([
            'name' => 'Beta Bakery',
            'status' => BusinessStatus::Active,
        ]);
        $business3 = Business::factory()->create([
            'name' => 'Gamma Gym',
            'status' => BusinessStatus::Active,
        ]);

        // Save businesses at explicit timestamps
        DB::table('saved_businesses')->insert([
            ['user_id' => $this->customerA->id, 'business_id' => $business1->id, 'created_at' => now()->subMinutes(10)],
            ['user_id' => $this->customerA->id, 'business_id' => $business2->id, 'created_at' => now()->subMinutes(5)],
            ['user_id' => $this->customerA->id, 'business_id' => $business3->id, 'created_at' => now()],
        ]);

        // Request page 1 with 2 items per page
        $response = $this->getJson('/api/v1/account/saved-businesses?page=1&per_page=2');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.pagination.current_page', 1);
        $response->assertJsonPath('meta.pagination.per_page', 2);
        $response->assertJsonPath('meta.pagination.total', 3);
        $response->assertJsonPath('meta.pagination.last_page', 2);

        // Verify order is newest saved first (business3 then business2)
        $response->assertJsonPath('data.0.public_id', $business3->public_id);
        $response->assertJsonPath('data.0.name', 'Gamma Gym');
        $response->assertJsonPath('data.1.public_id', $business2->public_id);
        $response->assertJsonPath('data.1.name', 'Beta Bakery');

        // Verify PublicBusinessResource structure includes key attributes
        $response->assertJsonStructure([
            'status',
            'data' => [
                '*' => [
                    'public_id',
                    'name',
                    'slug',
                    'tagline',
                    'description',
                    'is_claimed',
                    'is_verified',
                    'is_trusted',
                    'primary_category',
                    'address' => [
                        'line_1',
                        'locality',
                        'city',
                        'pincode',
                    ],
                    'contact' => [
                        'phone_masked',
                    ],
                ],
            ],
            'meta' => [
                'pagination' => [
                    'type',
                    'total',
                    'per_page',
                    'current_page',
                    'last_page',
                ],
            ],
        ]);
    }

    public function test_customer_can_retrieve_lightweight_array_of_saved_public_ids(): void
    {
        Sanctum::actingAs($this->customerA);

        $business1 = Business::factory()->create(['status' => BusinessStatus::Active]);
        $business2 = Business::factory()->create(['status' => BusinessStatus::Active]);

        DB::table('saved_businesses')->insert([
            ['user_id' => $this->customerA->id, 'business_id' => $business1->id, 'created_at' => now()->subMinutes(5)],
            ['user_id' => $this->customerA->id, 'business_id' => $business2->id, 'created_at' => now()],
        ]);

        $response = $this->getJson('/api/v1/account/saved-businesses/ids');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.saved_ids', [
            $business2->public_id,
            $business1->public_id,
        ]);
    }

    public function test_customer_can_save_active_business_idempotently(): void
    {
        Sanctum::actingAs($this->customerA);

        $business = Business::factory()->create(['status' => BusinessStatus::Active]);

        // First save using PUT
        $response1 = $this->putJson("/api/v1/account/saved-businesses/{$business->public_id}");
        $response1->assertStatus(201);
        $response1->assertJsonPath('status', 'success');
        $response1->assertJsonPath('data.saved', true);
        $response1->assertJsonPath('data.public_id', $business->public_id);

        $this->assertDatabaseHas('saved_businesses', [
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
        ]);
        $this->assertDatabaseCount('saved_businesses', 1);

        // Repeat save using POST
        $response2 = $this->postJson("/api/v1/account/saved-businesses/{$business->public_id}");
        $response2->assertStatus(201);
        $response2->assertJsonPath('status', 'success');
        $response2->assertJsonPath('data.saved', true);
        $response2->assertJsonPath('data.public_id', $business->public_id);

        // No duplicate records created
        $this->assertDatabaseCount('saved_businesses', 1);
    }

    public function test_customer_can_unsave_business_idempotently(): void
    {
        Sanctum::actingAs($this->customerA);

        $business = Business::factory()->create(['status' => BusinessStatus::Active]);

        DB::table('saved_businesses')->insert([
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
            'created_at' => now(),
        ]);

        // Unsave first time
        $response1 = $this->deleteJson("/api/v1/account/saved-businesses/{$business->public_id}");
        $response1->assertNoContent();

        $this->assertDatabaseMissing('saved_businesses', [
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
        ]);
        $this->assertDatabaseCount('saved_businesses', 0);

        // Unsave again (repeat is idempotent)
        $response2 = $this->deleteJson("/api/v1/account/saved-businesses/{$business->public_id}");
        $response2->assertNoContent();
        $this->assertDatabaseCount('saved_businesses', 0);
    }

    public function test_strict_customer_data_isolation(): void
    {
        $bizA = Business::factory()->create(['name' => 'Biz of Customer A', 'status' => BusinessStatus::Active]);
        $bizB = Business::factory()->create(['name' => 'Biz of Customer B', 'status' => BusinessStatus::Active]);

        // Customer A saves Biz A
        DB::table('saved_businesses')->insert([
            'user_id' => $this->customerA->id,
            'business_id' => $bizA->id,
            'created_at' => now(),
        ]);

        // Customer B saves Biz B
        DB::table('saved_businesses')->insert([
            'user_id' => $this->customerB->id,
            'business_id' => $bizB->id,
            'created_at' => now(),
        ]);

        // Customer A inspects saved businesses
        Sanctum::actingAs($this->customerA);
        $listResponse = $this->getJson('/api/v1/account/saved-businesses');
        $listResponse->assertOk();
        $listResponse->assertJsonCount(1, 'data');
        $listResponse->assertJsonPath('data.0.public_id', $bizA->public_id);

        $idsResponse = $this->getJson('/api/v1/account/saved-businesses/ids');
        $idsResponse->assertOk();
        $idsResponse->assertJsonPath('data.saved_ids', [$bizA->public_id]);

        // Customer A tries to unsave Biz B (which is saved by Customer B)
        $deleteResponse = $this->deleteJson("/api/v1/account/saved-businesses/{$bizB->public_id}");
        $deleteResponse->assertNoContent();

        // Customer B's bookmark must be completely unaffected
        $this->assertDatabaseHas('saved_businesses', [
            'user_id' => $this->customerB->id,
            'business_id' => $bizB->id,
        ]);
    }

    public function test_saving_unpublished_business_returns_404(): void
    {
        Sanctum::actingAs($this->customerA);

        $statuses = [
            BusinessStatus::Draft,
            BusinessStatus::PendingReview,
            BusinessStatus::Rejected,
            BusinessStatus::Suspended,
        ];

        foreach ($statuses as $status) {
            $business = Business::factory()->create(['status' => $status]);

            $response = $this->putJson("/api/v1/account/saved-businesses/{$business->public_id}");
            $response->assertNotFound();

            $this->assertDatabaseMissing('saved_businesses', [
                'user_id' => $this->customerA->id,
                'business_id' => $business->id,
            ]);
        }
    }

    public function test_saving_soft_deleted_business_returns_404(): void
    {
        Sanctum::actingAs($this->customerA);

        $business = Business::factory()->create(['status' => BusinessStatus::Active]);
        $business->delete();

        $response = $this->putJson("/api/v1/account/saved-businesses/{$business->public_id}");
        $response->assertNotFound();

        $this->assertDatabaseMissing('saved_businesses', [
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
        ]);
    }

    public function test_saved_business_subsequently_suspended_or_soft_deleted_is_omitted_from_list_and_ids(): void
    {
        Sanctum::actingAs($this->customerA);

        $bizActive = Business::factory()->create(['status' => BusinessStatus::Active]);
        $bizSuspended = Business::factory()->create(['status' => BusinessStatus::Active]);
        $bizDeleted = Business::factory()->create(['status' => BusinessStatus::Active]);

        DB::table('saved_businesses')->insert([
            ['user_id' => $this->customerA->id, 'business_id' => $bizActive->id, 'created_at' => now()->subMinutes(3)],
            ['user_id' => $this->customerA->id, 'business_id' => $bizSuspended->id, 'created_at' => now()->subMinutes(2)],
            ['user_id' => $this->customerA->id, 'business_id' => $bizDeleted->id, 'created_at' => now()->subMinutes(1)],
        ]);

        // Mutate status and soft-delete (using forceFill because status is not in fillable)
        $bizSuspended->forceFill(['status' => BusinessStatus::Suspended])->save();
        $bizDeleted->delete();

        // Listing should only return the remaining active business
        $listResponse = $this->getJson('/api/v1/account/saved-businesses');
        $listResponse->assertOk();
        $listResponse->assertJsonCount(1, 'data');
        $listResponse->assertJsonPath('data.0.public_id', $bizActive->public_id);
        $listResponse->assertJsonPath('meta.pagination.total', 1);

        // IDs endpoint should also only return the active business
        $idsResponse = $this->getJson('/api/v1/account/saved-businesses/ids');
        $idsResponse->assertOk();
        $idsResponse->assertJsonPath('data.saved_ids', [$bizActive->public_id]);
    }

    public function test_unsaving_inactive_or_soft_deleted_business_succeeds_with_204(): void
    {
        Sanctum::actingAs($this->customerA);

        $bizSuspended = Business::factory()->create(['status' => BusinessStatus::Active]);
        $bizDeleted = Business::factory()->create(['status' => BusinessStatus::Active]);

        DB::table('saved_businesses')->insert([
            ['user_id' => $this->customerA->id, 'business_id' => $bizSuspended->id, 'created_at' => now()],
            ['user_id' => $this->customerA->id, 'business_id' => $bizDeleted->id, 'created_at' => now()],
        ]);

        // Suspend and delete
        $bizSuspended->forceFill(['status' => BusinessStatus::Suspended])->save();
        $bizDeleted->delete();

        // Customer unsaves the suspended business
        $delSuspended = $this->deleteJson("/api/v1/account/saved-businesses/{$bizSuspended->public_id}");
        $delSuspended->assertNoContent();
        $this->assertDatabaseMissing('saved_businesses', [
            'user_id' => $this->customerA->id,
            'business_id' => $bizSuspended->id,
        ]);

        // Customer unsaves the soft-deleted business
        $delDeleted = $this->deleteJson("/api/v1/account/saved-businesses/{$bizDeleted->public_id}");
        $delDeleted->assertNoContent();
        $this->assertDatabaseMissing('saved_businesses', [
            'user_id' => $this->customerA->id,
            'business_id' => $bizDeleted->id,
        ]);
    }

    public function test_deleting_user_cascades_and_removes_saved_businesses(): void
    {
        $business = Business::factory()->create(['status' => BusinessStatus::Active]);

        DB::table('saved_businesses')->insert([
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('saved_businesses', [
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
        ]);

        // Force delete user to test foreign key ON DELETE CASCADE
        $this->customerA->forceDelete();

        $this->assertDatabaseMissing('saved_businesses', [
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
        ]);
    }

    public function test_deleting_business_cascades_and_removes_saved_businesses(): void
    {
        $business = Business::factory()->create(['status' => BusinessStatus::Active]);

        DB::table('saved_businesses')->insert([
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('saved_businesses', [
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
        ]);

        // Force delete business to test foreign key ON DELETE CASCADE
        $business->forceDelete();

        $this->assertDatabaseMissing('saved_businesses', [
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
        ]);
    }

    public function test_concurrent_or_duplicate_save_handled_gracefully(): void
    {
        Sanctum::actingAs($this->customerA);

        $business = Business::factory()->create(['status' => BusinessStatus::Active]);

        // Pre-insert row simulating a concurrent process that won the race
        DB::table('saved_businesses')->insert([
            'user_id' => $this->customerA->id,
            'business_id' => $business->id,
            'created_at' => now(),
        ]);

        // Save again via controller
        $response = $this->putJson("/api/v1/account/saved-businesses/{$business->public_id}");
        $response->assertStatus(201);
        $response->assertJsonPath('data.saved', true);

        // Verify still exactly 1 entry
        $this->assertDatabaseCount('saved_businesses', 1);
    }
}
