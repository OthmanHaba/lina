# Alerts & Notifications — Milestone 2, Slice 1 Design

**Date:** 2026-07-26
**Status:** Approved
**Scope:** Alert rules (threshold + missing-logs), state-transition notifications (email, webhook, Telegram), alert management UI, notification channel management.

Out of scope for this slice (later M2 slices): dedicated agent-health page, retention policies, redaction, usage/billing UI. Anomaly/baseline alerting is out of M2 entirely.

## Decisions made

| Decision | Choice |
|---|---|
| Condition types | `threshold` (> N matching lines in M minutes) and `missing_logs` (zero lines in M minutes) |
| Notification channels | Email (SMTP), generic webhook (JSON POST), Telegram (single platform bot; user supplies chat ID) |
| Notification semantics | State transitions only: one notification entering `firing`, one on `recovered`. No repeats while firing |
| Evaluation engine | Laravel scheduler polling every minute; no Loki ruler/Alertmanager |
| Query building | Reuse `App\Services\LogQlBuilder`; evaluation queries always carry the team's `X-Scope-OrgID` |

## Data model

- **`notification_channels`** — `team_id`, `type` (enum: `email`, `webhook`, `telegram`), `name`, `config` JSON, `enabled` bool, `failure_count` int default 0. Config shapes: email `{address}`, webhook `{url}`, telegram `{chat_id}`.
- **`alerts`** — `team_id`, `name`, `type` (enum: `threshold`, `missing_logs`), `config` JSON, `state` (enum: `ok`, `firing`, default `ok`), `enabled` bool, `last_evaluated_at` nullable, `last_state_change_at` nullable. Config shape: `{servers: int[], sources: string[], search: string|null, threshold: int|null, window_minutes: int}` — `threshold` required for threshold type, ignored for missing_logs; `servers`/`sources` empty = all; `search` only meaningful for threshold type.
- **`alert_channel`** — pivot `alert_id`, `notification_channel_id`.
- **`alert_events`** — `alert_id`, `state` (the state entered), `measured_value` int, `created_at`. Feeds the per-alert timeline; no updates, append-only.

Deleting a channel detaches it from alerts. Deleting an alert cascades its events and pivot rows. Server deletion does not touch alerts (their `servers` filter just stops matching; a missing-logs alert on a deleted server will fire — acceptable, visible, and user-fixable).

## Evaluation

`php artisan alerts:evaluate`, scheduled `everyMinute()` (requires the standard Laravel cron entry on the host — deployment note).

Per enabled alert:
1. Build the LogQL selector with `LogQlBuilder` from the alert's config, intersecting configured server IDs with the team's current servers (same double-enforcement as the log explorer).
2. Query Loki's instant `query` endpoint with `sum(count_over_time(<selector> [<window>m]))`, `X-Scope-OrgID: team-{id}`. A missing/empty result vector counts as 0.
3. Compute breached: threshold → `count > threshold`; missing_logs → `count == 0`.
4. If breached and state is `ok` → set `firing`, record event, notify. If not breached and state is `firing` → set `ok`, record `recovered` event, notify. Otherwise just bump `last_evaluated_at`.
5. Loki unreachable/erroring → skip the alert this round (no state change, no notification); log a warning. Evaluation must never crash the scheduler run; per-alert failures are isolated.

Teams with no servers: missing_logs alerts do not fire (skip evaluation when the alert's effective server set is empty).

## Notifications

One Laravel notification (`AlertStateChanged`) rendered per channel type:
- **Email:** subject `[Lina] {alert name} is {firing|recovered}`; body: alert name, state, measured value vs threshold, window, servers/sources, deep link to the Logs page pre-filtered to the alert's filters.
- **Webhook:** `POST {url}` with JSON `{alert: {id, name, type}, state, measured_value, threshold, window_minutes, servers, sources, search, fired_at, url}` — payload shape is stable API, documented in the channel UI.
- **Telegram:** platform bot token from `config('services.telegram.bot_token')` (env `TELEGRAM_BOT_TOKEN`); `sendMessage` to the channel's `chat_id` with an HTML-formatted message mirroring the email content. Users obtain their chat ID by messaging the bot (instructions shown in the channel form).

Delivery failures increment the channel's `failure_count` (reset on success). At 10 consecutive failures the channel is auto-disabled (`enabled=false`) and the UI shows a "disabled after repeated failures" badge. Failures never abort evaluation or other channels. Delivery is synchronous within the evaluation run for v1 (each send wrapped in its own try/catch with a short HTTP timeout) — no queue worker dependency; move to a queued driver when notification volume justifies it.

## UI

- **Alerts page** (new sidebar item, team-scoped like Servers/Logs):
  - List: name, type, enabled toggle, state badge (`ok` green / `firing` red), last state change.
  - Create/edit dialog: name; type picker; filters identical in look to the log explorer (server multi-select, source select, search text — search hidden for missing_logs); threshold + window inputs (window only, for missing_logs); channel checkboxes from the team's channels.
  - Alert detail/expanded row: recent `alert_events` timeline (state, measured value, time).
- **Notification channels** — section on the team settings page (or its own settings tab, matching existing settings layout): list channels with type icon + failure badge, add/edit/delete dialogs per type with type-specific fields and inline setup help (Telegram chat-ID instructions, webhook payload doc).

## Tenancy & authorization

Alerts and channels belong to teams; all routes live under the `{current_team}` prefix with the same guard pattern as servers (`abort_unless(... ->team_id === current_team_id, 404)`). Evaluation queries derive OrgID exclusively from the alert's `team_id`. Channel configs are visible only to their team.

## Testing

Pest feature tests:
- Evaluation: threshold breach → firing + event + notification; recovery → ok + event + notification; steady-state (still firing / still ok) → no notification; missing_logs zero-count fires; Loki failure skips without state change; empty-server-set missing_logs skipped. Loki faked with `Http::fake`, notifications with `Notification::fake`, time with `Carbon::setTestNow` where needed.
- Tenancy: evaluation sends the alert team's OrgID; alert/channel CRUD unreachable across teams (404); server-id intersection strips foreign IDs.
- Channels: CRUD + validation per type; failure_count increment/reset; auto-disable at 10.
- Notifications: per-type payload/formatting assertions (webhook JSON shape is the documented contract).
- Scheduling: `alerts:evaluate` is registered on the every-minute schedule.

## Deployment notes (server B, applied when this ships)

- Cron entry: `* * * * * cd /var/www/lina && php artisan schedule:run >> /dev/null 2>&1`
- `TELEGRAM_BOT_TOKEN` + SMTP mail env vars
