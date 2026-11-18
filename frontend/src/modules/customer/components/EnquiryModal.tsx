'use client';

import { FormEvent, useEffect, useState } from 'react';
import { CheckCircle2, ShieldCheck, X } from 'lucide-react';

interface EnquiryModalProps {
  isOpen: boolean;
  onClose: () => void;
  businessName: string;
  businessPublicId?: string;
  phoneMasked?: string;
}

export function EnquiryModal({
  isOpen,
  onClose,
  businessName,
  phoneMasked,
}: EnquiryModalProps) {
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [requirement, setRequirement] = useState('');
  const [consentGiven, setConsentGiven] = useState(false);
  const [submitted, setSubmitted] = useState(false);

  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
      setSubmitted(false);
    }
    return () => {
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  if (!isOpen) return null;

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!consentGiven) return;
    // Phase 3A/3B UI: Enquiry dispatch backend is scheduled for Phase 3C (POST /api/v1/enquiries).
    setSubmitted(true);
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="enquiry-modal-title"
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-in fade-in duration-200"
    >
      <div className="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
        <button
          type="button"
          onClick={onClose}
          aria-label="Close dialog"
          className="absolute right-4 top-4 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
        >
          <X className="h-5 w-5" aria-hidden="true" />
        </button>

        <div className="mb-4">
          <div className="flex items-center gap-2">
            <span className="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold text-brand-700">
              Direct Enquiry
            </span>
            <span className="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
              Phase 3C Preview
            </span>
          </div>
          <h2
            id="enquiry-modal-title"
            className="mt-2 text-lg font-bold text-ink"
          >
            Contact {businessName}
          </h2>
          <p className="mt-1 text-xs text-ink-muted">
            Send your requirement directly to this business.
            {phoneMasked ? ` Or reach them directly at ${phoneMasked}.` : ''}
          </p>
        </div>

        {submitted ? (
          <div className="space-y-4 rounded-xl border border-trust-200 bg-trust-50 p-5 text-sm text-trust-900">
            <div className="flex items-center gap-2 font-semibold">
              <CheckCircle2 className="h-5 w-5 text-trust-700 shrink-0" aria-hidden="true" />
              <span>Enquiry Preview Recorded</span>
            </div>
            <p className="text-xs text-trust-800 leading-relaxed">
              Thank you, <strong>{name}</strong>. In production, this enquiry will be
              dispatched immediately via SMS/WhatsApp to {businessName}.
            </p>
            <div className="rounded-lg bg-white/80 p-3 text-xs text-ink-muted border border-trust-100">
              <p className="font-semibold text-ink">Phase 3C Architecture Notice:</p>
              <p className="mt-0.5">
                Backend enquiry routing (POST /api/v1/enquiries) and lead SMS notifications
                will connect in Phase 3C with customer consent verification.
              </p>
            </div>
            <div className="pt-2 flex justify-end">
              <button
                type="button"
                onClick={onClose}
                className="rounded-lg bg-trust-700 px-4 py-2 text-xs font-semibold text-white hover:bg-trust-800"
              >
                Done
              </button>
            </div>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label
                htmlFor="enquiry-name"
                className="mb-1 block text-xs font-semibold text-ink"
              >
                Your Name <span className="text-signal-600">*</span>
              </label>
              <input
                id="enquiry-name"
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Enter your full name"
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-ink focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
              />
            </div>

            <div>
              <label
                htmlFor="enquiry-phone"
                className="mb-1 block text-xs font-semibold text-ink"
              >
                Mobile Number <span className="text-signal-600">*</span>
              </label>
              <input
                id="enquiry-phone"
                type="tel"
                required
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="e.g. 9840012345"
                pattern="^[0-9+ -]{10,15}$"
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-ink focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
              />
            </div>

            <div>
              <label
                htmlFor="enquiry-requirement"
                className="mb-1 block text-xs font-semibold text-ink"
              >
                Requirement / Message <span className="text-signal-600">*</span>
              </label>
              <textarea
                id="enquiry-requirement"
                rows={3}
                required
                value={requirement}
                onChange={(e) => setRequirement(e.target.value)}
                placeholder="Describe the product, service, or pricing you need..."
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-ink focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
              />
            </div>

            <label className="flex items-start gap-2.5 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-ink-muted cursor-pointer hover:bg-gray-100">
              <input
                type="checkbox"
                required
                checked={consentGiven}
                onChange={(e) => setConsentGiven(e.target.checked)}
                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
              />
              <span className="leading-relaxed">
                I authorize NearGrid and {businessName} to contact me via Call or
                WhatsApp regarding this enquiry. (DPDP Act compliant)
              </span>
            </label>

            <div className="flex items-center justify-end gap-3 pt-2">
              <button
                type="button"
                onClick={onClose}
                className="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-ink-muted hover:bg-gray-100"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={!consentGiven}
                className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-5 py-2 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm"
              >
                <ShieldCheck className="h-4 w-4" aria-hidden="true" />
                Submit Enquiry
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
