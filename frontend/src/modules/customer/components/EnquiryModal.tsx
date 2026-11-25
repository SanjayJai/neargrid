'use client';

import { useEffect, useState } from 'react';
import {
  AlertCircle,
  CheckCircle2,
  Clock,
  Loader2,
  Lock,
  Phone,
  RotateCcw,
  Send,
  ShieldCheck,
  X,
} from 'lucide-react';

interface EnquiryModalProps {
  isOpen: boolean;
  onClose: () => void;
  businessName?: string;
  businessSlug?: string;
  categorySlug: string;
  categoryName?: string;
  citySlug: string;
  cityName?: string;
  localitySlug?: string;
  localityName?: string;
  phoneMasked?: string;
}

type ModalStep = 'form' | 'otp' | 'success';

export function EnquiryModal({
  isOpen,
  onClose,
  businessName,
  businessSlug,
  categorySlug,
  categoryName,
  citySlug,
  cityName,
  localitySlug,
  localityName,
  phoneMasked,
}: EnquiryModalProps) {
  const isDirect = Boolean(businessSlug && businessName);

  const [step, setStep] = useState<ModalStep>('form');
  const [customerName, setCustomerName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [requirement, setRequirement] = useState('');
  const [urgency, setUrgency] = useState<'immediate' | 'within_week' | 'flexible'>('immediate');
  const [consentGiven, setConsentGiven] = useState(false);

  // OTP state
  const [otp, setOtp] = useState('');
  const [enquiryPublicId, setEnquiryPublicId] = useState<string | null>(null);
  const [resendTimer, setResendTimer] = useState<number>(45);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Reset modal state on open
  useEffect(() => {
    if (isOpen) {
      setStep('form');
      setOtp('');
      setErrorMessage(null);
      setIsSubmitting(false);
    }
  }, [isOpen]);

  // Resend OTP countdown timer
  useEffect(() => {
    if (step !== 'otp' || resendTimer <= 0) return;
    const interval = setInterval(() => {
      setResendTimer((prev) => (prev > 0 ? prev - 1 : 0));
    }, 1000);
    return () => clearInterval(interval);
  }, [step, resendTimer]);

  // Trap body scroll & ESC key
  useEffect(() => {
    if (!isOpen) return;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape' && !isSubmitting) {
        onClose();
      }
    }

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen, isSubmitting, onClose]);

  if (!isOpen) return null;

  async function getCsrfToken(): Promise<string | null> {
    try {
      const res = await fetch('/api/auth/csrf');
      if (!res.ok) return null;
      const json = await res.json();
      return json?.data?.csrf_token ?? null;
    } catch {
      return null;
    }
  }

  async function handleFormSubmit(e: React.FormEvent) {
    e.preventDefault();
    setErrorMessage(null);

    const cleanPhone = phone.replace(/\D/g, '');
    if (cleanPhone.length !== 10 || !/^[6-9]\d{9}$/.test(cleanPhone)) {
      setErrorMessage('Please enter a valid 10-digit Indian mobile number.');
      return;
    }

    if (requirement.trim().length < 10) {
      setErrorMessage('Please provide at least 10 characters describing your requirement.');
      return;
    }

    if (!consentGiven) {
      setErrorMessage('You must affirmatively accept the data sharing consent notice.');
      return;
    }

    setIsSubmitting(true);

    try {
      const csrfToken = await getCsrfToken();
      const phoneE164 = `+91${cleanPhone}`;

      const res = await fetch('/api/public/enquiries', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
        },
        body: JSON.stringify({
          customer_name: customerName.trim(),
          phone_e164: phoneE164,
          email: email.trim() || undefined,
          requirement: requirement.trim(),
          urgency,
          category_slug: categorySlug,
          city_slug: citySlug,
          locality_slug: localitySlug || undefined,
          target_business_slug: businessSlug || undefined,
          consent_given: true,
          consent_version: '2026.1',
        }),
      });

      const json = await res.json();

      if (!res.ok) {
        setErrorMessage(json?.error?.message || 'Failed to submit enquiry. Please try again.');
        setIsSubmitting(false);
        return;
      }

      const data = json.data;
      setEnquiryPublicId(data.public_id);

      if (data.requires_otp) {
        setResendTimer(data.resend_after_seconds || 45);
        setStep('otp');
      } else {
        setStep('success');
      }
    } catch {
      setErrorMessage('Network error while submitting enquiry. Please check your connection.');
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleOtpSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!enquiryPublicId) return;

    const cleanOtp = otp.replace(/\D/g, '');
    if (cleanOtp.length !== 6) {
      setErrorMessage('Please enter the 6-digit OTP sent to your phone.');
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);

    try {
      const csrfToken = await getCsrfToken();

      const res = await fetch(`/api/public/enquiries/${enquiryPublicId}/verify-otp`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
        },
        body: JSON.stringify({
          otp: cleanOtp,
          device_name: 'NearGrid Web Browser',
        }),
      });

      const json = await res.json();

      if (!res.ok) {
        setErrorMessage(json?.error?.message || 'Invalid or expired OTP. Please try again.');
        setIsSubmitting(false);
        return;
      }

      setStep('success');
    } catch {
      setErrorMessage('Network error during OTP verification.');
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="enquiry-modal-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm overflow-y-auto"
      onClick={(e) => {
        if (e.target === e.currentTarget && !isSubmitting) onClose();
      }}
    >
      <div className="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl my-8">
        <button
          type="button"
          onClick={onClose}
          disabled={isSubmitting}
          aria-label="Close dialog"
          className="absolute right-4 top-4 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-50"
        >
          <X className="h-5 w-5" aria-hidden="true" />
        </button>

        {/* Step 1: Enquiry Form */}
        {step === 'form' && (
          <form onSubmit={handleFormSubmit} className="space-y-4">
            <div className="pr-6">
              <span className="inline-flex items-center gap-1 rounded-full border border-trust-200 bg-trust-50 px-2.5 py-0.5 text-[11px] font-semibold text-trust-800">
                <ShieldCheck className="h-3 w-3" aria-hidden="true" />
                Verified &amp; DPDP Consented Lead
              </span>
              <h2 id="enquiry-modal-title" className="mt-2 text-lg font-bold text-ink">
                {isDirect ? `Get Best Quotes from ${businessName}` : `Get Quotes for ${categoryName || 'Services'}`}
              </h2>
              <p className="mt-1 text-xs text-ink-muted">
                {isDirect
                  ? `Your requirement will be sent directly to ${businessName} in ${cityName || 'your city'}.`
                  : `Your requirement will be shared with up to 4 verified, top-rated businesses in ${localityName || cityName || 'your area'}.`}
              </p>
              {isDirect && phoneMasked && (
                <p className="mt-1.5 inline-flex items-center gap-1 text-[11px] font-medium text-trust-800">
                  <Phone className="h-3 w-3 text-trust-700" aria-hidden="true" />
                  <span>Verified Listing: {phoneMasked}</span>
                </p>
              )}
            </div>

            {errorMessage && (
              <div
                role="alert"
                className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-900"
              >
                <AlertCircle className="h-4 w-4 text-red-600 shrink-0 mt-0.5" aria-hidden="true" />
                <span>{errorMessage}</span>
              </div>
            )}

            <div className="space-y-3">
              <div>
                <label htmlFor="enquiry-name" className="block text-xs font-semibold text-ink">
                  Your Full Name <span className="text-red-500">*</span>
                </label>
                <input
                  id="enquiry-name"
                  type="text"
                  required
                  placeholder="e.g. Ramesh Kumar"
                  value={customerName}
                  onChange={(e) => setCustomerName(e.target.value)}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-xs text-ink placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                />
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <label htmlFor="enquiry-phone" className="block text-xs font-semibold text-ink">
                    10-Digit Mobile Number <span className="text-red-500">*</span>
                  </label>
                  <div className="relative mt-1">
                    <span className="absolute inset-y-0 left-0 flex items-center pl-3 text-xs font-semibold text-gray-500">
                      +91
                    </span>
                    <input
                      id="enquiry-phone"
                      type="tel"
                      required
                      maxLength={10}
                      placeholder="9840123456"
                      value={phone}
                      onChange={(e) => setPhone(e.target.value.replace(/\D/g, '').slice(0, 10))}
                      className="block w-full rounded-lg border border-gray-300 pl-11 pr-3 py-2 text-xs text-ink placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                    />
                  </div>
                </div>

                <div>
                  <label htmlFor="enquiry-email" className="block text-xs font-semibold text-ink">
                    Email Address <span className="text-gray-400 font-normal">(Optional)</span>
                  </label>
                  <input
                    id="enquiry-email"
                    type="email"
                    placeholder="name@example.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-xs text-ink placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                  />
                </div>
              </div>

              <div>
                <label htmlFor="enquiry-requirement" className="block text-xs font-semibold text-ink">
                  Requirement Details <span className="text-red-500">*</span>
                </label>
                <textarea
                  id="enquiry-requirement"
                  required
                  rows={3}
                  minLength={10}
                  placeholder="Describe the services or consultation you need..."
                  value={requirement}
                  onChange={(e) => setRequirement(e.target.value)}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-xs text-ink placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-ink mb-1">
                  How soon do you need this?
                </label>
                <div className="grid grid-cols-3 gap-2">
                  {([
                    { id: 'immediate', label: 'Immediately' },
                    { id: 'within_week', label: 'Within a week' },
                    { id: 'flexible', label: 'Flexible' },
                  ] as const).map((option) => (
                    <button
                      key={option.id}
                      type="button"
                      onClick={() => setUrgency(option.id)}
                      className={`rounded-lg border px-3 py-2 text-center text-xs font-semibold transition-colors ${
                        urgency === option.id
                          ? 'border-brand-600 bg-brand-50 text-brand-700'
                          : 'border-gray-200 bg-gray-50 text-ink-muted hover:bg-gray-100'
                      }`}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Explicit DPDP Consent Disclosure */}
              <div className="rounded-xl border border-gray-200 bg-gray-50/80 p-3.5 space-y-2">
                <label className="flex items-start gap-2.5 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={consentGiven}
                    onChange={(e) => setConsentGiven(e.target.checked)}
                    className="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500 shrink-0"
                  />
                  <span className="text-[11px] leading-relaxed text-ink-muted">
                    <strong className="text-ink font-semibold">Consent Disclosure: </strong>
                    {isDirect
                      ? `I consent to NearGrid sharing my name, phone number, and enquiry details directly with ${businessName} for receiving callbacks and price estimates.`
                      : `I consent to NearGrid sharing my requirement and contact details with up to 4 eligible, verified businesses matching ${categoryName || 'this category'} in ${localityName || cityName || 'my city'} for competitive quotes.`}
                  </span>
                </label>
                <div className="flex items-center gap-1.5 text-[10px] text-ink-subtle pl-6">
                  <Lock className="h-3 w-3" aria-hidden="true" />
                  <span>Protected under Digital Personal Data Protection (DPDP) Act. Strictly zero automated spam.</span>
                </div>
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5 border-t border-gray-100 pt-4">
              <button
                type="button"
                onClick={onClose}
                disabled={isSubmitting}
                className="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-ink-muted hover:bg-gray-50 disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={isSubmitting || !consentGiven}
                className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {isSubmitting ? (
                  <>
                    <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                    <span>Submitting...</span>
                  </>
                ) : (
                  <>
                    <Send className="h-3.5 w-3.5" aria-hidden="true" />
                    <span>Send Enquiry</span>
                  </>
                )}
              </button>
            </div>
          </form>
        )}

        {/* Step 2: OTP Verification */}
        {step === 'otp' && (
          <form onSubmit={handleOtpSubmit} className="space-y-4">
            <div className="pr-6">
              <span className="inline-flex items-center gap-1 rounded-full border border-brand-200 bg-brand-50 px-2.5 py-0.5 text-[11px] font-semibold text-brand-700">
                <Clock className="h-3 w-3" aria-hidden="true" />
                Step 2 of 2: Mobile Verification
              </span>
              <h2 id="enquiry-modal-title" className="mt-2 text-lg font-bold text-ink">
                Verify Mobile Number
              </h2>
              <p className="mt-1 text-xs text-ink-muted">
                Please enter the 6-digit one-time password (OTP) sent to{' '}
                <strong className="text-ink font-semibold">+91 {phone}</strong> to confirm your enquiry.
              </p>
            </div>

            {errorMessage && (
              <div
                role="alert"
                className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-900"
              >
                <AlertCircle className="h-4 w-4 text-red-600 shrink-0 mt-0.5" aria-hidden="true" />
                <span>{errorMessage}</span>
              </div>
            )}

            <div>
              <label htmlFor="enquiry-otp" className="block text-xs font-semibold text-ink">
                6-Digit OTP Code
              </label>
              <input
                id="enquiry-otp"
                type="text"
                required
                maxLength={6}
                autoFocus
                placeholder="Enter 6-digit code"
                value={otp}
                onChange={(e) => setOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
                className="mt-1 block w-full tracking-widest text-center font-mono text-lg rounded-lg border border-gray-300 py-2.5 text-ink placeholder:text-gray-300 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
              />
            </div>

            <div className="flex items-center justify-between text-xs text-ink-muted pt-1">
              <span>Didn&apos;t receive code?</span>
              {resendTimer > 0 ? (
                <span className="font-semibold text-ink-muted">Resend in {resendTimer}s</span>
              ) : (
                <button
                  type="button"
                  onClick={handleFormSubmit}
                  disabled={isSubmitting}
                  className="inline-flex items-center gap-1 font-semibold text-brand-600 hover:underline disabled:opacity-50"
                >
                  <RotateCcw className="h-3 w-3" aria-hidden="true" />
                  <span>Resend OTP</span>
                </button>
              )}
            </div>

            <div className="flex items-center justify-end gap-2.5 border-t border-gray-100 pt-4">
              <button
                type="button"
                onClick={() => setStep('form')}
                disabled={isSubmitting}
                className="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-ink-muted hover:bg-gray-50 disabled:opacity-50"
              >
                Back
              </button>
              <button
                type="submit"
                disabled={isSubmitting || otp.length !== 6}
                className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {isSubmitting ? (
                  <>
                    <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                    <span>Verifying...</span>
                  </>
                ) : (
                  <span>Verify &amp; Confirm Enquiry</span>
                )}
              </button>
            </div>
          </form>
        )}

        {/* Step 3: Success Screen */}
        {step === 'success' && (
          <div className="py-4 text-center space-y-4">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-trust-100 text-trust-700">
              <CheckCircle2 className="h-8 w-8" aria-hidden="true" />
            </div>

            <div>
              <h2 className="text-lg font-bold text-ink">Enquiry Dispatched Successfully!</h2>
              <p className="mt-1.5 text-xs text-ink-muted max-w-sm mx-auto leading-relaxed">
                {isDirect
                  ? `Your requirement has been sent to ${businessName}. They will contact you shortly via phone or WhatsApp.`
                  : `Your requirement has been matched with up to 4 eligible verified businesses in ${localityName || cityName || 'your city'}. You will receive competitive quotes soon.`}
              </p>
            </div>

            <div className="rounded-xl border border-gray-100 bg-gray-50 p-3.5 text-left text-xs space-y-1.5">
              <div className="flex justify-between text-ink-muted">
                <span>Customer:</span>
                <span className="font-semibold text-ink">{customerName}</span>
              </div>
              <div className="flex justify-between text-ink-muted">
                <span>Phone:</span>
                <span className="font-semibold text-ink">+91 {phone}</span>
              </div>
              <div className="flex justify-between text-ink-muted">
                <span>Urgency:</span>
                <span className="font-semibold text-brand-700 capitalize">{urgency.replace('_', ' ')}</span>
              </div>
            </div>

            <button
              type="button"
              onClick={onClose}
              className="w-full rounded-lg bg-brand-600 py-2.5 text-xs font-semibold text-white hover:bg-brand-700 shadow-sm"
            >
              Done
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
