<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Domains\Notifications\Channels\CustomDatabaseChannel;
use App\Domains\Notifications\Enums\NotificationCategory;
use App\Domains\Notifications\Models\CustomerNotification;
use App\Domains\Notifications\Notifications\AccountSecurityNotification;
use App\Domains\Notifications\Notifications\EnquiryCancelledNotification;
use App\Domains\Notifications\Notifications\EnquiryMatchedNotification;
use App\Domains\Notifications\Notifications\EnquirySubmittedNotification;
use App\Domains\Notifications\Notifications\LeadStatusUpdatedNotification;
use App\Domains\Notifications\Notifications\ReviewReplyReceivedNotification;
use App\Domains\Notifications\Support\CancellationReasonMapper;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class NotificationDeduplicationAndQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->user = User::factory()->create(['status' => 'active']);
    }

    public function test_category_and_deduplication_key_are_persisted_via_custom_database_channel(): void
    {
        $this->user->notify(new EnquirySubmittedNotification(
            enquiryId: 777,
            enquiryUlid: '01HQENQ777',
            categoryName: 'Solar Solutions',
            targetBusinessName: 'SunPower Ltd'
        ));

        /** @var CustomerNotification $record */
        $record = $this->user->notifications()->firstOrFail();

        $this->assertEquals(NotificationCategory::Enquiry, $record->category);
        $this->assertEquals('enquiry:submitted:777', $record->deduplication_key);
        $this->assertIsArray($record->data);
        $this->assertEquals('Enquiry Submitted & Verified', $record->data['title']);
        $this->assertStringContainsString('SunPower Ltd', $record->data['message']);
    }

    public function test_database_level_unique_constraint_rejects_duplicate_deduplication_keys_per_user(): void
    {
        // Insert first record
        CustomerNotification::create([
            'id' => (string) Str::uuid(),
            'type' => EnquirySubmittedNotification::class,
            'notifiable_type' => $this->user->getMorphClass(),
            'notifiable_id' => $this->user->id,
            'category' => NotificationCategory::Enquiry->value,
            'deduplication_key' => 'test:dedup:key:001',
            'data' => ['title' => 'Test 1', 'message' => 'First message'],
        ]);

        // Attempting direct raw insert of exact same deduplication_key for same user must throw QueryException
        $this->expectException(QueryException::class);

        CustomerNotification::create([
            'id' => (string) Str::uuid(),
            'type' => EnquirySubmittedNotification::class,
            'notifiable_type' => $this->user->getMorphClass(),
            'notifiable_id' => $this->user->id,
            'category' => NotificationCategory::Enquiry->value,
            'deduplication_key' => 'test:dedup:key:001',
            'data' => ['title' => 'Test 2', 'message' => 'Duplicate attempt'],
        ]);
    }

    public function test_custom_database_channel_safely_handles_duplicate_events_idempotently(): void
    {
        $notification = new EnquirySubmittedNotification(
            enquiryId: 888,
            enquiryUlid: '01HQENQ888',
            categoryName: 'Plumbing'
        );

        // First notification send succeeds
        $this->user->notify($notification);
        $this->assertEquals(1, $this->user->notifications()->count());

        // Second notification send with exact same deduplication_key (e.g. queue retry) is safely ignored
        $this->user->notify($notification);
        $this->assertEquals(1, $this->user->notifications()->count());
    }

    public function test_soft_deleted_notification_retains_deduplication_key_preventing_job_retry_resurrection(): void
    {
        $notification = new EnquirySubmittedNotification(
            enquiryId: 999,
            enquiryUlid: '01HQENQ999',
            categoryName: 'Roofing'
        );

        $this->user->notify($notification);
        $this->assertEquals(1, $this->user->notifications()->count());

        /** @var CustomerNotification $notif */
        $notif = $this->user->notifications()->firstOrFail();
        $notif->delete(); // Soft-delete by user

        // Active count is 0
        $this->assertEquals(0, $this->user->notifications()->count());

        // Deduplication key still exists in database with deleted_at timestamp
        $this->assertDatabaseHas('notifications', [
            'id' => $notif->id,
            'deduplication_key' => 'enquiry:submitted:999',
        ]);

        // Worker retry attempt with same deduplication key does NOT resurrect or recreate the notification
        $this->user->notify($notification);
        $this->assertEquals(0, $this->user->notifications()->count());
        $this->assertEquals(1, CustomerNotification::withTrashed()->count());
    }

    public function test_review_reply_notification_uses_stable_initial_reply_version(): void
    {
        $notif1 = new ReviewReplyReceivedNotification(
            reviewId: 501,
            reviewUlid: '01HQREV501',
            businessName: 'Star Auto',
            businessSlug: 'star-auto',
            citySlug: 'vellore',
            replyText: 'Initial reply text.'
        );

        $this->assertEquals('review:reply:501:initial', $notif1->deduplicationKey($this->user));

        $this->user->notify($notif1);
        $this->assertEquals(1, $this->user->notifications()->count());

        // Merchant edits existing reply - default behavior: subsequent edits do not send another notification
        $notif2 = new ReviewReplyReceivedNotification(
            reviewId: 501,
            reviewUlid: '01HQREV501',
            businessName: 'Star Auto',
            businessSlug: 'star-auto',
            citySlug: 'vellore',
            replyText: 'Updated edited reply text.'
        );

        $this->user->notify($notif2);
        // Count remains 1; duplicate is prevented
        $this->assertEquals(1, $this->user->notifications()->count());
    }

    public function test_all_notifications_implement_should_queue_with_after_commit_and_notifications_queue(): void
    {
        $classes = [
            new EnquirySubmittedNotification(1, 'ULID1', 'Cat1'),
            new EnquiryMatchedNotification(1, 'ULID1', 'Vellore', 3),
            new LeadStatusUpdatedNotification(1, 'ULID1', 10, 'Biz1', 'contacted'),
            new EnquiryCancelledNotification(1, 'ULID1', 'Safe reason'),
            new ReviewReplyReceivedNotification(1, 'ULID1', 'Biz1', 'biz-1', 'vellore', 'Reply'),
            new AccountSecurityNotification('new_device', 'Title', 'Msg', 'DEV1'),
        ];

        foreach ($classes as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
            $this->assertTrue($notification->afterCommit);
            $this->assertEquals('notifications', $notification->queue);
        }
    }

    public function test_transaction_rollback_aborts_queued_notification_and_creates_no_database_record(): void
    {
        $this->assertEquals(0, CustomerNotification::query()->count());

        try {
            DB::transaction(function () {
                $this->user->notify(new EnquirySubmittedNotification(
                    enquiryId: 9999,
                    enquiryUlid: '01ROLLBACKENQ',
                    categoryName: 'Dentists',
                    targetBusinessName: 'Pearl Dental'
                ));

                // Before commit, verify no record was prematurely inserted
                $this->assertEquals(0, CustomerNotification::query()->count());

                throw new RuntimeException('Simulated transaction failure for rollback verification.');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated transaction failure for rollback verification.', $e->getMessage());
        }

        // After rollback: Laravel must have aborted the deferred afterCommit notification callback
        $this->assertEquals(0, CustomerNotification::query()->count());
        $this->assertDatabaseMissing('notifications', [
            'deduplication_key' => 'enquiry:submitted:9999',
        ]);

        // Contrast: Within a successful committed transaction, the afterCommit notification executes normally
        DB::transaction(function () {
            $this->user->notify(new EnquirySubmittedNotification(
                enquiryId: 8888,
                enquiryUlid: '01COMMITTEDENQ',
                categoryName: 'Dentists',
                targetBusinessName: 'Pearl Dental'
            ));
        });

        $this->assertEquals(1, CustomerNotification::query()->count());
        $this->assertDatabaseHas('notifications', [
            'deduplication_key' => 'enquiry:submitted:8888',
        ]);
    }

    public function test_cancellation_reason_mapper_sanitizes_internal_admin_reasons(): void
    {
        $testCases = [
            'duplicate customer lead submission' => 'This enquiry was identified as a duplicate submission and closed.',
            'Spam or inappropriate content violation' => 'This enquiry could not be fulfilled as it did not meet platform directory guidelines.',
            'Invalid phone number unreachable' => 'This enquiry was closed due to incomplete or unreachable contact details.',
            'customer requested cancellation via phone' => 'This enquiry was cancelled per your request.',
            'test submission from developer' => 'This test enquiry has been removed by platform administration.',
            'Other non-standard administrative note' => 'This enquiry has been closed by administration. Please contact support if you need assistance.',
        ];

        foreach ($testCases as $adminReason => $expectedCustomerCopy) {
            $actual = CancellationReasonMapper::toCustomerFacingMessage($adminReason);
            $this->assertEquals($expectedCustomerCopy, $actual);
        }
    }

    public function test_different_deduplication_keys_create_separate_records(): void
    {
        $notif1 = new EnquirySubmittedNotification(111, '01HQENQ111', 'Carpentry');
        $notif2 = new EnquirySubmittedNotification(222, '01HQENQ222', 'Electrical');

        $this->user->notify($notif1);
        $this->user->notify($notif2);

        $this->assertEquals(2, $this->user->notifications()->count());
    }

    public function test_repeated_identical_keys_for_same_recipient_do_not_create_duplicates(): void
    {
        $notif = new EnquirySubmittedNotification(333, '01HQENQ333', 'Painting');

        $this->user->notify($notif);
        $this->user->notify($notif);
        $this->user->notify($notif);

        $this->assertEquals(1, $this->user->notifications()->count());
    }

    public function test_not_null_violation_propagates_normally_through_custom_database_channel(): void
    {
        $channel = app(CustomDatabaseChannel::class);

        $invalidNotification = new class extends Notification
        {
            public function via($n): array
            {
                return [CustomDatabaseChannel::class];
            }

            public function toArray($n): ?array
            {
                return null;
            }
        };
        $invalidNotification->id = (string) Str::uuid();

        $this->expectException(QueryException::class);
        $channel->send($this->user, $invalidNotification);
    }

    public function test_different_unique_constraint_violation_propagates_normally(): void
    {
        $channel = app(CustomDatabaseChannel::class);

        $existing = CustomerNotification::create([
            'id' => (string) Str::uuid(),
            'type' => EnquirySubmittedNotification::class,
            'notifiable_type' => $this->user->getMorphClass(),
            'notifiable_id' => $this->user->id,
            'category' => NotificationCategory::Enquiry->value,
            'deduplication_key' => 'unique:pk:test:1',
            'data' => ['title' => 'Existing', 'message' => 'First message'],
        ]);

        $collisionNotification = new class extends Notification
        {
            public function via($n): array
            {
                return [CustomDatabaseChannel::class];
            }

            public function toArray($n): array
            {
                return ['title' => 'Collision', 'message' => 'Second message'];
            }

            public function deduplicationKey($n): string
            {
                return 'unique:pk:test:DIFFERENT_KEY';
            }
        };
        $collisionNotification->id = $existing->id;

        $this->expectException(QueryException::class);
        $channel->send($this->user, $collisionNotification);
    }

    public function test_foreign_key_violation_propagates_normally(): void
    {
        $channel = app(CustomDatabaseChannel::class);
        $ref = new ReflectionMethod($channel, 'isDuplicateKeyException');
        $ref->setAccessible(true);

        $fkException = new QueryException(
            'mysql',
            'insert into notifications ...',
            [],
            new \Exception('Cannot add or update a child row: a foreign key constraint fails (`local_biz`.`notifications`, CONSTRAINT `notifications_user_fk` FOREIGN KEY (`notifiable_id`) REFERENCES `users` (`id`))', 23000)
        );

        $this->assertFalse($ref->invoke($channel, $fkException));
    }
}
