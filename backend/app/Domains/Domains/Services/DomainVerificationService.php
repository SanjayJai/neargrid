<?php

declare(strict_types=1);

namespace App\Domains\Domains\Services;

use App\Domains\Domains\Exceptions\DomainException;
use App\Domains\Domains\Models\Domain;
use App\Domains\Governance\Services\AuditLogger;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DomainVerificationService
{
    private const CHALLENGE_TTL_HOURS = 72;
    public const MAX_VERIFICATION_ATTEMPTS = 10;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Generates a fresh verification challenge.
     * Stores only the HMAC-SHA256 hash; returns the plaintext token once for admin DNS/HTTP setup.
     */
    public function issueChallenge(Domain $domain, User $actor, ?string $ipAddress = null): string
    {
        $plaintextToken = 'ngv_' . Str::random(48);
        $hash = $this->hashChallengeToken($plaintextToken);

        DB::transaction(function () use ($domain, $hash, $actor, $ipAddress): void {
            $locked = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $locked->challenge_hash = $hash;
            $locked->challenge_expires_at = now()->addHours(self::CHALLENGE_TTL_HOURS);
            $locked->challenge_consumed_at = null;
            $locked->verification_attempts = 0;
            $locked->verification_status = 'pending';
            if ($locked->status === 'draft') {
                $locked->status = 'pending_verification';
            }
            $locked->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'domain.verification.challenge_issued',
                auditable: $locked,
                newValues: [
                    'verification_method' => $locked->verification_method,
                    'challenge_expires_at' => $locked->challenge_expires_at?->toIso8601String(),
                ],
                ipAddress: $ipAddress,
            );
        });

        return $plaintextToken;
    }

    /**
     * Verifies ownership against observed challenge tokens (from DNS TXT or HTTP-01 lookup).
     * Never activates SSL automatically.
     *
     * @param  callable(Domain): list<string>  $tokenFetcher
     */
    public function verifyChallenge(
        Domain $domain,
        callable $tokenFetcher,
        User $actor,
        ?string $ipAddress = null,
    ): Domain {
        /** @var array{ok: true, domain: Domain}|array{ok: false, code: string, message: string} $outcome */
        $outcome = DB::transaction(function () use ($domain, $tokenFetcher, $actor, $ipAddress): array {
            $locked = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();

            if ($locked->challenge_hash === null || $locked->challenge_consumed_at !== null) {
                return [
                    'ok' => false,
                    'code' => 'CHALLENGE_NOT_AVAILABLE',
                    'message' => 'No active verification challenge exists for this domain.',
                ];
            }

            if ($locked->challenge_expires_at !== null && $locked->challenge_expires_at->isPast()) {
                $locked->verification_status = 'expired';
                $locked->save();

                return [
                    'ok' => false,
                    'code' => 'CHALLENGE_EXPIRED',
                    'message' => 'Domain verification challenge has expired.',
                ];
            }

            if ((int) $locked->verification_attempts >= self::MAX_VERIFICATION_ATTEMPTS) {
                $locked->verification_status = 'failed';
                $locked->save();

                return [
                    'ok' => false,
                    'code' => 'CHALLENGE_ATTEMPTS_EXCEEDED',
                    'message' => 'Maximum verification attempts exceeded. Issue a new challenge.',
                ];
            }

            $observedTokens = $tokenFetcher($locked);
            $matched = false;

            foreach ($observedTokens as $candidate) {
                $candidateHash = $this->hashChallengeToken(trim((string) $candidate));
                if (hash_equals($locked->challenge_hash, $candidateHash)) {
                    $matched = true;
                    break;
                }
            }

            $locked->verification_attempts = (int) $locked->verification_attempts + 1;

            if (! $matched) {
                $locked->verification_status = 'failed';
                $locked->save();

                return [
                    'ok' => false,
                    'code' => 'CHALLENGE_MISMATCH',
                    'message' => 'Verification token could not be validated for this domain.',
                ];
            }

            $locked->verification_status = 'verified';
            $locked->verified_at = now();
            $locked->challenge_consumed_at = now();
            $locked->status = 'active';
            // SSL remains separate and never becomes active without actual validation.
            $locked->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'domain.verification.verified',
                auditable: $locked,
                newValues: [
                    'verification_status' => $locked->verification_status,
                    'status' => $locked->status,
                    'ssl_status' => $locked->ssl_status,
                ],
                ipAddress: $ipAddress,
            );

            return ['ok' => true, 'domain' => $locked->fresh()];
        });

        if (! $outcome['ok']) {
            throw new DomainException(
                $outcome['code'],
                $outcome['message'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $outcome['domain'];
    }

    public function updateSslStatus(
        Domain $domain,
        string $sslStatus,
        bool $validated,
        User $actor,
        ?string $ipAddress = null,
    ): Domain {
        $allowed = ['not_started', 'provisioning', 'active', 'renewing', 'failed'];
        if (! in_array($sslStatus, $allowed, true)) {
            throw new DomainException('INVALID_SSL_STATUS', 'Invalid SSL status.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($sslStatus === 'active' && ! $validated) {
            throw new DomainException(
                'SSL_VALIDATION_REQUIRED',
                'SSL status cannot be marked active without actual validation.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $oldStatus = $domain->ssl_status;
        $domain->ssl_status = $sslStatus;
        $domain->ssl_checked_at = now();
        $domain->save();

        $this->auditLogger->record(
            actor: $actor,
            action: 'domain.ssl.updated',
            auditable: $domain,
            oldValues: ['ssl_status' => $oldStatus],
            newValues: ['ssl_status' => $sslStatus, 'validated' => $validated],
            ipAddress: $ipAddress,
        );

        return $domain->fresh();
    }

    public function hashChallengeToken(string $plaintextToken): string
    {
        $secret = (string) config('app.key', '');
        if ($secret === '') {
            throw new DomainException(
                'APP_KEY_MISSING',
                'Application key is required to hash domain verification challenges.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return hash_hmac('sha256', $plaintextToken, $secret);
    }
}
