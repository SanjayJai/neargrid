<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Notifications;

use App\Domains\Notifications\Models\CustomerNotification;
use App\Domains\Notifications\Notifications\AccountSecurityNotification;
use App\Domains\Notifications\Notifications\EnquirySubmittedNotification;
use App\Domains\Notifications\Notifications\ReviewReplyReceivedNotification;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CustomerNotificationApiTest extends TestCase
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

    public function test_unauthenticated_request_to_notifications_returns_401(): void
    {
        $response = $this->getJson('/api/v1/account/notifications');
        $response->assertStatus(401);
    }

    public function test_suspended_user_is_blocked_by_active_middleware(): void
    {
        $suspendedUser = User::factory()->create(['status' => 'suspended']);
        Sanctum::actingAs($suspendedUser);

        $response = $this->getJson('/api/v1/account/notifications');
        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    }

    public function test_customer_can_list_notifications_with_offset_pagination_meta(): void
    {
        Sanctum::actingAs($this->customerA);

        $this->customerA->notify(new EnquirySubmittedNotification(
            enquiryId: 101,
            enquiryUlid: '01HQENQ001',
            categoryName: 'Electricians'
        ));

        $this->customerA->notify(new ReviewReplyReceivedNotification(
            reviewId: 201,
            reviewUlid: '01HQREV001',
            businessName: 'Apex Electricals',
            businessSlug: 'apex-electricals',
            citySlug: 'vellore',
            replyText: 'Thank you for your feedback!'
        ));

        $response = $this->getJson('/api/v1/account/notifications?page=1&per_page=15');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.pagination.type', 'offset');
        $response->assertJsonPath('meta.pagination.current_page', 1);
        $response->assertJsonPath('meta.pagination.per_page', 15);
        $response->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_customer_can_filter_notifications_by_unread_status(): void
    {
        Sanctum::actingAs($this->customerA);

        $this->customerA->notify(new EnquirySubmittedNotification(
            enquiryId: 101,
            enquiryUlid: '01HQENQ001',
            categoryName: 'Plumbers'
        ));

        $this->customerA->notify(new AccountSecurityNotification(
            eventType: 'new_device',
            title: 'New Device Login',
            message: 'Chrome on Windows connected',
            uniqueEventId: '01HQDEV001'
        ));

        // Mark the first notification as read
        /** @var CustomerNotification $first */
        $first = $this->customerA->notifications()->firstOrFail();
        $first->markAsRead();

        // Query status=unread
        $response = $this->getJson('/api/v1/account/notifications?status=unread');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.is_read', false);
    }

    public function test_customer_can_filter_notifications_by_category(): void
    {
        Sanctum::actingAs($this->customerA);

        $this->customerA->notify(new EnquirySubmittedNotification(
            enquiryId: 101,
            enquiryUlid: '01HQENQ001',
            categoryName: 'Carpenters'
        ));

        $this->customerA->notify(new ReviewReplyReceivedNotification(
            reviewId: 201,
            reviewUlid: '01HQREV001',
            businessName: 'Vellore Woodworks',
            businessSlug: 'vellore-woodworks',
            citySlug: 'vellore',
            replyText: 'Glad we could help!'
        ));

        $this->customerA->notify(new AccountSecurityNotification(
            eventType: 'session_revocation',
            title: 'Sessions Revoked',
            message: 'Admin revoked sessions',
            uniqueEventId: '01HQREVOKE001'
        ));

        // Filter by enquiry
        $enqRes = $this->getJson('/api/v1/account/notifications?category=enquiry');
        $enqRes->assertOk();
        $enqRes->assertJsonCount(1, 'data');
        $enqRes->assertJsonPath('data.0.category', 'enquiry');

        // Filter by review
        $revRes = $this->getJson('/api/v1/account/notifications?category=review');
        $revRes->assertOk();
        $revRes->assertJsonCount(1, 'data');
        $revRes->assertJsonPath('data.0.category', 'review');

        // Filter by account
        $accRes = $this->getJson('/api/v1/account/notifications?category=account');
        $accRes->assertOk();
        $accRes->assertJsonCount(1, 'data');
        $accRes->assertJsonPath('data.0.category', 'account');
    }

    public function test_customer_can_fetch_unread_notification_count(): void
    {
        Sanctum::actingAs($this->customerA);

        $res0 = $this->getJson('/api/v1/account/notifications/unread-count');
        $res0->assertOk();
        $res0->assertJsonPath('data.unread_count', 0);

        $this->customerA->notify(new EnquirySubmittedNotification(
            enquiryId: 101,
            enquiryUlid: '01HQENQ001',
            categoryName: 'Pest Control'
        ));

        $this->customerA->notify(new EnquirySubmittedNotification(
            enquiryId: 102,
            enquiryUlid: '01HQENQ002',
            categoryName: 'Cleaning'
        ));

        $res2 = $this->getJson('/api/v1/account/notifications/unread-count');
        $res2->assertOk();
        $res2->assertJsonPath('data.unread_count', 2);
    }

    public function test_customer_can_mark_single_notification_as_read_idempotently(): void
    {
        Sanctum::actingAs($this->customerA);

        $this->customerA->notify(new EnquirySubmittedNotification(
            enquiryId: 101,
            enquiryUlid: '01HQENQ001',
            categoryName: 'Painters'
        ));

        /** @var CustomerNotification $notif */
        $notif = $this->customerA->notifications()->firstOrFail();
        $this->assertNull($notif->read_at);

        $res1 = $this->patchJson("/api/v1/account/notifications/{$notif->id}/read");
        $res1->assertOk();
        $res1->assertJsonPath('data.is_read', true);
        $this->assertNotNull($notif->fresh()->read_at);

        // Second call remains idempotent
        $res2 = $this->patchJson("/api/v1/account/notifications/{$notif->id}/read");
        $res2->assertOk();
        $res2->assertJsonPath('data.is_read', true);
    }

    public function test_customer_can_mark_all_unread_notifications_as_read(): void
    {
        Sanctum::actingAs($this->customerA);

        $this->customerA->notify(new EnquirySubmittedNotification(
            enquiryId: 101,
            enquiryUlid: '01HQENQ001',
            categoryName: 'Mechanics'
        ));
        $this->customerA->notify(new EnquirySubmittedNotification(
            enquiryId: 102,
            enquiryUlid: '01HQENQ002',
            categoryName: 'AC Repair'
        ));

        $this->assertEquals(2, $this->customerA->unreadNotifications()->count());

        $response = $this->postJson('/api/v1/account/notifications/mark-all-read');
        $response->assertOk();
        $response->assertJsonPath('data.marked_count', 2);

        $this->assertEquals(0, $this->customerA->unreadNotifications()->count());
    }

    public function test_customer_can_soft_delete_notification(): void
    {
        Sanctum::actingAs($this->customerA);

        $this->customerA->notify(new EnquirySubmittedNotification(
            enquiryId: 101,
            enquiryUlid: '01HQENQ001',
            categoryName: 'Catering'
        ));

        /** @var CustomerNotification $notif */
        $notif = $this->customerA->notifications()->firstOrFail();

        $delResponse = $this->deleteJson("/api/v1/account/notifications/{$notif->id}");
        $delResponse->assertOk();
        $delResponse->assertJsonPath('status', 'success');

        // Notification is soft-deleted
        $this->assertSoftDeleted('notifications', ['id' => $notif->id]);

        // Excluded from standard list and unread count
        $this->assertEquals(0, $this->customerA->notifications()->count());
        $this->assertEquals(0, $this->customerA->unreadNotifications()->count());

        $listRes = $this->getJson('/api/v1/account/notifications');
        $listRes->assertJsonCount(0, 'data');
    }

    public function test_strict_customer_isolation_customer_cannot_view_or_mutate_another_users_notification(): void
    {
        // Notification belongs to Customer B
        $this->customerB->notify(new EnquirySubmittedNotification(
            enquiryId: 201,
            enquiryUlid: '01HQENQB001',
            categoryName: 'Roofing'
        ));

        /** @var CustomerNotification $notifB */
        $notifB = $this->customerB->notifications()->firstOrFail();

        // Customer A attempts to access Customer B's notification
        Sanctum::actingAs($this->customerA);

        // Attempt mark read
        $readRes = $this->patchJson("/api/v1/account/notifications/{$notifB->id}/read");
        $readRes->assertStatus(404);

        // Attempt delete
        $delRes = $this->deleteJson("/api/v1/account/notifications/{$notifB->id}");
        $delRes->assertStatus(404);

        // Customer B's notification remains intact and unread
        $this->assertNull($notifB->fresh()->read_at);
        $this->assertNull($notifB->fresh()->deleted_at);
    }
}
