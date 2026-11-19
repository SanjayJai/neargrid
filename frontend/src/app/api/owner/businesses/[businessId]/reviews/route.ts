import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  forwardLaravelJson,
  isValidPublicUlid,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

export async function GET(
  request: Request,
  context: { params: { businessId: string } }
) {
  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId } = context.params;
  if (!isValidPublicUlid(businessId)) {
    return bffError('INVALID_BUSINESS_ID', 'Invalid business identifier.', 400);
  }

  const { searchParams } = new URL(request.url);
  const upstreamParams = new URLSearchParams();

  const rating = searchParams.get('rating');
  if (rating) upstreamParams.set('rating', rating);

  const status = searchParams.get('status');
  if (status) upstreamParams.set('status', status);

  const hasReply = searchParams.get('has_reply');
  if (hasReply) upstreamParams.set('has_reply', hasReply);

  const cursor = searchParams.get('cursor');
  if (cursor) upstreamParams.set('cursor', cursor);

  const perPage = searchParams.get('per_page');
  if (perPage) upstreamParams.set('per_page', perPage);

  const querySuffix = upstreamParams.toString() ? `?${upstreamParams.toString()}` : '';

  try {
    const result = await callLaravelApi(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/reviews${querySuffix}`,
      {
        method: 'GET',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Reviews service unavailable.', 503);
  }
}
