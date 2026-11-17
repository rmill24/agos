<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 10);
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date');
            $table->text('description');
            $table->string('source_type', 30)->default('manual');
            $table->string('source_reference')->nullable();
            $table->string('external_id')->nullable();
            $table->string('currency_code', 3)->default('PHP');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'source_type', 'external_id']);
            $table->index(['team_id', 'transaction_date']);
            $table->index(['team_id', 'direction']);
            $table->index(['team_id', 'source_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
