<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyverse_connections', function (Blueprint $table) {
            $table->dropForeign(['sales_category_id']);
            $table->dropForeign(['refund_category_id']);
            $table->dropColumn(['sales_category_id', 'refund_category_id']);
        });
    }

    public function down(): void
    {
        Schema::table('loyverse_connections', function (Blueprint $table) {
            $table->foreignId('sales_category_id')->nullable()->after('collected_method_ids')
                ->constrained('categories')->restrictOnDelete();
            $table->foreignId('refund_category_id')->nullable()->after('sales_category_id')
                ->constrained('categories')->restrictOnDelete();
        });
    }
};
