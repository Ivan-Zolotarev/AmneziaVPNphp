## Amnezia VPN Web Panel

Веб‑панель управления VPN‑серверами Amnezia AWG (WireGuard).

### Возможности

- **Развёртывание VPN‑сервера по SSH**
- **Импорт клиентов** из существующих панелей (wg-easy, 3x-ui)
- Управление клиентами с **сроками действия**
- **Лимиты трафика** для клиентов с автоматическим отключением
- **Резервные копии серверов** и восстановление
- Мониторинг статистики трафика
- Генерация QR‑кодов для мобильных приложений
- Многоязычный интерфейс (русский, английский, испанский, немецкий, французский, китайский)
- REST API с аутентификацией по JWT
- Аутентификация пользователей и контроль доступа (роли admin / user)
- **Защита от брутфорса** при входе (веб‑форма и `POST /api/auth/token`)
- **LDAP** — вход через корпоративный каталог (см. `LDAP_SETUP.md`)
- **Автоматическая проверка сроков действия и лимитов трафика** по cron

### Требования

- Docker
- Docker Compose

### Установка

```bash
git clone https://github.com/Ivan-Zolotarev/AmneziaVPNphp.git
cd AmneziaVPNphp          # или, например: /opt/AmneziaVPNphp
cp .env.example .env
chmod +x nginx/docker-entrypoint.sh update.sh

# Docker Compose V2 (рекомендуется)
docker compose up -d
docker compose exec web composer install

# Старый Docker Compose V1
docker-compose up -d
docker-compose exec web composer install
```

Локально на сервере: `http://127.0.0.1:8082` (порт только на localhost).

