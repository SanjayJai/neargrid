import { NextResponse, type NextRequest } from 'next/server';
import { extractTrustedRequestHost, normalizeRequestHostname } from '@/lib/domains/host-resolver';

const SESSION_COOKIE_NAME = 'lbd_sid';

export function middleware(request: NextRequest): NextResponse {
  const { pathname, search } = request.nextUrl;
  const requestedPath = `${pathname}${search}`;

  const rawHeaderHost = request.headers.get('host');
  const trustedHost = rawHeaderHost !== null
    ? extractTrustedRequestHost(request.headers)
    : normalizeRequestHostname(request.nextUrl.host);
  if (!trustedHost) {
    return new NextResponse('Invalid Host header', {
      status: 400,
      headers: { 'Cache-Control': 'no-store' },
    });
  }

  if (pathname === '/dashboard' || pathname.startsWith('/dashboard/')) {
    const hasSessionCookie = Boolean(request.cookies.get(SESSION_COOKIE_NAME)?.value);
    if (!hasSessionCookie) {
      const loginUrl = new URL('/login', request.url);
      loginUrl.searchParams.set('next', requestedPath);
      return NextResponse.redirect(loginUrl);
    }
  }

  const requestHeaders = new Headers(request.headers);
  requestHeaders.set('x-pathname', requestedPath);
  // Overwrite any client-supplied internal host header with validated host.
  requestHeaders.set('x-neargrid-host', trustedHost);

  return NextResponse.next({
    request: {
      headers: requestHeaders,
    },
  });
}

export const config = {
  matcher: ['/dashboard', '/dashboard/:path*'],
};
