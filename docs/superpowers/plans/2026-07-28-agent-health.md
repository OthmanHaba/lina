# Agent Health Implementation Plan (M2 Slice 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface per-server agent health (poll/push recency, config-drift status, 14-day ingest chart) on the server detail page.

**Architecture:** Four nullable columns on `servers` recorded by the existing agent endpoints (poll time from the config endpoint, push time from ingest, sync hash from 304 responses). Status derivation and the usage series are `Server` model methods consumed by `ServerController::show` as one `health` prop; the UI is one card section with a CSS-only bar chart.

**Tech Stack:** Laravel 13, Pest 4, Inertia v3 + React 19 (no new dependencies, no chart library).

**Spec:** `docs/superpowers/specs/2026-07-28-agent-health-design.md`

## Global Constraints

- All health writes are throttled to once per 30s per server per field-group (same pattern as `last_seen_at` in `AuthenticateAgent`); `last_seen_at` and status derivation are untouched.
- A `304` response is the only place `synced_config_hash`/`config_synced_at` are recorded (the agent proved it holds the current config); `200` records nothing extra.
- `configStatus()` contract: `synced` (synced hash == sha1 of currently rendered config), `syncing` (differs or absent AND `last_poll_at` within 5 minutes), `unknown` (otherwise).
- `usage` series: exactly 14 entries, oldest first, zero-filled, `{date: 'YYYY-MM-DD', bytes: int}`.
- Run `vendor/bin/pint --dirty --format agent` before every commit touching PHP.

---

### Task 1: Health columns + recording in agent endpoints

**Files:**
- Create: migration `add_health_columns_to_servers_table` (via artisan)
- Modify: `app/Models/Server.php` (fillable, casts, PHPDoc)
- Modify: `app/Http/Controllers/Agent/AgentConfigController.php`
- Modify: `app/Http/Controllers/Agent/IngestController.php`
- Test: `tests/Feature/Agent/AgentHealthRecordingTest.php`

**Interfaces:**
- Consumes: existing `AuthenticateAgent` flow (server in request attributes), existing controllers as shown below.
- Produces: `Server` columns `last_poll_at`, `last_push_at`, `config_synced_at` (datetime casts, nullable), `synced_config_hash` (string 40, nullable). Task 2 reads them.

- [ ] **Step 1: Generate files**

```bash
php artisan make:migration add_health_columns_to_servers_table --table=servers --no-interaction
php artisan make:test --pest Agent/AgentHealthRecordingTest --no-interaction
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Agent/AgentHealthRecordingTest.php`:

```php
<?php

use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function healthAgent(): array
{
    $server = Server::factory()->create();
    $plain = $server->newToken();

    return [$server, $plain];
}

function healthPush(string $token)
{
    return test()->call('POST', '/api/agent/push', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'CONTENT_TYPE' => 'application/x-protobuf',
    ], content: 'body');
}

test('config polls bump last_poll_at but not last_push_at', function () {
    [$server, $plain] = healthAgent();

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->get('/api/agent/config')
        ->assertOk();

    $server->refresh();
    expect($server->last_poll_at)->not->toBeNull()
        ->and($server->last_push_at)->toBeNull();
});

test('pushes bump last_push_at but not last_poll_at', function () {
    Http::fake(['*/loki/api/v1/push' => Http::response('', 204)]);
    [$server, $plain] = healthAgent();

    healthPush($plain)->assertNoContent();

    $server->refresh();
    expect($server->last_push_at)->not->toBeNull()
        ->and($server->last_poll_at)->toBeNull();
});

test('poll bumps are throttled to 30 seconds', function () {
    [$server, $plain] = healthAgent();

    Carbon::setTestNow('2026-07-28 10:00:00');
    $this->withHeader('Authorization', 'Bearer '.$plain)->get('/api/agent/config');
    $first = $server->fresh()->last_poll_at;

    Carbon::setTestNow('2026-07-28 10:00:10');
    $this->withHeader('Authorization', 'Bearer '.$plain)->get('/api/agent/config');
    expect($server->fresh()->last_poll_at)->toEqual($first);

    Carbon::setTestNow('2026-07-28 10:00:45');
    $this->withHeader('Authorization', 'Bearer '.$plain)->get('/api/agent/config');
    expect($server->fresh()->last_poll_at)->toBeGreaterThan($first);

    Carbon::setTestNow();
});

test('a 304 poll records config sync while a 200 does not', function () {
    [$server, $plain] = healthAgent();

    $response = $this->withHeader('Authorization', 'Bearer '.$plain)->get('/api/agent/config');
    $etag = $response->headers->get('ETag');

    $server->refresh();
    expect($server->synced_config_hash)->toBeNull()
        ->and($server->config_synced_at)->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'If-None-Match' => $etag])
        ->get('/api/agent/config')
        ->assertStatus(304);

    $server->refresh();
    expect($server->synced_config_hash)->toBe(trim($etag, '"'))
        ->and($server->config_synced_at)->not->toBeNull();
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=AgentHealthRecordingTest`
Expected: FAIL (columns missing).

