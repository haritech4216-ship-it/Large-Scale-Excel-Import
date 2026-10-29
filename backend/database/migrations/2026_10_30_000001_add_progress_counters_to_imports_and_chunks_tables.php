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
        Schema::table('imports', function (Blueprint $table) {
            $table->unsignedInteger('validated_rows')->default(0)->after('processed_rows');
            $table->unsignedInteger('valid_rows')->default(0)->after('validated_rows');
            $table->unsignedInteger('invalid_rows')->default(0)->after('valid_rows');
            $table->unsignedInteger('duplicate_rows')->default(0)->after('invalid_rows');
        });

        Schema::table('import_chunks', function (Blueprint $table) {
            $table->unsignedInteger('validated_rows')->default(0)->after('processed_rows');
            $table->unsignedInteger('valid_rows')->default(0)->after('validated_rows');
            $table->unsignedInteger('invalid_rows')->default(0)->after('valid_rows');
            $table->unsignedInteger('duplicate_rows')->default(0)->after('invalid_rows');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn(['validated_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows']);
        });

        Schema::table('import_chunks', function (Blueprint $table) {
            $table->dropColumn(['validated_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows']);
        });
    }
};
