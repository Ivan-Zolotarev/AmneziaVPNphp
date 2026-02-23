# Руководство разработчика (RU)

Этот документ описывает, как поднять окружение разработки для Amnezia VPN Web Panel, где лежат основные части кода и какие практики безопасности/код‑стайла используются.

Кратко:

- Для разработки можно использовать либо локальную установку PHP + MySQL, либо Docker (`docker compose up -d`).
- Входная точка — `public/index.php`, логика маршрутизации — в `inc/Router.php`, бизнес‑логика — в `inc/VpnServer.php`, `inc/VpnClient.php` и других файлах в `inc/`.
- Шаблоны интерфейса находятся в `templates/` и рендерятся через `inc/View.php` (Twig).
- Подключение к базе данных инкапсулировано в `inc/DB.php`.
- За авторизацию и роли отвечает `inc/Auth.php`.
- REST API добавляется через маршруты в `public/index.php` и использует JWT (`inc/JWT.php`).
- Обязательно использовать подготовленные выражения (PDO), экранирование вывода и bcrypt для паролей.

Ниже приведена оригинальная англоязычная версия с более подробными примерами и кодом.

---

# Developer Guide

Guide for developers contributing to Amnezia VPN Web Panel.

## Development Setup

### Local Development (without Docker)

1. **Install PHP 8.2+**
```bash
# Ubuntu/Debian
sudo apt install php8.2 php8.2-cli php8.2-mysql php8.2-gd php8.2-curl php8.2-mbstring

# macOS (Homebrew)
brew install php@8.2
```

2. **Install MySQL 8.0**
```bash
# Ubuntu/Debian
sudo apt install mysql-server-8.0

# macOS
brew install mysql@8.0
```

3. **Install Composer**
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

4. **Clone and Setup**
```bash
git clone <repo-url>
cd amnezia-web-panel
composer install
```

5. **Configure Database**
```bash
mysql -u root -p

CREATE DATABASE amnezia_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'amnezia'@'localhost' IDENTIFIED BY 'amnezia123';
GRANT ALL PRIVILEGES ON amnezia_panel.* TO 'amnezia'@'localhost';
FLUSH PRIVILEGES;

USE amnezia_panel;
SOURCE migrations/001_init.sql;
SOURCE migrations/002_translations_ru.sql;
SOURCE migrations/003_translations_es.sql;
SOURCE migrations/004_translations_de.sql;
SOURCE migrations/005_translations_fr.sql;
SOURCE migrations/006_translations_zh.sql;
```

6. **Update Database Config**

Edit `inc/DB.php`:
```php
private static $config = [
    'host' => 'localhost',  // Change from 'db'
    'dbname' => 'amnezia_panel',
    'user' => 'amnezia',
    'password' => 'amnezia123',
    'charset' => 'utf8mb4',
];
```

7. **Run Development Server**
```bash
cd public
php -S localhost:8000
```

Access: `http://localhost:8000`

### Docker Development (Recommended)

```bash
docker compose up -d
```

Access: `http://localhost:8082`

**Live code editing**: Mount project as volume (already configured in docker-compose.yml)

## Project Architecture

### MVC Pattern

```
Request → Router → Controller Logic → Model → Database
                      ↓
                   View (Twig) → Response
```

### Core Components

#### 1. Router (`inc/Router.php`)

Simple pattern-matching router:

```php
Router::get('/path/{param}', function($params) {
    // Handler logic
    echo $params['param'];
});

Router::post('/form', function() {
    // Handle POST
    $data = $_POST['field'];
});
```

#### 2. Database (`inc/DB.php`)

Singleton PDO connection:

```php
$pdo = DB::conn();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();
```

#### 3. Authentication (`inc/Auth.php`)

Session-based auth:

```php
// Login
Auth::login($email, $password);

// Get current user
$user = Auth::user();

// Check admin
if (Auth::isAdmin()) {
    // Admin logic
}

// Logout
Auth::logout();

// Middleware
requireAuth(); // In route handler
```

#### 4. Views (`inc/View.php`)

Twig template rendering:

```php
View::render('template.twig', [
    'var1' => 'value1',
    'var2' => 'value2',
]);
```

#### 5. Models

**VpnServer** (`inc/VpnServer.php`):
```php
// Create and deploy server
$serverId = VpnServer::create($userId, $name, $host, $port, $username, $password);

// Get server instance
$server = new VpnServer($serverId);
$data = $server->getData();

// Deploy to remote server
$server->deploy();

// List servers
$servers = VpnServer::listAll();
$userServers = VpnServer::listByUser($userId);
```

