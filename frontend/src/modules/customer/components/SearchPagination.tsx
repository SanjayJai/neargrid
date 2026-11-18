import Link from 'next/link';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface SearchPaginationProps {
  currentPage: number;
  totalPages: number;
  totalItems: number;
  perPage?: number;
  buildPageHref: (page: number) => string;
  onPageChange?: (page: number) => void;
}

export function SearchPagination({
  currentPage,
  totalPages,
  totalItems,
  perPage = 20,
  buildPageHref,
  onPageChange,
}: SearchPaginationProps) {
  if (totalPages <= 1) return null;

  const startItem = Math.min((currentPage - 1) * perPage + 1, totalItems);
  const endItem = Math.min(currentPage * perPage, totalItems);

  // Compute page numbers window
  const getPageNumbers = () => {
    const pages: (number | 'ellipsis')[] = [];
    const maxVisible = 5;

    if (totalPages <= maxVisible + 2) {
      for (let i = 1; i <= totalPages; i++) pages.push(i);
    } else {
      pages.push(1);
      const start = Math.max(2, currentPage - 1);
      const end = Math.min(totalPages - 1, currentPage + 1);

      if (start > 2) pages.push('ellipsis');
      for (let i = start; i <= end; i++) pages.push(i);
      if (end < totalPages - 1) pages.push('ellipsis');
      pages.push(totalPages);
    }
    return pages;
  };

  const pages = getPageNumbers();

  const handlePageClick = (page: number, e: React.MouseEvent) => {
    if (onPageChange) {
      e.preventDefault();
      onPageChange(page);
    }
  };

  return (
    <nav
      aria-label="Search pagination"
      className="flex flex-col sm:flex-row items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm"
    >
      <div className="text-xs text-ink-muted">
        Showing <span className="font-semibold text-ink">{startItem}</span> to{' '}
        <span className="font-semibold text-ink">{endItem}</span> of{' '}
        <span className="font-semibold text-ink">{totalItems}</span> businesses
      </div>

      <div className="flex items-center gap-1">
        {/* Previous */}
        {currentPage > 1 ? (
          <Link
            href={buildPageHref(currentPage - 1)}
            onClick={(e) => handlePageClick(currentPage - 1, e)}
            className="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-semibold text-ink hover:bg-gray-50"
            aria-label="Go to previous page"
          >
            <ChevronLeft className="h-4 w-4" aria-hidden="true" />
            <span className="hidden sm:inline">Prev</span>
          </Link>
        ) : (
          <span
            aria-disabled="true"
            className="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-300 cursor-not-allowed"
          >
            <ChevronLeft className="h-4 w-4" aria-hidden="true" />
            <span className="hidden sm:inline">Prev</span>
          </span>
        )}

        {/* Page numbers */}
        {pages.map((p, idx) => {
          if (p === 'ellipsis') {
            return (
              <span key={`ellipsis-${idx}`} className="px-2 py-1 text-xs text-gray-400">
                …
              </span>
            );
          }

          const isActive = p === currentPage;

          return (
            <Link
              key={p}
              href={buildPageHref(p)}
              onClick={(e) => handlePageClick(p, e)}
              aria-current={isActive ? 'page' : undefined}
              className={`min-w-[32px] text-center rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-colors ${
                isActive
                  ? 'bg-brand-600 text-white shadow-xs'
                  : 'text-ink hover:bg-gray-100'
              }`}
            >
              {p}
            </Link>
          );
        })}

        {/* Next */}
        {currentPage < totalPages ? (
          <Link
            href={buildPageHref(currentPage + 1)}
            onClick={(e) => handlePageClick(currentPage + 1, e)}
            className="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-semibold text-ink hover:bg-gray-50"
            aria-label="Go to next page"
          >
            <span className="hidden sm:inline">Next</span>
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </Link>
        ) : (
          <span
            aria-disabled="true"
            className="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-300 cursor-not-allowed"
          >
            <span className="hidden sm:inline">Next</span>
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </span>
        )}
      </div>
    </nav>
  );
}
