# Logging Platform M1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship milestone 1 of the logging platform: onboard Linux servers with a one-line Alloy install, ingest their logs into multi-tenant Loki through a Laravel gateway, configure log sources per server, and search/live-tail logs in the dashboard.

**Architecture:** The existing Team model is the tenant; Loki OrgID = `team-{id}`. The gateway never parses log payloads — it authenticates a per-server bearer token and forwards raw Loki push bodies with `X-Scope-OrgID`. Laravel renders complete Alloy configs; a bash updater on each server polls them via systemd timer.

**Tech Stack:** Laravel 13, Inertia v3 + React 19, Pest 4, Wayfinder, Tailwind v4 + shadcn ui, Grafana Loki (Docker), Grafana Alloy (agent).

**Spec:** `docs/superpowers/specs/2026-07-23-logging-platform-m1-design.md`

## Global Constraints

- Tenant isolation is header-based: the ONLY thing that determines Loki OrgID is the authenticated server's `team_id`. Never trust anything from the request body or query for tenancy.
- Tokens: 40-char `Str::random()`, stored only as SHA-256 hex in `servers.token_hash`, shown to the user exactly once.
- Users never write raw LogQL; selectors are built server-side from validated filters.
- Loki is never publicly exposed; Laravel is its only client. Base URL from `config('services.loki.url')`.
- Follow existing codebase conventions: controllers in `app/Http/Controllers/<Domain>/`, FormRequests in `app/Http/Requests/<Domain>/`, Pest feature tests with `route()` helper, Wayfinder imports from `@/routes/*`, shadcn components from `@/components/ui/*`.
- Run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- Run tests with `php artisan test --compact --filter=<name>` (min needed per step).
- Agent-facing env var names on customer servers: `LINA_TOKEN`, `LINA_BASE_URL` (in `/etc/default/alloy` or `/etc/sysconfig/alloy`).
- The agent push endpoint is `POST /api/agent/push`; config poll is `GET /api/agent/config`. These paths are baked into generated configs and the install script — keep them in sync.

---

### Task 1: Loki infrastructure

**Files:**
- Create: `docker-compose.yml`
- Create: `docker/loki/loki-config.yaml`
- Modify: `config/services.php`
- Modify: `.env.example`

**Interfaces:**
- Produces: `config('services.loki.url')` (string, default `http://localhost:3100`) — used by Tasks 7 and 9.

- [ ] **Step 1: Create `docker/loki/loki-config.yaml`**

```yaml
auth_enabled: true

server:
  http_listen_port: 3100

common:
  instance_addr: 127.0.0.1
  path_prefix: /loki
  storage:
    filesystem:
      chunks_directory: /loki/chunks
      rules_directory: /loki/rules
  replication_factor: 1
  ring:
    kvstore:
      store: inmemory

schema_config:
  configs:
    - from: 2024-01-01
      store: tsdb
      object_store: filesystem
      schema: v13
      index:
        prefix: index_
        period: 24h

limits_config:
  retention_period: 744h # 31 days; per-tenant retention is a later milestone

compactor:
  working_directory: /loki/compactor
  retention_enabled: true
  delete_request_store: filesystem
```

- [ ] **Step 2: Create `docker-compose.yml`**

```yaml
services:
  loki:
    image: grafana/loki:3.4.2
    command: -config.file=/etc/loki/loki-config.yaml
    ports:
      - '127.0.0.1:3100:3100'
    volumes:
      - ./docker/loki/loki-config.yaml:/etc/loki/loki-config.yaml:ro
      - loki-data:/loki
    restart: unless-stopped

volumes:
  loki-data:
```

- [ ] **Step 3: Add Loki to `config/services.php`**

Add to the returned array:

```php
'loki' => [
    'url' => env('LOKI_URL', 'http://localhost:3100'),
    'ingest_rpm' => (int) env('LOKI_INGEST_RPM', 300),
],
```

- [ ] **Step 4: Add to `.env.example`** (after the `APP_URL` line):

```
LOKI_URL=http://localhost:3100
```

Also add `LOKI_URL=http://localhost:3100` to `.env`.

- [ ] **Step 5: Verify Loki boots**

Run: `docker compose up -d && sleep 15 && curl -s http://localhost:3100/ready`
Expected: `ready` (may need one retry; Loki takes ~15s to become ready).

- [ ] **Step 6: Commit**

```bash
git add docker-compose.yml docker/loki/loki-config.yaml config/services.php .env.example
git commit -m "feat: add Loki docker-compose infrastructure"
```

---

### Task 2: Data layer — servers, log_sources, usage_records

**Files:**
- Create: `database/migrations/<timestamp>_create_servers_table.php` (via artisan)
- Create: `database/migrations/<timestamp>_create_log_sources_table.php`
- Create: `database/migrations/<timestamp>_create_usage_records_table.php`
- Create: `app/Models/Server.php`, `app/Models/LogSource.php`, `app/Models/UsageRecord.php`
- Create: `app/Enums/LogSourceType.php`
- Create: `database/factories/ServerFactory.php`, `database/factories/LogSourceFactory.php`
- Test: `tests/Feature/Servers/ServerModelTest.php`

**Interfaces:**
- Produces:
  - `Server::newToken(): string` — regenerates + saves `token_hash`, returns plaintext.
  - `Server::findByToken(?string $plainToken): ?Server` (static).
  - `Server::status(): string` — `'pending' | 'online' | 'offline'` (online = seen within 5 minutes).
  - `Server::bytesToday(): int`
  - Relations: `Server::team()`, `Server::logSources()`, `Server::usageRecords()`; `LogSource::server()`.
  - `LogSourceType` enum: `Systemd = 'systemd'`, `Docker = 'docker'`, `Nginx = 'nginx'`, `File = 'file'`.
  - `LogSource` fields: `server_id`, `type` (cast to `LogSourceType`), `name`, `config` (array cast), `enabled` (bool).
  - `UsageRecord` fields: `server_id`, `date` (date cast), `bytes` (int).
  - Factory states: `Server::factory()->online()`, `Server::factory()->offline()`.

- [ ] **Step 1: Generate files**

```bash
php artisan make:model Server -mf --no-interaction
php artisan make:model LogSource -mf --no-interaction
php artisan make:model UsageRecord -m --no-interaction
php artisan make:enum LogSourceType --string --no-interaction
php artisan make:test --pest Servers/ServerModelTest --no-interaction
```

- [ ] **Step 2: Write the failing model test**

`tests/Feature/Servers/ServerModelTest.php`:

```php
<?php

use App\Models\Server;

test('newToken stores a sha256 hash and returns the plaintext once', function () {
    $server = Server::factory()->create();

    $plain = $server->newToken();

    expect(strlen($plain))->toBe(40)
        ->and($server->fresh()->token_hash)->toBe(hash('sha256', $plain));
});

test('findByToken resolves a server from the plaintext token', function () {
    $server = Server::factory()->create();
    $plain = $server->newToken();

    expect(Server::findByToken($plain)?->id)->toBe($server->id)
        ->and(Server::findByToken('wrong-token'))->toBeNull()
        ->and(Server::findByToken(null))->toBeNull();
});

test('status is derived from last_seen_at', function () {
    expect(Server::factory()->create(['last_seen_at' => null])->status())->toBe('pending')
        ->and(Server::factory()->online()->create()->status())->toBe('online')
        ->and(Server::factory()->offline()->create()->status())->toBe('offline');
});

test('bytesToday sums only today', function () {
    $server = Server::factory()->create();
    $server->usageRecords()->create(['date' => today(), 'bytes' => 100]);
    $server->usageRecords()->create(['date' => today()->subDay(), 'bytes' => 900]);

    expect($server->bytesToday())->toBe(100);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=ServerModelTest`
Expected: FAIL (missing columns/methods).

- [ ] **Step 4: Write the migrations**

`create_servers_table.php` `up()`:

```php
Schema::create('servers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('token_hash', 64)->unique();
    $table->string('hostname')->nullable();
    $table->string('agent_version')->nullable();
    $table->timestamp('last_seen_at')->nullable();
    $table->timestamps();
});
```

`create_log_sources_table.php` `up()`:

```php
Schema::create('log_sources', function (Blueprint $table) {
    $table->id();
    $table->foreignId('server_id')->constrained()->cascadeOnDelete();
    $table->string('type');
    $table->string('name');
    $table->json('config')->nullable();
    $table->boolean('enabled')->default(true);
    $table->timestamps();
});
```

`create_usage_records_table.php` `up()`:

```php
Schema::create('usage_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('server_id')->constrained()->cascadeOnDelete();
    $table->date('date');
    $table->unsignedBigInteger('bytes')->default(0);
    $table->timestamps();
    $table->unique(['server_id', 'date']);
});
```

- [ ] **Step 5: Write the enum**

`app/Enums/LogSourceType.php`:

```php
<?php

namespace App\Enums;

enum LogSourceType: string
{
    case Systemd = 'systemd';
    case Docker = 'docker';
    case Nginx = 'nginx';
    case File = 'file';
}
```

- [ ] **Step 6: Write the models**

`app/Models/Server.php`:

```php
<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $token_hash
 * @property string|null $hostname
 * @property string|null $agent_version
 * @property Carbon|null $last_seen_at
 * @property-read Team $team
 * @property-read Collection<int, LogSource> $logSources
 * @property-read Collection<int, UsageRecord> $usageRecords
 */
#[Fillable(['team_id', 'name', 'token_hash', 'hostname', 'agent_version', 'last_seen_at'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /**
     * Regenerate the server token, returning the plaintext exactly once.
     */
    public function newToken(): string
    {
        $plain = Str::random(40);

        $this->forceFill(['token_hash' => hash('sha256', $plain)])->save();

        return $plain;
    }

    /**
     * Resolve a server from a plaintext bearer token.
     */
    public static function findByToken(?string $plainToken): ?self
    {
        if ($plainToken === null || $plainToken === '') {
            return null;
        }

        return static::where('token_hash', hash('sha256', $plainToken))->first();
    }

    /**
     * Derived agent status: pending until first contact, offline after 5 minutes of silence.
     */
    public function status(): string
    {
        return match (true) {
            $this->last_seen_at === null => 'pending',
            $this->last_seen_at->gt(now()->subMinutes(5)) => 'online',
            default => 'offline',
        };
    }

    /**
     * Bytes ingested today.
     */
    public function bytesToday(): int
    {
        return (int) $this->usageRecords()->whereDate('date', today())->sum('bytes');
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<LogSource, $this> */
    public function logSources(): HasMany
    {
        return $this->hasMany(LogSource::class);
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
```

`app/Models/LogSource.php`:

