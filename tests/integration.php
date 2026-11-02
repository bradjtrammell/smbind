<?php
declare(strict_types=1);

/**
 * Disposable MySQL + actual BIND validation integration suite.
 * RNDC is a local fake command; no production DNS process is contacted.
 */
if (PHP_SAPI !== 'cli' || getenv('SMBIND_CI_DATABASE') !== 'smbind_ci') {
    fwrite(STDERR, "Refusing to run outside the dedicated disposable CI database.\n");
    exit(2);
}
$dsn = (string) getenv('SMBIND_DSN');
if (!str_contains($dsn, 'dbname=smbind_ci')) {
    fwrite(STDERR, "SMBIND_DSN must name the disposable smbind_ci database.\n");
    exit(2);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Smbind\Dns;
use Smbind\Publisher;
use function Smbind\db;
use function Smbind\query;

$tested = 0;
$failed = 0;
function verify(bool $ok, string $name): void
{
    global $tested, $failed;
    $tested++;
    if (!$ok) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name}\n");
    }
}

$db = db();
$schema = (string) file_get_contents(dirname(__DIR__) . '/db/schema.sql');
foreach (explode(';', $schema) as $sql) {
    if (trim($sql) !== '') {
        $db->exec($sql);
    }
}
verify((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'Empty schema initialized');

$hash = password_hash('Test-only-administrator-password-123', PASSWORD_DEFAULT);
query('INSERT INTO users (username,password_hash,role) VALUES (?,?,?)', ['ciadmin', $hash, 'admin']);
verify(password_verify('Test-only-administrator-password-123', (string) $db->query('SELECT password_hash FROM users')->fetchColumn()), 'PDO account/password storage');

$user = (int) $db->query("SELECT id FROM users WHERE username='ciadmin'")->fetchColumn();
query(
    'INSERT INTO zones (name,owner_id,primary_ns,contact,serial) VALUES (?,?,?,?,?)',
    ['example.com', $user, 'ns1.example.com', 'hostmaster.example.com', 1]
);
$id = (int) $db->lastInsertId();
query('INSERT INTO records (zone_id,host,type,value,ttl,priority,weight,port) VALUES (?,?,?,?,?,?,?,?)',
    [$id, 'ns1', 'A', '192.0.2.10', 3600, 0, 0, 0]);
query('INSERT INTO records (zone_id,host,type,value,ttl,priority,weight,port) VALUES (?,?,?,?,?,?,?,?)',
    [$id, '@', 'A', '192.0.2.11', 3600, 0, 0, 0]);
query('INSERT INTO records (zone_id,host,type,value,ttl) VALUES (?,?,?,?,?)',
    [$id, '@', 'CAA', '0 issue "letsencrypt.org"', 3600]);
query('INSERT INTO records (zone_id,host,type,value,ttl) VALUES (?,?,?,?,?)',
    [$id, '_443._tcp.www', 'TLSA', '3 1 1 ' . str_repeat('ab', 32), 3600]);

$path = sys_get_temp_dir() . '/smbind-ci-' . bin2hex(random_bytes(6));
$zones = $path . '/zones';
$config = $path . '/config';
if (!mkdir($path, 0700) || !mkdir($zones, 0700) || !mkdir($config, 0700)) {
    throw new RuntimeException('Could not create test fixture directories.');
}
$include = $config . '/smbind-zones.conf';
$namedConf = $config . '/named.conf';
file_put_contents($include, '');
file_put_contents($namedConf, 'options { directory "' . $path . '"; recursion no; };' . "\n" .
    'include "' . $include . '";' . "\n");
$okR = $path . '/rndc-ok';
$badR = $path . '/rndc-fail';
file_put_contents($okR, "#!/bin/sh\nexit 0\n");
file_put_contents($badR, "#!/bin/sh\nexit 1\n");
chmod($okR, 0700);
chmod($badR, 0700);

$which = static function (string $command): string {
    $answer = trim((string) shell_exec('command -v ' . $command . ' 2>/dev/null'));
    if ($answer === '' || !is_executable($answer)) {
        throw new RuntimeException('Test requires BIND utility: ' . $command);
    }
    return $answer;
};
putenv('SMBIND_ZONE_DIR=' . $zones);
putenv('SMBIND_INCLUDE_FILE=' . $include);
putenv('SMBIND_NAMED_CONF=' . $namedConf);
putenv('SMBIND_NAMED_CHECKZONE=' . $which('named-checkzone'));
putenv('SMBIND_NAMED_CHECKCONF=' . $which('named-checkconf'));
putenv('SMBIND_RNDC=' . $okR);
$zoneFile = $zones . '/example.com.zone';

try {
    $publisher = new Publisher($db);

    // Pre-existing unmanaged files must never be silently overwritten.
    $foreign = "; External DNS manager owns this file\n";
    file_put_contents($zoneFile, $foreign);
    $blockedZone = false;
    try { $publisher->publish(); } catch (RuntimeException) { $blockedZone = true; }
    verify($blockedZone && file_get_contents($zoneFile) === $foreign, 'External zone files are protected');
    unlink($zoneFile);

    file_put_contents($include, '// Maintained by an external DNS tool');
    $blockedInclude = false;
    try { $publisher->publish(); } catch (RuntimeException) { $blockedInclude = true; }
    verify($blockedInclude && file_get_contents($include) === '// Maintained by an external DNS tool',
        'Unmanaged BIND include files are protected');
    file_put_contents($include, '');

    verify($publisher->publish() === 1, 'Valid zone publishes');
    verify(is_file($zoneFile), 'Zone file created');
    $original = (string) file_get_contents($zoneFile);
    verify(str_contains($original, 'ns1 3600 IN A 192.0.2.10'), 'Real BIND validates generated zone');
    verify(str_contains($original, 'CAA 0 issue "letsencrypt.org"'), 'BIND validates CAA');
    verify(str_contains($original, 'TLSA 3 1 1 ' . strtoupper(str_repeat('ab', 32))), 'BIND validates TLSA');
    verify((int) $db->query('SELECT dirty FROM zones')->fetchColumn() === 0, 'Successful publish clears dirty state');
    verify((int) $db->query('SELECT COUNT(*) FROM published_zones')->fetchColumn() === 1, 'Managed zone manifest saved');

    // A symlink replacing a previously managed file must not give the
    // publisher write access to an unrelated target, even if the manifest
    // claims that zone name.
    $saved = $zoneFile . '.saved-test';
    $unrelated = $path . '/unrelated.txt';
    file_put_contents($unrelated, 'Unrelated file must never change');
    if (!rename($zoneFile, $saved) || !symlink($unrelated, $zoneFile)) {
        throw new RuntimeException('Could not construct symlink regression fixture.');
    }
    $blockedLink = false;
    try {
        $publisher->publish();
    } catch (RuntimeException) {
        $blockedLink = true;
    } finally {
        unlink($zoneFile);
        rename($saved, $zoneFile);
    }
    verify($blockedLink && file_get_contents($unrelated) === 'Unrelated file must never change',
        'Symlink zone output is refused without modifying its target');
    unlink($unrelated);

    // Refuse to touch a managed zone whose previous contents cannot be read.
    chmod($zoneFile, 0000);
    $refusedBackup = false;
    try {
        $publisher->publish();
    } catch (RuntimeException) {
        $refusedBackup = true;
    } finally {
        chmod($zoneFile, 0640);
    }
    verify($refusedBackup && file_get_contents($zoneFile) === $original,
        'Unreadable managed zone backup causes fail-closed publication');


    query("UPDATE records SET value='192.0.2.50' WHERE zone_id=? AND host='ns1'", [$id]);
    query('UPDATE zones SET dirty=1, revision=revision+1 WHERE id=?', [$id]);
    putenv('SMBIND_RNDC=' . $badR);
    $failedToReload = false;
    try {
        $publisher->publish();
    } catch (RuntimeException) {
        $failedToReload = true;
    }
    verify($failedToReload, 'RNDC failure detected');
    verify(file_get_contents($zoneFile) === $original, 'Failed reload restores previously published file');
    verify((int) $db->query('SELECT dirty FROM zones')->fetchColumn() === 1, 'Failed reload leaves zone pending');
    putenv('SMBIND_RNDC=' . $okR);
    verify($publisher->publish() === 1, 'Retry after failure succeeds');
    verify(str_contains((string) file_get_contents($zoneFile), 'ns1 3600 IN A 192.0.2.50'), 'Retry publishes new DNS content');

    query('DELETE FROM zones WHERE id=?', [$id]);
    verify($publisher->publish() === 0, 'Empty authoritative inventory publishes');
    verify(!file_exists($zoneFile), 'Removed zone file cleaned up through managed manifest');
    verify((int) $db->query('SELECT COUNT(*) FROM published_zones')->fetchColumn() === 0, 'Deleted zone removed from publication manifest');
} finally {
    foreach (scandir($zones) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            @unlink($zones . '/' . $name);
        }
    }
    foreach (scandir($config) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            @unlink($config . '/' . $name);
        }
    }
    foreach ([$okR, $badR] as $script) {
        @unlink($script);
    }
    @rmdir($zones);
    @rmdir($config);
    @rmdir($path);
}
fwrite($failed ? STDERR : STDOUT, "SMBind integration: {$tested} assertions; {$failed} failed.\n");
exit($failed ? 1 : 0);
