#!/usr/bin/env bash
# First-time install of Amnezia VPN Web Panel on a Linux VPS.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

info() { echo -e "${GREEN}[+]${NC} $*"; }
warn() { echo -e "${YELLOW}[!]${NC} $*"; }
err()  { echo -e "${RED}[x]${NC} $*"; }

dc() {
  if docker compose version >/dev/null 2>&1; then
    docker compose "$@"
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose "$@"
  else
    err "Docker Compose не найден. Установите Docker: curl -fsSL https://get.docker.com | sh"
    exit 1
  fi
}

if [ "$(id -u)" -ne 0 ]; then
  warn "Лучше запускать от root (нужны порты 80/443 и chown)."
fi

if ! command -v docker >/dev/null 2>&1; then
  err "Docker не установлен."
  echo "  curl -fsSL https://get.docker.com | sh"
  echo "  usermod -aG docker \$USER"
  exit 1
fi

# CRLF from Windows clones break shell scripts and .env
find . -name '*.sh' -print0 2>/dev/null | xargs -0 sed -i 's/\r$//' 2>/dev/null || true
sed -i 's/\r$//' .env.example 2>/dev/null || true
[ -f .env ] && sed -i 's/\r$//' .env || true

chmod +x nginx/docker-entrypoint.sh update.sh install.sh 2>/dev/null || true
chmod +x scripts/ensure_awg_nat.sh scripts/install_awg_nat_cron.sh 2>/dev/null || true

if [ ! -f .env ]; then
  cp .env.example .env
  info "Создан .env из .env.example"
  PUBLIC_IP="$(curl -4 -fsS --max-time 5 ifconfig.me 2>/dev/null || true)"
  if [ -n "${PUBLIC_IP}" ]; then
    if grep -q '^PANEL_IP=' .env; then
      sed -i "s/^PANEL_IP=.*/PANEL_IP=${PUBLIC_IP}/" .env
    else
      echo "PANEL_IP=${PUBLIC_IP}" >> .env
    fi
    info "В .env записан PANEL_IP=${PUBLIC_IP} (HTTPS по IP, самоподписанный сертификат)"
  else
    warn "Не удалось определить публичный IP. При необходимости задайте PANEL_IP в .env"
  fi
  warn "Обязательно смените DB_PASSWORD, DB_ROOT_PASSWORD, JWT_SECRET, ADMIN_PASSWORD в .env"
fi

mkdir -p storage/server-backups
chmod 775 storage/server-backups || true
chown 33:33 storage/server-backups 2>/dev/null || true

if command -v ss >/dev/null 2>&1; then
  if ss -tln | grep -qE ':80 |:443 '; then
    warn "Порты 80 и/или 443 уже заняты. nginx панели не сможет стартовать."
    ss -tlnp | grep -E ':80 |:443 ' || true
    echo "  Остановите другой nginx/caddy/apache или смените порты в docker-compose.yml"
  fi
fi

if command -v ufw >/dev/null 2>&1; then
  ufw allow 80/tcp >/dev/null 2>&1 || true
  ufw allow 443/tcp >/dev/null 2>&1 || true
fi

info "Сборка и запуск контейнеров..."
dc up -d --build

info "Ожидание MySQL..."
DB_OK=0
for i in $(seq 1 60); do
  if dc exec -T db mysqladmin ping -h localhost --silent >/dev/null 2>&1; then
    DB_OK=1
    break
  fi
  sleep 2
done

if [ "$DB_OK" -ne 1 ]; then
  err "MySQL не поднялся. Часто это сломанный том после прошлой неудачной установки."
  echo ""
  echo "  Логи БД:"
  dc logs --tail=80 db || true
  echo ""
  echo "Если это НОВЫЙ сервер и данных панели ещё нет:"
  echo "  docker compose down -v"
  echo "  ./install.sh"
  echo ""
  echo "down -v удалит том MySQL (все данные панели)."
  exit 1
fi

info "composer install внутри контейнера web..."
dc exec -T web composer install --no-interaction --prefer-dist --optimize-autoloader

info "Контейнеры:"
dc ps

PANEL_DOMAIN="$(grep '^PANEL_DOMAIN=' .env | cut -d= -f2- | tr -d '\r' || true)"
PANEL_IP="$(grep '^PANEL_IP=' .env | cut -d= -f2- | tr -d '\r' || true)"

echo ""
info "Панель должна отвечать:"
if [ -n "${PANEL_DOMAIN}" ]; then
  echo "  https://${PANEL_DOMAIN}"
elif [ -n "${PANEL_IP}" ]; then
  echo "  https://${PANEL_IP}  (предупреждение браузера о сертификате — нормально)"
else
  echo "  http://$(curl -4 -fsS --max-time 5 ifconfig.me 2>/dev/null || echo 'IP-сервера')"
fi
echo "  Локально: http://127.0.0.1:8082"
echo ""
echo "Логин по умолчанию (если не меняли .env): admin@amnez.ia / admin123"
echo "Смените пароль сразу после входа."
echo ""
echo "Проверка:  docker compose logs -f nginx"
echo "           curl -kI https://127.0.0.1"
