'use client';

import type { ReactNode } from 'react';
import { ArrowDownUp, ChevronLeft, ChevronRight, Inbox, Search } from 'lucide-react';

export interface AdminTableColumn<T> {
  key: string;
  label: string;
  sortable?: boolean;
  align?: 'left' | 'right';
  render: (row: T) => ReactNode;
}

export interface AdminTableBulkAction {
  key: string;
  label: string;
  allowed: boolean;
  tone?: 'default' | 'danger';
  onExecute: (selectedKeys: string[]) => void;
}

export interface AdminSavedView {
  key: string;
  label: string;
}

interface AdminDataTableProps<T> {
  columns: AdminTableColumn<T>[];
  rows: T[];
  rowKey: (row: T) => string;
  loading?: boolean;
  error?: string | null;
  onRetry?: () => void;
  emptyTitle?: string;
  emptyDescription?: string;
  searchValue?: string;
  searchPlaceholder?: string;
  onSearchChange?: (val: string) => void;
  sortBy?: string;
  sortDir?: 'asc' | 'desc';
  onSortChange?: (key: string) => void;
  page?: number;
  lastPage?: number;
  total?: number;
  onPageChange?: (page: number) => void;
  selectedKeys?: string[];
  onSelectionChange?: (keys: string[]) => void;
  bulkActions?: AdminTableBulkAction[];
  savedViews?: AdminSavedView[];
  activeViewKey?: string;
  onSelectView?: (viewKey: string) => void;
  toolbarExtra?: ReactNode;
  onRowClick?: (row: T) => void;
}

