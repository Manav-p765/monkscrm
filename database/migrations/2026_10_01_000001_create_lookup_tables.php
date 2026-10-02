<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lookup tables used by leads: statuses, sources and tags.
     * Names are unique ignoring case (unique index on lower(name)).
     */
    public function up(): void
    {
        Schema::create('lead_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('color', 7);
            $table->unsignedInteger('sort_order')->default(0);
            // Disabled statuses stay attached to existing leads; they are only hidden from pickers.
            $table->boolean('is_active')->default(true);
            // open | won | lost, enforced by the App\Enums\LeadStatusType cast.
            $table->string('type', 10)->default('open');
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('color', 7);
            $table->timestamps();
        });

        // Expression indexes (PostgreSQL and SQLite): "VIP" and "vip" count as the same name.
        DB::statement('CREATE UNIQUE INDEX lead_statuses_name_lower_unique ON lead_statuses (lower(name))');
        DB::statement('CREATE UNIQUE INDEX lead_sources_name_lower_unique ON lead_sources (lower(name))');
        DB::statement('CREATE UNIQUE INDEX tags_name_lower_unique ON tags (lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('lead_statuses');
    }
};
