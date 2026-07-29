# Container Source In-UI Guidance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the `container` log source self-explanatory in the UI — a form-time explainer when the type is chosen, a numbered copy-paste terminal runbook on the saved source, and a path field that rejects the `/*.log` mistake.

**Architecture:** All command text comes from the tested `ContainerMountInstructions` service (extended with prepare/recreate/tailGlob, dokploy dropped). The frontend `ContainerMountPanel` becomes a numbered runbook; the `SourceForm` container block gains an explainer + live preview. Validation gains two `not_regex` rules. No new endpoint; renderer untouched.

**Tech Stack:** Laravel 13, Pest 4, Inertia v3 + React 19, Tailwind v4/shadcn.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-29-container-source-guidance-design.md`.
- Host path stays id-derived (`/var/log/lina/<id>`) — unchanged. Renderer stays `/var/log/lina/<id>/*.log` — do NOT touch `AlloyConfigRenderer`.
- Terminal-first copy (compose + docker run); a single static "managed platform" aside in the UI, no Dokploy-specific service output.
- Path field: reject `*` glob and trailing slash server-side with a clear message; existing `starts_with:/`, `max:1024` stay.
- After PHP changes: `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --no-progress` clean. Frontend: `npm run build` zero TS errors, `npx prettier --write` touched files, `npm run lint` clean.
- Full `php artisan test --compact` green before each commit. Known pre-existing tsc issue in widget-dialog.tsx is out of scope; `npm run build` passes regardless.
- 1Password commit signing — if it fails, report BLOCKED.

---

### Task 1: Backend — service copy + path validation

**Files:**
- Modify: `app/Services/ContainerMountInstructions.php`, `app/Http/Requests/Servers/SaveLogSourceRequest.php`
- Test: `tests/Feature/Servers/ContainerSourceTest.php` (extend — it exists)

**Interfaces:**
- Produces: `ContainerMountInstructions::for()` returns `array{hostPath, containerPath, compose, dockerRun, prepare, recreate, tailGlob}` (no `dokploy`). Validation rejects a `container` `in_container_path` containing `*` or ending in `/`.

- [ ] **Step 1: Update the existing service tests + add validation tests** in `tests/Feature/Servers/ContainerSourceTest.php`. Replace the existing "builds mount instructions" test's assertions and add the two validation cases:

```php
it('builds mount instructions from the source id and in-container path', function () {
    $server = Server::factory()->for($this->team)->create();
    $source = LogSource::factory()->for($server)->create([
        'type' => LogSourceType::Container,
        'name' => 'store-app',
        'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs'],
    ]);

    $i = ContainerMountInstructions::for($source);
    $host = '/var/log/lina/'.$source->id;

    expect($i['hostPath'])->toBe($host)
        ->and($i['containerPath'])->toBe('/var/www/html/storage/logs')
        ->and($i['compose'])->toBe("- {$host}:/var/www/html/storage/logs")
        ->and($i['dockerRun'])->toBe("-v {$host}:/var/www/html/storage/logs")
        ->and($i['prepare'])->toBe("sudo mkdir -p {$host} && sudo chmod 777 {$host}")
        ->and($i['recreate'])->toBe('docker compose up -d')
        ->and($i['tailGlob'])->toBe("{$host}/*.log")
        ->and($i)->not->toHaveKey('dokploy');
});

it('rejects an in-container path containing a glob', function () {
    $server = Server::factory()->for($this->team)->create();

    $this->post(route('servers.sources.store', [$this->team->slug, $server]), [
        'type' => 'container', 'name' => 'store-app',
        'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs/*.log'],
    ])->assertSessionHasErrors('config.in_container_path');
});

it('rejects an in-container path with a trailing slash', function () {
    $server = Server::factory()->for($this->team)->create();

    $this->post(route('servers.sources.store', [$this->team->slug, $server]), [
        'type' => 'container', 'name' => 'store-app',
        'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs/'],
    ])->assertSessionHasErrors('config.in_container_path');
});

it('accepts a clean directory path', function () {
    $server = Server::factory()->for($this->team)->create();

    $this->post(route('servers.sources.store', [$this->team->slug, $server]), [
        'type' => 'container', 'name' => 'store-app',
        'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs'],
    ])->assertRedirect();
});
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --compact --filter=ContainerSourceTest` — FAIL (missing keys / glob accepted).

- [ ] **Step 3: Implement.** `app/Services/ContainerMountInstructions.php` — replace the return array + PHPDoc:

```php
    /**
     * The copy-paste bind-mount runbook for a container source. The host path
     * is derived from the source id so it is always stable and unique.
     *
     * @return array{hostPath: string, containerPath: string, compose: string, dockerRun: string, prepare: string, recreate: string, tailGlob: string}
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
            'prepare' => "sudo mkdir -p {$hostPath} && sudo chmod 777 {$hostPath}",
            'recreate' => 'docker compose up -d',
            'tailGlob' => "{$hostPath}/*.log",
        ];
    }
```

`app/Http/Requests/Servers/SaveLogSourceRequest.php` — the `container` arm becomes:

```php
LogSourceType::Container => [
    'config.container' => ['required', 'string', 'max:255'],
    'config.in_container_path' => [
        'required', 'string', 'max:1024', 'starts_with:/',
        'not_regex:/\*/', 'not_regex:/\/$/',
    ],
],
```

Add a message so the error is clear — in the request's `messages()` method (create it if absent):

```php
public function messages(): array
{
    return [
        'config.in_container_path.not_regex' => 'Enter the directory only — no "*" glob or trailing slash.',
    ];
}
```

- [ ] **Step 4: Run tests** — `php artisan test --compact --filter=ContainerSourceTest` PASS; full `php artisan test --compact` green.
- [ ] **Step 5: Pint, phpstan, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --no-progress | tail -5
git add -A && git commit -m "feat: container mount runbook copy + path input hardening"
```

