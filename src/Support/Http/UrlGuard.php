<?php

declare(strict_types=1);

namespace SupportAI\Support\Http;

use RuntimeException;

/**
 * SSRF guard for server-side fetches of user/admin-supplied URLs (knowledge
 * ingestion). Without this, an "add this URL" could make the server fetch cloud
 * metadata (169.254.169.254), a loopback admin panel, or an internal service.
 *
 * Policy: http/https only, and every IP the host resolves to must be a public,
 * routable address. Applied to the INGESTION path only — LLM/provider calls go
 * to fixed, trusted hosts and are exempt.
 *
 * Residual risk: DNS rebinding between this check and the actual fetch is not
 * fully closed on shared hosting (no custom cURL socket hooks). Combined with
 * the admin-only surface and the http/https redirect restriction on HttpClient,
 * the realistic vectors are covered.
 */
final class UrlGuard
{
    public static function assertPublic(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Only http and https URLs can be fetched.');
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            throw new RuntimeException('The URL has no host.');
        }

        foreach (self::resolve($host) as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new RuntimeException('That URL points to a private or internal address and cannot be fetched.');
            }
        }
    }

    /**
     * Resolve a host to the IPs we must vet. A literal IP host resolves to
     * itself; a name is resolved via DNS. If resolution yields nothing we fail
     * closed by returning the host so isPublicIp() rejects a non-IP string.
     *
     * @return string[]
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = (string) $rec['ipv6'];
                }
            }
        }
        return $ips !== [] ? $ips : [$host];
    }

    /** Public = a valid IP that is NOT private, reserved, loopback or link-local. */
    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
