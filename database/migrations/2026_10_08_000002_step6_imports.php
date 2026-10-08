<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            // Chunked import progress (no queue worker: the page asks for the next chunk).
            $table->unsignedInteger('processed_rows')->default(0);
            // How to treat unknown statuses / sources / tags / users (default | create | skip).
            $table->jsonb('options')->nullable();
            // Rows that couldn't be imported, with their original values, for the error CSV. Encrypted.
            $table->text('error_rows')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('undone_at')->nullable();
        });

        // Which import created a lead (for "Undo import").
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->index()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropConstrainedForeignId('import_id'));
        Schema::table('imports', fn (Blueprint $table) => $table->dropColumn(['processed_rows', 'options', 'error_rows', 'completed_at', 'undone_at']));
    }
};
