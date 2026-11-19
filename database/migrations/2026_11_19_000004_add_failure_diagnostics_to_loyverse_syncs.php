<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyverse_syncs', function (Blueprint $table) {
            $table->string('failure_code', 40)->nullable()->after('failure');
            $table->uuid('failure_reference')->nullable()->after('failure_code');
        });
    }

    public function down(): void
    {
        Schema::table('loyverse_syncs', function (Blueprint $table) {
            $table->dropColumn(['failure_code', 'failure_reference']);
        });
    }
};
