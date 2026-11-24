import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, forwardLaravelJson, requireActiveBffSession } from '@/lib/bff/route-helpers';

const ALLOWED_STATUSES = new Set(['all', 'unread']);
const ALLOWED_CATEGORIES = new Set(['all', 'enquiry', 'review', 'account']);

export async function GET(request: Request): Promise<NextResponse> {
  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const url = new URL(request.url);
  const status = url.searchParams.get('status');
  const category = url.searchParams.get('category');
  const pageStr = url.searchParams.get('page');
  const perPageStr = url.searchParams.get('per_page');

  if (status && !ALLOWED_STATUSES.has(status)) {
    return bffError('INVALID_QUERY_PARAMS', 'Invalid notification status filter.', 400);
  }

  if (category && !ALLOWED_CATEGORIES.has(category)) {
    return bffError('INVALID_QUERY_PARAMS', 'Invalid notification category filter.', 400);
  }

  if (pageStr) {
    const pageNum = Number(pageStr);
    if (!Number.isInteger(pageNum) || pageNum < 1) {
      return bffError('INVALID_QUERY_PARAMS', 'Page must be a positive integer.', 400);
    }
  }

  if (perPageStr) {
    const perPageNum = Number(perPageStr);
    if (!Number.isInteger(perPageNum) || perPageNum < 1 || perPageNum > 50) {
      return bffError('INVALID_QUERY_PARAMS', 'per_page must be an integer between 1 and 50.', 400);
    }
  }

  const forwardParams = new URLSearchParams();
  if (status) forwardParams.set('status', status);
  if (category) forwardParams.set('category', category);
  if (pageStr) forwardParams.set('page', pageStr);
  if (perPageStr) forwardParams.set('per_page', perPageStr);

  const qs = forwardParams.toString();
  const upstreamPath = `/api/v1/account/notifications${qs ? `?${qs}` : ''}`;

  try {
    const result = await callLaravelApi(upstreamPath, {
      method: 'GET',
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
    });

    return await forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to retrieve notifications at this time.', 503);
  }
}
