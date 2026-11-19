<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures the legacy UNIQUE(pool_id) index is gone so the same pool_id can exist per company.
 * The earlier migration (2026_05_12_120000_pools_unique_per_company) only drops an index named
 * pools_pool_id_unique; some hosts keep a differently named unique index — this migration removes
 * any single-column unique index on pool_id, then ensures UNIQUE(company_id, pool_id) exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pools')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $indexNames = DB::select("
                SELECT INDEX_NAME
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'pools'
                  AND INDEX_NAME != 'PRIMARY'
                  AND NON_UNIQUE = 0
                GROUP BY INDEX_NAME
                HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = 'pool_id'
            ");
            foreach ($indexNames as $row) {
                $arr = (array) $row;
                $name = $arr['INDEX_NAME'] ?? $arr['index_name'] ?? null;
                if (! is_string($name) || $name === '') {
                    continue;
                }
                DB::statement('ALTER TABLE `pools` DROP INDEX `'.str_replace('`', '``', $name).'`');
            }
        } else {
            // Find any single-column unique index on pool_id and drop it by name
            // to avoid aborting the PostgreSQL transaction on a failed DROP.
            $singleColPoolIdUnique = collect(Schema::getIndexes('pools'))
                ->first(function (array $index) {
                    return (bool) $index['unique']
                        && ! (bool) $index['primary']
                        && $index['columns'] === ['pool_id'];
                });

            if ($singleColPoolIdUnique) {
                Schema::table('pools', function (Blueprint $table) use ($singleColPoolIdUnique) {
                    $table->dropIndex($singleColPoolIdUnique['name']);
                });
            }
        }

        if (! Schema::hasIndex('pools', 'pools_company_id_pool_id_unique')) {
            Schema::table('pools', function (Blueprint $table) {
                $table->unique(['company_id', 'pool_id']);
            });
        }
    }

    public function down(): void
    {
        //
    }
};