---

### Task 2: Frontend — runbook panel, form explainer + live preview

**Files:**
- Modify: `resources/js/types/logging.ts`, `resources/js/pages/servers/show.tsx`

**Interfaces:**
- Consumes: the extended `mountInstructions` from Task 1.

- [ ] **Step 1: Types.** In `resources/js/types/logging.ts`, change the `mountInstructions` shape on `LogSourceItem` to:

```ts
    mountInstructions?: {
        hostPath: string;
        containerPath: string;
        compose: string;
        dockerRun: string;
        prepare: string;
        recreate: string;
        tailGlob: string;
    };
```

- [ ] **Step 2: Rewrite `ContainerMountPanel`** in `resources/js/pages/servers/show.tsx` as a numbered runbook (keep the `checkForLogs` logic and the props exactly; only the returned JSX changes). Replace the `return (...)` with:

```tsx
    return (
        <div className="mt-3 space-y-3 rounded-lg border bg-muted/30 p-3 text-sm">
            <p className="text-xs text-muted-foreground">
                Lina's agent runs on your host and can't read files inside a
                container. These one-time steps expose the log directory to the
                host so the agent can tail it.
            </p>

            <div className="space-y-1">
                <p className="text-xs font-medium">1. Prepare the host folder</p>
                <p className="rounded bg-background p-2 font-mono text-xs break-all">
                    {mountInstructions.prepare}
                </p>
                <p className="text-xs text-muted-foreground">
                    So the container can write logs here and the agent can read
                    them.
                </p>
            </div>

            <div className="space-y-1">
                <p className="text-xs font-medium">
                    2. Add the mount and recreate the container
                </p>
                <p className="text-xs text-muted-foreground">
                    docker compose — add under the service's{' '}
                    <code>volumes:</code>, then recreate:
                </p>
                <p className="rounded bg-background p-2 font-mono text-xs break-all">
                    {mountInstructions.compose}
                </p>
                <p className="rounded bg-background p-2 font-mono text-xs break-all">
                    {mountInstructions.recreate}
                </p>
                <p className="text-xs text-muted-foreground">
                    or docker run — stop, remove, and re-run the container with:
                </p>
                <p className="rounded bg-background p-2 font-mono text-xs break-all">
                    {mountInstructions.dockerRun}
                </p>
                <p className="text-xs text-muted-foreground">
                    On a managed platform, add the same bind mount in its UI and
                    redeploy.
                </p>
            </div>

            <div className="space-y-1">
                <p className="text-xs font-medium">3. Verify</p>
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={checkForLogs}
                        disabled={checking}
                    >
                        Check for logs
                    </Button>
                    {result === 'found' && (
                        <span className="text-success">Logs are arriving</span>
                    )}
                    {result === 'empty' && (
                        <span className="text-muted-foreground">
                            No logs yet — apply the steps above and recreate the
                            container.
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
```

