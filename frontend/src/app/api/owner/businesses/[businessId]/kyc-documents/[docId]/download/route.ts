import { NextResponse } from 'next/server';
import {
  callLaravelApi,
  fetchLaravelSignedKycBinary,
} from '@/lib/bff/laravel-client';
import {
  bffError,
  forwardLaravelJson,
  isValidPublicUlid,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import { clearOpaqueSessionCookie } from '@/lib/bff/session-cookie';
import { getBffSessionStore } from '@/lib/bff/session-store';
import type { LaravelKycSignedDownloadServerDto } from '@/shared/api/directory-types';

/**
 * Server-side private KYC download proxy:
 * 1. Authenticates browser via opaque HttpOnly `lbd_sid` cookie.
 * 2. Requests a short-lived signed URL from Laravel server-side (enforcing KycDocumentPolicy).
 * 3. Validates signed URL origin (SSRF protection) and fetches private bytes with Bearer token.
 * 4. Streams raw PDF/WebP bytes to browser with `Cache-Control: private, no-store` and `nosniff`.
 * NEVER exposes Sanctum Bearer tokens, signed URLs/signatures, or private storage paths to browser JS.
 */
export async function GET(
  request: Request,
  context: { params: { businessId: string; docId: string } }
) {
  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId, docId } = context.params;
  if (!isValidPublicUlid(businessId) || !isValidPublicUlid(docId)) {
    return bffError('INVALID_IDENTIFIER', 'Invalid business or KYC document identifier.', 400);
  }

  const requestId = request.headers.get('x-request-id');

  try {
    // Step 1: Mint actor-bound short-lived signed URL server-side
    const signedRes = await callLaravelApi<LaravelKycSignedDownloadServerDto>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/kyc-documents/${encodeURIComponent(docId)}/signed-url`,
      {
        method: 'POST',
        bearerToken: session.record.accessToken,
        requestId,
      }
    );

    if (!signedRes.ok || !signedRes.payload || signedRes.payload.status !== 'success') {
      return forwardLaravelJson(signedRes, session.opaqueSessionId);
    }

    const signedUrl = signedRes.payload.data.download_url;

    // Step 2: Fetch private KYC bytes server-side using Bearer token + signed URL
    const download = await fetchLaravelSignedKycBinary(
      signedUrl,
      session.record.accessToken,
      requestId
    );

    if (!download.ok || !download.bytes) {
      const errRes = NextResponse.json(
        download.errorPayload ?? {
          status: 'error',
          error: {
            code: 'KYC_DOWNLOAD_FAILED',
            message: 'Unable to retrieve the requested KYC document.',
          },
        },
        { status: download.status }
      );
      errRes.headers.set('Cache-Control', 'private, no-store');

      if (download.status === 401) {
        await getBffSessionStore().delete(session.opaqueSessionId);
        clearOpaqueSessionCookie(errRes);
      }

      return errRes;
    }

    const safeContentType =
      download.contentType.startsWith('application/pdf') ||
      download.contentType.startsWith('image/webp')
        ? download.contentType
        : 'application/octet-stream';

    const extension = safeContentType.startsWith('application/pdf') ? 'pdf' : 'webp';
    const response = new NextResponse(download.bytes, {
      status: 200,
      headers: {
        'Content-Type': safeContentType,
        'Content-Disposition': `inline; filename="kyc-${docId}.${extension}"`,
        'Cache-Control': 'private, no-store, max-age=0',
        Pragma: 'no-cache',
        'X-Content-Type-Options': 'nosniff',
      },
    });

    if (download.requestId) {
      response.headers.set('X-Request-ID', download.requestId);
    }

    return response;
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to retrieve KYC document.', 503);
  }
}
