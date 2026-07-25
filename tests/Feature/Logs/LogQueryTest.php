<?php

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function logsUser(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    return [$user, $team];
}

function fakeLokiRange(): void
{
    Http::fake(['*/loki/api/v1/query_range*' => Http::response([
        'status' => 'success',
        'data' => [
            'resultType' => 'streams',
            'result' => [
                [
                    'stream' => ['server_id' => '1', 'source' => 'nginx'],
                    'values' => [
                        ['1750000000000000000', 'GET / 200'],
                        ['1750000001000000000', '{"level":"error","msg":"boom"}'],
                    ],
                ],
            ],
        ],
    ])]);
}

test('logs index renders with team filter options', function () {
    [$user, $team] = logsUser();
    $server = Server::factory()->for($team)->create();
    $server->logSources()->create(['type' => 'nginx', 'name' => 'nginx', 'config' => [], 'enabled' => true]);
    Server::factory()->create(); // foreign

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('logs/index')
            ->has('servers', 1)
            ->where('sources.0', 'nginx'),
        );
});

test('query returns normalized entries newest first', function () {
    [$user, $team] = logsUser();
    Server::factory()->for($team)->create();
    fakeLokiRange();

    $response = $this->actingAs($user)
        ->getJson(route('logs.query', ['current_team' => $team->slug, 'search' => 'boom']));

    $response->assertOk();
    $entries = $response->json('entries');
    expect($entries)->toHaveCount(2)
        ->and($entries[0]['ts'])->toBe('1750000001000000000')
        ->and($entries[0]['line'])->toContain('boom')
        ->and($entries[0]['labels']['source'])->toBe('nginx');

    Http::assertSent(fn ($request) => $request->header('X-Scope-OrgID')[0] === 'team-'.$team->id);
});

test('foreign server ids are stripped from the query', function () {
    [$user, $team] = logsUser();
    $mine = Server::factory()->for($team)->create();
    $foreign = Server::factory()->create();
    fakeLokiRange();

    $this->actingAs($user)
        ->getJson(route('logs.query', ['current_team' => $team->slug, 'servers' => $mine->id.','.$foreign->id]))
        ->assertOk();

    Http::assertSent(function ($request) use ($mine, $foreign) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);

        return str_contains($params['query'], 'server_id=~"'.$mine->id.'"')
            && ! str_contains($params['query'], (string) $foreign->id);
    });
});

test('teams with no servers get an empty result without calling loki', function () {
    [$user, $team] = logsUser();
    Http::fake();

    $this->actingAs($user)
        ->getJson(route('logs.query', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertExactJson(['entries' => []]);

    Http::assertNothingSent();
});

test('an unreachable loki returns a 502 instead of a server error', function () {
    [$user, $team] = logsUser();
    Server::factory()->for($team)->create();
    Http::fake(['*/loki/api/v1/query_range*' => Http::failedConnection()]);

    $this->actingAs($user)
        ->getJson(route('logs.query', ['current_team' => $team->slug]))
        ->assertStatus(502)
        ->assertExactJson(['entries' => [], 'error' => 'Log storage is unavailable right now.']);
});
