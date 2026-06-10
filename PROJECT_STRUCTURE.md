# Структура проекта

Полная структура файлов Amnezia VPN Web Panel с пояснениями.

```text
amnezia-web-panel/
│
├── 📄 README.md                    # Основная документация
├── 📄 PROJECT_STRUCTURE.md         # Описание структуры проекта
├── 📄 DEVELOPER.md                 # Гайд для разработчиков
├── 📄 API_EXAMPLES.md              # Примеры работы с API
├── 📄 LDAP_SETUP.md                # Настройка LDAP/AD
├── 📄 LICENSE                      # Лицензия MIT
├── 📄 .gitignore                   # Исключения для git
├── 📄 .env.example                 # Пример настроек окружения
│
├── 🐳 Docker
│   ├── docker-compose.yml          # Оркестрация контейнеров (web + db)
│   ├── Dockerfile                  # Образ PHP 8.2 + Apache
│   └── apache.conf                 # Конфигурация виртуального хоста Apache
│
├── 📦 Зависимости
│   └── composer.json               # PHP‑зависимости
│
├── 💾 База данных
│   └── migrations/                 # SQL‑миграции
│       ├── 000_create_user.sql
│       ├── 001_init.sql            # Основная схема (users, vpn_servers, vpn_clients и т.д.)
│       ├── 002_translations_ru.sql # Переводы на русский
│       └── ...                     # Дополнительные миграции
│
├── 🎨 Публичная часть (Frontend)
│   └── public/
│       ├── index.php               # Точка входа и роутер
│       └── .htaccess               # ЧПУ и проксирование на index.php
│
├── 🧩 Backend (ядро)
│   └── inc/
│       ├── Config.php              # Загрузка `.env`, общие настройки
│       ├── DB.php                  # Соединение с MySQL (PDO)
│       ├── Auth.php                # Авторизация, сессии, роли
│       ├── Router.php              # Простой роутер (GET/POST + плейсхолдеры)
│       ├── View.php                # Обёртка над Twig
│       ├── VpnServer.php           # Работа с VPN‑серверами (деплой, бэкапы)
│       ├── VpnClient.php           # Работа с клиентами (конфиги, QR, лимиты)
│       ├── Translator.php          # Система переводов
│       ├── JWT.php                 # JWT‑аутентификация для API
│       ├── QrUtil.php              # Кодирование конфигов в формат QR Amnezia
│       ├── PanelImporter.php       # Импорт из wg-easy / 3x-ui
│       ├── ServerMonitoring.php    # Метрики серверов/клиентов
│       └── LdapSync.php            # Синхронизация пользователей по LDAP/AD
│
├── 🖼️ Шаблоны (Twig)
│   └── templates/
│       ├── layout.twig             # Базовый макет (шапка, меню, уведомления)
│       ├── login.twig              # Страница логина
│       ├── register.twig           # Регистрация
│       ├── dashboard.twig          # Главная панель пользователя
│       ├── servers/
│       │   ├── index.twig          # Список серверов
│       │   ├── create.twig         # Форма добавления сервера
│       │   ├── deploy.twig         # Процесс деплоя (AJAX‑лог)
│       │   ├── view.twig           # Карточка сервера + клиенты
│       │   └── monitoring.twig     # Графики и метрики сервера
│       └── clients/
│           └── view.twig           # Карточка клиента, конфиг и QR‑код
│
└── 🧪 Тесты / утилиты
    ├── test_qr.php                 # Проверка генерации QR‑кодов
    └── examples/                   # Примеры использования API/конфигов
```

---

## Ключевые файлы

### Корень репозитория

- **`README.md`** — быстрый старт, установка, базовое использование панели.
- **`PROJECT_STRUCTURE.md`** — этот файл; обзор архитектуры.
- **`DEVELOPER.md`** — как поднять окружение разработчика, где какие слои кода.
- **`API_EXAMPLES.md`** — готовые запросы curl/Python/JS к REST API.
- **`LDAP_SETUP.md`** — как подключить LDAP/Active Directory.
- **`.env.example`** — шаблон переменных окружения (без секретов).
- **`.gitignore`** — исключает `.env`, `vendor/`, `db_data/`, логи и прочие артефакты.

### Docker

- **`docker-compose.yml`**
  - `web` — контейнер с PHP 8.2 + Apache, монтирует проект; снаружи только `127.0.0.1:8082`.
  - `nginx` — reverse proxy, порты 80/443; certbot внутри образа для Let's Encrypt.
  - `db` — контейнер MySQL 8.0, данные в томе `db_data`, при первом старте накатывает миграции.