```php
<?php

namespace App\Models;

use App\Enums\LogSourceType;
use Database\Factories\LogSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property LogSourceType $type
 * @property string $name
 * @property array<string, mixed>|null $config
 * @property bool $enabled
 * @property-read Server $server
 */
#[Fillable(['server_id', 'type', 'name', 'config', 'enabled'])]
class LogSource extends Model
{
    /** @use HasFactory<LogSourceFactory> */
    use HasFactory;

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LogSourceType::class,
            'config' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
```

`app/Models/UsageRecord.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property Carbon $date
 * @property int $bytes
 * @property-read Server $server
 */
#[Fillable(['server_id', 'date', 'bytes'])]
class UsageRecord extends Model
{
    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'bytes' => 'integer',
        ];
    }
}
```

- [ ] **Step 7: Write the factories**

`database/factories/ServerFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => $this->faker->unique()->domainWord().'-server',
            'token_hash' => hash('sha256', Str::random(40)),
            'hostname' => null,
            'agent_version' => null,
            'last_seen_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subSeconds(30), 'hostname' => $this->faker->domainName()]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subHour(), 'hostname' => $this->faker->domainName()]);
    }
}
```

`database/factories/LogSourceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\LogSourceType;
use App\Models\LogSource;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogSource>
 */
class LogSourceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'type' => LogSourceType::Systemd,
            'name' => $this->faker->unique()->word(),
            'config' => [],
            'enabled' => true,
        ];
    }

    public function file(array $paths = ['/var/log/app/*.log']): static
    {
        return $this->state(fn () => ['type' => LogSourceType::File, 'config' => ['paths' => $paths]]);
    }

    public function nginx(): static
    {
        return $this->state(fn () => ['type' => LogSourceType::Nginx, 'config' => ['access_log' => '/var/log/nginx/access.log', 'error_log' => '/var/log/nginx/error.log']]);
    }

    public function docker(array $containers = []): static
    {
        return $this->state(fn () => ['type' => LogSourceType::Docker, 'config' => ['containers' => $containers]]);
    }

    public function systemd(array $units = []): static
    {
        return $this->state(fn () => ['type' => LogSourceType::Systemd, 'config' => ['units' => $units]]);
    }
}
```

- [ ] **Step 8: Run migrations and the test**

Run: `php artisan migrate --no-interaction && php artisan test --compact --filter=ServerModelTest`
Expected: PASS (4 tests).

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add Server, LogSource, UsageRecord data layer"
```

---

### Task 3: Server CRUD backend

**Files:**
- Create: `app/Http/Controllers/Servers/ServerController.php`
- Create: `app/Http/Requests/Servers/StoreServerRequest.php`
- Modify: `routes/web.php` (inside the existing `{current_team}` group)
- Test: `tests/Feature/Servers/ServerControllerTest.php`

**Interfaces:**
- Consumes: `Server` model from Task 2.
- Produces routes (all under `{current_team}` prefix, `auth + verified + EnsureTeamMembership` middleware):
  - `servers.index` GET `servers` → Inertia `servers/index`, prop `servers: [{id, name, hostname, status, lastSeenAt, bytesToday}]`
  - `servers.store` POST `servers` → creates server + token, redirects to `servers.show` with session-flashed `plainToken`
  - `servers.show` GET `servers/{server}` → Inertia `servers/show`, props `server`, `sources`, `plainToken` (nullable), `installUrl`
  - `servers.token` POST `servers/{server}/token` → regenerates token, redirect back to show with flashed `plainToken`
  - `servers.destroy` DELETE `servers/{server}`
- Produces for later tasks: the *team-scoping guard pattern* — `abort_unless($server->team_id === $request->user()->current_team_id, 404);`
- `installUrl` prop format: `route('servers.install', ['token' => ...])` — but the plaintext token only exists at creation, so `installUrl` is built client-side from `plainToken`; the controller passes `installBaseUrl: url('/install')` instead. Full command shown in UI: `curl -sSL {installBaseUrl}/{plainToken} | sudo bash`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Servers/ServerControllerTest.php`:

```php
<?php

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function memberWithTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    return [$user, $team];
}

test('servers index lists only current team servers', function () {
    [$user, $team] = memberWithTeam();
    $mine = Server::factory()->for($team)->create();
    Server::factory()->create(); // other team

    $this->actingAs($user)
        ->get(route('servers.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/index')
            ->has('servers', 1)
            ->where('servers.0.id', $mine->id)
            ->where('servers.0.status', 'pending'),
        );
});

test('storing a server flashes the plaintext token once', function () {
    [$user, $team] = memberWithTeam();

    $response = $this->actingAs($user)
        ->post(route('servers.store', ['current_team' => $team->slug]), ['name' => 'web-1']);

    $server = Server::where('team_id', $team->id)->firstOrFail();
    $response->assertRedirect(route('servers.show', ['current_team' => $team->slug, 'server' => $server->id]));

    $plain = session('plainToken');
    expect($plain)->not->toBeNull()
        ->and($server->token_hash)->toBe(hash('sha256', $plain));
});

test('show page renders server with sources', function () {
    [$user, $team] = memberWithTeam();
    $server = Server::factory()->for($team)->online()->create();
    $server->logSources()->create(['type' => 'systemd', 'name' => 'journal', 'config' => [], 'enabled' => true]);

    $this->actingAs($user)
        ->get(route('servers.show', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/show')
            ->where('server.id', $server->id)
            ->where('server.status', 'online')
            ->has('sources', 1)
            ->where('plainToken', null),
        );
});

test('servers of other teams 404', function () {
    [$user, $team] = memberWithTeam();
    $foreign = Server::factory()->create();

    $this->actingAs($user)
        ->get(route('servers.show', ['current_team' => $team->slug, 'server' => $foreign->id]))
        ->assertNotFound();
});

test('token can be regenerated', function () {
    [$user, $team] = memberWithTeam();
    $server = Server::factory()->for($team)->create();
    $oldHash = $server->token_hash;

    $this->actingAs($user)
        ->post(route('servers.token', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertRedirect();

    expect($server->fresh()->token_hash)->not->toBe($oldHash)
        ->and(session('plainToken'))->not->toBeNull();
});

test('servers can be deleted with their sources', function () {
    [$user, $team] = memberWithTeam();
    $server = Server::factory()->for($team)->create();
    $server->logSources()->create(['type' => 'file', 'name' => 'app', 'config' => ['paths' => ['/tmp/a.log']], 'enabled' => true]);

    $this->actingAs($user)
        ->delete(route('servers.destroy', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertRedirect(route('servers.index', ['current_team' => $team->slug]));

    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    $this->assertDatabaseCount('log_sources', 0);
});

test('guests cannot access servers', function () {
    $team = Team::factory()->create();

    $this->get(route('servers.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ServerControllerTest`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Write request + controller**

`app/Http/Requests/Servers/StoreServerRequest.php`:

```php
<?php

namespace App\Http\Requests\Servers;

use Illuminate\Foundation\Http\FormRequest;

class StoreServerRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
```

`app/Http/Controllers/Servers/ServerController.php`:

```php
<?php

namespace App\Http\Controllers\Servers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\StoreServerRequest;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    /**
     * List the current team's servers.
     */
    public function index(Request $request): Response
    {
        $servers = Server::where('team_id', $request->user()->current_team_id)
            ->latest()
            ->get()
            ->map(fn (Server $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'status' => $server->status(),
                'lastSeenAt' => $server->last_seen_at?->toISOString(),
                'bytesToday' => $server->bytesToday(),
            ]);

        return Inertia::render('servers/index', ['servers' => $servers]);
    }

    /**
     * Create a server and flash its plaintext token exactly once.
     */
    public function store(StoreServerRequest $request): RedirectResponse
    {
        $server = Server::create([
            'team_id' => $request->user()->current_team_id,
            'name' => $request->validated('name'),
            'token_hash' => 'pending',
        ]);

        $plain = $server->newToken();

        return to_route('servers.show', ['current_team' => $request->route('current_team'), 'server' => $server->id])
            ->with('plainToken', $plain);
    }

    /**
     * Show a server with its log sources.
     */
    public function show(Request $request, Server $server): Response
    {
        $this->authorizeServer($request, $server);

        return Inertia::render('servers/show', [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'agentVersion' => $server->agent_version,
                'status' => $server->status(),
                'lastSeenAt' => $server->last_seen_at?->toISOString(),
                'bytesToday' => $server->bytesToday(),
            ],
            'sources' => $server->logSources()->get()->map(fn ($source) => [
                'id' => $source->id,
                'type' => $source->type->value,
                'name' => $source->name,
                'config' => $source->config ?? [],
                'enabled' => $source->enabled,
            ]),
            'plainToken' => session('plainToken'),
            'installBaseUrl' => url('/install'),
        ]);
    }

    /**
     * Regenerate the server's token; the old one stops working immediately.
     */
    public function regenerateToken(Request $request, Server $server): RedirectResponse
    {
        $this->authorizeServer($request, $server);

        $plain = $server->newToken();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token regenerated. Update the server before it goes offline.')]);

        return back()->with('plainToken', $plain);
    }

    /**
     * Delete the server.
     */
    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $this->authorizeServer($request, $server);

        $server->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server deleted.')]);

        return to_route('servers.index', ['current_team' => $request->route('current_team')]);
    }

    /**
     * Servers are only visible within their own team.
     */
    protected function authorizeServer(Request $request, Server $server): void
    {
        abort_unless($server->team_id === $request->user()->current_team_id, 404);
    }
}
```

- [ ] **Step 4: Register routes**

In `routes/web.php`, inside the existing `Route::prefix('{current_team}')` group (after the dashboard route):

```php
Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
Route::post('servers/{server}/token', [ServerController::class, 'regenerateToken'])->name('servers.token');
Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
```

Add `use App\Http\Controllers\Servers\ServerController;` at the top.

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter=ServerControllerTest`
Expected: PASS (7 tests). Inertia page components don't exist yet — `assertInertia` does not render the JS page, so this passes without frontend files.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: server CRUD with one-time token flow"
```

---

### Task 4: Log source backend

**Files:**
- Create: `app/Http/Controllers/Servers/LogSourceController.php`
- Create: `app/Http/Requests/Servers/SaveLogSourceRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Servers/LogSourceTest.php`

**Interfaces:**
- Consumes: `Server`, `LogSource`, `LogSourceType`, team-scoping guard pattern from Task 3.
- Produces routes (under `{current_team}`):
  - `servers.sources.store` POST `servers/{server}/sources`
  - `servers.sources.update` PATCH `servers/{server}/sources/{source}`
  - `servers.sources.destroy` DELETE `servers/{server}/sources/{source}`
