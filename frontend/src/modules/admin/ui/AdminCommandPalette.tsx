'use client';

import { useEffect, useMemo, useState, type KeyboardEvent } from 'react';
import { useRouter } from 'next/navigation';
import { ArrowRight, Command, Search, X } from 'lucide-react';
import { filterAdminNavigation } from '@/modules/admin/lib/admin-navigation';
import { AdminStatusBadge } from './AdminStatusBadge';

interface AdminCommandPaletteProps {
  open: boolean;
  onClose: () => void;
}

export function AdminCommandPalette({ open, onClose }: AdminCommandPaletteProps) {
  const router = useRouter();
  const [query, setQuery] = useState('');
  const [activeIndex, setActiveIndex] = useState(0);

  const items = useMemo(() => filterAdminNavigation(query), [query]);

  useEffect(() => {
    setActiveIndex(0);
  }, [query, open]);

  useEffect(() => {
    if (!open) return;
    function onKeyDown(e: globalThis.KeyboardEvent) {
      if (e.key === 'Escape') {
        onClose();
      }
    }
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [open, onClose]);

  if (!open) return null;

  function navigateTo(href: string, enabled: boolean) {
    if (!enabled) return;
    onClose();
    router.push(href);
  }

  function handleInputKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (items.length === 0) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActiveIndex((prev) => (prev + 1) % items.length);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActiveIndex((prev) => (prev - 1 + items.length) % items.length);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const selected = items[activeIndex];
      if (selected) {
        navigateTo(selected.href, selected.enabled);
      }
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center bg-slate-950/60 p-4 pt-20 backdrop-blur-xs"
      role="dialog"
      aria-modal="true"
      aria-label="Admin command palette"
    >
      <div className="w-full max-w-xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-center gap-2.5 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
          <Search className="h-4 w-4 text-slate-400" aria-hidden="true" />
          <input
            type="text"
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={handleInputKeyDown}
            placeholder="Jump to any admin module or capability..."
            className="flex-1 bg-transparent text-sm text-slate-950 placeholder:text-slate-400 focus:outline-none dark:text-white"
          />
          <button
            type="button"
            onClick={onClose}
            aria-label="Close command palette"
            className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800"
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        </div>

        <div className="max-h-80 overflow-y-auto p-2">
          {items.length === 0 ? (
            <p className="px-3 py-6 text-center text-xs text-slate-500 dark:text-slate-400">
              No admin modules matched &ldquo;{query}&rdquo;.
            </p>
          ) : (
            <ul role="listbox" aria-label="Admin navigation results" className="space-y-1">
              {items.map((item, idx) => {
                const isSelected = idx === activeIndex;
                return (
                  <li key={item.key}>
                    <button
                      type="button"
                      role="option"
                      aria-selected={isSelected}
                      disabled={!item.enabled}
                      onClick={() => navigateTo(item.href, item.enabled)}
                      className={`flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left transition-colors ${
                        isSelected
                          ? 'bg-brand-50 text-brand-900 dark:bg-brand-950/60 dark:text-white'
                          : 'hover:bg-slate-50 dark:hover:bg-slate-800/60'
                      } ${!item.enabled ? 'cursor-not-allowed opacity-70' : ''}`}
                    >
                      <div className="min-w-0">
                        <p className="text-xs font-bold text-slate-900 dark:text-white">
                          {item.label}
                        </p>
                        <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">
                          {item.description}
                        </p>
                      </div>
                      <div className="flex shrink-0 items-center gap-2">
                        {item.enabled ? (
                          <ArrowRight className="h-3.5 w-3.5 text-brand-600" aria-hidden="true" />
                        ) : (
                          <AdminStatusBadge label={item.milestone} tone="neutral" />
                        )}
                      </div>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </div>

        <div className="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-2 text-[11px] text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
          <span className="inline-flex items-center gap-1">
            <Command className="h-3 w-3" aria-hidden="true" />
            Navigation search active; cross-module record search rolls out in M2–M9.
          </span>
          <span>↑↓ Navigate • Enter Open • Esc Close</span>
        </div>
      </div>
    </div>
  );
}
