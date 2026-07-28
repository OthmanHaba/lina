<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('size')->default('half');
            $table->unsignedInteger('position')->default(0);
            $table->json('config')->default('{}');
            $table->timestamps();
        });

        // Backfill defaults for existing teams (frozen snapshot — intentionally not calling the model).
        $now = now();
        $teamIds = DB::table('teams')->whereNull('deleted_at')->pluck('id');
        foreach ($teamIds as $teamId) {
            DB::table('dashboard_widgets')->insert([
                ['team_id' => $teamId, 'type' => 'timeseries', 'title' => 'Log volume', 'size' => 'full', 'position' => 0, 'config' => json_encode(['chart' => 'bar', 'group_by' => 'server']), 'created_at' => $now, 'updated_at' => $now],
                ['team_id' => $teamId, 'type' => 'stat', 'title' => 'Errors (last hour)', 'size' => 'third', 'position' => 1, 'config' => json_encode(['search' => 'error', 'window_minutes' => 60]), 'created_at' => $now, 'updated_at' => $now],
                ['team_id' => $teamId, 'type' => 'server_health', 'title' => 'Servers', 'size' => 'third', 'position' => 2, 'config' => json_encode([]), 'created_at' => $now, 'updated_at' => $now],
                ['team_id' => $teamId, 'type' => 'log_stream', 'title' => 'Latest logs', 'size' => 'full', 'position' => 3, 'config' => json_encode(['limit' => 25]), 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
