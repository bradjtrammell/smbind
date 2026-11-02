<?php
declare(strict_types=1);

namespace Smbind;

use InvalidArgumentException;

final class Dns
{
    public const TYPES = ['A', 'AAAA', 'CAA', 'CNAME', 'DS', 'MX', 'SSHFP', 'TLSA', 'TXT', 'NS', 'PTR', 'SRV'];

    public static function domain(string $value): string
    {
        $name = strtolower(rtrim(trim($value), '.'));
        if (strlen($name) > 253 || strlen($name) < 3 || !str_contains($name, '.')) {
            throw new InvalidArgumentException('Enter a valid fully qualified domain name.');
        }
        foreach (explode('.', $name) as $label) {
            if (strlen($label) < 1 || strlen($label) > 63 ||
                !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label)) {
                throw new InvalidArgumentException('Domain labels must contain letters, numbers, or interior hyphens.');
            }
        }
        return $name;
    }

    public static function host(string $value): string
    {
        $host = strtolower(trim($value));
        if ($host === '@') {
            return '@';
        }
        if ($host === '' || strlen($host) > 253) {
            throw new InvalidArgumentException('Enter a valid relative record name.');
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '*') {
                continue;
            }
            if (strlen($label) > 63 || !preg_match('/^[_a-z0-9](?:[_a-z0-9-]*[_a-z0-9])?$/D', $label)) {
                throw new InvalidArgumentException('Record names may use letters, numbers, hyphens, underscores, and wildcards.');
            }
        }
        return $host;
    }

    public static function number(mixed $value, int $min, int $max, string $label): int
    {
        if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string) $value)) {
            throw new InvalidArgumentException($label . ' must be a whole number.');
        }
        $number = filter_var((string) $value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $min || $number > $max) {
            throw new InvalidArgumentException($label . ' must be between ' . $min . ' and ' . $max . '.');
        }
        return $number;
    }

    public static function validateRecord(array $input): array
    {
        $type = strtoupper(trim((string) ($input['type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported DNS record type.');
        }
        $host = self::host((string) ($input['host'] ?? '@'));
        if ($host === '@' && $type === 'CNAME') {
            throw new InvalidArgumentException('A zone apex cannot contain a CNAME record.');
        }
        $value = trim((string) ($input['value'] ?? ''));
        if ($value === '' || strlen($value) > 2048 || str_contains($value, "\0") ||
            str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException('Enter a valid DNS record value.');
        }
        if ($type === 'A' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('A records require a valid IPv4 address.');
        }
        if ($type === 'AAAA' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException('AAAA records require a valid IPv6 address.');
        }
        if (in_array($type, ['CNAME', 'MX', 'NS', 'PTR', 'SRV'], true)) {
            $value = self::domain($value);
        }
        if ($type === 'TXT' && preg_match('/[^\x20-\x7E]/', $value)) {
            throw new InvalidArgumentException('TXT records must contain printable ASCII characters.');
        }
        if ($type === 'CAA') {
            if (!preg_match('/^([0-9]{1,3})\s+([a-zA-Z0-9-]{1,15})\s+"([^"\\\\\x00-\x1F\x7F]*)"$/D', $value, $parts) ||
                (int) $parts[1] > 255) {
                throw new InvalidArgumentException('CAA requires: flags tag "value" (flags 0–255).');
            }
            $value = (int) $parts[1] . ' ' . strtolower($parts[2]) . ' "' . $parts[3] . '"';
        }
        if ($type === 'DS') {
            if (!preg_match('/^(\d{1,5})\s+(\d{1,3})\s+(\d{1,3})\s+([a-fA-F0-9]+)$/D', $value, $parts) ||
                (int) $parts[1] > 65535 || (int) $parts[2] > 255 || (int) $parts[3] > 255 ||
                strlen($parts[4]) % 2 !== 0) {
                throw new InvalidArgumentException('DS requires: key tag, algorithm, digest type, even-length hexadecimal digest.');
            }
            $expected = [1 => 40, 2 => 64, 4 => 96];
            if (isset($expected[(int) $parts[3]]) && strlen($parts[4]) !== $expected[(int) $parts[3]]) {
                throw new InvalidArgumentException('DS digest has the wrong length for its digest type.');
            }
            $value = (int) $parts[1] . ' ' . (int) $parts[2] . ' ' . (int) $parts[3] . ' ' . strtoupper($parts[4]);
        }
        if ($type === 'SSHFP') {
            if (!preg_match('/^(\d{1,3})\s+([12])\s+([a-fA-F0-9]+)$/D', $value, $parts) ||
                (int) $parts[1] > 255 || strlen($parts[3]) !== ((int) $parts[2] === 1 ? 40 : 64)) {
                throw new InvalidArgumentException('SSHFP requires: algorithm, fingerprint type, and SHA1/SHA256 hexadecimal fingerprint.');
            }
            $value = (int) $parts[1] . ' ' . $parts[2] . ' ' . strtoupper($parts[3]);
        }
        if ($type === 'TLSA') {
            if (!preg_match('/^([0-3])\s+([01])\s+([012])\s+([a-fA-F0-9]+)$/D', $value, $parts) ||
                strlen($parts[4]) % 2 !== 0 ||
                ($parts[3] === '1' && strlen($parts[4]) !== 64) ||
                ($parts[3] === '2' && strlen($parts[4]) !== 128)) {
                throw new InvalidArgumentException('TLSA requires: usage selector matching-type hex-data with a valid digest length.');
            }
            $value = $parts[1] . ' ' . $parts[2] . ' ' . $parts[3] . ' ' . strtoupper($parts[4]);
        }
        $ttl = self::number($input['ttl'] ?? '3600', 60, 2147483647, 'TTL');
        $priority = self::number($input['priority'] ?? '0', 0, 65535, 'Priority');
        $weight = self::number($input['weight'] ?? '0', 0, 65535, 'Weight');
        $port = self::number($input['port'] ?? '0', 0, 65535, 'Port');
        if ($type === 'SRV' && $host !== '@' && !str_starts_with($host, '_')) {
            throw new InvalidArgumentException('SRV record names must begin with a service label (for example _sip._tcp).');
        }
        return compact('type', 'host', 'value', 'ttl', 'priority', 'weight', 'port');
    }

    public static function nextSerial(int $old, ?string $today = null): int
    {
        $base = (int) (($today ?? gmdate('Ymd')) . '00');
        $serial = max($base, $old + 1);
        if ($serial > 4294967295) {
            throw new InvalidArgumentException('SOA serial would exceed the DNS 32-bit limit.');
        }
        return $serial;
    }

    public static function render(array $zone, array $records): string
    {
        $name = self::domain((string) $zone['name']);
        $primary = self::domain((string) $zone['primary_ns']) . '.';
        $contact = self::domain((string) $zone['contact']) . '.';
        $serial = self::number($zone['serial'], 1, 4294967295, 'Serial');
        $ttl = self::number($zone['default_ttl'], 60, 2147483647, 'TTL');
        $refresh = self::number($zone['refresh'], 60, 2147483647, 'Refresh');
        $retry = self::number($zone['retry'], 60, 2147483647, 'Retry');
        $expire = self::number($zone['expire'], 60, 2147483647, 'Expire');
        $minimum = self::number($zone['minimum'], 60, 2147483647, 'Minimum');
        $lines = [
            '; Managed by SMBind. Changes made directly to this file will be overwritten.',
            '$ORIGIN ' . $name . '.',
            '$TTL ' . $ttl,
            '@ IN SOA ' . $primary . ' ' . $contact . ' (',
            '  ' . $serial . ' ; serial',
            '  ' . $refresh . ' ; refresh',
            '  ' . $retry . ' ; retry',
            '  ' . $expire . ' ; expire',
            '  ' . $minimum . ' ; negative cache TTL',
            ')',
            '@ IN NS ' . $primary,
        ];
        foreach ($records as $record) {
            $r = self::validateRecord($record);
            $value = $r['value'];
            if (in_array($r['type'], ['CNAME', 'MX', 'NS', 'PTR', 'SRV'], true)) {
                $value .= '.';
            }
            if ($r['type'] === 'TXT') {
                $chunks = str_split($value, 255);
                $value = implode(' ', array_map(
                    static fn (string $part): string => '"' . addcslashes($part, "\\\"") . '"',
                    $chunks
                ));
            }
            if ($r['type'] === 'MX') {
                $value = $r['priority'] . ' ' . $value;
            } elseif ($r['type'] === 'SRV') {
                $value = $r['priority'] . ' ' . $r['weight'] . ' ' . $r['port'] . ' ' . $value;
            }
            $lines[] = $r['host'] . ' ' . $r['ttl'] . ' IN ' . $r['type'] . ' ' . $value;
        }
        return implode("\n", $lines) . "\n";
    }
}
