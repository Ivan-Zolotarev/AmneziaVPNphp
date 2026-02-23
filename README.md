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
- Аутентификация пользователей и контроль доступа
- **Автоматическая проверка сроков действия и лимитов трафика** по cron

### Требования

- Docker
- Docker Compose

### Установка

```bash
git clone https://github.com/Ivan-Zolotarev/AmneziaVPNphp.git
cd AmneziaVPNphp
cp .env.example .env

# Docker Compose V2 (рекомендуется)
docker compose up -d
docker compose exec web composer install

# Старый Docker Compose V1
docker-compose up -d
docker-compose exec web composer install
```

Панель будет доступна по адресу: `http://localhost:8082`

Логин по умолчанию: `admin@amnez.ia` / `admin123`  
**Обязательно измените пароль после первого входа.**

### Настройка (`.env`)

```env
DB_HOST=db
DB_PORT=3306
DB_DATABASE=amnezia_panel
DB_USERNAME=amnezia
DB_PASSWORD=amnezia123

ADMIN_EMAIL=admin@amnez.ia
ADMIN_PASSWORD=admin123

JWT_SECRET=your-secret-key-change-this
```

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
GET    /api/servers                 - список серверов пользователя
POST   /api/servers/create          - создать сервер
        Параметры: name, host, port, username, password
DELETE /api/servers/{id}/delete     - удалить сервер
GET    /api/servers/{id}/clients    - список клиентов на сервере
```

**Клиенты**

```text
GET    /api/clients                 - все клиенты пользователя
GET    /api/clients/{id}/details    - детали клиента + статы + конфиг + QR
GET    /api/clients/{id}/qr         - только QR‑код
POST   /api/clients/create          - создать клиента (возвращает конфиг и QR)
        Параметры: server_id, name, expires_in_days (опционально)
POST   /api/clients/{id}/revoke     - отозвать доступ
POST   /api/clients/{id}/restore    - восстановить доступ
DELETE /api/clients/{id}/delete     - удалить клиента
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
  Auth.php            - аутентификация
  DB.php              - подключение к БД
  Router.php          - роутинг
  View.php            - шаблоны Twig
  VpnServer.php       - управление серверами
  VpnClient.php       - управление клиентами
  Translator.php      - переводы
  JWT.php             - JWT‑аутентификация
  QrUtil.php          - генерация QR‑кодов Amnezia
  PanelImporter.php   - импорт из wg-easy / 3x-ui
templates/            - шаблоны Twig
migrations/           - SQL‑миграции (выполняются в алфавитном порядке)
```

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