- [ ] **Step 4: Write the migration**

`up()`:

```php
Schema::table('servers', function (Blueprint $table) {
    $table->timestamp('last_poll_at')->nullable();
    $table->timestamp('last_push_at')->nullable();
    $table->timestamp('config_synced_at')->nullable();
    $table->string('synced_config_hash', 40)->nullable();
});
```

`down()`:

```php
Schema::table('servers', function (Blueprint $table) {
    $table->dropColumn(['last_poll_at', 'last_push_at', 'config_synced_at', 'synced_config_hash']);
});
```

- [ ] **Step 5: Extend the Server model**

In `app/Models/Server.php`:
- Add to the `#[Fillable]` array: `'last_poll_at', 'last_push_at', 'config_synced_at', 'synced_config_hash'`.
- Add to `casts()`: `'last_poll_at' => 'datetime', 'last_push_at' => 'datetime', 'config_synced_at' => 'datetime',`.
- Add to the PHPDoc block: `@property Carbon|null $last_poll_at`, `@property Carbon|null $last_push_at`, `@property Carbon|null $config_synced_at`, `@property string|null $synced_config_hash`.

- [ ] **Step 6: Record in the config endpoint**

Replace the body of `AgentConfigController::__invoke` with:

```php
/** @var Server $server */
$server = $request->attributes->get('server');

$updates = array_filter([
    'hostname' => $request->header('X-Lina-Hostname'),
    'agent_version' => $request->header('X-Lina-Agent-Version'),
]);

if ($server->last_poll_at === null || $server->last_poll_at->lt(now()->subSeconds(30))) {
    $updates['last_poll_at'] = now();
}

$config = $renderer->render($server);
$hash = sha1($config);
$etag = '"'.$hash.'"';

if ($request->header('If-None-Match') === $etag) {
    // A 304 is the agent proving it holds the current config.
    if ($server->synced_config_hash !== $hash
        || $server->config_synced_at === null
        || $server->config_synced_at->lt(now()->subSeconds(30))) {
        $updates['synced_config_hash'] = $hash;
        $updates['config_synced_at'] = now();
    }

    $server->forceFill($updates)->save();

    return response('', 304, ['ETag' => $etag]);
}

$server->forceFill($updates)->save();

return response($config, 200, [
    'Content-Type' => 'text/plain',
    'ETag' => $etag,
]);
```

- [ ] **Step 7: Record in the ingest endpoint**

In `IngestController::__invoke`, after `$this->recordUsage($server, strlen($body));` add:

```php
if ($server->last_push_at === null || $server->last_push_at->lt(now()->subSeconds(30))) {
    $server->forceFill(['last_push_at' => now()])->save();
}
```

- [ ] **Step 8: Migrate and run tests**

Run: `php artisan migrate --no-interaction && php artisan test --compact --filter="AgentHealthRecordingTest|AgentConfigTest|IngestTest"`
Expected: PASS — the 4 new tests plus the existing agent suites (10 tests) stay green.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: record agent poll/push recency and config sync"
```

---

### Task 2: configStatus + usage series + health prop

**Files:**
- Modify: `app/Models/Server.php`
- Modify: `app/Http/Controllers/Servers/ServerController.php` (`show`)
- Test: `tests/Feature/Servers/AgentHealthTest.php`

**Interfaces:**
- Consumes: Task 1 columns; `App\Services\AlloyConfigRenderer::render(Server): string`; `App\Models\UsageRecord`.
- Produces:
  - `Server::configStatus(): string` — `'synced' | 'syncing' | 'unknown'` per the Global Constraints contract.
  - `Server::usageSeries(int $days = 14): array` — `[{date: 'YYYY-MM-DD', bytes: int}]`, exactly `$days` entries, oldest first, zero-filled.
  - `servers/show` prop `health: {lastPollAt, lastPushAt, configStatus, configSyncedAt, usage}` (ISO strings / null; usage as above). Task 3 consumes this exact shape.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Servers/AgentHealthTest.php`:

