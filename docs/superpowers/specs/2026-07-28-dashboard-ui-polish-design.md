# Dashboard Builder + UI Polish — Milestone 2, Slice 4 Design

**Date:** 2026-07-28
**Status:** Approved
**Scope:** Replace the placeholder team dashboard with a widget-based builder (users compose their own logging dashboard from configurable widgets), then a visual refresh pass bringing all existing pages up to the new visual language.

Out of scope: multiple named dashboards per team, free-form drag-anywhere grid positioning, per-widget sharing/embedding, scheduled dashboard snapshots/exports.

## Decisions made

| Decision | Choice |
|---|---|
| Builder depth | Widget-based builder, one dashboard per team (not full Grafana-style multi-dashboard) |
| Widget config | Filter dropdowns (servers/source/level/search) + optional advanced raw-LogQL mode |
| Layout | Simple responsive grid; widget sizes third/half/full; reorder via drag handle (no free positioning) |
| Widget types v1 | timeseries, stat, log_stream, alert_status, server_health |
| New frontend deps | `recharts` (charts), `@dnd-kit/core` + `@dnd-kit/sortable` (reordering) — approved |
| UI polish | Full visual refresh of existing pages, sequenced after the dashboard (dashboard sets the language) |
| Sequencing | One feature cycle, two phases: Phase 1 dashboard builder, Phase 2 visual refresh |

## Data model

`dashboard_widgets` table:

- `team_id` — FK, cascade delete.
- `type` — string-backed enum `App\Enums\WidgetType`: `Timeseries`, `Stat`, `LogStream`, `AlertStatus`, `ServerHealth`.
- `title` — string, user-set (defaults per type, e.g. "Log volume").
- `size` — string-backed enum `App\Enums\WidgetSize`: `Third`, `Half`, `Full`.
- `position` — unsigned int; ordering within the dashboard (0-based, compacted on reorder).
- `config` — JSON. Common keys (all optional): `server_ids` (int[]), `source` (string), `level` (string), `search` (string). Per-type keys:
  - timeseries: `chart` (`line`|`bar`|`area`, default `bar`), `group_by` (`level`|`server`|`source`|null)
  - stat: `window_minutes` (int, default 60)
  - log_stream: `limit` (int, default 25, max 100)
  - alert_status / server_health: no extra keys
  - timeseries + stat only: `logql` (string, nullable) — advanced mode; when set, it replaces the filter-built query entirely.

One dashboard per team — no `dashboards` table. New teams are seeded with four default widgets (timeseries volume full-width, error stat third, server_health third, log_stream full) inside `CreateTeam`; migration backfills the same defaults for existing teams.

## Backend

All routes live in the existing `{current_team}` group (controllers keep the positional `string $current_team` parameter — repo quirk).

- `GET dashboard` — `DashboardController@index` (replaces placeholder): renders `dashboard` page with `widgets` (id, type, title, size, position, config) and the same server/source option lists the Logs page uses for filter dropdowns.
- `POST dashboard/widgets` / `PATCH dashboard/widgets/{widget}` / `DELETE dashboard/widgets/{widget}` — CRUD. Validation: type/size enums, title max 100, config shape per type (server_ids must belong to the team; level in known set; limit/window bounds; `logql` max 2000 chars, only allowed for timeseries/stat). Widget belongs-to-team enforced by scoped route binding (foreign widget → 404).
- `PATCH dashboard/widgets/reorder` — accepts `ids` (int[], all widgets of the team); rewrites `position` in the given order.
- `GET dashboard/widgets/{widget}/data?range=15m|1h|6h|24h|7d` — the single data endpoint. Per type:
  - **timeseries:** `sum(count_over_time(<selector> [<step>]))` (grouped `by (level|server_id|source)` when `group_by` set) via Loki `query_range`; step chosen so the range yields ~30–60 points. Returns `{series: [{label, points: [[ts, value], …]}]}`.
  - **stat:** instant `sum(count_over_time(<selector> [<window>m]))` now and for the previous window; returns `{value, previous}`.
  - **log_stream:** existing query path (LogQlBuilder selector, newest-first, `limit`); returns the same log-entry shape the Logs page uses.
  - **alert_status:** no Loki call — returns the team's alerts with state, last transition time.
  - **server_health:** no Loki call — returns the team's servers with status()/configStatus().
  - Selector built by the existing `LogQlBuilder` from widget config filters. When `logql` is set (advanced mode), the stored query is wrapped in `query_range`/instant as-is. Tenancy needs no query inspection: every Loki call carries `X-Scope-OrgID: team-{id}` derived from the authenticated team, so raw LogQL cannot cross tenants.
  - Loki failure (ConnectionException, non-200, bad LogQL) → HTTP 200 with `{error: "<friendly message>"}` so the widget renders an inline error, not a broken dashboard. Endpoint sits behind the same rate limiter as the logs query endpoint.

