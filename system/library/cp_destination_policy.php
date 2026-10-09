<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** URL and DNS policy; contains no deployment hostname or alternate authority. */
final class CpDestinationPolicy
{
    public const MAX_CNAME_DEPTH = 8;
    public const MAX_RECORDS = 64;

    public static function canonicalRoot(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new CpException('Control Panel must be a public HTTPS root URL on port 443.');
        }
        $host = self::publicHostname((string) $parts['host']);
        return 'https://' . $host;
    }

    private static function publicHostname(string $host): string
    {
        $host = strtolower($host);
        if (strlen($host) > 253 || !str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host)) {
            throw new CpException('Control Panel requires a public DNS hostname.');
        }
        // Special-use namespaces, not a list of permitted CP hosts.
        foreach (['localhost', 'local', 'test', 'invalid', 'example', 'example.com', 'example.net', 'example.org',
            'onion', 'internal', 'arpa', 'alt', 'home', 'corp', 'mail', 'lan'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                throw new CpException('Control Panel hostname is special-use.');
            }
        }
        return $host;
    }

    /** Resolve every advertised A/AAAA address; reject the entire answer if any is unsafe.
     * @param callable(string): array|false|null $query DNS record seam; never supplies a CP URL.
     * @return list<string>
     */
    public function resolve(string $host, ?callable $query = null): array
    {
        $host = self::publicHostname($host);
        $query ??= static fn(string $name): array|false => @dns_get_record($name . '.', DNS_A | DNS_AAAA | DNS_CNAME);
        $seen = [];
        $addresses = [];
        $remainingRecords = self::MAX_RECORDS;
        for ($depth = 0; $depth <= self::MAX_CNAME_DEPTH; ++$depth) {
            if (isset($seen[$host])) {
                throw new CpException('Control Panel DNS alias loop.');
            }
            $seen[$host] = true;
            $records = $query($host);
            if (!is_array($records) || $records === [] || count($records) > $remainingRecords) {
                throw new CpConnectionException('Control Panel DNS resolution failed or exceeded its bound.');
            }
            $remainingRecords -= count($records);
            $target = null;
            foreach ($records as $record) {
                $type = $record['type'] ?? '';
                if ($type === 'A' || $type === 'AAAA') {
                    $ip = (string) ($record[$type === 'A' ? 'ip' : 'ipv6'] ?? '');
                    if (!self::isPublicAddress($ip)) {
                        throw new CpException('Control Panel DNS resolved to a forbidden network.');
                    }
                    $addresses[$ip] = $ip;
                } elseif ($type === 'CNAME') {
                    $alias = self::publicHostname(rtrim((string) ($record['target'] ?? ''), '.'));
                    if ($target !== null && $target !== $alias) {
                        throw new CpException('Control Panel DNS has conflicting aliases.');
                    }
                    $target = $alias;
                }
            }
            if ($target === null) {
                if ($addresses === []) {
                    throw new CpConnectionException('Control Panel DNS has no usable addresses.');
                }
                return array_values($addresses);
            }
            $host = $target;
        }
        throw new CpException('Control Panel DNS alias depth exceeded.');
    }

    public static function isPublicAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        // Conservative exclusions of special-purpose space (IANA), including transition ranges.
        $excluded = strlen($packed) === 4
            ? ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                '192.31.196.0/24', '192.52.193.0/24', '192.175.48.0/24',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3']
            : ['2001::/23', '2001:db8::/32', '2002::/16', '2620:4f:8000::/48', '3fff::/20'];
        if (strlen($packed) === 16 && !self::inNetwork($packed, '2000::/3')) {
            return false;
        }
        foreach ($excluded as $network) {
            if (self::inNetwork($packed, $network)) {
                return false;
            }
        }
        return true;
    }

    private static function inNetwork(string $packed, string $network): bool
    {
        [$base, $bits] = explode('/', $network);
        $prefix = inet_pton($base);
        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;
        return substr($packed, 0, $bytes) === substr($prefix, 0, $bytes)
            && ($remainder === 0 || ((ord($packed[$bytes]) ^ ord($prefix[$bytes])) & (255 << (8 - $remainder))) === 0);
    }
}
