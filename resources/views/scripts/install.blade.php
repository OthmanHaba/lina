set -euo pipefail

TOKEN="{!! $token !!}"
BASE_URL="{!! $baseUrl !!}"

if [ "$(id -u)" -ne 0 ]; then
    echo "This installer must run as root: curl -sSL $BASE_URL/install/<token> | sudo bash" >&2
    exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
    echo "systemd is required. This installer supports systemd-based Linux distributions only." >&2
    exit 1
fi

echo "==> Installing Grafana Alloy"
if command -v apt-get >/dev/null 2>&1; then
    ENV_FILE=/etc/default/alloy
    apt-get install -y gpg curl >/dev/null
    mkdir -p /etc/apt/keyrings
    curl -fsSL https://apt.grafana.com/gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/grafana.gpg
    echo "deb [signed-by=/etc/apt/keyrings/grafana.gpg] https://apt.grafana.com stable main" > /etc/apt/sources.list.d/grafana.list
    apt-get update -qq
    apt-get install -y alloy
elif command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
    ENV_FILE=/etc/sysconfig/alloy
    cat > /etc/yum.repos.d/grafana.repo <<'REPO'
[grafana]
name=grafana
baseurl=https://rpm.grafana.com
repo_gpgcheck=1
enabled=1
gpgcheck=1
gpgkey=https://rpm.grafana.com/gpg.key
REPO
    (command -v dnf >/dev/null 2>&1 && dnf install -y alloy) || yum install -y alloy
else
    echo "Unsupported distribution: need apt-get, dnf, or yum." >&2
    exit 1
fi

echo "==> Configuring agent credentials"
touch "$ENV_FILE"
sed -i '/^LINA_TOKEN=/d;/^LINA_BASE_URL=/d' "$ENV_FILE"
echo "LINA_TOKEN={!! $token !!}" >> "$ENV_FILE"
echo "LINA_BASE_URL={!! $baseUrl !!}" >> "$ENV_FILE"
chmod 600 "$ENV_FILE"

echo "==> Installing config updater"
cat > /usr/local/bin/lina-agent-update <<UPDATER
#!/usr/bin/env bash
set -euo pipefail
set -a; source $ENV_FILE; set +a
ETAG_FILE=/etc/alloy/.config-etag
HEADERS=\$(mktemp)
BODY=\$(mktemp)
STATUS=\$(curl -sS -w '%{http_code}' -o "\$BODY" -D "\$HEADERS" \
    -H "Authorization: Bearer \$LINA_TOKEN" \
    -H "If-None-Match: \$(cat "\$ETAG_FILE" 2>/dev/null || echo none)" \
    -H "X-Lina-Hostname: \$(hostname -f 2>/dev/null || hostname)" \
    -H "X-Lina-Agent-Version: \$(alloy --version 2>/dev/null | head -n1 || echo unknown)" \
    "\$LINA_BASE_URL/api/agent/config")
if [ "\$STATUS" = "200" ]; then
    mv "\$BODY" /etc/alloy/config.alloy
    grep -i '^etag:' "\$HEADERS" | awk '{print \$2}' | tr -d '\r' > "\$ETAG_FILE"
    systemctl try-reload-or-restart alloy
elif [ "\$STATUS" != "304" ]; then
    echo "Config poll failed with HTTP \$STATUS; keeping current config." >&2
    exit 1
fi
rm -f "\$HEADERS" "\$BODY" 2>/dev/null || true
UPDATER
chmod 755 /usr/local/bin/lina-agent-update

cat > /etc/systemd/system/lina-agent-update.service <<'UNIT'
[Unit]
Description=Lina agent config updater

[Service]
Type=oneshot
ExecStart=/usr/local/bin/lina-agent-update
UNIT

cat > /etc/systemd/system/lina-agent-update.timer <<'UNIT'
[Unit]
Description=Poll Lina for agent config changes

[Timer]
OnBootSec=30s
OnUnitActiveSec=60s

[Install]
WantedBy=timers.target
UNIT

echo "==> Fetching initial config and starting Alloy"
systemctl daemon-reload
/usr/local/bin/lina-agent-update
systemctl enable --now alloy
systemctl enable --now lina-agent-update.timer

echo ""
echo "✔ Lina agent installed. This server will appear online in the dashboard within a minute."
