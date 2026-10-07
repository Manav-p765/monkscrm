<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 2 schema, added in one go so later steps don't churn: teams, user profile fields,
     * status descriptions + milestones, milestone timestamps on leads, and import staging rows.
     */
    public function up(): void
    {
        // Teams are for display and filtering only; they never change who sees which leads.
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX teams_name_lower_unique ON teams (lower(name))');

        // SQLite rebuilds the table to add a foreign key and cannot copy the lower(email) expression
        // index, so drop it around the change there. PostgreSQL alters the table in place.
        $sqlite = DB::getDriverName() === 'sqlite';
        if ($sqlite) {
            DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('job_title', 150)->nullable();
            $table->string('phone', 50)->nullable();
        });

        if ($sqlite) {
            DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        }

        Schema::table('lead_statuses', function (Blueprint $table) {
            $table->string('description', 255)->nullable();
            // contacted | qualified | meeting_booked | proposal_sent | won | lost (App\Enums\LeadMilestone).
            $table->string('milestone', 20)->nullable();
        });

        // Each is stamped once, the first time a lead enters a status with that milestone (step 3).
        // They drive conversion reporting (e.g. Meta Ads conversions, step 8).
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('meeting_booked_at')->nullable();
            $table->timestamp('proposal_sent_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
        });

        // Parsed import rows kept between wizard steps (the file itself is deleted at upload).
        // Deleted when the import completes or is cancelled, and rows older than 24 hours are purged
        // whenever a new import starts (there is no cron).
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            // Encrypted (contact details): text, not jsonb.
            $table->text('data');
            $table->jsonb('errors')->nullable();
            $table->foreignId('duplicate_lead_id')->nullable()->index()->constrained('leads')->nullOnDelete();
            // create | update | skip, decided at the duplicates step.
            $table->string('action', 20)->nullable();
            $table->timestamps();
            // Also serves as the import_id index.
            $table->unique(['import_id', 'row_number']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['first_contacted_at', 'qualified_at', 'meeting_booked_at', 'proposal_sent_at', 'won_at', 'lost_at']);
        });

        Schema::table('lead_statuses', function (Blueprint $table) {
            $table->dropColumn(['description', 'milestone']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn(['job_title', 'phone']);
        });

        Schema::dropIfExists('teams');
    }
};