```php
<?php

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\AlloyConfigRenderer;
use Inertia\Testing\AssertableInertia as Assert;

test('configStatus is synced when the synced hash matches the rendered config', function () {
    $server = Server::factory()->create();
    $hash = sha1(app(AlloyConfigRenderer::class)->render($server));
    $server->forceFill(['synced_config_hash' => $hash, 'config_synced_at' => now(), 'last_poll_at' => now()])->save();

    expect($server->configStatus())->toBe('synced');
});

test('configStatus flips to syncing when the config changes and polls are recent', function () {
    $server = Server::factory()->create();
    $hash = sha1(app(AlloyConfigRenderer::class)->render($server));
    $server->forceFill(['synced_config_hash' => $hash, 'last_poll_at' => now()])->save();

    $server->logSources()->create(['type' => 'systemd', 'name' => 'journal', 'config' => [], 'enabled' => true]);

    expect($server->fresh()->configStatus())->toBe('syncing');
});

test('configStatus is unknown when the agent is silent', function () {
    expect(Server::factory()->create()->configStatus())->toBe('unknown')
        ->and(Server::factory()->create(['last_poll_at' => now()->subMinutes(10)])->configStatus())->toBe('unknown');
});

test('usageSeries returns 14 zero-filled entries oldest first', function () {
    $server = Server::factory()->create();
    $server->usageRecords()->create(['date' => today(), 'bytes' => 100]);
    $server->usageRecords()->create(['date' => today()->subDays(3), 'bytes' => 50]);
    $server->usageRecords()->create(['date' => today()->subDays(20), 'bytes' => 999]); // outside window

    $series = $server->usageSeries();

    expect($series)->toHaveCount(14)
        ->and($series[0]['date'])->toBe(today()->subDays(13)->toDateString())
        ->and($series[13])->toBe(['date' => today()->toDateString(), 'bytes' => 100])
        ->and($series[10]['bytes'])->toBe(50)
        ->and(collect($series)->sum('bytes'))->toBe(150);
});

test('the server page includes the health prop', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);
    $server = Server::factory()->for($team)->online()->create(['last_poll_at' => now(), 'last_push_at' => now()]);

    $this->actingAs($user)
        ->get(route('servers.show', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/show')
            ->where('health.configStatus', 'syncing')
            ->has('health.usage', 14)
            ->whereNot('health.lastPollAt', null)
            ->whereNot('health.lastPushAt', null),
        );
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AgentHealthTest`
Expected: FAIL — methods missing.

- [ ] **Step 3: Add the model methods**

In `app/Models/Server.php` (after `bytesToday()`; add imports `App\Services\AlloyConfigRenderer` and `App\Models\UsageRecord` is same namespace so only the service import):

```php
/**
 * Config drift status derived from the last 304-confirmed hash.
 * Renders the current config — call from page views, not hot paths.
 */
public function configStatus(): string
{
    $current = sha1(app(AlloyConfigRenderer::class)->render($this));

    return match (true) {
        $this->synced_config_hash === $current => 'synced',
        (bool) $this->last_poll_at?->gt(now()->subMinutes(5)) => 'syncing',
        default => 'unknown',
    };
}

/**
 * Daily ingest bytes for the trailing window, zero-filled, oldest first.
 *
 * @return array<int, array{date: string, bytes: int}>
 */
public function usageSeries(int $days = 14): array
{
    $bytesByDate = $this->usageRecords()
        ->whereDate('date', '>=', today()->subDays($days - 1))
        ->get()
        ->mapWithKeys(fn (UsageRecord $record) => [$record->date->toDateString() => $record->bytes]);

    return collect(range($days - 1, 0))
        ->map(fn (int $daysAgo) => [
            'date' => today()->subDays($daysAgo)->toDateString(),
            'bytes' => (int) ($bytesByDate[today()->subDays($daysAgo)->toDateString()] ?? 0),
        ])
        ->all();
}
```

- [ ] **Step 4: Add the health prop**

In `ServerController::show`, add to the `Inertia::render('servers/show', [...])` array after `'server' => [...]`:

