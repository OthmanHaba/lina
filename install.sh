#!/usr/bin/env bash
set -Eeuo pipefail

readonly DEFAULT_REPOSITORY="https://github.com/OthmanHaba/lina.git"
readonly DEFAULT_BRANCH="main"
readonly DEFAULT_INSTALL_DIRECTORY="/opt/lina"
readonly COMPOSE_FILE="docker-compose.production.yml"

repository="$DEFAULT_REPOSITORY"
branch="$DEFAULT_BRANCH"
install_directory="$DEFAULT_INSTALL_DIRECTORY"
domain=""
ip_mode=false

print_usage() {
    cat <<'USAGE'
Install or upgrade Lina on a Linux host.

Usage:
  install.sh --domain logs.example.com
  install.sh --ip

Options:
  --domain HOST       Serve Lina with automatic HTTPS for this domain.
  --ip                Serve Lina over HTTP using the detected public IP.
  --branch NAME       Git branch or tag to deploy. Default: main.
  --repository URL    Git repository to clone.
  --directory PATH    Installation directory. Default: /opt/lina.
  --help              Show this help.
USAGE
}

fail() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

log() {
    printf '\n==> %s\n' "$*"
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --domain)
            [ "$#" -ge 2 ] || fail "--domain requires a value"
            domain="$2"
            shift 2
            ;;
        --ip)
            ip_mode=true
            shift
            ;;
        --branch)
            [ "$#" -ge 2 ] || fail "--branch requires a value"
            branch="$2"
            shift 2
            ;;
        --repository)
            [ "$#" -ge 2 ] || fail "--repository requires a value"
            repository="$2"
            shift 2
            ;;
        --directory)
            [ "$#" -ge 2 ] || fail "--directory requires a value"
            install_directory="$2"
            shift 2
            ;;
        --help|-h)
            print_usage
            exit 0
            ;;
        *)
            fail "unknown option: $1"
            ;;
    esac
done

[ "$(id -u)" -eq 0 ] || fail "run this installer as root or through sudo"
[ "$(uname -s)" = "Linux" ] || fail "Lina's production installer supports Linux hosts"
[ -n "$install_directory" ] || fail "the installation directory cannot be empty"
[ "$install_directory" != "/" ] || fail "the installation directory cannot be /"

if [ -n "$domain" ] && [ "$ip_mode" = true ]; then
    fail "choose either --domain or --ip"
fi

if [ -z "$domain" ] && [ "$ip_mode" = false ]; then
    if [ -r /dev/tty ]; then
        printf 'Public domain for Lina, or type "ip" for HTTP-only mode: ' >/dev/tty
        read -r answer </dev/tty

        if [ "$answer" = "ip" ]; then
            ip_mode=true
        else
            domain="$answer"
        fi
    else
        fail "pass --domain logs.example.com or --ip"
    fi
fi

if [ -n "$domain" ]; then
    case "$domain" in
        *[!A-Za-z0-9.-]*|.*|*.)
            fail "invalid domain: $domain"
            ;;
    esac
fi

install_prerequisites() {
    log "Installing host prerequisites"

    if command -v apt-get >/dev/null 2>&1; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y ca-certificates curl git openssl sqlite3
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y ca-certificates curl git openssl sqlite
    elif command -v yum >/dev/null 2>&1; then
        yum install -y ca-certificates curl git openssl sqlite
    else
        fail "supported package manager not found; install curl, git, openssl, sqlite3, and Docker manually"
    fi
}

install_docker() {
    if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        return
    fi

    log "Installing Docker Engine and Compose"
    local docker_installer
    docker_installer="$(mktemp)"
    trap 'rm -f "${docker_installer:-}"' RETURN
    curl -fsSL https://get.docker.com -o "$docker_installer"
    sh "$docker_installer"

    if command -v systemctl >/dev/null 2>&1; then
        systemctl enable --now docker
    fi

    docker compose version >/dev/null 2>&1 || fail "Docker Compose is unavailable after installation"
}

detect_public_ip() {
    local detected_ip
    detected_ip="$(curl -4 -fsS --max-time 8 https://api.ipify.org 2>/dev/null || true)"

    if [ -z "$detected_ip" ]; then
        detected_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    fi

    [ -n "$detected_ip" ] || fail "could not detect this host's IP address; use --domain"
    printf '%s' "$detected_ip"
}

