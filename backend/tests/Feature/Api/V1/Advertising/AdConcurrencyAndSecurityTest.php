<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Advertising;

use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdCreative;
use App\Domains\Advertising\Models\AdEventLog;
use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Advertising\Models\AdTargetingRule;
use App\Domains\Advertising\Services\AdTrackingService;
use App\Domains\Domains\Models\Domain;
use App\Domains\Location\Models\City;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AdConcurrencyAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_delivery_does_not_record_impressions_and_enforces_cap_and_utc_reset(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $placement = AdPlacement::query()->where('key', 'home.hero_banner')->firstOrFail();

        $campaign = AdCampaign::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Cap & Reset Campaign',
            'campaign_type' => 'house_promo',
            'status' => 'active',
            'daily_impression_cap' => 2,
            'total_impression_cap' => 10,
            'impressions_today' => 2,
            'impressions_today_date' => CarbonImmutable::now('UTC')->subDay()->startOfDay(),
            'total_impressions' => 5,
        ]);
        $campaign->placements()->attach($placement->id, ['weight' => 100, 'is_active' => true]);

        $creative = AdCreative::query()->create([
            'ad_campaign_id' => $campaign->id,
            'title' => 'Hero Banner',
            'destination_url' => '/chennai',
            'locale' => 'en',
            'status' => 'active',
        ]);

        // 1) Delivery must NOT record impressions
        $deliver = $this->getJson('/api/v1/public/ads/deliver?'.http_build_query([
            'page_type' => 'home',
            'placement_keys' => ['home.hero_banner'],
        ]))->assertOk();

        $this->assertSame(0, AdEventLog::query()->count());
        $campaign->refresh();
        $this->assertSame(5, $campaign->total_impressions);

        $placements = $deliver->json('data.placements');
        $token = (string) $placements['home.hero_banner']['ads'][0]['tracking_token'];

        // 2) First impression today (UTC) resets impressions_today from yesterday's 2 to 1
        $this->withHeader('User-Agent', 'Mozilla/5.0')
            ->postJson('/api/v1/public/ads/impression', ['token' => $token, 'session_id' => 'sess-a'])
            ->assertOk()
            ->assertJsonPath('data.recorded', true);

        $campaign->refresh();
        $this->assertSame(1, $campaign->impressions_today);
        $this->assertSame(CarbonImmutable::now('UTC')->toDateString(), $campaign->impressions_today_date?->toDateString());
        $this->assertSame(6, $campaign->total_impressions);

        // 3) Second distinct session hits daily cap = 2
        $this->withHeader('User-Agent', 'Mozilla/5.0')
            ->postJson('/api/v1/public/ads/impression', ['token' => $token, 'session_id' => 'sess-b'])
            ->assertOk()
            ->assertJsonPath('data.recorded', true);

        // 4) Third distinct session is blocked by in-lock cap check (never exceeds cap!)
        $this->withHeader('User-Agent', 'Mozilla/5.0')
            ->postJson('/api/v1/public/ads/impression', ['token' => $token, 'session_id' => 'sess-c'])
            ->assertOk()
            ->assertJsonPath('data.recorded', false)
            ->assertJsonPath('data.reason', 'cap_reached');

        $campaign->refresh();
        $this->assertSame(2, $campaign->impressions_today);
        $this->assertSame(7, $campaign->total_impressions);

        // 5) Paused / archived / expired / unassigned placement checks
        $campaign->status = 'paused';
        $campaign->save();
        $pausedDeliver = $this->getJson('/api/v1/public/ads/deliver?'.http_build_query([
            'page_type' => 'home',
            'placement_keys' => ['home.hero_banner'],
        ]))->assertOk();
        $this->assertEmpty($pausedDeliver->json('data.placements'));

        // Expired token rejected
        $tracking = app(AdTrackingService::class);
        $expiredPayload = base64_encode((string) json_encode([
            'cid' => $campaign->public_id,
            'crid' => $creative->public_id,
            'pid' => $placement->public_id,
            'exp' => time() - 60,
        ]));
        $expiredSig = hash_hmac('sha256', $expiredPayload, (string) config('app.key'));
        $this->postJson('/api/v1/public/ads/impression', [
            'token' => "{$expiredPayload}.{$expiredSig}",
        ])->assertStatus(422);
        $this->assertNotNull($tracking);
    }

    public function test_redis_unavailable_falls_back_to_db_dedup_and_spoofed_host_is_ignored(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $city = City::query()->where('slug', 'chennai')->firstOrFail();
        $placement = AdPlacement::query()->where('key', 'home.below_search_strip')->firstOrFail();

        $domain = Domain::query()->create([
            'hostname' => 'chennai.neargrid.test',
            'domain_type' => 'city_portal',
            'status' => 'active',
            'verification_status' => 'verified',
            'visibility_mode' => 'public',
            'default_locale' => 'en',
            'default_city_id' => $city->id,
        ]);

        $domainCampaign = AdCampaign::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Domain Scoped',
            'campaign_type' => 'house_promo',
            'status' => 'active',
        ]);
        $domainCampaign->placements()->attach($placement->id, ['weight' => 100, 'is_active' => true]);
        AdTargetingRule::query()->create([
            'ad_campaign_id' => $domainCampaign->id,
            'target_type' => 'domain',
            'domain_id' => $domain->id,
            'is_exclude' => false,
        ]);
        AdCreative::query()->create([
            'ad_campaign_id' => $domainCampaign->id,
            'title' => 'Portal Strip',
            'destination_url' => '/chennai',
            'locale' => 'en',
            'status' => 'active',
        ]);

        // Spoofed host must NOT match domain-targeted campaign
        $spoofRes = $this->getJson('/api/v1/public/ads/deliver?'.http_build_query([
            'page_type' => 'home',
            'placement_keys' => ['home.below_search_strip'],
            'host' => 'evil.attacker.com',
        ]))->assertOk();
        $this->assertEmpty($spoofRes->json('data.placements'));

        // Valid verified host matches
        $validRes = $this->getJson('/api/v1/public/ads/deliver?'.http_build_query([
            'page_type' => 'home',
            'placement_keys' => ['home.below_search_strip'],
            'host' => 'chennai.neargrid.test',
        ]))->assertOk();
        $token = (string) $validRes->json('data.placements')['home.below_search_strip']['ads'][0]['tracking_token'];

        // Simulate Redis outage during tracking
        Cache::shouldReceive('has')->andThrow(new RuntimeException('Redis connection refused'));
        Cache::shouldReceive('put')->andThrow(new RuntimeException('Redis connection refused'));

        $this->withHeader('User-Agent', 'Mozilla/5.0')
            ->postJson('/api/v1/public/ads/impression', ['token' => $token, 'session_id' => 'redis-down-1'])
            ->assertOk()
            ->assertJsonPath('data.recorded', true);

        // DB dedup still suppresses repeat
        $this->withHeader('User-Agent', 'Mozilla/5.0')
            ->postJson('/api/v1/public/ads/impression', ['token' => $token, 'session_id' => 'redis-down-1'])
            ->assertOk()
            ->assertJsonPath('data.recorded', false)
            ->assertJsonPath('data.reason', 'duplicate');
    }

    public function test_innodb_row_lock_serializes_concurrent_campaign_cap_updates(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("InnoDB row lock concurrency test requires MySQL/MariaDB (active: {$driver}).");
        }

        $config = config('database.connections.'.config('database.default'));
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database']
        );

        $pdoA = new PDO($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdoB = new PDO($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $userPublicId = strtoupper(Str::random(26));
        $phone = '+9199'.random_int(10000000, 99999999);
        $publicId = strtoupper(Str::random(26));
        $userId = null;
        $campaignId = null;

        try {
            $userStmt = $pdoA->prepare(
                'INSERT INTO users (public_id, name, phone_e164, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())'
            );
            $userStmt->execute([$userPublicId, 'Ad Lock Test User', $phone, 'active']);
            $userId = (int) $pdoA->lastInsertId();

            $stmt = $pdoA->prepare(
                "INSERT INTO ad_campaigns (public_id, created_by_user_id, name, campaign_type, status, priority, daily_impression_cap, impressions_today, total_impressions, total_clicks, created_at, updated_at) VALUES (?, ?, 'Lock Test', 'house_promo', 'active', 100, 1, 0, 0, 0, NOW(), NOW())"
            );
            $stmt->execute([$publicId, $userId]);
            $campaignId = (int) $pdoA->lastInsertId();

            $pdoA->beginTransaction();
            $lockA = $pdoA->prepare('SELECT id, impressions_today FROM ad_campaigns WHERE id = ? FOR UPDATE');
            $lockA->execute([$campaignId]);
            $this->assertSame($campaignId, (int) $lockA->fetchColumn());

            $pdoB->exec('SET innodb_lock_wait_timeout = 1');
            $pdoB->beginTransaction();
            $timedOut = false;
            try {
                $lockB = $pdoB->prepare('SELECT id, impressions_today FROM ad_campaigns WHERE id = ? FOR UPDATE');
                $lockB->execute([$campaignId]);
            } catch (PDOException $e) {
                $timedOut = str_contains($e->getMessage(), '1205') || str_contains($e->getMessage(), 'Lock wait timeout');
            }
            $pdoB->rollBack();
            $pdoA->commit();

            $this->assertTrue($timedOut, 'Expected Connection B FOR UPDATE on ad_campaigns to block until timeout.');
        } finally {
            if ($campaignId !== null) {
                $pdoA->prepare('DELETE FROM ad_campaigns WHERE id = ?')->execute([$campaignId]);
            }
            if ($userId !== null) {
                $pdoA->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            }
        }
    }

    public function test_concurrent_workers_calling_ad_tracking_service_never_exceed_cap_or_log_rejected_events(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("Concurrent worker test requires MySQL/MariaDB (active: {$driver}).");
        }

        $config = config('database.connections.'.config('database.default'));
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database']
        );

        $pdo = new PDO($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $userPublicId = strtoupper(Str::random(26));
        $campaignPublicId = strtoupper(Str::random(26));
        $creativePublicId = strtoupper(Str::random(26));
        $placementPublicId = strtoupper(Str::random(26));
        $placementKey = 'test.concurrent.'.strtolower(Str::random(8));
        $phone = '+9198'.random_int(10000000, 99999999);

        $userId = null;
        $campaignId = null;
        $placementId = null;

        try {
            $pdo->prepare('INSERT INTO users (public_id, name, phone_e164, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
                ->execute([$userPublicId, 'Concurrent Worker User', $phone, 'active']);
            $userId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO ad_placements (public_id, `key`, name, page_type, route_pattern, slot_position, render_format, device_scope, audience_scope, max_ads, is_active, sort_order, created_at, updated_at) VALUES (?, ?, 'Concurrent Slot', 'home', '/', 'concurrent_test', 'banner', 'all', 'all', 1, 1, 999, NOW(), NOW())")
                ->execute([$placementPublicId, $placementKey]);
            $placementId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO ad_campaigns (public_id, created_by_user_id, name, campaign_type, status, priority, daily_impression_cap, total_impression_cap, impressions_today, total_impressions, total_clicks, created_at, updated_at) VALUES (?, ?, 'Concurrent Cap Test', 'house_promo', 'active', 100, 2, 2, 0, 0, 0, NOW(), NOW())")
                ->execute([$campaignPublicId, $userId]);
            $campaignId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO ad_campaign_placements (ad_campaign_id, ad_placement_id, weight, is_active, created_at, updated_at) VALUES (?, ?, 100, 1, NOW(), NOW())')
                ->execute([$campaignId, $placementId]);

            $pdo->prepare("INSERT INTO ad_creatives (public_id, ad_campaign_id, title, destination_url, locale, status, created_at, updated_at) VALUES (?, ?, 'Concurrent Ad', '/chennai', 'en', 'active', NOW(), NOW())")
                ->execute([$creativePublicId, $campaignId]);

            $tracking = app(AdTrackingService::class);
            $token = $tracking->mintToken([
                'cid' => $campaignPublicId,
                'crid' => $creativePublicId,
                'pid' => $placementPublicId,
                'dev' => 'desktop',
                'aud' => 'guest',
            ]);

            $workerScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$svc = $app->make(App\Domains\Advertising\Services\AdTrackingService::class);
$res = $svc->recordEvent($argv[1], 'impression', 'Mozilla/5.0', '10.10.0.' . $argv[2], 'concurrent-sess-' . $argv[2]);
echo json_encode($res);
PHP;

            $workers = [];
            for ($i = 1; $i <= 4; $i++) {
                $proc = new Process([PHP_BINARY, '-r', $workerScript, $token, (string) $i], base_path());
                $proc->start();
                $workers[] = $proc;
            }

            $recordedCount = 0;
            $capRejectedCount = 0;
            foreach ($workers as $proc) {
                $proc->wait();
                $this->assertSame(0, $proc->getExitCode(), $proc->getErrorOutput());
                $out = json_decode(trim($proc->getOutput()), true);
                $this->assertIsArray($out);
                if (($out['recorded'] ?? false) === true) {
                    $recordedCount++;
                } elseif (($out['reason'] ?? null) === 'cap_reached') {
                    $capRejectedCount++;
                }
            }

            $this->assertSame(2, $recordedCount);
            $this->assertSame(2, $capRejectedCount);

            $row = $pdo->query("SELECT impressions_today, total_impressions FROM ad_campaigns WHERE id = {$campaignId}")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(2, (int) $row['impressions_today']);
            $this->assertSame(2, (int) $row['total_impressions']);

            $logCount = (int) $pdo->query("SELECT COUNT(*) FROM ad_event_logs WHERE ad_campaign_id = {$campaignId}")->fetchColumn();
            $this->assertSame(2, $logCount);
        } finally {
            if ($campaignId !== null) {
                $pdo->prepare('DELETE FROM ad_event_logs WHERE ad_campaign_id = ?')->execute([$campaignId]);
                $pdo->prepare('DELETE FROM ad_campaigns WHERE id = ?')->execute([$campaignId]);
            }
            if ($placementId !== null) {
                $pdo->prepare('DELETE FROM ad_placements WHERE id = ?')->execute([$placementId]);
            }
            if ($userId !== null) {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            }
        }
    }
}
