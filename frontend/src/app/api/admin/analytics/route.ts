import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, forwardLaravelJson, requireActiveBffSession } from '@/lib/bff/route-helpers';

export const dynamic = 'force-dynamic';

const ALLOWED_RANGES = new Set(['7d', '30d', '90d', 'custom']);
const DATE_REGEX = /^\d{4}-\d{2}-\d{2}$/;
const SLUG_REGEX = /^[a-z0-9-]+$/;

export async function GET(request: Request): Promise<NextResponse> {
  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const url = new URL(request.url);
  const qs = new URLSearchParams();

  const range = url.searchParams.get('range') ?? '30d';
  if (!ALLOWED_RANGES.has(range)) {
    return bffError('INVALID_RANGE', 'Unsupported analytics date range.', 422);
  }
  qs.set('range', range);

  if (range === 'custom') {
    const from = url.searchParams.get('from') ?? '';
    const to = url.searchParams.get('to') ?? '';
    if (!DATE_REGEX.test(from) || !DATE_REGEX.test(to) || from > to) {
      return bffError('INVALID_DATE_RANGE', 'Custom range requires valid YYYY-MM-DD from/to dates.', 422);
    }
    qs.set('from', from);
    qs.set('to', to);
  }

  const citySlug = url.searchParams.get('city_slug');
  if (citySlug) {
    if (!SLUG_REGEX.test(citySlug)) {
      return bffError('INVALID_CITY_SLUG', 'Invalid city filter.', 422);
    }
    qs.set('city_slug', citySlug);
  }

  try {
    const result = await callLaravelApi(`/api/v1/admin/analytics?${qs.toString()}`, {
      method: 'GET',
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
    });

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to load platform analytics.', 503);
  }
}