checkout_source() {
    log "Preparing Lina source"

    if [ -d "$install_directory/.git" ]; then
        if [ -n "$(git -C "$install_directory" status --porcelain --untracked-files=normal)" ]; then
            fail "$install_directory has local changes; commit or remove them before upgrading"
        fi

        git -C "$install_directory" fetch --prune origin
        git -C "$install_directory" checkout "$branch"
        git -C "$install_directory" pull --ff-only origin "$branch"
        return
    fi

    if [ -e "$install_directory" ] && [ -n "$(find "$install_directory" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
        fail "$install_directory exists and is not an empty Lina checkout"
    fi

    mkdir -p "$install_directory"
    git clone --branch "$branch" --depth 1 "$repository" "$install_directory"
}

write_environment() {
    local environment_file="$install_directory/.env.production"
    local app_url="$1"
    local site_address="$2"
    local secure_cookie="$3"

    if [ ! -f "$environment_file" ]; then
        local app_key
        app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"

        cat >"$environment_file" <<ENV
APP_NAME=Lina
APP_ENV=production
APP_KEY=$app_key
APP_DEBUG=false
APP_URL=$app_url
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stderr
LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/database/database.sqlite
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=$secure_cookie
CACHE_STORE=database
QUEUE_CONNECTION=database

LOKI_URL=http://loki:3100
LOKI_INGEST_RPM=300
LOKI_OVERRIDES_PATH=/var/www/html/storage/app/loki/overrides.yaml

MAIL_MAILER=log
MAIL_FROM_ADDRESS=alerts@localhost
MAIL_FROM_NAME=Lina
TELEGRAM_BOT_TOKEN=

LINA_SITE_ADDRESS=$site_address
VITE_APP_NAME=Lina
ENV
        chmod 0600 "$environment_file"
        return
    fi

    upsert_environment_value "$environment_file" "APP_URL" "$app_url"
    upsert_environment_value "$environment_file" "SESSION_SECURE_COOKIE" "$secure_cookie"
    upsert_environment_value "$environment_file" "LINA_SITE_ADDRESS" "$site_address"
}

upsert_environment_value() {
    local file="$1"
    local key="$2"
    local value="$3"

    if grep -q "^${key}=" "$file"; then
        sed -i.bak "s|^${key}=.*|${key}=${value}|" "$file"
        rm -f "${file}.bak"
    else
        printf '%s=%s\n' "$key" "$value" >>"$file"
    fi
}

prepare_persistent_storage() {
    mkdir -p \
        "$install_directory/backups" \
        "$install_directory/database" \
        "$install_directory/storage/app/loki" \
        "$install_directory/storage/framework/cache/data" \
        "$install_directory/storage/framework/sessions" \
        "$install_directory/storage/framework/views" \
        "$install_directory/storage/logs"

    touch "$install_directory/database/database.sqlite"
    chown -R 33:33 "$install_directory/database" "$install_directory/storage"
    find "$install_directory/database" "$install_directory/storage" -type d -exec chmod 0770 {} +
    find "$install_directory/database" "$install_directory/storage" -type f -exec chmod 0660 {} +
}

backup_database() {
    local database="$install_directory/database/database.sqlite"

    if [ ! -s "$database" ]; then
        return
    fi

    local backup="$install_directory/backups/database-$(date -u +%Y%m%dT%H%M%SZ).sqlite"
    log "Backing up SQLite metadata"
    sqlite3 "$database" ".backup '$backup'"
    chmod 0600 "$backup"
    printf 'Backup: %s\n' "$backup"
}

deploy_stack() {
    local compose=(docker compose -f "$install_directory/$COMPOSE_FILE" --project-directory "$install_directory")

    log "Building the Lina application image"
    "${compose[@]}" build --pull app

    backup_database

    "${compose[@]}" stop app queue scheduler >/dev/null 2>&1 || true

    log "Applying database and Loki configuration"
    "${compose[@]}" run --rm app php artisan migrate --force
    "${compose[@]}" run --rm app php artisan loki:write-overrides

    log "Starting Lina"
    "${compose[@]}" up -d --remove-orphans
}

wait_for_health() {
    local app_url="$1"
    local health_url="${app_url%/}/up"

    log "Waiting for Lina to become healthy"

    for attempt in $(seq 1 30); do
        if curl -fsS --max-time 5 "$health_url" >/dev/null 2>&1; then
            return
        fi

        sleep 2
    done

    printf 'Warning: services started, but %s did not answer yet.\n' "$health_url" >&2
    printf 'Check DNS, ports 80/443, and: cd %s && docker compose -f %s ps\n' "$install_directory" "$COMPOSE_FILE" >&2
}

install_prerequisites
install_docker
checkout_source

if [ -n "$domain" ]; then
    public_url="https://$domain"
    caddy_address="$domain"
    secure_cookie=true
else
    public_ip="$(detect_public_ip)"
    public_url="http://$public_ip"
    caddy_address=":80"
    secure_cookie=false
fi

write_environment "$public_url" "$caddy_address" "$secure_cookie"
prepare_persistent_storage
deploy_stack
wait_for_health "$public_url"

printf '\nLina is ready: %s\n' "$public_url"
printf 'Create the first account at: %s/register\n' "$public_url"
printf 'Installation directory: %s\n' "$install_directory"
