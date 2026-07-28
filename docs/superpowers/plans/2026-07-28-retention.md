# Retention Policies Implementation Plan (M2 Slice 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Per-team log retention (free-form 1–365 days) with per-server overrides, enforced by Loki through an app-owned runtime overrides file, configurable from the team edit page and server detail page.

**Architecture:** Two retention columns (`teams.retention_days` default 30, `servers.retention_days` nullable = inherit). A `LokiOverridesWriter` renders the complete per-tenant overrides YAML (hand-rendered, atomic temp-file+rename write) and is re-run after every retention change and server/team deletion; `loki:write-overrides` bootstraps/repairs the file. Loki mounts the file read-only via `runtime_config` and its compactor enforces the periods.

**Tech Stack:** Laravel 13, Pest 4, Inertia v3 + React 19, Loki `runtime_config`/`retention_stream`. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-07-28-retention-design.md`

## Global Constraints

- Retention values validated `integer, min 1, max 365` everywhere; team default 30; server `null` = inherit team.
- The overrides file is the single source of truth for Loki: EVERY non-deleted team gets an explicit `retention_period: {days}d` entry; `retention_stream` entries only for servers with non-null `retention_days`. Tenant keys are `team-{id}` (must match the ingest/query OrgID format).
- Writer failures are logged, never thrown — a failed write must not fail the user's request; `loki:write-overrides` repairs.
- Atomic write: temp file in the same directory, then `rename()`.
- Repo quirk: controller methods under `{current_team}` accept `string $current_team` positionally before route-bound models.
- Run `vendor/bin/pint --dirty --format agent` before every commit touching PHP; wayfinder regeneration uses `--with-form`.

---

### Task 1: Columns, writer, command, infra config

**Files:**
- Create: migrations `add_retention_days_to_teams_table`, `add_retention_days_to_servers_table` (via artisan)
- Modify: `app/Models/Team.php` (fillable, cast, PHPDoc, new `servers()` relation)
- Modify: `app/Models/Server.php` (fillable, cast, PHPDoc)
- Create: `app/Services/LokiOverridesWriter.php`
- Create: `app/Console/Commands/WriteLokiOverrides.php`
- Modify: `config/services.php` (`loki.overrides_path`)
- Modify: `docker/loki/loki-config.yaml` (`runtime_config`)
- Modify: `docker-compose.yml` (overrides mount)
- Test: `tests/Feature/Retention/LokiOverridesWriterTest.php`

**Interfaces:**
- Produces:
  - `teams.retention_days` int default 30 (cast int, fillable); `servers.retention_days` nullable int (cast int, fillable).
  - `Team::servers(): HasMany` (new relation — Task 2's controllers and the writer use it).
  - `LokiOverridesWriter::write(): void` (atomic, never throws) and `LokiOverridesWriter::render(): string` (pure, testable).
  - `php artisan loki:write-overrides` command.
  - `config('services.loki.overrides_path')` default `storage_path('app/loki/overrides.yaml')`, env `LOKI_OVERRIDES_PATH`.

- [ ] **Step 1: Generate files**

```bash
php artisan make:migration add_retention_days_to_teams_table --table=teams --no-interaction
php artisan make:migration add_retention_days_to_servers_table --table=servers --no-interaction
php artisan make:class Services/LokiOverridesWriter --no-interaction
php artisan make:command WriteLokiOverrides --command=loki:write-overrides --no-interaction
php artisan make:test --pest Retention/LokiOverridesWriterTest --no-interaction
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Retention/LokiOverridesWriterTest.php`:

```php
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
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=LokiOverridesWriterTest`
Expected: FAIL (class/columns missing).

- [ ] **Step 4: Migrations**

Teams `up()`: `Schema::table('teams', fn (Blueprint $table) => $table->unsignedSmallInteger('retention_days')->default(30));`
Teams `down()`: `Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('retention_days'));`
Servers `up()`: `Schema::table('servers', fn (Blueprint $table) => $table->unsignedSmallInteger('retention_days')->nullable());`
Servers `down()`: `Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('retention_days'));`

- [ ] **Step 5: Model updates**

`app/Models/Team.php`: add `'retention_days'` to `#[Fillable]`; add `'retention_days' => 'integer'` to `casts()`; add `@property int $retention_days` and `@property-read Collection<int, Server> $servers`; add relation (import `App\Models\Server` is same-namespace — no import needed):

