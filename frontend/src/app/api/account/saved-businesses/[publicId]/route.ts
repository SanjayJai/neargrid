import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidPublicUlid,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

interface RouteContext {
  params: Promise<{ publicId: string }> | { publicId: string };
}

async function handleSavedBusinessMutation(
  request: Request,
  context: RouteContext,
  method: 'PUT' | 'DELETE'
): Promise<NextResponse> {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const resolvedParams = await Promise.resolve(context.params);
  const publicId = resolvedParams?.publicId;
  if (!publicId || !isValidPublicUlid(publicId)) {
    return bffError('INVALID_BUSINESS_ID', 'Invalid business identifier.', 400);
  }

  try {
    const result = await callLaravelApi(
      `/api/v1/account/saved-businesses/${encodeURIComponent(publicId)}`,
      {
        method,
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return await forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError(
      'UPSTREAM_UNREACHABLE',
      method === 'PUT'
        ? 'Unable to save business at this time.'
        : 'Unable to remove saved business at this time.',
      503
    );
  }
}

export async function PUT(
  request: Request,
  context: RouteContext
): Promise<NextResponse> {
  return handleSavedBusinessMutation(request, context, 'PUT');
}

export async function DELETE(
  request: Request,
  context: RouteContext
): Promise<NextResponse> {
  return handleSavedBusinessMutation(request, context, 'DELETE');
}
