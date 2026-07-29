# Container File Log Source Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `container` log-source type that tails a file inside a Docker container by guiding the customer to bind-mount the in-container log directory to a fixed host path, which the existing File-tail machinery then reads.

**Architecture:** `container` is a thin type over the File source: it renders the identical `local.file_match`/`loki.source.file` block on `/var/log/lina/<source-id>/*.log`, but stores the container name + in-container path so the UI can regenerate the bind-mount snippet and drive a "waiting for mount" state. No migration, no new agent capability, agent stays non-root.

**Tech Stack:** Laravel 13, Pest 4, Inertia v3 + React 19, Grafana Alloy (rendered server-side).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-29-container-file-source-design.md`.
- **REPO QUIRK:** controller methods under the `{current_team}` prefix take `string $current_team` as the first route param before route-bound models. Do not drop it.
- Host path is a pure function of the source id: `/var/log/lina/{id}` — never stored, never user-chosen.
- Glob is `/var/log/lina/{id}/*.log` (catches daily rotation + all channels).
- Config keys for the `container` type: `container` (string) and `in_container_path` (absolute string). No other keys.
- The `container` type renders through the EXISTING `fileBlock` — do not write a new Alloy block type.
- After PHP changes: `vendor/bin/pint --dirty --format agent`; larastan clean (`vendor/bin/phpstan analyse --no-progress`). After route changes: `php artisan wayfinder:generate --no-interaction --with-form`. Frontend: `npm run build` zero TS errors, `npx prettier --write` touched files, `npm run lint` clean.
- Tests: `php artisan test --compact --filter=...`, then the full suite before each commit.
- Commit signing uses 1Password — if it fails, report BLOCKED.

---

### Task 1: Backend — `container` type, renderer arm, mount-instructions service, validation

**Files:**
- Modify: `app/Enums/LogSourceType.php` (add case), `app/Services/AlloyConfigRenderer.php` (match arm), `app/Http/Requests/Servers/SaveLogSourceRequest.php` (validation arm)
- Create: `app/Services/ContainerMountInstructions.php`
- Test: `tests/Feature/Servers/ContainerSourceTest.php` (new), and extend whatever renderer test already exists (find with `grep -rl AlloyConfigRenderer tests`)

**Interfaces:**
- Produces: `LogSourceType::Container` (value `container`); `ContainerMountInstructions::for(LogSource $source): array` returning `array{hostPath:string, containerPath:string, compose:string, dockerRun:string, dokploy:string}`; validation requiring `config.container` (string ≤255) and absolute `config.in_container_path` (string ≤1024, `starts_with:/`).

- [ ] **Step 1: Write the failing tests.** `php artisan make:test --pest Servers/ContainerSourceTest`. Use the auth/team/server setup style from the sibling `tests/Feature/Servers` tests (read one first — e.g. the existing log-source test — and mirror its `beforeEach`/factory usage).

```php
<?php

use App\Enums\LogSourceType;
use App\Models\LogSource;
use App\Models\Server;
use App\Services\AlloyConfigRenderer;
use App\Services\ContainerMountInstructions;

it('renders a container source as a file tail on the fixed host path', function () {
    $server = Server::factory()->for($this->team)->create();
    $source = LogSource::factory()->for($server)->create([
        'type' => LogSourceType::Container,
        'name' => 'store-app',
        'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs'],
    ]);

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('/var/log/lina/'.$source->id.'/*.log')
        ->toContain('loki.source.file "src_'.$source->id.'"');
});

it('builds mount instructions from the source id and in-container path', function () {
    $server = Server::factory()->for($this->team)->create();
    $source = LogSource::factory()->for($server)->create([
        'type' => LogSourceType::Container,
        'name' => 'store-app',
        'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs'],
    ]);

    $instructions = ContainerMountInstructions::for($source);

    expect($instructions['hostPath'])->toBe('/var/log/lina/'.$source->id)
        ->and($instructions['containerPath'])->toBe('/var/www/html/storage/logs')
        ->and($instructions['compose'])->toBe('- /var/log/lina/'.$source->id.':/var/www/html/storage/logs')
        ->and($instructions['dockerRun'])->toBe('-v /var/log/lina/'.$source->id.':/var/www/html/storage/logs')
        ->and($instructions['dokploy'])->toContain('Bind mount');
});

it('creates a container source with valid config', function () {
    $server = Server::factory()->for($this->team)->create();

    $this->post(route('servers.sources.store', [$this->team->slug, $server]), [
        'type' => 'container', 'name' => 'store-app',
        'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs'],
    ])->assertRedirect();

    expect(LogSource::where('server_id', $server->id)->where('type', LogSourceType::Container)->exists())->toBeTrue();
});

it('rejects a container source with a relative in-container path', function () {
    $server = Server::factory()->for($this->team)->create();

    $this->post(route('servers.sources.store', [$this->team->slug, $server]), [
        'type' => 'container', 'name' => 'store-app',
        'config' => ['container' => 'store-app', 'in_container_path' => 'storage/logs'],
    ])->assertSessionHasErrors('config.in_container_path');
});

it('rejects a container source missing the container name', function () {
    $server = Server::factory()->for($this->team)->create();

    $this->post(route('servers.sources.store', [$this->team->slug, $server]), [
        'type' => 'container', 'name' => 'store-app',
        'config' => ['in_container_path' => '/var/www/html/storage/logs'],
    ])->assertSessionHasErrors('config.container');
});
```

(If the existing renderer test uses a different entry method than `render($server)`, match the real signature — read `app/Services/AlloyConfigRenderer.php` for the public method name.)

- [ ] **Step 2: Run to verify failure** — `php artisan test --compact --filter=ContainerSourceTest` — FAIL (enum case missing).

- [ ] **Step 3: Implement.**

`app/Enums/LogSourceType.php` — add:

```php
case Container = 'container';
```

`app/Services/AlloyConfigRenderer.php` — add the match arm alongside the others (after the `File` arm):

```php
LogSourceType::Container => $this->fileBlock($source, ['/var/log/lina/'.$source->id.'/*.log']),
```

`app/Services/ContainerMountInstructions.php`:

```php
<?php

namespace App\Services;

use App\Models\LogSource;

class ContainerMountInstructions
{
    /**
     * The copy-paste bind-mount guidance for a container source. The host path
     * is derived from the source id so it is always stable and unique.
     *
     * @return array{hostPath: string, containerPath: string, compose: string, dockerRun: string, dokploy: string}
     */
    public static function for(LogSource $source): array
    {
        $hostPath = '/var/log/lina/'.$source->id;
        $containerPath = (string) ($source->config['in_container_path'] ?? '');

        return [
            'hostPath' => $hostPath,
            'containerPath' => $containerPath,
            'compose' => "- {$hostPath}:{$containerPath}",
            'dockerRun' => "-v {$hostPath}:{$containerPath}",
            'dokploy' => "Add a Bind mount — host path {$hostPath}, container path {$containerPath} — then redeploy the service so the container is recreated with the mount.",
        ];
    }
}
```

`app/Http/Requests/Servers/SaveLogSourceRequest.php` — add a `container` arm to the per-type `match` (alongside Systemd/Docker/Nginx/File):

```php
LogSourceType::Container => [
    'config.container' => ['required', 'string', 'max:255'],
    'config.in_container_path' => ['required', 'string', 'max:1024', 'starts_with:/'],
],
```

- [ ] **Step 4: Run tests** — `php artisan test --compact --filter=ContainerSourceTest` PASS; full `php artisan test --compact` green.

- [ ] **Step 5: Pint, larastan, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --no-progress | tail -5
git add -A && git commit -m "feat: container log source type, renderer, mount instructions, validation"
```

