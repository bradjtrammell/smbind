<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('SMBIND_CI_DATABASE') !== 'smbind_ci' ||
    !str_contains((string) getenv('SMBIND_DSN'), 'dbname=smbind_ci')) {
    fwrite(STDERR, "Refusing to test upgrades outside the disposable smbind_ci database.\n");
    exit(2);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = \Smbind\db();
$before = $pdo->query('SELECT id,username,role,password_hash FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if (count($before) < 2) {
    throw new RuntimeException('Expected HTTP fixtures to create multiple user accounts.');
}
$oldZoneCount = (int) $pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn();
$oldRecordCount = (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn();

// Simulate the only v1.0.0-dev.2 -> dev.3 schema delta after
// creating actual user accounts and DNS records.
$pdo->exec('ALTER TABLE users DROP COLUMN is_active');
$columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'")->fetchAll();
if ($columns !== []) {
    throw new RuntimeException('Could not construct pre-upgrade users schema.');
}
$sql = file_get_contents(dirname(__DIR__) . '/db/upgrade-dev2-to-dev3.sql');
if ($sql === false) {
    throw new RuntimeException('Database upgrade SQL missing.');
}
$pdo->exec($sql);
$after = $pdo->query('SELECT id,username,role,password_hash,is_active FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

if (count($before) !== count($after)) {
    throw new RuntimeException('User rows lost during schema upgrade.');
}
foreach ($before as $index => $original) {
    $upgraded = $after[$index];
    foreach ($original as $column => $value) {
        if ($upgraded[$column] !== $value) {
            throw new RuntimeException('Upgrade altered an existing user column: ' . $column);
        }
    }
    if ((int) $upgraded['is_active'] !== 1) {
        throw new RuntimeException('Existing users did not retain default active status.');
    }
}
if ((int) $pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn() !== $oldZoneCount ||
    (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn() !== $oldRecordCount) {
    throw new RuntimeException('Upgrade changed DNS record or zone counts.');
}
echo "PASS: dev.2 -> dev.3 schema upgrade preserved " . count($after) . " accounts, " .
    $oldZoneCount . " zones and " . $oldRecordCount . " records.\n";
