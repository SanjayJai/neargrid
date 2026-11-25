'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Building2, Check, ChevronDown, Loader2, Search } from 'lucide-react';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';

const BRANCH_TYPE_LABELS: Record<string, string> = {
  headquarters: 'HQ',
  company_owned: 'Company',
  franchise: 'Franchise',
  independent: 'Listing',
};

export function BranchSwitcher() {
  const router = useRouter();
  const containerRef = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');

  const {
    businesses,
    activeBusiness,
    activeBusinessId,
    organizations,
    activeOrganization,
    activeOrganizationId,
    orgBranches,
    branchSwitching,
    branchSwitchError,
    selectOrganization,
    selectBranch,
  } = useOwnerBusiness();

  useEffect(() => {
    function handleOutside(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    function handleEscape(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', handleOutside);
    document.addEventListener('keydown', handleEscape);
    return () => {
      document.removeEventListener('mousedown', handleOutside);
      document.removeEventListener('keydown', handleEscape);
    };
  }, []);

  const mergedBranchOptions = useMemo(() => {
    const byId = new Map<
      string,
      { public_id: string; name: string; subtitle: string; badge: string }
    >();

    for (const branch of orgBranches) {
      byId.set(branch.public_id, {
        public_id: branch.public_id,
        name: branch.name,
        subtitle: branch.city_slug ? `${branch.city_slug}` : 'Organization branch',
        badge: BRANCH_TYPE_LABELS[branch.branch_type] ?? 'Branch',
      });
    }

    for (const biz of businesses) {
      if (!byId.has(biz.public_id)) {
        byId.set(biz.public_id, {
          public_id: biz.public_id,
          name: biz.name,
          subtitle: 'Direct listing',
          badge: 'Listing',
        });
      }
    }

    const list = Array.from(byId.values());
    if (!searchTerm.trim()) return list;
    const q = searchTerm.trim().toLowerCase();
    return list.filter(
      (item) => item.name.toLowerCase().includes(q) || item.subtitle.toLowerCase().includes(q)
    );
  }, [orgBranches, businesses, searchTerm]);

  if (businesses.length === 0 && organizations.length === 0) {
    return null;
  }

  async function handleSelectBranch(publicId: string) {
    const ok = await selectBranch(publicId, activeOrganizationId);
    if (ok) {
      setOpen(false);
      router.refresh();
    }
  }

  return (
    <div ref={containerRef} className="relative ml-2 sm:ml-4">
      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        disabled={branchSwitching}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label="Switch organization or branch"
        className="inline-flex h-9 max-w-[180px] items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 text-left text-xs font-semibold text-zinc-900 hover:bg-zinc-100 focus:border-zinc-950 focus:outline-none sm:max-w-[260px]"
      >
        {branchSwitching ? (
          <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin text-zinc-500" aria-hidden="true" />
        ) : (
          <Building2 className="h-3.5 w-3.5 shrink-0 text-zinc-500" aria-hidden="true" />
        )}
        <span className="truncate">
          {activeOrganization ? `${activeOrganization.name} · ` : ''}
          {activeBusiness?.name ?? 'Select branch'}
        </span>
        <ChevronDown className="h-3.5 w-3.5 shrink-0 text-zinc-400" aria-hidden="true" />
      </button>

      {open && (
        <div
          role="dialog"
          aria-label="Organization and branch selector"
          className="absolute left-0 top-11 z-50 w-72 rounded-xl border border-zinc-200 bg-white p-2 shadow-lg sm:w-80"
        >
          {organizations.length > 0 && (
            <div className="mb-2 border-b border-zinc-100 pb-2">
              <label
                htmlFor="org-switcher-select"
                className="mb-1 block px-1 text-[10px] font-bold uppercase tracking-wider text-zinc-400"
              >
                Organization
              </label>
              <select
                id="org-switcher-select"
                value={activeOrganizationId ?? ''}
                onChange={(e) => {
                  void selectOrganization(e.target.value);
                }}
                className="h-8 w-full rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-xs font-semibold text-zinc-900 focus:border-zinc-950 focus:outline-none"
              >
                {organizations.map((org) => (
                  <option key={org.public_id} value={org.public_id}>
                    {org.name}
                  </option>
                ))}
              </select>
            </div>
          )}

          {mergedBranchOptions.length > 5 && (
            <div className="relative mb-2">
              <Search
                className="pointer-events-none absolute left-2.5 top-2 h-3.5 w-3.5 text-zinc-400"
                aria-hidden="true"
              />
              <input
                type="search"
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                placeholder="Filter branches..."
                aria-label="Filter branches"
                className="h-8 w-full rounded-lg border border-zinc-200 bg-white pl-8 pr-2 text-xs text-zinc-900 focus:border-zinc-950 focus:outline-none"
              />
            </div>
          )}

          {branchSwitchError && (
            <div
              role="alert"
              className="mb-2 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-[11px] font-medium text-red-700"
            >
              {branchSwitchError}
            </div>
          )}

          <ul role="listbox" aria-label="Authorized branches" className="max-h-60 space-y-1 overflow-y-auto">
            {mergedBranchOptions.length === 0 ? (
              <li className="px-2 py-3 text-center text-xs text-zinc-500">
                No authorized branches match your filter.
              </li>
            ) : (
              mergedBranchOptions.map((item) => {
                const isSelected = item.public_id === activeBusinessId;
                return (
                  <li key={item.public_id}>
                    <button
                      type="button"
                      role="option"
                      aria-selected={isSelected}
                      onClick={() => {
                        void handleSelectBranch(item.public_id);
                      }}
                      className={`flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-xs transition-colors ${
                        isSelected
                          ? 'bg-zinc-900 font-semibold text-white'
                          : 'text-zinc-800 hover:bg-zinc-100'
                      }`}
                    >
                      <span className="min-w-0 pr-2">
                        <span className="block truncate">{item.name}</span>
                        <span
                          className={`block truncate text-[10px] ${
                            isSelected ? 'text-zinc-300' : 'text-zinc-500'
                          }`}
                        >
                          {item.subtitle}
                        </span>
                      </span>
                      <span className="flex shrink-0 items-center gap-1.5">
                        <span
                          className={`rounded px-1.5 py-0.5 text-[10px] font-bold uppercase ${
                            isSelected
                              ? 'bg-zinc-700 text-zinc-100'
                              : 'bg-zinc-100 text-zinc-600'
                          }`}
                        >
                          {item.badge}
                        </span>
                        {isSelected && <Check className="h-3.5 w-3.5" aria-hidden="true" />}
                      </span>
                    </button>
                  </li>
                );
              })
            )}
          </ul>
        </div>
      )}
    </div>
  );
}
