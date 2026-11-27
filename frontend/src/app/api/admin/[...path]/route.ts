import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

export const dynamic = 'force-dynamic';

const ALLOWED_PREFIXES = new Set([
  'businesses',
  'organizations',
  'verifications',
  'claims',
  'reviews',
  'enquiries',
  'categories',
  'category-collections',
  'locations',
  'domains',
  'seo',
  'users',
  'roles',
  'permissions',
  'security',
  'erasure-requests',
  'advertising',
]);

const SAFE_SEGMENT_REGEX = /^[a-zA-Z0-9._-]+$/;

async function proxyAdminRequest(
  request: Request,
  context: { params: Promise<{ path: string[] }> }
): Promise<NextResponse> {
  if (request.method !== 'GET' && request.method !== 'HEAD') {
    const securityError = enforceMutationSecurity(request);
    if (securityError) return securityError;
  }

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { path } = await context.params;
  if (!Array.isArray(path) || path.length === 0 || !ALLOWED_PREFIXES.has(path[0])) {
    return bffError('FORBIDDEN_ADMIN_PATH', 'Unsupported admin API resource.', 404);
  }

  for (const segment of path) {
    if (
      !segment ||
      segment === '.' ||
      segment === '..' ||
      segment.includes('..') ||
      segment.includes('\\') ||
      !SAFE_SEGMENT_REGEX.test(segment)
    ) {
      return bffError('INVALID_ADMIN_PATH', 'Invalid admin resource path.', 400);
    }
  }

  const url = new URL(request.url);
  const query = url.searchParams.toString();
  const upstreamPath = `/api/v1/admin/${path.join('/')}${query ? `?${query}` : ''}`;

  let body: string | undefined;
  if (request.method !== 'GET' && request.method !== 'HEAD') {
    const raw = await request.text();
    if (raw.trim().length > 0) {
      body = raw;
    }
  }

  try {
    const result = await callLaravelApi(upstreamPath, {
      method: request.method as 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
      body,
    });

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to reach admin backend service.', 503);
  }
}

export async function GET(
  request: Request,
  context: { params: Promise<{ path: string[] }> }
): Promise<NextResponse> {
  return proxyAdminRequest(request, context);
}

export async function POST(
  request: Request,
  context: { params: Promise<{ path: string[] }> }
): Promise<NextResponse> {
  return proxyAdminRequest(request, context);
}

export async function PUT(
  request: Request,
  context: { params: Promise<{ path: string[] }> }
): Promise<NextResponse> {
  return proxyAdminRequest(request, context);
}

export async function PATCH(
  request: Request,
  context: { params: Promise<{ path: string[] }> }
): Promise<NextResponse> {
  return proxyAdminRequest(request, context);
}

export async function DELETE(
  request: Request,
  context: { params: Promise<{ path: string[] }> }
): Promise<NextResponse> {
  return proxyAdminRequest(request, context);
}
