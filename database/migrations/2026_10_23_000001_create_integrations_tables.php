<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where leads come in from outside: a website form today; Meta / Google Ads pages later.
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // website | … (App\Enums\IntegrationType).
            $table->string('type', 20)->index();
            // In the intake URL (/intake/{public_key}). Not a secret: browser forms show it.
            $table->string('public_key', 40)->unique();
            // Signs server-to-server posts. Encrypted at rest.
            $table->text('secret');
            $table->foreignId('lead_source_id')->nullable()->index()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('assign_to')->nullable()->index()->constrained('users')->nullOnDelete();
            // Browser posts: off unless turned on, and then only from these hosts.
            $table->boolean('allow_browser')->default(false);
            $table->jsonb('allowed_origins')->nullable();
            $table->string('redirect_url', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestamp('last_received_at')->nullable();
            $table->timestamps();
        });

        // What each post did. Field names and errors only, never the submitted values.
        Schema::create('integration_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            // created | duplicate | rejected | spam (App\Enums\SubmissionResult).
            $table->string('result', 20);
            $table->foreignId('lead_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->jsonb('fields')->nullable();
            $table->jsonb('errors')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['integration_id', 'created_at']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('integration_id')->nullable()->index()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropConstrainedForeignId('integration_id'));
        Schema::dropIfExists('integration_submissions');
        Schema::dropIfExists('integrations');
    }
};
