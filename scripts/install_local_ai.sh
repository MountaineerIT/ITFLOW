#!/usr/bin/env bash
#
# Mountaineer IT - local embeddings for ITFlow's AI helper: installs Ollama on this server and downloads the embeddings model.
#
#   sudo bash /var/www/itflow/scripts/install_local_ai.sh
#
# Then Admin > Integrations > AI helper > "Use this server" switches the helper's meaning search onto it.
# Run as root on the Debian 12 / 13 server ITFlow runs on (the web server can't install software - on purpose). Safe to run again:
# an existing Ollama is kept (use --upgrade to install the newest / --version's release over it).
#
# What it does: checks memory and disk; installs Ollama with Ollama's own installer (a systemd service under its own 'ollama' user);
# makes it listen on 127.0.0.1 only and keep at most one model loaded; downloads nomic-embed-text (about 270 MB); checks that it
# answers. Uses about 1 GB of memory while it works. Remove it again: systemctl disable --now ollama, then delete /usr/local/bin/ollama,
# /usr/share/ollama and /etc/systemd/system/ollama.service*.

set -Eeuo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

MODEL="nomic-embed-text"
OLLAMA_URL="http://127.0.0.1:11434"
INSTALLER_URL="https://ollama.com/install.sh"
OLLAMA_PIN=""
UPGRADE=0
ASSUME_YES=0

info()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
ok()    { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn()  { printf '    \033[33m!\033[0m %s\n' "$*" >&2; }
die()   { printf '\n\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<'EOF'
Local embeddings for ITFlow's AI helper (Ollama + nomic-embed-text), for Debian 12 / 13

Options:
  --version X.Y.Z   Install this Ollama release (default: Ollama's current one)
  --upgrade         Install over an Ollama that is already here
  -y, --yes         Don't ask before installing
  -h, --help        Show this help
EOF
}

main() {

while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)  OLLAMA_PIN="${2:?}"; shift 2 ;;
        --upgrade)  UPGRADE=1; shift ;;
        -y|--yes)   ASSUME_YES=1; shift ;;
        -h|--help)  usage; exit 0 ;;
        *) die "unknown option: $1 (see --help)" ;;
    esac
done
[[ -z "$OLLAMA_PIN" || "$OLLAMA_PIN" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-rc[0-9]+)?$ ]] || die "--version must look like 0.12.3"

info "Checking this server"
[[ $EUID -eq 0 ]] || die "run as root (sudo bash $0)."
# shellcheck disable=SC1091
[[ -r /etc/os-release ]] && . /etc/os-release
[[ "${ID:-}" == "debian" && ( "${VERSION_ID:-}" == "12" || "${VERSION_ID:-}" == "13" ) ]] \
    || warn "made for Debian 12 / 13 (found ${PRETTY_NAME:-unknown}) - carrying on"
case "$(uname -m)" in
    x86_64|aarch64) ok "$(uname -m)" ;;
    *) die "Ollama needs a 64-bit x86 or ARM server (found $(uname -m))." ;;
esac
{ command -v systemctl >/dev/null && [[ -d /run/systemd/system ]]; } || die "systemd is needed to run Ollama as a service."

MEM_MB=$(( $(awk '/^MemTotal:/ {print $2}' /proc/meminfo) / 1024 ))
FREE_MB=$(( $(awk '/^MemAvailable:/ {print $2}' /proc/meminfo) / 1024 ))
[[ $MEM_MB -ge 3000 ]] || die "${MEM_MB} MB of memory - at least 3 GB is needed (ITFlow, MariaDB and about 1 GB for Ollama)."
[[ $FREE_MB -ge 1200 ]] || warn "only ${FREE_MB} MB free now - Ollama needs about 1 GB while it works."
ok "${MEM_MB} MB memory, ${FREE_MB} MB free"
DISK_MB=$(df -Pm /usr | awk 'NR==2 {print $4}')
[[ $DISK_MB -ge 3000 ]] || die "${DISK_MB} MB free on /usr - at least 3 GB is needed (Ollama about 2 GB, the model 270 MB)."
ok "${DISK_MB} MB free disk"

