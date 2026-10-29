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
        Schema::create('import_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->unsignedInteger('chunk_number');
            $table->unsignedInteger('start_row');
            $table->unsignedInteger('end_row');
            $table->string('status', 30)->default('pending'); // pending, processing, completed, failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('saved_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'chunk_number']);
            $table->index(['import_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_chunks');
    }
};