**VpnClient** (`inc/VpnClient.php`):
```php
// Create client
$clientId = VpnClient::create($serverId, $userId, $name);

// Get client instance
$client = new VpnClient($clientId);
$config = $client->getConfig();
$qrCode = $client->getQRCode();

// List clients
$clients = VpnClient::listByServer($serverId);
$userClients = VpnClient::listByUser($userId);
```

#### 6. QR Code Utility (`inc/QrUtil.php`)

Amnezia-compatible QR encoding:

```php
require_once 'inc/QrUtil.php';

// From WireGuard config text
$payload = QrUtil::encodeOldPayloadFromConf($configText);

// Generate PNG data URI
$qrImage = QrUtil::pngBase64($payload);

// Use in template
echo '<img src="' . $qrImage . '">';
```

## Adding New Features

### Example: Add Server Statistics

**1. Add database column**

Create migration `migrations/002_add_stats.sql`:
```sql
ALTER TABLE vpn_servers ADD COLUMN stats_json TEXT;
```

**2. Add method to model**

Edit `inc/VpnServer.php`:
```php
public function getStats(): array {
    if (!$this->data['stats_json']) {
        return [];
    }
    return json_decode($this->data['stats_json'], true);
}

public function updateStats(): void {
    $stats = $this->collectStatsFromServer();
    
    $pdo = DB::conn();
    $stmt = $pdo->prepare('UPDATE vpn_servers SET stats_json = ? WHERE id = ?');
    $stmt->execute([json_encode($stats), $this->serverId]);
}

private function collectStatsFromServer(): array {
    // SSH to server, get stats
    // ...
    return ['cpu' => 45, 'memory' => 60, 'bandwidth' => 1024];
}
```

**3. Add route**

Edit `public/index.php`:
```php
Router::get('/servers/{id}/stats', function($params) {
    requireAuth();
    $serverId = (int)$params['id'];
    
    $server = new VpnServer($serverId);
    $stats = $server->getStats();
    
    header('Content-Type: application/json');
    echo json_encode($stats);
});
```

**4. Add template**

Create `templates/servers/stats.twig`:
```twig
{% extends "layout.twig" %}

{% block content %}
<div class="max-w-4xl mx-auto">
  <h1>Server Statistics</h1>
  
  <div class="grid grid-cols-3 gap-4">
    <div class="bg-white p-4 rounded shadow">
      <h3>CPU Usage</h3>
      <p class="text-3xl">{{ stats.cpu }}%</p>
    </div>
    <!-- More stats -->
  </div>
</div>
{% endblock %}
```

**5. Update navigation**

Edit `templates/layout.twig`:
```twig
<a href="/servers/{{ server.id }}/stats">Statistics</a>
```

## Code Style Guidelines

### PHP

Follow PSR-12 coding standard:

```php
<?php

namespace MyNamespace;

use AnotherNamespace\SomeClass;

class MyClass
{
    private string $property;
    
    public function __construct(string $param)
    {
        $this->property = $param;
    }
    
    public function method(int $arg): bool
    {
        if ($arg > 0) {
            return true;
        }
        
        return false;
    }
}
```

### SQL

```sql
-- Use uppercase keywords
SELECT id, name, created_at
FROM vpn_servers
WHERE status = 'active'
ORDER BY created_at DESC;

-- Prepared statements always
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
```

### JavaScript

```javascript
// Use modern ES6+
const fetchData = async () => {
  try {
    const response = await fetch('/api/servers');
    const data = await response.json();
    console.log(data);
  } catch (error) {
    console.error('Error:', error);
  }
};

// Event listeners
document.getElementById('btn').addEventListener('click', () => {
  fetchData();
});
```

### Twig

```twig
{# Comments #}

{# Variables #}
{{ variable }}
{{ object.property }}
{{ array[0] }}

{# Control structures #}
{% if condition %}
  Content
{% endif %}

{% for item in items %}
  {{ item.name }}
{% endfor %}

{# Filters #}
{{ text|upper }}
{{ html|raw }}  {# Careful with XSS! #}
```

## Security Best Practices

### 1. SQL Injection Prevention

❌ **Never do this:**
```php
$sql = "SELECT * FROM users WHERE email = '$email'";
```

✅ **Always use prepared statements:**
```php
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
```

### 2. XSS Prevention

❌ **Never output unescaped:**
```php
echo $_GET['name'];  // Dangerous!
```

✅ **Escape output:**
```php
echo htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8');
```

In Twig (auto-escapes by default):
```twig
{{ user_input }}  {# Safe #}
{{ user_input|raw }}  {# Unsafe - use carefully #}
```

### 3. CSRF Protection

TODO: Implement token-based CSRF protection:

```php
// Generate token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// In form
<input type="hidden" name="csrf_token" value="{{ csrf_token }}">

// Verify
if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die('CSRF token mismatch');
}
```

### 4. Password Hashing