- [ ] **Step 3: Form explainer + live preview.** In the `SourceForm` component's `type === 'container'` block, (a) make the path input controlled so we can preview it, and (b) add the explainer + preview. Add near the top of `SourceForm` a state seeded from the source:

```tsx
const [inContainerPath, setInContainerPath] = useState(
    source?.config.in_container_path ?? '',
);
```

Change the path `Input` to controlled (`value={inContainerPath}` + `onChange={(e) => setInContainerPath(e.target.value)}`, keep `name="config_in_container_path"`), and replace the single helper `<p>` with:

```tsx
<p className="text-xs text-muted-foreground">
    Directory only — no <code>/*.log</code>. Lina's agent runs on your
    host and can't read inside a container, so after you save you'll get
    one-time terminal commands to mount this directory to the host.
</p>
{inContainerPath.trim() !== '' && (
    <p className="text-xs text-muted-foreground">
        Lina will tail{' '}
        <code className="break-all">
            {inContainerPath.replace(/\/+$/, '')}/*.log
        </code>{' '}
        once mounted. Exact host path shown after you save.
    </p>
)}
```

- [ ] **Step 4: Gates.** `npm run build` zero TS errors; `npx prettier --write` the two files; `npm run lint`; `php artisan test --compact` green (no backend change here). If routes unchanged, no wayfinder regen.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: container source form explainer, live preview, runbook panel"`.

---

### Task 3: Deploy to server B + verify

**Files:** none (deployment; post-merge on `main`).

- [ ] **Step 1:** Local: full suite green, `npm run build`, tarball `public/build`, `git bundle` of `main`.
- [ ] **Step 2:** Deploy to B (established flow): scp → `sudo git checkout -- .` → fetch/merge --ff-only → extract build → `migrate --force` (no new migration) → `view:clear && config:cache && route:cache` → `chown`.
- [ ] **Step 3:** Verify at http://18.159.103.136: add a container source → the form shows the explainer + a live "will tail …/*.log" preview as you type; entering `/foo/*.log` is rejected with the glob message; saving shows the numbered runbook with the real `/var/log/lina/<id>` prepare/compose/recreate/dockerRun commands. Use source #7 (Qonvo -lara) to confirm its panel now reads as a runbook.
- [ ] **Step 4:** Record results in `.superpowers/sdd/progress.md`.

---

## Self-Review Notes

- Spec coverage: service prepare/recreate/tailGlob + drop dokploy (T1), glob/trailing-slash validation with message (T1), runbook panel (T2), form explainer + live preview (T2), deploy + verify (T3).
- Type consistency: `mountInstructions` keys in types/logging.ts (T2) match the service return shape (T1) exactly — hostPath/containerPath/compose/dockerRun/prepare/recreate/tailGlob, no dokploy; the panel consumes prepare/compose/recreate/dockerRun; the live preview mirrors the backend `tailGlob` derivation (`<dir>/*.log`, trailing slashes stripped).
- Judgment areas: exact current `SourceForm` state block location (read before inserting); confirm `ContainerMountPanel`'s existing imports (Button, useState) already present.
