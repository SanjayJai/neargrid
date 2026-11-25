<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const LOCATION_TABLES = ['states', 'cities', 'localities'];

    public function up(): void
    {
        $driver = DB::getDriverName();

        foreach (self::LOCATION_TABLES as $table) {
            // 1. Backfill any unexpected NULL or blank public_id without touching existing ULIDs.
            DB::table($table)
                ->whereNull('public_id')
                ->orWhere('public_id', '')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table): void {
                    foreach ($rows as $row) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['public_id' => (string) Str::ulid()]);
                    }
                });

            // 2. Verify zero NULL/blank or duplicate public_ids exist before enforcing NOT NULL.
            $hasNulls = DB::table($table)
                ->whereNull('public_id')
                ->orWhere('public_id', '')
                ->exists();

            if ($hasNulls) {
                throw new RuntimeException("Cannot enforce NOT NULL on {$table}.public_id: NULL values still exist.");
            }

            $hasDuplicates = DB::table($table)
                ->select('public_id')
                ->groupBy('public_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($hasDuplicates) {
                throw new RuntimeException("Cannot enforce NOT NULL on {$table}.public_id: duplicate values detected.");
            }

            // 3. Enforce database-level NOT NULL cleanly per driver without destructive table rebuilds.
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `public_id` CHAR(26) NOT NULL");
            } elseif ($driver === 'sqlite') {
                DB::statement(
                    "CREATE TRIGGER IF NOT EXISTS `trg_{$table}_public_id_not_null_ins`
                     BEFORE INSERT ON `{$table}`
                     FOR EACH ROW
                     WHEN NEW.`public_id` IS NULL OR LENGTH(TRIM(NEW.`public_id`)) = 0
                     BEGIN
                         SELECT RAISE(ABORT, 'NOT NULL constraint failed: {$table}.public_id');
                     END;"
                );

                DB::statement(
                    "CREATE TRIGGER IF NOT EXISTS `trg_{$table}_public_id_not_null_upd`
                     BEFORE UPDATE ON `{$table}`
                     FOR EACH ROW
                     WHEN NEW.`public_id` IS NULL OR LENGTH(TRIM(NEW.`public_id`)) = 0
                     BEGIN
                         SELECT RAISE(ABORT, 'NOT NULL constraint failed: {$table}.public_id');
                     END;"
                );
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        foreach (self::LOCATION_TABLES as $table) {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `public_id` CHAR(26) NULL");
            } elseif ($driver === 'sqlite') {
                DB::statement("DROP TRIGGER IF EXISTS `trg_{$table}_public_id_not_null_ins`");
                DB::statement("DROP TRIGGER IF EXISTS `trg_{$table}_public_id_not_null_upd`");
            }
        }
    }
};
