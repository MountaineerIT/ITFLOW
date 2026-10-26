#!/usr/bin/env bash
#
# Mountaineer IT - ITFlow one-line installer for Debian 12 / 13
#
# Beta / development server (tracks the develop branch):
#   curl -fsSL https://raw.githubusercontent.com/MountaineerIT/ITFLOW/develop/scripts/install.sh | bash -s -- --branch develop
#
# Run as root on a fresh Debian server. Anything not given as an option is asked for.
# Run with --help for all options.
#
# What it does: installs Apache, MariaDB, PHP and git; creates the database; clones
# ITFlow from github.com/MountaineerIT/ITFLOW; sets up the Apache site with HTTPS
# (Let's Encrypt or self-signed); runs ITFlow's setup to create the company and the first
# admin; installs the cron entry and switches cron on; and, on non-production branches,
# schedules a nightly self-update.

set -Eeuo pipefail

# 'su' without '-' keeps the user's PATH, which lacks the sbin folders (runuser, a2enmod...).
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

REPO_URL="https://github.com/MountaineerIT/ITFLOW.git"
INSTALL_DIR="/var/www/itflow"
WEB_USER="www-data"
CREDENTIALS_FILE="/root/itflow-install-credentials.txt"

BRANCH="MountaineerIT"
DOMAIN=""
SSL_MODE=""
LE_EMAIL=""
TIMEZONE=""
LOCALE="en_US"
CURRENCY="USD"
COUNTRY="United States"
COMPANY_NAME=""
ADMIN_NAME=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
AUTO_UPDATE=""
ASSUME_YES=0
LOCAL_AI=""

usage() {
    cat <<'EOF'
ITFlow installer (Mountaineer IT build) for Debian 12 / 13

Options:
  -b, --branch NAME      Branch to install and track: develop (beta) or MountaineerIT (production).
                         Default: MountaineerIT
  -d, --domain FQDN      Server name, e.g. itflow-beta.example.com
  -s, --ssl MODE         letsencrypt | self-signed | none
                         letsencrypt needs the domain's public DNS pointing here and ports 80/443 open.
                         none = plain HTTP, for a server behind a reverse proxy that handles HTTPS.
                         The proxy must send X-Forwarded-Proto: https and X-Forwarded-For.
  --email ADDRESS        Email for Let's Encrypt expiry notices
  -t, --timezone TZ      e.g. America/New_York (default: this server's timezone)
  --locale LOCALE        Default: en_US
  --currency CODE        Default: USD
  --country NAME         Default: United States
  --company NAME         Company name
  --admin-name NAME      First admin's full name
  --admin-email ADDRESS  First admin's login email
  --admin-password PASS  First admin's password (min 8 chars; asked for if not given)
  --auto-update          Update nightly from the branch (default on for non-production branches)
  --no-auto-update       Don't schedule nightly updates
  --local-ai             Also install Ollama for the AI helper's local embeddings (scripts/install_local_ai.sh;
                         at least 3 GB of memory). Asked when neither this nor --no-local-ai is given.
  --no-local-ai          Don't install it (nor ask)
  -y, -u, --yes, --unattended
                         Don't ask for confirmation before installing
  -h, --help             Show this help
EOF
}

# ---------------------------------------------------------------------------- helpers

