<?php

declare(strict_types=1);

namespace App\Domains\Directory\Services;

use App\Domains\Directory\Enums\BusinessMemberRole;
use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Enums\VerificationDocumentType;
use App\Domains\Directory\Exceptions\DirectoryException;
use App\Domains\Directory\Jobs\DispatchRevalidationWebhookJob;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessClaim;
use App\Domains\Directory\Models\BusinessMember;
use App\Domains\Directory\Models\BusinessVerification;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Identity\Models\Role;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class BusinessVerificationAndClaimService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function submitVerification(
        User $actor,
        Business $business,
        VerificationDocumentType $documentType,
        string $documentNumber,
        string $privateFilePath,
    ): BusinessVerification {
        $duplicatePending = BusinessVerification::query()
            ->where('business_id', $business->id)
            ->where('document_type', $documentType->value)
            ->whereIn('status', ['pending', 'under_review'])
            ->exists();

        if ($duplicatePending) {
            throw new DirectoryException(
                'VERIFICATION_ALREADY_PENDING',
                'A verification submission for this document type is already under review.',
                Response::HTTP_CONFLICT,
            );
        }

        return DB::transaction(function () use ($actor, $business, $documentType, $documentNumber, $privateFilePath): BusinessVerification {
            $verification = BusinessVerification::query()->create([
                'business_id' => $business->id,
                'submitted_by_user_id' => $actor->id,
                'document_type' => $documentType,
                'document_number_encrypted' => trim($documentNumber),
                'private_file_path' => trim($privateFilePath),
                'status' => 'pending',
            ]);

            $this->auditLogger->record(
                actor: $actor,
                action: 'business.verification_submitted',
                auditable: $business,
                newValues: [
                    'verification_public_id' => $verification->public_id,
                    'document_type' => $documentType->value,
                ],
            );

            return $verification;
        });
    }

    /**
     * Admin-only KYC verification decision. Strictly separate from owner claim workflows.
     */
    public function reviewVerification(
        User $admin,
        BusinessVerification $verification,
        string $decision,
        ?string $rejectionReason = null,
        bool $grantTrustBadge = false,
    ): BusinessVerification {
        if (! $admin->hasPermission('kyc.review')) {
            throw new DirectoryException('FORBIDDEN', 'Only authorized KYC reviewers can audit verifications.', Response::HTTP_FORBIDDEN);
        }

        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new DirectoryException('INVALID_DECISION', 'Verification decision must be approved or rejected.');
        }

        return DB::transaction(function () use ($admin, $verification, $decision, $rejectionReason, $grantTrustBadge): BusinessVerification {
            $business = $verification->business;
            $oldState = [
                'verification_status' => $verification->status,
                'business_is_verified' => $business->is_verified,
                'business_status' => $business->status->value,
            ];

            $verification->status = $decision;
            $verification->reviewed_by_admin_id = $admin->id;
            $verification->rejection_reason = $decision === 'rejected' ? $rejectionReason : null;
            $verification->reviewed_at = now();
            $verification->save();

            if ($decision === 'approved') {
                $business->is_verified = true;
                $business->verified_at = now();
                if ($grantTrustBadge) {
                    $business->is_trusted = true;
                }
                if ($business->status === BusinessStatus::PendingReview) {
                    $business->status = BusinessStatus::Active;
                }
                $business->save();
            }

            $this->auditLogger->record(
                actor: $admin,
                action: "business.verification_{$decision}",
                auditable: $business,
                oldValues: $oldState,
                newValues: [
                    'verification_public_id' => $verification->public_id,
                    'verification_status' => $verification->status,
                    'business_is_verified' => $business->is_verified,
                    'business_status' => $business->status->value,
                ],
                actorRole: 'kyc_verifier',
            );

            $citySlug = $business->city?->slug ?? 'chennai';
            if ($business->slug) {
                DispatchRevalidationWebhookJob::dispatch([
                    "/{$citySlug}/business/{$business->slug}",
                ], 'kyc_verification')->afterCommit();
            }

            return $verification->refresh();
        });
    }

    public function submitClaim(
        User $claimant,
        Business $business,
        string $proofFilePathPrivate,
        ?string $claimNotes = null,
    ): BusinessClaim {
        if ($business->is_claimed || $business->owner_user_id !== null) {
            throw new DirectoryException(
                'BUSINESS_ALREADY_CLAIMED',
                'This business listing has already been claimed.',
                Response::HTTP_CONFLICT,
            );
        }

        if ($business->status !== BusinessStatus::Active) {
            throw new DirectoryException(
                'BUSINESS_NOT_CLAIMABLE',
                'Only active unclaimed listings can be claimed.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $duplicate = BusinessClaim::query()
            ->where('business_id', $business->id)
            ->where('claimant_user_id', $claimant->id)
            ->where('status', 'pending')
            ->exists();

        if ($duplicate) {
            throw new DirectoryException(
                'CLAIM_ALREADY_PENDING',
                'You already have a pending claim for this business.',
                Response::HTTP_CONFLICT,
            );
        }

        return DB::transaction(function () use ($claimant, $business, $proofFilePathPrivate, $claimNotes): BusinessClaim {
            $claim = BusinessClaim::query()->create([
                'business_id' => $business->id,
                'claimant_user_id' => $claimant->id,
                'proof_file_path_private' => trim($proofFilePathPrivate),
                'claim_notes' => $claimNotes !== null ? trim($claimNotes) : null,
                'status' => 'pending',
            ]);

            $this->auditLogger->record(
                actor: $claimant,
                action: 'business.claim_submitted',
                auditable: $business,
                newValues: [
                    'claim_public_id' => $claim->public_id,
                    'claimant_public_id' => $claimant->public_id,
                ],
            );

            return $claim;
        });
    }

    /**
     * Admin-only claim approval transfers ownership without automatically granting KYC verification.
     */
    public function reviewClaim(
        User $admin,
        BusinessClaim $claim,
        string $decision,
        ?string $reviewNotes = null,
    ): BusinessClaim {
        if (! $admin->hasPermission('kyc.review')) {
            throw new DirectoryException('FORBIDDEN', 'Only authorized reviewers can process business claims.', Response::HTTP_FORBIDDEN);
        }

        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new DirectoryException('INVALID_DECISION', 'Claim decision must be approved or rejected.');
        }

        return DB::transaction(function () use ($admin, $claim, $decision, $reviewNotes): BusinessClaim {
            $business = $claim->business;
            $oldOwnerId = $business->owner_user_id;

            $claim->status = $decision;
            $claim->reviewed_by_admin_id = $admin->id;
            $claim->review_notes = $reviewNotes;
            $claim->reviewed_at = now();
            $claim->save();

            if ($decision === 'approved') {
                $business->owner_user_id = $claim->claimant_user_id;
                $business->is_claimed = true;
                // Deliberately does NOT flip is_verified = true; KYC verification remains separate.
                $business->save();

                BusinessMember::query()->updateOrCreate(
                    ['business_id' => $business->id, 'user_id' => $claim->claimant_user_id],
                    [
                        'role' => BusinessMemberRole::Owner,
                        'status' => 'active',
                        'invited_by_user_id' => $admin->id,
                        'accepted_at' => now(),
                    ]
                );

                $ownerRole = Role::query()->firstOrCreate(
                    ['name' => 'business_owner'],
                    ['label' => 'Business Owner']
                );
                $claim->claimant->roles()->syncWithoutDetaching([$ownerRole->id]);

                // Automatically reject competing pending claims on the same business.
                BusinessClaim::query()
                    ->where('business_id', $business->id)
                    ->whereKeyNot($claim->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'rejected',
                        'reviewed_by_admin_id' => $admin->id,
                        'review_notes' => 'Superseded by another approved ownership claim.',
                        'reviewed_at' => now(),
                    ]);
            }

            $this->auditLogger->record(
                actor: $admin,
                action: "business.claim_{$decision}",
                auditable: $business,
                oldValues: ['owner_user_id' => $oldOwnerId, 'is_claimed' => false],
                newValues: [
                    'claim_public_id' => $claim->public_id,
                    'owner_user_id' => $business->owner_user_id,
                    'is_claimed' => $business->is_claimed,
                    'is_verified' => $business->is_verified,
                ],
                actorRole: 'kyc_verifier',
            );

            return $claim->refresh();
        });
    }

    public function changePublicationStatus(
        User $admin,
        Business $business,
        BusinessStatus $newStatus,
        ?string $reason = null,
    ): Business {
        if (! $admin->hasPermission('badges.manage')) {
            throw new DirectoryException('FORBIDDEN', 'Only authorized administrators can change publication status.', Response::HTTP_FORBIDDEN);
        }

        $oldStatus = $business->status->value;
        $business->status = $newStatus;
        $business->save();

        $this->auditLogger->record(
            actor: $admin,
            action: 'business.status_changed',
            auditable: $business,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $newStatus->value, 'reason' => $reason],
        );

        return $business;
    }
}
