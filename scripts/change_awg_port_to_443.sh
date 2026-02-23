#!/bin/bash
# Скрипт смены порта Amnezia AWG с текущего на 443
# Запускать на VPS (root)
# ВАЖНО: порт 443 UDP не должен быть занят (nginx/apache используют 443 TCP — это другой порт)

set -e

CONTAINER="amnezia-awg"
BACKUP_DIR="/tmp/amnezia-awg-backup"
NEW_PORT=443

echo "=== Backup AWG config from container ==="
mkdir -p "$BACKUP_DIR"
docker cp "$CONTAINER:/opt/amnezia/awg" "$BACKUP_DIR/"

echo "=== Modify ListenPort to $NEW_PORT ==="
sed -i "s/ListenPort = [0-9]*/ListenPort = $NEW_PORT/" "$BACKUP_DIR/awg/wg0.conf"
grep ListenPort "$BACKUP_DIR/awg/wg0.conf"

echo "=== Stop and remove old container ==="
docker stop "$CONTAINER" 2>/dev/null || true
docker rm "$CONTAINER" 2>/dev/null || true

echo "=== Run new container with port $NEW_PORT ==="
docker run -d \
  --log-driver none \
  --restart always \
  --privileged \
  --cap-add=NET_ADMIN \
  --cap-add=SYS_MODULE \
  -p ${NEW_PORT}:${NEW_PORT}/udp \
  -v /lib/modules:/lib/modules \
  --name "$CONTAINER" \
  "$CONTAINER"

echo "=== Wait for container to start ==="
sleep 5

echo "=== Restore config ==="
docker cp "$BACKUP_DIR/awg/." "$CONTAINER:/opt/amnezia/awg/"

echo "=== Start WireGuard inside container ==="
docker exec "$CONTAINER" wg-quick down /opt/amnezia/awg/wg0.conf 2>/dev/null || true
docker exec "$CONTAINER" wg-quick up /opt/amnezia/awg/wg0.conf

echo "=== Verify ==="
docker exec "$CONTAINER" wg show
ss -ulnp | grep "$NEW_PORT"

echo ""
echo "=== DONE. Port changed to $NEW_PORT ==="
echo ""
echo "Update panel DB (run from amneziavpnphp dir):"
echo "  docker compose exec db mysql -u amnezia -p\${DB_PASSWORD:-amnezia} amnezia_panel -e \"UPDATE vpn_servers SET vpn_port = $NEW_PORT WHERE container_name = '$CONTAINER';\""
echo ""
echo "In Amnezia app: Settings -> Port -> set to $NEW_PORT"
