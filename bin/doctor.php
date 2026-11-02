<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Smbind\Dns;
use function Smbind\db;
use function Smbind\setting;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "SMBind preflight must run from PHP CLI.\n");
    exit(2);
}

$failures = 0;
$warnings = 0;
$report = static function (bool $ok, string $what, bool $warning = false) use (&$failures, &$warnings): void {
    if ($ok) {
        fwrite(STDOUT, "[PASS] " . $what . "\n");
        return;
    }
    if ($warning) {
        $warnings++;
        fwrite(STDOUT, "[WARN] " . $what . "\n");
    } else {
        $failures++;
        fwrite(STDERR, "[FAIL] " . $what . "\n");
    }
};

echo "SMBind deployment preflight — non-destructive\n\n";
$report(PHP_VERSION_ID >= 80200, "PHP >= 8.2 (" . PHP_VERSION . ")");
foreach (['pdo_mysql', 'session', 'filter', 'json'] as $extension) {
    $report(extension_loaded($extension), "PHP extension: " . $extension);
}

$compiled = dirname(__DIR__) . '/public/assets/app.css';
$report(is_file($compiled) && filesize($compiled) > 1000, 'Compiled Tailwind stylesheet: public/assets/app.css');

foreach (['SMBIND_DSN', 'SMBIND_DB_USER', 'SMBIND_DB_PASS', 'SMBIND_ZONE_DIR', 'SMBIND_INCLUDE_FILE', 'SMBIND_NAMED_CONF'] as $key) {
    $value = getenv($key);
    $report($value !== false && $value !== '', 'Environment variable configured: ' . $key);
}

$zoneDir = getenv('SMBIND_ZONE_DIR') ?: '';
$include = getenv('SMBIND_INCLUDE_FILE') ?: '';
$namedConf = getenv('SMBIND_NAMED_CONF') ?: '';
$publicRoot = realpath(dirname(__DIR__) . '/public') ?: '';

$outsideWebroot = static function (string $path) use ($publicRoot): bool {
    if ($path === '' || !str_starts_with($path, '/')) {
        return false;
    }
    $absolute = realpath($path) ?: realpath(dirname($path)) ?: '';
    return $absolute !== '' && $publicRoot !== '' &&
        $absolute !== $publicRoot && !str_starts_with($absolute . '/', $publicRoot . '/');
};

$report($zoneDir !== '' && is_dir($zoneDir) && is_writable($zoneDir), 'Writable DNS zone output directory');
$report($zoneDir !== '' && $outsideWebroot($zoneDir), 'Zone files outside HTTP document root');
$report($include !== '' && is_file($include) && is_readable($include), 'BIND managed include file exists and is readable');
$report($include !== '' && is_writable(dirname($include)), 'Managed BIND include directory writable for atomic replacement');
$report($include !== '' && $outsideWebroot($include), 'BIND include outside HTTP document root');
$report($namedConf !== '' && is_file($namedConf) && is_readable($namedConf), 'BIND main configuration exists and is readable');
if ($namedConf !== '' && is_readable($namedConf) && $include !== '') {
    $text = file_get_contents($namedConf);
    $report($text !== false && str_contains($text, 'include "' . $include . '"'),
        'BIND main configuration references the managed include file', true);
}

foreach ([
    'SMBIND_NAMED_CHECKZONE' => '/usr/sbin/named-checkzone',
    'SMBIND_NAMED_CHECKCONF' => '/usr/sbin/named-checkconf',
    'SMBIND_RNDC' => '/usr/sbin/rndc',
] as $key => $default) {
    $command = getenv($key) ?: $default;
    $report(is_file($command) && is_executable($command), 'Executable: ' . $key . ' (' . $command . ')');
}

try {
    $connection = db();
    $report($connection->query('SELECT 1')->fetchColumn() == 1, 'PDO MySQL connectivity');
    foreach (['users', 'zones', 'records', 'audit_log', 'published_zones'] as $table) {
        $statement = $connection->query('SELECT COUNT(*) FROM ' . $table);
        $report($statement !== false, 'Database table accessible: ' . $table);
    }
} catch (Throwable $error) {
    $report(false, 'Database connectivity/schema: ' . $error->getMessage());
}

$report((getenv('SMBIND_SECURE_COOKIES') ?: '0') === '1',
    'Secure session cookies forced (required for HTTPS)', true);

echo "\nPreflight: " . $failures . " error(s), " . $warnings . " warning(s).\n";
exit($failures === 0 ? 0 : 1);
