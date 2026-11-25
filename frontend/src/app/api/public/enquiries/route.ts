import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  extractBffTrustedHost,
  extractSafeEntryPath,
  forwardLaravelJson,
  parseJsonBody,
  stripUntrustedDomainFields,
} from '@/lib/bff/route-helpers';
import { readCurrentServerSession } from '@/lib/bff/session-cookie';

export async function POST(request: Request): Promise<NextResponse> {
  const trustedHost = extractBffTrustedHost(request);
  if (!trustedHost) {
    return bffError('INVALID_HOST', 'Invalid request host.', 400);
  }

  const securityError = enforceMutationSecurity(request);
  if (securityError) return securityError;

  const parsed = await parseJsonBody(request);
  if (!parsed.ok) return parsed.response;

  // Optional bearer auth injection if customer has an existing session
  const session = await readCurrentServerSession();
  const bearerToken = session?.record.accessToken;
  const opaqueId = session?.opaqueSessionId;

  try {
    const result = await callLaravelApi('/api/v1/enquiries', {
      method: 'POST',
      body: stripUntrustedDomainFields(parsed.data),
      bearerToken,
      requestId: request.headers.get('x-request-id'),
      requestHost: trustedHost,
      entryPath: extractSafeEntryPath(request),
    });

    return forwardLaravelJson(result, opaqueId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to process enquiry at this time.', 503);
  }
}