```php
'health' => [
    'lastPollAt' => $server->last_poll_at?->toISOString(),
    'lastPushAt' => $server->last_push_at?->toISOString(),
    'configStatus' => $server->configStatus(),
    'configSyncedAt' => $server->config_synced_at?->toISOString(),
    'usage' => $server->usageSeries(),
],
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter="AgentHealthTest|ServerControllerTest"`
Expected: PASS (5 new + 7 existing).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: config drift status and usage series with health page prop"
```

---

### Task 3: Health card UI

**Files:**
- Modify: `resources/js/types/logging.ts` + (re-export already covered by `export type * from './logging'`)
- Modify: `resources/js/pages/servers/show.tsx`

**Interfaces:**
- Consumes: the `health` prop from Task 2 (exact shape below); existing page structure and conventions in `servers/show.tsx`.

- [ ] **Step 1: Add the type**

Append to `resources/js/types/logging.ts`:

```ts
export type ServerHealth = {
    lastPollAt: string | null;
    lastPushAt: string | null;
    configStatus: 'synced' | 'syncing' | 'unknown';
    configSyncedAt: string | null;
    usage: { date: string; bytes: number }[];
};
```

- [ ] **Step 2: Add the health card to `servers/show.tsx`**

Add `health: ServerHealth` to the page `Props` and render an "Agent health" section between the existing meta-tile grid and the "Log sources" section, following the page's existing card/tile styling:

- Three stat tiles (same `rounded-xl border p-4` tiles as the existing meta row):
  - **Last config poll** — relative time from `lastPollAt` (`new Date(...).toLocaleString()` or a small `relativeTime` helper; "Never" when null)
  - **Last log push** — same treatment of `lastPushAt`
  - **Config** — colored dot + label: green + "In sync" (`synced`, with `configSyncedAt` time muted underneath), amber + "Syncing…" (`syncing`), gray + "Unknown" (`unknown`). Dot: `<span className="inline-block size-2 rounded-full bg-emerald-500" />` (amber-500/gray-400 variants).
- **Ingest, last 14 days**: a `flex items-end gap-1 h-24` row of 14 bars; each bar `flex-1 rounded-t bg-primary/70` with inline `style={{ height: \`${(bytes / max) * 100}%\` }}` (min height 2px so zero-days remain visible), `title={\`${date}: ${formatBytes(bytes)}\`}` for hover. Reuse the existing `formatBytes` helper from `servers/index.tsx`. When every day is zero, show the muted line "No ingest in the last 14 days" instead of the bars.

- [ ] **Step 3: Build + lint + backend contract**

Run: `npm run build && npm run lint && php artisan test --compact --filter=AgentHealthTest`
Expected: zero TS errors, lint clean, tests green.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: agent health card with ingest chart"
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

Expected: suite green, phpstan 0 errors, build clean. Commit any fixes.

- [ ] **Step 2: Deploy to server B**

```bash
git bundle create /tmp/lina-health.bundle HEAD
tar -czf /tmp/build-health.tgz public/build
scp /tmp/lina-health.bundle /tmp/build-health.tgz ubuntu@18.159.103.136:/tmp/
ssh ubuntu@18.159.103.136 '
  cd /var/www/lina &&
  sudo git checkout -- . &&
  sudo git fetch /tmp/lina-health.bundle HEAD && sudo git merge --ff-only FETCH_HEAD &&
  sudo tar -xzf /tmp/build-health.tgz -C /var/www/lina 2>/dev/null;
  php artisan migrate --force --no-interaction &&
  php artisan view:clear -q && php artisan config:cache -q && php artisan route:cache -q &&
  sudo chown -R ubuntu:www-data /var/www/lina && sudo chmod -R g+w storage bootstrap/cache database &&
  echo DEPLOY_DONE
'
```

(`sudo git checkout -- .` first: the extracted `public/build` files can otherwise block the merge — learned in the alerts deploy.)

- [ ] **Step 3: Live verification**

Server A's agent polls every 60s and pushes continuously, so within ~2 minutes:

```bash
ssh ubuntu@18.159.103.136 'cd /var/www/lina && php artisan tinker --execute "
\$s = App\Models\Server::first();
echo \"poll=\".(\$s->last_poll_at?->diffForHumans() ?? \"never\").\" push=\".(\$s->last_push_at?->diffForHumans() ?? \"never\").\" config=\".\$s->configStatus();"'
```

Expected: `poll=<1 minute ago push=<1 minute ago config=synced` (config flips to synced on the first 304 after deploy). Then load the server page in the UI and confirm the health card renders with real values and the 14-day chart shows the smoke-test days' bytes.

- [ ] **Step 4: Commit any fixes**

```bash
git add -A && git commit -m "chore: agent health verification fixes" # only if needed
```
