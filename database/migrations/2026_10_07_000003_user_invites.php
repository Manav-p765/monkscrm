<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invite (set-password) tokens: single use, 48 hours (password broker "invites").
        // Separate from password_reset_tokens so a 30-minute reset token can never be
        // redeemed through the invite route with the longer invite lifetime.
        Schema::create('user_invite_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Deactivating a user may leave their pending follow-ups unassigned.
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invite_tokens');
    }
};
