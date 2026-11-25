<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/**
 * Truncates MySQL tables outside transactions for FULLTEXT tests while skipping setup on non-MySQL drivers.
 */
trait TruncatesMysqlDatabase
{
    use DatabaseTruncation {
        truncateDatabaseTables as protected baseTruncateDatabaseTables;
    }

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->baseTruncateDatabaseTables();

        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                $this->truncateTablesForAllConnections();
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });
    }
}
