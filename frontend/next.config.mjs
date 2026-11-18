import { PHASE_DEVELOPMENT_SERVER } from 'next/constants.js';

/** @param {string} phase */
export default function createNextConfig(phase) {
  /** @type {import('next').NextConfig} */
  const nextConfig = {
    // Isolate dev server artifacts from production `next build` so building never corrupts live dev chunks.
    distDir: phase === PHASE_DEVELOPMENT_SERVER ? '.next-dev' : '.next',
    reactStrictMode: true,
    poweredByHeader: false,
    async headers() {
      return [
        {
          source: '/(.*)',
          headers: [
            { key: 'X-Content-Type-Options', value: 'nosniff' },
            { key: 'X-Frame-Options', value: 'DENY' },
            { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
            { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=(self)' },
          ],
        },
        {
          source: '/dashboard/:path*',
          headers: [
            { key: 'X-Robots-Tag', value: 'noindex, nofollow' },
            { key: 'Cache-Control', value: 'private, no-store' },
          ],
        },
      ];
    },
  };

  return nextConfig;
}
