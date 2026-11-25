import fs from 'node:fs';
import path from 'node:path';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

(globalThis as { React?: typeof React }).React = React;

vi.mock('next/navigation', () => ({
  useRouter: () => ({
    push: vi.fn(),
    replace: vi.fn(),
    refresh: vi.fn(),
  }),
}));

import LoginPage from '@/app/(customer)/login/page';
import { sanitizeInternalRedirectPath } from '@/modules/customer/lib/auth-redirect';

describe('LoginPage SSR & redirect sanitization regression', () => {
  it('does not call a helper imported from a use-client component in login/page.tsx', () => {
    const source = fs.readFileSync(
      path.resolve(__dirname, '../../../app/(customer)/login/page.tsx'),
      'utf8'
    );
    expect(source).toContain("from '@/modules/customer/lib/auth-redirect'");
    expect(source).not.toMatch(
      /sanitizeInternalRedirectPath[\s\S]*from\s+['"]@\/modules\/customer\/components\/OtpLoginForm['"]/
    );
  });

  it('renders /login cleanly without throwing when searchParams is omitted', async () => {
    const element = await LoginPage({});
    const html = renderToStaticMarkup(element);
    expect(html).toContain('Sign in with Mobile OTP');
    expect(html).toContain('Continue with OTP');
  });

  it('renders /login?next=/saved cleanly and preserves safe internal redirect paths', async () => {
    const element = await LoginPage({
      searchParams: Promise.resolve({ next: '/saved' }),
    });
    const html = renderToStaticMarkup(element);
    expect(html).toContain('Sign in with Mobile OTP');
    expect(sanitizeInternalRedirectPath('/saved')).toBe('/saved');
    expect(sanitizeInternalRedirectPath('//evil.example.com')).toBe('/dashboard/settings');
    expect(sanitizeInternalRedirectPath('https://evil.example.com')).toBe('/dashboard/settings');
  });
});
