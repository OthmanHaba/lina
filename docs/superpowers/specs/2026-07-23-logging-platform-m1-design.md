# Logging Platform — Milestone 1 Design

**Date:** 2026-07-23
**Status:** Approved
**Scope:** Server onboarding, agent install, ingestion gateway, log search/live-tail, log source configuration.

## Product summary

Managed logging-as-a-service built on the existing Laravel 13 + Inertia React app. Users connect Linux servers via a one-line install command that sets up Grafana Alloy, choose which logs to collect (systemd, Docker, Nginx, files) in the dashboard, and search/live-tail those logs — without running their own Loki/Grafana.

Out of scope for M1 (later milestones): alerts, retention policies, redaction, billing UI, MinIO/object storage.

## Decisions made

| Decision | Choice |
|---|---|
| Deployment | Single VPS the operator controls; Loki runs in Docker beside the Laravel app |
| Tenancy | Existing `Team` model is the tenant; Loki OrgID = `team-{id}` |
| Isolation strategy | Header-based: gateway never parses payloads, sets `X-Scope-OrgID` from the authenticated token. Loki's native multi-tenancy enforces isolation at storage |
| Config delivery | Laravel renders the full Alloy config; a bash updater script on the server polls it via systemd timer (60s). No Grafana remotecfg protocol |
| Loki storage | Local filesystem volume for now; swap to object storage later via Loki config only |
| Hardening level | Correct tenant isolation + per-server auth from day one; TLS assumed; polish/abuse-protection deferred |

## Architecture

```
User's server                    Operator VPS
┌─────────────────┐   HTTPS   ┌──────────────────────────────┐
│ Alloy (agent)   │──push────▶│ Laravel                      │
│ config-updater  │──poll────▶│  /api/ingest  ──▶ Loki:3100  │
│ (systemd timer) │           │  /api/agent/config  (Docker) │
└─────────────────┘           │  Inertia UI ──query──▶ Loki  │
                              └──────────────────────────────┘
```

## Data model

- **`servers`** — `team_id`, `name`, `token` (SHA-256 hash), `hostname`, `agent_version`, `last_seen_at`, timestamps. Status (online/offline/pending) is **derived**: pending until first poll, offline when `last_seen_at` > 5 min. No status column, no cron.
- **`log_sources`** — `server_id`, `type` (enum: `systemd`, `docker`, `nginx`, `file`), `config` (JSON: unit names / container filters / file paths), `enabled`.
- **`usage_records`** — `server_id`, `date`, `bytes`. Upserted (`bytes += content-length`) on each ingest request.

Loki labels, baked into generated config: `server` (server id), `source` (log_source type/name). Users cannot set labels.

## Flows

### Onboarding & install
1. "Add server" → creates `servers` row, generates 40-char random token, shows it once with the install command.
2. `curl -sSL https://app/servers/install/{token} | sudo bash` — the endpoint serves a bash script with the token embedded. Script: detects apt/rpm, installs Alloy from Grafana's official repo, writes token to `/etc/alloy/token`, installs `alloy-config-update.sh` + systemd service/timer (60s), runs the updater once, enables + starts Alloy. Idempotent and re-runnable; fails loudly on unsupported distro / missing systemd.
3. First successful config poll records `hostname` + `agent_version` (from headers) and sets `last_seen_at` → server shows **online**.

### Config poll
- `GET /api/agent/config`, `Authorization: Bearer {token}`.
- Response: complete rendered `config.alloy`. `ETag` = hash of rendered config; `304` when unchanged.
- Updater writes the file only on change, then reloads Alloy. Keeps last good config if a poll fails.
- Config is rendered from PHP templates, one block per enabled `log_source`, plus a `loki.write` block pointing at `/api/ingest` with the bearer token.

### Ingestion
- Alloy `loki.write` → `POST /api/ingest` (body: snappy/protobuf Loki push — never parsed).
- Middleware/controller: bearer token → hashed lookup → server + team; per-server rate limit (Laravel `RateLimiter`); usage upsert; bump `last_seen_at`; forward raw body + content headers to `loki:3100/loki/api/v1/push` with `X-Scope-OrgID: team-{id}`.
- Responses: 401 bad/revoked token, 429 rate-limited, 502/503 when Loki is down. All of these are retry-safe: Alloy buffers and backs off, so no loss within its buffer window.

### Search & live tail
- Laravel controller proxies Loki `query_range` / `labels` / `label values`, always injecting the current team's OrgID. Users never write raw LogQL in v1; the controller builds the selector from validated filters: servers (multi-select), sources, text contains (`|=` / `!=`), time range.
- React page: filter bar, virtualized result list, expandable rows with pretty-printed JSON for structured lines.
- Live tail: poll `query_range` every 2s from the last-seen timestamp. No websockets.

### Source configuration
- Per-server page listing sources with enable/disable and type-specific fields:
  - **systemd**: optional unit filter list (default: whole journal)
  - **docker**: optional container name filters (default: all containers)
  - **nginx**: access/error log paths (sensible defaults `/var/log/nginx/*.log`)
  - **file**: arbitrary glob paths
- Saving bumps the rendered-config hash; the agent converges within ~60s.

## Server revocation / token rotation
- Regenerate token per server (old hash replaced; agent goes 401 → offline until reinstalled/updated).
- Deleting a server cascades `log_sources` and `usage_records`; its Loki data ages out with team retention (retention management is a later milestone).

## Infrastructure
- `docker-compose.yml` (or additions to an existing one) running `grafana/loki` with multi-tenancy enabled (`auth_enabled: true`), filesystem storage in a named volume. Loki is bound to localhost/internal network only — never publicly exposed; Laravel is the only client.
- Loki base URL in `config/services.php` via env.

## Error handling summary

| Failure | Behaviour |
|---|---|
| App or Loki unreachable | Alloy retries with backoff, buffers; updater keeps last good config |
| Bad/revoked token | 401; UI shows offline after 5 min |
| Rate limit exceeded | 429; Alloy backs off and retries (loss-safe) |
| Unsupported distro / no systemd | Install script exits with clear message |

## Testing

Pest feature tests, Loki faked with `Http::fake()`:
- Ingest: auth (valid/invalid/revoked token), tenant isolation (a token can only produce its own team's OrgID — assert forwarded header), rate limiting, usage metering, `last_seen_at` bump, raw body passthrough.
- Config: rendered output per source type and combination (snapshot-style assertions), ETag/304 behaviour, agent metadata capture.
- Query proxy: team scoping enforced, filter → LogQL selector building, input validation.
- Onboarding: server CRUD + policies (team membership), token shown once, install script endpoint serves script with token, token regeneration.

Real end-to-end (VPS + real Alloy + real Loki) is a manual checklist, not automated.