info()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
ok()    { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn()  { printf '    \033[33m!\033[0m %s\n' "$*" >&2; }
die()   { printf '\n\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

trap 'die "the installer stopped unexpectedly at line $LINENO. Fix the problem above and run it again on a fresh server (or remove $INSTALL_DIR and the itflow database first)."' ERR

# When piped from curl, stdin is the script itself - prompts read from the terminal instead.
HAVE_TTY=0
if [[ -r /dev/tty ]] && { : < /dev/tty; } 2>/dev/null; then HAVE_TTY=1; fi

# ask VAR "Question" [default]
ask() {
    local __var="$1" __question="$2" __default="${3:-}" __answer=""
    if [[ -n "${!__var}" ]]; then return; fi
    [[ $HAVE_TTY -eq 1 ]] || die "no terminal to ask \"$__question\" on - pass it as an option (see --help)."
    while [[ -z "$__answer" ]]; do
        if [[ -n "$__default" ]]; then
            read -r -p "    $__question [$__default]: " __answer < /dev/tty || true
            __answer="${__answer:-$__default}"
        else
            read -r -p "    $__question: " __answer < /dev/tty || true
        fi
    done
    printf -v "$__var" '%s' "$__answer"
}

ask_secret() {
    local __var="$1" __question="$2" __a="" __b=""
    if [[ -n "${!__var}" ]]; then return; fi
    [[ $HAVE_TTY -eq 1 ]] || die "missing --admin-password (no terminal to ask on). See --help."
    while :; do
        read -r -s -p "    $__question: " __a < /dev/tty || true; echo > /dev/tty
        if [[ ${#__a} -lt 8 ]]; then warn "at least 8 characters, please"; continue; fi
        read -r -s -p "    Repeat it: " __b < /dev/tty || true; echo > /dev/tty
        [[ "$__a" == "$__b" ]] && break
        warn "they don't match, try again"
    done
    printf -v "$__var" '%s' "$__a"
}

random_password() { tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32 || true; }

# The whole install runs inside main(), called on the last line, so bash has read the
# entire script before anything runs. When piped from curl, stdin IS the script, and a
# command that reads stdin would otherwise swallow the rest of it.
main() {

# ---------------------------------------------------------------------------- options

while [[ $# -gt 0 ]]; do
    case "$1" in
        -b|--branch)       BRANCH="${2:?}"; shift 2 ;;
        -d|--domain)       DOMAIN="${2:?}"; shift 2 ;;
        -s|--ssl)          SSL_MODE="${2:?}"; shift 2 ;;
        --email)           LE_EMAIL="${2:?}"; shift 2 ;;
        -t|--timezone)     TIMEZONE="${2:?}"; shift 2 ;;
        --locale)          LOCALE="${2:?}"; shift 2 ;;
        --currency)        CURRENCY="${2:?}"; shift 2 ;;
        --country)         COUNTRY="${2:?}"; shift 2 ;;
        --company)         COMPANY_NAME="${2:?}"; shift 2 ;;
        --admin-name)      ADMIN_NAME="${2:?}"; shift 2 ;;
        --admin-email)     ADMIN_EMAIL="${2:?}"; shift 2 ;;
        --admin-password)  ADMIN_PASSWORD="${2:?}"; shift 2 ;;
        --auto-update)     AUTO_UPDATE=1; shift ;;
        --no-auto-update)  AUTO_UPDATE=0; shift ;;
        --local-ai)        LOCAL_AI=1; shift ;;
        --no-local-ai)     LOCAL_AI=0; shift ;;
        -y|-u|--yes|--unattended) ASSUME_YES=1; shift ;;
        -h|--help)         usage; exit 0 ;;
        *) die "unknown option: $1 (see --help)" ;;
    esac
done

# ---------------------------------------------------------------------------- pre-flight

info "Checking this server"

[[ $EUID -eq 0 ]] || die "run as root (e.g. 'sudo -i' first, or pipe into 'sudo bash -s -- ...')."

[[ -r /etc/os-release ]] || die "can't read /etc/os-release."
# shellcheck disable=SC1091
. /etc/os-release
[[ "${ID:-}" == "debian" ]] || die "this installer supports Debian 12 or 13 (found ${PRETTY_NAME:-unknown})."
[[ "${VERSION_ID:-}" == "12" || "${VERSION_ID:-}" == "13" ]] || die "Debian 12 or 13 required (found ${PRETTY_NAME})."
ok "$PRETTY_NAME"

[[ -e "$INSTALL_DIR" ]] && die "$INSTALL_DIR already exists - this installer is for fresh servers only."

[[ "$BRANCH" =~ ^[A-Za-z0-9._/-]+$ ]] || die "invalid branch name: $BRANCH"

# ---------------------------------------------------------------------------- questions

info "A few questions (press Enter to accept the [default])"

