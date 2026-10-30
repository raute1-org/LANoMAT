<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Data-safe: existing production data may already hold duplicate team
        // names. Never delete or rename anything automatically; fail loudly so
        // the duplicates are resolved by hand before the index is created.
        $duplicates = DB::table('teams')
            ->selectRaw('lower(name) as lowered, count(*) as total')
            ->groupByRaw('lower(name)')
            ->havingRaw('count(*) > 1')
            ->pluck('lowered')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot add the unique team-name index: these team names exist more than once (case-insensitive): '
                .implode(', ', $duplicates)
                .'. Rename or merge the duplicate teams (Admin > Teams), then run `php artisan migrate` again.',
            );
        }

        DB::statement('CREATE UNIQUE INDEX teams_name_lower_unique ON teams (lower(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS teams_name_lower_unique');
    }
};