```php
/** @return HasMany<Server, $this> */
public function servers(): HasMany
{
    return $this->hasMany(Server::class);
}
```

`app/Models/Server.php`: add `'retention_days'` to `#[Fillable]`; `'retention_days' => 'integer'` in `casts()`; `@property int|null $retention_days`.

- [ ] **Step 6: Config + infra**

`config/services.php` loki block — add: `'overrides_path' => env('LOKI_OVERRIDES_PATH', storage_path('app/loki/overrides.yaml')),`

`docker/loki/loki-config.yaml` — append at top level:

```yaml
runtime_config:
  file: /etc/loki/overrides.yaml
```

`docker-compose.yml` loki volumes — add: `- ./storage/app/loki/overrides.yaml:/etc/loki/overrides.yaml:ro`

- [ ] **Step 7: Writer + command**

`app/Services/LokiOverridesWriter.php`:

```php
<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class LokiOverridesWriter
{
    /**
     * Atomically write the Loki per-tenant retention overrides file.
     * Never throws: the DB is the source of truth and `loki:write-overrides`
     * can repair a failed write; Loki keeps using the previous file meanwhile.
     */
    public function write(): void
    {
        try {
            $path = config('services.loki.overrides_path');
            File::ensureDirectoryExists(dirname($path));

            $temp = $path.'.tmp';
            file_put_contents($temp, $this->render());
            rename($temp, $path);
        } catch (Throwable $exception) {
            Log::error('Failed to write Loki overrides', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * The complete overrides YAML. Hand-rendered: the structure is tiny and
     * every interpolated value is an app-controlled integer.
     */
    public function render(): string
    {
        $teams = Team::orderBy('id')
            ->with(['servers' => fn ($query) => $query->whereNotNull('retention_days')->orderBy('id')])
            ->get();

        $lines = ['overrides:'];

        foreach ($teams as $team) {
            $lines[] = "  team-{$team->id}:";
            $lines[] = "    retention_period: {$team->retention_days}d";

            if ($team->servers->isNotEmpty()) {
                $lines[] = '    retention_stream:';

                foreach ($team->servers as $server) {
                    $lines[] = "      - selector: '{server_id=\"{$server->id}\"}'";
                    $lines[] = '        priority: 1';
                    $lines[] = "        period: {$server->retention_days}d";
                }
            }
        }

        return implode("\n", $lines)."\n";
    }
}
```

`app/Console/Commands/WriteLokiOverrides.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\LokiOverridesWriter;
use Illuminate\Console\Command;

class WriteLokiOverrides extends Command
{
    protected $signature = 'loki:write-overrides';

    protected $description = 'Render and write the Loki per-tenant retention overrides file';

    public function handle(LokiOverridesWriter $writer): int
    {
        $writer->write();

        $this->info('Overrides written to '.config('services.loki.overrides_path'));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 8: Migrate and run tests**

Run: `php artisan migrate --no-interaction && php artisan test --compact --filter=LokiOverridesWriterTest`
Expected: PASS (5 tests). The first test compares the FULL rendered string — if heredoc indentation trips it, print the actual output and fix the *renderer* (2-space nesting per level, trailing newline), not the assertion, unless the renderer is verifiably correct YAML.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: retention columns and Loki overrides writer"
```

---

### Task 2: Endpoints + rewrite triggers + page props

**Files:**
- Modify: `app/Http/Requests/Teams/SaveTeamRequest.php`
- Modify: `app/Http/Controllers/Teams/TeamController.php` (`edit` props + `update`)
- Modify: `app/Http/Controllers/Servers/ServerController.php` (`show` props, new `update`, `destroy` trigger)
- Modify: `routes/web.php` (servers.update route)
- Test: `tests/Feature/Retention/RetentionEndpointsTest.php`

