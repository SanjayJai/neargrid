'use client';

import Link from 'next/link';
import { ChevronRight } from 'lucide-react';

export interface AdminBreadcrumbItem {
  label: string;
  href?: string;
}

interface AdminBreadcrumbsProps {
  items: AdminBreadcrumbItem[];
}

export function AdminBreadcrumbs({ items }: AdminBreadcrumbsProps) {
  return (
    <nav aria-label="Breadcrumb" className="flex items-center gap-1.5 text-xs">
      {items.map((item, idx) => {
        const isLast = idx === items.length - 1;
        return (
          <span key={`${item.label}-${idx}`} className="flex items-center gap-1.5">
            {idx > 0 && (
              <ChevronRight
                className="h-3.5 w-3.5 text-slate-400 dark:text-slate-500"
                aria-hidden="true"
              />
            )}
            {item.href && !isLast ? (
              <Link
                href={item.href}
                className="font-medium text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
              >
                {item.label}
              </Link>
            ) : (
              <span className="font-semibold text-slate-900 dark:text-white">
                {item.label}
              </span>
            )}
          </span>
        );
      })}
    </nav>
  );
}
