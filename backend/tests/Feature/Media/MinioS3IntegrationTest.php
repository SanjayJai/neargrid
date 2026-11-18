<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MinioS3IntegrationTest extends TestCase
{
    public function test_live_minio_s3_public_and_private_bucket_separation(): void
    {
        if (config('filesystems.disks.media_public.driver') !== 's3'
            || config('filesystems.disks.kyc_private.driver') !== 's3') {
            $this->markTestSkipped(
                'S3/MinIO integration test skipped: MEDIA_PUBLIC_DRIVER and MEDIA_KYC_DRIVER are not configured to s3 in this environment.'
            );
        }

        $endpoint = (string) config('filesystems.disks.media_public.endpoint', '');
        $parts = parse_url($endpoint);
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? 9000;

        if ($host === '' || @fsockopen($host, (int) $port, $errno, $errstr, 1) === false) {
            $this->markTestSkipped(
                "S3/MinIO integration test skipped: endpoint {$endpoint} is not reachable locally."
            );
        }

        $probeId = (string) Str::ulid();
        $publicKey = "health-probes/{$probeId}.txt";
        $kycKey = "health-probes/{$probeId}.txt";

        Storage::disk('media_public')->put($publicKey, 'public-probe', ['visibility' => 'public']);
        Storage::disk('kyc_private')->put($kycKey, 'private-probe', ['visibility' => 'private']);

        try {
            $this->assertTrue(Storage::disk('media_public')->exists($publicKey));
            $this->assertTrue(Storage::disk('kyc_private')->exists($kycKey));
            $this->assertSame('public-probe', Storage::disk('media_public')->get($publicKey));
            $this->assertSame('private-probe', Storage::disk('kyc_private')->get($kycKey));
        } finally {
            Storage::disk('media_public')->delete($publicKey);
            Storage::disk('kyc_private')->delete($kycKey);
        }
    }
}
