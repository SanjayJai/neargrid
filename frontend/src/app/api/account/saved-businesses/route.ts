import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, forwardLaravelJson, requireActiveBffSession } from '@/lib/bff/route-helpers';

const POSITIVE_INT_REGEX = /^\d+$/;

export async function GET(request: Request): Promise<NextResponse> {
  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const url = new URL(request.url);
  const pageStr = url.searchParams.get('page');
  const perPageStr = url.searchParams.get('per_page');

  if (pageStr !== null) {
    const pageNum = Number(pageStr);
    if (!POSITIVE_INT_REGEX.test(pageStr) || !Number.isInteger(pageNum) || pageNum < 1) {
      return bffError('INVALID_QUERY_PARAMS', 'Page must be a positive integer.', 400);
    }
  }

  if (perPageStr !== null) {
    const perPageNum = Number(perPageStr);
    if (
      !POSITIVE_INT_REGEX.test(perPageStr) ||
      !Number.isInteger(perPageNum) ||
      perPageNum < 1 ||
      perPageNum > 50
    ) {
      return bffError(
        'INVALID_QUERY_PARAMS',
        'per_page must be an integer between 1 and 50.',
        400
      );
    }
  }

  const forwardParams = new URLSearchParams();
  if (pageStr !== null) forwardParams.set('page', pageStr);
  if (perPageStr !== null) forwardParams.set('per_page', perPageStr);

  const qs = forwardParams.toString();
  const upstreamPath = `/api/v1/account/saved-businesses${qs ? `?${qs}` : ''}`;

  try {
    const result = await callLaravelApi(upstreamPath, {
      method: 'GET',
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
    });

    return await forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError(
      'UPSTREAM_UNREACHABLE',
      'Unable to retrieve saved businesses at this time.',
      503
    );
  }
}
