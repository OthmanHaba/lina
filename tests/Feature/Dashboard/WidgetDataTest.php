<?php

use App\Enums\TeamRole;
use App\Enums\WidgetType;
use App\Models\Alert;
use App\Models\DashboardWidget;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);
    $this->user->update(['current_team_id' => $this->team->id]);
    $this->actingAs($this->user);
});

function matrixResponse(int $serverId = 1): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
        ['metric' => ['server_id' => (string) $serverId], 'values' => [[1753700000, '5'], [1753700060, '7']]],
    ]]];
}

function vectorResponse(string $value): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
        ['metric' => [], 'value' => [1753700000, $value]],
    ]]];
}

it('builds a grouped timeseries query and maps series labels to server names', function () {
    $server = Server::factory()->for($this->team)->create(['name' => 'web-1']);
    $widget = DashboardWidget::factory()->for($this->team)->create([
        'type' => WidgetType::Timeseries,
        'config' => ['group_by' => 'server', 'sources' => ['nginx']],
    ]);
    Http::fake(['*' => Http::response(matrixResponse($server->id))]);

    $response = $this->getJson(route('dashboard.widgets.data', [$this->team->slug, $widget]).'?range=6h');

    $response->assertOk();
    Http::assertSent(function (ClientRequest $request) use ($server) {
        return str_contains($request->url(), '/loki/api/v1/query_range')
            && str_contains($request['query'], 'sum by (server_id)(count_over_time(')
            && str_contains($request['query'], 'server_id=~"^('.$server->id.')$"')
            && str_contains($request['query'], 'nginx')
            && $request['step'] === '540'
            && $request->header('X-Scope-OrgID')[0] === 'team-'.$this->team->id;
    });
});

it('passes advanced logql through verbatim', function () {
    $widget = DashboardWidget::factory()->for($this->team)->create([
        'type' => WidgetType::Timeseries,
        'config' => ['logql' => 'sum(rate({server_id="9"}[5m]))'],
    ]);
    Http::fake(['*' => Http::response(matrixResponse())]);

    $this->getJson(route('dashboard.widgets.data', [$this->team->slug, $widget]))->assertOk();

    Http::assertSent(fn (ClientRequest $request) => $request['query'] === 'sum(rate({server_id="9"}[5m]))');
});

it('returns value and previous for a stat widget', function () {
    Server::factory()->for($this->team)->create();
    $widget = DashboardWidget::factory()->for($this->team)->create([
        'type' => WidgetType::Stat,
        'config' => ['window_minutes' => 30, 'search' => 'error'],
    ]);
    Http::fake(['*' => Http::response(vectorResponse('12'))]);

    $this->getJson(route('dashboard.widgets.data', [$this->team->slug, $widget]))
        ->assertOk()
        ->assertJson(['value' => 12, 'previous' => 12]);

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/loki/api/v1/query')
        && str_contains($request['query'], '[30m]'));
});

it('returns log entries for a log_stream widget', function () {
    Server::factory()->for($this->team)->create();
    $widget = DashboardWidget::factory()->for($this->team)->create([
        'type' => WidgetType::LogStream, 'config' => ['limit' => 5],
    ]);
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
        ['stream' => ['server_id' => '1', 'source' => 'app'], 'values' => [['1753700000000000000', 'hello']]],
    ]]])]);

    $this->getJson(route('dashboard.widgets.data', [$this->team->slug, $widget]))
        ->assertOk()
        ->assertJsonPath('entries.0.line', 'hello');
});

it('serves alert_status and server_health from the database without calling loki', function () {
    Http::fake();
    Alert::factory()->for($this->team)->create(['name' => 'High errors']);
    Server::factory()->for($this->team)->create(['name' => 'web-1']);

    $alertWidget = DashboardWidget::factory()->for($this->team)->create(['type' => WidgetType::AlertStatus]);
    $serverWidget = DashboardWidget::factory()->for($this->team)->create(['type' => WidgetType::ServerHealth]);

    $this->getJson(route('dashboard.widgets.data', [$this->team->slug, $alertWidget]))
        ->assertOk()->assertJsonPath('alerts.0.name', 'High errors');
    $this->getJson(route('dashboard.widgets.data', [$this->team->slug, $serverWidget]))
        ->assertOk()->assertJsonPath('servers.0.name', 'web-1');

    Http::assertNothingSent();
});

it('returns a friendly error payload when loki is unreachable', function () {
    Server::factory()->for($this->team)->create();
    $widget = DashboardWidget::factory()->for($this->team)->create(['type' => WidgetType::Timeseries]);
    Http::fake(fn () => throw new ConnectionException('down'));

    $this->getJson(route('dashboard.widgets.data', [$this->team->slug, $widget]))
        ->assertOk()
        ->assertJsonStructure(['error']);
});

it('returns empty series when the team has no servers', function () {
    Http::fake();
    $widget = DashboardWidget::factory()->for($this->team)->create(['type' => WidgetType::Timeseries]);

    $this->getJson(route('dashboard.widgets.data', [$this->team->slug, $widget]))
        ->assertOk()->assertExactJson(['series' => []]);

    Http::assertNothingSent();
});

it('rejects an unknown range and 404s foreign widgets', function () {
    $widget = DashboardWidget::factory()->for($this->team)->create();
    $this->getJson(route('dashboard.widgets.data', [$this->team->slug, $widget]).'?range=3y')->assertStatus(422);

    $foreign = DashboardWidget::factory()->for(Team::factory())->create();
    $this->getJson(route('dashboard.widgets.data', [$this->team->slug, $foreign]))->assertNotFound();
});
