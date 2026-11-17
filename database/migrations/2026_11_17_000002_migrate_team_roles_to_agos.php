<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('team_members')->where('role', 'admin')->update(['role' => 'manager']);
        DB::table('team_members')->where('role', 'member')->update(['role' => 'bookkeeper']);

        DB::table('team_invitations')->where('role', 'admin')->update(['role' => 'manager']);
        DB::table('team_invitations')->where('role', 'member')->update(['role' => 'bookkeeper']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('team_members')->where('role', 'manager')->update(['role' => 'admin']);
        DB::table('team_members')->where('role', 'bookkeeper')->update(['role' => 'member']);

        DB::table('team_invitations')->where('role', 'manager')->update(['role' => 'admin']);
        DB::table('team_invitations')->where('role', 'bookkeeper')->update(['role' => 'member']);
    }
};
