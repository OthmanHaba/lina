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
        Schema::table('teams', fn (Blueprint $table) => $table->unsignedSmallInteger('retention_days')->default(30));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('retention_days'));
    }
};
