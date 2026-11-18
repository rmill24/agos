<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->json('headers');
            $t->json('mapping')->nullable();
            $t->string('date_format')->default('Y-m-d');
            $t->boolean('confirmed_cash')->default(false);
            $t->unsignedInteger('revision')->default(0);
            $t->timestamp('committed_at')->nullable();
            $t->date('first_date')->nullable();
            $t->date('last_date')->nullable();
            $t->boolean('incomplete_history')->default(true);
            $t->timestamps();
            $t->index(['team_id', 'committed_at']);
            $t->unique(['team_id', 'id']);
        });
        Schema::create('import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained();
            $t->foreignId('import_batch_id');
            $t->foreign(['team_id', 'import_batch_id'])->references(['team_id', 'id'])->on('import_batches')->cascadeOnDelete();
            $t->unsignedInteger('row_number');
            $t->json('cells');
            $t->json('attributes_reviewed')->nullable();
            $t->string('status')->default('pending');
            $t->text('reason')->nullable();
            $t->json('matches')->nullable();
            foreach (['customer' => 'customers', 'supplier' => 'suppliers', 'transaction' => 'transactions'] as $key => $table) {
                $t->foreignId($key.'_id')->nullable();
                $t->foreign(['team_id', $key.'_id'])->references(['team_id', 'id'])->on($table);
            }
            $t->unique(['import_batch_id', 'row_number']);
            $t->unique('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
