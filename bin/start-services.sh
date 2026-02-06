#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$ROOT_DIR/.." && pwd)"
WORKSPACE_ROOT="$(cd "$REPO_DIR/../.." && pwd)"

COMPOSE_FILE="${COMPOSE_FILE:-$WORKSPACE_ROOT/sites/scan/docker-compose.yaml}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-scan}"
PODMAN_COMPOSE_PROVIDER="${PODMAN_COMPOSE_PROVIDER:-docker-compose}"

export COMPOSE_FILE
export COMPOSE_PROJECT_NAME
export PODMAN_COMPOSE_PROVIDER

PODMAN_SOCKET="/run/user/$(id -u)/podman/podman.sock"
if [ -z "${DOCKER_HOST:-}" ] && [ -S "$PODMAN_SOCKET" ]; then
  export DOCKER_HOST="unix://$PODMAN_SOCKET"
fi

if [ ! -f "$COMPOSE_FILE" ]; then
  echo "[start-services] compose file not found: $COMPOSE_FILE" >&2
  exit 1
fi

echo "[start-services] using compose file: $COMPOSE_FILE"
echo "[start-services] profiles: omeka"

podman compose -f "$COMPOSE_FILE" --profile omeka up -d