HAVE=0
command -v ollama >/dev/null && HAVE=1
if [[ $HAVE -eq 1 ]]; then
    ok "Ollama is already installed ($(ollama --version 2>/dev/null | tail -1))"
fi

if [[ $ASSUME_YES -eq 0 && ( $HAVE -eq 0 || $UPGRADE -eq 1 ) ]]; then
    printf '\n    Install Ollama%s from %s and download %s? [y/N] ' "${OLLAMA_PIN:+ $OLLAMA_PIN}" "$INSTALLER_URL" "$MODEL"
    read -r answer < /dev/tty || answer=""
    [[ "$answer" =~ ^[Yy] ]] || die "nothing changed."
fi

# ---------------------------------------------------------------------------- install

if [[ $HAVE -eq 0 || $UPGRADE -eq 1 ]]; then
    info "Installing Ollama"
    DEBIAN_FRONTEND=noninteractive apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq curl ca-certificates zstd >/dev/null
    TMP="$(mktemp -d)"
    trap 'rm -rf "$TMP"' EXIT
    curl -fsSL --retry 3 "$INSTALLER_URL" -o "$TMP/install.sh" || die "could not download $INSTALLER_URL"
    OLLAMA_VERSION="$OLLAMA_PIN" sh "$TMP/install.sh" || die "Ollama's installer failed (see above)."
    ok "Ollama $(ollama --version 2>/dev/null | tail -1)"
fi

# ---------------------------------------------------------------------------- settings

info "Settings"
# Only this server can reach it; one model in memory at a time; kept loaded 30 minutes after a request (loading takes a second)
install -d /etc/systemd/system/ollama.service.d
cat > /etc/systemd/system/ollama.service.d/mountaineerit.conf <<'EOF'
# Written by ITFlow's scripts/install_local_ai.sh (Mountaineer IT)
[Service]
Environment="OLLAMA_HOST=127.0.0.1:11434"
Environment="OLLAMA_MAX_LOADED_MODELS=1"
Environment="OLLAMA_NUM_PARALLEL=1"
Environment="OLLAMA_KEEP_ALIVE=30m"
EOF
systemctl daemon-reload
systemctl enable ollama >/dev/null 2>&1 || true
systemctl restart ollama
for _ in $(seq 1 30); do
    curl -fsS -m 2 "$OLLAMA_URL/api/version" >/dev/null 2>&1 && break
    sleep 1
done
curl -fsS -m 2 "$OLLAMA_URL/api/version" >/dev/null 2>&1 || die "Ollama did not start - see: journalctl -u ollama"
ok "listening on 127.0.0.1:11434 only"

# ---------------------------------------------------------------------------- model

info "Downloading $MODEL"
OLLAMA_HOST=127.0.0.1:11434 ollama pull "$MODEL" || die "could not download $MODEL"
TEST=$(curl -fsS -m 120 "$OLLAMA_URL/v1/embeddings" -H 'Content-Type: application/json' \
    -d "{\"model\":\"$MODEL\",\"input\":[\"Outlook keeps asking the user to sign in again\"]}") || die "the embeddings test failed"
[[ "$TEST" == *'"embedding"'* ]] || die "the embeddings test gave no vector: ${TEST:0:200}"
ok "$MODEL answers"

cat <<EOF

  Local embeddings are ready.

    Next: ITFlow > Admin > Integrations > AI helper > "Use this server".
    The library then re-does its passages with $MODEL over the next runs (keyword search works meanwhile).

    Status:   systemctl status ollama      Logs: journalctl -u ollama
    Settings: /etc/systemd/system/ollama.service.d/mountaineerit.conf

EOF
}

main "$@"
