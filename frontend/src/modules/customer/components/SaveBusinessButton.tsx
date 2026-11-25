'use client';

import { useState } from 'react';
import { Bookmark, Loader2 } from 'lucide-react';
import { useSavedBusinesses } from '@/modules/customer/context/SavedBusinessesContext';
import { isSafeInternalNavigationUrl } from '@/modules/customer/lib/safe-navigation';

export interface SaveBusinessButtonProps {
  businessPublicId: string;
  businessName?: string;
  initialSaved?: boolean;
  variant?: 'pill' | 'icon';
  size?: 'sm' | 'md';
  onSavedChange?: (saved: boolean) => void;
  className?: string;
}

export function buildSafeLoginRedirectUrl(): string {
  let nextPath = '/';
  if (typeof window !== 'undefined' && window.location) {
    const candidate = `${window.location.pathname}${window.location.search}`;
    if (isSafeInternalNavigationUrl(candidate)) {
      nextPath = candidate;
    }
  }
  return `/login?next=${encodeURIComponent(nextPath)}`;
}

/**
 * Accessible, reusable Save / Bookmark button with optimistic UI, rollback on error,
 * 500-ID cap awareness, and safe login redirect for unauthenticated visitors.
 */
export function SaveBusinessButton({
  businessPublicId,
  businessName,
  initialSaved,
  variant = 'pill',
  size = 'md',
  onSavedChange,
  className = '',
}: SaveBusinessButtonProps) {
  const { isAuthenticated, getSaveState, isPending, toggleSave } = useSavedBusinesses();
  const [announcement, setAnnouncement] = useState<string | null>(null);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  const saveState = getSaveState(businessPublicId, initialSaved);
  const pending = isPending(businessPublicId);
  const isSaved = saveState === 'saved';
  const isUnknown = saveState === 'unknown';
  const labelTarget = businessName ? businessName : 'business';

  const ariaLabel = isSaved
    ? `Remove ${labelTarget} from saved`
    : `Save ${labelTarget}`;

  const ariaPressed: boolean | undefined =
    saveState === 'saved'
      ? true
      : saveState === 'unsaved'
        ? false
        : undefined;

  async function handleClick() {
    setErrorMsg(null);

    if (!isAuthenticated) {
      const loginUrl = buildSafeLoginRedirectUrl();
      if (typeof window !== 'undefined') {
        window.location.assign(loginUrl);
      }
      return;
    }

    const result = await toggleSave(businessPublicId, initialSaved);
    if (result.ok) {
      setAnnouncement(
        result.saved
          ? `Saved ${labelTarget}`
          : `Removed ${labelTarget} from saved`
      );
      onSavedChange?.(result.saved);
    } else if (result.error && result.error !== 'IN_FLIGHT') {
      setErrorMsg(result.error);
      setAnnouncement(`Could not update saved status for ${labelTarget}.`);
    }
  }

  const sizeClasses =
    size === 'sm'
      ? variant === 'icon'
        ? 'p-1.5 text-xs'
        : 'px-2.5 py-1 text-xs'
      : variant === 'icon'
        ? 'p-2 text-xs'
        : 'px-3 py-1.5 text-xs';

  const stateClasses = isSaved
    ? 'border-brand-300 bg-brand-50 text-brand-700 hover:bg-brand-100'
    : 'border-gray-300 bg-white text-ink hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700';

  return (
    <div className="inline-flex flex-col items-end">
      <button
        type="button"
        onClick={handleClick}
        disabled={pending || saveState === 'loading'}
        aria-pressed={ariaPressed}
        aria-label={ariaLabel}
        aria-busy={pending}
        data-testid={`save-business-btn-${businessPublicId}`}
        data-saved-state={saveState}
        title={
          isUnknown
            ? 'Save this business (manage all bookmarks in Saved Businesses)'
            : ariaLabel
        }
        className={`inline-flex shrink-0 items-center gap-1.5 rounded-lg border font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 disabled:cursor-not-allowed disabled:opacity-60 ${sizeClasses} ${stateClasses} ${className}`}
      >
        {pending ? (
          <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
        ) : (
          <Bookmark
            className={`h-3.5 w-3.5 ${isSaved ? 'fill-current text-brand-600' : ''}`}
            aria-hidden="true"
          />
        )}
        {variant === 'pill' && (
          <span>{isSaved ? 'Saved' : 'Save'}</span>
        )}
      </button>

      {errorMsg && (
        <span role="alert" className="mt-1 text-[11px] font-medium text-rose-700">
          {errorMsg}
        </span>
      )}

      {announcement && (
        <span role="status" aria-live="polite" className="sr-only">
          {announcement}
        </span>
      )}
    </div>
  );
}
