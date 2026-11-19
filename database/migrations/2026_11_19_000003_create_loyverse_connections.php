<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyverse_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->string('merchant_id');
            $table->string('store_id');
            $table->json('collected_method_ids')->nullable();
            $table->timestampTz('verified_at', 6)->nullable();
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyverse_connections');
    }
};
