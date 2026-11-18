<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'suppliers', 'transactions'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->unique(['team_id', 'id']));
        }
        Schema::create('obligations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained();
            $t->string('kind', 10);
            foreach (['customer' => 'customers', 'supplier' => 'suppliers'] as $contact => $table) {
                $column = $contact.'_id';
                $t->foreignId($column)->nullable();
                $t->foreign(['team_id', $column])->references(['team_id', 'id'])->on($table);
            }
            $t->string('number')->nullable();
            $t->text('description')->nullable();
            $t->date('issue_date');
            $t->date('due_date');
            $t->decimal('total', 15, 2);
            $t->timestamps();
            $t->unique(['team_id', 'number']);
            $t->unique(['team_id', 'id']);
            $t->index(['team_id', 'kind', 'due_date']);
        });
        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('obligation_id')->constrained();
            $t->string('description');
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 15, 2);
            $t->decimal('line_total', 15, 2);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained();
            $t->foreignId('obligation_id');
            $t->foreignId('transaction_id')->unique();
            $t->uuid('submission_key');
            $t->foreign(['team_id', 'obligation_id'])->references(['team_id', 'id'])->on('obligations');
            $t->foreign(['team_id', 'transaction_id'])->references(['team_id', 'id'])->on('transactions');
            $t->unique(['obligation_id', 'submission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('obligations');
        foreach (['customers', 'suppliers', 'transactions'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropUnique(['team_id', 'id']));
        }
    }
};
