<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leads and everything that hangs off a lead: tags, notes, activities, follow-ups.
     *
     * PostgreSQL does not index foreign-key columns automatically, so every FK column that
     * is not the leading column of another index gets an explicit index here.
     * "Enum" columns are plain strings validated by PHP enum casts (see CLAUDE.md "Database").
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            // Public reference code, e.g. "LD-000123". Generated from the id right after insert.
            $table->string('reference', 20)->nullable()->unique();

            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company', 191)->nullable();
            $table->string('job_title', 150)->nullable();

            $table->string('email', 191)->nullable();
            $table->string('secondary_email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('secondary_phone', 50)->nullable();
            // Normalized copies used for search and duplicate detection.
            $table->string('email_normalized', 191)->nullable()->index();
            $table->string('phone_normalized', 30)->nullable()->index();

            $table->string('address_line1', 191)->nullable();
            $table->string('address_line2', 191)->nullable();
            $table->string('city', 100)->nullable()->index();
            $table->string('state', 100)->nullable()->index();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->nullable()->index();
            $table->string('website', 255)->nullable();
            $table->text('description')->nullable();

            $table->foreignId('lead_status_id')->index()->constrained('lead_statuses')->restrictOnDelete();
            $table->foreignId('lead_source_id')->nullable()->index()->constrained('lead_sources')->nullOnDelete();
            // Set once at creation; never changes afterwards (enforced in the Lead model).
            $table->foreignId('original_source_id')->nullable()->index()->constrained('lead_sources')->nullOnDelete();
            // low | medium | high | urgent, enforced by the App\Enums\LeadPriority cast.
            $table->string('priority', 10)->nullable()->index();
            $table->foreignId('assigned_to')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();

            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->timestamp('last_activity_at')->nullable()->index();

            $table->softDeletes();
            $table->timestamps();

            $table->index('created_at');
            $table->index('deleted_at');
        });

        Schema::create('lead_tag', function (Blueprint $table) {
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->index()->constrained()->cascadeOnDelete();

            // Also serves as the lead_id index.
            $table->primary(['lead_id', 'tag_id']);
        });

        Schema::create('lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            // Nullable so automated "system" events can exist without an acting user.
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            // call | email | meeting | sms | follow_up | note | other | system (App\Enums\ActivityType).
            $table->string('type', 20)->index();
            $table->text('description')->nullable();
            $table->string('outcome', 191)->nullable();
            $table->timestamp('occurred_at');
            // Structured data for system events (e.g. status change from/to).
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'occurred_at']);
        });

        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            // call | email | meeting | other (App\Enums\FollowUpType).
            $table->string('type', 20);
            $table->timestamp('due_at');
            $table->text('notes')->nullable();
            // pending | completed | cancelled (App\Enums\FollowUpStatus).
            // "Overdue" is not stored: it is pending AND due_at < now(), computed at query time.
            $table->string('status', 20)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            // Also serves as the assigned_to index.
            $table->index(['assigned_to', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('lead_notes');
        Schema::dropIfExists('lead_tag');
        Schema::dropIfExists('leads');
    }
};