- Validated `config` shape per type (this is the canonical shape Tasks 5 and 10 rely on):
  - `systemd`: `{units?: string[]}` (empty = whole journal)
  - `docker`: `{containers?: string[]}` (empty = all containers)
  - `nginx`: `{access_log: string, error_log: string}`
  - `file`: `{paths: string[]}` (required, non-empty)

- [ ] **Step 1: Write the failing test**

`tests/Feature/Servers/LogSourceTest.php`:

```php
<?php

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

function sourceOwner(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);
    $server = Server::factory()->for($team)->create();

    return [$user, $team, $server];
}

test('a file source can be created', function () {
    [$user, $team, $server] = sourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'file',
            'name' => 'app-logs',
            'config' => ['paths' => ['/var/log/app/*.log']],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('log_sources', ['server_id' => $server->id, 'type' => 'file', 'name' => 'app-logs']);
});

test('file sources require at least one path', function () {
    [$user, $team, $server] = sourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'file',
            'name' => 'app-logs',
            'config' => ['paths' => []],
        ])
        ->assertSessionHasErrors('config.paths');
});

test('nginx sources get default paths', function () {
    [$user, $team, $server] = sourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'nginx',
            'name' => 'nginx',
        ])
        ->assertRedirect();

    $source = $server->logSources()->firstOrFail();
    expect($source->config['access_log'])->toBe('/var/log/nginx/access.log')
        ->and($source->config['error_log'])->toBe('/var/log/nginx/error.log');
});

test('sources can be updated and toggled', function () {
    [$user, $team, $server] = sourceOwner();
    $source = $server->logSources()->create(['type' => 'systemd', 'name' => 'journal', 'config' => [], 'enabled' => true]);

    $this->actingAs($user)
        ->patch(route('servers.sources.update', ['current_team' => $team->slug, 'server' => $server->id, 'source' => $source->id]), [
            'type' => 'systemd',
            'name' => 'journal',
            'config' => ['units' => ['nginx.service']],
            'enabled' => false,
        ])
        ->assertRedirect();

    $source->refresh();
    expect($source->config['units'])->toBe(['nginx.service'])
        ->and($source->enabled)->toBeFalse();
});

test('sources can be deleted', function () {
    [$user, $team, $server] = sourceOwner();
    $source = $server->logSources()->create(['type' => 'systemd', 'name' => 'journal', 'config' => [], 'enabled' => true]);

    $this->actingAs($user)
        ->delete(route('servers.sources.destroy', ['current_team' => $team->slug, 'server' => $server->id, 'source' => $source->id]))
        ->assertRedirect();

    $this->assertDatabaseCount('log_sources', 0);
});

test('sources of other teams servers are unreachable', function () {
    [$user, $team] = sourceOwner();
    $foreignServer = Server::factory()->create();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $foreignServer->id]), [
            'type' => 'systemd',
            'name' => 'journal',
        ])
        ->assertNotFound();
});

test('a source must belong to the server in the url', function () {
    [$user, $team, $server] = sourceOwner();
    $otherServer = Server::factory()->for($team)->create();
    $source = $otherServer->logSources()->create(['type' => 'systemd', 'name' => 'journal', 'config' => [], 'enabled' => true]);

    $this->actingAs($user)
        ->delete(route('servers.sources.destroy', ['current_team' => $team->slug, 'server' => $server->id, 'source' => $source->id]))
        ->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LogSourceTest`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Write request + controller**

`app/Http/Requests/Servers/SaveLogSourceRequest.php`:

```php
<?php

namespace App\Http\Requests\Servers;

use App\Enums\LogSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLogSourceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = LogSourceType::tryFrom((string) $this->input('type'));

        return [
            'type' => ['required', Rule::enum(LogSourceType::class)],
            'name' => ['required', 'string', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'array'],
            ...match ($type) {
                LogSourceType::Systemd => [
                    'config.units' => ['sometimes', 'array'],
                    'config.units.*' => ['string', 'max:255'],
                ],
                LogSourceType::Docker => [
                    'config.containers' => ['sometimes', 'array'],
                    'config.containers.*' => ['string', 'max:255'],
                ],
                LogSourceType::Nginx => [
                    'config.access_log' => ['sometimes', 'string', 'max:1024'],
                    'config.error_log' => ['sometimes', 'string', 'max:1024'],
                ],
                LogSourceType::File => [
                    'config.paths' => ['required', 'array', 'min:1'],
                    'config.paths.*' => ['string', 'max:1024'],
                ],
                default => [],
            },
        ];
    }

    /**
     * Normalized config with per-type defaults applied.
     *
     * @return array<string, mixed>
     */
    public function sourceConfig(): array
    {
        $config = $this->validated('config', []);

        if ($this->validated('type') === LogSourceType::Nginx->value) {
            $config += ['access_log' => '/var/log/nginx/access.log', 'error_log' => '/var/log/nginx/error.log'];
        }

        return $config;
    }
}
```

`app/Http/Controllers/Servers/LogSourceController.php`:

```php
<?php

namespace App\Http\Controllers\Servers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\SaveLogSourceRequest;
use App\Models\LogSource;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LogSourceController extends Controller
{
    /**
     * Add a log source to the server.
     */
    public function store(SaveLogSourceRequest $request, Server $server): RedirectResponse
    {
        $this->authorizeServer($request, $server);

        $server->logSources()->create([
            'type' => $request->validated('type'),
            'name' => $request->validated('name'),
            'config' => $request->sourceConfig(),
            'enabled' => $request->validated('enabled', true),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Log source added. The agent picks it up within a minute.')]);

        return back();
    }

    /**
     * Update a log source.
     */
    public function update(SaveLogSourceRequest $request, Server $server, LogSource $source): RedirectResponse
    {
        $this->authorizeServer($request, $server);
        abort_unless($source->server_id === $server->id, 404);

        $source->update([
            'type' => $request->validated('type'),
            'name' => $request->validated('name'),
            'config' => $request->sourceConfig(),
            'enabled' => $request->validated('enabled', true),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Log source updated.')]);

        return back();
    }

    /**
     * Remove a log source.
     */
    public function destroy(Request $request, Server $server, LogSource $source): RedirectResponse
    {
        $this->authorizeServer($request, $server);
        abort_unless($source->server_id === $server->id, 404);

        $source->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Log source removed.')]);

        return back();
    }

    /**
     * Servers are only visible within their own team.
     */
    protected function authorizeServer(Request $request, Server $server): void
    {
        abort_unless($server->team_id === $request->user()->current_team_id, 404);
    }
}
```

- [ ] **Step 4: Register routes** (same `{current_team}` group, after server routes):

```php
Route::post('servers/{server}/sources', [LogSourceController::class, 'store'])->name('servers.sources.store');
Route::patch('servers/{server}/sources/{source}', [LogSourceController::class, 'update'])->name('servers.sources.update');
Route::delete('servers/{server}/sources/{source}', [LogSourceController::class, 'destroy'])->name('servers.sources.destroy');
```

Add `use App\Http\Controllers\Servers\LogSourceController;`.

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter=LogSourceTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: log source configuration endpoints"
```

---

### Task 5: Alloy config renderer

**Files:**
- Create: `app/Services/AlloyConfigRenderer.php`
- Test: `tests/Feature/Agent/AlloyConfigRendererTest.php`

**Interfaces:**
- Consumes: `Server`, `LogSource`, `LogSourceType`, config shapes from Task 4.
- Produces: `AlloyConfigRenderer::render(Server $server): string` — the complete `config.alloy` file content. Uses `config('app.url')` for the push endpoint and `sys.env("LINA_TOKEN")` for auth (the plaintext token never exists server-side after creation).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Agent/AlloyConfigRendererTest.php`:

```php
<?php

use App\Models\LogSource;
use App\Models\Server;
use App\Services\AlloyConfigRenderer;

test('base config always contains an authenticated loki writer with the server label', function () {
    $server = Server::factory()->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('loki.write "lina"')
        ->toContain('url = "'.config('app.url').'/api/agent/push"')
        ->toContain('bearer_token = sys.env("LINA_TOKEN")')
        ->toContain('server_id = "'.$server->id.'"');
});

test('systemd sources render a journal block with unit filtering', function () {
    $server = Server::factory()->create();
    $source = LogSource::factory()->systemd(['nginx.service', 'php8.4-fpm.service'])->for($server)->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('loki.source.journal "src_'.$source->id.'"')
        ->toContain('loki.relabel "src_'.$source->id.'"')
        // preg_quote escapes . and -, then quote() doubles backslashes for Alloy string literals
        ->toContain('regex = "nginx\\\\.service|php8\\\\.4\\\\-fpm\\\\.service"')
        ->toContain('source = "'.$source->name.'"');
});

test('systemd sources without units skip the keep rule', function () {
    $server = Server::factory()->create();
    LogSource::factory()->systemd()->for($server)->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)->toContain('loki.source.journal')
        ->not->toContain('action = "keep"');
});

test('file sources render file matchers per path', function () {
    $server = Server::factory()->create();
    $source = LogSource::factory()->file(['/var/log/app/*.log', '/srv/worker.log'])->for($server)->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('local.file_match "src_'.$source->id.'"')
        ->toContain('"/var/log/app/*.log"')
        ->toContain('"/srv/worker.log"')
        ->toContain('loki.source.file "src_'.$source->id.'"');
});

test('nginx sources render as file sources with the configured paths', function () {
    $server = Server::factory()->create();
    LogSource::factory()->nginx()->for($server)->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('"/var/log/nginx/access.log"')
        ->toContain('"/var/log/nginx/error.log"');
});

test('docker sources render docker discovery with optional container filter', function () {
    $server = Server::factory()->create();
    $source = LogSource::factory()->docker(['web', 'worker'])->for($server)->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('discovery.docker "src_'.$source->id.'"')
        ->toContain('loki.source.docker "src_'.$source->id.'"')
        ->toContain('regex = "/?(web|worker)"');
});

test('disabled sources are omitted', function () {
    $server = Server::factory()->create();
    LogSource::factory()->file()->for($server)->create(['enabled' => false]);

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)->not->toContain('loki.source.file');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AlloyConfigRendererTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the renderer**

`app/Services/AlloyConfigRenderer.php`:

```php
<?php

namespace App\Services;

use App\Enums\LogSourceType;
use App\Models\LogSource;
use App\Models\Server;

