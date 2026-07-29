# User guide

This guide follows the normal operator workflow from the first account through search, dashboards, alerts, and team administration.

## Core concepts

### Team

A team is Lina's tenant. Users, servers, dashboards, alerts, channels, and retention settings are scoped to a team. The same team ID becomes the Loki organization ID, so the collaboration boundary and log storage boundary remain aligned.

### Server

A server represents one Linux host and owns one agent token. The token is stored as a SHA-256 hash in Lina and shown in plaintext only when created or regenerated.

### Log source

A log source tells Grafana Alloy what to collect and which stable `source` label to add. Sources belong to one server and can be enabled or disabled without reinstalling the agent.

### Alert

An alert applies a count condition to a server and source scope. It changes between `ok` and `firing`, records each transition, and notifies attached channels only when state changes.

## Teams and membership

Registration creates a personal team. Users can create additional teams from **Settings > Teams** when different applications or customers require separate members and retention.

Roles:

| Role | Capabilities |
| --- | --- |
| Owner | Full team, member, invitation, and deletion control |
| Admin | Update the team and manage invitations |
| Member | Use the team workspace without team administration |

Invite a member:

1. Open **Settings > Teams**.
2. Select the team.
3. Choose **Invite member**.
4. Enter the email and role.
5. Send the invitation.

Invitations expire and are pruned by the scheduler. A recipient can accept an invitation during registration or from a signed-in account that uses the same email address.

## Connect a server

1. Switch to the correct team.
2. Open **Servers**.
3. Choose **Add server** and give the host a recognizable name.
4. Copy the one-time command.
5. Run it as root on the target Linux host.

The agent installer supports `apt`, `dnf`, and `yum` distributions with systemd. It installs Grafana Alloy and a `lina-agent-update.timer` that polls Lina every 60 seconds.

The server appears online after it has polled configuration and pushed a log batch. The detail page separates:

- Last agent poll
- Last log push
- Last successful config sync
- Agent version and hostname
- Daily ingest usage

## Configure log sources

### systemd

Use systemd for journal entries. Leave the unit list empty to collect the available journal, or list units such as:

```text
nginx.service
php8.4-fpm.service
my-worker.service
```

The Alloy service account is added to `systemd-journal` during installation.

### Docker

Use Docker for container stdout and stderr. Lina discovers the local Docker daemon and can keep only selected container names.

The installer adds Alloy to the `docker` group when `/var/run/docker.sock` exists. Restart Alloy after changing group membership outside the installer.

### Nginx

Use Nginx for access and error files. Defaults are:

```text
/var/log/nginx/access.log
/var/log/nginx/error.log
```

Override either path when the distribution or virtual host uses a different location.

### File

Use File for host-visible application logs. Add explicit paths or supported glob patterns. Make sure the `alloy` user can traverse the parent directories and read matching files.

### Container file

Use Container file when an application writes logs inside its filesystem instead of stdout. Lina gives the source a stable host directory under:

```text
/var/log/lina/<source-id>
```

Bind-mount that host directory into the application container at its log output path. The source detail panel generates Docker Compose and `docker run` examples and can check whether Lina receives matching lines.

## Search logs

Open **Logs** and build the narrowest useful query:

1. Choose a time range.
2. Select one or more servers. Empty means all team servers.
3. Select a source when the incident is source-specific.
4. Enter a stable message fragment such as an exception class or request ID.

Lina builds a LogQL stream selector from the authorized server set and configured sources. It queries newest first, merges streams by nanosecond timestamp, and returns up to 500 entries.

The browser never calls Loki directly.

## Build the dashboard

Every team has one dashboard. New teams receive starter widgets that can be edited, resized, reordered, or removed.

Widget types:

- **Timeseries** for log volume over a selected range
- **Stat** for a current count and comparison with the previous window
- **Log stream** for recent matching lines
- **Alert status** for current alert states and last transitions
- **Server health** for agent and configuration status

Common widget filters:

- Server IDs
- Source names
- Message search text
- Time range selected in the dashboard toolbar

Timeseries and stat widgets can use raw LogQL in advanced mode. Lina still sets the team organization header on every request, so raw LogQL does not bypass tenancy.

Widgets load independently. Use the retry action on one failed widget without interrupting the rest of the dashboard.

## Configure notifications

Open **Alerts** and add one or more channels.

### Email

Provide the destination address. Delivery uses the host's Laravel mail configuration.

### Webhook

Provide an HTTPS URL. Lina sends a JSON state-change payload containing:

```json
{
  "alert": {
    "id": 12,
    "name": "High error rate",
    "type": "threshold"
  },
  "state": "firing",
  "measured_value": 28,
  "threshold": 10,
  "window_minutes": 5,
  "servers": [3],
  "sources": ["nginx"],
  "search": "ERROR",
  "fired_at": "2026-07-29T10:00:00Z",
  "url": "https://logs.example.com/team/logs?..."
}
```

Validate a webhook signature at the receiver only after adding signing support to Lina. The current payload does not include an HMAC signature, so restrict the endpoint with an unguessable path or network control.

### Telegram

Set `TELEGRAM_BOT_TOKEN` on the Lina host, then configure the target chat ID in the channel.

After editing `.env.production`, recreate the application containers:

```bash
cd /opt/lina
docker compose -f docker-compose.production.yml up -d --force-recreate app queue scheduler
```

## Create alerts

### Threshold alert

Use a threshold alert when the number of matching lines becomes too high.

Example:

- Servers: API nodes
- Source: `api`
- Search: `TimeoutException`
- Threshold: `10`
- Window: `5 minutes`

The rule fires when the count is greater than 10.

### Missing logs alert

Use missing logs for a process that should emit regularly.

Example:

- Server: billing worker
- Source: `billing`
- Window: `15 minutes`

The rule fires when the count is zero.

Rules evaluate once per minute. A steady firing rule does not send repeated notifications. A recovery creates a new event and sends another notification.

## Retention

The base Loki configuration retains data for 31 days. Each team can choose a policy from 1 to 365 days. A server can inherit the team value or use its own override.

Changing retention updates an atomically written Loki runtime configuration. Expired chunks are removed by Loki's compactor, so disk usage may not decrease immediately.

## Security settings

From **Settings > Security**, users can:

- Change their password after password confirmation
- Enable TOTP two-factor authentication
- Scan the QR code with an authenticator
- Confirm the one-time code
- Save recovery codes offline
- Regenerate recovery codes
- Disable two-factor authentication

Treat recovery codes like passwords. Store them outside Lina and outside the host running Lina.

## Command palette

Press `Command or Ctrl + K` from the authenticated interface to:

- Navigate to dashboards, servers, logs, alerts, and settings
- Search commands by label and keywords
- Move through results with the keyboard

The palette respects the current team when it builds team-scoped routes.
