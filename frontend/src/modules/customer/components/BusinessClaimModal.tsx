'use client';

import { FormEvent, useEffect, useState } from 'react';
import Link from 'next/link';
import {
  AlertTriangle,
  Building2,
  CheckCircle2,
  Clock,
  Loader2,
  LogIn,
  ShieldCheck,
  X,
} from 'lucide-react';
import { bffFetch } from '@/shared/api/bff-client';
import type { BusinessClaimDto } from '@/shared/api/directory-types';

interface BusinessClaimModalProps {
  isOpen: boolean;
  onClose: () => void;
  businessPublicId: string;
  businessName: string;
  profilePath: string;
  isAuthenticated: boolean;
}

/**
 * Live Claim This Business workflow connected to POST /api/businesses/[businessId]/claims.
 * Enforces OTP authentication, CSRF protection (via bffFetch), and Laravel BusinessPolicy::claim.
 * Never grants immediate ownership — claims always enter admin review (`status: 'pending'`).
 */
export function BusinessClaimModal({
  isOpen,
  onClose,
  businessPublicId,
  businessName,
  profilePath,
  isAuthenticated,
}: BusinessClaimModalProps) {
  const [proofReference, setProofReference] = useState('');
  const [claimNotes, setClaimNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [submittedClaim, setSubmittedClaim] = useState<BusinessClaimDto | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [requiresRelogin, setRequiresRelogin] = useState(false);

  const loginReturnHref = `/login?next=${encodeURIComponent(`${profilePath}?claim=1`)}`;

  useEffect(() => {
    if (!isOpen) return;

    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose();
    }

    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.body.style.overflow = prevOverflow;
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setErrorMessage(null);
    setRequiresRelogin(false);

    const trimmedProof = proofReference.trim();
    if (trimmedProof.length < 5) {
      setErrorMessage('Please enter a valid ownership proof reference (at least 5 characters).');
      return;
    }

    setSubmitting(true);
    try {
      const res = await bffFetch<BusinessClaimDto>(
        `/api/businesses/${encodeURIComponent(businessPublicId)}/claims`,
        {
          method: 'POST',
          body: {
            proof_file_path_private: trimmedProof,
            claim_notes: claimNotes.trim() !== '' ? claimNotes.trim() : null,
          },
        }
      );

      if (res.ok && res.payload?.status === 'success') {
        setSubmittedClaim(res.payload.data);
        return;
      }

      if (res.status === 401) {
        setRequiresRelogin(true);
        setErrorMessage('Your session has expired. Please sign in with Mobile OTP to continue.');
        return;
      }

      const apiErr =
        res.payload?.status === 'error'
          ? res.payload.error
          : null;

      if (apiErr?.code === 'CLAIM_ALREADY_PENDING') {
        setErrorMessage('You already have a pending ownership claim under review for this business.');
      } else if (apiErr?.code === 'BUSINESS_ALREADY_CLAIMED') {
        setErrorMessage('This business listing has already been claimed.');
      } else if (apiErr?.message) {
        setErrorMessage(apiErr.message);
      } else {
        setErrorMessage('Unable to submit your claim right now. Please try again.');
      }
    } catch {
      setErrorMessage('Network error while communicating with NearGrid. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="claim-modal-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
        <button
          type="button"
          onClick={onClose}
          aria-label="Close claim dialog"
          className="absolute right-4 top-4 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
        >
          <X className="h-5 w-5" aria-hidden="true" />
        </button>

        <div className="mb-4 pr-6">
          <span className="inline-flex items-center gap-1 rounded-full border border-brand-200 bg-brand-50 px-2.5 py-0.5 text-[11px] font-semibold text-brand-700">
            <Building2 className="h-3 w-3" aria-hidden="true" />
            Business Ownership Claim
          </span>
          <h2 id="claim-modal-title" className="mt-2 text-lg font-bold text-ink">
            Claim {businessName}
          </h2>
          <p className="mt-1 text-xs text-ink-muted">
            Ownership is transferred only after NearGrid administrators verify your claim request.
          </p>
        </div>

        {/* Unauthenticated State: Prompt OTP Login preserving intended claim action */}
        {!isAuthenticated || requiresRelogin ? (
          <div className="space-y-4">
            {errorMessage && (
              <div
                role="alert"
                className="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900"
              >
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
                <span>{errorMessage}</span>
              </div>
            )}

            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs leading-relaxed text-ink-muted">
              <p className="font-semibold text-ink">Sign in required to submit a claim</p>
              <p className="mt-1">
                Verify your mobile number via NearGrid OTP login so we can tie this ownership
                claim to your account. After signing in, you will be returned directly to this
                listing to complete your claim.
              </p>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2.5 border-t border-gray-100 pt-4">
              <button
                type="button"
                onClick={onClose}
                className="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-ink-muted hover:bg-gray-50"
              >
                Cancel
              </button>
              <Link
                href={loginReturnHref}
                className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
              >
                <LogIn className="h-3.5 w-3.5" aria-hidden="true" />
                <span>Sign in with Mobile OTP</span>
              </Link>
            </div>
          </div>
        ) : submittedClaim ? (
          /* Pending Review State — NEVER implies immediate ownership transfer */
          <div className="space-y-4">
            <div
              role="status"
              className="rounded-xl border border-trust-200 bg-trust-50 p-4 text-xs leading-relaxed text-trust-950"
            >
              <div className="flex items-center gap-2 font-bold text-trust-800">
                <CheckCircle2 className="h-4 w-4 shrink-0" aria-hidden="true" />
                <span>Claim Submitted for Admin Review</span>
              </div>
              <p className="mt-1.5 text-trust-900">
                Your ownership claim for <strong>{businessName}</strong> has been recorded
                with status <strong className="uppercase">{submittedClaim.status}</strong>.
              </p>
              <p className="mt-2 flex items-center gap-1.5 text-[11px] text-trust-800">
                <Clock className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>
                  Claim Reference: <code className="font-mono font-semibold">{submittedClaim.public_id}</code>
                </span>
              </p>
              <p className="mt-2 text-[11px] text-trust-900">
                Submitting a claim does not immediately grant ownership or verification status.
                Our review team will audit your details before transferring management access.
              </p>
            </div>

            <div className="flex justify-end border-t border-gray-100 pt-3">
              <button
                type="button"
                onClick={onClose}
                className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
              >
                Done
              </button>
            </div>
          </div>
        ) : (
          /* Authenticated Claim Form */
          <form onSubmit={handleSubmit} className="space-y-4">
            {errorMessage && (
              <div
                role="alert"
                className="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900"
              >
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
                <span>{errorMessage}</span>
              </div>
            )}

            <div>
              <label
                htmlFor="claim-proof-ref"
                className="mb-1 block text-xs font-semibold text-ink"
              >
                Ownership Proof Reference / Registration Document ID{' '}
                <span className="text-signal-600">*</span>
              </label>
              <input
                id="claim-proof-ref"
                type="text"
                required
                minLength={5}
                maxLength={500}
                value={proofReference}
                onChange={(e) => setProofReference(e.target.value)}
                placeholder="e.g. GSTIN / Shop Act / Trade License reference"
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-ink focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
              />
              <p className="mt-1 text-[11px] text-ink-muted">
                Provide your business registration reference (5–500 characters). Private proof
                details are stored securely and never displayed publicly.
              </p>
            </div>

            <div>
              <label
                htmlFor="claim-notes"
                className="mb-1 block text-xs font-semibold text-ink"
              >
                Additional Notes <span className="font-normal text-ink-muted">(Optional)</span>
              </label>
              <textarea
                id="claim-notes"
                rows={3}
                maxLength={1000}
                value={claimNotes}
                onChange={(e) => setClaimNotes(e.target.value)}
                placeholder="Share your role (e.g., proprietor, partner, branch manager) or context for our reviewer..."
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-ink focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
              />
            </div>

            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-[11px] leading-relaxed text-ink-muted">
              <div className="flex items-center gap-1.5 font-semibold text-ink">
                <ShieldCheck className="h-3.5 w-3.5 text-brand-600" aria-hidden="true" />
                <span>Manual Review Required</span>
              </div>
              <p className="mt-0.5">
                All claims enter a pending review queue. Ownership is granted only after
                verification by NearGrid administrators.
              </p>
            </div>

            <div className="flex items-center justify-end gap-2.5 border-t border-gray-100 pt-3">
              <button
                type="button"
                onClick={onClose}
                disabled={submitting}
                className="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-ink-muted hover:bg-gray-50 disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={submitting}
                className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
              >
                {submitting ? (
                  <>
                    <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                    <span>Submitting Claim...</span>
                  </>
                ) : (
                  <span>Submit Claim for Review</span>
                )}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
