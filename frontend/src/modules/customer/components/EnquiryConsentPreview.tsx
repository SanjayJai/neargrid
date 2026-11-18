'use client';

import { FormEvent, useState } from 'react';
import { CheckCircle2, ShieldAlert } from 'lucide-react';

interface EnquiryConsentPreviewProps {
  businessName: string;
}

export function EnquiryConsentPreview({ businessName }: EnquiryConsentPreviewProps) {
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [requirement, setRequirement] = useState('');
  const [consentGiven, setConsentGiven] = useState(false);
  const [submittedDemo, setSubmittedDemo] = useState(false);

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!consentGiven) return;
    // Phase 3A UI Preview ONLY: does not transmit data until Phase 3C connects POST /api/v1/enquiries.
    setSubmittedDemo(true);
  }

  return (
    <div id="enquire" className="rounded-2xl border border-zinc-200 bg-white p-6">
      <div className="mb-4 flex items-center justify-between gap-2">
        <h2 className="text-base font-semibold text-zinc-950">Send Direct Enquiry</h2>
        <span className="rounded-full border border-dashed border-zinc-300 px-2.5 py-0.5 text-xs font-medium text-zinc-600">
          Demo Form
        </span>
      </div>

      {submittedDemo ? (
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
          <div className="flex items-center gap-2 font-semibold">
            <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
            Demo submission recorded locally only
          </div>
          <p className="mt-1 text-xs text-emerald-800">
            No network request was sent. Backend enquiry creation (POST /api/v1/enquiries) will be
            connected in Phase 3C.
          </p>
          <button
            type="button"
            onClick={() => setSubmittedDemo(false)}
            className="mt-3 text-xs font-semibold underline"
          >
            Reset demo form
          </button>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-3.5">
          <div>
            <label htmlFor="enq-name" className="mb-1 block text-xs font-medium text-zinc-700">
              Full Name
            </label>
            <input
              id="enq-name"
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Your name"
              className="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-950 focus:border-zinc-950 focus:outline-none"
            />
          </div>

          <div>
            <label htmlFor="enq-phone" className="mb-1 block text-xs font-medium text-zinc-700">
              Mobile Number
            </label>
            <input
              id="enq-phone"
              type="tel"
              required
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              placeholder="+919840012345"
              className="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-950 focus:border-zinc-950 focus:outline-none"
            />
          </div>

          <div>
            <label htmlFor="enq-req" className="mb-1 block text-xs font-medium text-zinc-700">
              Service Requirement
            </label>
            <textarea
              id="enq-req"
              rows={3}
              required
              value={requirement}
              onChange={(e) => setRequirement(e.target.value)}
              placeholder="Briefly describe what you need..."
              className="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-950 focus:border-zinc-950 focus:outline-none"
            />
          </div>

          <label className="flex cursor-pointer items-start gap-2.5 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs leading-relaxed text-zinc-700">
            <input
              type="checkbox"
              required
              checked={consentGiven}
              onChange={(e) => setConsentGiven(e.target.checked)}
              className="mt-0.5 h-4 w-4 shrink-0 accent-zinc-950"
            />
            <span>
              I explicitly consent to sharing my name, mobile number, and enquiry details with{' '}
              <strong className="text-zinc-950">{businessName}</strong>. Zero data is transmitted
              until you submit.
            </span>
          </label>

          <button
            type="submit"
            disabled={!consentGiven}
            className="w-full rounded-xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-zinc-800 disabled:opacity-40"
          >
            Preview Enquiry Submission
          </button>

          <p className="flex items-center gap-1.5 text-[11px] text-zinc-500">
            <ShieldAlert className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            Privacy-by-design: draft inputs remain strictly local in your browser.
          </p>
        </form>
      )}
    </div>
  );
}
