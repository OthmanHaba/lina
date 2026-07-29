# Operations

This runbook covers the production stack created by `install.sh`.

## Files and persistent data

Default installation:

```text
/opt/lina
├── .env.production
├── backups/
├── database/
│   └── database.sqlite
├── storage/
│   ├── app/loki/overrides.yaml
│   ├── framework/
│   └── logs/
├── docker-compose.production.yml
└── docker/
```

Docker named volumes hold:

- `lina_loki-data`
- `lina_caddy-data`
- `lina_caddy-config`

Confirm actual names with:

```bash
docker volume ls --filter label=com.docker.compose.project=lina
```

## Service status

```bash
cd /opt/lina
docker compose -f docker-compose.production.yml ps
```

Expected services:

- `app` healthy
- `queue` running
- `scheduler` running
- `loki` running
- `caddy` running

The public health endpoint is `/up`.

## Logs

Application services:

```bash
docker compose -f docker-compose.production.yml logs -f app queue scheduler
```

Loki:

```bash
docker compose -f docker-compose.production.yml logs -f loki
```

Caddy and certificate issuance:

```bash
docker compose -f docker-compose.production.yml logs -f caddy
```

Limit output during an incident:

```bash
docker compose -f docker-compose.production.yml logs --since 30m --tail 300 app scheduler loki
```

## Upgrade

Run the same installation command:

```bash
curl -fsSL https://raw.githubusercontent.com/OthmanHaba/lina/main/install.sh | sudo bash -s -- --domain logs.example.com
```

The installer builds before stopping application services, backs up SQLite, applies migrations, rewrites Loki overrides, and starts the stack.

If a deployment fails after the application stops:

```bash
cd /opt/lina
docker compose -f docker-compose.production.yml ps
docker compose -f docker-compose.production.yml logs --tail 300 app
docker compose -f docker-compose.production.yml up -d
```

Do not run a destructive Git reset. Resolve local changes or a failed migration deliberately.

## Backups

Lina has two independent data stores. A complete recovery requires both.

### SQLite metadata

Use SQLite's online backup command:

```bash
cd /opt/lina
backup="backups/database-$(date -u +%Y%m%dT%H%M%SZ).sqlite"
sqlite3 database/database.sqlite ".backup '$backup'"
chmod 600 "$backup"
```

Copy the result to encrypted off-host storage. Also back up `.env.production` because the `APP_KEY` is required to decrypt protected values.

### Loki logs

Find the volume:

```bash
docker volume inspect lina_loki-data
```

For a consistent filesystem snapshot, pause ingestion or stop Loki:

```bash
cd /opt/lina
docker compose -f docker-compose.production.yml stop loki
```

Archive the volume with your host or storage provider's snapshot facility, then restart:

```bash
docker compose -f docker-compose.production.yml up -d loki
```

Stopping Loki temporarily makes agent pushes fail. Alloy retries according to its client behavior, but schedule this during a low-volume window.

### Suggested schedule

- SQLite: daily and before every upgrade
- `.env.production`: after any configuration change
- Loki volume: according to recovery point requirements
- Retention: keep at least one backup outside the Lina host
- Restore test: monthly on an isolated host

## Restore SQLite

1. Stop application writers.
2. Preserve the failed database under a new name.
3. Copy the verified backup into place.
4. Restore UID 33 ownership and mode 660.
5. Run migrations.
6. Start the stack.

```bash
cd /opt/lina
docker compose -f docker-compose.production.yml stop app queue scheduler
mv database/database.sqlite database/database.failed.sqlite
cp backups/database-YYYYMMDDTHHMMSSZ.sqlite database/database.sqlite
chown 33:33 database/database.sqlite
chmod 660 database/database.sqlite
docker compose -f docker-compose.production.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.production.yml up -d
```

## Configuration changes

Edit `/opt/lina/.env.production` as root.

Recreate the affected application services:

```bash
cd /opt/lina
docker compose -f docker-compose.production.yml up -d --force-recreate app queue scheduler
```

