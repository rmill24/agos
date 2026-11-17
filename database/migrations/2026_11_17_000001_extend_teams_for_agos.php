<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('business_type')->nullable()->after('legal_name');
            $table->string('contact_email')->nullable()->after('business_type');
            $table->string('contact_phone')->nullable()->after('contact_email');
            $table->string('address_line1')->nullable()->after('contact_phone');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('city')->nullable()->after('address_line2');
            $table->string('province')->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('province');
            $table->string('country_code', 2)->default('PH')->after('postal_code');
            $table->string('currency_code', 3)->default('PHP')->after('country_code');
            $table->string('timezone')->default('Asia/Manila')->after('currency_code');
            $table->timestamp('onboarding_completed_at')->nullable()->after('timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name',
                'business_type',
                'contact_email',
                'contact_phone',
                'address_line1',
                'address_line2',
                'city',
                'province',
                'postal_code',
                'country_code',
                'currency_code',
                'timezone',
                'onboarding_completed_at',
            ]);
        });
    }
};