---

### Task 2: Frontend — form type, controller prop, detail card with mount snippet + verification

**Files:**
- Modify: `app/Http/Controllers/Servers/ServerController.php` (attach `mountInstructions` to container sources in the show payload — read the existing source `.map(...)` first), `resources/js/types/logging.ts`, `resources/js/pages/servers/show.tsx`
- Consumes: `ContainerMountInstructions::for()` (Task 1); the existing `logs.query` route (reused for verification).

**Interfaces:**
- Consumes: `LogSourceType::Container`; the `logs.query` GET endpoint (params `servers`, `sources` — comma lists — returns `{entries: [...]}`).
- Produces: `LogSourceItem.config` gains `container?: string; in_container_path?: string;`; `LogSourceItem` gains `mountInstructions?: { hostPath: string; containerPath: string; compose: string; dockerRun: string; dokploy: string }` (present only for container sources).

- [ ] **Step 1: Controller prop.** In `ServerController@show`, where sources are mapped to the array sent to Inertia (read the current mapping first), add `mountInstructions` for container sources:

```php
'mountInstructions' => $source->type === LogSourceType::Container
    ? \App\Services\ContainerMountInstructions::for($source)
    : null,
```

(import `App\Enums\LogSourceType` and the service if not already imported.) Keep every other mapped key as-is.

- [ ] **Step 2: Types.** `resources/js/types/logging.ts`:
  - `export type LogSourceType = 'systemd' | 'docker' | 'nginx' | 'file' | 'container';`
  - add to the `config` shape: `container?: string; in_container_path?: string;`
  - add to `LogSourceItem`: `mountInstructions?: { hostPath: string; containerPath: string; compose: string; dockerRun: string; dokploy: string; };`

