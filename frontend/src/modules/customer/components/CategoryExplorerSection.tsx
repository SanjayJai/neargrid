'use client';

import { useMemo, useState, type KeyboardEvent } from 'react';
import Link from 'next/link';
import {
  ArrowLeft,
  ArrowRight,
  ChevronDown,
  ChevronRight,
  Layers,
  Search,
  Sparkles,
  X,
} from 'lucide-react';
import type {
  CategoryCollectionDto,
  CategoryTreeNodeDto,
} from '@/shared/api/directory-types';
import { getCategoryVisual } from '@/modules/customer/lib/category-icons';
import type { SupportedLocale } from '@/modules/customer/lib/search-url';

export interface FlattenedCategoryMatch {
  node: CategoryTreeNodeDto;
  breadcrumb: string[];
}

export function flattenCategoryTree(
  nodes: CategoryTreeNodeDto[],
  ancestors: string[] = []
): FlattenedCategoryMatch[] {
  const results: FlattenedCategoryMatch[] = [];
  for (const node of nodes) {
    const trail = [...ancestors, node.name];
    results.push({ node, breadcrumb: trail });
    if (node.children && node.children.length > 0) {
      results.push(...flattenCategoryTree(node.children, trail));
    }
  }
  return results;
}

export function filterCategoryTree(
  nodes: CategoryTreeNodeDto[],
  query: string
): FlattenedCategoryMatch[] {
  const trimmed = query.trim().toLowerCase();
  if (!trimmed) return [];

  return flattenCategoryTree(nodes)
    .filter(
      ({ node }) =>
        node.name.toLowerCase().includes(trimmed) ||
        node.name_en.toLowerCase().includes(trimmed) ||
        node.slug.toLowerCase().includes(trimmed)
    )
    .slice(0, 24);
}

interface CategoryExplorerSectionProps {
  categories: CategoryTreeNodeDto[];
  collections: CategoryCollectionDto[];
  citySlug: string;
  cityLabel: string;
  locale: SupportedLocale;
}

