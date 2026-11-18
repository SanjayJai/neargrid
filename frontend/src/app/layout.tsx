import type { Metadata } from 'next';
import { Inter } from 'next/font/google';
import type { ReactNode } from 'react';
import './globals.css';

const inter = Inter({
  subsets: ['latin'],
  variable: '--font-inter',
  display: 'swap',
});

export const metadata: Metadata = {
  title: {
    default: 'NearGrid — Local Business Directory',
    template: '%s | NearGrid',
  },
  description:
    'Discover verified local professionals, clinics, restaurants, and home services across Indian cities with transparent reviews and privacy-first enquiries.',
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en" className={inter.variable}>
      <body className="min-h-screen bg-white font-sans text-zinc-950">
        {children}
      </body>
    </html>
  );
}
