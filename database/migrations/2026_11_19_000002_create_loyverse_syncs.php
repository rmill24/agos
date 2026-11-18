<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyverse_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedInteger('pages')->default(0);
            $table->text('cursor')->nullable();
            $table->json('cursor_history')->nullable();
            $table->string('source_mapping', 64);
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->string('failure')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status', 'id']);
        });
        Schema::create('loyverse_sync_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyverse_sync_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number')->nullable();
            $table->string('reason')->nullable();
            $table->json('movements')->nullable();
            $table->date('business_date')->nullable();
            $table->unique(['loyverse_sync_id', 'receipt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyverse_sync_entries');
        Schema::dropIfExists('loyverse_syncs');
    }
};
