<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // migrate:fresh drops PostgreSQL tables but retains standalone functions.
        // Run before the original creation migration and leave installed triggers intact.
        if (DB::getDriverName() === 'pgsql' && ! Schema::hasTable('report_snapshots')) {
            DB::statement('DROP FUNCTION IF EXISTS reject_report_snapshot_change() RESTRICT');
        }
    }

    public function down(): void
    {
        // Only an orphan left by a previous reset can have been removed.
    }
};
