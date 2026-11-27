<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

final class AdminConcurrencyTest extends TestCase
{
    public function test_mariadb_innodb_row_locks_serialize_concurrent_super_admin_and_erasure_transactions(): void
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

        $publicId = strtoupper(Str::random(26));
        $erasurePublicId = strtoupper(Str::random(26));
        $phone = '+9199'.random_int(10000000, 99999999);
        $userId = null;
        $erasureId = null;

        try {
            // Insert committed rows visible to both independent InnoDB connections
            $stmt = $pdoA->prepare(
                'INSERT INTO users (public_id, name, phone_e164, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$publicId, 'Concurrency Test User', $phone, 'active']);
            $userId = (int) $pdoA->lastInsertId();

            $stmt2 = $pdoA->prepare(
                'INSERT INTO account_erasure_requests (public_id, user_id, status, reason, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt2->execute([$erasurePublicId, $userId, 'pending', 'Concurrency lock test']);
            $erasureId = (int) $pdoA->lastInsertId();

            // 1) Verify InnoDB FOR UPDATE on users blocks Connection B while Connection A holds the lock
            $pdoA->beginTransaction();
            $lockUserA = $pdoA->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lockUserA->execute([$userId]);
            $this->assertSame($userId, (int) $lockUserA->fetchColumn());

            $pdoB->exec('SET innodb_lock_wait_timeout = 1');
            $pdoB->beginTransaction();
            $timedOut = false;
            try {
                $lockUserB = $pdoB->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
                $lockUserB->execute([$userId]);
            } catch (PDOException $e) {
                $timedOut = str_contains($e->getMessage(), '1205') || str_contains($e->getMessage(), 'Lock wait timeout');
            }
            $pdoB->rollBack();
            $pdoA->commit();

            $this->assertTrue($timedOut, 'Expected Connection B FOR UPDATE on users to hit InnoDB lock wait timeout (1205).');

            // 2) Verify InnoDB FOR UPDATE on account_erasure_requests blocks Connection B while Connection A holds the lock
            $pdoA->beginTransaction();
            $lockErasureA = $pdoA->prepare('SELECT id, status FROM account_erasure_requests WHERE id = ? FOR UPDATE');
            $lockErasureA->execute([$erasureId]);
            $rowA = $lockErasureA->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('pending', $rowA['status']);

            $pdoB->beginTransaction();
            $erasureTimedOut = false;
            try {
                $lockErasureB = $pdoB->prepare('SELECT id, status FROM account_erasure_requests WHERE id = ? FOR UPDATE');
                $lockErasureB->execute([$erasureId]);
            } catch (PDOException $e) {
                $erasureTimedOut = str_contains($e->getMessage(), '1205') || str_contains($e->getMessage(), 'Lock wait timeout');
            }
            $pdoB->rollBack();
            $pdoA->commit();

            $this->assertTrue($erasureTimedOut, 'Expected Connection B FOR UPDATE on account_erasure_requests to hit InnoDB lock wait timeout (1205).');
        } finally {
            if ($pdoA->inTransaction()) {
                $pdoA->rollBack();
            }
            if ($pdoB->inTransaction()) {
                $pdoB->rollBack();
            }
            if ($erasureId !== null) {
                $pdoA->prepare('DELETE FROM account_erasure_requests WHERE id = ?')->execute([$erasureId]);
            }
            if ($userId !== null) {
                $pdoA->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            }
        }
    }
}
