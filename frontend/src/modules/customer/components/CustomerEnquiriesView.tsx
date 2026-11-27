'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Clock, Loader2, MapPin, MessageSquare, Send } from 'lucide-react';
import { bffFetch } from '@/shared/api/bff-client';
import type { CustomerEnquiryItemDto } from '@/shared/api/directory-types';

export function CustomerEnquiriesView() {
  const [enquiries, setEnquiries] = useState<CustomerEnquiryItemDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    async function load() {
      setLoading(true);
      setError(null);
      try {
        const res = await bffFetch<CustomerEnquiryItemDto[]>('/api/account/enquiries');
        if (!active) return;
        if (!res.ok || !res.payload || !('data' in res.payload)) {
          const msg =
            res.payload && 'error' in res.payload
              ? res.payload.error.message
              : 'Unable to load your enquiries.';
          setError(msg);
          return;
        }
        setEnquiries(res.payload.data ?? []);
      } catch {
        if (active) setError('Network error while loading your enquiries.');
      } finally {
        if (active) setLoading(false);
      }
    }
    void load();
    return () => {
      active = false;
    };
  }, []);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-extrabold tracking-tight text-ink">
            My Enquiries
          </h1>
          <p className="mt-1 text-sm text-ink-muted">
            Track the status of enquiries you have sent to local businesses.
          </p>
        </div>
        <Link
          href="/search"
          className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
        >
          <Send className="h-3.5 w-3.5" aria-hidden="true" />
          <span>Find Businesses</span>
        </Link>
      </div>

      {loading ? (
        <div className="flex items-center gap-2 rounded-2xl border border-gray-200 bg-white p-8 text-sm text-ink-muted">
          <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          <span>Loading your enquiries...</span>
        </div>
      ) : error ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800">
          {error}
        </div>
      ) : enquiries.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center">
          <MessageSquare className="mx-auto h-8 w-8 text-ink-subtle" aria-hidden="true" />
          <h2 className="mt-3 text-base font-bold text-ink">No enquiries yet</h2>
          <p className="mt-1 text-sm text-ink-muted">
            When you contact a business on NearGrid, your enquiry history will appear here.
          </p>
        </div>
      ) : (
        <div className="space-y-4">
          {enquiries.map((item) => (
            <article
              key={item.public_id}
              className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
            >
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold text-brand-700">
                      {item.category.name}
                    </span>
                    <span className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold uppercase text-ink-muted">
                      {item.status.replace(/_/g, ' ')}
                    </span>
                    <span className="inline-flex items-center gap-1 text-xs text-ink-muted">
                      <Clock className="h-3 w-3" aria-hidden="true" />
                      {item.urgency}
                    </span>
                  </div>
                  {item.target_business && (
                    <h2 className="mt-2 text-base font-bold text-ink">
                      <Link
                        href={`/${encodeURIComponent(item.city.slug)}/business/${encodeURIComponent(item.target_business.slug)}`}
                        className="hover:text-brand-600"
                      >
                        {item.target_business.name}
                      </Link>
                    </h2>
                  )}
                </div>

                <span className="inline-flex items-center gap-1 text-xs text-ink-muted">
                  <MapPin className="h-3.5 w-3.5" aria-hidden="true" />
                  {item.locality ? `${item.locality.name}, ` : ''}
                  {item.city.name}
                </span>
              </div>

              <p className="mt-3 text-sm leading-relaxed text-ink">
                {item.requirement}
              </p>

              {item.recipients.length > 0 && (
                <div className="mt-3 border-t border-gray-100 pt-3">
                  <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                    Delivered To ({item.recipients_count})
                  </p>
                  <div className="mt-1.5 flex flex-wrap gap-2">
                    {item.recipients.map((rec) => (
                      <Link
                        key={rec.public_id}
                        href={`/${encodeURIComponent(item.city.slug)}/business/${encodeURIComponent(rec.business_slug)}`}
                        className="rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-ink hover:border-brand-300"
                      >
                        {rec.business_name} ({rec.delivery_status})
                      </Link>
                    ))}
                  </div>
                </div>
              )}
            </article>
          ))}
        </div>
      )}
    </div>
  );
}
