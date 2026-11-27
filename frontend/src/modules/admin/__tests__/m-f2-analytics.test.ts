import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AdminAnalyticsView } from '@/modules/admin/components/AdminAnalyticsView';
import type { AdminAnalyticsDto } from '@/modules/admin/lib/admin-analytics-types';

(globalThis as unknown as { React: typeof React }).React = React;

const mockRequireActiveBffSession = vi.fn();
const mockCallLaravelApi = vi.fn();

vi.mock('next/link', () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) =>
    React.createElement('a', { href, ...props }, children),
}));

vi.mock('@/lib/bff/route-helpers', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/bff/route-helpers')>();
  return {
    ...actual,
    requireActiveBffSession: mockRequireActiveBffSession,
  };
});

vi.mock('@/lib/bff/laravel-client', () => ({
  callLaravelApi: mockCallLaravelApi,
}));

const sampleAnalytics: AdminAnalyticsDto = {
  period: {
    range: '30d',
    from: '2026-10-29',
    to: '2026-11-27',
    previous_from: '2026-09-29',
    previous_to: '2026-10-28',
    days: 30,
    city_slug: null,
  },
  summary: {
    businesses: {
      total: 120,
      active: 110,
      pending_review: 10,
      verified: 80,
      trusted: 20,
      comparison: { current: 15, previous: 10, delta_count: 5, delta_percent: 50 },
    },
    users: {
      total: 400,
      active: 395,
      suspended: 5,
      comparison: { current: 40, previous: 30, delta_count: 10, delta_percent: 33.3 },
    },
    enquiries: {
      total: 90,
      dispatched: 72,
      pending_otp: 8,
      closed: 10,
      dispatch_rate_percent: 80,
      comparison: { current: 25, previous: 20, delta_count: 5, delta_percent: 25 },
    },
    reviews: {
      total: 60,
      approved: 55,
      pending_moderation: 3,
      flagged: 2,
      avg_rating: 4.4,
      open_reports: 1,
      comparison: { current: 12, previous: 10, delta_count: 2, delta_percent: 20 },
    },
    kyc_and_claims: {
      pending_verifications: 4,
      approved_verifications: 50,
      rejected_verifications: 2,
      pending_claims: 1,
    },
    domains: { total: 4, active: 3, verified: 3, ssl_active: 3 },
    categories: { total: 50, published: 45, collections: 6 },
    search: {
      total_searches: 310,
      avg_duration_ms: 38,
      zero_result_searches: 12,
      comparison: { current: 310, previous: 250, delta_count: 60, delta_percent: 24 },
    },
  },
  daily_trends: [
    { date: '2026-11-26', businesses: 1, users: 3, enquiries: 4, reviews: 2, searches: 20 },
    { date: '2026-11-27', businesses: 2, users: 4, enquiries: 6, reviews: 1, searches: 35 },
  ],
  top_queries: [{ query: 'dentist', search_count: 42, avg_results: 5.5 }],
  top_cities: [{ city_slug: 'chennai', search_count: 200 }],
  recent_activity: [
    {
      id: 1,
      actor_role: 'super_admin',
      action: 'business.verified',
      auditable_type: 'Business',
      auditable_id: 10,
      created_at: '2026-11-27T09:00:00Z',
    },
  ],
  unavailable_integrations: [
    {
      key: 'ga4',
      label: 'Google Analytics 4 (Traffic & Sessions)',
      status: 'not_configured',
      reason: 'External GA4 integration is not configured.',
    },
    {
      key: 'ad_campaigns',
      label: 'Advertising Placements & Campaign CTR',
      status: 'pending_milestone',
      reason: 'Advertising placement & campaign analytics are scheduled for M-F2 M5.',
    },
  ],
};

describe('M-F2 M2 Admin Analytics BFF & UI', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockRequireActiveBffSession.mockResolvedValue({
      ok: true,
      opaqueSessionId: 'sess-admin',
      record: { accessToken: 'secret-admin-bearer' },
    });
  });

  it('validates range and custom dates on /api/admin/analytics and forwards Bearer token server-side', async () => {
    const { GET } = await import('@/app/api/admin/analytics/route');

    const badRange = await GET(new Request('http://localhost/api/admin/analytics?range=1y'));
    expect(badRange.status).toBe(422);
    expect(mockCallLaravelApi).not.toHaveBeenCalled();

    const badCustom = await GET(
      new Request('http://localhost/api/admin/analytics?range=custom&from=2026-11-20&to=2026-11-10')
    );
    expect(badCustom.status).toBe(422);

    mockCallLaravelApi.mockResolvedValueOnce({
      ok: true,
      status: 200,
      payload: { status: 'success', data: sampleAnalytics },
    });

    const okRes = await GET(new Request('http://localhost/api/admin/analytics?range=7d&city_slug=chennai'));
    expect(okRes.status).toBe(200);
    expect(mockCallLaravelApi).toHaveBeenCalledWith(
      '/api/v1/admin/analytics?range=7d&city_slug=chennai',
      expect.objectContaining({
        method: 'GET',
        bearerToken: 'secret-admin-bearer',
      })
    );
  });

  it('renders AdminAnalyticsView KPIs, comparison deltas, trend chart, search tables and unavailable integrations', () => {
    const html = renderToStaticMarkup(
      React.createElement(AdminAnalyticsView, { initialData: sampleAnalytics })
    );

    expect(html).toContain('Platform Analytics');
    expect(html).toContain('+5 (+50%) vs prev');
    expect(html).toContain('80% dispatched');
    expect(html).toContain('dentist');
    expect(html).toContain('chennai');
    expect(html).toContain('Not Configured');
    expect(html).toContain('External GA4 integration is not configured.');
    expect(html).toContain('Upcoming');
  });
});
