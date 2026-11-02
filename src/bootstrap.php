<?php
declare(strict_types=1);

namespace Smbind;

use PDO;
use RuntimeException;

if (PHP_VERSION_ID < 80200) {
    throw new RuntimeException('SMBind requires PHP 8.2 or newer.');
}
if (!defined('PASSWORD_ARGON2ID') || !in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
    throw new RuntimeException('SMBind requires PHP with Argon2id password hashing support.');
}

require_once __DIR__ . '/Dns.php';
require_once __DIR__ . '/Publisher.php';
require_once __DIR__ . '/Ui.php';

function setting(string $key, ?string $fallback = null): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($fallback === null) {
            throw new RuntimeException('Missing required environment setting: ' . $key);
        }
        return $fallback;
    }
    return $value;
}

function db(): PDO
{
    static $database = null;
    if ($database instanceof PDO) {
        return $database;
    }
    $database = new PDO(
        setting('SMBIND_DSN'),
        setting('SMBIND_DB_USER'),
        setting('SMBIND_DB_PASS'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $database;
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('smbind_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https || setting('SMBIND_SECURE_COOKIES', '0') === '1',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function assertCsrf(): void
{
    if (!isset($_POST['_token']) || !is_string($_POST['_token']) ||
        !hash_equals(csrf(), $_POST['_token'])) {
        throw new \InvalidArgumentException('Your session token is invalid. Reload the page and try again.');
    }
}

function e(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function route(string $route, array $query = []): string
{
    return '/?route=' . rawurlencode($route) . ($query === [] ? '' : '&' . http_build_query($query));
}

function redirect(string $route, array $query = []): never
{
    header('Location: ' . route($route, $query), true, 303);
    exit;
}

function flash(string $kind, string $message): void
{
    $_SESSION['flash'] = ['kind' => $kind, 'message' => $message];
}

function user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireUser(): array
{
    $user = user();
    if (!$user) {
        redirect('login');
    }
    return $user;
}

function canEdit(array $zone): bool
{
    $user = requireUser();
    return $user['role'] === 'admin' ||
        ($user['role'] === 'editor' && (int) $zone['owner_id'] === (int) $user['id']);
}

function requireAdmin(): void
{
    if (requireUser()['role'] !== 'admin') {
        throw new \RuntimeException('Administrator access is required.', 403);
    }
}

function query(string $sql, array $params = []): \PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function one(string $sql, array $params = []): ?array
{
    $value = query($sql, $params)->fetch();
    return $value === false ? null : $value;
}

function zone(int $id): array
{
    $result = one('SELECT z.*, u.username AS owner_name FROM zones z JOIN users u ON u.id = z.owner_id WHERE z.id = ?', [$id]);
    if (!$result) {
        throw new \RuntimeException('Zone was not found.', 404);
    }
    return $result;
}

function audit(string $action, ?int $zoneId = null, array $detail = []): void
{
    query(
        'INSERT INTO audit_log (user_id, zone_id, action, detail, ip_address) VALUES (?, ?, ?, ?, ?)',
        [
            user()['id'] ?? null, $zoneId, $action,
            json_encode($detail, JSON_THROW_ON_ERROR),
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45),
        ]
    );
}
