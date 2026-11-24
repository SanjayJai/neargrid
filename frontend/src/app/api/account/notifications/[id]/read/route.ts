import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidNotificationId,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

interface RouteContext {
  params: Promise<{ id: string }> | { id: string };
}

export async function PATCH(
  request: Request,
  context: RouteContext
): Promise<NextResponse> {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const resolvedParams = await Promise.resolve(context.params);
  const id = resolvedParams?.id;
  if (!id || !isValidNotificationId(id)) {
    return bffError('INVALID_NOTIFICATION_ID', 'Invalid notification identifier.', 400);
  }

  try {
    const result = await callLaravelApi(
      `/api/v1/account/notifications/${encodeURIComponent(id)}/read`,
      {
        method: 'PATCH',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return await forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to mark notification as read.', 503);
  }
}
