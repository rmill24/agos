<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->decimal('opening_cash_balance', 15, 2)->nullable()->after('cash_safety_threshold');
            $table->date('opening_cash_balance_date')->nullable()->after('opening_cash_balance');
            $table->timestamp('opening_cash_balance_verified_at')->nullable()->after('opening_cash_balance_date');
            $table->foreignId('opening_cash_balance_verified_by')->nullable()->after('opening_cash_balance_verified_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_cash_balance_verified_by');
            $table->dropColumn(['opening_cash_balance', 'opening_cash_balance_date', 'opening_cash_balance_verified_at']);
        });
    }
};
