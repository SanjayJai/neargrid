<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Promotions;

use App\Domains\Promotions\Services\PromoNoticeTrackingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PromoNoticeConcurrencyTest extends TestCase
{
    public function test_concurrent_workers_recording_promo_impressions_never_exceed_cap_or_log_rejected_events(): void
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

        $noticePublicId = strtoupper(Str::random(26));
        $localePublicId = strtoupper(Str::random(26));
        $noticeId = null;

        try {
            $pdo->prepare("INSERT INTO promo_notices (public_id, kind, notice_type, display_format, severity, status, is_enabled, priority, trigger_type, is_dismissible, cooldown_hours, max_total_impressions, impressions_count, clicks_count, dismissals_count, created_at, updated_at) VALUES (?, 'popup', 'promo', 'modal', 'promo', 'active', 1, 100, 'page_load', 1, 24, 2, 0, 0, 0, NOW(), NOW())")
                ->execute([$noticePublicId]);
            $noticeId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO promo_notice_locales (public_id, promo_notice_id, locale, title, destination_url, created_at, updated_at) VALUES (?, ?, 'en', 'Concurrent Promo', '/chennai', NOW(), NOW())")
                ->execute([$localePublicId, $noticeId]);

            $tracking = app(PromoNoticeTrackingService::class);
            $token = $tracking->mintToken([
                'nid' => $noticePublicId,
                'lid' => $localePublicId,
                'dev' => 'desktop',
                'aud' => 'guest',
            ]);

            $workerScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$svc = $app->make(App\Domains\Promotions\Services\PromoNoticeTrackingService::class);
$res = $svc->recordEvent($argv[1], 'impression', 'Mozilla/5.0', '10.20.0.' . $argv[2], 'promo-worker-' . $argv[2]);
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

            $impressions = (int) $pdo->query("SELECT impressions_count FROM promo_notices WHERE id = {$noticeId}")->fetchColumn();
            $this->assertSame(2, $impressions);

            $logCount = (int) $pdo->query("SELECT COUNT(*) FROM promo_notice_events WHERE promo_notice_id = {$noticeId}")->fetchColumn();
            $this->assertSame(2, $logCount);
        } finally {
            if ($noticeId !== null) {
                $pdo->prepare('DELETE FROM promo_notice_events WHERE promo_notice_id = ?')->execute([$noticeId]);
                $pdo->prepare('DELETE FROM promo_notice_locales WHERE promo_notice_id = ?')->execute([$noticeId]);
                $pdo->prepare('DELETE FROM promo_notices WHERE id = ?')->execute([$noticeId]);
            }
        }
    }

    public function test_concurrent_workers_enforce_per_user_frequency_cap_and_dismissal_cooldown(): void
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

        $noticePublicId = strtoupper(Str::random(26));
        $localePublicId = strtoupper(Str::random(26));
        $noticeId = null;

        try {
            $pdo->prepare("INSERT INTO promo_notices (public_id, kind, notice_type, display_format, severity, status, is_enabled, priority, trigger_type, is_dismissible, cooldown_hours, max_displays_per_user, impressions_count, clicks_count, dismissals_count, created_at, updated_at) VALUES (?, 'popup', 'welcome', 'modal', 'promo', 'active', 1, 100, 'page_load', 1, 24, 1, 0, 0, 0, NOW(), NOW())")
                ->execute([$noticePublicId]);
            $noticeId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO promo_notice_locales (public_id, promo_notice_id, locale, title, destination_url, created_at, updated_at) VALUES (?, ?, 'en', 'User Cap Promo', '/chennai', NOW(), NOW())")
                ->execute([$localePublicId, $noticeId]);

            $tracking = app(PromoNoticeTrackingService::class);
            $token = $tracking->mintToken([
                'nid' => $noticePublicId,
                'lid' => $localePublicId,
                'dev' => 'desktop',
                'aud' => 'customer',
            ]);

            $workerScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$svc = $app->make(App\Domains\Promotions\Services\PromoNoticeTrackingService::class);
$res = $svc->recordEvent($argv[1], 'impression', 'Mozilla/5.0', '10.30.0.' . $argv[2], 'user-sess-' . $argv[2], 999001);
echo json_encode($res);
PHP;

            // 4 concurrent workers with distinct sessions but SAME userId=999001
            $workers = [];
            for ($i = 1; $i <= 4; $i++) {
                $proc = new Process([PHP_BINARY, '-r', $workerScript, $token, (string) $i], base_path());
                $proc->start();
                $workers[] = $proc;
            }

            $recordedCount = 0;
            $userCapRejected = 0;
            foreach ($workers as $proc) {
                $proc->wait();
                $this->assertSame(0, $proc->getExitCode(), $proc->getErrorOutput());
                $out = json_decode(trim($proc->getOutput()), true);
                if (($out['recorded'] ?? false) === true) {
                    $recordedCount++;
                } elseif (($out['reason'] ?? null) === 'user_cap_reached') {
                    $userCapRejected++;
                }
            }

            $this->assertSame(1, $recordedCount);
            $this->assertSame(3, $userCapRejected);
            $this->assertSame(1, (int) $pdo->query("SELECT impressions_count FROM promo_notices WHERE id = {$noticeId}")->fetchColumn());
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM promo_notice_events WHERE promo_notice_id = {$noticeId}")->fetchColumn());
        } finally {
            if ($noticeId !== null) {
                $pdo->prepare('DELETE FROM promo_notice_events WHERE promo_notice_id = ?')->execute([$noticeId]);
                $pdo->prepare('DELETE FROM promo_notice_locales WHERE promo_notice_id = ?')->execute([$noticeId]);
                $pdo->prepare('DELETE FROM promo_notices WHERE id = ?')->execute([$noticeId]);
            }
        }
    }

    public function test_concurrent_workers_enforce_max_displays_per_session_and_dismissal_cooldown(): void
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

        $sessNoticePublicId = strtoupper(Str::random(26));
        $sessLocalePublicId = strtoupper(Str::random(26));
        $coolNoticePublicId = strtoupper(Str::random(26));
        $coolLocalePublicId = strtoupper(Str::random(26));
        $sessNoticeId = null;
        $coolNoticeId = null;

        try {
            // 1) max_displays_per_session = 1 with 4 concurrent workers on SAME session
            $pdo->prepare("INSERT INTO promo_notices (public_id, kind, notice_type, display_format, severity, status, is_enabled, priority, trigger_type, is_dismissible, cooldown_hours, max_displays_per_session, impressions_count, clicks_count, dismissals_count, created_at, updated_at) VALUES (?, 'popup', 'welcome', 'modal', 'promo', 'active', 1, 100, 'page_load', 1, 24, 1, 0, 0, 0, NOW(), NOW())")
                ->execute([$sessNoticePublicId]);
            $sessNoticeId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO promo_notice_locales (public_id, promo_notice_id, locale, title, destination_url, created_at, updated_at) VALUES (?, ?, 'en', 'Session Cap Promo', '/chennai', NOW(), NOW())")
                ->execute([$sessLocalePublicId, $sessNoticeId]);

            $tracking = app(PromoNoticeTrackingService::class);
            $sessToken = $tracking->mintToken([
                'nid' => $sessNoticePublicId,
                'lid' => $sessLocalePublicId,
                'dev' => 'desktop',
                'aud' => 'guest',
            ]);

            $workerScript = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$svc = $app->make(App\Domains\Promotions\Services\PromoNoticeTrackingService::class);
$res = $svc->recordEvent($argv[1], 'impression', 'Mozilla/5.0', '10.40.0.' . $argv[2], $argv[3]);
echo json_encode($res);
PHP;

            $workers = [];
            for ($i = 1; $i <= 4; $i++) {
                $proc = new Process([PHP_BINARY, '-r', $workerScript, $sessToken, (string) $i, 'shared-session-abc'], base_path());
                $proc->start();
                $workers[] = $proc;
            }

            $recorded = 0;
            $rejected = 0;
            foreach ($workers as $proc) {
                $proc->wait();
                $this->assertSame(0, $proc->getExitCode(), $proc->getErrorOutput());
                $out = json_decode(trim($proc->getOutput()), true);
                if (($out['recorded'] ?? false) === true) {
                    $recorded++;
                } elseif (in_array($out['reason'] ?? null, ['session_cap_reached', 'duplicate'], true)) {
                    $rejected++;
                }
            }

            $this->assertSame(1, $recorded);
            $this->assertSame(3, $rejected);
            $this->assertSame(1, (int) $pdo->query("SELECT impressions_count FROM promo_notices WHERE id = {$sessNoticeId}")->fetchColumn());
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM promo_notice_events WHERE promo_notice_id = {$sessNoticeId}")->fetchColumn());

            // 2) cooldown_hours = 24 after dismiss: 4 concurrent impression workers must all be rejected
            $pdo->prepare("INSERT INTO promo_notices (public_id, kind, notice_type, display_format, severity, status, is_enabled, priority, trigger_type, is_dismissible, cooldown_hours, impressions_count, clicks_count, dismissals_count, created_at, updated_at) VALUES (?, 'popup', 'promo', 'modal', 'promo', 'active', 1, 100, 'page_load', 1, 24, 0, 0, 0, NOW(), NOW())")
                ->execute([$coolNoticePublicId]);
            $coolNoticeId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO promo_notice_locales (public_id, promo_notice_id, locale, title, destination_url, created_at, updated_at) VALUES (?, ?, 'en', 'Cooldown Promo', '/chennai', NOW(), NOW())")
                ->execute([$coolLocalePublicId, $coolNoticeId]);

            $coolToken = $tracking->mintToken([
                'nid' => $coolNoticePublicId,
                'lid' => $coolLocalePublicId,
                'dev' => 'desktop',
                'aud' => 'guest',
            ]);

            $dismissRes = $tracking->recordEvent($coolToken, 'dismiss', 'Mozilla/5.0', '10.40.0.10', 'cooldown-sess-1');
            $this->assertTrue($dismissRes['recorded']);

            $coolWorkers = [];
            for ($i = 1; $i <= 4; $i++) {
                $proc = new Process([PHP_BINARY, '-r', $workerScript, $coolToken, (string) $i, 'cooldown-sess-1'], base_path());
                $proc->start();
                $coolWorkers[] = $proc;
            }

            $coolRejected = 0;
            foreach ($coolWorkers as $proc) {
                $proc->wait();
                $this->assertSame(0, $proc->getExitCode(), $proc->getErrorOutput());
                $out = json_decode(trim($proc->getOutput()), true);
                $this->assertFalse($out['recorded'] ?? true);
                if (($out['reason'] ?? null) === 'dismissed_cooldown') {
                    $coolRejected++;
                }
            }

            $this->assertSame(4, $coolRejected);
            $this->assertSame(0, (int) $pdo->query("SELECT impressions_count FROM promo_notices WHERE id = {$coolNoticeId}")->fetchColumn());
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM promo_notice_events WHERE promo_notice_id = {$coolNoticeId} AND event_type = 'impression'")->fetchColumn());
        } finally {
            foreach ([$sessNoticeId, $coolNoticeId] as $id) {
                if ($id !== null) {
                    $pdo->prepare('DELETE FROM promo_notice_events WHERE promo_notice_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM promo_notice_locales WHERE promo_notice_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM promo_notices WHERE id = ?')->execute([$id]);
                }
            }
        }
    }
}
