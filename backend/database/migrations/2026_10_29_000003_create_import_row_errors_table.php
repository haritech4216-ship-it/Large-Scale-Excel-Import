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
        Schema::create('import_row_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->unsignedInteger('excel_row');
            $table->string('customer_id', 100)->nullable();
            $table->text('rejection_reason');
            $table->timestamps();

            $table->index(['import_id', 'excel_row']);
            $table->index(['import_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_row_errors');
    }
};
