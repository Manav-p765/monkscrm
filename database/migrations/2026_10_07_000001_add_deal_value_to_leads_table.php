<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-lead deal value. Amounts are integer minor units (cents/paise) in the lead's own
     * currency; amounts in different currencies are never added together.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->bigInteger('deal_value')->nullable();
            // ISO 4217 code, enforced by the App\Enums\Currency cast. The default only backfills
            // existing rows; new leads get the `default_currency` setting from the Lead model.
            $table->string('currency', 3)->default('USD');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['deal_value', 'currency']);
        });
    }
};
