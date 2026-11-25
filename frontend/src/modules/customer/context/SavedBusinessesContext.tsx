'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import {
  fetchSavedBusinessIds,
  saveBusiness,
  unsaveBusiness,
} from '@/modules/customer/lib/saved-businesses-api';

export const SAVED_IDS_CAP = 500;

export type BusinessSaveState = 'saved' | 'unsaved' | 'unknown' | 'loading';

export interface ToggleSaveResult {
  ok: boolean;
  saved: boolean;
  error?: string;
}

export interface SavedBusinessesContextValue {
  isAuthenticated: boolean;
  status: 'idle' | 'loading' | 'loaded' | 'error';
  savedCount: number;
  isAtCap: boolean;
  getSaveState: (publicId: string, initialSaved?: boolean) => BusinessSaveState;
  isPending: (publicId: string) => boolean;
  toggleSave: (publicId: string, initialSaved?: boolean) => Promise<ToggleSaveResult>;
  refreshIds: () => Promise<void>;
}

const DEFAULT_UNAUTHENTICATED_CONTEXT: SavedBusinessesContextValue = {
  isAuthenticated: false,
  status: 'idle',
  savedCount: 0,
  isAtCap: false,
  getSaveState: () => 'unsaved',
  isPending: () => false,
  toggleSave: async () => ({ ok: false, saved: false, error: 'UNAUTHENTICATED' }),
  refreshIds: async () => {},
};

const SavedBusinessesContext = createContext<SavedBusinessesContextValue>(
  DEFAULT_UNAUTHENTICATED_CONTEXT
);

interface SavedBusinessesProviderProps {
  isAuthenticated: boolean;
  initialIds?: string[];
  children?: ReactNode;
}

