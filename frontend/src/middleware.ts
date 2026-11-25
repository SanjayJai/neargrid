import { NextResponse, type NextRequest } from 'next/server';

const SESSION_COOKIE_NAME = 'lbd_sid';

export function middleware(request: NextRequest): NextResponse {
  const { pathname, search } = request.nextUrl;
  const requestedPath = `${pathname}${search}`;

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

  return NextResponse.next({
    request: {
      headers: requestHeaders,
    },
  });
}

export const config = {
  matcher: ['/dashboard', '/dashboard/:path*'],
};