class AlloyConfigRenderer
{
    /**
     * Render the complete config.alloy for a server. All labels are set here —
     * agents can never invent labels the dashboard didn't define.
     */
    public function render(Server $server): string
    {
        $pushUrl = rtrim(config('app.url'), '/').'/api/agent/push';

        $blocks = [
            <<<ALLOY
            // Managed by Lina — local edits are overwritten on the next config poll.

            loki.write "lina" {
              endpoint {
                url = "{$pushUrl}"
                bearer_token = sys.env("LINA_TOKEN")
              }
              external_labels = {
                server_id = "{$server->id}",
              }
            }
            ALLOY,
        ];

        foreach ($server->logSources()->where('enabled', true)->get() as $source) {
            $blocks[] = match ($source->type) {
                LogSourceType::Systemd => $this->systemdBlock($source),
                LogSourceType::Docker => $this->dockerBlock($source),
                LogSourceType::Nginx => $this->fileBlock($source, [
                    $source->config['access_log'] ?? '/var/log/nginx/access.log',
                    $source->config['error_log'] ?? '/var/log/nginx/error.log',
                ]),
                LogSourceType::File => $this->fileBlock($source, $source->config['paths'] ?? []),
            };
        }

        return implode("\n\n", $blocks)."\n";
    }

    protected function systemdBlock(LogSource $source): string
    {
        $id = 'src_'.$source->id;
        $name = $this->quote($source->name);
        $units = array_values(array_filter($source->config['units'] ?? []));

        $keepRule = '';
        if ($units !== []) {
            $regex = $this->quote(implode('|', array_map(fn ($unit) => preg_quote($unit), $units)));
            $keepRule = <<<ALLOY

              rule {
                source_labels = ["__journal__systemd_unit"]
                regex = "{$regex}"
                action = "keep"
              }
            ALLOY;
        }

        return <<<ALLOY
        loki.relabel "{$id}" {
          forward_to = []
        {$keepRule}
          rule {
            source_labels = ["__journal__systemd_unit"]
            target_label = "unit"
          }
        }

        loki.source.journal "{$id}" {
          relabel_rules = loki.relabel.{$id}.rules
          labels = { source = "{$name}" }
          forward_to = [loki.write.lina.receiver]
        }
        ALLOY;
    }

    /** @param array<int, string> $paths */
    protected function fileBlock(LogSource $source, array $paths): string
    {
        $id = 'src_'.$source->id;
        $name = $this->quote($source->name);

        $targets = implode("\n", array_map(
            fn ($path) => '    { __path__ = "'.$this->quote($path).'", source = "'.$name.'" },',
            $paths,
        ));

        return <<<ALLOY
        local.file_match "{$id}" {
          path_targets = [
        {$targets}
          ]
        }

        loki.source.file "{$id}" {
          targets = local.file_match.{$id}.targets
          forward_to = [loki.write.lina.receiver]
        }
        ALLOY;
    }

    protected function dockerBlock(LogSource $source): string
    {
        $id = 'src_'.$source->id;
        $name = $this->quote($source->name);
        $containers = array_values(array_filter($source->config['containers'] ?? []));

        $keepRule = '';
        if ($containers !== []) {
            $regex = $this->quote('/?('.implode('|', array_map(fn ($container) => preg_quote($container), $containers)).')');
            $keepRule = <<<ALLOY

              rule {
                source_labels = ["__meta_docker_container_name"]
                regex = "{$regex}"
                action = "keep"
              }
            ALLOY;
        }

        return <<<ALLOY
        discovery.docker "{$id}" {
          host = "unix:///var/run/docker.sock"
        }

        discovery.relabel "{$id}" {
          targets = discovery.docker.{$id}.targets
        {$keepRule}
          rule {
            source_labels = ["__meta_docker_container_name"]
            regex = "/?(.*)"
            target_label = "container"
          }
          rule {
            target_label = "source"
            replacement = "{$name}"
          }
        }

        loki.source.docker "{$id}" {
          host = "unix:///var/run/docker.sock"
          targets = discovery.relabel.{$id}.output
          forward_to = [loki.write.lina.receiver]
        }
        ALLOY;
    }

