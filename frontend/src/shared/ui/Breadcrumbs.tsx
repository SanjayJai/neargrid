import Link from 'next/link';
import { ChevronRight, Home } from 'lucide-react';

export interface BreadcrumbItem {
  label: string;
  href?: string;
}

interface BreadcrumbsProps {
  items: BreadcrumbItem[];
  className?: string;
}

/**
 * Accessible Breadcrumb navigation with automated Schema.org BreadcrumbList JSON-LD.
 * Conforms to docs/12-seo-architecture.md.
 */
export function Breadcrumbs({ items, className = '' }: BreadcrumbsProps) {
  const siteUrl = (
    process.env.NEXT_PUBLIC_SITE_URL || 'http://localhost:3000'
  ).replace(/\/$/, '');

  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, index) => {
      const fullUrl = item.href
        ? item.href.startsWith('http')
          ? item.href
          : `${siteUrl}${item.href.startsWith('/') ? '' : '/'}${item.href}`
        : `${siteUrl}/`;

      return {
        '@type': 'ListItem',
        position: index + 1,
        name: item.label,
        item: fullUrl,
      };
    }),
  };

  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />
      <nav
        aria-label="Breadcrumb"
        className={`flex items-center text-xs text-ink-muted ${className}`}
      >
        <ol className="flex flex-wrap items-center gap-1.5 sm:gap-2">
          {items.map((item, index) => {
            const isLast = index === items.length - 1;
            const isFirst = index === 0;

            return (
              <li key={`${item.label}-${index}`} className="flex items-center gap-1.5 sm:gap-2">
                {index > 0 && (
                  <ChevronRight
                    className="h-3 w-3 text-gray-400 shrink-0"
                    aria-hidden="true"
                  />
                )}
                {isLast || !item.href ? (
                  <span
                    aria-current={isLast ? 'page' : undefined}
                    className="font-medium text-ink truncate max-w-[200px] sm:max-w-[320px]"
                  >
                    {isFirst && <Home className="inline mr-1 h-3 w-3" aria-hidden="true" />}
                    {item.label}
                  </span>
                ) : (
                  <Link
                    href={item.href}
                    className="flex items-center hover:text-brand-700 transition-colors"
                  >
                    {isFirst && <Home className="inline mr-1 h-3 w-3" aria-hidden="true" />}
                    <span>{item.label}</span>
                  </Link>
                )}
              </li>
            );
          })}
        </ol>
      </nav>
    </>
  );
}
