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
$zonePage = sendHttp('/?route=zone&id=' . $zoneId, 'GET', [], $cookie);
checkHttp($zonePage['status'] === 200 && str_contains($zonePage['body'], 'smoke.example.com'),
    'New zone page renders');
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

echo "SMBind HTTP smoke: {$assertions} checks; {$failed} failures.\n";
exit($failed ? 1 : 0);