    /**
     * Escape a value for inclusion inside a double-quoted Alloy string.
     */
    protected function quote(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
```

Note on the test expectation `regex = "nginx\\.service|..."`: `preg_quote('nginx.service')` gives `nginx\.service`; `quote()` doubles the backslash to `nginx\\.service`, which is the literal the test asserts (PHP single-quoted `'nginx\\.service'` is `nginx\.service` + doubled = the rendered `nginx\\.service`). If the assertion fails on backslash count, print the rendered config and match the assertion to the actual correct Alloy escaping: Alloy strings need `\\.` to express a regex dot-escape.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=AlloyConfigRendererTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: Alloy config renderer for all source types"
```

---

### Task 6: Agent API — auth middleware + config endpoint

**Files:**
- Create: `routes/api.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `app/Http/Middleware/AuthenticateAgent.php`
- Create: `app/Http/Controllers/Agent/AgentConfigController.php`
- Test: `tests/Feature/Agent/AgentConfigTest.php`

**Interfaces:**
- Consumes: `Server::findByToken()`, `AlloyConfigRenderer::render()`.
- Produces:
  - `GET /api/agent/config` route named `agent.config` — bearer auth, returns `text/plain` Alloy config with `ETag`, honors `If-None-Match` with 304. Reads optional `X-Lina-Hostname` and `X-Lina-Agent-Version` request headers into the server record. Bumps `last_seen_at`.
  - Middleware `AuthenticateAgent` — resolves the server from the bearer token, 401 JSON on failure, stores it as `$request->attributes->get('server')`, bumps `last_seen_at` (at most once per 30s). Task 7 reuses this.
  - `RateLimiter::for('api')` and `RateLimiter::for('ingest')` defined in `AppServiceProvider`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Agent/AgentConfigTest.php`:

```php
<?php

use App\Models\Server;

function agentServer(): array
{
    $server = Server::factory()->create();
    $plain = $server->newToken();

    return [$server, $plain];
}

test('agents fetch their rendered config with a bearer token', function () {
    [$server, $plain] = agentServer();

    $response = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->get('/api/agent/config');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('ETag');

    expect($response->getContent())->toContain('loki.write "lina"');
});

test('a matching If-None-Match returns 304', function () {
    [$server, $plain] = agentServer();

    $etag = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->get('/api/agent/config')
        ->headers->get('ETag');

    $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'If-None-Match' => $etag])
        ->get('/api/agent/config')
        ->assertStatus(304);
});

test('config polls record agent metadata and liveness', function () {
    [$server, $plain] = agentServer();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$plain,
        'X-Lina-Hostname' => 'web-1.example.com',
        'X-Lina-Agent-Version' => 'alloy 1.7.1',
    ])->get('/api/agent/config')->assertOk();

    $server->refresh();
    expect($server->hostname)->toBe('web-1.example.com')
        ->and($server->agent_version)->toBe('alloy 1.7.1')
        ->and($server->last_seen_at)->not->toBeNull()
        ->and($server->status())->toBe('online');
});

test('missing or invalid tokens are rejected', function () {
    $this->get('/api/agent/config')->assertUnauthorized();

    $this->withHeader('Authorization', 'Bearer nope')
        ->get('/api/agent/config')
        ->assertUnauthorized();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AgentConfigTest`
Expected: FAIL — 404, api routes don't exist.

- [ ] **Step 3: Wire api routing**

In `bootstrap/app.php`, add to `withRouting`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

In `app/Providers/AppServiceProvider.php` `boot()`, add (with imports `Illuminate\Cache\RateLimiting\Limit`, `Illuminate\Http\Request`, `Illuminate\Support\Facades\RateLimiter`):

```php
RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

RateLimiter::for('ingest', fn (Request $request) => Limit::perMinute(config('services.loki.ingest_rpm'))
    ->by('ingest:'.($request->attributes->get('server')?->id ?? $request->ip())));
```

- [ ] **Step 4: Write middleware + controller + routes**

`app/Http/Middleware/AuthenticateAgent.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Server;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    /**
     * Authenticate an agent request via its per-server bearer token.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $server = Server::findByToken($request->bearerToken());

        if (! $server) {
            return response()->json(['message' => 'Invalid agent token.'], 401);
        }

        $request->attributes->set('server', $server);

        // ponytail: liveness writes throttled to one per 30s per server
        if ($server->last_seen_at === null || $server->last_seen_at->lt(now()->subSeconds(30))) {
            $server->forceFill(['last_seen_at' => now()])->save();
        }

        return $next($request);
    }
}
```

`app/Http/Controllers/Agent/AgentConfigController.php`:

```php
<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\AlloyConfigRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AgentConfigController extends Controller
{
    /**
     * Serve the server's rendered Alloy config with ETag support.
     */
    public function __invoke(Request $request, AlloyConfigRenderer $renderer): Response
    {
        /** @var Server $server */
        $server = $request->attributes->get('server');

        $server->forceFill(array_filter([
            'hostname' => $request->header('X-Lina-Hostname'),
            'agent_version' => $request->header('X-Lina-Agent-Version'),
        ]))->save();

        $config = $renderer->render($server);
        $etag = '"'.sha1($config).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response($config, 200, [
            'Content-Type' => 'text/plain',
            'ETag' => $etag,
        ]);
    }
}
```

`routes/api.php`:

```php
<?php

use App\Http\Controllers\Agent\AgentConfigController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateAgent::class)->group(function () {
    Route::get('agent/config', AgentConfigController::class)->name('agent.config');
});
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter=AgentConfigTest`
Expected: PASS (4 tests). If the ETag/304 test fails because the framework's `SetCacheHeaders`/`ConditionalGetHandler` already answered the `If-None-Match`, that's equivalent behavior — keep the explicit check anyway (it skips rendering work) and assert status 304 only.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: agent auth middleware and config poll endpoint"
```

---

### Task 7: Ingestion endpoint

**Files:**
- Create: `app/Http/Controllers/Agent/IngestController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Agent/IngestTest.php`

**Interfaces:**
- Consumes: `AuthenticateAgent` (server in request attributes), `config('services.loki.url')`, `UsageRecord`, `ingest` rate limiter.
- Produces: `POST /api/agent/push` route named `agent.push`. Forwards the raw body to `{loki}/loki/api/v1/push` with `X-Scope-OrgID: team-{team_id}`. Returns Loki's status. Records `usage_records.bytes += content-length`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Agent/IngestTest.php`:

```php
<?php

use App\Models\Server;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function pushAs(string $token, string $body = 'raw-snappy-protobuf')
{
    return test()->call(
        'POST',
        '/api/agent/push',
        server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/x-protobuf',
        ],
        content: $body,
    );
}

test('pushes over the rate limit get a 429', function () {
    config(['services.loki.ingest_rpm' => 2]);
    Http::fake(['*/loki/api/v1/push' => Http::response('', 204)]);
    $server = Server::factory()->create();
    $plain = $server->newToken();

    pushAs($plain)->assertNoContent();
    pushAs($plain)->assertNoContent();
    pushAs($plain)->assertStatus(429);
});

test('pushes are forwarded verbatim to loki with the team org id', function () {
    Http::fake(['*/loki/api/v1/push' => Http::response('', 204)]);
    $server = Server::factory()->create();
    $plain = $server->newToken();

    pushAs($plain, 'the-raw-body')->assertNoContent();

    Http::assertSent(function ($request) use ($server) {
        return str_ends_with($request->url(), '/loki/api/v1/push')
            && $request->header('X-Scope-OrgID')[0] === 'team-'.$server->team_id
            && $request->body() === 'the-raw-body';
    });
});

test('the org id always comes from the token, never the request', function () {
    Http::fake(['*/loki/api/v1/push' => Http::response('', 204)]);
    $serverA = Server::factory()->create();
    $plain = $serverA->newToken();
    $serverB = Server::factory()->create(); // different team

    // Even a malicious body/header cannot change tenancy.
    test()->call('POST', '/api/agent/push', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$plain,
        'HTTP_X_SCOPE_ORGID' => 'team-'.$serverB->team_id,
        'CONTENT_TYPE' => 'application/x-protobuf',
    ], content: 'body')->assertNoContent();

    Http::assertSent(fn ($request) => $request->header('X-Scope-OrgID')[0] === 'team-'.$serverA->team_id);
});

test('usage bytes accumulate per server per day', function () {
    Http::fake(['*/loki/api/v1/push' => Http::response('', 204)]);
    $server = Server::factory()->create();
    $plain = $server->newToken();

    pushAs($plain, str_repeat('a', 100));
    pushAs($plain, str_repeat('b', 50));

    $record = UsageRecord::where('server_id', $server->id)->whereDate('date', today())->firstOrFail();
    expect($record->bytes)->toBe(150);
});

test('loki errors surface to the agent for retry', function () {
    Http::fake(['*/loki/api/v1/push' => Http::response('error', 503)]);
    $server = Server::factory()->create();
    $plain = $server->newToken();

    pushAs($plain)->assertStatus(503);
});

test('pushes without a valid token are rejected and never forwarded', function () {
    Http::fake();

    pushAs('bad-token')->assertUnauthorized();

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=IngestTest`
Expected: FAIL — 404.

- [ ] **Step 3: Write the controller + route**

`app/Http/Controllers/Agent/IngestController.php`:

```php
<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\UsageRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class IngestController extends Controller
{
    /**
     * Forward a raw Loki push from the agent to Loki, scoped to the server's team.
     * The body is never parsed; tenancy comes exclusively from the authenticated token.
     */
    public function __invoke(Request $request): Response
    {
        /** @var Server $server */
        $server = $request->attributes->get('server');

        $body = $request->getContent();

        $this->recordUsage($server, strlen($body));

        $response = Http::withHeaders([
            'X-Scope-OrgID' => 'team-'.$server->team_id,
        ])->withBody($body, $request->header('Content-Type', 'application/x-protobuf'))
            ->post(rtrim(config('services.loki.url'), '/').'/loki/api/v1/push');

        return response($response->body(), $response->status());
    }

    /**
     * Accumulate ingested bytes per server per day. insertOrIgnore + increment is
     * race-safe under the (server_id, date) unique index.
     */
    protected function recordUsage(Server $server, int $bytes): void
    {
        UsageRecord::insertOrIgnore([
            'server_id' => $server->id,
            'date' => today()->toDateString(),
            'bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UsageRecord::where('server_id', $server->id)
            ->whereDate('date', today())
            ->increment('bytes', $bytes);
    }
}
```

In `routes/api.php`, add inside the `AuthenticateAgent` group:

```php
Route::post('agent/push', IngestController::class)
    ->middleware('throttle:ingest')
    ->name('agent.push');
```

Add `use App\Http\Controllers\Agent\IngestController;`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=IngestTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: multi-tenant log ingestion proxy with usage metering"
```

---

### Task 8: Install script endpoint

**Files:**
- Create: `app/Http/Controllers/Servers/InstallScriptController.php`
- Create: `resources/views/scripts/install.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Servers/InstallScriptTest.php`

**Interfaces:**
- Consumes: `Server::findByToken()`.
- Produces: `GET /install/{token}` route named `servers.install` (public — the token IS the auth; unknown token = 404). Returns `text/plain` bash. The script installs Alloy (apt or dnf), writes `LINA_TOKEN`/`LINA_BASE_URL` into Alloy's env file, installs the `lina-agent-update` script + systemd service/timer (60s), runs the first update, and starts Alloy.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Servers/InstallScriptTest.php`:

```php
<?php

use App\Models\Server;

test('the install script embeds the token and base url', function () {
    $server = Server::factory()->create();
    $plain = $server->newToken();

    $response = $this->get('/install/'.$plain);

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    $script = $response->getContent();
    expect($script)
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('LINA_TOKEN='.$plain)
        ->toContain('LINA_BASE_URL='.rtrim(config('app.url'), '/'))
        ->toContain('lina-agent-update')
        ->toContain('apt.grafana.com')
        ->toContain('rpm.grafana.com');
});

test('unknown tokens 404', function () {
    $this->get('/install/not-a-real-token')->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=InstallScriptTest`
Expected: FAIL — 404 for both.

- [ ] **Step 3: Write controller, view, route**

`app/Http/Controllers/Servers/InstallScriptController.php`:

```php
<?php

namespace App\Http\Controllers\Servers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Response;

class InstallScriptController extends Controller
{
    /**
     * Serve the bash install script. The token is the authentication.
     */
    public function __invoke(string $token): Response
    {
        abort_unless(Server::findByToken($token) instanceof Server, 404);

        $script = view('scripts.install', [
            'token' => $token,
            'baseUrl' => rtrim(config('app.url'), '/'),
        ])->render();

        return response($script, 200, ['Content-Type' => 'text/plain']);
    }
}
```

`resources/views/scripts/install.blade.php` (bash — every variable uses `{!! !!}` because Blade's HTML escaping would corrupt shell syntax; both values are URL/token-safe strings we generate):

```bash
#!/usr/bin/env bash
set -euo pipefail

TOKEN="{!! $token !!}"
BASE_URL="{!! $baseUrl !!}"

if [ "$(id -u)" -ne 0 ]; then
    echo "This installer must run as root: curl -sSL $BASE_URL/install/<token> | sudo bash" >&2
    exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
    echo "systemd is required. This installer supports systemd-based Linux distributions only." >&2
    exit 1
fi

echo "==> Installing Grafana Alloy"
if command -v apt-get >/dev/null 2>&1; then
    ENV_FILE=/etc/default/alloy
    apt-get install -y gpg curl >/dev/null
    mkdir -p /etc/apt/keyrings
    curl -fsSL https://apt.grafana.com/gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/grafana.gpg
    echo "deb [signed-by=/etc/apt/keyrings/grafana.gpg] https://apt.grafana.com stable main" > /etc/apt/sources.list.d/grafana.list
    apt-get update -qq
    apt-get install -y alloy
elif command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
    ENV_FILE=/etc/sysconfig/alloy
    cat > /etc/yum.repos.d/grafana.repo <<'REPO'
[grafana]
name=grafana
baseurl=https://rpm.grafana.com
repo_gpgcheck=1
enabled=1
gpgcheck=1
gpgkey=https://rpm.grafana.com/gpg.key
REPO
    (command -v dnf >/dev/null 2>&1 && dnf install -y alloy) || yum install -y alloy
else
    echo "Unsupported distribution: need apt-get, dnf, or yum." >&2
    exit 1
fi

echo "==> Configuring agent credentials"
touch "$ENV_FILE"
sed -i '/^LINA_TOKEN=/d;/^LINA_BASE_URL=/d' "$ENV_FILE"
echo "LINA_TOKEN=$TOKEN" >> "$ENV_FILE"
echo "LINA_BASE_URL=$BASE_URL" >> "$ENV_FILE"
chmod 600 "$ENV_FILE"

echo "==> Installing config updater"
cat > /usr/local/bin/lina-agent-update <<UPDATER
#!/usr/bin/env bash
set -euo pipefail
set -a; source $ENV_FILE; set +a
ETAG_FILE=/etc/alloy/.config-etag
HEADERS=\$(mktemp)
BODY=\$(mktemp)
STATUS=\$(curl -sS -w '%{http_code}' -o "\$BODY" -D "\$HEADERS" \
    -H "Authorization: Bearer \$LINA_TOKEN" \
    -H "If-None-Match: \$(cat "\$ETAG_FILE" 2>/dev/null || echo none)" \
    -H "X-Lina-Hostname: \$(hostname -f 2>/dev/null || hostname)" \
    -H "X-Lina-Agent-Version: \$(alloy --version 2>/dev/null | head -n1 || echo unknown)" \
    "\$LINA_BASE_URL/api/agent/config")
if [ "\$STATUS" = "200" ]; then
    mv "\$BODY" /etc/alloy/config.alloy
    grep -i '^etag:' "\$HEADERS" | awk '{print \$2}' | tr -d '\r' > "\$ETAG_FILE"
    systemctl try-reload-or-restart alloy
elif [ "\$STATUS" != "304" ]; then
    echo "Config poll failed with HTTP \$STATUS; keeping current config." >&2
    exit 1
fi
rm -f "\$HEADERS" "\$BODY" 2>/dev/null || true
UPDATER
chmod 755 /usr/local/bin/lina-agent-update

cat > /etc/systemd/system/lina-agent-update.service <<'UNIT'
[Unit]
Description=Lina agent config updater

[Service]
Type=oneshot
ExecStart=/usr/local/bin/lina-agent-update
UNIT

cat > /etc/systemd/system/lina-agent-update.timer <<'UNIT'
[Unit]
Description=Poll Lina for agent config changes

[Timer]
OnBootSec=30s
OnUnitActiveSec=60s

[Install]
WantedBy=timers.target
UNIT

echo "==> Fetching initial config and starting Alloy"
systemctl daemon-reload
/usr/local/bin/lina-agent-update
systemctl enable --now alloy
systemctl enable --now lina-agent-update.timer

echo ""
echo "✔ Lina agent installed. This server will appear online in the dashboard within a minute."
```

In `routes/web.php` (top level, NOT inside any auth group):

```php
Route::get('install/{token}', InstallScriptController::class)->name('servers.install');
```

Add `use App\Http\Controllers\Servers\InstallScriptController;`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=InstallScriptTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Sanity-check the generated bash parses**

```bash
php artisan tinker --execute 'file_put_contents("/tmp/lina-install-check.sh", view("scripts.install", ["token" => "tok", "baseUrl" => "https://example.test"])->render());'
bash -n /tmp/lina-install-check.sh && echo SYNTAX_OK
```

Expected: `SYNTAX_OK`. (This catches Blade-escaping corruption of the shell.)

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: one-line agent install script endpoint"
```

---

### Task 9: Log query backend

**Files:**
- Create: `app/Services/LogQlBuilder.php`
- Create: `app/Http/Controllers/Logs/LogController.php`
- Modify: `routes/web.php`
- Test: `tests/Unit/LogQlBuilderTest.php`, `tests/Feature/Logs/LogQueryTest.php`

**Interfaces:**
- Consumes: `Server`, `config('services.loki.url')`.
- Produces:
  - `LogQlBuilder::build(array $serverIds, array $sources, ?string $search): string` — e.g. `{server_id=~"1|2", source=~"nginx|app"} |= "error"`; omits `source` matcher when `$sources` empty; omits the line filter when `$search` empty.
  - `logs.index` GET `{current_team}/logs` → Inertia `logs/index`, props `servers: [{id, name}]`, `sources: string[]` (distinct enabled source names across team servers).
  - `logs.query` GET `{current_team}/logs/query` → JSON `{entries: [{ts: string(ns), time: string(ISO), line: string, labels: object}]}`, newest first, max 500. Query params: `servers` (comma-separated ids), `sources` (comma-separated names), `search` (string), `start`/`end` (unix seconds, optional; default last hour).

- [ ] **Step 1: Write the failing unit test**

`tests/Unit/LogQlBuilderTest.php`:

```php
<?php

use App\Services\LogQlBuilder;

test('builds a selector from servers, sources, and search', function () {
    $query = new LogQlBuilder()->build([1, 2], ['nginx', 'app'], 'error 500');

    expect($query)->toBe('{server_id=~"1|2", source=~"nginx|app"} |= "error 500"');
});

test('omits empty parts', function () {
    expect(new LogQlBuilder()->build([7], [], null))->toBe('{server_id=~"7"}');
});

test('escapes quotes and backslashes in search and sources', function () {
    $query = new LogQlBuilder()->build([1], ['we"ird'], 'say "hi" \\ bye');

    expect($query)->toBe('{server_id=~"1", source=~"we\\"ird"} |= "say \\"hi\\" \\\\ bye"');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=LogQlBuilderTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Write the builder**

`app/Services/LogQlBuilder.php`:

```php
<?php

namespace App\Services;

class LogQlBuilder
{
    /**
     * Build a LogQL query from validated dashboard filters. Users never supply
     * raw LogQL; every value is escaped into quoted matchers.
     *
     * @param  array<int, int>  $serverIds
     * @param  array<int, string>  $sources
     */
    public function build(array $serverIds, array $sources, ?string $search): string
    {
        $matchers = ['server_id=~"'.implode('|', $serverIds).'"'];

        if ($sources !== []) {
            $matchers[] = 'source=~"'.$this->escape(implode('|', array_map($this->escapeRegexAlternative(...), $sources))).'"';
        }

        $query = '{'.implode(', ', $matchers).'}';

        if ($search !== null && $search !== '') {
            $query .= ' |= "'.$this->escape($search).'"';
        }

        return $query;
    }

    /**
     * Escape for a double-quoted LogQL string.
     */
    protected function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * Keep regex alternation safe for literal source names.
     */
    protected function escapeRegexAlternative(string $value): string
    {
        return preg_quote($value);
    }
}
```

Note: the escaping test pins exact output. If `preg_quote` + `escape` double up differently than asserted, adjust the *test* to the actual rendered string only after manually confirming the string is valid LogQL (quotes backslash-escaped, regex metacharacters escaped).

- [ ] **Step 4: Run unit tests**

Run: `php artisan test --compact --filter=LogQlBuilderTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Write the failing feature test**

`tests/Feature/Logs/LogQueryTest.php`:

```php
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
```

- [ ] **Step 6: Run to verify failure**

Run: `php artisan test --compact --filter=LogQueryTest`
Expected: FAIL — routes missing.

- [ ] **Step 7: Write the controller + routes**

`app/Http/Controllers/Logs/LogController.php`:

```php
<?php

namespace App\Http\Controllers\Logs;

use App\Http\Controllers\Controller;
use App\Models\LogSource;
use App\Models\Server;
use App\Services\LogQlBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class LogController extends Controller
{
    /**
     * The log explorer page with the team's filter options.
     */
    public function index(Request $request): Response
    {
        $teamId = $request->user()->current_team_id;

        return Inertia::render('logs/index', [
            'servers' => Server::where('team_id', $teamId)
                ->get(['id', 'name'])
                ->map(fn (Server $server) => ['id' => $server->id, 'name' => $server->name]),
            'sources' => LogSource::whereIn('server_id', Server::where('team_id', $teamId)->select('id'))
                ->where('enabled', true)
                ->distinct()
                ->pluck('name'),
        ]);
    }

    /**
     * Query Loki for the current team. Server ids are intersected with the
     * team's servers, so tenancy is enforced twice: here and via X-Scope-OrgID.
     */
    public function query(Request $request, LogQlBuilder $builder): JsonResponse
    {
        $validated = $request->validate([
            'servers' => ['sometimes', 'string', 'max:500'],
            'sources' => ['sometimes', 'string', 'max:500'],
            'search' => ['sometimes', 'nullable', 'string', 'max:500'],
            'start' => ['sometimes', 'integer', 'min:0'],
            'end' => ['sometimes', 'integer', 'min:0'],
        ]);

        $teamId = $request->user()->current_team_id;
        $teamServerIds = Server::where('team_id', $teamId)->pluck('id')->all();

        $requested = array_filter(array_map(intval(...), explode(',', $validated['servers'] ?? '')));
        $serverIds = $requested === [] ? $teamServerIds : array_values(array_intersect($requested, $teamServerIds));

        if ($serverIds === []) {
            return response()->json(['entries' => []]);
        }

        $sources = array_values(array_filter(explode(',', $validated['sources'] ?? '')));

        $response = Http::withHeaders(['X-Scope-OrgID' => 'team-'.$teamId])
            ->get(rtrim(config('services.loki.url'), '/').'/loki/api/v1/query_range', [
                'query' => $builder->build($serverIds, $sources, $validated['search'] ?? null),
                'start' => ($validated['start'] ?? now()->subHour()->timestamp) * 1_000_000_000,
                'end' => ($validated['end'] ?? now()->timestamp) * 1_000_000_000,
                'limit' => 500,
                'direction' => 'backward',
            ]);

        if ($response->failed()) {
            return response()->json(['entries' => [], 'error' => 'Log storage is unavailable right now.'], 502);
        }

        $entries = collect($response->json('data.result', []))
            ->flatMap(fn (array $stream) => collect($stream['values'])->map(fn (array $value) => [
                'ts' => $value[0],
                'time' => \Illuminate\Support\Carbon::createFromTimestampMs((int) ($value[0] / 1_000_000))->toISOString(),
                'line' => $value[1],
                'labels' => $stream['stream'],
            ]))
            ->sortByDesc('ts')
            ->values();

        return response()->json(['entries' => $entries]);
    }
}
```

In `routes/web.php`, inside the `{current_team}` group:

```php
Route::get('logs', [LogController::class, 'index'])->name('logs.index');
Route::get('logs/query', [LogController::class, 'query'])->name('logs.query');
```

Add `use App\Http\Controllers\Logs\LogController;`.

- [ ] **Step 8: Run tests**

Run: `php artisan test --compact --filter=LogQueryTest`
Expected: PASS (4 tests).

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: team-scoped log query proxy with server-side LogQL building"
```

---

### Task 10: Frontend — servers pages, types, navigation

**Files:**
- Create: `resources/js/types/logging.ts`
- Modify: `resources/js/types/index.ts`
- Create: `resources/js/pages/servers/index.tsx`
- Create: `resources/js/pages/servers/show.tsx`
- Modify: `resources/js/components/app-sidebar.tsx`

**Interfaces:**
- Consumes: props shapes from Tasks 3–4, Wayfinder route functions `@/routes/servers`, `@/routes/servers/sources`, `@/routes/logs`, shadcn components.
- Produces: `Server`, `ServerSummary`, `LogSourceItem`, `LogEntry` types exported from `@/types`.

- [ ] **Step 1: Add types**

`resources/js/types/logging.ts`:

```ts
export type ServerStatus = 'pending' | 'online' | 'offline';

export type ServerSummary = {
    id: number;
    name: string;
    hostname: string | null;
    status: ServerStatus;
    lastSeenAt: string | null;
    bytesToday: number;
};

export type ServerDetail = ServerSummary & {
    agentVersion: string | null;
};

export type LogSourceType = 'systemd' | 'docker' | 'nginx' | 'file';

export type LogSourceItem = {
    id: number;
    type: LogSourceType;
    name: string;
    config: {
        units?: string[];
        containers?: string[];
        access_log?: string;
        error_log?: string;
        paths?: string[];
    };
    enabled: boolean;
};

export type LogEntry = {
    ts: string;
    time: string;
    line: string;
    labels: Record<string, string>;
};
```

Append to `resources/js/types/index.ts`:

```ts
export type * from './logging';
```

- [ ] **Step 2: Regenerate Wayfinder routes**

Run: `php artisan wayfinder:generate --no-interaction`
Expected: generates `resources/js/routes/servers/*`, `resources/js/routes/logs/*` (exact directory layout comes from route names; check `resources/js/routes/` afterwards and adjust imports below to what was generated).

- [ ] **Step 3: Servers index page**

`resources/js/pages/servers/index.tsx`:

```tsx
import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Plus, Server as ServerIcon } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show, store } from '@/routes/servers';
import type { ServerSummary } from '@/types';

const statusVariant = {
    online: 'default',
    offline: 'destructive',
    pending: 'secondary',
} as const;

export function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = '';
    for (const next of units) {
        value /= 1024;
        unit = next;
        if (value < 1024) break;
    }
    return `${value.toFixed(1)} ${unit}`;
}

type Props = {
    servers: ServerSummary[];
};

export default function ServersIndex({ servers }: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';
    const [open, setOpen] = useState(false);

    return (
        <>
            <Head title="Servers" />

            <div className="flex flex-col space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title="Servers"
                        description="Connected machines shipping logs to this team"
                    />
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button>
                                <Plus className="size-4" /> Add server
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Add server</DialogTitle>
                                <DialogDescription>
                                    Name the server, then run the install
                                    command shown on the next screen.
                                </DialogDescription>
                            </DialogHeader>
                            <Form
                                action={store({ current_team: teamSlug })}
                                resetOnSuccess
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="name">Name</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                placeholder="web-1"
                                                autoFocus
                                            />
                                            <InputError
                                                message={errors.name}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Create server
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>

                {servers.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center">
                        <ServerIcon className="size-8 text-muted-foreground" />
                        <p className="font-medium">No servers yet</p>
                        <p className="text-sm text-muted-foreground">
                            Add a server to get a one-line install command.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y rounded-xl border">
                        {servers.map((server) => (
                            <Link
                                key={server.id}
                                href={show({
                                    current_team: teamSlug,
                                    server: server.id,
                                })}
                                className="flex items-center justify-between p-4 hover:bg-muted/50"
                            >
                                <div>
                                    <p className="font-medium">{server.name}</p>
                                    <p className="text-sm text-muted-foreground">
                                        {server.hostname ?? 'Not connected yet'}
                                    </p>
                                </div>
                                <div className="flex items-center gap-4">
                                    <span className="text-sm text-muted-foreground">
                                        {formatBytes(server.bytesToday)} today
                                    </span>
                                    <Badge
                                        variant={statusVariant[server.status]}
                                    >
                                        {server.status}
                                    </Badge>
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
```

Note: check how existing pages get the app layout (e.g. `dashboard.tsx` — the starter kit usually assigns a default layout in `app.tsx` resolver or via `Page.layout`). Match whatever `teams/index.tsx` does; if pages declare `ServersIndex.layout = (page) => <AppLayout children={page} />`, do the same on every new page.

- [ ] **Step 4: Server show page**

`resources/js/pages/servers/show.tsx`:

```tsx
import { Form, Head, router, usePage } from '@inertiajs/react';
import { Copy, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { destroy, token } from '@/routes/servers';
import {
    destroy as destroySource,
    store as storeSource,
    update as updateSource,
} from '@/routes/servers/sources';
import type { LogSourceItem, LogSourceType, ServerDetail } from '@/types';
import { formatBytes } from './index';

const statusVariant = {
    online: 'default',
    offline: 'destructive',
    pending: 'secondary',
} as const;

const typeLabels: Record<LogSourceType, string> = {
    systemd: 'systemd journal',
    docker: 'Docker containers',
    nginx: 'Nginx',
    file: 'Log files',
};

type Props = {
    server: ServerDetail;
    sources: LogSourceItem[];
    plainToken: string | null;
    installBaseUrl: string;
};

function SourceForm({
    action,
    method,
    source,
    onSuccess,
}: {
    action: { url: string };
    method: 'post' | 'patch';
    source?: LogSourceItem;
    onSuccess: () => void;
}) {
    const [type, setType] = useState<LogSourceType>(source?.type ?? 'systemd');

    return (
        <Form
            action={action.url}
            method={method}
            onSuccess={onSuccess}
            transform={(data) => ({
                ...data,
                type,
                enabled: data.enabled === 'on' || data.enabled === true,
                config: {
                    units: splitLines(data['config_units']),
                    containers: splitLines(data['config_containers']),
                    access_log: data['config_access_log'] || undefined,
                    error_log: data['config_error_log'] || undefined,
                    paths: splitLines(data['config_paths']),
                },
            })}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-2">
                        <Label>Type</Label>
                        <Select
                            value={type}
                            onValueChange={(value) =>
                                setType(value as LogSourceType)
                            }
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(typeLabels).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="source-name">Name</Label>
                        <Input
                            id="source-name"
                            name="name"
                            defaultValue={source?.name}
                            placeholder="app-logs"
                        />
                        <InputError message={errors.name} />
                    </div>

                    {type === 'systemd' && (
                        <div className="space-y-2">
                            <Label htmlFor="config_units">
                                Units (one per line, empty = whole journal)
                            </Label>
                            <textarea
                                id="config_units"
                                name="config_units"
                                defaultValue={source?.config.units?.join('\n')}
                                placeholder={'nginx.service\nphp8.4-fpm.service'}
                                className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            />
                        </div>
                    )}

                    {type === 'docker' && (
                        <div className="space-y-2">
                            <Label htmlFor="config_containers">
                                Container names (one per line, empty = all)
                            </Label>
                            <textarea
                                id="config_containers"
                                name="config_containers"
                                defaultValue={source?.config.containers?.join('\n')}
                                placeholder={'web\nworker'}
                                className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            />
                        </div>
                    )}

                    {type === 'nginx' && (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="config_access_log">
                                    Access log path
                                </Label>
                                <Input
                                    id="config_access_log"
                                    name="config_access_log"
                                    defaultValue={
                                        source?.config.access_log ??
                                        '/var/log/nginx/access.log'
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="config_error_log">
                                    Error log path
                                </Label>
                                <Input
                                    id="config_error_log"
                                    name="config_error_log"
                                    defaultValue={
                                        source?.config.error_log ??
                                        '/var/log/nginx/error.log'
                                    }
                                />
                            </div>
                        </>
                    )}

                    {type === 'file' && (
                        <div className="space-y-2">
                            <Label htmlFor="config_paths">
                                Paths or globs (one per line)
                            </Label>
                            <textarea
                                id="config_paths"
                                name="config_paths"
                                defaultValue={source?.config.paths?.join('\n')}
                                placeholder="/var/log/app/*.log"
                                className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            />
                            <InputError message={errors['config.paths']} />
                        </div>
                    )}

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="source-enabled"
                            name="enabled"
                            defaultChecked={source?.enabled ?? true}
                        />
                        <Label htmlFor="source-enabled">Enabled</Label>
                    </div>

                    <Button type="submit" disabled={processing}>
                        {source ? 'Save source' : 'Add source'}
                    </Button>
                </>
            )}
        </Form>
    );
}

function splitLines(value: unknown): string[] | undefined {
    if (typeof value !== 'string' || value.trim() === '') return undefined;
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);
}

export default function ServerShow({
    server,
    sources,
    plainToken,
    installBaseUrl,
}: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';
    const [addOpen, setAddOpen] = useState(false);
    const [editing, setEditing] = useState<LogSourceItem | null>(null);
    const [copied, setCopied] = useState(false);

    const installCommand = plainToken
        ? `curl -sSL ${installBaseUrl}/${plainToken} | sudo bash`
        : null;

    const copyCommand = async () => {
        if (!installCommand) return;
        await navigator.clipboard.writeText(installCommand);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <>
            <Head title={server.name} />

            <div className="flex flex-col space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={server.name}
                        description={server.hostname ?? 'Not connected yet'}
                    />
                    <Badge variant={statusVariant[server.status]}>
                        {server.status}
                    </Badge>
                </div>

                {installCommand && (
                    <div className="space-y-2 rounded-xl border border-amber-400/50 bg-amber-50 p-4 dark:bg-amber-950/20">
                        <p className="font-medium">
                            Run this on the server as root — the token is shown
                            only once:
                        </p>
                        <div className="flex items-center gap-2">
                            <code className="flex-1 overflow-x-auto rounded bg-background p-2 font-mono text-sm">
                                {installCommand}
                            </code>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={copyCommand}
                            >
                                <Copy className="size-4" />
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    </div>
                )}

                <div className="grid gap-4 text-sm md:grid-cols-3">
                    <div className="rounded-xl border p-4">
                        <p className="text-muted-foreground">Last seen</p>
                        <p className="font-medium">
                            {server.lastSeenAt
                                ? new Date(server.lastSeenAt).toLocaleString()
                                : 'Never'}
                        </p>
                    </div>
                    <div className="rounded-xl border p-4">
                        <p className="text-muted-foreground">Agent</p>
                        <p className="font-medium">
                            {server.agentVersion ?? 'Unknown'}
                        </p>
                    </div>
                    <div className="rounded-xl border p-4">
                        <p className="text-muted-foreground">Ingested today</p>
                        <p className="font-medium">
                            {formatBytes(server.bytesToday)}
                        </p>
                    </div>
                </div>

                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title="Log sources"
                            description="What this server collects. Changes reach the agent within a minute."
                        />
                        <Dialog open={addOpen} onOpenChange={setAddOpen}>
                            <DialogTrigger asChild>
                                <Button variant="outline">
                                    <Plus className="size-4" /> Add source
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Add log source</DialogTitle>
                                </DialogHeader>
                                <SourceForm
                                    action={storeSource({
                                        current_team: teamSlug,
                                        server: server.id,
                                    })}
                                    method="post"
                                    onSuccess={() => setAddOpen(false)}
                                />
                            </DialogContent>
                        </Dialog>
                    </div>

                    {sources.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            No sources yet — this server isn't collecting
                            anything.
                        </p>
                    ) : (
                        <div className="divide-y rounded-xl border">
                            {sources.map((source) => (
                                <div
                                    key={source.id}
                                    className="flex items-center justify-between p-4"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {source.name}
                                            {!source.enabled && (
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-2"
                                                >
                                                    disabled
                                                </Badge>
                                            )}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {typeLabels[source.type]}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setEditing(source)}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.delete(
                                                    destroySource({
                                                        current_team: teamSlug,
                                                        server: server.id,
                                                        source: source.id,
                                                    }),
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    <Dialog
                        open={editing !== null}
                        onOpenChange={(value) => !value && setEditing(null)}
                    >
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Edit log source</DialogTitle>
                            </DialogHeader>
                            {editing && (
                                <SourceForm
                                    action={updateSource({
                                        current_team: teamSlug,
                                        server: server.id,
                                        source: editing.id,
                                    })}
                                    method="patch"
                                    source={editing}
                                    onSuccess={() => setEditing(null)}
                                />
                            )}
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="space-y-3 rounded-xl border border-destructive/40 p-4">
                    <Heading
                        variant="small"
                        title="Danger zone"
                        description="Token rotation breaks the current agent until reinstalled; deletion is permanent."
                    />
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    token({
                                        current_team: teamSlug,
                                        server: server.id,
                                    }),
                                )
                            }
                        >
                            <RefreshCw className="size-4" /> Regenerate token
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (
                                    confirm(
                                        `Delete ${server.name}? Its agent stops working immediately.`,
                                    )
                                ) {
                                    router.delete(
                                        destroy({
                                            current_team: teamSlug,
                                            server: server.id,
                                        }),
                                    );
                                }
                            }}
                        >
                            <Trash2 className="size-4" /> Delete server
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}
```

Adjust the `<Form>` usage to the Inertia v3 `Form` component API actually present in the codebase (see `create-team-modal.tsx` for the established pattern) — including how `transform`, errors, and nested `config` fields are handled. If the codebase uses `useForm` instead, rewrite `SourceForm` with `useForm` following the sibling modal components. The route-function import paths (`@/routes/servers`, `@/routes/servers/sources`) must match what `wayfinder:generate` produced in Step 2.

- [ ] **Step 5: Add nav items**

In `resources/js/components/app-sidebar.tsx`, extend imports and `mainNavItems`:

```tsx
import { BookOpen, FolderGit2, LayoutGrid, ScrollText, Server } from 'lucide-react';
import { index as serversIndex } from '@/routes/servers';
import { index as logsIndex } from '@/routes/logs';
```

```tsx
const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboardUrl,
        icon: LayoutGrid,
    },
    {
        title: 'Servers',
        href: page.props.currentTeam
            ? serversIndex({ current_team: page.props.currentTeam.slug })
            : '/',
        icon: Server,
    },
    {
        title: 'Logs',
        href: page.props.currentTeam
            ? logsIndex({ current_team: page.props.currentTeam.slug })
            : '/',
        icon: ScrollText,
    },
];
```

- [ ] **Step 6: Build and verify**

Run: `npm run build`
Expected: build succeeds with no TypeScript errors. Fix any type/import errors (especially Wayfinder import paths and the layout assignment convention).

Run: `php artisan test --compact --filter="ServerControllerTest"`
Expected: still PASS.

- [ ] **Step 7: Commit**

```bash
npm run lint
git add -A && git commit -m "feat: servers UI with one-time install command and source configuration"
```

---

### Task 11: Frontend — log explorer with live tail

**Files:**
- Create: `resources/js/pages/logs/index.tsx`

**Interfaces:**
- Consumes: `logs.query` JSON endpoint (Task 9), `LogEntry` type, Wayfinder `@/routes/logs`.

- [ ] **Step 1: Write the page**

`resources/js/pages/logs/index.tsx`:

```tsx
import { Head, usePage } from '@inertiajs/react';
import { Pause, Play, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { query as logsQuery } from '@/routes/logs';
import type { LogEntry } from '@/types';

type Props = {
    servers: { id: number; name: string }[];
    sources: string[];
};

const RANGES: Record<string, number> = {
    '15m': 15 * 60,
    '1h': 60 * 60,
    '24h': 24 * 60 * 60,
    '7d': 7 * 24 * 60 * 60,
};

function prettyLine(line: string): string | null {
    if (!line.startsWith('{') && !line.startsWith('[')) return null;
    try {
        return JSON.stringify(JSON.parse(line), null, 2);
    } catch {
        return null;
    }
}

function LogRow({ entry }: { entry: LogEntry }) {
    const [expanded, setExpanded] = useState(false);
    const pretty = expanded ? prettyLine(entry.line) : null;

    return (
        <div
            className="cursor-pointer border-b border-border/50 px-3 py-1 font-mono text-xs hover:bg-muted/50"
            onClick={() => setExpanded(!expanded)}
        >
            <div className="flex gap-3">
                <span className="shrink-0 text-muted-foreground">
                    {new Date(entry.time).toLocaleTimeString()}
                </span>
                <span className="shrink-0 text-muted-foreground">
                    {entry.labels.source}
                </span>
                <span className="truncate whitespace-pre">{entry.line}</span>
            </div>
            {expanded && (
                <div className="mt-1 space-y-1 pl-4">
                    <div className="flex flex-wrap gap-1">
                        {Object.entries(entry.labels).map(([key, value]) => (
                            <Badge key={key} variant="secondary">
                                {key}={value}
                            </Badge>
                        ))}
                    </div>
                    <pre className="overflow-x-auto whitespace-pre-wrap rounded bg-muted p-2">
                        {pretty ?? entry.line}
                    </pre>
                </div>
            )}
        </div>
    );
}

export default function LogsIndex({ servers, sources }: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';

    const [serverId, setServerId] = useState<string>('all');
    const [source, setSource] = useState<string>('all');
    const [search, setSearch] = useState('');
    const [range, setRange] = useState('1h');
    const [entries, setEntries] = useState<LogEntry[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [live, setLive] = useState(false);
    const lastTs = useRef<string | null>(null);

    const fetchLogs = useCallback(
        async (since?: string | null) => {
            const params: Record<string, string> = {};
            if (serverId !== 'all') params.servers = serverId;
            if (source !== 'all') params.sources = source;
            if (search) params.search = search;
            if (since) {
                // Tail from just after the newest entry we have.
                params.start = String(
                    Math.floor(Number(BigInt(since) / 1_000_000_000n)) ,
                );
            } else {
                params.start = String(
                    Math.floor(Date.now() / 1000) - RANGES[range],
                );
            }

            const url =
                logsQuery({ current_team: teamSlug }).url +
                '?' +
                new URLSearchParams(params).toString();

            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                setError('Log storage is unavailable right now.');
                return [];
            }
            setError(null);
            const data = (await response.json()) as { entries: LogEntry[] };
            return data.entries;
        },
        [serverId, source, search, range, teamSlug],
    );

    const runSearch = async () => {
        setLoading(true);
        const result = await fetchLogs();
        setEntries(result);
        lastTs.current = result[0]?.ts ?? null;
        setLoading(false);
    };

    useEffect(() => {
        if (!live) return;
        const interval = setInterval(async () => {
            const fresh = await fetchLogs(lastTs.current);
            if (fresh.length > 0) {
                setEntries((current) => {
                    const known = new Set(
                        current.map((entry) => entry.ts + entry.line),
                    );
                    const additions = fresh.filter(
                        (entry) => !known.has(entry.ts + entry.line),
                    );
                    if (additions.length === 0) return current;
                    lastTs.current = additions[0].ts;
                    return [...additions, ...current].slice(0, 1000);
                });
            }
        }, 2000);
        return () => clearInterval(interval);
    }, [live, fetchLogs]);

    return (
        <>
            <Head title="Logs" />

            <div className="flex h-full flex-col space-y-4 p-4">
                <Heading
                    variant="small"
                    title="Logs"
                    description="Search and live-tail logs across your servers"
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Select value={serverId} onValueChange={setServerId}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All servers" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All servers</SelectItem>
                            {servers.map((server) => (
                                <SelectItem
                                    key={server.id}
                                    value={String(server.id)}
                                >
                                    {server.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={source} onValueChange={setSource}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All sources" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All sources</SelectItem>
                            {sources.map((name) => (
                                <SelectItem key={name} value={name}>
                                    {name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={range} onValueChange={setRange}>
                        <SelectTrigger className="w-28">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.keys(RANGES).map((key) => (
                                <SelectItem key={key} value={key}>
                                    Last {key}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) =>
                            event.key === 'Enter' && runSearch()
                        }
                        placeholder="Contains text…"
                        className="w-64"
                    />

                    <Button onClick={runSearch} disabled={loading}>
                        <Search className="size-4" /> Search
                    </Button>

                    <Button
                        variant={live ? 'default' : 'outline'}
                        onClick={() => {
                            if (!live && entries.length === 0) runSearch();
                            setLive(!live);
                        }}
                    >
                        {live ? (
                            <>
                                <Pause className="size-4" /> Pause
                            </>
                        ) : (
                            <>
                                <Play className="size-4" /> Live
                            </>
                        )}
                    </Button>
                </div>

                {error && (
                    <p className="rounded-md border border-destructive/40 p-3 text-sm text-destructive">
                        {error}
                    </p>
                )}

                <div className="min-h-0 flex-1 overflow-y-auto rounded-xl border">
                    {entries.length === 0 ? (
                        <p className="p-8 text-center text-sm text-muted-foreground">
                            {loading
                                ? 'Searching…'
                                : 'No log entries. Adjust filters and search.'}
                        </p>
                    ) : (
                        entries.map((entry) => (
                            <LogRow
                                key={entry.ts + entry.line.slice(0, 80)}
                                entry={entry}
                            />
                        ))
                    )}
                </div>
            </div>
        </>
    );
}
```

Note the tail `start` computation: `logs.query` takes unix **seconds**, and `entry.ts` is nanoseconds, so tail polls re-fetch the last second and dedupe client-side via the `known` set. Apply the same layout-assignment convention as the servers pages.

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: success, no TS errors.

- [ ] **Step 3: Run the logs feature tests**

Run: `php artisan test --compact --filter=LogQueryTest`
Expected: still PASS.

- [ ] **Step 4: Commit**

```bash
npm run lint
git add -A && git commit -m "feat: log explorer with search and 2s live tail"
```

---

### Task 12: Final verification

**Files:** none new.

- [ ] **Step 1: Full test suite**

Run: `php artisan test --compact`
Expected: all tests pass, including pre-existing auth/teams/settings tests.

- [ ] **Step 2: Static analysis + formatting + frontend**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=512M
npm run lint && npm run build
```

Expected: clean (fix anything Larastan flags in the new files).

- [ ] **Step 3: Local smoke test with real Loki**

```bash
docker compose up -d
curl -s http://localhost:3100/ready   # → ready
```

Then with the app running (`composer run dev`): create a team server in the UI, copy the token from the install banner, and simulate an agent:

```bash
TOKEN=<paste>
# Config poll works:
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/agent/config | head -20
# Push a real JSON-format Loki payload (Loki accepts JSON pushes as well as protobuf):
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"streams":[{"stream":{"server_id":"1","source":"smoke"},"values":[["'$(date +%s)'000000000","hello from smoke test"]]}]}' \
  http://localhost:8000/api/agent/push
# Expect 204. Now the entry must appear in the Logs page (search "hello").
```

Expected: 204 from push, entry visible in the log explorer, server shows "online".

- [ ] **Step 4: Commit any fixes**

```bash
git add -A && git commit -m "chore: milestone 1 verification fixes"
```

- [ ] **Step 5: Manual E2E checklist (real VPS — do when infrastructure is available)**

Not automatable here; record results in the PR/notes:

1. Deploy app + Loki compose to the VPS with real `APP_URL` (HTTPS) and `LOKI_URL`.
2. Add a server in the UI; run the install command on a fresh Ubuntu 24.04 box → script completes, `systemctl status alloy` active.
3. Server flips to **online** within a minute; hostname + agent version populate.
4. Add a `file` source pointing at `/var/log/syslog` (or a systemd source) → logs appear in the explorer within ~2 minutes.
5. Live tail shows new lines (`logger "tail-test-$(date +%s)"`).
6. Edit the source (add a unit filter) → `/etc/alloy/config.alloy` on the box changes within a minute and Alloy reloads.
7. Regenerate the token → agent polls start failing (401 in updater logs), server goes offline in ~5 min; re-run a fresh install command → back online.
8. Second team cannot see the first team's servers or logs (switch teams and verify empty).
9. Repeat install on a Rocky/Alma box to exercise the rpm path.
```
