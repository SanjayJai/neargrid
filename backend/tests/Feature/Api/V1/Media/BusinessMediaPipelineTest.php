<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Media;

use App\Domains\Directory\Enums\BusinessMemberRole;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Enums\VerificationDocumentType;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Directory\Services\BusinessCompletenessCalculator;
use App\Domains\Media\Enums\BusinessMediaType;
use App\Domains\Media\Enums\MediaUploadStatus;
use App\Domains\Media\Jobs\ProcessBusinessMediaJob;
use App\Domains\Media\Models\BusinessMedia;
use App\Domains\Media\Models\KycDocument;
use App\Domains\Media\Services\ImageProcessor;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class BusinessMediaPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('media_public');
        Storage::fake('kyc_private');
    }

    public function test_owner_can_upload_jpeg_png_webp_with_variants_exif_stripping_and_singleton_replacement(): void
    {
        $owner = User::factory()->create();
        $business = $this->createActiveOwnedBusiness($owner);
        $token = $owner->createToken('web')->plainTextToken;

        // 1. Upload 1600x1000 EXIF/GPS-bearing JPEG with untrusted client filename
        $jpegWithExif = $this->makeUploadedJpegWithExif('../../../evil-photo.php.jpg', 1600, 1000);

        $uploadRes = $this->withToken($token)->postJson(
            "/api/v1/owner/businesses/{$business->public_id}/media",
            [
                'file' => $jpegWithExif,
                'media_type' => 'gallery',
                'alt_text' => 'Main showroom',
                'sort_order' => 1,
            ]
        );

        $uploadRes->assertCreated()
            ->assertJsonPath('data.media_type', 'gallery')
            ->assertJsonPath('data.upload_status', 'ready')
            ->assertJsonPath('data.mime_type', 'image/webp')
            ->assertJsonPath('data.exif_stripped', true)
            ->assertJsonPath('data.width', 1280)
            ->assertJsonPath('data.height', 800);

        $responseJson = (string) $uploadRes->getContent();
        $this->assertStringNotContainsString('evil-photo', $responseJson);
        $this->assertStringNotContainsString('storage_key', $responseJson);
        $this->assertStringNotContainsString('media_public', $responseJson);

        $media = BusinessMedia::query()->where('public_id', $uploadRes->json('data.public_id'))->firstOrFail();
        $this->assertNull($media->original_key, 'Original must be removed by default when preserve_originals=false.');

        $disk = Storage::disk('media_public');
        $disk->assertExists((string) $media->webp_thumb_key);
        $disk->assertExists((string) $media->webp_card_key);
        $disk->assertExists((string) $media->webp_full_key);

        // Verify staging file was deleted
        $this->assertEmpty($disk->files("businesses/{$business->public_id}/staging"));

        // Verify all WebP variants are real WebP bitstreams with zero EXIF/GPS markers
        foreach ([$media->webp_thumb_key, $media->webp_card_key, $media->webp_full_key] as $variantKey) {
            $bytes = (string) $disk->get((string) $variantKey);
            $this->assertSame('RIFF', substr($bytes, 0, 4));
            $this->assertSame('WEBP', substr($bytes, 8, 4));
            $this->assertStringNotContainsString('Exif', $bytes);
            $this->assertStringNotContainsString('GPS_SECRET_13.0827_80.2707', $bytes);
        }

        // Verify exact variant widths (no upscaling, proportional scaling)
        $thumbInfo = getimagesizefromstring((string) $disk->get((string) $media->webp_thumb_key));
        $cardInfo = getimagesizefromstring((string) $disk->get((string) $media->webp_card_key));
        $fullInfo = getimagesizefromstring((string) $disk->get((string) $media->webp_full_key));
        $this->assertSame([320, 200], [$thumbInfo[0], $thumbInfo[1]]);
        $this->assertSame([640, 400], [$cardInfo[0], $cardInfo[1]]);
        $this->assertSame([1280, 800], [$fullInfo[0], $fullInfo[1]]);

        // 2. Upload transparent PNG logo, then replace it and verify old logo files + row are removed
        $logo1Res = $this->withToken($token)->postJson(
            "/api/v1/owner/businesses/{$business->public_id}/media",
            [
                'file' => $this->makeUploadedPng('logo1.png', 400, 400),
                'media_type' => 'logo',
            ]
        );
        $logo1Res->assertCreated();
        $logo1 = BusinessMedia::query()->where('public_id', $logo1Res->json('data.public_id'))->firstOrFail();
        $oldLogoKeys = $logo1->allStorageKeys();

        $logo2Res = $this->withToken($token)->postJson(
            "/api/v1/owner/businesses/{$business->public_id}/media",
            [
                'file' => $this->makeUploadedWebp('logo2.webp', 300, 300),
                'media_type' => 'logo',
            ]
        );
        $logo2Res->assertCreated();

        $this->assertDatabaseMissing('business_media', ['public_id' => $logo1->public_id]);
        foreach ($oldLogoKeys as $oldKey) {
            $disk->assertMissing($oldKey);
        }

        $business->refresh();
        $this->assertSame(2, $business->photo_count);
    }

    public function test_preserve_originals_flag_keeps_staged_original_when_enabled(): void
    {
        config(['media.preserve_originals' => true]);

        $owner = User::factory()->create();
        $business = $this->createActiveOwnedBusiness($owner);
        $token = $owner->createToken('web')->plainTextToken;

        $res = $this->withToken($token)->postJson(
            "/api/v1/owner/businesses/{$business->public_id}/media",
            [
                'file' => $this->makeUploadedPng('storefront.png', 600, 400),
                'media_type' => 'cover',
            ]
        );

        $res->assertCreated();
        $media = BusinessMedia::query()->where('public_id', $res->json('data.public_id'))->firstOrFail();

        $this->assertNotNull($media->original_key);
        Storage::disk('media_public')->assertExists((string) $media->original_key);
        Storage::disk('media_public')->assertExists((string) $media->webp_full_key);
    }

    public function test_rejects_executables_scripts_svg_malformed_images_oversized_and_count_limits(): void
    {
        $owner = User::factory()->create();
        $business = $this->createActiveOwnedBusiness($owner);
        $token = $owner->createToken('web')->plainTextToken;

        // Disguised Windows PE executable
        $exe = UploadedFile::fake()->createWithContent('photo.jpg', "MZ\x90\x00\x03\x00\x00\x00PE_EXEC_PAYLOAD");
        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/media", [
            'file' => $exe,
            'media_type' => 'gallery',
        ])->assertStatus(422)->assertJsonPath('error.code', 'DISALLOWED_EXECUTABLE_OR_SCRIPT');

        // Disguised PHP script
        $php = UploadedFile::fake()->createWithContent('avatar.png', '<?php echo "not-an-image"; ?>');
        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/media", [
            'file' => $php,
            'media_type' => 'gallery',
        ])->assertStatus(422)->assertJsonPath('error.code', 'DISALLOWED_EXECUTABLE_OR_SCRIPT');

        // Disguised SVG with script tag
        $svg = UploadedFile::fake()->createWithContent('logo.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/media", [
            'file' => $svg,
            'media_type' => 'logo',
        ])->assertStatus(422)->assertJsonPath('error.code', 'DISALLOWED_EXECUTABLE_OR_SCRIPT');

        // Corrupt/truncated JPEG with valid header bytes
        $corruptJpeg = UploadedFile::fake()->createWithContent('broken.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF_CORRUPTED_BYTES_ONLY");
        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/media", [
            'file' => $corruptJpeg,
            'media_type' => 'gallery',
        ])->assertStatus(422);

        // Oversized file check (413)
        config(['media.limits.public_max_bytes' => 512]);
        $tooBig = $this->makeUploadedPng('large.png', 500, 500);
        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/media", [
            'file' => $tooBig,
            'media_type' => 'gallery',
        ])->assertStatus(413)->assertJsonPath('error.code', 'FILE_TOO_LARGE');

        // Restore byte limit and test gallery count cap
        config(['media.limits.public_max_bytes' => 5 * 1024 * 1024]);
        config(['media.limits.max_gallery_per_business' => 2]);

        for ($i = 1; $i <= 2; $i++) {
            $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/media", [
                'file' => $this->makeUploadedPng("g{$i}.png", 200, 200),
                'media_type' => 'gallery',
            ])->assertCreated();
        }

        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/media", [
            'file' => $this->makeUploadedPng('g3.png', 200, 200),
            'media_type' => 'gallery',
        ])->assertStatus(422)->assertJsonPath('error.code', 'MEDIA_LIMIT_EXCEEDED');
    }

    public function test_large_upload_queues_async_job_and_cleans_up_on_storage_and_permanent_failure(): void
    {
        Queue::fake();
        config(['media.async_threshold_bytes' => 50]);

        $owner = User::factory()->create();
        $business = $this->createActiveOwnedBusiness($owner);
        $token = $owner->createToken('web')->plainTextToken;

        $res = $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/media", [
            'file' => $this->makeUploadedPng('async.png', 400, 300),
            'media_type' => 'gallery',
        ]);

        $res->assertStatus(202)
            ->assertJsonPath('data.upload_status', 'pending');

        $media = BusinessMedia::query()->where('public_id', $res->json('data.public_id'))->firstOrFail();

        Queue::assertPushedOn('media', ProcessBusinessMediaJob::class, function (ProcessBusinessMediaJob $job) use ($media): bool {
            return $job->businessMediaId === $media->id
                && $job->tries === 4
                && $job->backoff() === [30, 120, 600];
        });

        // Simulate partial storage write failure during handle(): thumb succeeds, card fails -> thumb must be cleaned up
        $realBytes = (string) Storage::disk('media_public')->get($media->storage_key);
        $mockDisk = Mockery::mock(FilesystemAdapter::class);
        $mockDisk->shouldReceive('exists')->once()->with($media->storage_key)->andReturn(true);
        $mockDisk->shouldReceive('get')->once()->with($media->storage_key)->andReturn($realBytes);
        $mockDisk->shouldReceive('put')->once()->andReturn(true); // thumb succeeds
        $mockDisk->shouldReceive('put')->once()->andThrow(new RuntimeException('Simulated MinIO write timeout')); // card fails
        $mockDisk->shouldReceive('delete')->once()->withArgs(static fn (array $keys): bool => count($keys) === 1 && str_ends_with($keys[0], '/thumb.webp'))->andReturn(true);

        Storage::shouldReceive('disk')->with('media_public')->andReturn($mockDisk);

        $job = new ProcessBusinessMediaJob($media->id);

        try {
            $job->handle(app(ImageProcessor::class), app(BusinessCompletenessCalculator::class));
            $this->fail('Expected storage exception to be rethrown for queue retry.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated MinIO write timeout', $e->getMessage());
        }

        // Simulate permanent failure callback -> marks failed, stores failure_reason, deletes staging file
        $mockDisk->shouldReceive('delete')->once()->withArgs(fn (array $keys): bool => in_array($media->storage_key, $keys, true))->andReturn(true);
        $job->failed(new RuntimeException('Simulated MinIO write timeout'));

        $media->refresh();
        $this->assertSame(MediaUploadStatus::Failed, $media->upload_status);
        $this->assertSame('Simulated MinIO write timeout', $media->failure_reason);
        $this->assertNull($media->webp_full_key);
    }

    public function test_authorization_staff_capabilities_and_cross_business_idor_on_media(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $leadAgent = User::factory()->create();
        $editor = User::factory()->create();

        $businessA = $this->createActiveOwnedBusiness($ownerA);
        $businessB = $this->createActiveOwnedBusiness($ownerB);

        BusinessMember::query()->create([
            'business_id' => $businessA->id,
            'user_id' => $leadAgent->id,
            'role' => BusinessMemberRole::LeadAgent,
            'status' => 'active',
            'invited_by_user_id' => $ownerA->id,
            'accepted_at' => now(),
        ]);

        BusinessMember::query()->create([
            'business_id' => $businessA->id,
            'user_id' => $editor->id,
            'role' => BusinessMemberRole::Editor,
            'status' => 'active',
            'invited_by_user_id' => $ownerA->id,
            'accepted_at' => now(),
        ]);

        // LeadAgent cannot upload media
        $this->withToken($leadAgent->createToken('web')->plainTextToken)
            ->postJson("/api/v1/owner/businesses/{$businessA->public_id}/media", [
                'file' => $this->makeUploadedPng('lead.png', 200, 200),
                'media_type' => 'gallery',
            ])->assertForbidden();

        auth()->forgetGuards();

        // Editor CAN upload media
        $editorUpload = $this->withToken($editor->createToken('web')->plainTextToken)
            ->postJson("/api/v1/owner/businesses/{$businessA->public_id}/media", [
                'file' => $this->makeUploadedPng('editor.png', 200, 200),
                'media_type' => 'gallery',
            ])->assertCreated();

        $mediaPublicId = (string) $editorUpload->json('data.public_id');
        $media = BusinessMedia::query()->where('public_id', $mediaPublicId)->firstOrFail();

        auth()->forgetGuards();

        // Owner B with wildcard token CANNOT list, upload to, or delete Business A's media (direct or cross-parent IDOR)
        $tokenB = $ownerB->createToken('web', ['*'])->plainTextToken;
        $this->withToken($tokenB)->getJson("/api/v1/owner/businesses/{$businessA->public_id}/media")->assertForbidden();
        $this->withToken($tokenB)->postJson("/api/v1/owner/businesses/{$businessA->public_id}/media", [
            'file' => $this->makeUploadedPng('idor.png', 200, 200),
            'media_type' => 'gallery',
        ])->assertForbidden();
        $this->withToken($tokenB)->deleteJson("/api/v1/owner/businesses/{$businessA->public_id}/media/{$mediaPublicId}")->assertForbidden();
        $this->withToken($tokenB)->deleteJson("/api/v1/owner/businesses/{$businessB->public_id}/media/{$mediaPublicId}")->assertForbidden();

        auth()->forgetGuards();

        // Owner A deletes media -> storage variants removed and audit logged
        $keysBeforeDelete = $media->allStorageKeys();
        $this->withToken($ownerA->createToken('web')->plainTextToken)
            ->deleteJson("/api/v1/owner/businesses/{$businessA->public_id}/media/{$mediaPublicId}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('business_media', ['public_id' => $mediaPublicId]);
        foreach ($keysBeforeDelete as $key) {
            Storage::disk('media_public')->assertMissing($key);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.media_deleted']);
    }

    public function test_public_business_profile_returns_only_ready_approved_public_media_and_never_kyc(): void
    {
        $owner = User::factory()->create();
        $business = $this->createActiveOwnedBusiness($owner);
        $token = $owner->createToken('web')->plainTextToken;

        // 1. Ready & approved public media
        $readyRes = $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/media", [
            'file' => $this->makeUploadedPng('visible.png', 400, 300),
            'media_type' => 'gallery',
            'alt_text' => 'Visible photo',
        ])->assertCreated();
        $visiblePublicId = (string) $readyRes->json('data.public_id');

        // 2. Pending, failed, and rejected public media records (must NOT appear publicly)
        BusinessMedia::query()->create([
            'business_id' => $business->id,
            'uploaded_by_user_id' => $owner->id,
            'media_type' => BusinessMediaType::Gallery,
            'upload_status' => MediaUploadStatus::Pending,
            'moderation_status' => 'approved',
            'disk' => 'media_public',
            'storage_key' => 'businesses/fake/staging/pending.jpg',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 1000,
        ]);

        BusinessMedia::query()->create([
            'business_id' => $business->id,
            'uploaded_by_user_id' => $owner->id,
            'media_type' => BusinessMediaType::Gallery,
            'upload_status' => MediaUploadStatus::Failed,
            'moderation_status' => 'approved',
            'disk' => 'media_public',
            'storage_key' => 'businesses/fake/staging/failed.jpg',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 1000,
            'failure_reason' => 'Corrupt',
        ]);

        BusinessMedia::query()->create([
            'business_id' => $business->id,
            'uploaded_by_user_id' => $owner->id,
            'media_type' => BusinessMediaType::Gallery,
            'upload_status' => MediaUploadStatus::Ready,
            'moderation_status' => 'rejected',
            'disk' => 'media_public',
            'storage_key' => 'businesses/fake/rejected/full.webp',
            'webp_full_key' => 'businesses/fake/rejected/full.webp',
            'mime_type' => 'image/webp',
            'file_size_bytes' => 1000,
        ]);

        // 3. Private KYC document (must NEVER leak in public profile)
        KycDocument::query()->create([
            'business_id' => $business->id,
            'uploaded_by_user_id' => $owner->id,
            'document_type' => VerificationDocumentType::Gstin,
            'upload_status' => 'ready',
            'disk' => 'kyc_private',
            'storage_key' => 'kyc/secret/gst-private.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'sha256_checksum' => str_repeat('a', 64),
        ]);

        $publicRes = $this->getJson("/api/v1/businesses/{$business->city->slug}/{$business->slug}");
        $publicRes->assertOk()
            ->assertJsonCount(1, 'data.media')
            ->assertJsonPath('data.media.0.public_id', $visiblePublicId)
            ->assertJsonPath('data.media.0.alt_text', 'Visible photo')
            ->assertJsonStructure([
                'data' => [
                    'media' => [
                        ['public_id', 'media_type', 'alt_text', 'width', 'height', 'sort_order', 'urls' => ['thumb', 'card', 'full']],
                    ],
                ],
            ]);

        $raw = (string) $publicRes->getContent();
        $this->assertStringNotContainsString('kyc_private', $raw);
        $this->assertStringNotContainsString('gst-private.pdf', $raw);
        $this->assertStringNotContainsString('storage_key', $raw);
        $this->assertStringNotContainsString('kyc_documents', $raw);
    }

    private function createActiveOwnedBusiness(User $owner): Business
    {
        $business = Business::factory()->create([
            'owner_user_id' => $owner->id,
            'status' => BusinessStatus::Active,
            'is_claimed' => true,
        ]);

        BusinessMember::query()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'role' => BusinessMemberRole::Owner,
            'status' => 'active',
            'invited_by_user_id' => $owner->id,
            'accepted_at' => now(),
        ]);

        return $business->load('city');
    }

    private function makeUploadedJpegWithExif(string $name, int $width, int $height): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, 40, 120, 200);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);
        ob_start();
        imagejpeg($img, null, 85);
        $jpeg = (string) ob_get_clean();
        unset($img);

        // Inject a synthetic APP1 EXIF/GPS segment directly after SOI (\xFF\xD8)
        $exifPayload = "Exif\x00\x00II*\x00\x08\x00\x00\x00\x00\x00\x00\x00GPS_SECRET_13.0827_80.2707";
        $app1Segment = "\xFF\xE1".pack('n', strlen($exifPayload) + 2).$exifPayload;
        $jpegWithExif = substr($jpeg, 0, 2).$app1Segment.substr($jpeg, 2);

        $this->assertStringContainsString('GPS_SECRET_13.0827_80.2707', $jpegWithExif);

        return UploadedFile::fake()->createWithContent($name, $jpegWithExif);
    }

    private function makeUploadedPng(string $name, int $width, int $height): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $width, $height, $transparent);
        $accent = imagecolorallocate($img, 20, 180, 120);
        imagefilledrectangle($img, 10, 10, $width - 10, $height - 10, $accent);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        unset($img);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function makeUploadedWebp(string $name, int $width, int $height): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, 180, 90, 40);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);
        ob_start();
        imagewebp($img, null, 82);
        $bytes = (string) ob_get_clean();
        unset($img);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}
