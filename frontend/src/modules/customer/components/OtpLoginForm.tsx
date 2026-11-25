'use client';

import { useRouter } from 'next/navigation';
import { FormEvent, useEffect, useState } from 'react';
import { ArrowRight, KeyRound, Lock, RefreshCw, Smartphone } from 'lucide-react';
import type { BffAuthClientData, OtpChallengeResponseDto } from '@/lib/bff/types';
import { bffFetch } from '@/shared/api/bff-client';

interface OtpLoginFormProps {
  redirectTo?: string;
}

export function sanitizeInternalRedirectPath(raw?: string | null): string {
  if (!raw) return '/dashboard/settings';
  const trimmed = raw.trim();
  if (!trimmed.startsWith('/') || trimmed.startsWith('//') || trimmed.includes('\\')) {
    return '/dashboard/settings';
  }
  return trimmed;
}

export function OtpLoginForm({ redirectTo }: OtpLoginFormProps) {
  const router = useRouter();
  const safeRedirectTarget = sanitizeInternalRedirectPath(redirectTo);
  const [step, setStep] = useState<'phone' | 'otp'>('phone');
  const [phone, setPhone] = useState('+91');
  const [name, setName] = useState('');
  const [challengeId, setChallengeId] = useState('');
  const [otp, setOtp] = useState('');
  const [cooldown, setCooldown] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = setInterval(() => {
      setCooldown((prev) => (prev > 0 ? prev - 1 : 0));
    }, 1000);
    return () => clearInterval(timer);
  }, [cooldown]);

  async function handleRequestOtp(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setErrorMessage(null);
    setInfoMessage(null);
    setSubmitting(true);

    try {
      const res = await bffFetch<OtpChallengeResponseDto>('/api/auth/otp/request', {
        method: 'POST',
        body: {
          phone_e164: phone.trim(),
          purpose: 'login',
        },
      });

      if (!res.ok || res.payload?.status !== 'success') {
        const msg = res.payload?.status === 'error'
          ? res.payload.error.message
          : 'Unable to send OTP. Ensure the Laravel API server is running.';
        setErrorMessage(msg);
        return;
      }

      setChallengeId(res.payload.data.challenge_id);
      setCooldown(res.payload.data.resend_after_seconds);
      setStep('otp');
      setInfoMessage('Verification code dispatched via Laravel OTP API. Enter the 6-digit code below.');
    } catch {
      setErrorMessage('Network error communicating with Next.js BFF.');
    } finally {
      setSubmitting(false);
    }
  }

  async function handleResendOtp() {
    if (cooldown > 0 || !challengeId) return;
    setErrorMessage(null);
    setInfoMessage(null);
    setSubmitting(true);

    try {
      const res = await bffFetch<OtpChallengeResponseDto>('/api/auth/otp/resend', {
        method: 'POST',
        body: {
          challenge_id: challengeId,
          phone_e164: phone.trim(),
          purpose: 'login',
        },
      });

      if (!res.ok || res.payload?.status !== 'success') {
        const msg = res.payload?.status === 'error'
          ? res.payload.error.message
          : 'Unable to resend OTP right now.';
        setErrorMessage(msg);
        return;
      }

      setChallengeId(res.payload.data.challenge_id);
      setCooldown(res.payload.data.resend_after_seconds);
      setInfoMessage('A new 6-digit verification code has been requested.');
    } finally {
      setSubmitting(false);
    }
  }

  async function handleVerifyOtp(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setErrorMessage(null);
    setSubmitting(true);

    try {
      const res = await bffFetch<BffAuthClientData>('/api/auth/otp/verify', {
        method: 'POST',
        body: {
          challenge_id: challengeId,
          phone_e164: phone.trim(),
          purpose: 'login',
          otp: otp.trim(),
          ...(name.trim() ? { name: name.trim() } : {}),
        },
      });

      if (!res.ok || res.payload?.status !== 'success') {
        const msg = res.payload?.status === 'error'
          ? res.payload.error.message
          : 'Verification failed. Please check the code and try again.';
        setErrorMessage(msg);
        return;
      }

      // Opaque HttpOnly session cookie is now set by Next.js BFF; Bearer token stays strictly server-side.
      router.push(safeRedirectTarget);
      router.refresh();
    } catch {
      setErrorMessage('Network error during OTP verification.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="mx-auto w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
      <div className="mb-6">
        <div className="mb-3 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-950 text-white">
          {step === 'phone' ? (
            <Smartphone className="h-5 w-5" aria-hidden="true" />
          ) : (
            <KeyRound className="h-5 w-5" aria-hidden="true" />
          )}
        </div>
        <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">
          {step === 'phone' ? 'Sign in with Mobile OTP' : 'Verify 6-digit code'}
        </h1>
        <p className="mt-1.5 text-sm text-zinc-600">
          {step === 'phone'
            ? 'Live-connected to Laravel 13 Identity API via Next.js BFF with opaque HttpOnly sessions.'
            : `Enter the 6-digit OTP sent to ${phone}.`}
        </p>
      </div>

      {errorMessage && (
        <div
          role="alert"
          className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
        >
          {errorMessage}
        </div>
      )}

      {infoMessage && (
        <div
          role="status"
          className="mb-4 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700"
        >
          {infoMessage}
        </div>
      )}

      {step === 'phone' ? (
        <form onSubmit={handleRequestOtp} className="space-y-4">
          <div>
            <label htmlFor="phone-input" className="mb-1.5 block text-sm font-medium text-zinc-900">
              Mobile Number (E.164)
            </label>
            <input
              id="phone-input"
              type="tel"
              required
              autoComplete="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              placeholder="+919840012345"
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2.5 text-sm text-zinc-950 placeholder:text-zinc-400 focus:border-zinc-950 focus:outline-none"
            />
            <p className="mt-1 text-xs text-zinc-500">
              Indian mobile numbers (+91) are permitted by default anti-pumping policy.
            </p>
          </div>

          <div>
            <label htmlFor="name-input" className="mb-1.5 block text-sm font-medium text-zinc-900">
              Your Name <span className="font-normal text-zinc-400">(optional for new accounts)</span>
            </label>
            <input
              id="name-input"
              type="text"
              autoComplete="name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Karthik Sundaram"
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2.5 text-sm text-zinc-950 placeholder:text-zinc-400 focus:border-zinc-950 focus:outline-none"
            />
          </div>

          <button
            type="submit"
            disabled={submitting}
            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-zinc-800 disabled:opacity-50"
          >
            {submitting ? 'Sending OTP...' : 'Continue with OTP'}
            <ArrowRight className="h-4 w-4" aria-hidden="true" />
          </button>
        </form>
      ) : (
        <form onSubmit={handleVerifyOtp} className="space-y-4">
          <div>
            <label htmlFor="otp-input" className="mb-1.5 block text-sm font-medium text-zinc-900">
              6-Digit Verification Code
            </label>
            <input
              id="otp-input"
              type="text"
              inputMode="numeric"
              pattern="\d{6}"
              maxLength={6}
              required
              autoComplete="one-time-code"
              value={otp}
              onChange={(e) => setOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
              placeholder="Enter 6-digit code"
              className="w-full rounded-xl border border-zinc-300 px-4 py-3 text-center text-lg font-semibold tracking-[0.4em] text-zinc-950 focus:border-zinc-950 focus:outline-none"
            />
          </div>

          <button
            type="submit"
            disabled={submitting || otp.length !== 6}
            className="w-full rounded-xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-zinc-800 disabled:opacity-50"
          >
            {submitting ? 'Verifying...' : 'Verify & Sign In'}
          </button>

          <div className="flex items-center justify-between pt-2 text-xs">
            <button
              type="button"
              onClick={() => {
                setStep('phone');
                setOtp('');
                setErrorMessage(null);
              }}
              className="font-medium text-zinc-600 hover:text-zinc-950"
            >
              Change phone number
            </button>

            <button
              type="button"
              onClick={handleResendOtp}
              disabled={cooldown > 0 || submitting}
              className="inline-flex items-center gap-1 font-medium text-zinc-900 disabled:text-zinc-400"
            >
              <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
              {cooldown > 0 ? `Resend in ${cooldown}s` : 'Resend OTP'}
            </button>
          </div>
        </form>
      )}

      <div className="mt-6 flex items-start gap-2 border-t border-zinc-100 pt-4 text-xs text-zinc-500">
        <Lock className="mt-0.5 h-3.5 w-3.5 shrink-0 text-zinc-700" aria-hidden="true" />
        <p>
          Protected by Next.js BFF: Sanctum Bearer tokens are stored server-side only and never
          exposed to browser JavaScript or localStorage.
        </p>
      </div>
    </div>
  );
}
