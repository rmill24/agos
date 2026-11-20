<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_pdfs', fn (Blueprint $table) => $table->unsignedInteger('generation')->default(1));
    }

    public function down(): void
    {
        Schema::table('report_pdfs', fn (Blueprint $table) => $table->dropColumn('generation'));
    }
};
