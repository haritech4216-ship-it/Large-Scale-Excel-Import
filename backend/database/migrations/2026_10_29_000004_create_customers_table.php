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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id', 100)->unique();
            $table->string('first_name', 150);
            $table->string('last_name', 150);
            $table->string('email', 255)->index();
            $table->string('phone_number', 60);
            $table->date('date_of_birth');
            $table->string('gender', 50)->nullable();
            $table->string('profession', 150);
            $table->string('company_name', 150)->nullable();
            $table->string('country', 100);
            $table->string('city', 100);
            $table->text('address')->nullable();
            $table->string('social_media_url', 500)->nullable();
            $table->date('registration_date');
            $table->string('status', 20); // Active or Inactive
            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
