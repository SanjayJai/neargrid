'use client';

import { useEffect } from 'react';
import Link from 'next/link';
import { ArrowRight, Clock, Phone, ShieldCheck, X } from 'lucide-react';

interface EnquiryModalProps {
  isOpen: boolean;
  onClose: () => void;
  businessName: string;
  profileHref: string;
  phoneMasked?: string;
}

/**
 * Honest feature-status dialog for Direct Enquiries.
 * Does NOT collect PII or claim to submit an enquiry while Phase 3C lead dispatch is unimplemented.
 */
export function EnquiryModal({
  isOpen,
  onClose,
  businessName,
  profileHref,
  phoneMasked,
}: EnquiryModalProps) {
  useEffect(() => {
    if (!isOpen) return;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        onClose();
      }
    }

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="enquiry-modal-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
        <button
          type="button"
          onClick={onClose}
          aria-label="Close dialog"
          className="absolute right-4 top-4 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
        >
          <X className="h-5 w-5" aria-hidden="true" />
        </button>

        <div className="mb-4 pr-6">
          <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-900">
            <Clock className="h-3 w-3" aria-hidden="true" />
            Phase 3C Feature — Coming Soon
          </span>
          <h2 id="enquiry-modal-title" className="mt-2.5 text-lg font-bold text-ink">
            Direct Enquiries for {businessName}
          </h2>
        </div>

        <div className="space-y-3 rounded-xl border border-amber-200 bg-amber-50/70 p-4 text-xs leading-relaxed text-amber-950">
          <p className="font-semibold">
            Online enquiry submission is not active yet.
          </p>
          <p className="text-amber-900">
            Consented lead dispatch and merchant enquiry notifications are scheduled for
            Phase 3C. NearGrid does not collect, store, or forward enquiry form data from
            this dialog.
          </p>
        </div>

        {phoneMasked && (
          <div className="mt-4 flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-3 text-xs">
            <div className="flex items-center gap-2 text-ink">
              <Phone className="h-4 w-4 text-trust-700 shrink-0" aria-hidden="true" />
              <span className="font-semibold">{phoneMasked}</span>
            </div>
            <span className="inline-flex items-center gap-1 text-[11px] text-ink-muted">
              <ShieldCheck className="h-3.5 w-3.5 text-trust-600" aria-hidden="true" />
              Masked for privacy
            </span>
          </div>
        )}

        <div className="mt-5 flex flex-wrap items-center justify-end gap-2.5 border-t border-gray-100 pt-4">
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-ink-muted hover:bg-gray-50"
          >
            Close
          </button>
          <Link
            href={profileHref}
            onClick={onClose}
            className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
          >
            <span>View Business Profile</span>
            <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
        </div>
      </div>
    </div>
  );
}
