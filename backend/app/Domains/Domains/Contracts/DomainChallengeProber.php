<?php

declare(strict_types=1);

namespace App\Domains\Domains\Contracts;

use App\Domains\Domains\Models\Domain;

interface DomainChallengeProber
{
    /**
     * Fetch observed verification tokens from live DNS TXT or HTTP-01 endpoint.
     *
     * @return list<string>
     */
    public function fetchChallengeTokens(Domain $domain): array;

    /**
     * Perform live TLS/SSL certificate validation for the domain hostname.
     */
    public function probeSslCertificate(Domain $domain): bool;
}
