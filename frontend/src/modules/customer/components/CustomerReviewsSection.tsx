'use client';

import React, { useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  Flag,
  MessageSquare,
  ShieldCheck,
  Star,
  X,
} from 'lucide-react';
import type {
  PublicReviewDto,
  PublicReviewsBreakdownDto,
  SubmitReviewResult,
} from '@/shared/api/directory-types';

interface CustomerReviewsSectionProps {
  businessId: string;
  businessName: string;
  citySlug: string;
  initialRatingAvg: number;
  initialReviewCount: number;
}

export function CustomerReviewsSection({
  businessId,
  businessName,
  citySlug,
  initialRatingAvg,
  initialReviewCount,
}: CustomerReviewsSectionProps) {
  const [reviews, setReviews] = useState<PublicReviewDto[]>([]);
  const [breakdown, setBreakdown] = useState<PublicReviewsBreakdownDto>({
    '1': 0,
    '2': 0,
    '3': 0,
    '4': 0,
    '5': 0,
  });
  const [ratingAvg, setRatingAvg] = useState(initialRatingAvg);
  const [reviewCount, setReviewCount] = useState(initialReviewCount);
  const [isLoading, setIsLoading] = useState(true);
  const [activeFilter, setActiveFilter] = useState<number | null>(null);
  const [activeSort, setActiveSort] = useState<'newest' | 'highest' | 'lowest'>('newest');

  // Review submission dialog state
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [formRating, setFormRating] = useState(5);
  const [hoverRating, setHoverRating] = useState<number | null>(null);
  const [customerName, setCustomerName] = useState('');
  const [phone, setPhone] = useState('');
  const [title, setTitle] = useState('');
  const [content, setContent] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // OTP Challenge state for guest reviews
  const [pendingOtpReviewId, setPendingOtpReviewId] = useState<string | null>(null);
  const [otpCode, setOtpCode] = useState('');
  const [otpError, setOtpError] = useState<string | null>(null);
  const [isVerifyingOtp, setIsVerifyingOtp] = useState(false);

  // Reporting dialog state
  const [reportingReviewId, setReportingReviewId] = useState<string | null>(null);
  const [reportReason, setReportReason] = useState('spam');
  const [reportDetails, setReportDetails] = useState('');
  const [isSubmittingReport, setIsSubmittingReport] = useState(false);
  const [reportSuccess, setReportSuccess] = useState(false);

  const fetchReviews = useCallback(async () => {
    setIsLoading(true);
    try {
      const params = new URLSearchParams();
      if (activeFilter !== null) params.set('rating', String(activeFilter));
      params.set('sort', activeSort);

      const res = await fetch(
        `/api/public/businesses/${encodeURIComponent(businessId)}/reviews?${params.toString()}`
      );
      const json = await res.json();

      if (res.ok && json.status === 'success') {
        setReviews(json.data ?? []);
        if (json.meta?.breakdown) setBreakdown(json.meta.breakdown);
        if (typeof json.meta?.rating_avg === 'number') setRatingAvg(json.meta.rating_avg);
        if (typeof json.meta?.review_count === 'number') setReviewCount(json.meta.review_count);
      }
    } catch {
      // Graceful fallback
    } finally {
      setIsLoading(false);
    }
  }, [businessId, activeFilter, activeSort]);

  useEffect(() => {
    void fetchReviews();
  }, [fetchReviews]);

  const handleSubmitReview = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setSuccessMessage(null);

    if (!customerName.trim() || !phone.trim() || !content.trim()) {
      setErrorMessage('Please provide your name, phone number, and review details.');
      return;
    }

    setIsSubmitting(true);
    try {
      const res = await fetch(
        `/api/public/businesses/${encodeURIComponent(businessId)}/reviews`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            customer_name: customerName.trim(),
            phone_e164: phone.trim(),
            rating: formRating,
            title: title.trim() || undefined,
            content: content.trim(),
          }),
        }
      );

      const json = await res.json();
      if (!res.ok || json.status === 'error') {
        const msg =
          json.error?.code === 'DUPLICATE_REVIEW_30_DAYS'
            ? 'You have already submitted a review for this business in the last 30 days.'
            : json.error?.code === 'CANNOT_REVIEW_OWN_BUSINESS'
            ? 'Business owners and staff members cannot review their own business.'
            : json.error?.message || 'Unable to submit review.';
        setErrorMessage(msg);
        return;
      }

      const result = json.data as SubmitReviewResult;
      if (result?.requires_otp) {
        setPendingOtpReviewId(result.review.public_id);
      } else {
        setSuccessMessage('Thank you! Your verified review has been published.');
        setIsModalOpen(false);
        resetForm();
        void fetchReviews();
      }
    } catch {
      setErrorMessage('Network error. Please try again.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleVerifyOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!pendingOtpReviewId || !otpCode.trim()) return;

    setIsVerifyingOtp(true);
    setOtpError(null);

    try {
      const res = await fetch(`/api/public/reviews/${pendingOtpReviewId}/verify-otp`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: otpCode.trim() }),
      });

      const json = await res.json();
      if (!res.ok || json.status === 'error') {
        setOtpError(json.error?.message || 'Invalid or expired OTP code.');
        return;
      }

      setPendingOtpReviewId(null);
      setIsModalOpen(false);
      resetForm();
      setSuccessMessage('Review verified and published successfully!');
      void fetchReviews();
    } catch {
      setOtpError('Failed to verify OTP. Please try again.');
    } finally {
      setIsVerifyingOtp(false);
    }
  };

  const handleReportReview = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!reportingReviewId) return;

    setIsSubmittingReport(true);
    try {
      const res = await fetch(`/api/public/reviews/${reportingReviewId}/report`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          reason: reportReason,
          details: reportDetails.trim() || undefined,
        }),
      });

      if (res.ok) {
        setReportSuccess(true);
        setTimeout(() => {
          setReportingReviewId(null);
          setReportSuccess(false);
          setReportDetails('');
        }, 1500);
      }
    } catch {
      // Ignored
    } finally {
      setIsSubmittingReport(false);
    }
  };

  const resetForm = () => {
    setCustomerName('');
    setPhone('');
    setTitle('');
    setContent('');
    setFormRating(5);
    setOtpCode('');
    setOtpError(null);
    setPendingOtpReviewId(null);
  };

  const totalReviews = reviewCount;

  return (
    <div className="space-y-6">
      {/* 1. Header & Summary Row */}
      <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-xl font-bold text-ink">Ratings &amp; Reviews</h2>
          <p className="mt-1 text-sm text-ink-muted">
            Genuine experiences from verified local customers in {citySlug}.
          </p>
        </div>

        <button
          type="button"
          onClick={() => {
            setIsModalOpen(true);
            setErrorMessage(null);
            setSuccessMessage(null);
          }}
          className="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-primary/90 focus:outline-none focus:ring-2 focus:ring-brand-primary/20"
        >
          <Star className="h-4 w-4 fill-white" />
          Write a Review
        </button>
      </div>

      {successMessage && (
        <div className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
          <CheckCircle2 className="h-5 w-5 text-emerald-600" />
          <span>{successMessage}</span>
        </div>
      )}

      {/* 2. Rating Scoreboard & Distribution Breakdown */}
      <div className="grid grid-cols-1 gap-6 rounded-2xl border border-gray-100 bg-gray-50/80 p-5 sm:grid-cols-12 sm:p-6">
        <div className="flex flex-col items-center justify-center text-center sm:col-span-4 sm:border-r sm:border-gray-200/80 sm:pr-6">
          <span className="text-4xl font-extrabold tracking-tight text-ink">
            {ratingAvg > 0 ? ratingAvg.toFixed(1) : '—'}
          </span>
          <div className="mt-2 flex items-center gap-1">
            {[1, 2, 3, 4, 5].map((s) => (
              <Star
                key={s}
                className={`h-5 w-5 ${
                  s <= Math.round(ratingAvg)
                    ? 'fill-amber-400 text-amber-400'
                    : 'fill-gray-200 text-gray-200'
                }`}
              />
            ))}
          </div>
          <span className="mt-2 text-xs font-medium text-ink-muted">
            Based on {totalReviews} {totalReviews === 1 ? 'review' : 'reviews'}
          </span>
        </div>

        <div className="space-y-2 sm:col-span-8 sm:pl-2">
          {[5, 4, 3, 2, 1].map((stars) => {
            const count = breakdown[String(stars) as keyof PublicReviewsBreakdownDto] || 0;
            const pct = totalReviews > 0 ? Math.round((count / totalReviews) * 100) : 0;
            const isSelected = activeFilter === stars;

            return (
              <button
                key={stars}
                type="button"
                onClick={() => setActiveFilter(isSelected ? null : stars)}
                className={`flex w-full items-center gap-3 rounded-lg px-2 py-1 text-xs transition ${
                  isSelected ? 'bg-amber-100/60 font-bold' : 'hover:bg-gray-100'
                }`}
              >
                <span className="w-10 text-right font-medium text-ink">
                  {stars} {stars === 1 ? 'star' : 'stars'}
                </span>
                <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-200">
                  <div
                    className="h-full rounded-full bg-amber-400 transition-all duration-300"
                    style={{ width: `${pct}%` }}
                  />
                </div>
                <span className="w-8 text-right font-medium text-ink-muted">{count}</span>
              </button>
            );
          })}
        </div>
      </div>

      {/* 3. Filter Pills & Sorting Controls */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3">
        <div className="flex flex-wrap items-center gap-1.5">
          <button
            type="button"
            onClick={() => setActiveFilter(null)}
            className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
              activeFilter === null
                ? 'bg-ink text-white'
                : 'bg-gray-100 text-ink-muted hover:bg-gray-200'
            }`}
          >
            All Reviews ({totalReviews})
          </button>
          {[5, 4, 3, 2, 1].map((s) => (
            <button
              key={s}
              type="button"
              onClick={() => setActiveFilter(activeFilter === s ? null : s)}
              className={`inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${
                activeFilter === s
                  ? 'bg-amber-500 text-white'
                  : 'bg-gray-100 text-ink-muted hover:bg-gray-200'
              }`}
            >
              <span>{s}</span>
              <Star className="h-3 w-3 fill-current" />
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2">
          <label htmlFor="review-sort" className="text-xs font-medium text-ink-muted">
            Sort:
          </label>
          <select
            id="review-sort"
            value={activeSort}
            onChange={(e) => setActiveSort(e.target.value as 'newest' | 'highest' | 'lowest')}
            className="rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-ink shadow-sm focus:border-brand-primary focus:outline-none"
          >
            <option value="newest">Newest First</option>
            <option value="highest">Highest Rating</option>
            <option value="lowest">Lowest Rating</option>
          </select>
        </div>
      </div>

      {/* 4. Reviews List */}
      {isLoading ? (
        <div className="space-y-4 py-6">
          {[1, 2, 3].map((i) => (
            <div key={i} className="animate-pulse rounded-xl border border-gray-100 p-4">
              <div className="flex items-center gap-3">
                <div className="h-9 w-9 rounded-full bg-gray-200" />
                <div className="space-y-1.5">
                  <div className="h-3.5 w-28 rounded bg-gray-200" />
                  <div className="h-2.5 w-20 rounded bg-gray-100" />
                </div>
              </div>
              <div className="mt-3 h-3 w-3/4 rounded bg-gray-100" />
              <div className="mt-2 h-3 w-1/2 rounded bg-gray-100" />
            </div>
          ))}
        </div>
      ) : reviews.length === 0 ? (
        <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 py-12 text-center">
          <MessageSquare className="h-8 w-8 text-gray-300" />
          <p className="mt-2 text-sm font-semibold text-ink">No reviews found</p>
          <p className="mt-1 text-xs text-ink-muted">
            {activeFilter !== null
              ? `No ${activeFilter}-star reviews yet. Try selecting another filter.`
              : 'Be the first customer to share your experience!'}
          </p>
        </div>
      ) : (
        <div className="space-y-4">
          {reviews.map((rev) => (
            <article
              key={rev.public_id}
              className="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm transition hover:border-gray-200"
            >
              {/* Header */}
              <div className="flex items-start justify-between gap-4">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-full bg-brand-primary/10 font-bold text-brand-primary">
                    {rev.customer_name.charAt(0).toUpperCase()}
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-semibold text-ink">{rev.customer_name}</span>
                      {rev.trust_badge === 'verified_customer' ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                          <ShieldCheck className="h-3.5 w-3.5 text-emerald-600" />
                          Verified Customer
                        </span>
                      ) : rev.trust_badge === 'verified_lead' ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">
                          <CheckCircle2 className="h-3.5 w-3.5 text-blue-600" />
                          Verified Lead
                        </span>
                      ) : null}
                    </div>
                    <time className="text-xs text-ink-muted">
                      {new Date(rev.created_at).toLocaleDateString('en-IN', {
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric',
                      })}
                    </time>
                  </div>
                </div>

                <div className="flex items-center gap-1 rounded-lg bg-amber-50 px-2 py-1 text-xs font-bold text-amber-700">
                  <Star className="h-3.5 w-3.5 fill-amber-400 text-amber-400" />
                  <span>{rev.rating}.0</span>
                </div>
              </div>

              {/* Title & Body */}
              {rev.title && (
                <h3 className="mt-3 text-sm font-bold text-ink">{rev.title}</h3>
              )}
              {rev.content && (
                <p className="mt-1.5 whitespace-pre-line text-sm text-ink-muted">
                  {rev.content}
                </p>
              )}

              {/* Business Owner Reply */}
              {rev.owner_reply && (
                <div className="mt-4 rounded-xl border-l-4 border-brand-primary bg-gray-50/90 p-4">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold text-ink">Response from the business owner</span>
                    {rev.owner_reply.replied_at && (
                      <time className="text-[11px] text-ink-muted">
                        {new Date(rev.owner_reply.replied_at).toLocaleDateString('en-IN', {
                          day: 'numeric',
                          month: 'short',
                        })}
                      </time>
                    )}
                  </div>
                  <p className="mt-1.5 whitespace-pre-line text-xs leading-relaxed text-ink-muted">
                    {rev.owner_reply.text}
                  </p>
                </div>
              )}

              {/* Actions */}
              <div className="mt-4 flex items-center justify-end gap-3 border-t border-gray-50 pt-2">
                <button
                  type="button"
                  onClick={() => setReportingReviewId(rev.public_id)}
                  className="inline-flex items-center gap-1 text-[11px] font-medium text-ink-muted hover:text-rose-600"
                >
                  <Flag className="h-3 w-3" />
                  Report
                </button>
              </div>
            </article>
          ))}
        </div>
      )}

      {/* 5. Review Submission Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
          <div className="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
            <button
              type="button"
              onClick={() => {
                setIsModalOpen(false);
                resetForm();
              }}
              className="absolute right-4 top-4 rounded-full p-1 text-ink-muted hover:bg-gray-100"
            >
              <X className="h-5 w-5" />
            </button>

            {pendingOtpReviewId ? (
              // Step 2: Guest Phone Verification Form
              <form onSubmit={handleVerifyOtp} className="space-y-4">
                <div className="text-center">
                  <ShieldCheck className="mx-auto h-10 w-10 text-brand-primary" />
                  <h3 className="mt-2 text-lg font-bold text-ink">Verify Your Phone Number</h3>
                  <p className="mt-1 text-xs text-ink-muted">
                    We sent a 6-digit verification code to <span className="font-semibold">{phone}</span> to publish your review.
                  </p>
                </div>

                {otpError && (
                  <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-semibold text-rose-700">
                    {otpError}
                  </div>
                )}

                <div>
                  <label htmlFor="otp-input" className="block text-xs font-medium text-ink">
                    6-Digit Verification Code
                  </label>
                  <input
                    id="otp-input"
                    type="text"
                    maxLength={6}
                    required
                    value={otpCode}
                    onChange={(e) => setOtpCode(e.target.value.replace(/\D/g, ''))}
                    placeholder="123456"
                    className="mt-1 w-full rounded-xl border border-gray-200 px-4 py-2.5 text-center text-lg font-mono tracking-widest text-ink focus:border-brand-primary focus:outline-none"
                  />
                </div>

                <button
                  type="submit"
                  disabled={isVerifyingOtp || otpCode.length !== 6}
                  className="w-full rounded-xl bg-brand-primary py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-primary/90 disabled:opacity-50"
                >
                  {isVerifyingOtp ? 'Verifying...' : 'Verify & Publish Review'}
                </button>
              </form>
            ) : (
              // Step 1: Review Submission Form
              <form onSubmit={handleSubmitReview} className="space-y-4">
                <h3 className="text-lg font-bold text-ink">Write a Review for {businessName}</h3>

                {errorMessage && (
                  <div className="flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-semibold text-rose-700">
                    <AlertTriangle className="h-4 w-4 shrink-0 text-rose-600" />
                    <span>{errorMessage}</span>
                  </div>
                )}

                {/* Star rating selector */}
                <div>
                  <label className="block text-xs font-medium text-ink">Overall Rating</label>
                  <div className="mt-1 flex items-center gap-1.5">
                    {[1, 2, 3, 4, 5].map((star) => (
                      <button
                        key={star}
                        type="button"
                        onMouseEnter={() => setHoverRating(star)}
                        onMouseLeave={() => setHoverRating(null)}
                        onClick={() => setFormRating(star)}
                        className="rounded p-1 focus:outline-none"
                      >
                        <Star
                          className={`h-7 w-7 transition ${
                            star <= (hoverRating ?? formRating)
                              ? 'fill-amber-400 text-amber-400'
                              : 'fill-gray-200 text-gray-200'
                          }`}
                        />
                      </button>
                    ))}
                    <span className="ml-2 text-xs font-bold text-amber-700">
                      {formRating} of 5 Stars
                    </span>
                  </div>
                </div>

                {/* Name & Phone */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div>
                    <label htmlFor="customer-name" className="block text-xs font-medium text-ink">
                      Your Name *
                    </label>
                    <input
                      id="customer-name"
                      type="text"
                      required
                      value={customerName}
                      onChange={(e) => setCustomerName(e.target.value)}
                      placeholder="e.g. Ananya S."
                      className="mt-1 w-full rounded-xl border border-gray-200 px-3 py-2 text-xs text-ink focus:border-brand-primary focus:outline-none"
                    />
                  </div>
                  <div>
                    <label htmlFor="customer-phone" className="block text-xs font-medium text-ink">
                      Phone Number *
                    </label>
                    <input
                      id="customer-phone"
                      type="tel"
                      required
                      value={phone}
                      onChange={(e) => setPhone(e.target.value)}
                      placeholder="+91 98401 23456"
                      className="mt-1 w-full rounded-xl border border-gray-200 px-3 py-2 text-xs text-ink focus:border-brand-primary focus:outline-none"
                    />
                  </div>
                </div>

                {/* Title */}
                <div>
                  <label htmlFor="review-title" className="block text-xs font-medium text-ink">
                    Headline (Optional)
                  </label>
                  <input
                    id="review-title"
                    type="text"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="Summarize your experience"
                    className="mt-1 w-full rounded-xl border border-gray-200 px-3 py-2 text-xs text-ink focus:border-brand-primary focus:outline-none"
                  />
                </div>

                {/* Content */}
                <div>
                  <label htmlFor="review-content" className="block text-xs font-medium text-ink">
                    Detailed Experience *
                  </label>
                  <textarea
                    id="review-content"
                    rows={4}
                    required
                    value={content}
                    onChange={(e) => setContent(e.target.value)}
                    placeholder="Tell others what you liked, services received, pricing transparency, or areas for improvement..."
                    className="mt-1 w-full rounded-xl border border-gray-200 px-3 py-2 text-xs text-ink focus:border-brand-primary focus:outline-none"
                  />
                </div>

                <p className="text-[11px] text-ink-muted">
                  By submitting, you confirm this review reflects your genuine experience. One review permitted per business every 30 days.
                </p>

                <div className="flex items-center justify-end gap-3 pt-2">
                  <button
                    type="button"
                    onClick={() => {
                      setIsModalOpen(false);
                      resetForm();
                    }}
                    className="rounded-xl border border-gray-200 px-4 py-2 text-xs font-semibold text-ink-muted hover:bg-gray-50"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={isSubmitting}
                    className="rounded-xl bg-brand-primary px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-primary/90 disabled:opacity-50"
                  >
                    {isSubmitting ? 'Submitting...' : 'Post Review'}
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}

      {/* 6. Report Review Dialog */}
      {reportingReviewId && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
          <div className="relative w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
            <button
              type="button"
              onClick={() => setReportingReviewId(null)}
              className="absolute right-4 top-4 rounded-full p-1 text-ink-muted hover:bg-gray-100"
            >
              <X className="h-5 w-5" />
            </button>

            {reportSuccess ? (
              <div className="py-6 text-center">
                <CheckCircle2 className="mx-auto h-10 w-10 text-emerald-600" />
                <p className="mt-2 text-sm font-bold text-ink">Report Submitted</p>
                <p className="mt-1 text-xs text-ink-muted">
                  Our moderation team will investigate this review.
                </p>
              </div>
            ) : (
              <form onSubmit={handleReportReview} className="space-y-4">
                <h3 className="text-base font-bold text-ink">Report Review</h3>
                <p className="text-xs text-ink-muted">
                  Help us maintain honest and trustworthy local listings.
                </p>

                <div>
                  <label htmlFor="report-reason" className="block text-xs font-medium text-ink">
                    Reason
                  </label>
                  <select
                    id="report-reason"
                    value={reportReason}
                    onChange={(e) => setReportReason(e.target.value)}
                    className="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs text-ink focus:border-brand-primary focus:outline-none"
                  >
                    <option value="spam">Spam or advertising</option>
                    <option value="offensive">Offensive, abusive or hateful language</option>
                    <option value="fake">Fake review or competitor attack</option>
                    <option value="conflict_of_interest">Conflict of interest / Owner self-review</option>
                    <option value="other">Other policy violation</option>
                  </select>
                </div>

                <div>
                  <label htmlFor="report-details" className="block text-xs font-medium text-ink">
                    Additional Context (Optional)
                  </label>
                  <textarea
                    id="report-details"
                    rows={3}
                    value={reportDetails}
                    onChange={(e) => setReportDetails(e.target.value)}
                    placeholder="Briefly describe why this review violates platform standards..."
                    className="mt-1 w-full rounded-xl border border-gray-200 px-3 py-2 text-xs text-ink focus:border-brand-primary focus:outline-none"
                  />
                </div>

                <div className="flex items-center justify-end gap-3 pt-2">
                  <button
                    type="button"
                    onClick={() => setReportingReviewId(null)}
                    className="rounded-xl border border-gray-200 px-3 py-1.5 text-xs font-semibold text-ink-muted hover:bg-gray-50"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={isSubmittingReport}
                    className="rounded-xl bg-rose-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-700 disabled:opacity-50"
                  >
                    {isSubmittingReport ? 'Submitting...' : 'Submit Report'}
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
