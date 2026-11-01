<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Dns.php';

use Smbind\Dns;

$checks = 0;
$failures = 0;
function check(bool $ok, string $description): void {
    global $checks, $failures;
    $checks++;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$description}\n");
    }
}
function reject(callable $callback, string $description): void {
    try { $callback(); check(false, $description); }
    catch (InvalidArgumentException) { check(true, $description); }
}
check(Dns::domain('Example.COM.') === 'example.com', 'Normalize FQDN');
check(Dns::domain('sub.example.com') === 'sub.example.com', 'Accept subdomains');
reject(fn () => Dns::domain('example'), 'Reject single label');
reject(fn () => Dns::domain('bad..example.com'), 'Reject empty label');
reject(fn () => Dns::domain('-bad.example.com'), 'Reject illegal label');
reject(fn () => Dns::domain("bad\n.example.com"), 'Reject embedded newline');
check(Dns::host('@') === '@', 'Zone apex');
check(Dns::host('_sip._tcp') === '_sip._tcp', 'Service label');
check(Dns::host('*.www') === '*.www', 'Wildcard label');
reject(fn () => Dns::host('evil;system'), 'Reject injected host');
check(Dns::nextSerial(1, '20261101') === 2026110100, 'Initial serial');
check(Dns::nextSerial(2026110107, '20261101') === 2026110108, 'Monotonic serial');
reject(fn () => Dns::nextSerial(4294967295), 'Reject serial overflow');
check(Dns::validateRecord(['host'=>'@','type'=>'A','value'=>'198.51.100.7'])['type'] === 'A', 'A record');
check(Dns::validateRecord(['host'=>'@','type'=>'AAAA','value'=>'2001:db8::1'])['type'] === 'AAAA', 'AAAA record');
reject(fn () => Dns::validateRecord(['host'=>'@','type'=>'A','value'=>'999.999.1.1']), 'Reject invalid IPv4');
reject(fn () => Dns::validateRecord(['host'=>'@','type'=>'CNAME','value'=>'example.com']), 'Reject apex CNAME');
reject(fn () => Dns::validateRecord(['host'=>'a','type'=>'TXT','value'=>"a\nb"]), 'Reject record newline');
reject(fn () => Dns::validateRecord(['host'=>'_sip._tcp','type'=>'SRV','value'=>'sip.example.com','port'=>'65536']), 'Reject bad port');
$zone = ['name'=>'example.com','primary_ns'=>'ns1.example.com','contact'=>'hostmaster.example.com',
    'serial'=>2026110101,'default_ttl'=>3600,'refresh'=>10800,'retry'=>3600,'expire'=>604800,'minimum'=>3600];
$text = Dns::render($zone, [
    ['host'=>'@','type'=>'A','value'=>'198.51.100.7','ttl'=>3600],
    ['host'=>'_sip._tcp','type'=>'SRV','value'=>'sip.example.com','ttl'=>60,'priority'=>10,'weight'=>20,'port'=>5060],
    ['host'=>'@','type'=>'TXT','value'=>'verify="ok"','ttl'=>3600]
]);
check(str_contains($text, '$ORIGIN example.com.'), 'Origin rendered');
check(str_contains($text, '@ IN SOA ns1.example.com. hostmaster.example.com.'), 'SOA rendered');
check(str_contains($text, '2026110101 ; serial'), 'Serial rendered');
check(str_contains($text, '10 20 5060 sip.example.com.'), 'SRV rendered');
check(str_contains($text, 'verify=\\"ok\\"'), 'TXT quote escaped');
reject(fn () => Dns::render($zone, [['host'=>'a','type'=>'A','value'=>'invalid']]), 'Invalid record cannot publish');
fwrite($failures ? STDERR : STDOUT, "SMBind: {$checks} DNS checks; {$failures} failures.\n");
exit($failures ? 1 : 0);
