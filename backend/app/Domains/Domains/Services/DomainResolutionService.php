<?php

declare(strict_types=1);

namespace App\Domains\Domains\Services;

use App\Domains\Domains\Exceptions\DomainException;
use App\Domains\Domains\Models\Domain;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class DomainResolutionService
{
    private const HOSTNAME_REGEX = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public function normalizeHostname(string $rawHost): string
    {
        $host = strtolower(trim($rawHost));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $host = rtrim($host, '.');

        if ($host === '' || preg_match('/[\s\/\\\\?#]/', $host) === 1) {
            throw new DomainException(
                'INVALID_HOSTNAME',
                'Hostname is malformed.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = strtolower($ascii);
            }
        }

        if (preg_match(self::HOSTNAME_REGEX, $host) !== 1) {
            throw new DomainException(
                'INVALID_HOSTNAME',
                "Hostname '{$rawHost}' is not a valid ASCII hostname.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $host;
    }

    /**
     * Resolve an incoming host strictly against registered, active domains.
     * Unknown, unverified, draft, or suspended domains return null.
     */
    public function resolveActiveDomain(?string $rawHost): ?Domain
    {
        if ($rawHost === null || trim($rawHost) === '') {
            return null;
        }

        try {
            $normalized = $this->normalizeHostname($rawHost);
        } catch (DomainException) {
            return null;
        }

        return Domain::query()
            ->with(['defaultCity', 'defaultState', 'canonicalDomain'])
            ->where('hostname', $normalized)
            ->where('status', 'active')
            ->where('verification_status', 'verified')
            ->first();
    }

    public function resolvePrimaryDomain(): ?Domain
    {
        return Domain::query()
            ->where('primary_slot', 1)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Atomically promotes the given domain as the single active primary domain.
     */
    public function promotePrimaryDomain(Domain $target): Domain
    {
        if ($target->status !== 'active' || $target->verification_status !== 'verified') {
            throw new DomainException(
                'DOMAIN_NOT_READY_FOR_PRIMARY',
                'Only an active, verified domain can be promoted to primary.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return DB::transaction(function () use ($target): Domain {
            Domain::query()
                ->where('primary_slot', 1)
                ->where('id', '!=', $target->id)
                ->lockForUpdate()
                ->update([
                    'primary_slot' => null,
                    'is_primary' => false,
                ]);

            $locked = Domain::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $locked->domain_type = 'primary';
            $locked->is_primary = true;
            $locked->primary_slot = 1;
            $locked->save();

            return $locked->fresh();
        });
    }
}
