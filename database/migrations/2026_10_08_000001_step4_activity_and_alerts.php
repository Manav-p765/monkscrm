<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admins can delete notes: soft delete, audit-logged.
        Schema::table('lead_notes', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Alerts bell: leads assigned to me after this moment count as "new".
        // SQLite rebuilds the table for new columns only when needed; a plain nullable timestamp is fine.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('alerts_seen_at')->nullable();
        });

        // Follow-ups: outcome noted when completing.
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->string('outcome', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', fn (Blueprint $table) => $table->dropColumn('outcome'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('alerts_seen_at'));
        Schema::table('lead_notes', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
