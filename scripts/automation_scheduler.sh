#!/bin/sh
set -eu

interval="${AUTOMATION_HEARTBEAT_INTERVAL:-15}"
timeout="${AUTOMATION_HEARTBEAT_TIMEOUT:-20}"
url="${AUTOMATION_HEARTBEAT_URL:-http://nginx/api/automation/heartbeat}"

log() {
  printf "%s %s\n" "$(date -Iseconds)" "$1"
}

log "automation scheduler started: interval=${interval}s timeout=${timeout}s url=${url}"

while true; do
  started_epoch=$(date +%s)

  if response=$(curl -sS --max-time "$timeout" -X POST "$url" -H "Accept: application/json" -H "Content-Type: application/json" -d "{}" 2>&1); then
    log "heartbeat ok: $response"
  else
    status=$?
    log "heartbeat failed (${status}): $response"
  fi

  finished_epoch=$(date +%s)
  elapsed=$((finished_epoch - started_epoch))
  if [ "$elapsed" -ge "$interval" ]; then
    sleep 1
  else
    sleep $((interval - elapsed))
  fi
done
