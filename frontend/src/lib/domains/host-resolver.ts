import { headers } from 'next/headers';
import { fetchPublicResolvedDomain } from '@/lib/api/public-directory';
import type { PublicResolvedDomainDto } from '@/shared/api/directory-types';

const HOSTNAME_REGEX =
  /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

export interface HeaderLookup {
  get(name: string): string | null;
}

export interface ResolvedDomainContext {
  kind: 'resolved' | 'primary_fallback' | 'unregistered_or_blocked' | 'invalid';
  hostname: string | null;
  domain: PublicResolvedDomainDto | null;
  isIndexable: boolean;
}

/**
 * Normalizes an incoming Host header strictly, validating optional port (1..65535)
 * and rejecting malformed or injection-prone host values.
 */
export function normalizeRequestHostname(rawHost?: string | null): string | null {
  if (!rawHost) return null;
  const trimmed = rawHost.trim().toLowerCase();
  if (!trimmed || trimmed.includes(',') || /[\s/\\?#\u0000-\u001f\u007f]/.test(trimmed)) {
    return null;
  }

  let hostPart = trimmed;
  const colonIdx = trimmed.lastIndexOf(':');
  if (colonIdx !== -1) {
    const portStr = trimmed.slice(colonIdx + 1);
    if (!/^\d{1,5}$/.test(portStr)) {
      return null;
    }
    const port = Number(portStr);
    if (port < 1 || port > 65535) {
      return null;
    }
    hostPart = trimmed.slice(0, colonIdx);
  }

  const clean = hostPart.replace(/\.$/, '');
  if (!clean || !HOSTNAME_REGEX.test(clean)) {
    return null;
  }

  return clean;
}

/**
 * Extracts the trusted request hostname.
 * X-Forwarded-Host is accepted ONLY when TRUST_FORWARDED_HOST=true AND
 * the request carries the matching TRUSTED_PROXY_SECRET header.
 */
export function extractTrustedRequestHost(
  headerBag: HeaderLookup,
  options?: {
    trustForwardedHost?: boolean;
    trustedProxySecret?: string;
  }
): string | null {
  const trustForwarded = options?.trustForwardedHost ?? process.env.TRUST_FORWARDED_HOST === 'true';
  const expectedSecret = options?.trustedProxySecret ?? process.env.TRUSTED_PROXY_SECRET ?? '';
  const presentedSecret = headerBag.get('x-trusted-proxy-secret') ?? '';

  const canTrustForwarded =
    trustForwarded && expectedSecret.length > 0 && presentedSecret === expectedSecret;

  const candidate = canTrustForwarded
    ? headerBag.get('x-forwarded-host') ?? headerBag.get('host')
    : headerBag.get('host');

  return normalizeRequestHostname(candidate);
}

export function isLocalDevHost(
  hostname: string,
  nodeEnv: string | undefined = process.env.NODE_ENV
): boolean {
  if (nodeEnv === 'production') {
    return false;
  }
  return hostname === 'localhost' || hostname === '127.0.0.1';
}

export async function resolveDomainContextForHost(
  rawHost: string | null,
  lookupDomain: typeof fetchPublicResolvedDomain = fetchPublicResolvedDomain,
  nodeEnv: string | undefined = process.env.NODE_ENV
): Promise<ResolvedDomainContext> {
  const hostname = normalizeRequestHostname(rawHost);
  if (!hostname) {
    return {
      kind: 'invalid',
      hostname: null,
      domain: null,
      isIndexable: false,
    };
  }

  const res = await lookupDomain(hostname);
  if (res.ok && res.data) {
    return {
      kind: 'resolved',
      hostname: res.data.hostname,
      domain: res.data,
      isIndexable: Boolean(res.data.is_indexable),
    };
  }

  if (isLocalDevHost(hostname, nodeEnv)) {
    return {
      kind: 'primary_fallback',
      hostname,
      domain: null,
      isIndexable: false,
    };
  }

  return {
    kind: 'unregistered_or_blocked',
    hostname,
    domain: null,
    isIndexable: false,
  };
}

export async function getServerDomainContext(): Promise<ResolvedDomainContext> {
  const headerBag = await Promise.resolve(headers());
  const trustedHost = extractTrustedRequestHost(headerBag);
  return resolveDomainContextForHost(trustedHost);
}