ask DOMAIN "Server name (FQDN), e.g. itflow-beta.example.com" "$(hostname -f 2>/dev/null || hostname)"
DOMAIN="${DOMAIN,,}"
[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || die "invalid server name: $DOMAIN"

if [[ -z "$SSL_MODE" ]]; then
    ask SSL_MODE "HTTPS: letsencrypt (public DNS), self-signed (internal) or none (behind a reverse proxy)" "letsencrypt"
fi
[[ "$SSL_MODE" == "selfsigned" ]] && SSL_MODE="self-signed"   # upstream installer's spelling
[[ "$SSL_MODE" == "letsencrypt" || "$SSL_MODE" == "self-signed" || "$SSL_MODE" == "none" ]] \
    || die "--ssl must be letsencrypt, self-signed or none"
[[ "$SSL_MODE" == "letsencrypt" ]] && ask LE_EMAIL "Email for Let's Encrypt notices"
SITE_URL="https://$DOMAIN"   # with --ssl none, the reverse proxy serves HTTPS

DEFAULT_TZ="$(cat /etc/timezone 2>/dev/null || timedatectl show -p Timezone --value 2>/dev/null || echo UTC)"
ask TIMEZONE "Timezone" "$DEFAULT_TZ"
[[ -e "/usr/share/zoneinfo/$TIMEZONE" ]] || die "unknown timezone: $TIMEZONE"

ask COMPANY_NAME "Company name"
ask ADMIN_NAME "Admin full name"
ask ADMIN_EMAIL "Admin email (login)"
[[ "$ADMIN_EMAIL" == *@*.* ]] || die "that doesn't look like an email address: $ADMIN_EMAIL"
ask_secret ADMIN_PASSWORD "Admin password (min 8 chars)"
[[ ${#ADMIN_PASSWORD} -ge 8 ]] || die "admin password must be at least 8 characters"


if [[ -z "$LOCAL_AI" ]]; then
    LOCAL_AI=0
    if [[ $HAVE_TTY -eq 1 ]]; then
        MEM_GB=$(( $(awk '/^MemTotal:/ {print $2}' /proc/meminfo) / 1024 / 1024 ))
        LOCAL_AI_ANSWER=""
        ask LOCAL_AI_ANSWER "Install local AI for the AI helper's search (Ollama, free, on this server; needs 3 GB+ memory - this one has ${MEM_GB} GB)? y/n" "n"
        [[ "$LOCAL_AI_ANSWER" =~ ^[Yy] ]] && LOCAL_AI=1
    fi
fi

if [[ -z "$AUTO_UPDATE" ]]; then
    if [[ "$BRANCH" == "MountaineerIT" ]]; then AUTO_UPDATE=0; else AUTO_UPDATE=1; fi
fi

cat <<EOF

    Install ITFlow with these settings:
      Branch       $BRANCH $( [[ "$BRANCH" == "MountaineerIT" ]] && echo "(production)" || echo "(beta / testing)" )
      URL          $SITE_URL
      HTTPS        $SSL_MODE
      Timezone     $TIMEZONE
      Company      $COMPANY_NAME
      Admin        $ADMIN_NAME <$ADMIN_EMAIL>
      Auto-update  $( [[ $AUTO_UPDATE -eq 1 ]] && echo "nightly at 03:15" || echo "off (update from Admin > Update)" )
      Local AI     $( [[ $LOCAL_AI -eq 1 ]] && echo "yes - Ollama for the AI helper's search" || echo "no (scripts/install_local_ai.sh adds it later)" )

EOF
if [[ $ASSUME_YES -eq 0 ]]; then
    [[ $HAVE_TTY -eq 1 ]] || die "no terminal to confirm on - add --yes to install without confirmation."
    read -r -p "    Continue? [Y/n]: " confirm < /dev/tty || true
    [[ -z "$confirm" || "$confirm" =~ ^[Yy] ]] || die "cancelled."
fi

# ---------------------------------------------------------------------------- packages

info "Installing packages (Apache, MariaDB, PHP, git)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
PACKAGES=(apache2 mariadb-server libapache2-mod-php php-cli php-mysql php-intl php-curl
          php-mbstring php-gd php-xml php-zip git cron openssl ssl-cert ca-certificates curl)
[[ "$SSL_MODE" == "letsencrypt" ]] && PACKAGES+=(certbot python3-certbot-apache)
apt-get install -y -qq "${PACKAGES[@]}" >/dev/null
ok "packages installed"

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || die "PHP 8.2 or newer is required (found $PHP_VER)."
ok "PHP $PHP_VER"

git ls-remote --exit-code --heads "$REPO_URL" "$BRANCH" >/dev/null 2>&1 \
    || die "branch '$BRANCH' not found at $REPO_URL (is the repository public?)"

timedatectl set-timezone "$TIMEZONE" 2>/dev/null || ln -sf "/usr/share/zoneinfo/$TIMEZONE" /etc/localtime

# ---------------------------------------------------------------------------- PHP settings

info "Configuring PHP"
# Backups and restores unpack in PHP's temporary folder; on Debian 13 /tmp is a RAM disk of half the memory, too
# small for a big backup - use a folder on disk instead
PHP_TMP="/var/lib/itflow-tmp"
install -d -m 0700 -o "$WEB_USER" -g "$WEB_USER" "$PHP_TMP"
for sapi in apache2 cli; do
    if [[ -d "/etc/php/$PHP_VER/$sapi/conf.d" ]]; then
        cat > "/etc/php/$PHP_VER/$sapi/conf.d/99-itflow.ini" <<EOF
; ITFlow (Mountaineer IT installer)
upload_max_filesize = 500M
post_max_size = 500M
memory_limit = 512M
max_execution_time = 300
date.timezone = $TIMEZONE
sys_temp_dir = $PHP_TMP
EOF
    fi
done
ok "uploads up to 500 MB, timezone $TIMEZONE, temporary files in $PHP_TMP"

# ---------------------------------------------------------------------------- database

info "Creating the database"
systemctl enable --now mariadb >/dev/null 2>&1 || service mariadb start >/dev/null
DB_NAME="itflow"
DB_USER="itflow"
DB_PASS="$(random_password)"
if mariadb -N -e "SHOW DATABASES LIKE '$DB_NAME'" | grep -q .; then
    die "a database called '$DB_NAME' already exists - this installer is for fresh servers only."
fi
mariadb <<EOF
CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
ok "database '$DB_NAME' and user '$DB_USER'"

# ---------------------------------------------------------------------------- code

info "Downloading ITFlow ($BRANCH)"
install -d -o "$WEB_USER" -g "$WEB_USER" -m 755 "$INSTALL_DIR"
# Run a command as the web user. runuser is preferred; su is the fallback on systems without it.
sudo_web() {
    if command -v runuser >/dev/null 2>&1; then
        runuser -u "$WEB_USER" -- "$@"
    else
        su -s /bin/bash "$WEB_USER" -c "$(printf '%q ' "$@")"
    fi
}
sudo_web git clone --quiet --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
ok "cloned into $INSTALL_DIR ($(sudo_web git -C "$INSTALL_DIR" rev-parse --short HEAD))"

# ---------------------------------------------------------------------------- Apache + HTTPS

info "Configuring Apache and HTTPS"
a2enmod -q rewrite headers ssl alias remoteip >/dev/null
a2dissite -q 000-default >/dev/null 2>&1 || true

SITE_CONF="/etc/apache2/sites-available/itflow.conf"
if [[ "$SSL_MODE" == "self-signed" ]]; then
    install -d -m 700 /etc/ssl/itflow
    openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -keyout /etc/ssl/itflow/itflow.key -out /etc/ssl/itflow/itflow.crt \
        -subj "/CN=$DOMAIN" -addext "subjectAltName=DNS:$DOMAIN" >/dev/null 2>&1
    CERT_FILE=/etc/ssl/itflow/itflow.crt
    KEY_FILE=/etc/ssl/itflow/itflow.key
else
    CERT_FILE=/etc/ssl/certs/ssl-cert-snakeoil.pem
    KEY_FILE=/etc/ssl/private/ssl-cert-snakeoil.key
    [[ -f "$CERT_FILE" ]] || make-ssl-cert generate-default-snakeoil >/dev/null 2>&1 || true
fi

# ---------------------------------------------------------------------------- ITFlow setup
# Before the Apache site is switched on: until setup has written config.php, ITFlow's web setup page would answer
# anyone who can reach this server (and could be pointed at their own database or backup)

info "Running ITFlow setup"
SETUP_LOG="$(mktemp)"   # private to root (not a guessable /tmp name)
(
    cd "$INSTALL_DIR/scripts"
    sudo_web php setup_cli.php --non-interactive \
        --host=localhost --username="$DB_USER" --password="$DB_PASS" --database="$DB_NAME" \
        --base-url="$DOMAIN" --locale="$LOCALE" --timezone="$TIMEZONE" --currency="$CURRENCY" \
        --company-name="$COMPANY_NAME" --country="$COUNTRY" \
        --user-name="$ADMIN_NAME" --user-email="$ADMIN_EMAIL" --user-password="$ADMIN_PASSWORD" \
        --repo-branch="$BRANCH" < /dev/null > "$SETUP_LOG" 2>&1
) || { cat "$SETUP_LOG" >&2; die "ITFlow setup failed (output above)."; }
grep -q "config_enable_setup = 0" "$INSTALL_DIR/config.php" || { cat "$SETUP_LOG" >&2; die "ITFlow setup did not finish."; }
rm -f "$SETUP_LOG"
# Our own secret key (Microsoft 365 certificate, Outlook sign-ins) - made now, so it never falls back to a weaker one
grep -q 'config_mit_secret_key' "$INSTALL_DIR/config.php" || printf "\n// Mountaineer IT: key for the Microsoft 365 certificate and Outlook sign-ins - keep it with the backup key\n\$config_mit_secret_key = '%s';\n" "$(random_password)$(random_password)" >> "$INSTALL_DIR/config.php"
chmod 640 "$INSTALL_DIR/config.php"
# Behind a reverse proxy ITFlow still insists on HTTPS (secure cookies): the proxy says the visit was HTTPS with
# X-Forwarded-Proto, which ITFlow and the Apache site below both read
ok "company, admin user and config.php created (tracking '$BRANCH')"

# ---------------------------------------------------------------------------- Apache site

if [[ "$SSL_MODE" == "none" ]]; then
    # Plain HTTP - HTTPS is handled by a reverse proxy in front of this server
    cat > "$SITE_CONF" <<EOF
# ITFlow - written by the Mountaineer IT installer (no HTTPS: behind a reverse proxy)
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $INSTALL_DIR

    # The visitor's own address from the proxy (X-Forwarded-For), trusted only from private-network proxies - so
    # sign-in lockouts and logs see each visitor, not the proxy. Add RemoteIPTrustedProxy <ip> for a public one.
    RemoteIPHeader X-Forwarded-For
    RemoteIPInternalProxy 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.1 ::1 fc00::/7
    # The proxy served the visit over HTTPS
    SetEnvIf X-Forwarded-Proto "^https\$" HTTPS=on

    <Directory $INSTALL_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/itflow-error.log
    CustomLog \${APACHE_LOG_DIR}/itflow-access.log combined
</VirtualHost>
EOF
else
cat > "$SITE_CONF" <<EOF
# ITFlow - written by the Mountaineer IT installer
<VirtualHost *:80>
    ServerName $DOMAIN
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
    DocumentRoot $INSTALL_DIR
</VirtualHost>

<VirtualHost *:443>
    ServerName $DOMAIN
    DocumentRoot $INSTALL_DIR

    SSLEngine on
    SSLCertificateFile $CERT_FILE
    SSLCertificateKeyFile $KEY_FILE

    <Directory $INSTALL_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/itflow-error.log
    CustomLog \${APACHE_LOG_DIR}/itflow-access.log combined
</VirtualHost>
EOF
fi
a2ensite -q itflow >/dev/null
apache2ctl configtest >/dev/null 2>&1 || die "Apache configuration test failed - run 'apache2ctl configtest' to see why."
systemctl enable apache2 >/dev/null 2>&1 || true
systemctl restart apache2 2>/dev/null || service apache2 restart >/dev/null 2>&1

if [[ "$SSL_MODE" == "letsencrypt" ]]; then
    if certbot --apache --non-interactive --agree-tos -m "$LE_EMAIL" -d "$DOMAIN" \
            --cert-name itflow >/dev/null 2>&1; then
        ok "Let's Encrypt certificate installed (renews automatically)"
    else
        warn "Let's Encrypt failed - the site is using a temporary self-signed certificate."
        warn "Check that $DOMAIN points to this server and ports 80/443 are open, then run:"
        warn "  certbot --apache -d $DOMAIN --cert-name itflow -m $LE_EMAIL --agree-tos"
    fi
elif [[ "$SSL_MODE" == "self-signed" ]]; then
    ok "self-signed certificate (browsers will warn until you trust it)"
else
    ok "plain HTTP on port 80 - point your reverse proxy at this server (it must send X-Forwarded-Proto and X-Forwarded-For)"
fi

# ---------------------------------------------------------------------------- cron

info "Setting up scheduled jobs"
# Jobs go in /etc/cron.d/itflow (run as the web user). Normal output is discarded; errors go
# to the system log, tagged itflow-cron / itflow-update (see: journalctl -t itflow-cron).
PHP_BIN="$(command -v php)"
CRON_LINES="# ITFlow - written by the Mountaineer IT installer
* * * * * $WEB_USER $PHP_BIN $INSTALL_DIR/cron/cron.php 2>&1 >/dev/null | logger -t itflow-cron"
if [[ $AUTO_UPDATE -eq 1 ]]; then
    install -o "$WEB_USER" -g "$WEB_USER" -m 640 /dev/stdin "$INSTALL_DIR/scripts/custom/queue_update.php" <<'EOF'
<?php
// Queue an ITFlow update for the cron dispatcher - same as Admin > Update > Update.
if (php_sapi_name() !== 'cli') { exit(1); }
require __DIR__ . '/../../config.php';
mysqli_query($mysqli, "UPDATE settings SET config_update_queued_at = NOW() WHERE company_id = 1");
mysqli_query($mysqli, "UPDATE cron_jobs SET cron_job_run_now = 1 WHERE cron_job_name = 'app_update'");
EOF
    CRON_LINES+=$'\n'"15 3 * * * $WEB_USER $PHP_BIN $INSTALL_DIR/scripts/custom/queue_update.php 2>&1 >/dev/null | logger -t itflow-update"
fi
printf '%s\n' "$CRON_LINES" > /etc/cron.d/itflow
chmod 644 /etc/cron.d/itflow
systemctl enable --now cron >/dev/null 2>&1 || service cron start >/dev/null 2>&1 || true

# Domain Refresher checks one domain per run - run it every 30 minutes instead of daily.
# The dispatcher only creates a job's row if it is missing, so seeding it here sticks.
mariadb "$DB_NAME" <<'EOF'
UPDATE settings SET config_enable_cron = 1 WHERE company_id = 1;
INSERT INTO cron_jobs SET cron_job_name = 'domain_refresher', cron_job_enabled = 1,
    cron_job_schedule = 'Interval', cron_job_interval_minutes = 30
    ON DUPLICATE KEY UPDATE cron_job_schedule = 'Interval', cron_job_interval_minutes = 30;
EOF
ok "cron installed and switched on$( [[ $AUTO_UPDATE -eq 1 ]] && echo ", nightly update queued at 03:15" )"

# ---------------------------------------------------------------------------- local AI (optional)

if [[ $LOCAL_AI -eq 1 ]]; then
    bash "$INSTALL_DIR/scripts/install_local_ai.sh" --yes || warn "local embeddings were not set up - run $INSTALL_DIR/scripts/install_local_ai.sh again later"
fi

# ---------------------------------------------------------------------------- done

umask 077
cat > "$CREDENTIALS_FILE" <<EOF
ITFlow installed $(date '+%Y-%m-%d %H:%M')
URL:            $SITE_URL
Branch:         $BRANCH
Install dir:    $INSTALL_DIR
Admin login:    $ADMIN_EMAIL
Database:       $DB_NAME
DB user:        $DB_USER
DB password:    $DB_PASS
EOF

cat <<EOF

  ITFlow is installed.

    Open:        $SITE_URL
    Log in as:   $ADMIN_EMAIL
    Branch:      $BRANCH
    Details:     $CREDENTIALS_FILE (database password - keep it safe)

  Next steps:
    - Set up backups (Admin > Backup) and mail (Admin > Settings > Mail).
    - Updates: Admin > Update$( [[ $AUTO_UPDATE -eq 1 ]] && echo " - this server also updates itself nightly" ).
    - See DEPLOYMENT.md in the repository for how updates and branches work.

EOF

}

main "$@"
