<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use function Smbind\db;
use function Smbind\query;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if ($argc !== 2 || !preg_match('/^[A-Za-z0-9_.-]{3,80}$/D', $argv[1])) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username>\nSupply the new password on standard input (not on the command line).\n");
    exit(2);
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if (strlen($password) < 14 || strlen($password) > 1024) {
    fwrite(STDERR, "Password must contain at least 14 characters (max 1024).\n");
    exit(2);
}
try {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
    $stmt->execute([$argv[1], $hash, 'admin']);
    fwrite(STDOUT, "Administrator created: " . $argv[1] . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Could not create administrator: " . $error->getMessage() . "\n");
    exit(1);
}
