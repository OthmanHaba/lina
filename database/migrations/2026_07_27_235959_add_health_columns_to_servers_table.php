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
        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('last_poll_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->timestamp('config_synced_at')->nullable();
            $table->string('synced_config_hash', 40)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['last_poll_at', 'last_push_at', 'config_synced_at', 'synced_config_hash']);
        });
    }
};
