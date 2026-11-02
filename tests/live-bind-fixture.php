<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('SMBIND_CI_DATABASE') !== 'smbind_ci' ||
    !str_contains((string) getenv('SMBIND_DSN'), 'dbname=smbind_ci')) {
    fwrite(STDERR, "Refusing to touch a non-CI database.\n");
    exit(2);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use function Smbind\db;
use function Smbind\one;
use function Smbind\query;

$action = $argv[1] ?? '';
if (!in_array($action, ['create', 'modify', 'delete'], true)) {
    fwrite(STDERR, "Usage: php tests/live-bind-fixture.php create|modify|delete\n");
    exit(2);
}

$name = 'live-ci.example.test';
switch ($action) {
    case 'create':
        $user = one("SELECT id FROM users WHERE username='ciadmin'");
        if (!$user) {
            throw new RuntimeException('Missing CI administrator; execute tests/integration.php first.');
        }
        query('INSERT INTO zones (name,owner_id,primary_ns,contact,serial) VALUES (?,?,?,?,?)',
            [$name, $user['id'], 'ns1.live-ci.example.test', 'hostmaster.live-ci.example.test', 1]);
        $zoneId = (int) db()->lastInsertId();
        query('INSERT INTO records (zone_id,host,type,value,ttl) VALUES (?,?,?,?,?)',
            [$zoneId, '@', 'A', '192.0.2.81', 60]);
        query('INSERT INTO records (zone_id,host,type,value,ttl) VALUES (?,?,?,?,?)',
            [$zoneId, 'ns1', 'A', '192.0.2.81', 60]);
        break;
    case 'modify':
        $zone = one('SELECT id FROM zones WHERE name=?', [$name]);
        if (!$zone) {
            throw new RuntimeException('Live test zone missing.');
        }
        query('UPDATE records SET value=? WHERE zone_id=?', ['192.0.2.82', $zone['id']]);
        query('UPDATE zones SET dirty=1,revision=revision+1 WHERE id=?', [$zone['id']]);
        break;
    case 'delete':
        query('DELETE FROM zones WHERE name=?', [$name]);
        break;
}
