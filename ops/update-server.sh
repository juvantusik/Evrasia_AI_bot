#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${SAMZABERU_APP_DIR:-/home/tech/samzaberu-bot}"
COMPOSE_FILE="${APP_DIR}/docker-compose.server.yml"
SERVICE="samzaberu-app"
CONTAINER="samzaberu-app"
IMAGE="${EVRASIA_AI_BOT_IMAGE:-ghcr.io/juvantusik/evrasia_ai_bot:latest}"
ROLLBACK_IMAGE="samzaberu-app:rollback"

if [[ ! -f "${COMPOSE_FILE}" ]]; then
  echo "Compose file not found: ${COMPOSE_FILE}" >&2
  exit 1
fi

if ! grep -Fq "${IMAGE}" "${COMPOSE_FILE}"; then
  echo "Compose file does not reference the expected image: ${IMAGE}" >&2
  echo "Update ${COMPOSE_FILE} before running this script." >&2
  exit 1
fi

current_image_id="$(sudo docker inspect --format='{{.Image}}' "${CONTAINER}")"
sudo docker tag "${current_image_id}" "${ROLLBACK_IMAGE}"

echo "Pulling ${IMAGE}..."
sudo docker pull "${IMAGE}"

new_image_id="$(sudo docker image inspect --format='{{.Id}}' "${IMAGE}")"
if [[ "${current_image_id}" == "${new_image_id}" ]]; then
  echo "Evrasia AI Bot is already up to date."
  exit 0
fi

echo "Starting the new image..."
cd "${APP_DIR}"
sudo docker-compose -f "${COMPOSE_FILE}" up -d --no-deps --force-recreate "${SERVICE}"

for _ in {1..60}; do
  status="$(sudo docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${CONTAINER}")"

  if [[ "${status}" == "healthy" ]]; then
    echo "Update completed successfully."
    sudo docker ps --filter "name=^${CONTAINER}$" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
    exit 0
  fi

  if [[ "${status}" == "unhealthy" || "${status}" == "exited" || "${status}" == "dead" ]]; then
    break
  fi

  sleep 2
done

echo "The new container did not become healthy. Rolling back..." >&2
sudo docker logs --tail 80 "${CONTAINER}" >&2 || true
sudo docker tag "${ROLLBACK_IMAGE}" "${IMAGE}"
sudo docker-compose -f "${COMPOSE_FILE}" up -d --no-deps --force-recreate "${SERVICE}"

echo "Rollback completed. Check: sudo docker ps --filter name=${CONTAINER}" >&2
exit 1
