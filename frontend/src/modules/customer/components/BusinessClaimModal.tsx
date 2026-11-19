'use client';

import { useEffect } from 'react';
import Link from 'next/link';
import {
  Building2,
  LogIn,
  ShieldAlert,
  ShieldCheck,
  X,
} from 'lucide-react';

interface BusinessClaimModalProps {
  isOpen: boolean;
  onClose: () => void;
  businessPublicId: string;
  businessName: string;
  profilePath: string;
  isAuthenticated: boolean;
}

/**
 * Business Ownership Claim Modal.
 *
 * Security Audit Notice (Step 4 Security Review):
 * - Laravel `POST /api/v1/businesses/{business}/claims` requires `proof_file_path_private`.
 * - In the backend, `proof_file_path_private` represents a private storage disk path, but
 *   the system only validates string length and currently lacks a secure, isolated upload
 *   pipeline for non-owner claimants (existing KYC upload requires already owning the business).
 * - To prevent claimants from submitting arbitrary strings, fabricated references, or
 *   cross-tenant private storage paths, self-serve online claim submission is kept disabled.
 * - Non-owner claimants are directed to platform administrators with verified
 *   registration documents (GSTIN / Trade License) for manual ownership assignment.
 * - Submitting a claim does not immediately grant ownership.
 *
 * Route references:
 * - BFF route: POST /api/businesses/[businessId]/claims
 * - Return login: /login?next=...
 */
export function BusinessClaimModal({
  isOpen,
  onClose,
  businessPublicId,
  businessName,
  profilePath,
  isAuthenticated,
}: BusinessClaimModalProps) {
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
            Ownership is transferred only after platform administrators verify authentic legal documentation.
          </p>
        </div>

        {/* Unauthenticated State: Prompt OTP Login preserving intended claim action */}
        {!isAuthenticated ? (
          <div className="space-y-4">
            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs leading-relaxed text-ink-muted">
              <p className="font-semibold text-ink">Sign in required to claim listing</p>
              <p className="mt-1">
                Verify your mobile number via NearGrid OTP login so we can tie this ownership
                inquiry to your verified account. After signing in, you will be returned directly to this
                listing.
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
        ) : (
          /* Authenticated State — Honest explanation that self-serve document upload is disabled */
          <div className="space-y-4">
            <div
              role="alert"
              className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs leading-relaxed text-amber-950"
            >
              <div className="flex items-center gap-2 font-bold text-amber-900">
                <ShieldAlert className="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                <span>Self-Serve Document Upload Temporarily Unavailable</span>
              </div>
              <p className="mt-2 text-amber-900">
                NearGrid requires verified legal ownership proof (e.g. GSTIN Certificate,
                Trade License, or Udyam Registration) before transferring listing ownership.
              </p>
              <p className="mt-2 text-[11px] text-amber-800">
                Because an encrypted, claimant-scoped private document upload workflow is
                currently undergoing hardening, self-serve online claim submission is kept disabled.
                NearGrid does not accept unverified text strings or arbitrary file paths for{' '}
                <code className="font-mono text-amber-900">proof_file_path_private</code>.
              </p>
              <p className="mt-2 text-[11px] font-medium text-amber-900">
                Submitting a claim does not immediately grant ownership or verification status.
              </p>
            </div>

            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs leading-relaxed text-ink-muted">
              <div className="flex items-center gap-1.5 font-semibold text-ink">
                <ShieldCheck className="h-4 w-4 text-brand-600" aria-hidden="true" />
                <span>Assisted Ownership Verification</span>
              </div>
              <p className="mt-1">
                If you are the rightful proprietor or authorized manager of <strong>{businessName}</strong>,
                please contact the platform administrator to initiate manual ownership verification:
              </p>
              <ul className="mt-2.5 list-disc space-y-1.5 pl-4 text-[11px] text-ink-muted">
                <li>
                  Contact the platform administrator with official registration credentials.
                </li>
                <li>
                  Provide your business registration documents (such as GSTIN Certificate, Udyam Registration, or Trade License).
                </li>
                <li>
                  Reference listing identifier:{' '}
                  <code className="font-mono font-semibold text-ink">{businessPublicId}</code>.
                </li>
                <li>
                  Authorized administrators will audit your documentation and transfer listing access.
                </li>
              </ul>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2.5 border-t border-gray-100 pt-3">
              <button
                type="button"
                onClick={onClose}
                className="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-ink-muted hover:bg-gray-50"
              >
                Close
              </button>
              <button
                type="button"
                disabled
                aria-disabled="true"
                className="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-100 px-4 py-2 text-xs font-semibold text-gray-400"
              >
                <span>Online Submission Disabled</span>
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