- **`Dockerfile`**
  - Базовый образ `php:8.2-apache`
  - Установка расширений: `pdo_mysql`, `gd`, `sodium`, `curl`, `mbstring` и др.
  - Установка Composer
  - Включение `mod_rewrite`
  - Настройка cron и скриптов для проверок лимитов/сроков действия.

---

## База данных

Основная миграция `migrations/001_init.sql` создаёт таблицы:

1. **`users`** — пользователи панели (email, пароль, роль, язык, статус).
2. **`vpn_servers`** — VPN‑серверы (хост, SSH‑параметры, контейнер, порт, ключи, AWG‑параметры).
3. **`vpn_clients`** — клиенты (сервер, пользователь, IP, ключи, конфиг, QR, статы, срок действия).
4. **`api_tokens`** — постоянные API‑токены.
5. **`settings`** — общие настройки панели (ключ‑значение).
6. **`languages`, `translations`** — система переводов интерфейса.
7. **`server_backups`** — метаданные бэкапов серверов.
8. Дополнительные миграции добавляют:
   - `panel_imports` — история импортов из панелей.
   - `server_metrics`, `client_metrics` — метрики.
   - `ldap_configs`, `ldap_group_mappings` — настройки LDAP.

---

## Потоки данных

### Деплой VPN‑сервера

```text
Форма в UI → POST /servers/create
    ↓
VpnServer::create() — запись в БД (status = deploying)
    ↓
/servers/{id}/deploy — страница с логом
    ↓
VpnServer->deploy()
    ↓
SSH на удалённый VPS:
  - Проверка / установка Docker
  - Сборка образа amnezia-awg
  - Запуск контейнера с UDP‑портом
  - Генерация ключей
  - Генерация wg0.conf с AWG‑параметрами
  - Настройка iptables и NAT
    ↓
Обновление записи в БД (status = active, vpn_port, ключи)
```

### Создание клиента

```text
Форма на странице сервера → POST /servers/{id}/clients/create
    ↓
VpnClient::create($serverId, $userId, $name, $expiresInDays)
    ↓
Шаги:
  1. Чтение данных сервера из БД
  2. Генерация ключей клиента внутри контейнера (wg genkey/pub)
  3. Выбор следующего свободного IP из подсети
  4. Сборка WireGuard‑конфига с AWG‑параметрами
  5. Добавление peer в wg0.conf + wg syncconf
  6. Генерация QR‑кода (QrUtil)
  7. Сохранение в БД
    ↓
Редирект на /clients/{id} — показ конфига и QR‑кода
```

### Генерация QR‑кода Amnezia

```text
WireGuard config text
    ↓
QrUtil::encodeOldPayloadFromConf($config)
    ↓
Парсинг конфига:
  - секция [Interface]
  - секции [Peer]
  - AWG‑поля (H1–H4, Jc, Jmin, Jmax, S1, S2)
    ↓
Формирование JSON‑обёртки (containers[], awg, dns, hostName и т.д.)
    ↓
gzcompress(JSON, level 9)
    ↓
Добавление заголовка (версия, длины)
    ↓
URL‑safe Base64
    ↓
QrUtil::pngBase64($payload) → data:image/png;base64,...
```

---

## Зависимости

### PHP (Composer)

```json
{
  "require": {
    "php": ">=8.0",
    "twig/twig": "^3.8",
    "endroid/qr-code": "^5.0",
    "ext-pdo": "*",
    "ext-json": "*",
    "ext-curl": "*",
    "ext-gd": "*",
    "ext-sodium": "*"
  }
}
```

### Среда (Docker)

- PHP 8.2 + Apache 2.4
- MySQL 8.0
- `sshpass` для SSH‑деплоя
- cron внутри контейнера `web`

---

## Безопасность и эксплуатация

Реализовано:

- Хранение паролей через `password_hash` (bcrypt)
- Подготовленные выражения (PDO) против SQL‑инъекций
- Автоэкранирование в Twig против XSS
- Роли пользователей (admin / user)

Рекомендуется доработать:

- CSRF‑защита (form tokens)
- Rate limiting для API
- Заголовки безопасности (CSP, HSTS и т.п.; HTTPS — nginx + certbot, см. README)

Мониторинг и резервное копирование описаны в `README.md` (разделы Monitoring / Backup & Recovery).

