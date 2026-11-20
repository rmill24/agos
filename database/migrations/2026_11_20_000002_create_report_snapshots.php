<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->timestampTz('created_at');
            $table->json('data');
            $table->longText('html');
            $table->index(['team_id', 'id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION reject_report_snapshot_change() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Report snapshots are immutable'; END; $$");
            DB::statement('CREATE TRIGGER report_snapshots_immutable BEFORE UPDATE OR DELETE ON report_snapshots FOR EACH ROW EXECUTE FUNCTION reject_report_snapshot_change()');
        } else {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER report_snapshots_no_$operation BEFORE $operation ON report_snapshots BEGIN SELECT RAISE(ABORT, 'Report snapshots are immutable'); END");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION reject_report_snapshot_change()');
        }
    }
};