С доменом: `https://ваш-домен` (Let's Encrypt). Без домена — по IP: `https://ваш-ip` (самоподписанный сертификат, см. ниже).

Логин по умолчанию: `admin@amnez.ia` / `admin123`  
**Обязательно измените пароль после первого входа.**

### HTTPS (nginx + Let's Encrypt)

Контейнер **nginx** — reverse proxy перед PHP. Внутри него **certbot** по протоколу ACME (webroot) получает и продлевает сертификат Let's Encrypt.

**Схема:**

```
Браузер → nginx (:443 HTTPS) → web (Apache/PHP :80 в Docker-сети)
                ↑
         certbot (тот же контейнер, том /etc/letsencrypt)
```

1. Укажите в `.env` домен и email:
   ```env
   PANEL_DOMAIN=panel.example.com
   ACME_EMAIL=you@example.com
   ```
2. **A-запись** DNS: `panel.example.com` → IP VPS.
3. Откройте порты **80** и **443** (нужны и для первой выдачи, и для продления):
   ```bash
   sudo ufw allow 80/tcp
   sudo ufw allow 443/tcp
   ```
4. `docker compose up -d --build`

При первом старте nginx поднимается на HTTP, certbot проходит проверку Let's Encrypt, затем включается HTTPS и редирект с HTTP. Продление — фоновый цикл раз в 12 часов.

Логи:
```bash
docker compose logs -f nginx
```

### HTTPS по IP (без домена)

Let's Encrypt не выдаёт сертификаты на голый IP. Если домена нет, укажите публичный IPv4 VPS:

```env
PANEL_DOMAIN=
PANEL_IP=203.0.113.5
```

Откройте порт **443** (`sudo ufw allow 443/tcp`) и перезапустите: `docker compose up -d --build`.

Nginx сгенерирует **самоподписанный** сертификат с IP в SAN и включит HTTPS с редиректом с HTTP. Браузер покажет предупреждение о недоверенном сертификате — для доступа по IP без домена это ожидаемо. Сертификат хранится в Docker-томе и перевыпускается при истечении срока.

Панель: `https://203.0.113.5` (подставьте свой IP).

**Без HTTPS:** оставьте `PANEL_DOMAIN` и `PANEL_IP` пустыми — nginx отдаёт панель по HTTP на порту 80.

#### Шпаргалка: обновление на сервере и включение HTTPS по IP

```bash
# 1. Узнать публичный IP
curl -4 ifconfig.me

# 2. Перейти в каталог проекта и обновить код
# (часто /opt/AmneziaVPNphp или ~/AmneziaVPNphp — зависит от установки)
cd /opt/AmneziaVPNphp
git pull
chmod +x nginx/docker-entrypoint.sh

# 3. В .env задать (PANEL_DOMAIN и ACME_EMAIL оставить пустыми):
#    PANEL_IP=203.0.113.5
nano .env

# 4. Открыть порты
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# 5. Пересобрать nginx (обычный ./update.sh может не пересобрать его)
docker compose up -d --build

# 6. Проверить
docker compose logs nginx
curl -kI https://127.0.0.1
```

Открыть в браузере: `https://203.0.113.5` (свой IP). При предупреждении браузера: «Дополнительно» → «Перейти на сайт».

| Проблема | Что проверить |
|----------|----------------|
| Сайт не открывается | `docker compose ps` — контейнер `nginx` в статусе `Up` |
| Всё ещё HTTP | В `.env` задан `PANEL_IP`, после правок был `--build` |
| Ошибка в логах | `docker compose logs nginx` |
| Порт занят | `sudo ss -tlnp \| grep ':443'` |

После настройки nginx заходите по **`https://IP`**, а не по `http://IP:8082` (8082 — только localhost для отладки).

### Настройка (`.env`)

Скопируйте шаблон и отредактируйте под себя:

```bash
cp .env.example .env
nano .env
```

Основные переменные:

```env
# База данных
DB_HOST=db
DB_PORT=3306
DB_DATABASE=amnezia_panel
DB_USERNAME=amnezia
DB_PASSWORD=amnezia
DB_ROOT_PASSWORD=rootpassword

# Учётная запись администратора (создаётся при первом запуске)
ADMIN_EMAIL=admin@amnez.ia
ADMIN_PASSWORD=admin123

# JWT для API
JWT_SECRET=change_this_to_random_secret_key_for_production

# Защита от брутфорса (/login, /api/auth/token)
LOGIN_MAX_ATTEMPTS=5
LOGIN_ATTEMPT_WINDOW_MINUTES=15
LOGIN_LOCKOUT_MINUTES=15

# HTTPS (nginx) — см. разделы выше
PANEL_DOMAIN=
PANEL_IP=
ACME_EMAIL=
```

Полный список — в файле `.env.example`.

### Обновление на сервере

Рекомендуемый способ — скрипт `./update.sh`: он делает бэкап БД, `git pull`, `composer install`, применяет новые SQL‑миграции и перезапускает контейнеры.

```bash
cd /opt/AmneziaVPNphp   # каталог установки
chmod +x update.sh nginx/docker-entrypoint.sh
./update.sh
```

Ручное обновление (как при добавлении новых миграций):

```bash
cd /opt/AmneziaVPNphp
git pull
docker compose exec web composer install --no-interaction

# Применить одну миграцию
docker compose exec -T db mysql -uroot -p"$(grep '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)" amnezia_panel \
  < migrations/014_add_login_rate_limit.sql

docker compose restart web
# Если менялись nginx/Dockerfile:
docker compose up -d --build
```

Проверка, что миграция применена:

```bash
docker compose exec db mysql -uroot -p"$(grep '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)" amnezia_panel \
  -e "SHOW TABLES LIKE 'login_attempts';"
```

### Защита от брутфорса

Ограничение неудачных попыток входа для **веб‑формы** (`POST /login`) и **получения JWT** (`POST /api/auth/token`).

| Переменная | По умолчанию | Описание |
|------------|--------------|----------|
| `LOGIN_MAX_ATTEMPTS` | 5 | Сколько неудачных попыток допускается |
| `LOGIN_ATTEMPT_WINDOW_MINUTES` | 15 | Окно подсчёта попыток (минуты) |
| `LOGIN_LOCKOUT_MINUTES` | 15 | Длительность блокировки после превышения лимита |

**Как работает:**

1. Каждая неудачная попытка записывается в таблицу `login_attempts` (IP + email).
2. Лимит проверяется **отдельно по IP и по email** — срабатывает более строгое условие.
3. После превышения порога в окне — блокировка на `LOGIN_LOCKOUT_MINUTES` с момента последней неудачной попытки.
4. Успешный вход сбрасывает счётчик для этого IP и email.
5. Клиент получает HTTP **429** и сообщение вида: «Слишком много попыток входа. Повторите через N мин.»

За nginx IP берётся из заголовка `X-Forwarded-For` (первый адрес в цепочке).

Требуется миграция `migrations/014_add_login_rate_limit.sql` (применяется автоматически через `./update.sh` или вручную — см. выше).

После изменения `LOGIN_*` в `.env`: `docker compose restart web`.

### Диск и бинарные логи MySQL

В репозитории в файле `my.cnf` задано **`binlog_expire_logs_seconds = 604800`** (7 суток): бинарные логи MySQL 8 не копятся бесконечно и не забивают том Docker под `/var/lib/mysql`.

- После обновления `my.cnf` перезапустите только БД: `docker compose restart db`.
- Уже накопленные большие файлы `binlog.*` сами не исчезнут сразу — при нехватке места их можно один раз удалить из MySQL (данные таблиц панели не трогаются), из каталога проекта:
  ```bash
  docker compose exec db mysql -uroot -p"$(sed -n 's/^DB_ROOT_PASSWORD=//p' .env)" -e "PURGE BINARY LOGS BEFORE DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY);"
  ```
  Пароль root задаётся в `.env` как `DB_ROOT_PASSWORD`.

---

## Как пользоваться

### Добавление VPN‑сервера

1. Откройте раздел **Серверы → Добавить сервер**
2. Заполните: имя, IP хоста, SSH‑порт, логин и пароль
3. **(Опционально) импорт из существующей панели:**
   - Отметьте «Импортировать из существующей панели»
   - Выберите тип панели (`wg-easy` или `3x-ui`)
   - Загрузите JSON‑бэкап
4. Нажмите **Создать сервер**
5. Дождитесь завершения деплоя
6. Если включён импорт, клиенты будут подтянуты автоматически

### Создание клиента

1. Откройте страницу нужного сервера
2. Укажите имя клиента
3. Выберите **срок действия** (опционально, по умолчанию — бессрочно)
4. Выберите **лимит трафика** (опционально, по умолчанию — безлимит)
5. Нажмите **Создать клиента**
6. Скачайте конфиг или отсканируйте QR‑код в приложении Amnezia

---

## Срок действия клиента

Через UI или API:

```bash
# Установить конкретную дату
curl -X POST http://localhost:8082/api/clients/123/set-expiration \
  -H "Authorization: Bearer <token>" \
  -d '{"expires_at": "2025-12-31 23:59:59"}'

# Продлить на 30 дней
curl -X POST http://localhost:8082/api/clients/123/extend \
  -H "Authorization: Bearer <token>" \
  -d '{"days": 30}'

# Получить клиентов, срок которых истечёт в течение 7 дней
curl http://localhost:8082/api/clients/expiring?days=7 \
  -H "Authorization: Bearer <token>"
```

### Лимиты трафика

Установка и проверка через API:

```bash
# Установить лимит трафика (10 ГБ = 10737418240 байт)
curl -X POST http://localhost:8082/api/clients/123/set-traffic-limit \
  -H "Authorization: Bearer <token>" \
  -d '{"limit_bytes": 10737418240}'

# Убрать лимит (сделать безлимитным)
curl -X POST http://localhost:8082/api/clients/123/set-traffic-limit \
  -H "Authorization: Bearer <token>" \
  -d '{"limit_bytes": null}'

# Проверить состояние лимита
curl http://localhost:8082/api/clients/123/traffic-limit-status \
  -H "Authorization: Bearer <token>"

# Получить список клиентов, превысивших лимит
curl http://localhost:8082/api/clients/overlimit \
  -H "Authorization: Bearer <token>"
```

---

## Резервные копии серверов

Через API:

```bash
# Создать бэкап
curl -X POST http://localhost:8082/api/servers/1/backup \
  -H "Authorization: Bearer <token>"

# Список бэкапов
curl http://localhost:8082/api/servers/1/backups \
  -H "Authorization: Bearer <token>"

# Восстановление из бэкапа
curl -X POST http://localhost:8082/api/servers/1/restore \
  -H "Authorization: Bearer <token>" \
  -d '{"backup_id": 123}'
```

---

## Автоматический мониторинг и метрики

Сборщик метрик запускается **автоматически** при старте контейнера и контролируется cron каждые 3 минуты.  
Если процесс падает, он будет перезапущен.

Логи сборщика метрик:

```bash
docker compose exec web tail -f /var/log/metrics_collector.log
```

Логи мониторинга:

```bash
docker compose exec web tail -f /var/log/metrics_monitor.log
```

Перезапуск сборщика вручную:

```bash
docker compose exec web pkill -f collect_metrics.php
# В течение ~3 минут мониторинг поднимет его снова
```

### Проверка сроков действия клиентов (cron)

Каждый час в контейнере выполняется скрипт, отключающий просроченных клиентов.

```bash
docker compose exec web tail -f /var/log/cron.log

docker compose exec web php /var/www/html/bin/check_expired_clients.php
```

### Проверка лимитов трафика (cron)

Каждый час отключаются клиенты, превысившие лимит:

```bash
docker compose exec web tail -f /var/log/cron.log

docker compose exec web php /var/www/html/bin/check_traffic_limits.php
```

---

## Аутентификация API (JWT)

Получение JWT‑токена:

```bash
curl -X POST http://localhost:8082/api/auth/token \
  -d "email=admin@amnez.ia&password=admin123"
```

При превышении лимита неудачных попыток API вернёт **429** с текстом ошибки (см. раздел «Защита от брутфорса»).  
Эндпоинт проверяет только локальный пароль в БД; LDAP‑пользователи входят через веб‑форму `/login`.

Использование токена:

```bash
curl -H "Authorization: Bearer <token>" \
  http://localhost:8082/api/servers
```

### Основные эндпоинты API

**Аутентификация**

```text
POST   /api/auth/token              - получить JWT‑токен
POST   /api/tokens                  - создать постоянный API‑токен
GET    /api/tokens                  - список API‑токенов
DELETE /api/tokens/{id}             - отозвать токен
```

**Серверы**

```text
GET    /api/servers                 - список серверов пользователя [x]
POST   /api/servers/create          - создать сервер [x]
        Параметры: name, host, port, username, password
DELETE /api/servers/{id}/delete     - удалить сервер [x]
GET    /api/servers/{id}/clients    - список клиентов на сервере [x]
```

**Клиенты**

```text
GET    /api/clients                 - все клиенты пользователя
GET    /api/clients/{id}/details    - детали клиента + статы + конфиг + QR [x]
GET    /api/clients/{id}/qr         - только QR‑код [x]
POST   /api/clients/create          - создать клиента (возвращает конфиг и QR) [x]
        Параметры: server_id, name, expires_in_days (опционально)
POST   /api/clients/{id}/revoke     - отозвать доступ [x]
POST   /api/clients/{id}/restore    - восстановить доступ [x]
DELETE /api/clients/{id}/delete     - удалить клиента [x]
POST   /api/clients/{id}/set-expiration  - установить дату окончания
        Параметры: expires_at (Y-m-d H:i:s или null)
POST   /api/clients/{id}/extend     - продлить срок
        Параметры: days (int)
GET    /api/clients/expiring        - клиенты, у которых скоро истечёт срок
        Параметры: days (по умолчанию 7)
POST   /api/clients/{id}/set-traffic-limit  - задать лимит трафика
        Параметры: limit_bytes (int или null)
GET    /api/clients/{id}/traffic-limit-status - состояние лимита
GET    /api/clients/overlimit       - клиенты, превысившие лимит
```

**Бэкапы**

```text
POST   /api/servers/{id}/backup     - создать бэкап сервера
GET    /api/servers/{id}/backups    - список бэкапов
POST   /api/servers/{id}/restore    - восстановиться из бэкапа (backup_id)
DELETE /api/backups/{id}            - удалить бэкап
```

**Импорт из панелей**

```text
POST   /api/servers/{id}/import     - импорт клиентов из другой панели
        Параметры: panel_type (wg-easy|3x-ui), backup_file (multipart/form-data)
GET    /api/servers/{id}/imports    - история импортов
```

---

## Переводы интерфейса

Можно перевести панель на другие языки:

```bash
docker compose exec web php bin/translate_all.php
```

Или через веб‑интерфейс: **Настройки → Авто‑перевод**.

---

## Структура проекта

```text
public/index.php      - маршруты и входная точка
inc/                  - основные классы
  Auth.php            - аутентификация (локальная + LDAP)
  LoginRateLimit.php  - защита от брутфорса при входе
  DB.php              - подключение к БД
  Router.php          - роутинг
  View.php            - шаблоны Twig
  VpnServer.php       - управление серверами
  VpnClient.php       - управление клиентами
  LdapSync.php        - синхронизация с LDAP
  Translator.php      - переводы
  JWT.php             - JWT‑аутентификация
  QrUtil.php          - генерация QR‑кодов Amnezia
  PanelImporter.php   - импорт из wg-easy / 3x-ui
  ServerMonitoring.php - сбор метрик серверов
templates/            - шаблоны Twig
migrations/           - SQL‑миграции (при первом старте БД и через update.sh)
nginx/                - reverse proxy, HTTPS, certbot
update.sh             - автоматическое обновление с бэкапом и миграциями
LDAP_SETUP.md         - настройка LDAP
```

---

## Безопасность

| Механизм | Описание |
|----------|----------|
| Пароли | `password_hash` / `password_verify` (bcrypt) |
| SQL | подготовленные выражения (PDO) |
| XSS | автоэкранирование Twig |
| Роли | `admin` / `user` |
| Брутфорс | `LoginRateLimit` — см. раздел «Защита от брутфорса» |
| LDAP | опционально, см. `LDAP_SETUP.md` |

Рекомендуется дополнительно: CSRF‑токены для форм, заголовки безопасности (CSP, HSTS).

---

## Технологии

- PHP 8.2
- MySQL 8.0
- Twig 3
- Tailwind CSS
- Docker

---

## Лицензия и авторство

- Код распространяется по лицензии **MIT** (см. файл `LICENSE`).
- Оригинальный проект и автор:  
  - Статья на Хабре: https://habr.com/ru/articles/964144/  
  - Репозиторий: https://github.com/infosave2007/amneziavpnphp  
  - Telegram‑канал проекта: https://t.me/amneziavpnphp

Этот форк: https://github.com/Ivan-Zolotarev/AmneziaVPNphp.git

