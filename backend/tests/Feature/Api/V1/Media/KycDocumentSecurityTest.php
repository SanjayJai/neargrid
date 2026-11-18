<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Media;

use App\Domains\Directory\Enums\BusinessMemberRole;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Identity\Models\Role;
use App\Domains\Media\Models\KycDocument;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class KycDocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('media_public');
        Storage::fake('kyc_private');
    }

    public function test_owner_uploads_pdf_and_exif_stripped_image_exclusively_to_private_kyc_disk(): void
    {
        $owner = User::factory()->create();
        $business = $this->createOwnedBusiness($owner);
        $token = $owner->createToken('web')->plainTextToken;

        // 1. Upload valid PDF with GST number -> creates linked BusinessVerification & private KycDocument
        $pdfRes = $this->withToken($token)->postJson(
            "/api/v1/owner/businesses/{$business->public_id}/kyc-documents",
            [
                'file' => UploadedFile::fake()->createWithContent('secret-gst-copy.pdf', $this->minimalValidPdfBytes()),
                'document_type' => 'gstin',
                'document_number' => '33AABCU9603R1ZM',
            ]
        );

        $pdfRes->assertCreated()
            ->assertJsonPath('data.document_type', 'gstin')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.upload_status', 'ready');

        $rawJson = (string) $pdfRes->getContent();
        $this->assertStringNotContainsString('secret-gst-copy', $rawJson);
        $this->assertStringNotContainsString('storage_key', $rawJson);
        $this->assertStringNotContainsString('kyc_private', $rawJson);

        $pdfDoc = KycDocument::query()->where('public_id', $pdfRes->json('data.public_id'))->firstOrFail();
        $this->assertSame('kyc_private', $pdfDoc->disk);
        $this->assertNotNull($pdfDoc->business_verification_id);
        Storage::disk('kyc_private')->assertExists($pdfDoc->storage_key);
        Storage::disk('media_public')->assertMissing($pdfDoc->storage_key);

        // 2. Upload EXIF/GPS-injected JPEG as KYC document -> stripped and saved as private WebP
        $imgRes = $this->withToken($token)->postJson(
            "/api/v1/owner/businesses/{$business->public_id}/kyc-documents",
            [
                'file' => $this->makeJpegWithExif('owner-license.jpg', 500, 350),
                'document_type' => 'shop_act',
            ]
        );

        $imgRes->assertCreated()
            ->assertJsonPath('data.mime_type', 'image/webp');

        $imgDoc = KycDocument::query()->where('public_id', $imgRes->json('data.public_id'))->firstOrFail();
        $storedBytes = (string) Storage::disk('kyc_private')->get($imgDoc->storage_key);
        $this->assertSame('RIFF', substr($storedBytes, 0, 4));
        $this->assertSame('WEBP', substr($storedBytes, 8, 4));
        $this->assertStringNotContainsString('Exif', $storedBytes);
        $this->assertStringNotContainsString('KYC_GPS_COORDS_SECRET', $storedBytes);
    }

    public function test_kyc_rejects_malicious_corrupt_oversized_and_excess_documents(): void
    {
        $owner = User::factory()->create();
        $business = $this->createOwnedBusiness($owner);
        $token = $owner->createToken('web')->plainTextToken;

        // Executable disguised as PDF
        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/kyc-documents", [
            'file' => UploadedFile::fake()->createWithContent('gst.pdf', "MZ\x90\x00MALWARE"),
            'document_type' => 'gstin',
        ])->assertStatus(422)->assertJsonPath('error.code', 'DISALLOWED_EXECUTABLE_OR_SCRIPT');

        // Fake PDF without %%EOF trailer
        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/kyc-documents", [
            'file' => UploadedFile::fake()->createWithContent('bad.pdf', '%PDF-1.4 truncated without trailer'),
            'document_type' => 'gstin',
        ])->assertStatus(422)->assertJsonPath('error.code', 'MALFORMED_PDF');

        // Oversized KYC file (413)
        config(['media.limits.kyc_max_bytes' => 64]);
        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/kyc-documents", [
            'file' => UploadedFile::fake()->createWithContent('big.pdf', $this->minimalValidPdfBytes()),
            'document_type' => 'gstin',
        ])->assertStatus(413)->assertJsonPath('error.code', 'FILE_TOO_LARGE');

        // Count limit check
        config(['media.limits.kyc_max_bytes' => 10 * 1024 * 1024]);
        config(['media.limits.max_kyc_docs_per_business' => 1]);

        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/kyc-documents", [
            'file' => UploadedFile::fake()->createWithContent('ok.pdf', $this->minimalValidPdfBytes()),
            'document_type' => 'gstin',
        ])->assertCreated();

        $this->withToken($token)->postJson("/api/v1/owner/businesses/{$business->public_id}/kyc-documents", [
            'file' => UploadedFile::fake()->createWithContent('second.pdf', $this->minimalValidPdfBytes()),
            'document_type' => 'pan',
        ])->assertStatus(422)->assertJsonPath('error.code', 'KYC_DOCUMENT_LIMIT_EXCEEDED');
    }

    public function test_signed_kyc_download_enforces_expiry_signature_actor_binding_and_rbac(): void
    {
        config(['media.kyc_signed_url_ttl_seconds' => 60]);

        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $admin = User::factory()->create();
        $kycVerifierRole = Role::query()->where('name', 'kyc_verifier')->firstOrFail();
        $admin->roles()->syncWithoutDetaching([$kycVerifierRole->id]);

        $businessA = $this->createOwnedBusiness($ownerA);
        $businessB = $this->createOwnedBusiness($ownerB);

        $tokenA = $ownerA->createToken('web')->plainTextToken;
        $tokenB = $ownerB->createToken('web', ['*'])->plainTextToken;
        $adminToken = $admin->createToken('admin')->plainTextToken;

        $pdfBytes = $this->minimalValidPdfBytes();
        $uploadRes = $this->withToken($tokenA)->postJson(
            "/api/v1/owner/businesses/{$businessA->public_id}/kyc-documents",
            [
                'file' => UploadedFile::fake()->createWithContent('gst.pdf', $pdfBytes),
                'document_type' => 'gstin',
            ]
        )->assertCreated();

        $docPublicId = (string) $uploadRes->json('data.public_id');

        // 1. Owner A obtains signed URL & downloads cleanly
        $signedRes = $this->withToken($tokenA)
            ->postJson("/api/v1/owner/businesses/{$businessA->public_id}/kyc-documents/{$docPublicId}/signed-url")
            ->assertOk()
            ->assertJsonPath('data.ttl_seconds', 60);

        $ownerSignedUrl = (string) $signedRes->json('data.download_url');

        $downloadRes = $this->withToken($tokenA)->get($ownerSignedUrl);
        $downloadRes->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $downloadRes->headers->get('Cache-Control'));
        $this->assertSame($pdfBytes, $downloadRes->getContent());

        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.signed_url_issued']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.document_downloaded']);

        auth()->forgetGuards();

        // 2. Unauthenticated access to valid signed URL is rejected (401)
        $this->flushHeaders();
        $this->getJson($ownerSignedUrl)->assertUnauthorized();

        // 3. Unauthorized Owner B (even with wildcard token!) cannot list, mint signed URL, replay Owner A's URL, or delete
        $this->withToken($tokenB)->getJson("/api/v1/owner/businesses/{$businessA->public_id}/kyc-documents")->assertForbidden();
        $this->withToken($tokenB)->postJson("/api/v1/owner/businesses/{$businessA->public_id}/kyc-documents/{$docPublicId}/signed-url")->assertForbidden();
        $this->withToken($tokenB)->postJson("/api/v1/owner/businesses/{$businessB->public_id}/kyc-documents/{$docPublicId}/signed-url")->assertForbidden();
        $this->withToken($tokenB)->getJson($ownerSignedUrl)->assertForbidden();
        $this->withToken($tokenB)->deleteJson("/api/v1/owner/businesses/{$businessB->public_id}/kyc-documents/{$docPublicId}")->assertForbidden();

        auth()->forgetGuards();

        // 4. Tampered signature is rejected (403)
        $tamperedUrl = $ownerSignedUrl.'tampered';
        $this->withToken($tokenA)->getJson($tamperedUrl)->assertForbidden();

        auth()->forgetGuards();

        // 5. Authorized kyc_verifier Admin CAN mint signed URL and download private KYC doc
        $adminSignedRes = $this->withToken($adminToken)
            ->postJson("/api/v1/owner/businesses/{$businessA->public_id}/kyc-documents/{$docPublicId}/signed-url")
            ->assertOk();
        $adminSignedUrl = (string) $adminSignedRes->json('data.download_url');
        $this->withToken($adminToken)->get($adminSignedUrl)->assertOk();

        auth()->forgetGuards();

        // 6. Expired signed URL (> 60s) is rejected (403)
        $this->travel(65)->seconds();
        $this->withToken($tokenA)->getJson($ownerSignedUrl)->assertForbidden();

        auth()->forgetGuards();

        // 7. Owner deletes KYC doc -> private file removed & audit logged
        $doc = KycDocument::query()->where('public_id', $docPublicId)->firstOrFail();
        $privateKey = $doc->storage_key;

        $this->withToken($tokenA)
            ->deleteJson("/api/v1/owner/businesses/{$businessA->public_id}/kyc-documents/{$docPublicId}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('kyc_documents', ['public_id' => $docPublicId]);
        Storage::disk('kyc_private')->assertMissing($privateKey);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.document_deleted']);
    }

    private function createOwnedBusiness(User $owner): Business
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

        return $business;
    }

    private function minimalValidPdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\nxref\n0 3\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \ntrailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n107\n%%EOF\n";
    }

    private function makeJpegWithExif(string $name, int $width, int $height): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, 90, 140, 180);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);
        ob_start();
        imagejpeg($img, null, 85);
        $jpeg = (string) ob_get_clean();
        unset($img);

        $exifPayload = "Exif\x00\x00II*\x00\x08\x00\x00\x00\x00\x00\x00\x00KYC_GPS_COORDS_SECRET";
        $app1Segment = "\xFF\xE1".pack('n', strlen($exifPayload) + 2).$exifPayload;
        $jpegWithExif = substr($jpeg, 0, 2).$app1Segment.substr($jpeg, 2);

        return UploadedFile::fake()->createWithContent($name, $jpegWithExif);
    }
}