Caddy address changes also require:

```bash
docker compose -f docker-compose.production.yml up -d --force-recreate caddy
```

Never change `APP_KEY` on an existing installation.

## Email

The installer uses the `log` mailer until an operator configures a provider. Example SMTP fields:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=lina
MAIL_PASSWORD=replace-me
MAIL_FROM_ADDRESS=alerts@example.com
MAIL_FROM_NAME=Lina
```

Recreate app, queue, and scheduler after editing.

Test password reset or an alert channel and inspect logs for errors. Do not send credentials to the browser or store `.env.production` in source control.

## Telegram

Set:

```dotenv
TELEGRAM_BOT_TOKEN=123456:replace-me
```

Recreate the application services, then add a Telegram channel with the destination chat ID.

## Scheduler

The scheduler runs continuously in its own container. It:

- Evaluates alerts every minute without overlap
- Deletes expired team invitations daily

Inspect the process:

```bash
docker compose -f docker-compose.production.yml logs --tail 200 scheduler
```

Run alert evaluation manually:

```bash
docker compose -f docker-compose.production.yml exec app php artisan alerts:evaluate
```

## Queue

The database queue worker restarts after one hour to pick up a clean process and release memory:

```text
queue:work --sleep=1 --tries=3 --timeout=90 --max-time=3600
```

Inspect failed jobs:

```bash
docker compose -f docker-compose.production.yml exec app php artisan queue:failed
```

Retry a known job only after correcting its cause.

## Retention and disk capacity

The Loki compactor removes expired chunks asynchronously. A policy change does not immediately reduce disk use.

Watch the volume:

```bash
docker system df -v
docker volume inspect lina_loki-data
```

When storage grows unexpectedly:

1. Confirm agents are not collecting duplicate sources.
2. Check daily ingest usage on server detail pages.
3. Review team and server retention.
4. Confirm the runtime overrides file exists.
5. Inspect Loki compactor logs.

Rewrite overrides:

```bash
docker compose -f docker-compose.production.yml exec app php artisan loki:write-overrides
```

## Common failures

### Domain does not load

- Confirm DNS points to the host.
- Confirm ports 80 and 443 are open.
- Check whether another host process owns those ports.
- Read Caddy logs.

```bash
ss -lntp | grep -E ':(80|443)\b'
docker compose -f docker-compose.production.yml logs caddy
```

### App is unhealthy

```bash
docker compose -f docker-compose.production.yml logs --tail 300 app
curl -i http://127.0.0.1/up
```

Check storage ownership:

```bash
chown -R 33:33 /opt/lina/database /opt/lina/storage
```

### Logs are unavailable

- Check the Loki container.
- Confirm `LOKI_URL=http://loki:3100`.
- Inspect `/opt/lina/storage/app/loki/overrides.yaml`.
- Verify free disk space.

### Agent is offline

On the observed host:

```bash
systemctl status alloy
systemctl status lina-agent-update.timer
journalctl -u lina-agent-update.service -n 100 --no-pager
journalctl -u alloy -n 100 --no-pager
```

Confirm the host can reach Lina over HTTPS and that the server token has not been regenerated.

### Config is stale

Run the updater manually on the observed host:

```bash
sudo /usr/local/bin/lina-agent-update
```

The command keeps the previous configuration when Lina returns an error.

### Alert does not fire

- Confirm the scheduler container is running.
- Run `alerts:evaluate` manually.
- Check that the rule is enabled.
- Reproduce the filter in the log explorer.
- Confirm its notification channels are enabled.
- Inspect channel failure counts.

## Security maintenance

- Apply host and Docker updates regularly.
- Keep Lina and Loki images current through reviewed upgrades.
- Use HTTPS for production.
- Restrict SSH and require key authentication.
- Keep `.env.production` and backups mode 600.
- Store off-host backups encrypted.
- Review team membership and invitations.
- Rotate a server token immediately if it is exposed.
- Enable two-factor authentication for owner and admin accounts.
