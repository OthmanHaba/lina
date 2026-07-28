# Dashboard Builder + UI Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the placeholder team dashboard with a widget-based builder (5 widget types, filterable, optional raw LogQL, drag reorder), then bring every existing page up to the new visual language.

**Architecture:** `dashboard_widgets` table (one dashboard per team, no `dashboards` table). CRUD + reorder + a single per-widget data endpoint under the `{current_team}` group; `WidgetDataService` builds LogQL via the existing `LogQlBuilder` and queries Loki with `X-Scope-OrgID: team-{id}` (tenancy holds even for raw LogQL). Frontend: recharts + dnd-kit, each widget fetches its own data with plain `fetch()` (Logs-page pattern).

**Tech Stack:** Laravel 13, Pest 4, Inertia v3 + React 19, Wayfinder, Tailwind v4 + shadcn primitives, recharts, @dnd-kit.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-28-dashboard-ui-polish-design.md`.
- **REPO QUIRK:** every controller method for routes under the `{current_team}` prefix MUST accept `string $current_team` as its first route parameter (before route-bound models). Removing it breaks dispatch.
- There is **no `level` label** on log streams — only `server_id` and `source`. No level filters anywhere server-side; level badges in the UI are a client-side heuristic on line content only.
- Widget types: `timeseries`, `stat`, `log_stream`, `alert_status`, `server_health`. Sizes: `third`, `half`, `full`. Title max 100. `logql` max 2000 chars, allowed ONLY on timeseries/stat. `window_minutes` 5–1440 (default 60). `limit` 1–100 (default 25). Ranges: `15m`, `1h`, `6h`, `24h`, `7d` (default `1h`).
- Loki errors from the data endpoint return HTTP 200 with `{"error": "..."}` — never a 5xx to the widget.
- After modifying PHP files: `vendor/bin/pint --dirty --format agent`. After route changes: `php artisan wayfinder:generate --no-interaction --with-form`.
- Frontend forms use the Wayfinder `<Form {...routeFn.form(args)}>` pattern (NOT useForm). Mirror existing dialogs (see `resources/js/components/create-team-modal.tsx`, `resources/js/pages/alerts/index.tsx`).
- Run tests with `php artisan test --compact --filter=...`; frontend verified with `npm run build` (must succeed with zero TS errors).
- Phase 2 tasks (8–10) are purely presentational: no route, endpoint, or behavior changes; the existing suite must stay green untouched.
- New deps approved: `recharts`, `@dnd-kit/core`, `@dnd-kit/sortable`, `@dnd-kit/utilities`. No others.

---

### Task 1: Widget data layer — enums, migration, model, factory, default seeding

**Files:**
- Create: `app/Enums/WidgetType.php`, `app/Enums/WidgetSize.php`
- Create: `app/Models/DashboardWidget.php`
- Create: `database/factories/DashboardWidgetFactory.php`
- Create: migration `create_dashboard_widgets_table` (via `php artisan make:migration`)
- Modify: `app/Models/Team.php` (add `widgets()` HasMany)
- Modify: `app/Actions/Teams/CreateTeam.php` (seed defaults in the transaction)
- Test: `tests/Feature/Dashboard/WidgetSeedingTest.php`

**Interfaces:**
- Produces: `WidgetType` (cases Timeseries/Stat/LogStream/AlertStatus/ServerHealth, string-backed `timeseries|stat|log_stream|alert_status|server_health`), `WidgetSize` (Third/Half/Full → `third|half|full`), `DashboardWidget` model (fillable team_id/type/title/size/position/config; casts type/size to enums, config to array; `team()`; static `seedDefaults(Team $team): void`), `Team::widgets(): HasMany`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Dashboard/WidgetSeedingTest`

```php
<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\WidgetSize;
use App\Enums\WidgetType;
use App\Models\DashboardWidget;
use App\Models\Team;
use App\Models\User;

it('seeds four default widgets when a team is created', function () {
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Acme');

    expect($team->widgets()->orderBy('position')->pluck('type')->all())->toBe([
        WidgetType::Timeseries, WidgetType::Stat, WidgetType::ServerHealth, WidgetType::LogStream,
    ]);
});

it('seedDefaults creates the documented defaults', function () {
    $team = Team::factory()->create();

    DashboardWidget::seedDefaults($team);

    $widgets = $team->widgets()->orderBy('position')->get();
    expect($widgets)->toHaveCount(4)
        ->and($widgets[0]->size)->toBe(WidgetSize::Full)
        ->and($widgets[0]->config['group_by'])->toBe('server')
        ->and($widgets[1]->config['search'])->toBe('error')
        ->and($widgets[3]->config['limit'])->toBe(25);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=WidgetSeedingTest` — Expected: FAIL (class/table missing).

- [ ] **Step 3: Implement**

`php artisan make:migration create_dashboard_widgets_table --no-interaction`:

```php
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
```

`app/Enums/WidgetType.php`:

```php
<?php

namespace App\Enums;

enum WidgetType: string
{
    case Timeseries = 'timeseries';
    case Stat = 'stat';
    case LogStream = 'log_stream';
    case AlertStatus = 'alert_status';
    case ServerHealth = 'server_health';
}
```

`app/Enums/WidgetSize.php`:

```php
<?php

namespace App\Enums;

enum WidgetSize: string
{
    case Third = 'third';
    case Half = 'half';
    case Full = 'full';
}
```

