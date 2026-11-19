<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pools')) {
            return;
        }

        $columnsToUpdate = [
            'uses_project' => true,
            'has_item_category' => false,
        ];

        $safeUpdates = array_filter(
            $columnsToUpdate,
            fn ($_, string $col) => Schema::hasColumn('pools', $col),
            ARRAY_FILTER_USE_BOTH,
        );

        if (! empty($safeUpdates)) {
            foreach (['P_LPO', 'P_OPO'] as $poolId) {
                DB::table('pools')
                    ->whereRaw('UPPER(TRIM(pool_id)) = ?', [$poolId])
                    ->update($safeUpdates);
            }
        }
    }

    public function down(): void
    {
        // Preset logic in PoolPurchReqRequirements remains the source of truth for these pools.
    }
};
