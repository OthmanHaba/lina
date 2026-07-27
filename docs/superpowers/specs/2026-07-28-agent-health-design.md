# Agent Health — Milestone 2, Slice 2 Design

**Date:** 2026-07-28
**Status:** Approved
**Scope:** Per-server agent health on the existing server detail page: poll/push recency, config-drift detection, 14-day ingest chart.

Out of scope: dedicated fleet health page, online/offline transition history, health-based alerting (missing-logs alerts already cover real-time detection).

## Decisions made

| Decision | Choice |
|---|---|
| Placement | "Agent health" section on `servers/show`; no new nav item or page |
| History | Snapshot values + 14-day daily ingest chart from existing `usage_records`; no transition timeline |
| Storage | Four nullable columns on `servers`; no new tables, no background jobs |
| Chart | Pure CSS flexbox bars; no chart library |

## Data model

`servers` gains: `last_poll_at` (timestamp, nullable), `last_push_at` (timestamp, nullable), `config_synced_at` (timestamp, nullable), `synced_config_hash` (string 40, nullable).

`last_seen_at` and the derived pending/online/offline status are unchanged.

## Recording

- **Config poll** (`AgentConfigController`): bump `last_poll_at`, write-throttled to once per 30s (same pattern as `last_seen_at` in `AuthenticateAgent`). When responding `304` (the agent's `If-None-Match` matches the current ETag — proof it holds the current config), also set `synced_config_hash` to the current config's sha1 and `config_synced_at = now()`. A `200` response records nothing extra; the follow-up poll's 304 confirms the apply within ~60s.
- **Ingest** (`IngestController`): bump `last_push_at`, write-throttled to once per 30s.

## Derivation

`Server::configStatus(): string` returns:
- `synced` — `synced_config_hash` equals sha1 of the currently rendered config
- `syncing` — hashes differ (or no synced hash) AND `last_poll_at` within the last 5 minutes
- `unknown` — otherwise (agent silent or never polled)

Note: `configStatus()` renders the current Alloy config to compare hashes; this runs on the server detail page only (one render per page view — acceptable).

## Page props

`ServerController::show` adds:

```
health: {
  lastPollAt: string|null,
  lastPushAt: string|null,
  configStatus: 'synced'|'syncing'|'unknown',
  configSyncedAt: string|null,
  usage: [{date: 'YYYY-MM-DD', bytes: int}]  // exactly 14 entries, oldest first, zero-filled
}
```

## UI

One "Agent health" card section on `servers/show` between the meta tiles and log sources:
- Three stat tiles: **Last config poll** (relative time or "Never"), **Last log push** (relative or "Never"), **Config** (colored dot: green `synced` / amber `syncing` / gray `unknown`, with `configSyncedAt` relative time under it when synced).
- **Ingest, last 14 days**: flexbox bar chart, bar height proportional to the max day's bytes, day label on hover (title attr), human-readable byte totals.

## Testing

Pest feature tests:
- Poll bumps `last_poll_at` (and not `last_push_at`); push bumps `last_push_at` (and not `last_poll_at`); both respect the 30s throttle.
- A 304 poll records `synced_config_hash` + `config_synced_at`; a 200 poll does not.
- `configStatus()`: `synced` when hashes match; flips to `syncing` after a log-source change (rendered hash moves) while polls are recent; `unknown` when never polled or polls stale.
- Usage series: exactly 14 entries, zero-filled for missing days, oldest first.
- `servers/show` renders the `health` prop with the expected shape.
