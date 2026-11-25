<?php

declare(strict_types=1);

namespace App\Domains\Domains\Services;

use App\Domains\Domains\Contracts\DomainChallengeProber;
use App\Domains\Domains\Models\Domain;
use Illuminate\Support\Facades\Http;

final class NativeDomainChallengeProber implements DomainChallengeProber
{
    private const MAX_HTTP_BODY_BYTES = 4096;

    /** @var callable(string): list<string> */
    private $dnsIpResolver;

    /**
     * @param  (callable(string): list<string>)|null  $dnsIpResolver
     */
    public function __construct(?callable $dnsIpResolver = null)
    {
        $this->dnsIpResolver = $dnsIpResolver ?? [$this, 'resolveAllIpsNative'];
    }

    public function fetchChallengeTokens(Domain $domain): array
    {
        if ($domain->verification_method === 'http_file') {
            return $this->fetchHttpChallengeTokens($domain->hostname);
        }

        return $this->fetchDnsTxtTokens($domain->hostname);
    }

    /**
     * Resolves all A/AAAA records for $hostname, rejects if ANY resolved IP
     * is loopback, private, link-local, or reserved, and returns the first
     * validated public IP to pin connections against DNS rebinding.
     */
    public function resolveValidatedPublicIp(string $hostname): ?string
    {
        $ips = ($this->dnsIpResolver)($hostname);
        if ($ips === []) {
            return null;
        }

        $validatedIps = [];
        foreach ($ips as $ip) {
            $trimmed = trim((string) $ip);
            if ($trimmed === '' || ! $this->isPublicIp($trimmed)) {
                // Reject entire host if ANY A/AAAA record resolves to an unsafe address.
                return null;
            }
            $validatedIps[] = $trimmed;
        }

        return $validatedIps[0] ?? null;
    }

    public function probeSslCertificate(Domain $domain): bool
    {
        $pinnedIp = $this->resolveValidatedPublicIp($domain->hostname);
        if ($pinnedIp === null) {
            return false;
        }

        $target = str_contains($pinnedIp, ':') ? "ssl://[{$pinnedIp}]:443" : "ssl://{$pinnedIp}:443";

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => $domain->hostname,
            ],
        ]);

        $client = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            4.0,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return false;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $certResource = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($certResource === null) {
            return false;
        }

        $parsed = openssl_x509_parse($certResource);
        if (! is_array($parsed)) {
            return false;
        }

        $now = time();
        $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
        $validTo = (int) ($parsed['validTo_time_t'] ?? 0);

        return $validFrom > 0 && $validTo > $now && $validFrom <= $now;
    }

    /**
     * @return list<string>
     */
    private function fetchDnsTxtTokens(string $hostname): array
    {
        $recordHost = "_neargrid-verify.{$hostname}";
        $records = @dns_get_record($recordHost, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        $tokens = [];
        foreach ($records as $record) {
            $txt = $record['txt'] ?? null;
            if (is_string($txt) && trim($txt) !== '') {
                $tokens[] = trim($txt);
            }
        }

        return $tokens;
    }

    /**
     * @return list<string>
     */
    private function fetchHttpChallengeTokens(string $hostname): array
    {
        $pinnedIp = $this->resolveValidatedPublicIp($hostname);
        if ($pinnedIp === null) {
            return [];
        }

        $curlResolveEntry = "{$hostname}:80:{$pinnedIp}";

        try {
            $response = Http::connectTimeout(3)
                ->timeout(4)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => [$curlResolveEntry],
                        CURLOPT_MAXFILESIZE => self::MAX_HTTP_BODY_BYTES,
                    ],
                ])
                ->get("http://{$hostname}/.well-known/neargrid-verify.txt");

            if (! $response->successful()) {
                return [];
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_HTTP_BODY_BYTES) {
                return [];
            }

            $trimmed = trim($body);
            return $trimmed !== '' ? [$trimmed] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function resolveAllIpsNative(string $hostname): array
    {
        $ips = [];

        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($hostname);
            if (is_array($fallback)) {
                $ips = $fallback;
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