**Interfaces:**
- Consumes: Task 1 columns + `LokiOverridesWriter::write()`.
- Produces:
  - `SaveTeamRequest` accepts optional `retention_days` (`sometimes required integer min:1 max:365`) — MUST stay optional so team creation (which doesn't send it) keeps working.
  - `teams.update` persists `retention_days` when present and triggers a rewrite.
  - New route `servers.update` PATCH `servers/{server}` accepting `{retention_days: int|null}`; persists and triggers rewrite. `servers.destroy` also triggers rewrite.
  - Props for Task 3: `teams/edit` team prop gains `retentionDays: int`; `servers/show` server prop gains `retentionDays: int|null` and page gains `teamRetentionDays: int`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Retention/RetentionEndpointsTest.php`:

```php
<?php

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.loki.overrides_path' => storage_path('framework/testing/overrides-test.yaml')]);
    @unlink(storage_path('framework/testing/overrides-test.yaml'));
});

function retentionOwner(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    return [$user, $team];
}

test('team retention can be updated and rewrites the overrides file', function () {
    [$user, $team] = retentionOwner();

    $this->actingAs($user)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'retention_days' => 90])
        ->assertRedirect();

    expect($team->fresh()->retention_days)->toBe(90)
        ->and(file_get_contents(config('services.loki.overrides_path')))->toContain('retention_period: 90d');
});

test('team retention is validated to 1-365', function () {
    [$user, $team] = retentionOwner();

    $this->actingAs($user)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'retention_days' => 0])
        ->assertSessionHasErrors('retention_days');

    $this->actingAs($user)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'retention_days' => 366])
        ->assertSessionHasErrors('retention_days');
});

test('team updates without retention_days keep the current value', function () {
    [$user, $team] = retentionOwner();
    $team->update(['retention_days' => 60]);

    $this->actingAs($user)
        ->patch(route('teams.update', $team), ['name' => 'Renamed'])
        ->assertRedirect();

    expect($team->fresh()->retention_days)->toBe(60);
});

test('members cannot change team retention', function () {
    [, $team] = retentionOwner();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->update(['current_team_id' => $team->id]);

    $this->actingAs($member)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'retention_days' => 5])
        ->assertForbidden();
});

test('the team edit page includes retentionDays', function () {
    [$user, $team] = retentionOwner();

    $this->actingAs($user)
        ->get(route('teams.edit', $team))
        ->assertInertia(fn (Assert $page) => $page->where('team.retentionDays', 30));
});

test('server retention override can be set and cleared', function () {
    [$user, $team] = retentionOwner();
    $server = Server::factory()->for($team)->create();

    $this->actingAs($user)
        ->patch(route('servers.update', ['current_team' => $team->slug, 'server' => $server->id]), ['retention_days' => 7])
        ->assertRedirect();

    expect($server->fresh()->retention_days)->toBe(7)
        ->and(file_get_contents(config('services.loki.overrides_path')))->toContain('period: 7d');

    $this->actingAs($user)
        ->patch(route('servers.update', ['current_team' => $team->slug, 'server' => $server->id]), ['retention_days' => null])
        ->assertRedirect();

    expect($server->fresh()->retention_days)->toBeNull()
        ->and(file_get_contents(config('services.loki.overrides_path')))->not->toContain('retention_stream');
});

test('foreign servers cannot be updated', function () {
    [$user, $team] = retentionOwner();
    $foreign = Server::factory()->create();

    $this->actingAs($user)
        ->patch(route('servers.update', ['current_team' => $team->slug, 'server' => $foreign->id]), ['retention_days' => 7])
        ->assertNotFound();
});