`app/Models/DashboardWidget.php` (match sibling models' style — see `app/Models/Alert.php` for the `#[Fillable]` + PHPDoc pattern):

```php
<?php

namespace App\Models;

use App\Enums\WidgetSize;
use App\Enums\WidgetType;
use Database\Factories\DashboardWidgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property WidgetType $type
 * @property string $title
 * @property WidgetSize $size
 * @property int $position
 * @property array<string, mixed> $config
 * @property-read Team $team
 */
#[Fillable(['team_id', 'type', 'title', 'size', 'position', 'config'])]
class DashboardWidget extends Model
{
    /** @use HasFactory<DashboardWidgetFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The default dashboard every team starts with.
     */
    public static function seedDefaults(Team $team): void
    {
        $defaults = [
            ['type' => WidgetType::Timeseries, 'title' => 'Log volume', 'size' => WidgetSize::Full, 'position' => 0, 'config' => ['chart' => 'bar', 'group_by' => 'server']],
            ['type' => WidgetType::Stat, 'title' => 'Errors (last hour)', 'size' => WidgetSize::Third, 'position' => 1, 'config' => ['search' => 'error', 'window_minutes' => 60]],
            ['type' => WidgetType::ServerHealth, 'title' => 'Servers', 'size' => WidgetSize::Third, 'position' => 2, 'config' => []],
            ['type' => WidgetType::LogStream, 'title' => 'Latest logs', 'size' => WidgetSize::Full, 'position' => 3, 'config' => ['limit' => 25]],
        ];

        foreach ($defaults as $attributes) {
            $team->widgets()->create($attributes);
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => WidgetType::class,
            'size' => WidgetSize::class,
            'config' => 'array',
        ];
    }
}
```

`Team::widgets()` (add beside the existing `servers()` relation):

```php
/** @return HasMany<DashboardWidget, $this> */
public function widgets(): HasMany
{
    return $this->hasMany(DashboardWidget::class);
}
```

`CreateTeam::handle` — inside the existing `DB::transaction` closure, after `$user->switchTeam($team);` add:

```php
DashboardWidget::seedDefaults($team);
```

`database/factories/DashboardWidgetFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\WidgetSize;
use App\Enums\WidgetType;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\DashboardWidget>
 */
class DashboardWidgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => WidgetType::Timeseries,
            'title' => $this->faker->words(2, true),
            'size' => WidgetSize::Half,
            'position' => 0,
            'config' => [],
        ];
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=WidgetSeedingTest` — Expected: PASS. Also run `php artisan test --compact --filter=Team` to confirm existing team tests still pass (CreateTeam changed).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: dashboard_widgets table, model, default seeding"
```

---

### Task 2: Widget CRUD + reorder endpoints, dashboard page props

**Files:**
- Create: `app/Http/Controllers/Dashboard/DashboardWidgetController.php`
- Modify: `routes/web.php` (widget routes), `app/Http/Controllers/DashboardController.php` (widgets/servers/sources props)
- Test: `tests/Feature/Dashboard/WidgetCrudTest.php`

**Interfaces:**
- Consumes: `DashboardWidget`, `WidgetType`, `WidgetSize` from Task 1.
- Produces: routes `dashboard.widgets.store` (POST `dashboard/widgets`), `dashboard.widgets.reorder` (PATCH `dashboard/widgets/reorder` — MUST be registered before the `{widget}` routes), `dashboard.widgets.update` (PATCH `dashboard/widgets/{widget}`), `dashboard.widgets.destroy` (DELETE). Dashboard page props: `widgets` (id/type/title/size/position/config, position-ordered), `servers` (`[{id, name}]`), `sources` (`string[]`). Type is immutable on update. Config is stripped to known keys per type.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Dashboard/WidgetCrudTest`. Use the same auth/team setup helper style as `tests/Feature/Alerts/AlertCrudTest.php` (read it first; reuse its `actingAs` + current-team pattern and the `{current_team}` slug in URLs).

```php
<?php

use App\Enums\WidgetType;
use App\Models\DashboardWidget;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

// Setup mirrors AlertCrudTest: $this->user with current team $this->team, actingAs($this->user).

it('creates a timeseries widget with filters', function () {
    $server = Server::factory()->for($this->team)->create();

    $this->post(route('dashboard.widgets.store', $this->team->slug), [
        'type' => 'timeseries', 'title' => 'Volume', 'size' => 'full',
        'config' => ['server_ids' => [$server->id], 'sources' => ['nginx'], 'chart' => 'area', 'group_by' => 'source'],
    ])->assertRedirect();

    $widget = DashboardWidget::where('team_id', $this->team->id)->where('title', 'Volume')->firstOrFail();
    expect($widget->type)->toBe(WidgetType::Timeseries)
        ->and($widget->config['chart'])->toBe('area');
});

it('strips unknown config keys', function () {
    $this->post(route('dashboard.widgets.store', $this->team->slug), [
        'type' => 'stat', 'title' => 'Errors', 'size' => 'third',
        'config' => ['search' => 'error', 'evil' => 'x'],
    ])->assertRedirect();

    expect(DashboardWidget::where('title', 'Errors')->firstOrFail()->config)->not->toHaveKey('evil');
});

it('rejects logql on a log_stream widget', function () {
    $this->post(route('dashboard.widgets.store', $this->team->slug), [
        'type' => 'log_stream', 'title' => 'Logs', 'size' => 'full',
        'config' => ['logql' => '{server_id="1"}'],
    ])->assertSessionHasErrors('config.logql');
});

it('rejects server ids from another team', function () {
    $foreign = Server::factory()->for(Team::factory())->create();

    $this->post(route('dashboard.widgets.store', $this->team->slug), [
        'type' => 'timeseries', 'title' => 'Volume', 'size' => 'full',
        'config' => ['server_ids' => [$foreign->id]],
    ])->assertSessionHasErrors('config.server_ids.0');
});

it('rejects out-of-range window and limit', function () {
    $this->post(route('dashboard.widgets.store', $this->team->slug), [
        'type' => 'stat', 'title' => 'S', 'size' => 'third', 'config' => ['window_minutes' => 2],
    ])->assertSessionHasErrors('config.window_minutes');

    $this->post(route('dashboard.widgets.store', $this->team->slug), [
        'type' => 'log_stream', 'title' => 'L', 'size' => 'full', 'config' => ['limit' => 500],
    ])->assertSessionHasErrors('config.limit');
});

it('updates title, size and config but never type', function () {
    $widget = DashboardWidget::factory()->for($this->team)->create(['type' => WidgetType::Stat, 'config' => ['window_minutes' => 60]]);

    $this->patch(route('dashboard.widgets.update', [$this->team->slug, $widget]), [
        'title' => 'Renamed', 'size' => 'half', 'type' => 'timeseries',
        'config' => ['window_minutes' => 120],
    ])->assertRedirect();

    $widget->refresh();
    expect($widget->title)->toBe('Renamed')
        ->and($widget->type)->toBe(WidgetType::Stat)
        ->and($widget->config['window_minutes'])->toBe(120);
});

it('404s on a foreign team widget', function () {
    $widget = DashboardWidget::factory()->for(Team::factory())->create();

    $this->patch(route('dashboard.widgets.update', [$this->team->slug, $widget]), ['title' => 'X', 'size' => 'half', 'config' => []])->assertNotFound();
    $this->delete(route('dashboard.widgets.destroy', [$this->team->slug, $widget]))->assertNotFound();
});

it('reorders widgets and rejects a partial id list', function () {
    $a = DashboardWidget::factory()->for($this->team)->create(['position' => 0]);
    $b = DashboardWidget::factory()->for($this->team)->create(['position' => 1]);

    $this->patch(route('dashboard.widgets.reorder', $this->team->slug), ['ids' => [$b->id, $a->id]])->assertRedirect();
    expect($a->refresh()->position)->toBe(1)->and($b->refresh()->position)->toBe(0);

    $this->patch(route('dashboard.widgets.reorder', $this->team->slug), ['ids' => [$a->id]])->assertSessionHasErrors('ids');
});

it('deletes a widget', function () {
    $widget = DashboardWidget::factory()->for($this->team)->create();

    $this->delete(route('dashboard.widgets.destroy', [$this->team->slug, $widget]))->assertRedirect();
    expect(DashboardWidget::find($widget->id))->toBeNull();
});

it('passes widgets and filter options to the dashboard page', function () {
    DashboardWidget::seedDefaults($this->team);
    Server::factory()->for($this->team)->create(['name' => 'web-1']);

    $this->get(route('dashboard', $this->team->slug))
        ->assertInertia(fn ($page) => $page->component('dashboard')
            ->has('widgets', 4)
            ->has('servers', 1)
            ->has('sources'));
});
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --compact --filter=WidgetCrudTest` — FAIL (routes missing).

- [ ] **Step 3: Implement**

`app/Http/Controllers/Dashboard/DashboardWidgetController.php`:

```php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\WidgetSize;
use App\Enums\WidgetType;
use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardWidgetController extends Controller
{
    public function store(Request $request, string $current_team): RedirectResponse
    {
        $teamId = $request->user()->current_team_id;

        $validated = $request->validate([
            'type' => ['required', Rule::enum(WidgetType::class)],
            'title' => ['required', 'string', 'max:100'],
            'size' => ['required', Rule::enum(WidgetSize::class)],
        ]);

        $type = WidgetType::from($validated['type']);

        DashboardWidget::create([
            'team_id' => $teamId,
            'type' => $type,
            'title' => $validated['title'],
            'size' => $validated['size'],
            'position' => (int) DashboardWidget::where('team_id', $teamId)->max('position') + 1,
            'config' => $this->validatedConfig($request, $type, $teamId),
        ]);

        return back();
    }

    public function update(Request $request, string $current_team, DashboardWidget $widget): RedirectResponse
    {
        $teamId = $request->user()->current_team_id;
        abort_unless($widget->team_id === $teamId, 404);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'size' => ['required', Rule::enum(WidgetSize::class)],
        ]);

        $widget->update([
            'title' => $validated['title'],
            'size' => $validated['size'],
            'config' => $this->validatedConfig($request, $widget->type, $teamId),
        ]);

        return back();
    }

    public function destroy(Request $request, string $current_team, DashboardWidget $widget): RedirectResponse
    {
        abort_unless($widget->team_id === $request->user()->current_team_id, 404);

        $widget->delete();

        return back();
    }

    public function reorder(Request $request, string $current_team): RedirectResponse
    {
        $teamId = $request->user()->current_team_id;

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $teamWidgetIds = DashboardWidget::where('team_id', $teamId)->pluck('id');

        if ($teamWidgetIds->count() !== count($validated['ids']) || $teamWidgetIds->diff($validated['ids'])->isNotEmpty()) {
            throw ValidationException::withMessages(['ids' => 'The order must include every widget exactly once.']);
        }

        DB::transaction(function () use ($validated) {
            foreach (array_values($validated['ids']) as $position => $id) {
                DashboardWidget::whereKey($id)->update(['position' => $position]);
            }
        });

        return back();
    }

    /**
     * Validate and strip the config payload to the keys the widget type knows.
     *
     * @return array<string, mixed>
     */
    protected function validatedConfig(Request $request, WidgetType $type, int $teamId): array
    {
        $rules = [
            'config' => ['present', 'array'],
            'config.server_ids' => ['sometimes', 'array'],
            'config.server_ids.*' => ['integer', Rule::exists('servers', 'id')->where('team_id', $teamId)],
            'config.sources' => ['sometimes', 'array'],
            'config.sources.*' => ['string', 'max:100'],
            'config.search' => ['sometimes', 'nullable', 'string', 'max:500'],
            'config.logql' => ['prohibited'],
        ];

        $keys = ['server_ids', 'sources', 'search'];

        [$rules, $keys] = match ($type) {
            WidgetType::Timeseries => [array_merge($rules, [
                'config.chart' => ['sometimes', Rule::in(['line', 'bar', 'area'])],
                'config.group_by' => ['sometimes', 'nullable', Rule::in(['server', 'source'])],
                'config.logql' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ]), [...$keys, 'chart', 'group_by', 'logql']],
            WidgetType::Stat => [array_merge($rules, [
                'config.window_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
                'config.logql' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ]), [...$keys, 'window_minutes', 'logql']],
            WidgetType::LogStream => [array_merge($rules, [
                'config.limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]), [...$keys, 'limit']],
            default => [$rules, $keys],
        };

        $validated = $request->validate($rules);

        return Arr::only($validated['config'] ?? [], $keys);
    }
}
```

`routes/web.php` — add inside the `{current_team}` group, right after the `dashboard` route (reorder BEFORE `{widget}` so "reorder" never binds as a model key):

```php
Route::post('dashboard/widgets', [DashboardWidgetController::class, 'store'])->name('dashboard.widgets.store');
Route::patch('dashboard/widgets/reorder', [DashboardWidgetController::class, 'reorder'])->name('dashboard.widgets.reorder');
Route::patch('dashboard/widgets/{widget}', [DashboardWidgetController::class, 'update'])->name('dashboard.widgets.update');
Route::delete('dashboard/widgets/{widget}', [DashboardWidgetController::class, 'destroy'])->name('dashboard.widgets.destroy');
```

`DashboardController::__invoke` — add to the `Inertia::render('dashboard', [...])` payload (keep `pendingInvitations` untouched; copy the servers/sources queries from `LogController::index`):

```php
'widgets' => DashboardWidget::where('team_id', $teamId)
    ->orderBy('position')
    ->get()
    ->map(fn (DashboardWidget $widget) => [
        'id' => $widget->id,
        'type' => $widget->type->value,
        'title' => $widget->title,
        'size' => $widget->size->value,
        'position' => $widget->position,
        'config' => $widget->config,
    ])
    ->all(),
'servers' => Server::where('team_id', $teamId)->get(['id', 'name'])
    ->map(fn (Server $server) => ['id' => $server->id, 'name' => $server->name])
    ->all(),
'sources' => LogSource::whereIn('server_id', Server::where('team_id', $teamId)->select('id'))
    ->where('enabled', true)->distinct()->pluck('name')->all(),
```

with `$teamId = $request->user()->current_team_id;` at the top. NOTE: map to `->all()` (plain arrays) — returning Eloquent Collections from `map()` trips larastan's covariance check (repo precedent).

- [ ] **Step 4: Run tests** — `php artisan test --compact --filter=WidgetCrudTest` — PASS. Then `php artisan wayfinder:generate --no-interaction --with-form`.

- [ ] **Step 5: Pint, larastan, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --no-progress | tail -5
git add -A && git commit -m "feat: dashboard widget CRUD, reorder, page props"
```

---

### Task 3: WidgetDataService + data endpoint

**Files:**
- Create: `app/Services/WidgetDataService.php`
- Modify: `app/Http/Controllers/Dashboard/DashboardWidgetController.php` (add `data` method), `routes/web.php` (data route)
- Test: `tests/Feature/Dashboard/WidgetDataTest.php`

**Interfaces:**
- Consumes: `LogQlBuilder::build(array $serverIds, array $sources, ?string $search): string`; `Server::status(): string`, `Server::configStatus(): string`; `Alert` model with `state: AlertState`, `last_state_change_at`.
- Produces: route `dashboard.widgets.data` (GET `dashboard/widgets/{widget}/data?range=1h`). `WidgetDataService::RANGES` public const (`['15m'=>900,'1h'=>3600,'6h'=>21600,'24h'=>86400,'7d'=>604800]`). `fetch(DashboardWidget $widget, string $range): array` returning per type: timeseries `{series: [{label, points: [[unixSeconds, count], …]}]}`; stat `{value, previous}`; log_stream `{entries: [...]}` (same entry shape as the Logs page: ts/time/line/labels); alert_status `{alerts: [{id, name, state, enabled, changedAt}]}`; server_health `{servers: [{id, name, status, configStatus}]}`. Any Loki failure → `{error: string}` (HTTP 200).

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Dashboard/WidgetDataTest`. Same auth/team setup as WidgetCrudTest. Loki base URL comes from `config('services.loki.url')` — use `Http::fake` with a wildcard on that host (see `tests/Feature/Logs` for the repo's existing fake pattern; reuse it).

```php
<?php

use App\Enums\WidgetType;
use App\Models\Alert;
use App\Models\DashboardWidget;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

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
```

Follow the repo's existing Http::fake idiom in `tests/Feature/Logs` if it differs from the wildcard fake shown here.

- [ ] **Step 2: Run to verify failure** — `php artisan test --compact --filter=WidgetDataTest` — FAIL.

- [ ] **Step 3: Implement**

`app/Services/WidgetDataService.php`:

```php
<?php

namespace App\Services;

use App\Enums\WidgetType;
use App\Models\Alert;
use App\Models\DashboardWidget;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class WidgetDataService
{
    public const RANGES = ['15m' => 900, '1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];

    protected const UNAVAILABLE = 'Log storage is unavailable right now.';

    public function __construct(protected LogQlBuilder $builder) {}

    /** @return array<string, mixed> */
    public function fetch(DashboardWidget $widget, string $range): array
    {
        $seconds = self::RANGES[$range];

        return match ($widget->type) {
            WidgetType::Timeseries => $this->timeseries($widget, $seconds),
            WidgetType::Stat => $this->stat($widget),
            WidgetType::LogStream => $this->logStream($widget, $seconds),
            WidgetType::AlertStatus => $this->alertStatus($widget),
            WidgetType::ServerHealth => $this->serverHealth($widget),
        };
    }

    /** @return array<string, mixed> */
    protected function timeseries(DashboardWidget $widget, int $seconds): array
    {
        $step = max(60, intdiv($seconds, 40));
        $config = $widget->config;
        $logql = $config['logql'] ?? null;

        if (is_string($logql) && $logql !== '') {
            $query = $logql;
        } else {
            $selector = $this->selector($widget);

            if ($selector === null) {
                return ['series' => []];
            }

            $by = match ($config['group_by'] ?? null) {
                'server' => ' by (server_id)',
                'source' => ' by (source)',
                default => '',
            };

            $query = "sum{$by}(count_over_time({$selector} [{$step}s]))";
        }

        $result = $this->lokiGet($widget->team_id, 'query_range', [
            'query' => $query,
            'start' => (now()->timestamp - $seconds) * 1_000_000_000,
            'end' => now()->timestamp * 1_000_000_000,
            'step' => (string) $step,
        ]);

        if (isset($result['error'])) {
            return $result;
        }

        $serverNames = Server::where('team_id', $widget->team_id)->pluck('name', 'id');

        $series = [];

        foreach ($result['data'] as $row) {
            $metric = $row['metric'] ?? [];

            $label = match (true) {
                isset($metric['server_id']) => $serverNames[(int) $metric['server_id']] ?? 'Server '.$metric['server_id'],
                isset($metric['source']) => $metric['source'],
                $metric === [] => 'Logs',
                default => implode(', ', $metric),
            };

            $series[] = [
                'label' => $label,
                'points' => array_map(fn (array $value) => [(int) $value[0], (float) $value[1]], $row['values'] ?? []),
            ];
        }

        return ['series' => $series];
    }

    /** @return array<string, mixed> */
    protected function stat(DashboardWidget $widget): array
    {
        $config = $widget->config;
        $window = (int) ($config['window_minutes'] ?? 60);
        $logql = $config['logql'] ?? null;

        if (is_string($logql) && $logql !== '') {
            $query = $logql;
        } else {
            $selector = $this->selector($widget);

            if ($selector === null) {
                return ['value' => 0, 'previous' => 0];
            }

            $query = "sum(count_over_time({$selector} [{$window}m]))";
        }

        $current = $this->instantValue($widget->team_id, $query, now()->timestamp);

        if (is_array($current)) {
            return $current;
        }

        $previous = $this->instantValue($widget->team_id, $query, now()->timestamp - $window * 60);

        if (is_array($previous)) {
            return $previous;
        }

        return ['value' => $current, 'previous' => $previous];
    }

    /** @return array<string, mixed> */
    protected function logStream(DashboardWidget $widget, int $seconds): array
    {
        $selector = $this->selector($widget);

        if ($selector === null) {
            return ['entries' => []];
        }

        $result = $this->lokiGet($widget->team_id, 'query_range', [
            'query' => $selector,
            'start' => (now()->timestamp - $seconds) * 1_000_000_000,
            'end' => now()->timestamp * 1_000_000_000,
            'limit' => (int) ($widget->config['limit'] ?? 25),
            'direction' => 'backward',
        ]);

        if (isset($result['error'])) {
            return $result;
        }

        $entries = [];

        foreach ($result['data'] as $stream) {
            foreach ($stream['values'] ?? [] as $value) {
                $entries[] = [
                    'ts' => $value[0],
                    'time' => Carbon::createFromTimestampMs((int) ((float) $value[0] / 1_000_000))->toISOString(),
                    'line' => $value[1],
                    'labels' => $stream['stream'] ?? [],
                ];
            }
        }

        usort($entries, fn (array $a, array $b) => [strlen($b['ts']), $b['ts']] <=> [strlen($a['ts']), $a['ts']]);

        return ['entries' => array_slice($entries, 0, (int) ($widget->config['limit'] ?? 25))];
    }

    /** @return array<string, mixed> */
    protected function alertStatus(DashboardWidget $widget): array
    {
        return ['alerts' => Alert::where('team_id', $widget->team_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Alert $alert) => [
                'id' => $alert->id,
                'name' => $alert->name,
                'state' => $alert->state->value,
                'enabled' => $alert->enabled,
                'changedAt' => $alert->last_state_change_at?->toISOString(),
            ])->all()];
    }

    /** @return array<string, mixed> */
    protected function serverHealth(DashboardWidget $widget): array
    {
        return ['servers' => Server::where('team_id', $widget->team_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Server $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'status' => $server->status(),
                'configStatus' => $server->configStatus(),
            ])->all()];
    }

    /**
     * Build the stream selector from the widget's filters, re-intersecting
     * server ids with the team's current servers (deleted servers drop out).
     */
    protected function selector(DashboardWidget $widget): ?string
    {
        $config = $widget->config;
        $teamServerIds = Server::where('team_id', $widget->team_id)->pluck('id')->all();

        $requested = array_map(intval(...), $config['server_ids'] ?? []);
        $serverIds = $requested === [] ? $teamServerIds : array_values(array_intersect($requested, $teamServerIds));

        if ($serverIds === []) {
            return null;
        }

        return $this->builder->build($serverIds, array_values($config['sources'] ?? []), $config['search'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{data: list<array<string, mixed>>}|array{error: string}
     */
    protected function lokiGet(int $teamId, string $endpoint, array $params): array
    {
        try {
            $response = Http::withHeaders(['X-Scope-OrgID' => 'team-'.$teamId])
                ->get(rtrim(config('services.loki.url'), '/').'/loki/api/v1/'.$endpoint, $params);
        } catch (ConnectionException) {
            return ['error' => self::UNAVAILABLE];
        }

        if ($response->failed()) {
            return ['error' => $response->status() === 400 ? 'Query failed — check the widget query.' : self::UNAVAILABLE];
        }

        return ['data' => $response->json('data.result', [])];
    }

    /** @return float|array{error: string} */
    protected function instantValue(int $teamId, string $query, int $time)
    {
        $result = $this->lokiGet($teamId, 'query', [
            'query' => $query,
            'time' => $time * 1_000_000_000,
        ]);

        if (isset($result['error'])) {
            return $result;
        }

        return (float) ($result['data'][0]['value'][1] ?? 0);
    }
}
```

Note the `instantValue` return type: declare it as `float|array` with a PHPDoc shape (larastan-friendly). If phpstan complains, split into a small result object — do NOT suppress with ignores.

Controller `data` method (add to `DashboardWidgetController`, plus imports `JsonResponse`, `WidgetDataService`):

```php
public function data(Request $request, string $current_team, DashboardWidget $widget, WidgetDataService $service): JsonResponse
{
    abort_unless($widget->team_id === $request->user()->current_team_id, 404);

    $validated = $request->validate([
        'range' => ['sometimes', Rule::in(array_keys(WidgetDataService::RANGES))],
    ]);

    return response()->json($service->fetch($widget, $validated['range'] ?? '1h'));
}
```

Route (before the reorder/{widget} block ordering doesn't matter for GET, add after destroy):

```php
Route::get('dashboard/widgets/{widget}/data', [DashboardWidgetController::class, 'data'])->name('dashboard.widgets.data');
```

- [ ] **Step 4: Run tests** — `php artisan test --compact --filter=WidgetDataTest` — PASS. Then wayfinder regen: `php artisan wayfinder:generate --no-interaction --with-form`.

- [ ] **Step 5: Pint, larastan, full suite, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --no-progress | tail -5
php artisan test --compact
git add -A && git commit -m "feat: widget data service and data endpoint"
```

---

### Task 4: Frontend foundation — deps, types, dashboard page shell with live widget data

**Files:**
- Modify: `package.json` (via npm install), `resources/js/pages/dashboard.tsx` (full rebuild)
- Create: `resources/js/types/dashboard.ts`, `resources/js/hooks/use-widget-data.ts`, `resources/js/components/dashboard/widget-frame.tsx`
- Reference (read first): `resources/js/pages/logs/index.tsx` (fetch pattern, wayfinder URL building), `resources/js/pages/dashboard.tsx` (current — keep the PendingInvitationsModal behavior and the `.layout` breadcrumbs export exactly as-is)

**Interfaces:**
- Consumes: `dashboard.widgets.data` wayfinder route fn from `@/routes/dashboard/widgets` (regenerated in Task 3); dashboard page props from Task 2.
- Produces: `Widget`, `WidgetType`, `WidgetSize`, `WidgetConfig`, `TimeRange` types in `@/types/dashboard`; `useWidgetData(widget, range, refreshMs)` hook returning `{data, loading, error, reload}`; `WidgetFrame` component (`{widget, children, onEdit, onRemove, onResize, dragHandleProps}` — onEdit/onRemove/onResize optional and unused until Task 6). Grid classes: `third`→`lg:col-span-2`, `half`→`lg:col-span-3`, `full`→`lg:col-span-6` in a `grid grid-cols-1 gap-4 lg:grid-cols-6` container.

- [ ] **Step 1: Install deps**

```bash
npm install recharts @dnd-kit/core @dnd-kit/sortable @dnd-kit/utilities
```

- [ ] **Step 2: Types**

`resources/js/types/dashboard.ts`:

```ts
export type WidgetType = 'timeseries' | 'stat' | 'log_stream' | 'alert_status' | 'server_health';
export type WidgetSize = 'third' | 'half' | 'full';
export type TimeRange = '15m' | '1h' | '6h' | '24h' | '7d';

export type WidgetConfig = {
    server_ids?: number[];
    sources?: string[];
    search?: string | null;
    chart?: 'line' | 'bar' | 'area';
    group_by?: 'server' | 'source' | null;
    window_minutes?: number;
    limit?: number;
    logql?: string | null;
};

export type Widget = {
    id: number;
    type: WidgetType;
    title: string;
    size: WidgetSize;
    position: number;
    config: WidgetConfig;
};

export type SeriesPoint = [number, number];
export type TimeseriesData = { series: { label: string; points: SeriesPoint[] }[] };
export type StatData = { value: number; previous: number };
export type LogStreamData = { entries: { ts: string; time: string; line: string; labels: Record<string, string> }[] };
export type AlertStatusData = { alerts: { id: number; name: string; state: string; enabled: boolean; changedAt: string | null }[] };
export type ServerHealthData = { servers: { id: number; name: string; status: string; configStatus: string }[] };
export type WidgetData = (TimeseriesData | StatData | LogStreamData | AlertStatusData | ServerHealthData) & { error?: string };
```

- [ ] **Step 3: Data hook**

`resources/js/hooks/use-widget-data.ts` — build the URL exactly the way `logs/index.tsx` builds its query URL (read it first; it uses the wayfinder route fn with `{ query: {...} }` options and `credentials: 'same-origin'`-style fetch with an Accept header — copy that idiom):

```ts
import { useCallback, useEffect, useState } from 'react';
import { data as widgetData } from '@/routes/dashboard/widgets';
import type { TimeRange, Widget, WidgetData } from '@/types/dashboard';

export function useWidgetData(teamSlug: string, widget: Widget, range: TimeRange, refreshMs: number | null) {
    const [data, setData] = useState<WidgetData | null>(null);
    const [loading, setLoading] = useState(true);

    const reload = useCallback(async () => {
        try {
            const url = widgetData.url({ current_team: teamSlug, widget: widget.id }, { query: { range } });
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                setData({ error: 'Could not load this widget.' } as WidgetData);
                return;
            }
            setData((await response.json()) as WidgetData);
        } catch {
            setData({ error: 'Could not load this widget.' } as WidgetData);
        } finally {
            setLoading(false);
        }
    }, [teamSlug, widget.id, range]);

    useEffect(() => {
        setLoading(true);
        void reload();
    }, [reload]);

    useEffect(() => {
        if (refreshMs === null) return;
        const interval = setInterval(() => void reload(), refreshMs);
        return () => clearInterval(interval);
    }, [refreshMs, reload]);

    return { data, loading, reload };
}
```

(If the generated wayfinder export name differs — check `resources/js/routes/dashboard/widgets/index.ts` after regen — use the actual export. The route fn for `dashboard.widgets.data` is what you want.)

- [ ] **Step 4: Widget frame**

`resources/js/components/dashboard/widget-frame.tsx`:

```tsx
import { GripVertical, MoreVertical } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator,
    DropdownMenuSub, DropdownMenuSubContent, DropdownMenuSubTrigger, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Widget, WidgetSize } from '@/types/dashboard';

type Props = {
    widget: Widget;
    children: ReactNode;
    onEdit?: () => void;
    onRemove?: () => void;
    onResize?: (size: WidgetSize) => void;
    dragHandleProps?: Record<string, unknown>;
};

export default function WidgetFrame({ widget, children, onEdit, onRemove, onResize, dragHandleProps }: Props) {
    return (
        <Card className="flex h-full flex-col gap-2 py-4">
            <CardHeader className="flex flex-row items-center gap-1 px-4">
                <button
                    type="button"
                    className="cursor-grab text-muted-foreground/50 hover:text-muted-foreground"
                    aria-label="Move widget"
                    {...dragHandleProps}
                >
                    <GripVertical className="size-4" />
                </button>
                <CardTitle className="flex-1 truncate text-sm font-medium">{widget.title}</CardTitle>
                {(onEdit || onRemove || onResize) && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="size-7" aria-label="Widget options">
                                <MoreVertical className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {onEdit && <DropdownMenuItem onSelect={onEdit}>Edit</DropdownMenuItem>}
                            {onResize && (
                                <DropdownMenuSub>
                                    <DropdownMenuSubTrigger>Size</DropdownMenuSubTrigger>
                                    <DropdownMenuSubContent>
                                        {(['third', 'half', 'full'] as const).map((size) => (
                                            <DropdownMenuItem key={size} onSelect={() => onResize(size)} disabled={size === widget.size}>
                                                {size === 'third' ? 'Small (1/3)' : size === 'half' ? 'Medium (1/2)' : 'Full width'}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuSubContent>
                                </DropdownMenuSub>
                            )}
                            {onRemove && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem variant="destructive" onSelect={onRemove}>Remove</DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </CardHeader>
            <CardContent className="min-h-0 flex-1 px-4">{children}</CardContent>
        </Card>
    );
}
```

(Check `components/ui/card.tsx` and `dropdown-menu.tsx` exports first; if `DropdownMenuSub*` primitives are missing from the local dropdown-menu.tsx, add them from radix — the dependency `@radix-ui/react-dropdown-menu` already ships them.)

- [ ] **Step 5: Rebuild `pages/dashboard.tsx`**

Keep: `PendingInvitationsModal` logic and the `Dashboard.layout` breadcrumbs export verbatim. Replace the placeholder grid with:

```tsx
import { Head, usePage } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import WidgetFrame from '@/components/dashboard/widget-frame';
import WidgetBody from '@/components/dashboard/widget-body';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { dashboard } from '@/routes';
import type { DashboardInvitation } from '@/types';
import type { TimeRange, Widget } from '@/types/dashboard';

const SIZE_CLASSES = { third: 'lg:col-span-2', half: 'lg:col-span-3', full: 'lg:col-span-6' } as const;
const RANGES: { value: TimeRange; label: string }[] = [
    { value: '15m', label: 'Last 15 min' },
    { value: '1h', label: 'Last hour' },
    { value: '6h', label: 'Last 6 hours' },
    { value: '24h', label: 'Last 24 hours' },
    { value: '7d', label: 'Last 7 days' },
];

type Props = {
    widgets: Widget[];
    servers: { id: number; name: string }[];
    sources: string[];
    pendingInvitations?: DashboardInvitation[];
};

export default function Dashboard({ widgets, servers, sources, pendingInvitations = [] }: Props) {
    const teamSlug = usePage().props.currentTeam!.slug; // match how sibling pages read the current team from shared props
    const [showInvitations, setShowInvitations] = useState(pendingInvitations.length > 0);
    const [range, setRange] = useState<TimeRange>('1h');
    const [autoRefresh, setAutoRefresh] = useState(false);

    return (
        <>
            <Head title="Dashboard" />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <h1 className="flex-1 text-lg font-semibold">Dashboard</h1>
                    <Select value={range} onValueChange={(value) => setRange(value as TimeRange)}>
                        <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            {RANGES.map((r) => <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Button variant={autoRefresh ? 'default' : 'outline'} size="sm" onClick={() => setAutoRefresh(!autoRefresh)}>
                        <RefreshCw className="size-4" /> {autoRefresh ? '30s' : 'Off'}
                    </Button>
                    {/* Add widget button wired in Task 6 */}
                </div>
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-6">
                    {widgets.map((widget) => (
                        <div key={widget.id} className={SIZE_CLASSES[widget.size]}>
                            <WidgetFrame widget={widget}>
                                <WidgetBody
                                    teamSlug={teamSlug}
                                    widget={widget}
                                    range={range}
                                    refreshMs={autoRefresh ? 30_000 : null}
                                />
                            </WidgetFrame>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
```

Notes for the implementer: check how the current team slug reaches sibling pages (grep `currentTeam` in `resources/js`) and use that exact mechanism — the `usePage().props.currentTeam` line above is illustrative. `servers`/`sources` are unused until Task 6 — still accept them in Props now. Create a minimal `components/dashboard/widget-body.tsx` in THIS task that calls `useWidgetData` and renders: skeleton (`<Skeleton className="h-full min-h-24 w-full" />`) while loading, an error box when `data.error` (message + a small "Retry" button calling `reload`), and `<pre className="text-xs">{JSON.stringify(data)}</pre>` otherwise — Task 5 replaces the `pre` with real bodies.

- [ ] **Step 6: Build + typecheck**

Run: `npm run build` — Expected: success, no TS errors. Run `npm run lint` if quick.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: dashboard shell — grid, range picker, widget data fetching"
```

---

### Task 5: Widget bodies — charts, stat, log stream, statuses

**Files:**
- Create: `resources/js/components/dashboard/widgets/timeseries-widget.tsx`, `stat-widget.tsx`, `log-stream-widget.tsx`, `alert-status-widget.tsx`, `server-health-widget.tsx`, `resources/js/lib/log-level.ts`, `resources/js/lib/chart.ts`
- Modify: `resources/js/components/dashboard/widget-body.tsx` (replace the JSON `pre` with per-type components)

**Interfaces:**
- Consumes: `useWidgetData`, data payload types from Task 4.
- Produces: `detectLevel(line: string): 'error' | 'warn' | null` in `@/lib/log-level` (Task 8 reuses it); `CHART_COLORS: string[]` in `@/lib/chart` (Task 9 reuses it).

- [ ] **Step 1: Shared helpers**

`resources/js/lib/log-level.ts`:

```ts
// ponytail: heuristic only — streams carry no level label; this colors rows, nothing more.
export function detectLevel(line: string): 'error' | 'warn' | null {
    if (/\b(error|err|fatal|critical|exception|panic)\b/i.test(line)) return 'error';
    if (/\b(warn|warning)\b/i.test(line)) return 'warn';
    return null;
}
```

`resources/js/lib/chart.ts`:

```ts
export const CHART_COLORS = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7', '#ec4899', '#84cc16'];
```

- [ ] **Step 2: Timeseries widget**

`timeseries-widget.tsx` — merge series into recharts rows keyed by timestamp; chart kind from config:

```tsx
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { CHART_COLORS } from '@/lib/chart';
import type { TimeRange, TimeseriesData, Widget } from '@/types/dashboard';

function formatTick(ts: number, range: TimeRange): string {
    const date = new Date(ts * 1000);
    return range === '7d' || range === '24h'
        ? date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
        : date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

export default function TimeseriesWidget({ widget, data, range }: { widget: Widget; data: TimeseriesData; range: TimeRange }) {
    if (data.series.length === 0 || data.series.every((s) => s.points.length === 0)) {
        return <p className="flex h-full min-h-24 items-center justify-center text-sm text-muted-foreground">No logs in this range.</p>;
    }

    const rows = new Map<number, Record<string, number>>();
    for (const series of data.series) {
        for (const [ts, value] of series.points) {
            const row = rows.get(ts) ?? {};
            row[series.label] = value;
            rows.set(ts, row);
        }
    }
    const chartData = [...rows.entries()].sort(([a], [b]) => a - b).map(([ts, values]) => ({ ts, ...values }));

    const kind = widget.config.chart ?? 'bar';
    const Chart = kind === 'line' ? LineChart : kind === 'area' ? AreaChart : BarChart;

    return (
        <ResponsiveContainer width="100%" height={220}>
            <Chart data={chartData}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" vertical={false} />
                <XAxis dataKey="ts" tickFormatter={(ts: number) => formatTick(ts, range)} fontSize={11} tickLine={false} axisLine={false} minTickGap={40} />
                <YAxis fontSize={11} tickLine={false} axisLine={false} width={40} allowDecimals={false} />
                <Tooltip labelFormatter={(ts) => formatTick(ts as number, range)} contentStyle={{ fontSize: 12 }} />
                {data.series.map((series, index) => {
                    const color = CHART_COLORS[index % CHART_COLORS.length];
                    if (kind === 'line') return <Line key={series.label} type="monotone" dataKey={series.label} stroke={color} dot={false} strokeWidth={2} />;
                    if (kind === 'area') return <Area key={series.label} type="monotone" dataKey={series.label} stroke={color} fill={color} fillOpacity={0.2} stackId="a" />;
                    return <Bar key={series.label} dataKey={series.label} fill={color} stackId="a" radius={[2, 2, 0, 0]} />;
                })}
            </Chart>
        </ResponsiveContainer>
    );
}
```

- [ ] **Step 3: Stat widget**

```tsx
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import type { StatData, Widget } from '@/types/dashboard';

const formatter = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });

export default function StatWidget({ widget, data }: { widget: Widget; data: StatData }) {
    const delta = data.previous === 0 ? null : ((data.value - data.previous) / data.previous) * 100;
    const window = widget.config.window_minutes ?? 60;

    return (
        <div className="flex h-full min-h-24 flex-col justify-center gap-1">
            <span className="text-3xl font-semibold tabular-nums">{formatter.format(data.value)}</span>
            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                {delta === null ? <Minus className="size-3" /> : delta >= 0 ? <ArrowUpRight className="size-3 text-red-500" /> : <ArrowDownRight className="size-3 text-emerald-500" />}
                {delta === null ? 'No previous data' : `${Math.abs(delta).toFixed(0)}% vs previous ${window} min`}
            </span>
        </div>
    );
}
```

- [ ] **Step 4: Log stream widget**

```tsx
import { Badge } from '@/components/ui/badge';
import { detectLevel } from '@/lib/log-level';
import type { LogStreamData } from '@/types/dashboard';

export default function LogStreamWidget({ data }: { data: LogStreamData }) {
    if (data.entries.length === 0) {
        return <p className="flex h-full min-h-24 items-center justify-center text-sm text-muted-foreground">No logs yet.</p>;
    }

    return (
        <div className="max-h-72 divide-y divide-border/50 overflow-y-auto font-mono text-xs">
            {data.entries.map((entry) => {
                const level = detectLevel(entry.line);
                return (
                    <div key={entry.ts} className="flex items-baseline gap-2 py-1 hover:bg-muted/50">
                        <span className="shrink-0 text-muted-foreground">{new Date(entry.time).toLocaleTimeString()}</span>
                        {level && (
                            <Badge variant={level === 'error' ? 'destructive' : 'secondary'} className="h-4 px-1 text-[10px] uppercase">
                                {level}
                            </Badge>
                        )}
                        <span className="min-w-0 truncate">{entry.line}</span>
                    </div>
                );
            })}
        </div>
    );
}
```

- [ ] **Step 5: Alert status + server health widgets**

`alert-status-widget.tsx`:

```tsx
import { Badge } from '@/components/ui/badge';
import type { AlertStatusData } from '@/types/dashboard';

export default function AlertStatusWidget({ data }: { data: AlertStatusData }) {
    if (data.alerts.length === 0) {
        return <p className="flex h-full min-h-24 items-center justify-center text-sm text-muted-foreground">No alerts configured.</p>;
    }

    return (
        <ul className="flex flex-col gap-2">
            {data.alerts.map((alert) => (
                <li key={alert.id} className="flex items-center gap-2 text-sm">
                    <span className="min-w-0 flex-1 truncate">{alert.name}</span>
                    {!alert.enabled ? (
                        <Badge variant="outline">Disabled</Badge>
                    ) : alert.state === 'firing' ? (
                        <Badge variant="destructive">Firing</Badge>
                    ) : (
                        <Badge variant="secondary">OK</Badge>
                    )}
                </li>
            ))}
        </ul>
    );
}
```

`server-health-widget.tsx` (dot colors: online→emerald, stale→amber, offline→red, pending→muted; check the exact `status()` strings in `app/Models/Server.php` before mapping):

```tsx
import { cn } from '@/lib/utils';
import type { ServerHealthData } from '@/types/dashboard';

const DOT: Record<string, string> = {
    online: 'bg-emerald-500',
    stale: 'bg-amber-500',
    offline: 'bg-red-500',
    pending: 'bg-muted-foreground/40',
};

export default function ServerHealthWidget({ data }: { data: ServerHealthData }) {
    if (data.servers.length === 0) {
        return <p className="flex h-full min-h-24 items-center justify-center text-sm text-muted-foreground">No servers connected.</p>;
    }

    return (
        <ul className="flex flex-col gap-2">
            {data.servers.map((server) => (
                <li key={server.id} className="flex items-center gap-2 text-sm">
                    <span className={cn('size-2 shrink-0 rounded-full', DOT[server.status] ?? 'bg-muted-foreground/40')} />
                    <span className="min-w-0 flex-1 truncate">{server.name}</span>
                    <span className="text-xs capitalize text-muted-foreground">{server.status}</span>
                </li>
            ))}
        </ul>
    );
}
```

- [ ] **Step 6: Wire into `widget-body.tsx`** — replace the JSON `pre` with a switch on `widget.type` rendering the matching component (cast `data` to the per-type payload type). Keep the skeleton/error/retry states from Task 4.

- [ ] **Step 7: Build, commit**

```bash
npm run build
git add -A && git commit -m "feat: dashboard widget bodies — charts, stat, logs, statuses"
```

---

### Task 6: Add/edit dialog, reorder, resize, remove

**Files:**
- Create: `resources/js/components/dashboard/widget-dialog.tsx`
- Modify: `resources/js/pages/dashboard.tsx` (Add-widget button, dnd-kit sortable grid, wire onEdit/onRemove/onResize)
- Reference (read first): `resources/js/pages/alerts/index.tsx` (the `<Form>` + wayfinder `.form()` dialog pattern, multi-checkbox filter UI), `resources/js/components/create-team-modal.tsx`

**Interfaces:**
- Consumes: wayfinder route fns from `@/routes/dashboard/widgets` (`store`, `update`, `destroy`, `reorder`); `Widget` types; `WidgetFrame` optional props from Task 4.
- Produces: `WidgetDialog` component: `{open, onOpenChange, teamSlug, servers, sources, widget}` — `widget: Widget | null` (null = create mode with type picker; non-null = edit mode, type immutable and hidden).

- [ ] **Step 1: WidgetDialog**

Follow the alerts page's `<Form>` dialog idiom exactly (it already solves resets, errors, processing state). Structure:

- Create mode first screen: a 5-card type picker (icon + name + one-line description per type: Timeseries "Log volume over time", Stat "A single number", Log stream "Latest matching logs", Alert status "Your alerts at a glance", Server health "Agent status"). Selecting a type reveals the form.
- Form fields: `title` (Input, required), `size` (Select: Small 1/3, Medium 1/2, Full width), filters section — servers (checkbox list from `servers` prop, checked ids → `config[server_ids][]` hidden inputs, empty = all servers), sources (checkbox list from `sources` prop → `config[sources][]`), search (Input → `config[search]`).
- Per-type extras: timeseries → chart style Select (`config[chart]`: line/bar/area) + group-by Select (`config[group_by]`: none/server/source); stat → window Select (`config[window_minutes]`: 15/30/60/180/360/1440 labelled "15 minutes"…"24 hours"); log_stream → limit Select (`config[limit]`: 10/25/50/100).
- Timeseries/stat only: a Collapsible "Advanced — raw LogQL" containing a `<textarea name="config[logql]" …>` (styled like Input, `font-mono`, 4 rows) with helper text: "When set, this query replaces the filters above. It runs inside your team's tenant only." When the textarea is non-empty, disable the filter inputs (local state).
- Submit via `<Form {...store.form({ current_team: teamSlug })} onSuccess={() => onOpenChange(false)} …>` in create mode, `update.form({ current_team: teamSlug, widget: widget.id })` in edit mode. Show `errors['config.logql']`-style keys with the existing `InputError` component (check how alerts render nested config errors and mirror it).
- IMPORTANT: unchecked checkbox groups submit nothing — that's fine (config keys are optional server-side). But in EDIT mode, to allow clearing a previously-set search/logql, always render the inputs (empty string submits and passes `nullable`).

- [ ] **Step 2: Sortable grid in dashboard.tsx**

- Local state `orderedWidgets` initialised from props (sync via `useEffect` on `widgets` prop change — Inertia refreshes props after CRUD redirects).
- Wrap the grid in `<DndContext collisionDetection={closestCenter} onDragEnd={handleDragEnd}>` + `<SortableContext items={orderedWidgets.map((w) => w.id)} strategy={rectSortingStrategy}>`.
- Each grid cell becomes a `SortableWidget` wrapper using `useSortable({ id: widget.id })`; spread `setNodeRef` + `transform`/`transition` styles (via `CSS.Translate.toString(transform)` from `@dnd-kit/utilities`) on the cell div; pass `{...attributes, ...listeners}` to `WidgetFrame`'s `dragHandleProps`.
- `handleDragEnd`: `arrayMove` the local state, then `router.patch(reorder.url({ current_team: teamSlug }), { ids: newOrder.map((w) => w.id) }, { preserveScroll: true })` — optimistic; on error Inertia's redirect restores server order via the prop sync effect.

- [ ] **Step 3: Wire frame actions**

- `onEdit` → open WidgetDialog with the widget.
- `onResize(size)` → `router.patch(update.url({ current_team: teamSlug, widget: widget.id }), { title: widget.title, size, config: widget.config }, { preserveScroll: true })`.
- `onRemove` → confirm dialog (mirror the delete-confirm pattern in `delete-team-modal.tsx`, lighter: a small Dialog with Cancel/Remove destructive button) → `router.delete(destroy.url(...), { preserveScroll: true })`.
- "Add widget" button in the toolbar opens WidgetDialog with `widget={null}`. Empty dashboard (zero widgets): centered empty state (LayoutDashboard icon, "Your dashboard is empty", "Add widget" button).

- [ ] **Step 4: Build, commit**

```bash
npm run build
git add -A && git commit -m "feat: widget dialog, drag reorder, resize and remove"
```

---

### Task 7: Live smoke test (local)

**Files:** none (verification task; fix anything found and commit fixes).

- [ ] **Step 1:** `php artisan migrate --no-interaction` (local dev DB — creates table + backfills existing teams), `npm run build`.
- [ ] **Step 2:** Full suite: `php artisan test --compact` — everything green.
- [ ] **Step 3:** With Herd + local Loki running (`docker compose up -d`), log in, open the dashboard: 4 default widgets render with real data; create/edit/resize/reorder/remove a widget; switch ranges; enable auto-refresh; enter a bad raw LogQL (`{foo=`) in a timeseries widget and confirm the inline "Query failed" error renders inside the widget; `docker compose stop loki` and confirm widgets show the unavailable error while alert/server widgets still work; `docker compose start loki`.
- [ ] **Step 4:** Commit any fixes: `git add -A && git commit -m "fix: dashboard smoke-test fixes"`.

---

### Task 8: Phase 2 — theme tokens, PageHeader, EmptyState, applied to all index pages

**Files:**
- Modify: `resources/js/app.css` (theme tokens)
- Create: `resources/js/components/page-header.tsx`, `resources/js/components/empty-state.tsx`
- Modify: `resources/js/pages/servers/index.tsx`, `resources/js/pages/alerts/index.tsx`, `resources/js/pages/logs/index.tsx`, `resources/js/pages/teams/index.tsx`, `resources/js/pages/teams/edit.tsx` (headers + empty states)

**Interfaces:**
- Produces: `PageHeader` (`{title, description?, action?: ReactNode}`), `EmptyState` (`{icon: LucideIcon, title, description?, action?: ReactNode}`). Task 9/10 reuse both.

Constraints for this task: purely presentational — do not touch routes, props, form field names, or fetch logic. `php artisan test --compact` must pass unchanged afterward.

- [ ] **Step 1: Theme tokens.** Read `resources/js/app.css`. Set the primary/accent hue to indigo in BOTH light and dark blocks: `--primary: oklch(0.585 0.233 277.117)` (light, indigo-500) with `--primary-foreground` near-white; dark block `--primary: oklch(0.673 0.182 276.935)` (indigo-400) with dark foreground; set `--ring` to match primary. Keep all neutral tokens as-is unless they visibly clash. Verify both modes via the appearance setting.

- [ ] **Step 2: Shared components.**

`page-header.tsx`:

```tsx
import type { ReactNode } from 'react';

export default function PageHeader({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="space-y-1">
                <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            {action && <div className="flex shrink-0 items-center gap-2">{action}</div>}
        </div>
    );
}
```

`empty-state.tsx`:

```tsx
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export default function EmptyState({ icon: Icon, title, description, action }: { icon: LucideIcon; title: string; description?: string; action?: ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed py-16 text-center">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                <Icon className="size-6 text-muted-foreground" />
            </div>
            <div className="space-y-1">
                <p className="text-sm font-medium">{title}</p>
                {description && <p className="max-w-sm text-sm text-muted-foreground">{description}</p>}
            </div>
            {action}
        </div>
    );
}
```

- [ ] **Step 3: Apply per page.** For each page, replace its ad-hoc heading markup with `PageHeader` (moving the page's primary action button into `action`) and its bare "no items" text with `EmptyState`:
  - `servers/index.tsx`: header "Servers" / "Connect Linux servers and manage their log sources." / action = existing Add-server button. Empty: icon `Server`, "No servers yet", "Connect your first server to start shipping logs.", action = the Add-server button.
  - `alerts/index.tsx`: header "Alerts" / "Get notified when your logs go quiet or noisy." Empty alerts: icon `BellRing`, "No alerts yet", "Create an alert to watch your logs for you." Empty channels: icon `Send`, "No notification channels", "Add a webhook, email, or Telegram channel first."
  - `logs/index.tsx`: header "Logs" / "Search and live-tail across all your servers." Empty results (only when a search returned nothing, not while loading): icon `SearchX`, "No matching logs", "Try widening the time range or removing filters."
  - `teams/index.tsx` + `teams/edit.tsx`: apply `PageHeader`; keep all forms/modals untouched.
- [ ] **Step 4: Verify.** `npm run build` clean; `php artisan test --compact` green (Inertia assertions may reference component names only — they must not break; if any test asserted on removed text, update ONLY the assertion string, nothing behavioral).
- [ ] **Step 5: Commit** — `git add -A && git commit -m "polish: theme accent, page headers, empty states"`.

---

### Task 9: Phase 2 — logs viewer polish

**Files:**
- Modify: `resources/js/pages/logs/index.tsx`
- Consumes: `detectLevel` from `@/lib/log-level` (Task 5).

Constraints: visual only — the fetch/live-tail/filter logic, prop names, and request shapes must not change.

- [ ] **Step 1:** Filter bar: wrap the existing filter controls in a `sticky top-0 z-10 -mx-4 border-b bg-background/95 px-4 py-3 backdrop-blur` container so filters stay visible while scrolling results.
- [ ] **Step 2:** Log rows: render each entry as a compact monospace row — `flex items-baseline gap-2 border-b border-border/40 px-2 py-1 font-mono text-xs hover:bg-muted/50`; timestamp in `text-muted-foreground` fixed-width; a level Badge via `detectLevel(entry.line)` (destructive for error, secondary amber-tinted for warn, none otherwise); the line itself `whitespace-pre-wrap break-all`. Preserve the existing source/server label chips if present.
- [ ] **Step 3:** Live-tail affordance: when live mode is on, show a small pulsing dot next to the Pause button (`<span className="size-2 animate-pulse rounded-full bg-emerald-500" />`).
- [ ] **Step 4:** `npm run build`; visual check locally; `git add -A && git commit -m "polish: logs viewer — sticky filters, dense rows, level badges"`.

---

### Task 10: Phase 2 — servers pages polish + recharts ingest chart

**Files:**
- Create: `resources/js/components/charts/daily-bar-chart.tsx`
- Modify: `resources/js/pages/servers/show.tsx` (replace hand-rolled 14-day chart; align cards), `resources/js/pages/servers/index.tsx` (card alignment)
- Consumes: `CHART_COLORS` from `@/lib/chart`, `PageHeader`/`EmptyState` from Task 8.

Constraints: visual only. The `usageSeries` prop shape from the backend is unchanged — read `servers/show.tsx` to see the exact prop (14 days of `{date, bytes}`-like entries) and adapt the chart's `dataKey`s to it.

- [ ] **Step 1:** `daily-bar-chart.tsx` — a small reusable recharts BarChart:

```tsx
import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { CHART_COLORS } from '@/lib/chart';

type Props = {
    data: { label: string; value: number }[];
    height?: number;
    formatValue?: (value: number) => string;
};

export default function DailyBarChart({ data, height = 160, formatValue = String }: Props) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={data}>
                <XAxis dataKey="label" fontSize={10} tickLine={false} axisLine={false} minTickGap={20} />
                <YAxis fontSize={10} tickLine={false} axisLine={false} width={44} tickFormatter={formatValue} />
                <Tooltip formatter={(value) => formatValue(value as number)} contentStyle={{ fontSize: 12 }} />
                <Bar dataKey="value" fill={CHART_COLORS[0]} radius={[3, 3, 0, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}
```

- [ ] **Step 2:** In `servers/show.tsx`, map the existing usage series prop to `{label, value}` (label = short date, value = bytes) and render `<DailyBarChart data={...} formatValue={formatBytes} />` using the page's existing bytes formatter; delete the hand-rolled chart markup. Align the page's cards to the dashboard Card style (consistent paddings/titles as in `WidgetFrame` — `text-sm font-medium` card titles).
- [ ] **Step 3:** `servers/index.tsx`: align server cards (status dot style from `server-health-widget.tsx`, consistent gap/typography).
- [ ] **Step 4:** `npm run build`; `php artisan test --compact` green; `git add -A && git commit -m "polish: servers pages, recharts ingest chart"`.

---

### Task 11: Deploy to server B + live E2E

**Files:** none in-repo (deployment). Follow the established deploy flow from `.superpowers/sdd/progress.md` history.

- [ ] **Step 1:** Local: full suite green, `npm run build`, tarball `public/build`, `git bundle` of `main` (merge happens before this task via finishing-a-development-branch — this task runs post-merge).
- [ ] **Step 2:** Deploy to B (`ssh ubuntu@18.159.103.136`): scp bundle + assets tarball → `sudo git checkout -- .` → fetch/merge --ff-only → extract build → `php artisan migrate --force --no-interaction` (creates `dashboard_widgets`, backfills the existing team's 4 defaults) → `php artisan view:clear && php artisan config:cache && php artisan route:cache` → `chown -R ubuntu:www-data`.
- [ ] **Step 3:** Live E2E at http://18.159.103.136 (login abdurahman.i.work@gmail.com): dashboard shows 4 default widgets with real data from server A's log flow; add a timeseries widget grouped by source; add a stat widget with raw LogQL; reorder by drag; resize; remove; switch every range; auto-refresh on for 1 minute; click through logs/servers/alerts/teams pages verifying the Phase 2 polish renders (headers, empty states, sticky log filters, recharts ingest chart on servers/show).
- [ ] **Step 4:** Record results in `.superpowers/sdd/progress.md`; fix-deploy loop for anything found.

---

## Self-Review Notes

- Spec coverage: data model (T1), CRUD/reorder/props (T2), data endpoint + service + friendly errors (T3), grid/toolbar/fetching (T4), five widget bodies (T5), dialog + dnd + resize/remove + empty dashboard (T6), local smoke (T7), theme/headers/empty states (T8), logs viewer (T9), servers + chart swap (T10), deploy/E2E (T11). Default seeding both in CreateTeam and migration backfill (T1).
- Type consistency: `WidgetDataService::RANGES` keys = frontend `TimeRange`; grid classes = spec's 2/3/6 columns; data payload shapes match `types/dashboard.ts`; `detectLevel`/`CHART_COLORS` defined T5, consumed T9/T10.
- Known judgment areas (implementer verifies against the live repo): exact wayfinder export names after regen, how pages read the current team slug, exact `usageSeries` prop shape, existing dropdown-menu sub-menu exports.
