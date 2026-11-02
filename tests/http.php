<?php
declare(strict_types=1);

/**
 * Black-box HTTP smoke tests using PHP's native HTTP stream wrapper.
 * Requires a temporary PHP development server on 127.0.0.1:18080 and the
 * disposable smbind_ci MySQL database populated by tests/integration.php.
 */
if (PHP_SAPI !== 'cli' || getenv('SMBIND_CI_DATABASE') !== 'smbind_ci') {
    fwrite(STDERR, "HTTP tests may only run with the disposable smbind_ci fixture.\n");
    exit(2);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$assertions = 0;
$failed = 0;
function checkHttp(bool $yes, string $what): void
{
    global $assertions, $failed;
    $assertions++;
    if (!$yes) {
        $failed++;
        fwrite(STDERR, "FAIL: " . $what . "\n");
    }
}

function sendHttp(string $path, string $method = 'GET', array $data = [], string $cookie = ''): array
{
    $headers = ["Accept: text/html"];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $opts = [
        'method' => $method,
        'header' => implode("\r\n", $headers) . "\r\n",
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 15,
    ];
    if ($method === 'POST') {
        $opts['content'] = http_build_query($data);
    }
    $context = stream_context_create(['http' => $opts]);
    $content = @file_get_contents('http://127.0.0.1:18080' . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('~^HTTP/\S+\s+(\d+)~', $responseHeaders[0] ?? '', $status);
    $newCookie = null;
    $redirect = null;
    foreach ($responseHeaders as $line) {
        if (preg_match('/^Set-Cookie:\s*(smbind_session=[^;\s]+)/i', $line, $match)) {
            $newCookie = $match[1];
        }
        if (preg_match('/^Location:\s*(.+)$/i', $line, $match)) {
            $redirect = trim($match[1]);
        }
    }
    return [
        'status' => (int) ($status[1] ?? 0),
        'body' => $content === false ? '' : $content,
        'cookie' => $newCookie,
        'location' => $redirect,
    ];
}

function tokenFrom(string $html): string
{
    if (!preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $match)) {
        throw new RuntimeException('CSRF token was not present in response.');
    }
    return $match[1];
}

$login = sendHttp('/?route=login');
checkHttp($login['status'] === 200, 'GET login renders HTTP 200');
checkHttp(str_contains($login['body'], 'Welcome back'), 'Login page body rendered');
$cookie = $login['cookie'] ?? '';
checkHttp($cookie !== '', 'Anonymous session cookie issued');
$token = tokenFrom($login['body']);

$invalid = sendHttp('/', 'POST', [
    'action' => 'login', '_token' => $token,
    'username' => 'ciadmin', 'password' => 'wrong-test-only-password',
], $cookie);
checkHttp($invalid['status'] === 303 && $invalid['location'] === '/?route=login',
    'Bad credentials redirect without authentication');
$login = sendHttp('/?route=login', 'GET', [], $cookie);
checkHttp(str_contains($login['body'], 'Invalid credentials'), 'Login failure is visible');
$token = tokenFrom($login['body']);

$loggedIn = sendHttp('/', 'POST', [
    'action' => 'login', '_token' => $token,
    'username' => 'ciadmin', 'password' => 'Test-only-administrator-password-123',
], $cookie);
checkHttp($loggedIn['status'] === 303 && $loggedIn['location'] === '/?route=dashboard',
    'Correct credentials log in and redirect');
$cookie = $loggedIn['cookie'] ?: $cookie;
$dashboard = sendHttp('/?route=dashboard', 'GET', [], $cookie);
checkHttp($dashboard['status'] === 200, 'Authenticated dashboard returns 200');
checkHttp(str_contains($dashboard['body'], 'Good to see you'), 'Authenticated dashboard displays');
$token = tokenFrom($dashboard['body']);

$badCsrf = sendHttp('/', 'POST', [
    'action' => 'create_zone', '_token' => 'invalid',
    'name' => 'rejected.example.com', 'owner_id' => '1',
], $cookie);
checkHttp($badCsrf['status'] === 303, 'Invalid CSRF is rejected with safe redirect');
checkHttp(\Smbind\one('SELECT id FROM zones WHERE name=?', ['rejected.example.com']) === null,
    'Invalid CSRF never creates a zone');

$owner = \Smbind\one("SELECT id FROM users WHERE username='ciadmin'");
if (!$owner) {
    throw new RuntimeException('CI admin fixture is missing.');
}
$create = sendHttp('/', 'POST', [
    'action' => 'create_zone', '_token' => $token,
    'name' => 'smoke.example.com', 'owner_id' => (string) $owner['id'],
    'primary_ns' => 'ns1.smoke.example.com', 'contact' => 'hostmaster.smoke.example.com',
    'default_ttl' => '3600', 'refresh' => '10800',
    'retry' => '3600', 'expire' => '604800', 'minimum' => '3600',
], $cookie);
checkHttp($create['status'] === 303 && str_contains($create['location'] ?? '', 'route=zone'),
    'Zone creation succeeds over HTTP');
$z = \Smbind\one('SELECT id FROM zones WHERE name=?', ['smoke.example.com']);
checkHttp($z !== null, 'Zone persists in PDO MySQL');

if (!$z) {
    throw new RuntimeException('Cannot continue record CRUD without a zone.');
}
$zoneId = (int) $z['id'];
// Authorization regression: viewer can read but must not mutate another user's zone.
\Smbind\query('INSERT INTO users (username,password_hash,role) VALUES (?,?,?)', [
    'civiewer', password_hash('Test-only-viewer-password-123', PASSWORD_ARGON2ID), 'viewer'
]);
$viewerLogin = sendHttp('/?route=login');
$viewerCookie = $viewerLogin['cookie'] ?? '';
$viewerAuth = sendHttp('/', 'POST', [
    'action' => 'login', '_token' => tokenFrom($viewerLogin['body']),
    'username' => 'civiewer', 'password' => 'Test-only-viewer-password-123',
], $viewerCookie);
$viewerCookie = $viewerAuth['cookie'] ?: $viewerCookie;
checkHttp($viewerAuth['status'] === 303, 'Viewer login succeeds');
$viewerZone = sendHttp('/?route=zone&id=' . $zoneId, 'GET', [], $viewerCookie);
checkHttp($viewerZone['status'] === 200 && !str_contains($viewerZone['body'], 'Delete zone permanently'),
    'Viewer may inspect zone without destructive controls');
$viewerEdit = sendHttp('/', 'POST', [
    'action' => 'save_record', '_token' => tokenFrom($viewerZone['body']),
    'zone_id' => (string) $zoneId, 'host' => 'unauthorized', 'type' => 'A',
    'value' => '192.0.2.55', 'ttl' => '3600',
], $viewerCookie);
checkHttp($viewerEdit['status'] === 303 &&
    \Smbind\one('SELECT id FROM records WHERE zone_id=? AND host=?', [$zoneId, 'unauthorized']) === null,
    'Viewer cannot modify DNS using crafted POST');

$preview = sendHttp('/?route=publish-preview', 'GET', [], $cookie);
checkHttp($preview['status'] === 200 && str_contains($preview['body'], 'Review DNS publication'),
    'Administrator can view read-only publication plan');
checkHttp(str_contains($preview['body'], 'Add new zone') &&
    str_contains($preview['body'], 'smoke.example.com'),
    'Read-only plan identifies newly created DNS zone');
$viewerPreview = sendHttp('/?route=publish-preview', 'GET', [], $viewerCookie);
checkHttp($viewerPreview['status'] === 403 && !str_contains($viewerPreview['body'], 'Planned changes'),
    'Viewer cannot inspect admin-only publication plan');

// Suspend an account and prove both active sessions and fresh logins are denied.
$viewer = \Smbind\one("SELECT id FROM users WHERE username='civiewer'");
$suspend = sendHttp('/', 'POST', [
    'action' => 'set_user_active', '_token' => $token,
    'user_id' => (string) $viewer['id'], 'active' => '0',
], $cookie);
checkHttp($suspend['status'] === 303 &&
    (int) \Smbind\one('SELECT is_active FROM users WHERE id=?', [$viewer['id']])['is_active'] === 0,
    'Administrator can suspend another account');
$revoked = sendHttp('/?route=dashboard', 'GET', [], $viewerCookie);
checkHttp($revoked['status'] === 303 && $revoked['location'] === '/?route=login',
    'Suspension revokes an existing viewer session on the next request');
$suspendedLogin = sendHttp('/?route=login');
$refused = sendHttp('/', 'POST', [
    'action' => 'login', '_token' => tokenFrom($suspendedLogin['body']),
    'username' => 'civiewer', 'password' => 'Test-only-viewer-password-123',
], $suspendedLogin['cookie'] ?? '');
checkHttp($refused['status'] === 303 && $refused['location'] === '/?route=login',
    'Suspended account cannot log in');
$restore = sendHttp('/', 'POST', [
    'action' => 'set_user_active', '_token' => $token,
    'user_id' => (string) $viewer['id'], 'active' => '1',
], $cookie);
checkHttp($restore['status'] === 303 &&
    (int) \Smbind\one('SELECT is_active FROM users WHERE id=?', [$viewer['id']])['is_active'] === 1,
    'Administrator can restore access without recreating the user');

$zonePage = sendHttp('/?route=zone&id=' . $zoneId, 'GET', [], $cookie);
checkHttp($zonePage['status'] === 200 && str_contains($zonePage['body'], 'smoke.example.com'),
    'New zone page renders');
$export = sendHttp('/?route=zone-export&id=' . $zoneId, 'GET', [], $cookie);
checkHttp($export['status'] === 200 &&
    str_contains($export['body'], '$ORIGIN smoke.example.com.') &&
    str_contains($export['body'], '@ IN SOA ns1.smoke.example.com.'),
    'Authenticated zone export renders complete BIND-format zone file');
$token = tokenFrom($zonePage['body']);

$add = sendHttp('/', 'POST', [
    'action' => 'save_record', '_token' => $token, 'zone_id' => (string) $zoneId,
    'host' => 'www', 'type' => 'A', 'value' => '192.0.2.51',
    'ttl' => '3600', 'priority' => '0', 'weight' => '0', 'port' => '0',
], $cookie);
checkHttp($add['status'] === 303, 'Record creation redirects');
$record = \Smbind\one('SELECT id,value FROM records WHERE zone_id=? AND host=?', [$zoneId, 'www']);
checkHttp($record !== null && $record['value'] === '192.0.2.51',
    'Record values persisted to correct PDO columns');

if ($record) {
    $delete = sendHttp('/', 'POST', [
        'action' => 'delete_record', '_token' => $token,
        'zone_id' => (string) $zoneId, 'record_id' => (string) $record['id'],
    ], $cookie);
    checkHttp($delete['status'] === 303 &&
        \Smbind\one('SELECT id FROM records WHERE id=?', [$record['id']]) === null,
        'Record delete succeeds');
}

// Model a previously published zone and verify that deletion remains publishable.
\Smbind\query('INSERT INTO published_zones (name) VALUES (?)', ['smoke.example.com']);
$deleteZone = sendHttp('/', 'POST', [
    'action' => 'delete_zone', '_token' => $token, 'zone_id' => (string) $zoneId,
], $cookie);
checkHttp($deleteZone['status'] === 303, 'Zone deletion redirected');
$dashboard = sendHttp('/?route=dashboard', 'GET', [], $cookie);
checkHttp(str_contains($dashboard['body'], 'Unpublished changes') &&
    str_contains($dashboard['body'], '1 deleted zone(s)'),
    'Deleted-only zones remain visible as pending DNS changes');
checkHttp(str_contains($dashboard['body'], 'Validate &amp; publish DNS'),
    'Dashboard exposes a working publication action for deleted-only zones');
$deletedPlan = sendHttp('/?route=publish-preview', 'GET', [], $cookie);
checkHttp(str_contains($deletedPlan['body'], 'Remove published zone') &&
    str_contains($deletedPlan['body'], 'smoke.example.com'),
    'Read-only plan identifies deletion even when no DNS zones remain');

echo "SMBind HTTP smoke: {$assertions} checks; {$failed} failures.\n";
exit($failed ? 1 : 0);