test('deleting a server rewrites the overrides file', function () {
    [$user, $team] = retentionOwner();
    $server = Server::factory()->for($team)->create(['retention_days' => 7]);
    app(App\Services\LokiOverridesWriter::class)->write();
    expect(file_get_contents(config('services.loki.overrides_path')))->toContain('retention_stream');

    $this->actingAs($user)
        ->delete(route('servers.destroy', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertRedirect();

    expect(file_get_contents(config('services.loki.overrides_path')))->not->toContain('retention_stream');
});

test('deleting a team rewrites the overrides file without it', function () {
    [$user, $team] = retentionOwner();
    app(App\Services\LokiOverridesWriter::class)->write();
    expect(file_get_contents(config('services.loki.overrides_path')))->toContain("team-{$team->id}:");

    $this->actingAs($user)
        ->delete(route('teams.destroy', $team), ['name' => $team->name])
        ->assertRedirect();

    expect(file_get_contents(config('services.loki.overrides_path')))->not->toContain("team-{$team->id}:");
});

test('the server page includes retention props', function () {
    [$user, $team] = retentionOwner();
    $server = Server::factory()->for($team)->create(['retention_days' => 7]);

    $this->actingAs($user)
        ->get(route('servers.show', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('server.retentionDays', 7)
            ->where('teamRetentionDays', 30),
        );
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RetentionEndpointsTest`
Expected: FAIL (validation/routes/props missing).

- [ ] **Step 3: Implement**

`SaveTeamRequest::rules()` — add: `'retention_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],`

`TeamController::edit` — add to the `'team' => [...]` array: `'retentionDays' => $team->retention_days,`

`TeamController::update` — inject the writer and persist both fields (replace the `$team->update(...)` line inside the transaction):

```php
public function update(SaveTeamRequest $request, Team $team, LokiOverridesWriter $overrides): RedirectResponse
{
    Gate::authorize('update', $team);

    $team = DB::transaction(function () use ($request, $team) {
        $team = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();

        $team->update($request->safe()->only(['name', 'retention_days']));

        return $team;
    });

    $overrides->write();

    Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

    return to_route('teams.edit', ['team' => $team->slug]);
}
```

(import `App\Services\LokiOverridesWriter`)

`ServerController`:
- `show`: add `'retentionDays' => $server->retention_days,` inside the `'server' => [...]` array and `'teamRetentionDays' => $server->team->retention_days,` as a top-level prop.
- New `update` method (register import for `LokiOverridesWriter`):

```php
/**
 * Update server settings (currently just the retention override).
 */
public function update(Request $request, string $current_team, Server $server, LokiOverridesWriter $overrides): RedirectResponse
{
    $this->authorizeServer($request, $server);

    $validated = $request->validate([
        'retention_days' => ['present', 'nullable', 'integer', 'min:1', 'max:365'],
    ]);

    $server->update(['retention_days' => $validated['retention_days']]);

    $overrides->write();

    Inertia::flash('toast', ['type' => 'success', 'message' => __('Retention updated.')]);

    return back();
}
```

- `destroy`: inject `LokiOverridesWriter $overrides` as the last parameter and call `$overrides->write();` after `$server->delete();`.

`TeamController::destroy` — same treatment: inject `LokiOverridesWriter $overrides` as the last parameter and call `$overrides->write();` after the deletion transaction completes (a soft-deleted team must drop out of the overrides file immediately).

`routes/web.php` — inside the `{current_team}` group after `servers.show`:

```php
Route::patch('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter="RetentionEndpointsTest|TeamTest|ServerControllerTest"`
Expected: PASS — 9 new + all existing team/server tests stay green (the `sometimes` rule keeps team creation working).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: retention endpoints with overrides rewrite triggers"
```

---

### Task 3: Settings UI

**Files:**
- Modify: `resources/js/pages/teams/edit.tsx`
- Modify: `resources/js/pages/servers/show.tsx`
- Modify: `resources/js/types/logging.ts` (ServerDetail gains `retentionDays: number | null`)

**Interfaces:**
- Consumes: Task 2 props (`team.retentionDays: number`; `server.retentionDays: number|null` + `teamRetentionDays: number`), `servers.update` route (regenerate wayfinder with `--with-form`), existing form conventions.

- [ ] **Step 1: Regenerate wayfinder routes**

Run: `php artisan wayfinder:generate --no-interaction --with-form`

- [ ] **Step 2: Team edit page**

In `resources/js/pages/teams/edit.tsx`: read the page first; add `retentionDays: number` to its team prop type and a "Log retention (days)" number input (min 1, max 365) to the existing team-settings form so it submits alongside `name` (the backend accepts both in `teams.update`). Helper text below the field: "How long logs are kept. Lowering this permanently deletes logs older than the new value." Respect the page's existing permission gating for the name field (render the input disabled when the user cannot update the team).

- [ ] **Step 3: Server retention card**

In `resources/js/pages/servers/show.tsx`: add `retentionDays: number | null` to `ServerDetail` (in `types/logging.ts`) and accept the new `teamRetentionDays: number` page prop. Add a small "Retention" card between the Agent health section and Log sources:
- A Select with two options: `Team default ({teamRetentionDays} days)` (value `default`) and `Custom` (value `custom`); when Custom, show a number input (min 1, max 365) initialized to `server.retentionDays ?? teamRetentionDays`.
- A Save button submitting `{retention_days: <number or null>}` via `router.patch` to the `servers.update` wayfinder route (null when "Team default" selected).
- Same helper copy as the team field.

- [ ] **Step 4: Build + lint + contract check**

Run: `npm run build && npm run lint && php artisan test --compact --filter=RetentionEndpointsTest`
Expected: zero TS errors, lint clean, tests green.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: retention settings UI for teams and servers"
```

---

### Task 4: Verification + deploy to server B

**Files:** none new locally.

- [ ] **Step 1: Full local verification**

```bash
php artisan test --compact
vendor/bin/phpstan analyse --memory-limit=512M
npm run lint && npm run build
vendor/bin/pint --dirty --format agent
```

Expected: suite green, phpstan 0, build clean. Commit fixes if any.

- [ ] **Step 2: Local Loki mount check**

```bash
php artisan loki:write-overrides
docker compose up -d --force-recreate loki
sleep 20 && curl -s http://localhost:3100/ready
docker compose exec loki cat /etc/loki/overrides.yaml
```

Expected: `ready`; the container sees the rendered overrides (with `team-1: retention_period: 30d`).

- [ ] **Step 3: Deploy to server B**

```bash
git bundle create /tmp/lina-retention.bundle HEAD
tar -czf /tmp/build-retention.tgz public/build
scp /tmp/lina-retention.bundle /tmp/build-retention.tgz ubuntu@18.159.103.136:/tmp/
ssh ubuntu@18.159.103.136 '
  cd /var/www/lina &&
  sudo git checkout -- . &&
  sudo git fetch /tmp/lina-retention.bundle HEAD && sudo git merge --ff-only FETCH_HEAD &&
  sudo tar -xzf /tmp/build-retention.tgz -C /var/www/lina 2>/dev/null;
  php artisan migrate --force --no-interaction &&
  php artisan loki:write-overrides &&
  php artisan view:clear -q && php artisan config:cache -q && php artisan route:cache -q &&
  sudo chown -R ubuntu:www-data /var/www/lina && sudo chmod -R g+w storage bootstrap/cache database &&
  sudo docker compose up -d --force-recreate loki &&
  sleep 20 && curl -s http://localhost:3100/ready &&
  sudo docker compose exec loki cat /etc/loki/overrides.yaml
'
```

Expected: `ready` + the overrides file content from inside the container.

- [ ] **Step 4: Live check**

```bash
ssh ubuntu@18.159.103.136 'cd /var/www/lina && php artisan tinker --execute "
App\Models\Server::first()->update([\"retention_days\" => 7]);
app(App\Services\LokiOverridesWriter::class)->write();" && sudo docker compose -f /var/www/lina/docker-compose.yml exec loki cat /etc/loki/overrides.yaml | grep -A2 retention_stream && cd /var/www/lina && php artisan tinker --execute "App\Models\Server::first()->update([\"retention_days\" => null]); app(App\Services\LokiOverridesWriter::class)->write(); echo \"reset\";"'
```

Expected: the stream rule with `period: 7d` visible inside the container, then reset. Also verify ingestion still works (server A stays online) and the UI shows both retention controls.

- [ ] **Step 5: Commit any fixes**

```bash
git add -A && git commit -m "chore: retention verification fixes" # only if needed
```