export function CategoryExplorerSection({
  categories,
  collections,
  citySlug,
  cityLabel,
  locale,
}: CategoryExplorerSectionProps) {
  const [selectedMainId, setSelectedMainId] = useState<string>(
    categories[0]?.public_id ?? ''
  );
  const [searchQuery, setSearchQuery] = useState('');
  const [expandedGroupIds, setExpandedGroupIds] = useState<Record<string, boolean>>({});
  const [mobileStack, setMobileStack] = useState<CategoryTreeNodeDto[]>([]);

  const activeMain = useMemo(
    () =>
      categories.find((c) => c.public_id === selectedMainId) ??
      categories[0] ??
      null,
    [categories, selectedMainId]
  );

  const searchMatches = useMemo(
    () => filterCategoryTree(categories, searchQuery),
    [categories, searchQuery]
  );

  const activeCollections = useMemo(() => {
    if (!activeMain) return [];
    const childSlugs = new Set(
      flattenCategoryTree([activeMain]).map((m) => m.node.slug)
    );
    return collections.filter((col) =>
      col.category_slugs.some((slug) => childSlugs.has(slug))
    );
  }, [activeMain, collections]);

  function buildCategoryHref(path: string): string {
    const cleanPath = path.startsWith('/') ? path : `/${path}`;
    const qs = new URLSearchParams();
    if (citySlug) qs.set('city', citySlug);
    if (locale !== 'en') qs.set('locale', locale);
    const suffix = qs.toString();
    return suffix ? `${cleanPath}?${suffix}` : cleanPath;
  }

  function handleRailKeyDown(e: KeyboardEvent<HTMLButtonElement>, index: number) {
    if (categories.length === 0) return;
    let nextIndex: number | null = null;
    if (e.key === 'ArrowDown') {
      nextIndex = (index + 1) % categories.length;
    } else if (e.key === 'ArrowUp') {
      nextIndex = (index - 1 + categories.length) % categories.length;
    } else if (e.key === 'Home') {
      nextIndex = 0;
    } else if (e.key === 'End') {
      nextIndex = categories.length - 1;
    }

    if (nextIndex !== null) {
      e.preventDefault();
      const target = categories[nextIndex];
      if (target) {
        setSelectedMainId(target.public_id);
      }
    }
  }

  function toggleGroupExpand(publicId: string) {
    setExpandedGroupIds((prev) => ({ ...prev, [publicId]: !prev[publicId] }));
  }

  const mobileCurrentParent = mobileStack[mobileStack.length - 1] ?? null;
  const mobileItems = mobileCurrentParent ? mobileCurrentParent.children : categories;

  return (
    <section
      aria-labelledby="home-categories-heading"
      className="mx-auto mt-8 max-w-7xl px-4 sm:px-6 lg:px-8"
    >
      <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
        {/* Header + Live Explorer Search */}
        <div className="mb-5 flex flex-col justify-between gap-3 md:flex-row md:items-end">
          <div>
            <h2
              id="home-categories-heading"
              className="text-lg font-extrabold tracking-tight text-ink sm:text-xl"
            >
              {locale === 'ta' ? 'வகைகளை ஆராயுங்கள்' : 'Explore Categories'}
            </h2>
            <p className="mt-0.5 text-xs text-ink-muted">
              {locale === 'ta'
                ? `${cityLabel} நகரின் அனைத்து சேவைகளும் ஒரே இடத்தில்`
                : `Browse all categories & specialities in ${cityLabel}`}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <div className="relative w-full sm:w-72">
              <Search
                className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-ink-subtle"
                aria-hidden="true"
              />
              <input
                type="search"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                aria-label={
                  locale === 'ta' ? 'வகைகளைத் தேடுங்கள்' : 'Filter categories'
                }
                placeholder={
                  locale === 'ta'
                    ? 'வகை அல்லது சிறப்புப் பிரிவைத் தேடுங்கள்...'
                    : 'Search categories & specialities...'
                }
                className="h-9 w-full rounded-xl border border-gray-200 bg-gray-50 pl-9 pr-8 text-xs text-ink focus:border-brand-500 focus:bg-white focus:outline-none"
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => setSearchQuery('')}
                  aria-label="Clear category filter"
                  className="absolute right-2.5 top-2 text-ink-subtle hover:text-ink"
                >
                  <X className="h-4 w-4" aria-hidden="true" />
                </button>
              )}
            </div>

            <Link
              href={`/search?city=${encodeURIComponent(citySlug)}`}
              className="inline-flex shrink-0 items-center gap-1 text-xs font-bold text-brand-600 hover:text-brand-700"
            >
              {locale === 'ta' ? 'அனைத்தையும் பார்க்க' : 'Explore All'}
              <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
            </Link>
          </div>
        </div>

        {categories.length === 0 ? (
          <p className="py-6 text-center text-sm text-ink-muted">
            {locale === 'ta'
              ? 'தற்போது வகைகள் கிடைக்கவில்லை.'
              : 'Categories are currently unavailable.'}
          </p>
        ) : searchQuery.trim() !== '' ? (
          /* Live Search Results Mode */
          <div data-testid="category-explorer-search-results">
            {searchMatches.length === 0 ? (
              <p className="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm text-ink-muted">
                No categories matched &ldquo;{searchQuery.trim()}&rdquo;.
              </p>
            ) : (
              <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                {searchMatches.map(({ node, breadcrumb }) => (
                  <Link
                    key={node.public_id}
                    href={buildCategoryHref(node.path)}
                    className="flex items-center justify-between gap-2 rounded-xl border border-gray-200 bg-gray-50/70 px-3.5 py-2.5 transition-colors hover:border-brand-300 hover:bg-brand-50/50"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold text-ink">
                        {node.name}
                      </p>
                      <p className="truncate text-[11px] text-ink-muted">
                        {breadcrumb.join(' › ')}
                      </p>
                    </div>
                    <ChevronRight
                      className="h-4 w-4 shrink-0 text-ink-subtle"
                      aria-hidden="true"
                    />
                  </Link>
                ))}
              </div>
            )}
          </div>
        ) : (
          <>
            {/* Desktop & Tablet Dual-Panel Explorer */}
            <div className="hidden gap-6 md:grid md:grid-cols-12">
              {/* Left Rail: Main Categories */}
              <div
                role="tablist"
                aria-label="Main categories"
                aria-orientation="vertical"
                className="space-y-1 border-r border-gray-100 pr-4 md:col-span-4 lg:col-span-3"
              >
                {categories.map((cat, idx) => {
                  const isActive = activeMain?.public_id === cat.public_id;
                  const visual = getCategoryVisual(cat.icon_name ?? cat.slug);
                  const Icon = visual.icon;

                  return (
                    <button
                      key={cat.public_id}
                      id={`cat-tab-${cat.public_id}`}
                      type="button"
                      role="tab"
                      aria-selected={isActive}
                      aria-controls={`cat-panel-${cat.public_id}`}
                      tabIndex={isActive ? 0 : -1}
                      onClick={() => setSelectedMainId(cat.public_id)}
                      onKeyDown={(e) => handleRailKeyDown(e, idx)}
                      className={`flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2.5 text-left text-xs font-semibold transition-colors ${
                        isActive
                          ? 'bg-brand-50 text-brand-700 ring-1 ring-brand-200'
                          : 'text-ink hover:bg-gray-50'
                      }`}
                    >
                      <span className="flex min-w-0 items-center gap-2.5">
                        <Icon
                          className={`h-4 w-4 shrink-0 ${
                            isActive ? 'text-brand-600' : visual.textClass
                          }`}
                          aria-hidden="true"
                        />
                        <span className="truncate">{cat.name}</span>
                      </span>
                      <ChevronRight
                        className={`h-3.5 w-3.5 shrink-0 ${
                          isActive ? 'text-brand-600' : 'text-ink-subtle'
                        }`}
                        aria-hidden="true"
                      />
                    </button>
                  );
                })}
              </div>

              {/* Right Panel: Groups, Subcategories, Specialities & Collections */}
              {activeMain && (
                <div
                  id={`cat-panel-${activeMain.public_id}`}
                  role="tabpanel"
                  aria-labelledby={`cat-tab-${activeMain.public_id}`}
                  className="space-y-5 md:col-span-8 lg:col-span-9"
                >
                  <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3">
                    <div>
                      <h3 className="text-base font-bold text-ink">
                        {activeMain.name}
                      </h3>
                      <p className="text-xs text-ink-muted">
                        {activeMain.children.length} sub-branch
                        {activeMain.children.length === 1 ? '' : 'es'}
                      </p>
                    </div>
                    <Link
                      href={buildCategoryHref(activeMain.path)}
                      className="inline-flex items-center gap-1 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 hover:bg-brand-100"
                    >
                      <span>View all {activeMain.name}</span>
                      <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                    </Link>
                  </div>

                  {activeCollections.length > 0 && (
                    <div>
                      <p className="mb-2 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-ink-muted">
                        <Sparkles className="h-3.5 w-3.5 text-signal-500" aria-hidden="true" />
                        Curated Collections
                      </p>
                      <div className="flex flex-wrap gap-2">
                        {activeCollections.map((col) => (
                          <Link
                            key={col.public_id}
                            href={buildCategoryHref(col.path)}
                            className="rounded-full border border-signal-200 bg-signal-50 px-3 py-1 text-xs font-semibold text-signal-800 hover:bg-signal-100"
                          >
                            {col.name}
                          </Link>
                        ))}
                      </div>
                    </div>
                  )}

                  {activeMain.children.length === 0 ? (
                    <p className="text-sm text-ink-muted">
                      Explore verified {activeMain.name} listings in {cityLabel}.
                    </p>
                  ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                      {activeMain.children.map((child) => {
                        const isGroup = child.node_type === 'category_group';
                        const hasGrandchildren = child.children.length > 0;
                        const isExpanded = Boolean(expandedGroupIds[child.public_id]);
                        const visibleKids = isExpanded
                          ? child.children
                          : child.children.slice(0, 6);

                        return (
                          <div
                            key={child.public_id}
                            className="rounded-xl border border-gray-100 bg-gray-50/60 p-3.5"
                          >
                            <div className="flex items-center justify-between gap-2">
                              {isGroup && !child.has_landing_page ? (
                                <span className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-ink-muted">
                                  <Layers className="h-3.5 w-3.5 text-brand-600" aria-hidden="true" />
                                  {child.name}
                                </span>
                              ) : (
                                <Link
                                  href={buildCategoryHref(child.path)}
                                  className="text-sm font-bold text-ink hover:text-brand-700"
                                >
                                  {child.name}
                                </Link>
                              )}
                            </div>

                            {hasGrandchildren && (
                              <ul className="mt-2.5 space-y-1.5">
                                {visibleKids.map((grand) => (
                                  <li key={grand.public_id}>
                                    <Link
                                      href={buildCategoryHref(grand.path)}
                                      className="flex items-center justify-between text-xs text-ink-muted hover:text-brand-700"
                                    >
                                      <span className="truncate">{grand.name}</span>
                                      {grand.children.length > 0 && (
                                        <span className="ml-1 text-[10px] text-ink-subtle">
                                          +{grand.children.length}
                                        </span>
                                      )}
                                    </Link>
                                  </li>
                                ))}
                              </ul>
                            )}

                            {child.children.length > 6 && (
                              <button
                                type="button"
                                onClick={() => toggleGroupExpand(child.public_id)}
                                className="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-brand-600 hover:text-brand-700"
                              >
                                <span>
                                  {isExpanded
                                    ? 'Show fewer'
                                    : `+${child.children.length - 6} more`}
                                </span>
                                <ChevronDown
                                  className={`h-3 w-3 transition-transform ${
                                    isExpanded ? 'rotate-180' : ''
                                  }`}
                                  aria-hidden="true"
                                />
                              </button>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}
            </div>

            {/* Mobile (<768px) Drilldown */}
            <div className="space-y-3 md:hidden">
              {mobileCurrentParent && (
                <div className="flex items-center justify-between gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                  <button
                    type="button"
                    onClick={() => setMobileStack((prev) => prev.slice(0, -1))}
                    className="inline-flex items-center gap-1 text-xs font-bold text-ink"
                  >
                    <ArrowLeft className="h-3.5 w-3.5" aria-hidden="true" />
                    <span>Back</span>
                  </button>
                  <Link
                    href={buildCategoryHref(mobileCurrentParent.path)}
                    className="text-xs font-bold text-brand-600"
                  >
                    Browse {mobileCurrentParent.name} →
                  </Link>
                </div>
              )}

              <div className="grid grid-cols-1 gap-2">
                {mobileItems.map((item) => {
                  const hasKids = item.children.length > 0;
                  if (hasKids) {
                    return (
                      <button
                        key={item.public_id}
                        type="button"
                        onClick={() => setMobileStack((prev) => [...prev, item])}
                        className="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-left text-sm font-semibold text-ink hover:bg-gray-50"
                      >
                        <span>{item.name}</span>
                        <ChevronRight className="h-4 w-4 text-ink-subtle" aria-hidden="true" />
                      </button>
                    );
                  }
                  return (
                    <Link
                      key={item.public_id}
                      href={buildCategoryHref(item.path)}
                      className="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm font-semibold text-ink hover:border-brand-300"
                    >
                      <span>{item.name}</span>
                      <ArrowRight className="h-4 w-4 text-brand-600" aria-hidden="true" />
                    </Link>
                  );
                })}
              </div>
            </div>
          </>
        )}
      </div>
    </section>
  );
}
