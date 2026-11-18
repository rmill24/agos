<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyverse_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number');
            $table->string('merchant_id');
            $table->string('store_id');
            $table->string('currency_code', 3);
            $table->timestampTz('source_updated_at', 6);
            $table->timestampTz('synced_at', 6);
            $table->json('receipt');
            $table->unique(['team_id', 'receipt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyverse_receipts');
    }
};
