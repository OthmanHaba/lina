<?php

use App\Models\Server;
use App\Models\Team;
use App\Services\LokiOverridesWriter;

beforeEach(function () {
    config(['services.loki.overrides_path' => storage_path('framework/testing/overrides-test.yaml')]);
    @unlink(storage_path('framework/testing/overrides-test.yaml'));
});

test('a default team renders an explicit 30d period and no stream rules', function () {
    $team = Team::factory()->create();

    $yaml = app(LokiOverridesWriter::class)->render();

    expect($yaml)->toBe(<<<YAML
overrides:
  team-{$team->id}:
    retention_period: 30d

YAML);
});

test('team and server overrides render periods and stream rules', function () {
    $team = Team::factory()->create(['retention_days' => 90]);
    $inherit = Server::factory()->for($team)->create();
    $override = Server::factory()->for($team)->create(['retention_days' => 7]);

    $yaml = app(LokiOverridesWriter::class)->render();

    expect($yaml)
        ->toContain("team-{$team->id}:")
        ->toContain('retention_period: 90d')
        ->toContain('retention_stream:')
        ->toContain('- selector: \'{server_id="'.$override->id.'"}\'')
        ->toContain('period: 7d')
        ->not->toContain('server_id="'.$inherit->id.'"');
});

test('deleted servers and soft-deleted teams drop out on rewrite', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['retention_days' => 7]);
    $deletedTeam = Team::factory()->create(['retention_days' => 99]);

    $server->delete();
    $deletedTeam->delete();

    $yaml = app(LokiOverridesWriter::class)->render();

    expect($yaml)->not->toContain('retention_stream')
        ->not->toContain('99d')
        ->toContain("team-{$team->id}:");
});

test('write is atomic and never leaves a temp file', function () {
    Team::factory()->create();

    app(LokiOverridesWriter::class)->write();

    $path = config('services.loki.overrides_path');
    expect(file_exists($path))->toBeTrue()
        ->and(file_exists($path.'.tmp'))->toBeFalse()
        ->and(file_get_contents($path))->toContain('overrides:');
});

test('the artisan command writes the file', function () {
    Team::factory()->create();

    $this->artisan('loki:write-overrides')->assertSuccessful();

    expect(file_exists(config('services.loki.overrides_path')))->toBeTrue();
});

test('the command fails loudly when the file cannot be written', function () {
    $blocker = storage_path('framework/testing/blocker-'.uniqid());
    file_put_contents($blocker, 'not a directory');
    config(['services.loki.overrides_path' => $blocker.'/overrides.yaml']);
    Team::factory()->create();

    $this->artisan('loki:write-overrides')->assertFailed();

    @unlink($blocker);
});