New service `App\Services\WidgetDataService` holds the per-type query building + Loki calls so the controller stays thin and the logic is unit-testable.

## Frontend — Phase 1 (dashboard)

New deps: `recharts`, `@dnd-kit/core`, `@dnd-kit/sortable`.

`pages/dashboard.tsx` rebuilt:

- **Toolbar:** time-range picker (15m / 1h / 6h / 24h / 7d, default 1h), auto-refresh toggle (off / 30s), "Add widget" button. Range + refresh apply to all widgets and live in page state (not persisted).
- **Grid:** CSS grid, 6 columns on desktop; sizes map third→2, half→3, full→6 columns; single column on mobile. Widgets render in `position` order; drag handle reorders via dnd-kit sortable, persisting through the reorder endpoint optimistically.
- **Widget frame:** shared `components/dashboard/widget-frame.tsx` — title, drag handle, kebab menu (edit, resize third/half/full, remove with confirm). Body states: skeleton while loading, inline error state (message + retry) when the data payload has `error`, designed empty state when there's no data.
- **Widget bodies** (`components/dashboard/widgets/*.tsx`): timeseries (recharts line/bar/area with tooltip + level-colored series), stat (big number + % delta vs previous window), log-stream (compact monospace rows w/ level badges, links to Logs page with filters applied), alert-status (list with firing/ok badges), server-health (tiles with status dots).
- **Data fetching:** each widget fetches its data endpoint via Inertia v3 `useHttp` on mount, on range change, and on the refresh interval; requests are independent so one slow widget never blocks the rest.
- **Add/edit dialog:** type picker (icon cards) → title, size, filter fields (multi-server select, source, level, search — same vocabulary/options as the Logs page) → for timeseries/stat an "Advanced" collapsible with a LogQL textarea (filling it disables the filter fields). Uses the Wayfinder `<Form>` pattern like existing dialogs.
- Empty dashboard (all widgets deleted) shows a designed empty state with an "Add widget" CTA.

## Frontend — Phase 2 (visual refresh)

Purely presentational — no route, endpoint, or behavior changes; the existing suite must stay green.

- **Theme:** refine Tailwind v4 tokens in `app.css` — one accent color used consistently, tightened neutral scale, consistent radii/shadows; dark mode kept in sync.
- **Page headers:** one consistent header pattern (title, description, primary action right-aligned) applied to logs, servers, alerts, teams pages.
- **Empty states:** designed empty state (icon, one line, CTA) for every empty table/list: servers index, alerts index, channels, log results, team members.
- **Logs viewer:** level color badges, denser monospace rows, sticky filter bar, subtle row hover; visual only — search/live-tail behavior untouched.
- **Servers:** index cards + show page aligned to dashboard card styles; hand-rolled 14-day ingest chart replaced with the shared recharts component.
- **Alerts/teams:** cards, badges, and forms aligned to the same patterns.

## Testing

Pest feature tests:

- Widget CRUD: create each type with valid config; invalid type/size/config rejected (bad level, out-of-range limit/window, `logql` on a log_stream widget rejected, server_ids from another team rejected); foreign-team widget 404 on update/delete/data; reorder persists positions and rejects a partial id list.
- Data endpoint: per-type LogQL assertions via `Http::fake` (selector built from filters, group_by clause, stat window, advanced `logql` passed through verbatim); Loki connection failure → 200 with `error` key; alert_status/server_health return DB-backed payloads with no Loki call; invalid `range` rejected.
- Seeding: new team gets the four default widgets; migration backfill covered by asserting an existing team gains them.
- `WidgetDataService` unit tests for step selection and query composition.

Frontend verified by `npm run build` + the live E2E click-through on server B (create/edit/reorder/delete widgets, range switching, advanced LogQL, error states with Loki stopped).

## Deployment (server B)

Standard deploy flow (bundle → migrate → prebuilt assets → config:cache). Migration backfills default widgets for the existing team; verify dashboard renders with live data from server A's log flow, then run the Phase 2 click-through of every page.
