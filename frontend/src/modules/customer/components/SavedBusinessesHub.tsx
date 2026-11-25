'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import {
  AlertCircle,
  Bookmark,
  ChevronLeft,
  ChevronRight,
  RefreshCw,
  Search,
} from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';
import type { PublicBusinessProfileDto } from '@/shared/api/directory-types';
import type { SavedBusinessesPaginationMeta } from '@/shared/api/saved-businesses-types';
import { fetchSavedBusinesses } from '@/modules/customer/lib/saved-businesses-api';
import { mapPublicBusinessToSearchHit } from '@/modules/customer/lib/saved-businesses-mapper';
import { DirectoryListingCard } from '@/modules/customer/components/DirectoryListingCard';

interface SavedBusinessesHubProps {
  initialUser: UserDto;
  perPage?: number;
}

export function SavedBusinessesHub({
  initialUser,
  perPage = 12,
}: SavedBusinessesHubProps) {
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<PublicBusinessProfileDto[]>([]);
  const [pagination, setPagination] = useState<SavedBusinessesPaginationMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadSavedBusinesses = useCallback(
    async (targetPage: number, signal?: AbortSignal) => {
      setLoading(true);
      setError(null);

      try {
        const res = await fetchSavedBusinesses({
          page: targetPage,
          per_page: perPage,
          signal,
        });

        if (signal?.aborted) return;

        if (!res.ok || !res.payload || res.payload.status !== 'success') {
          const message =
            res.payload?.status === 'error'
              ? res.payload.error.message
              : 'Unable to load your saved businesses. Please try again.';
          setError(message);
          setLoading(false);
          return;
        }

        const list = Array.isArray(res.payload.data) ? res.payload.data : [];
        const metaPagination = res.payload.meta?.pagination as
          | SavedBusinessesPaginationMeta
          | undefined;

        setItems(list);
        setPagination(
          metaPagination ?? {
            type: 'offset',
            current_page: targetPage,
            per_page: perPage,
            total: list.length,
            last_page: 1,
            next_cursor: null,
          }
        );
        setLoading(false);
      } catch (err: unknown) {
        if ((err as Error)?.name === 'AbortError') return;
        setError('Network error while loading your saved businesses.');
        setLoading(false);
      }
    },
    [perPage]
  );

  useEffect(() => {
    const controller = new AbortController();
    void loadSavedBusinesses(page, controller.signal);
    return () => controller.abort();
  }, [page, loadSavedBusinesses]);

  function handleBusinessSavedChange(publicId: string, saved: boolean) {
    if (saved) return;

    setItems((prev) => {
      const next = prev.filter((item) => item.public_id !== publicId);
      if (next.length === 0 && page > 1) {
        setPage((p) => Math.max(1, p - 1));
      }
      return next;
    });

    setPagination((prev) =>
      prev
        ? {
            ...prev,
            total: Math.max(0, prev.total - 1),
          }
        : prev
    );
  }

  const totalSaved = pagination?.total ?? items.length;
  const lastPage = pagination?.last_page ?? 1;

  return (
    <section aria-labelledby="saved-businesses-heading" className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-3 border-b border-gray-200 pb-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2">
            <Bookmark className="h-6 w-6 text-brand-600" aria-hidden="true" />
            <h1
              id="saved-businesses-heading"
              className="text-2xl font-bold tracking-tight text-ink sm:text-3xl"
            >
              Saved Businesses
            </h1>
          </div>
          <p className="mt-1 text-sm text-ink-muted">
            Your bookmarked local businesses for{' '}
            <span className="font-medium text-ink">
              {initialUser.name ?? initialUser.phone_e164 ?? 'your account'}
            </span>
            {!loading && !error ? ` · ${totalSaved} saved` : ''}
          </p>
        </div>

        <Link
          href="/search"
          className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-200 bg-brand-50 px-3.5 py-2 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-600 hover:text-white"
        >
          <Search className="h-3.5 w-3.5" aria-hidden="true" />
          <span>Explore Directory</span>
        </Link>
      </div>

      {/* Loading State */}
      {loading && (
        <div
          aria-busy="true"
          aria-label="Loading saved businesses"
          data-testid="saved-businesses-loading"
          className="space-y-4"
        >
          {[1, 2, 3].map((n) => (
            <div
              key={n}
              className="flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:gap-4"
            >
              <div className="h-40 w-full animate-pulse rounded-lg bg-gray-200 sm:h-36 sm:w-48" />
              <div className="mt-4 flex-1 space-y-3 sm:mt-0">
                <div className="h-4 w-32 animate-pulse rounded bg-gray-200" />
                <div className="h-5 w-2/3 animate-pulse rounded bg-gray-200" />
                <div className="h-3.5 w-1/2 animate-pulse rounded bg-gray-200" />
                <div className="h-8 w-44 animate-pulse rounded bg-gray-200" />
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Error State with Retry */}
      {!loading && error && (
        <div
          role="alert"
          data-testid="saved-businesses-error"
          className="flex flex-col items-center justify-center rounded-2xl border border-rose-200 bg-rose-50 px-6 py-12 text-center"
        >
          <AlertCircle className="h-10 w-10 text-rose-600" aria-hidden="true" />
          <h2 className="mt-3 text-base font-bold text-rose-900">
            Could not load saved businesses
          </h2>
          <p className="mt-1 max-w-md text-sm text-rose-700">{error}</p>
          <button
            type="button"
            onClick={() => void loadSavedBusinesses(page)}
            className="mt-5 inline-flex items-center gap-1.5 rounded-lg bg-rose-700 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-rose-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600"
          >
            <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
            <span>Retry</span>
          </button>
        </div>
      )}

      {/* Empty State */}
      {!loading && !error && items.length === 0 && (
        <div
          data-testid="saved-businesses-empty"
          className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center"
        >
          <div className="flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-600">
            <Bookmark className="h-6 w-6" aria-hidden="true" />
          </div>
          <h2 className="mt-4 text-lg font-bold text-ink">No saved businesses yet</h2>
          <p className="mt-1.5 max-w-md text-sm text-ink-muted">
            Tap the Save button on any business card or profile page to bookmark trusted local
            services here for quick access.
          </p>
          <Link
            href="/search"
            className="mt-6 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700"
          >
            <Search className="h-4 w-4" aria-hidden="true" />
            <span>Browse Local Businesses</span>
          </Link>
        </div>
      )}

      {/* Saved Business Cards */}
      {!loading && !error && items.length > 0 && (
        <>
          <div className="space-y-4" data-testid="saved-businesses-list">
            {items.map((profile, idx) => (
              <DirectoryListingCard
                key={profile.public_id}
                hit={mapPublicBusinessToSearchHit(profile)}
                priority={idx < 2}
                initialSaved
                onSavedChange={(saved) =>
                  handleBusinessSavedChange(profile.public_id, saved)
                }
              />
            ))}
          </div>

          {/* Pagination */}
          {lastPage > 1 && (
            <nav
              aria-label="Saved businesses pagination"
              className="flex items-center justify-between border-t border-gray-200 pt-4"
            >
              <button
                type="button"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1}
                className="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-xs font-semibold text-ink transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                <span>Previous</span>
              </button>

              <span className="text-xs font-medium text-ink-muted">
                Page <strong className="text-ink">{page}</strong> of{' '}
                <strong className="text-ink">{lastPage}</strong>
              </span>

              <button
                type="button"
                onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                disabled={page >= lastPage}
                className="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-xs font-semibold text-ink transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <span>Next</span>
                <ChevronRight className="h-4 w-4" aria-hidden="true" />
              </button>
            </nav>
          )}
        </>
      )}
    </section>
  );
}