export function AdminDataTable<T>({
  columns,
  rows,
  rowKey,
  loading = false,
  error = null,
  onRetry,
  emptyTitle = 'No records found',
  emptyDescription = 'Try adjusting your filters or search criteria.',
  searchValue,
  searchPlaceholder = 'Filter records...',
  onSearchChange,
  sortBy,
  sortDir,
  onSortChange,
  page = 1,
  lastPage = 1,
  total,
  onPageChange,
  selectedKeys = [],
  onSelectionChange,
  bulkActions = [],
  savedViews = [],
  activeViewKey,
  onSelectView,
  toolbarExtra,
  onRowClick,
}: AdminDataTableProps<T>) {
  const selectable = Boolean(onSelectionChange);
  const allVisibleKeys = rows.map(rowKey);
  const allSelected =
    selectable &&
    allVisibleKeys.length > 0 &&
    allVisibleKeys.every((k) => selectedKeys.includes(k));

  function toggleAll() {
    if (!onSelectionChange) return;
    if (allSelected) {
      onSelectionChange(selectedKeys.filter((k) => !allVisibleKeys.includes(k)));
    } else {
      onSelectionChange(Array.from(new Set([...selectedKeys, ...allVisibleKeys])));
    }
  }

  function toggleOne(key: string) {
    if (!onSelectionChange) return;
    if (selectedKeys.includes(key)) {
      onSelectionChange(selectedKeys.filter((k) => k !== key));
    } else {
      onSelectionChange([...selectedKeys, key]);
    }
  }

  const permittedBulkActions = bulkActions.filter((a) => a.allowed);

  return (
    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
      {/* Toolbar: Saved Views, Search & Extra */}
      {(savedViews.length > 0 || onSearchChange || toolbarExtra) && (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
          <div className="flex flex-wrap items-center gap-2">
            {savedViews.map((view) => (
              <button
                key={view.key}
                type="button"
                onClick={() => onSelectView?.(view.key)}
                className={`rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors ${
                  activeViewKey === view.key
                    ? 'bg-brand-50 text-brand-700 dark:bg-brand-950/60 dark:text-brand-300'
                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
                }`}
              >
                {view.label}
              </button>
            ))}
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {onSearchChange && (
              <div className="relative">
                <Search
                  className="pointer-events-none absolute left-2.5 top-2 h-3.5 w-3.5 text-slate-400"
                  aria-hidden="true"
                />
                <input
                  type="search"
                  value={searchValue ?? ''}
                  onChange={(e) => onSearchChange(e.target.value)}
                  placeholder={searchPlaceholder}
                  aria-label={searchPlaceholder}
                  className="h-8 w-56 rounded-lg border border-slate-200 bg-slate-50 pl-8 pr-3 text-xs text-slate-900 focus:border-brand-500 focus:bg-white focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>
            )}
            {toolbarExtra}
          </div>
        </div>
      )}

      {/* Bulk Action Bar */}
      {selectable && selectedKeys.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-brand-200 bg-brand-50/70 px-4 py-2 text-xs dark:border-brand-900 dark:bg-brand-950/40">
          <span className="font-semibold text-brand-800 dark:text-brand-200">
            {selectedKeys.length} selected
          </span>
          <div className="flex flex-wrap items-center gap-2">
            {permittedBulkActions.map((action) => (
              <button
                key={action.key}
                type="button"
                onClick={() => action.onExecute(selectedKeys)}
                className={`rounded-lg px-3 py-1 font-semibold transition-colors ${
                  action.tone === 'danger'
                    ? 'bg-rose-600 text-white hover:bg-rose-700'
                    : 'bg-white text-slate-800 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-800 dark:text-white dark:ring-slate-700'
                }`}
              >
                {action.label}
              </button>
            ))}
            <button
              type="button"
              onClick={() => onSelectionChange?.([])}
              className="font-semibold text-slate-600 hover:text-slate-900 dark:text-slate-300"
            >
              Clear
            </button>
          </div>
        </div>
      )}

      {/* Body States */}
      {loading ? (
        <div className="space-y-3 p-6">
          {[1, 2, 3, 4].map((n) => (
            <div
              key={n}
              className="h-9 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800"
            />
          ))}
        </div>
      ) : error ? (
        <div className="p-8 text-center">
          <p className="text-sm font-semibold text-rose-600 dark:text-rose-400">
            {error}
          </p>
          {onRetry && (
            <button
              type="button"
              onClick={onRetry}
              className="mt-3 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200"
            >
              Retry
            </button>
          )}
        </div>
      ) : rows.length === 0 ? (
        <div className="p-10 text-center">
          <Inbox className="mx-auto h-8 w-8 text-slate-400" aria-hidden="true" />
          <p className="mt-2 text-sm font-bold text-slate-900 dark:text-white">
            {emptyTitle}
          </p>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            {emptyDescription}
          </p>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-left text-xs">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50/80 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:bg-slate-800/60 dark:text-slate-400">
                {selectable && (
                  <th className="w-10 px-4 py-3">
                    <input
                      type="checkbox"
                      aria-label="Select all rows"
                      checked={allSelected}
                      onChange={toggleAll}
                      className="h-3.5 w-3.5 rounded border-slate-300 text-brand-600"
                    />
                  </th>
                )}
                {columns.map((col) => (
                  <th
                    key={col.key}
                    className={`px-4 py-3 ${col.align === 'right' ? 'text-right' : 'text-left'}`}
                  >
                    {col.sortable && onSortChange ? (
                      <button
                        type="button"
                        onClick={() => onSortChange(col.key)}
                        className="inline-flex items-center gap-1 hover:text-slate-900 dark:hover:text-white"
                      >
                        <span>{col.label}</span>
                        <ArrowDownUp
                          className={`h-3 w-3 ${
                            sortBy === col.key ? 'text-brand-600' : 'text-slate-400'
                          }`}
                          aria-hidden="true"
                        />
                        {sortBy === col.key && sortDir && (
                          <span className="sr-only">({sortDir})</span>
                        )}
                      </button>
                    ) : (
                      col.label
                    )}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {rows.map((row) => {
                const key = rowKey(row);
                const checked = selectedKeys.includes(key);
                return (
                  <tr
                    key={key}
                    onClick={onRowClick ? () => onRowClick(row) : undefined}
                    className={`transition-colors ${
                      onRowClick
                        ? 'cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50'
                        : 'hover:bg-slate-50/60 dark:hover:bg-slate-800/30'
                    }`}
                  >
                    {selectable && (
                      <td
                        className="px-4 py-3"
                        onClick={(e) => e.stopPropagation()}
                      >
                        <input
                          type="checkbox"
                          aria-label={`Select row ${key}`}
                          checked={checked}
                          onChange={() => toggleOne(key)}
                          className="h-3.5 w-3.5 rounded border-slate-300 text-brand-600"
                        />
                      </td>
                    )}
                    {columns.map((col) => (
                      <td
                        key={col.key}
                        className={`px-4 py-3 text-slate-700 dark:text-slate-200 ${
                          col.align === 'right' ? 'text-right' : 'text-left'
                        }`}
                      >
                        {col.render(row)}
                      </td>
                    ))}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Server-Side Pagination */}
      {onPageChange && lastPage > 1 && (
        <div className="flex items-center justify-between border-t border-slate-200 px-4 py-2.5 text-xs text-slate-600 dark:border-slate-800 dark:text-slate-400">
          <span>
            Page <strong className="text-slate-900 dark:text-white">{page}</strong> of{' '}
            <strong className="text-slate-900 dark:text-white">{lastPage}</strong>
            {total !== undefined ? ` (${total} total)` : ''}
          </span>
          <div className="flex items-center gap-1.5">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => onPageChange(page - 1)}
              className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 font-semibold hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800"
            >
              <ChevronLeft className="h-3.5 w-3.5" aria-hidden="true" />
              Prev
            </button>
            <button
              type="button"
              disabled={page >= lastPage}
              onClick={() => onPageChange(page + 1)}
              className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 font-semibold hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800"
            >
              Next
              <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
