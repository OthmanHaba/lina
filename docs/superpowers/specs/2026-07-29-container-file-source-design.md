# Container File Log Source — Design

**Date:** 2026-07-29
**Status:** Approved
**Scope:** A new `container` log-source type that tails a file *inside* a running Docker container by guiding the customer to bind-mount the in-container log directory to a host path, then tailing that host path with the existing File-source machinery. The agent stays non-root; no Docker internals are read.

Out of scope: agent-side container inventory/auto-discovery, auto-editing the customer's compose/Dokploy config, reading unmounted container filesystems (rejected during brainstorming for the privilege cost), per-file (non-glob) selection.

## Decisions made

| Decision | Choice |
|---|---|
| Mechanism | Guided volume mount — customer applies a one-time bind mount + container recreate; agent tails the resulting host path |
| Guidance depth | Pure guided form — user types container name + in-container dir; no new agent telemetry |
| Host path | Fixed convention `/var/log/lina/<source-id>/`, derived from the source id (not stored, not user-chosen) |
| Source type | Distinct `container` type (renders identically to File, but stores container + in-container path for the mount snippet and empty state) |
| Glob | `<host-path>/*.log` — catches daily rotation and every channel |

## Why this mechanism

Alloy runs as the non-root `alloy` user (in `docker`/`systemd-journal` groups). It can reach the Docker socket but **cannot** read a container's filesystem (`/proc/<pid>/root`, `/var/lib/docker/overlay2` are root-only), and it tails static paths while an in-container file's host location changes on every restart. Bind-mounting the log dir to a stable host path sidesteps all of that: the file becomes an ordinary host-path tail — the exact case the File source already handles — with zero privilege escalation and restart-survival for free.

## Data model

No migration. `container` is added to `App\Enums\LogSourceType`. Config JSON for the type:

- `container` — string, the container name/service (display + snippet only).
- `in_container_path` — string, absolute path to the log **directory** inside the container (default surfaced in UI: `/var/www/html/storage/logs`).

The host path is a pure function of the source id: `/var/log/lina/{id}` — computed at render and snippet time, never stored.

## Rendering (AlloyConfigRenderer)

`LogSourceType::Container` maps to the existing `fileBlock($source, ["/var/log/lina/{$source->id}/*.log"])`. No new Alloy block type — same `local.file_match` + `loki.source.file` the File source emits, labeled with `server_id` + `source` as usual. This is the whole reason the risk surface is unchanged.

## Mount snippet generator

`App\Services\ContainerMountInstructions::for(LogSource $source): array` returns the copy-paste text the UI shows, given the source id + `in_container_path`:

- **compose:** `- /var/log/lina/{id}:{in_container_path}`
- **docker run:** `-v /var/log/lina/{id}:{in_container_path}`
- **Dokploy:** prose — add a Bind mount, host `/var/log/lina/{id}`, container `{in_container_path}`, then redeploy/recreate the service.

Pure function of stored fields; regenerated on the detail page so it's always current.

## Endpoints & UI

- Extend `SaveLogSourceRequest` with the `container` arm: `config.container` required string max 255; `config.in_container_path` required string max 1024 and **must be absolute** (`starts_with:/`). Follows the existing per-type `match` + `$enforceConfig` partial-update pattern.
- `servers/show` source form gains the `container` type: two fields (container name, in-container directory with the `/var/www/html/storage/logs` placeholder).
- The source's detail card renders the **mount instructions** (compose / docker run / Dokploy tabs) plus the "recreate the container after mounting" note.
- **Verification state:** the card shows whether logs have arrived — reuse the existing Loki query proxy scoped to this source's label (`{server_id="X", source="name"}`, last 15m). If none: "No logs yet — apply the mount above and recreate the container." If present: last-received timestamp. No new agent telemetry; it's the same query the Logs page already makes.

## Edge cases

- **Readability:** Docker creates a missing bind-source dir as root `0755` (world-readable), and Laravel writes logs `0644`, so the `alloy` user can read by default. If the app writes restrictive perms (e.g. `0600`), the agent can't read — the detail card's help text notes the dir/files must be readable by others, mirroring the perms lesson from the installer work.
- **Wrong path / not mounted:** the File tail simply matches nothing; the verification state surfaces it as "no logs yet" rather than an error.
- **Shadowing:** bind-mounting an empty host dir over `storage/logs` hides any pre-existing file, but the app recreates its log file immediately — acceptable and expected.

## Tenancy

Unchanged from the File source: a host-path tail labeled `server_id` + `source`, OrgID-isolated (`team-{id}`) on ingest and query. The only new host access is read of one customer-exposed directory. No privilege change.

## Testing

Pest:
- Renderer: `container` source renders a `local.file_match` over `/var/log/lina/{id}/*.log` and a `loki.source.file` forwarding to the receiver.
- `ContainerMountInstructions`: correct compose / docker-run / Dokploy strings for a given id + in-container path.
- Request validation: `container` requires `config.container` and an **absolute** `config.in_container_path` (relative path rejected); partial update preserves stored config when `config` absent.
- Source CRUD: create/edit/delete a `container` source; foreign-server source 404 (existing gate).

Frontend + a live E2E on server B against the real dockerized app on server A (mount `storage/logs`, recreate, confirm logs land and the verification state flips) — as with prior cycles.
