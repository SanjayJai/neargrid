'use client';

import { useCallback, useEffect, useState } from 'react';
import {
  AlertCircle,
  ArrowLeft,
  CheckCircle2,
  Clock,
  Inbox,
  Loader2,
  MessageSquare,
  RefreshCw,
  Send,
  ShieldCheck,
  Star,
  Trash2,
} from 'lucide-react';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';
import { bffFetch } from '@/shared/api/bff-client';
import type { OwnerReviewDto } from '@/shared/api/directory-types';

type FilterTab = 'all' | 'needs_reply' | '5' | '4' | '3' | '2' | '1';

const FILTER_TABS: Array<{ id: FilterTab; label: string }> = [
  { id: 'all', label: 'All Reviews' },
  { id: 'needs_reply', label: 'Needs Reply' },
  { id: '5', label: '5 Stars' },
  { id: '4', label: '4 Stars' },
  { id: '3', label: '3 Stars' },
  { id: '2', label: '2 Stars' },
  { id: '1', label: '1 Star' },
];

export default function BusinessOwnerReviewsPage() {
  const { activeBusiness, loading: businessLoading } = useOwnerBusiness();

  const [reviews, setReviews] = useState<OwnerReviewDto[]>([]);
  const [selectedReviewId, setSelectedReviewId] = useState<string | null>(null);
  const [filter, setFilter] = useState<FilterTab>('all');
  const [isLoading, setIsLoading] = useState(true);
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Reply state
  const [replyText, setReplyText] = useState('');
  const [isSubmittingReply, setIsSubmittingReply] = useState(false);
  const [actionFeedback, setActionFeedback] = useState<string | null>(null);

  // Mobile list vs detail view toggle
  const [mobileDetailOpen, setMobileDetailOpen] = useState(false);

  const selectedReview =
    reviews.find((r) => r.public_id === selectedReviewId) ?? reviews[0] ?? null;

  // Sync reply draft text when selected review changes
  useEffect(() => {
    if (selectedReview) {
      setReplyText(selectedReview.owner_reply?.text || '');
      setActionFeedback(null);
    }
  }, [selectedReviewId, selectedReview]);

  const fetchReviews = useCallback(
    async (cursor?: string | null, append = false) => {
      if (!activeBusiness?.public_id) return;

      if (append) {
        setIsLoadingMore(true);
      } else {
        setIsLoading(true);
        setErrorMessage(null);
      }

      try {
        const params = new URLSearchParams();
        if (filter === 'needs_reply') {
          params.set('has_reply', 'false');
        } else if (filter !== 'all') {
          params.set('rating', filter);
        }

        if (cursor) {
          params.set('cursor', cursor);
        }

        const querySuffix = params.toString() ? `?${params.toString()}` : '';
        const response = await bffFetch<OwnerReviewDto[]>(
          `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/reviews${querySuffix}`
        );

        if (response.ok && response.payload?.status === 'success') {
          const fetched = response.payload.data ?? [];
          setReviews((prev) => (append ? [...prev, ...fetched] : fetched));
          const paginationMeta = response.payload.meta?.pagination;
          setNextCursor(paginationMeta?.next_cursor ?? null);

          if (!append && fetched.length > 0) {
            setSelectedReviewId(fetched[0].public_id);
          }
        } else {
          const errMsg =
            response.payload && response.payload.status === 'error'
              ? response.payload.error.message
              : 'Failed to load customer reviews.';
          setErrorMessage(errMsg);
        }
      } catch {
        setErrorMessage('Unable to connect to reviews service.');
      } finally {
        setIsLoading(false);
        setIsLoadingMore(false);
      }
    },
    [activeBusiness?.public_id, filter]
  );

  useEffect(() => {
    void fetchReviews();
  }, [fetchReviews]);

  const handleSaveReply = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeBusiness?.public_id || !selectedReview || !replyText.trim()) return;

    setIsSubmittingReply(true);
    setActionFeedback(null);

    try {
      const response = await bffFetch<OwnerReviewDto>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/reviews/${encodeURIComponent(selectedReview.public_id)}/reply`,
        {
          method: 'POST',
          body: { reply_text: replyText.trim() },
        }
      );

      if (response.ok && response.payload?.status === 'success' && response.payload.data) {
        const updated = response.payload.data;
        setReviews((prev) =>
          prev.map((r) => (r.public_id === updated.public_id ? updated : r))
        );
        setActionFeedback('Reply posted successfully.');
      } else {
        const errMsg =
          response.payload && response.payload.status === 'error'
            ? response.payload.error.message
            : 'Failed to save reply.';
        setActionFeedback(errMsg);
      }
    } catch {
      setActionFeedback('Failed to save reply due to a network error.');
    } finally {
      setIsSubmittingReply(false);
    }
  };

  const handleDeleteReply = async () => {
    if (!activeBusiness?.public_id || !selectedReview) return;

    setIsSubmittingReply(true);
    setActionFeedback(null);

    try {
      const response = await bffFetch<null>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/reviews/${encodeURIComponent(selectedReview.public_id)}/reply`,
        {
          method: 'DELETE',
        }
      );

      if (response.ok) {
        setReviews((prev) =>
          prev.map((r) =>
            r.public_id === selectedReview.public_id
              ? { ...r, owner_reply: null, has_reply: false }
              : r
          )
        );
        setReplyText('');
        setActionFeedback('Reply removed.');
      } else {
        const errMsg =
          response.payload && response.payload.status === 'error'
            ? response.payload.error.message
            : 'Failed to delete reply.';
        setActionFeedback(errMsg);
      }
    } catch {
      setActionFeedback('Failed to delete reply due to a network error.');
    } finally {
      setIsSubmittingReply(false);
    }
  };

  if (businessLoading) {
    return (
      <div className="flex min-h-[400px] items-center justify-center p-8">
        <Loader2 className="h-8 w-8 animate-spin text-brand-primary" />
      </div>
    );
  }

  if (!activeBusiness) {
    return (
      <div className="mx-auto max-w-lg p-8 text-center">
        <Inbox className="mx-auto h-12 w-12 text-ink-muted/50" />
        <h2 className="mt-4 text-lg font-bold text-ink">No Business Selected</h2>
        <p className="mt-1 text-sm text-ink-muted">
          Please select or register a business listing to manage customer reviews.
        </p>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      {/* 1. Header & Quick Controls */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-ink">Customer Reviews</h1>
          <p className="mt-1 text-sm text-ink-muted">
            Manage, inspect, and respond to verified customer feedback for{' '}
            <span className="font-semibold text-ink">{activeBusiness.name}</span>.
          </p>
        </div>

        <button
          type="button"
          onClick={() => void fetchReviews()}
          disabled={isLoading}
          className="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-xs font-semibold text-ink shadow-sm transition hover:bg-gray-50 focus:outline-none"
        >
          <RefreshCw className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {/* 2. Filter Pills */}
      <div className="flex flex-wrap items-center gap-2 border-b border-gray-100 pb-3">
        {FILTER_TABS.map((tab) => {
          const isSelected = filter === tab.id;
          return (
            <button
              key={tab.id}
              type="button"
              onClick={() => {
                setFilter(tab.id);
                setNextCursor(null);
              }}
              className={`rounded-xl px-3.5 py-1.5 text-xs font-semibold transition ${
                isSelected
                  ? 'bg-brand-primary text-white shadow-sm'
                  : 'bg-gray-100 text-ink-muted hover:bg-gray-200 hover:text-ink'
              }`}
            >
              {tab.label}
            </button>
          );
        })}
      </div>

      {/* 3. Error Banner */}
      {errorMessage && (
        <div className="flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs font-medium text-rose-800">
          <AlertCircle className="h-5 w-5 shrink-0 text-rose-600" />
          <span>{errorMessage}</span>
        </div>
      )}

      {/* 4. Split-View Workspace */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        {/* Left Pane: Reviews List (5 cols on lg) */}
        <div
          className={`space-y-3 lg:col-span-5 ${
            mobileDetailOpen ? 'hidden lg:block' : 'block'
          }`}
        >
          {isLoading && reviews.length === 0 ? (
            <div className="space-y-3">
              {[1, 2, 3, 4].map((i) => (
                <div key={i} className="animate-pulse rounded-2xl border border-gray-100 bg-white p-4">
                  <div className="h-4 w-1/3 rounded bg-gray-200" />
                  <div className="mt-2 h-3 w-1/4 rounded bg-gray-100" />
                  <div className="mt-3 h-3 w-full rounded bg-gray-100" />
                </div>
              ))}
            </div>
          ) : reviews.length === 0 ? (
            <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 bg-white p-12 text-center">
              <MessageSquare className="h-8 w-8 text-gray-300" />
              <p className="mt-2 text-sm font-semibold text-ink">No reviews found</p>
              <p className="mt-1 text-xs text-ink-muted">
                {filter === 'needs_reply'
                  ? 'All reviews have been responded to!'
                  : 'No reviews matching the selected filter.'}
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              {reviews.map((rev) => {
                const isSelected = selectedReview?.public_id === rev.public_id;

                return (
                  <button
                    key={rev.public_id}
                    type="button"
                    onClick={() => {
                      setSelectedReviewId(rev.public_id);
                      setMobileDetailOpen(true);
                    }}
                    className={`w-full text-left rounded-2xl border p-4 transition ${
                      isSelected
                        ? 'border-brand-primary bg-brand-primary/[0.03] shadow-sm'
                        : 'border-gray-100 bg-white hover:border-gray-200'
                    }`}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div className="flex items-center gap-2">
                        <span className="font-bold text-sm text-ink">
                          {rev.customer_name}
                        </span>
                        {rev.trust_badge === 'verified_customer' ? (
                          <span title="Verified Customer" className="text-emerald-600">
                            <ShieldCheck className="h-4 w-4" />
                          </span>
                        ) : rev.trust_badge === 'verified_lead' ? (
                          <span title="Verified Lead" className="text-blue-600">
                            <CheckCircle2 className="h-4 w-4" />
                          </span>
                        ) : null}
                      </div>

                      <div className="flex items-center gap-1 rounded bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-700">
                        <Star className="h-3 w-3 fill-amber-400 text-amber-400" />
                        <span>{rev.rating}.0</span>
                      </div>
                    </div>

                    {rev.title && (
                      <p className="mt-1 line-clamp-1 text-xs font-semibold text-ink">
                        {rev.title}
                      </p>
                    )}

                    <p className="mt-1 line-clamp-2 text-xs text-ink-muted">
                      {rev.content || 'No written comment provided.'}
                    </p>

                    <div className="mt-3 flex items-center justify-between text-[11px] text-ink-muted">
                      <span>
                        {new Date(rev.created_at).toLocaleDateString('en-IN', {
                          day: 'numeric',
                          month: 'short',
                        })}
                      </span>

                      {rev.has_reply ? (
                        <span className="inline-flex items-center gap-1 font-semibold text-emerald-600">
                          <CheckCircle2 className="h-3 w-3" />
                          Replied
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 font-semibold text-amber-600">
                          <Clock className="h-3 w-3" />
                          Needs Reply
                        </span>
                      )}
                    </div>
                  </button>
                );
              })}

              {nextCursor && (
                <div className="pt-2 text-center">
                  <button
                    type="button"
                    onClick={() => void fetchReviews(nextCursor, true)}
                    disabled={isLoadingMore}
                    className="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-xs font-semibold text-ink shadow-sm transition hover:bg-gray-50 disabled:opacity-50"
                  >
                    {isLoadingMore ? (
                      <>
                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                        Loading...
                      </>
                    ) : (
                      'Load More Reviews'
                    )}
                  </button>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Right Pane: Selected Review Detail & Reply Workspace (7 cols on lg) */}
        <div
          className={`lg:col-span-7 ${
            mobileDetailOpen ? 'block' : 'hidden lg:block'
          }`}
        >
          {selectedReview ? (
            <div className="space-y-6 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
              {/* Mobile Back Button */}
              <div className="lg:hidden">
                <button
                  type="button"
                  onClick={() => setMobileDetailOpen(false)}
                  className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-primary"
                >
                  <ArrowLeft className="h-4 w-4" />
                  Back to reviews list
                </button>
              </div>

              {/* Reviewer Header */}
              <div className="flex items-start justify-between gap-4 border-b border-gray-100 pb-4">
                <div className="flex items-center gap-3">
                  <div className="flex h-12 w-12 items-center justify-center rounded-full bg-brand-primary/10 text-lg font-bold text-brand-primary">
                    {selectedReview.customer_name.charAt(0).toUpperCase()}
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <h2 className="text-base font-bold text-ink">
                        {selectedReview.customer_name}
                      </h2>
                      {selectedReview.trust_badge === 'verified_customer' ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                          <ShieldCheck className="h-3.5 w-3.5 text-emerald-600" />
                          Verified Customer
                        </span>
                      ) : selectedReview.trust_badge === 'verified_lead' ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">
                          <CheckCircle2 className="h-3.5 w-3.5 text-blue-600" />
                          Verified Lead
                        </span>
                      ) : null}
                    </div>
                    <time className="text-xs text-ink-muted">
                      Posted on{' '}
                      {new Date(selectedReview.created_at).toLocaleDateString('en-IN', {
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric',
                      })}
                    </time>
                  </div>
                </div>

                <div className="flex items-center gap-1 rounded-lg bg-amber-50 px-2.5 py-1 text-sm font-bold text-amber-700">
                  <Star className="h-4 w-4 fill-amber-400 text-amber-400" />
                  <span>{selectedReview.rating}.0</span>
                </div>
              </div>

              {/* Review Content */}
              <div className="space-y-2">
                {selectedReview.title && (
                  <h3 className="text-base font-bold text-ink">{selectedReview.title}</h3>
                )}
                <p className="whitespace-pre-line text-sm leading-relaxed text-ink-muted">
                  {selectedReview.content || 'No detailed comments left by reviewer.'}
                </p>
              </div>

              {/* Action feedback */}
              {actionFeedback && (
                <div className="rounded-xl border border-brand-primary/20 bg-brand-primary/5 p-3 text-xs font-semibold text-brand-primary">
                  {actionFeedback}
                </div>
              )}

              {/* Owner Reply Section */}
              <div className="rounded-xl border border-gray-100 bg-gray-50/70 p-5">
                <div className="flex items-center justify-between">
                  <h4 className="text-xs font-bold uppercase tracking-wider text-ink">
                    Business Owner Response
                  </h4>
                  {selectedReview.owner_reply && (
                    <button
                      type="button"
                      onClick={() => void handleDeleteReply()}
                      disabled={isSubmittingReply}
                      className="inline-flex items-center gap-1 text-[11px] font-semibold text-rose-600 hover:text-rose-700 disabled:opacity-50"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                      Remove Reply
                    </button>
                  )}
                </div>

                {selectedReview.owner_reply?.replied_at && (
                  <p className="mt-1 text-[11px] text-ink-muted">
                    Last responded on{' '}
                    {new Date(selectedReview.owner_reply.replied_at).toLocaleDateString('en-IN', {
                      day: 'numeric',
                      month: 'short',
                      year: 'numeric',
                    })}
                    {selectedReview.owner_reply.replied_by?.name
                      ? ` by ${selectedReview.owner_reply.replied_by.name}`
                      : ''}
                  </p>
                )}

                <form onSubmit={handleSaveReply} className="mt-3 space-y-3">
                  <textarea
                    rows={4}
                    required
                    value={replyText}
                    onChange={(e) => setReplyText(e.target.value)}
                    placeholder="Write a professional response acknowledging the customer's experience..."
                    className="w-full rounded-xl border border-gray-200 bg-white p-3 text-xs text-ink focus:border-brand-primary focus:outline-none"
                  />

                  <div className="flex items-center justify-between">
                    <span className="text-[11px] text-ink-muted">
                      Your response is visible publicly under this customer review.
                    </span>

                    <button
                      type="submit"
                      disabled={isSubmittingReply || !replyText.trim()}
                      className="inline-flex items-center gap-1.5 rounded-xl bg-brand-primary px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-primary/90 disabled:opacity-50"
                    >
                      {isSubmittingReply ? (
                        <>
                          <Loader2 className="h-3.5 w-3.5 animate-spin" />
                          Saving...
                        </>
                      ) : (
                        <>
                          <Send className="h-3.5 w-3.5" />
                          {selectedReview.has_reply ? 'Update Reply' : 'Post Reply'}
                        </>
                      )}
                    </button>
                  </div>
                </form>
              </div>
            </div>
          ) : (
            <div className="flex min-h-[400px] items-center justify-center rounded-2xl border border-dashed border-gray-200 bg-white p-8 text-center">
              <p className="text-xs text-ink-muted">Select a review from the left to view details.</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