export function SavedBusinessesProvider({
  isAuthenticated,
  initialIds,
  children,
}: SavedBusinessesProviderProps) {
  const [status, setStatus] = useState<'idle' | 'loading' | 'loaded' | 'error'>(
    initialIds ? 'loaded' : isAuthenticated ? 'loading' : 'idle'
  );
  const [savedIds, setSavedIds] = useState<Set<string>>(() => new Set(initialIds ?? []));
  const [unsavedOverrides, setUnsavedOverrides] = useState<Set<string>>(() => new Set());
  const [isAtCap, setIsAtCap] = useState<boolean>(
    () => (initialIds?.length ?? 0) >= SAVED_IDS_CAP
  );
  const [pendingIds, setPendingIds] = useState<Set<string>>(() => new Set());

  const savedIdsRef = useRef(savedIds);
  savedIdsRef.current = savedIds;
  const unsavedOverridesRef = useRef(unsavedOverrides);
  unsavedOverridesRef.current = unsavedOverrides;
  const pendingIdsRef = useRef(pendingIds);
  pendingIdsRef.current = pendingIds;

  const refreshIds = useCallback(async () => {
    if (!isAuthenticated) {
      setStatus('idle');
      setSavedIds(new Set());
      setUnsavedOverrides(new Set());
      setIsAtCap(false);
      return;
    }

    setStatus('loading');
    try {
      const res = await fetchSavedBusinessIds();
      if (res.ok && res.payload?.status === 'success') {
        const ids = res.payload.data.saved_ids ?? [];
        setSavedIds(new Set(ids));
        setUnsavedOverrides(new Set());
        setIsAtCap(ids.length >= SAVED_IDS_CAP);
        setStatus('loaded');
      } else {
        setStatus('error');
      }
    } catch {
      setStatus('error');
    }
  }, [isAuthenticated]);

  useEffect(() => {
    if (isAuthenticated && initialIds === undefined) {
      void refreshIds();
    } else if (!isAuthenticated) {
      setStatus('idle');
      setSavedIds(new Set());
      setUnsavedOverrides(new Set());
      setIsAtCap(false);
    }
  }, [isAuthenticated, initialIds, refreshIds]);

  const getSaveState = useCallback(
    (publicId: string, initialSaved?: boolean): BusinessSaveState => {
      if (!isAuthenticated) {
        return 'unsaved';
      }
      if (savedIds.has(publicId)) {
        return 'saved';
      }
      if (unsavedOverrides.has(publicId)) {
        return 'unsaved';
      }
      if (initialSaved === true) {
        return 'saved';
      }
      if (status === 'loading') {
        return 'loading';
      }
      if (isAtCap) {
        return 'unknown';
      }
      return 'unsaved';
    },
    [isAuthenticated, savedIds, unsavedOverrides, status, isAtCap]
  );

  const isPending = useCallback(
    (publicId: string) => pendingIds.has(publicId),
    [pendingIds]
  );

  const toggleSave = useCallback(
    async (publicId: string, initialSaved?: boolean): Promise<ToggleSaveResult> => {
      if (!isAuthenticated) {
        return { ok: false, saved: false, error: 'UNAUTHENTICATED' };
      }

      if (pendingIdsRef.current.has(publicId)) {
        const current =
          savedIdsRef.current.has(publicId) ||
          (!unsavedOverridesRef.current.has(publicId) && initialSaved === true);
        return { ok: false, saved: current, error: 'IN_FLIGHT' };
      }

      const prevSavedIds = new Set(savedIdsRef.current);
      const prevUnsavedOverrides = new Set(unsavedOverridesRef.current);

      const currentlySaved =
        prevSavedIds.has(publicId) ||
        (!prevUnsavedOverrides.has(publicId) && initialSaved === true);
      const targetSaved = !currentlySaved;

      setPendingIds((prev) => {
        const next = new Set(prev);
        next.add(publicId);
        return next;
      });

      // Optimistic update
      if (targetSaved) {
        setSavedIds((prev) => {
          const next = new Set(prev);
          next.add(publicId);
          return next;
        });
        setUnsavedOverrides((prev) => {
          if (!prev.has(publicId)) return prev;
          const next = new Set(prev);
          next.delete(publicId);
          return next;
        });
      } else {
        setSavedIds((prev) => {
          if (!prev.has(publicId)) return prev;
          const next = new Set(prev);
          next.delete(publicId);
          return next;
        });
        setUnsavedOverrides((prev) => {
          const next = new Set(prev);
          next.add(publicId);
          return next;
        });
      }

      try {
        const res = targetSaved
          ? await saveBusiness(publicId)
          : await unsaveBusiness(publicId);

        if (!res.ok) {
          // Rollback optimistic update on HTTP failure
          setSavedIds(prevSavedIds);
          setUnsavedOverrides(prevUnsavedOverrides);
          const errorMessage =
            res.payload?.status === 'error'
              ? res.payload.error.message
              : 'Unable to update saved status. Please try again.';
          return { ok: false, saved: currentlySaved, error: errorMessage };
        }

        return { ok: true, saved: targetSaved };
      } catch {
        // Rollback optimistic update on network failure
        setSavedIds(prevSavedIds);
        setUnsavedOverrides(prevUnsavedOverrides);
        return {
          ok: false,
          saved: currentlySaved,
          error: 'Network error while updating saved status.',
        };
      } finally {
        setPendingIds((prev) => {
          const next = new Set(prev);
          next.delete(publicId);
          return next;
        });
      }
    },
    [isAuthenticated]
  );

  const value = useMemo<SavedBusinessesContextValue>(
    () => ({
      isAuthenticated,
      status,
      savedCount: savedIds.size,
      isAtCap,
      getSaveState,
      isPending,
      toggleSave,
      refreshIds,
    }),
    [isAuthenticated, status, savedIds.size, isAtCap, getSaveState, isPending, toggleSave, refreshIds]
  );

  return (
    <SavedBusinessesContext.Provider value={value}>
      {children}
    </SavedBusinessesContext.Provider>
  );
}

export function useSavedBusinesses(): SavedBusinessesContextValue {
  return useContext(SavedBusinessesContext);
}