✅ **Always use bcrypt:**
```php
// Hash
$hash = password_hash($password, PASSWORD_BCRYPT);

// Verify
if (password_verify($password, $hash)) {
    // Correct
}
```

### 5. Input Validation

```php
// Email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new Exception('Invalid email');
}

// Integer
$id = (int)$_GET['id'];

// String length
if (strlen($name) < 3 || strlen($name) > 50) {
    throw new Exception('Invalid name length');
}
```

## Testing

### Unit Tests (TODO)

```php
// tests/VpnServerTest.php
use PHPUnit\Framework\TestCase;

class VpnServerTest extends TestCase
{
    public function testCreate()
    {
        $serverId = VpnServer::create(1, 'Test', '192.168.1.1', 22, 'root', 'pass');
        $this->assertIsInt($serverId);
        $this->assertGreaterThan(0, $serverId);
    }
}
```

Run tests:
```bash
composer require --dev phpunit/phpunit
./vendor/bin/phpunit tests/
```

### Manual Testing

See [TESTING.md](TESTING.md) for comprehensive testing guide.

## Debugging

### Enable Error Display

In development, edit `public/index.php`:

```php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
```

### Database Queries

```php
// Enable query logging
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (PDOException $e) {
    error_log("SQL Error: " . $e->getMessage());
    error_log("Query: $sql");
    error_log("Params: " . print_r($params, true));
    throw $e;
}
```

### SSH Commands

```php
// Add debug output
$cmd = "your command";
error_log("Executing SSH command: $cmd");
$output = shell_exec($sshCmd);
error_log("SSH output: $output");
```

### Docker Logs

```bash
# Web container logs
docker compose logs -f web

# Database logs
docker compose logs -f db

# Last 100 lines
docker compose logs --tail=100 web
```

## API Development

### Adding New Endpoint

```php
// In public/index.php

Router::post('/api/clients', function() {
    // TODO: Verify JWT token
    
    header('Content-Type: application/json');
    
    try {
        $serverId = (int)$_POST['server_id'];
        $name = trim($_POST['name'] ?? '');
        
        if (!$serverId || !$name) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing parameters']);
            return;
        }
        
        $user = Auth::user();
        $clientId = VpnClient::create($serverId, $user['id'], $name);
        
        $client = new VpnClient($clientId);
        
        echo json_encode([
            'success' => true,
            'client' => $client->getData(),
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});
```

### JWT Authentication (TODO)

```php
use Firebase\JWT\JWT;

// Generate token
$payload = [
    'user_id' => $user['id'],
    'exp' => time() + 3600, // 1 hour
];
$token = JWT::encode($payload, $secretKey, 'HS256');

// Verify token
try {
    $decoded = JWT::decode($token, $secretKey, ['HS256']);
    $userId = $decoded->user_id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}
```

## Deployment

### Production Checklist

- [ ] Change default admin password
- [ ] Update database passwords in docker-compose.yml
- [ ] Set up HTTPS (nginx reverse proxy + Let's Encrypt)
- [ ] Disable error display
- [ ] Enable error logging
- [ ] Set up automated backups
- [ ] Configure firewall
- [ ] Set up monitoring
- [ ] Review security settings
- [ ] Test disaster recovery

### Environment Variables

Create `.env.production`:
```env
DB_HOST=db
DB_NAME=amnezia_panel
DB_USER=amnezia
DB_PASS=strong_random_password_here
JWT_SECRET=another_strong_random_secret_here
ADMIN_EMAIL=admin@yourdomain.com
```

Load in PHP:
```php
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dbPassword = $_ENV['DB_PASS'];
```

## Contributing

1. Fork repository
2. Create feature branch: `git checkout -b feature/my-feature`
3. Make changes
4. Write tests
5. Commit: `git commit -am 'Add my feature'`
6. Push: `git push origin feature/my-feature`
7. Create Pull Request

### Commit Message Format

```
type: subject

body (optional)

footer (optional)
```

Types:
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation
- `style`: Formatting
- `refactor`: Code restructuring
- `test`: Tests
- `chore`: Maintenance

Example:
```
feat: add server statistics dashboard

- Added stats collection via SSH
- Created stats API endpoint
- Built statistics template
- Updated navigation

Closes #123
```

## Resources

- [PHP Documentation](https://www.php.net/docs.php)
- [MySQL Reference](https://dev.mysql.com/doc/)
- [Twig Documentation](https://twig.symfony.com/doc/)
- [Tailwind CSS](https://tailwindcss.com/docs)
- [Docker Documentation](https://docs.docker.com/)
- [WireGuard Protocol](https://www.wireguard.com/)
- [Amnezia VPN GitHub](https://github.com/amnezia-vpn/amnezia-client)

---

Happy coding! 🚀