- [ ] **Step 3: Form.** In `resources/js/pages/servers/show.tsx`:
  - `typeLabels` gains `container: 'File inside a container'`.
  - In the `transform` config object add: `container: data['config_container'], in_container_path: data['config_in_container_path'],` (harmless for other types — the backend `validated()` strips keys not in the active type's rules, exactly as it already does for units/containers/paths).
  - Add the type-specific field block (mirror the `type === 'nginx'` block's markup):

```tsx
{type === 'container' && (
    <>
        <div className="space-y-2">
            <Label htmlFor="config_container">Container name</Label>
            <Input
                id="config_container"
                name="config_container"
                defaultValue={source?.config.container}
                placeholder="store-app"
            />
            <InputError message={errors['config.container']} />
        </div>
        <div className="space-y-2">
            <Label htmlFor="config_in_container_path">Log directory inside the container</Label>
            <Input
                id="config_in_container_path"
                name="config_in_container_path"
                defaultValue={source?.config.in_container_path}
                placeholder="/var/www/html/storage/logs"
            />
            <InputError message={errors['config.in_container_path']} />
            <p className="text-xs text-muted-foreground">
                Lina tails <code>*.log</code> from this directory after you mount it to the host.
            </p>
        </div>
    </>
)}
```

- [ ] **Step 4: Detail card (mount snippet + verification).** Where existing sources are listed on the page (read the current list rendering), add for `source.type === 'container' && source.mountInstructions` a panel that:
  - Shows the compose line and the Dokploy prose from `source.mountInstructions`, each in a `font-mono` block, with a one-line note: "Apply this mount and recreate the container, then Lina will tail the logs."
  - Has a "Check for logs" button that calls the existing logs query for this source and reports the result. Build the URL with the wayfinder `query` route fn (same pattern `logs/index.tsx` uses — read its `fetchLogs`), passing `servers=<server.id>&sources=<source.name>`, `fetch` with `Accept: application/json`, then show ✓ "Logs are arriving" when `entries.length > 0`, else "No logs yet — apply the mount above and recreate the container." Keep it local state, no auto-poll.

- [ ] **Step 5: Gates.** `npm run build` zero TS errors; `npx prettier --write` the touched files; `npm run lint`; `php artisan test --compact` green (no backend behavior changed here, but the controller edit must not break the show test — run it). If routes were regenerated, `php artisan wayfinder:generate --no-interaction --with-form`.

- [ ] **Step 6: Commit** — `git add -A && git commit -m "feat: container source form, mount instructions and verification UI"`.

---

### Task 3: Deploy to server B + live E2E

**Files:** none in-repo (deployment; runs post-merge on `main`).

- [ ] **Step 1:** Local: full suite green, `npm run build`, `tar -czf /tmp/lina-build.tar.gz -C public build`, `git bundle create /tmp/lina-main.bundle main`.
- [ ] **Step 2:** Deploy to B (established flow): scp bundle+tarball → `sudo git checkout -- .` → fetch/merge --ff-only → extract build → `php artisan migrate --force --no-interaction` (no new migration; harmless) → `view:clear && config:cache && route:cache` → `chown -R ubuntu:www-data`.
- [ ] **Step 3: Live E2E (non-destructive — does NOT touch server A's real e-commerce app).** In Lina (http://18.159.103.136), create a **container** source on server A named `e2e-container`, in-container path `/logs`. Note the source id → host path `/var/log/lina/<id>`. On server A (`ssh ubuntu@63.183.193.136`) start a throwaway writer container using that exact bind mount:

```bash
ID=<source-id>
sudo mkdir -p /var/log/lina/$ID
docker run -d --name lina-e2e -v /var/log/lina/$ID:/logs busybox \
  sh -c 'while true; do echo "$(date -Is) e2e container log line"; done >> /logs/app.log 2>&1 & while true; do sleep 3600; done'
```

Wait ~90s (config poll + first push), then in Lina confirm the source's "Check for logs" flips to "Logs are arriving" and the lines appear on the Logs page filtered to `e2e-container`. Verify the agent (non-root `alloy`) can read `/var/log/lina/$ID/app.log` (perms note from the spec). Tear down: `docker rm -f lina-e2e && sudo rm -rf /var/log/lina/$ID`.
- [ ] **Step 4:** Record results in `.superpowers/sdd/progress.md`; fix-deploy loop for anything found.

---

## Self-Review Notes

- Spec coverage: enum + renderer reuse (T1), mount-instructions service (T1), validation arm with absolute-path rule (T1), form fields (T2), controller prop (T2), detail-card snippet + verification via existing logs.query (T2), deploy + live E2E proving the mount→tail path end to end (T3). Edge cases (readability, not-mounted → "no logs yet", shadowing) are surfaced as UI copy in T2 and verified in T3.
- Type consistency: `ContainerMountInstructions::for` return shape (hostPath/containerPath/compose/dockerRun/dokploy) matches the `mountInstructions` prop type in types/logging.ts and the T2 card usage; `LogSourceType::Container` value `container` matches the frontend union member and the form option; host-path glob `/var/log/lina/{id}/*.log` identical in renderer (T1) and E2E mount (T3).
- Judgment areas for implementers: exact public method name on AlloyConfigRenderer; exact source-list rendering block in servers/show.tsx; the logs.query wayfinder fn name (mirror logs/index.tsx).
