# Retention Policies — Milestone 2, Slice 3 Design

**Date:** 2026-07-28
**Status:** Approved
**Scope:** Per-team log retention with per-server overrides, enforced by Loki via a runtime overrides file the app owns; settings UI on the team edit page and server detail page.

Out of scope: per-source retention, retention-based billing/quotas, surfacing storage usage per retention tier.

## Decisions made

| Decision | Choice |
|---|---|
| Retention values | Free-form integer days, validated 1–365 |
| Granularity | Team-wide default + optional per-server override |
| Enforcement | Loki runtime overrides file (`runtime_config`), compactor enforces; no delete-API scheduling |
| YAML rendering | Hand-rendered (structure is tiny and fully app-controlled); no YAML dependency |
| Defaults | Team: 30 days (column default). Server: null = inherit team |

## Data model

- `teams.retention_days` — unsigned small int, default 30.
- `servers.retention_days` — unsigned small int, nullable (null = inherit team).

## Overrides writer

`App\Services\LokiOverridesWriter::write(): void`
- Renders the complete overrides YAML for ALL teams (including soft-deleted teams' exclusion — only non-deleted teams appear):

```yaml
overrides:
  team-1:
    retention_period: 90d
    retention_stream:
      - selector: '{server_id="3"}'
        priority: 1
        period: 7d
  team-2:
    retention_period: 30d
```

- Every team gets a `retention_period` entry (even default 30d — explicit beats implicit here; the file is the single source of truth).
- `retention_stream` entries only for servers whose `retention_days` is non-null; deleted servers naturally drop out on rewrite.
- Written atomically: render → write temp file in the same directory → `rename()`.
- Target path from `config('services.loki.overrides_path')`, default `storage_path('app/loki/overrides.yaml')`; directory created on demand.

**Triggers:** after team retention update, server retention update, server deletion, and team deletion. Plus `php artisan loki:write-overrides` for bootstrap/repair (also creates the file so the Loki container has something to mount).

Failure mode: if the write fails (permissions/disk), log an error; the request still succeeds (retention change is stored in DB; the artisan command can repair the file). Loki keeps using the previous file — stale retention, never broken ingestion.

## Infra

- `docker/loki/loki-config.yaml`: add `runtime_config: {file: /etc/loki/runtime/overrides.yaml}`.
- `docker-compose.yml`: mount the directory `./storage/app/loki:/etc/loki/runtime:ro` (a single-file bind mount would pin the pre-rename inode and never see atomic updates).
- Loki polls runtime config periodically (default 10s); the compactor applies per-tenant/per-stream retention on its next cycle. Note: compactor deletion is not instant — retention changes take effect within Loki's compaction interval, not seconds.

## Endpoints & UI

- **Team retention:** extend the existing team update flow (`teams.update` / `SaveTeamRequest`) with `retention_days` (required int 1–365). Field on `teams/edit` next to the team name, visible to all, editable per the existing update gate (owner/admin — same `Gate::authorize('update', $team)` already in place). Helper copy: "How long logs are kept. Lowering this permanently deletes logs older than the new value."
- **Server retention:** `servers.update` route (PATCH `servers/{server}`) — new; accepts `retention_days` nullable int 1–365 (null = team default). UI on `servers/show`: a small "Retention" card with a select ("Team default (N days)") plus a days input when overriding.
- Props: `teams/edit` gains `team.retentionDays`; `servers/show` server prop gains `retentionDays` (int|null) and `teamRetentionDays` (int).

## Tenancy

Overrides are keyed by `team-{id}` — the same OrgID the ingest/query paths derive from auth; a team's retention can never affect another tenant's data. Server selectors live inside their own tenant's block only.

## Testing

Pest feature tests:
- Writer: default-only team renders `retention_period: 30d` with no `retention_stream`; team override renders its days; server override renders a `retention_stream` entry with the right selector/period; multiple teams render sorted deterministically; deleted server disappears after rewrite; file written atomically (final file exists, no temp leftover).
- Team update: `retention_days` validated (0 and 366 rejected), persists, triggers a rewrite (assert file content), member without update rights gets 403 (existing gate).
- Server update: nullable override persists, null resets to inherit, triggers rewrite; foreign server 404.
- Server deletion triggers rewrite (its stream entry gone).
- `loki:write-overrides` command writes the file and is idempotent.

## Deployment (server B)

- Deploy + migrate; run `php artisan loki:write-overrides`; add the volume mount to compose and `docker compose up -d` (recreates Loki); verify `curl -s -H 'X-Scope-OrgID: team-1' localhost:3100/loki/api/v1/query` still works and the container sees the file.
