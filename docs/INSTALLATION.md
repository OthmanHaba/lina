# Installation

This guide installs the complete Lina stack on one Linux host. The installer is designed for a small self-hosted deployment and can be run again to upgrade the same installation.

## Host requirements

Use a dedicated 64-bit host with:

- Ubuntu, Debian, Fedora, Rocky Linux, AlmaLinux, or RHEL
- systemd
- A root shell or passwordless `sudo`
- A public IPv4 or IPv6 address
- TCP ports 80 and 443 available
- Outbound access to GitHub, Docker Hub, Grafana images, and the Docker installer
- At least 2 CPU cores, 4 GB RAM, and 40 GB disk for a starting deployment

Storage requirements depend on log volume and retention. Watch the Loki volume and increase disk capacity before it approaches exhaustion.

## Domain and DNS

For automatic HTTPS, create an A or AAAA record before installation:

```text
logs.example.com -> your Lina host
```

Caddy must be reachable from the public internet on ports 80 and 443 to obtain the first certificate. A proxied DNS provider can interfere with the initial challenge, so use DNS-only mode until the certificate is issued when necessary.

## One-command installation

```bash
curl -fsSL https://raw.githubusercontent.com/OthmanHaba/lina/main/install.sh | sudo bash -s -- --domain logs.example.com
```

The installer:

1. Installs `curl`, Git, OpenSSL, SQLite tools, and Docker when missing.
2. Clones `https://github.com/OthmanHaba/lina.git` into `/opt/lina`.
3. Creates `/opt/lina/.env.production` with a random Laravel application key.
4. Prepares persistent SQLite and Laravel storage with container-safe permissions.
5. Builds the PHP application and frontend assets.
6. Runs all database migrations.
7. Writes the initial Loki retention override file.
8. Starts the web app, queue, scheduler, Loki, and Caddy.
9. Waits for `/up` and prints the registration URL.

## IP-only installation

Use IP mode when DNS is unavailable:

```bash
curl -fsSL https://raw.githubusercontent.com/OthmanHaba/lina/main/install.sh | sudo bash -s -- --ip
```

The installer detects the public IPv4 address and serves Lina over plain HTTP on port 80. This mode sets non-secure session cookies and should only be used for evaluation or a protected private network.

## Installer options

Download the help without installing:

```bash
curl -fsSL https://raw.githubusercontent.com/OthmanHaba/lina/main/install.sh | bash -s -- --help
```

| Option | Meaning |
| --- | --- |
| `--domain HOST` | Serve the host with automatic HTTPS |
| `--ip` | Serve HTTP on the detected public IP |
| `--branch NAME` | Deploy a branch or tag instead of `main` |
| `--repository URL` | Clone a fork or private mirror |
| `--directory PATH` | Install somewhere other than `/opt/lina` |

Example for a fork:

```bash
curl -fsSL https://raw.githubusercontent.com/OthmanHaba/lina/main/install.sh \
  | sudo bash -s -- \
      --domain logs.example.com \
      --repository https://github.com/example/lina.git \
      --branch stable
```

## First account

Open the URL printed by the installer and choose **Register**. Registration creates a personal team and makes the user its owner.

If email verification is enabled but the production mailer is still `log`, verification messages are written to the application log:

```bash
cd /opt/lina
docker compose -f docker-compose.production.yml logs app
```

Configure a real mail transport in `.env.production` before inviting a team.

## Production environment

The generated `/opt/lina/.env.production` includes:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/database/database.sqlite
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
LOKI_URL=http://loki:3100
LOKI_OVERRIDES_PATH=/var/www/html/storage/app/loki/overrides.yaml
```

Keep this file readable only by root. Do not commit or transmit its `APP_KEY`.

## Upgrading

Run the same install command again:

```bash
curl -fsSL https://raw.githubusercontent.com/OthmanHaba/lina/main/install.sh | sudo bash -s -- --domain logs.example.com
```

On an existing installation, the script:

- Refuses to overwrite local Git changes
- Keeps `.env.production` and the current `APP_KEY`
- Updates the public URL and Caddy address when the mode changes
- Pulls the configured branch with a fast-forward-only update
- Builds a new image before stopping the running application
- Creates `backups/database-<UTC timestamp>.sqlite`
- Stops the app, worker, and scheduler for the migration window
- Runs migrations and rewrites Loki overrides
- Starts the complete stack and performs a health check

Review release notes and take an off-host backup before a major upgrade.

## Uninstalling

Uninstallation is intentionally not automated because it destroys stored logs and metadata. To retire a host safely:

1. Put Lina in maintenance mode.
2. Export or archive SQLite and the Loki volume.
3. Stop the Compose stack.
4. Verify the backup on another system.
5. Remove the containers and volumes only after the retention requirement has expired.

See [Operations](OPERATIONS.md) for backup details.
