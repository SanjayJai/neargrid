'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from 'react';
import { bffFetch } from '@/shared/api/bff-client';
import type { OwnerBusinessProfileDto } from '@/shared/api/directory-types';

export interface OwnerBusinessContextValue {
  businesses: OwnerBusinessProfileDto[];
  activeBusiness: OwnerBusinessProfileDto | null;
  activeBusinessId: string | null;
  loading: boolean;
  error: string | null;
  setActiveBusinessId: (id: string) => void;
  refreshBusinesses: () => Promise<void>;
  updateActiveBusiness: (updated: OwnerBusinessProfileDto) => void;
}

const OwnerBusinessContext = createContext<OwnerBusinessContextValue | null>(null);

export function OwnerBusinessProvider({
  children,
  initialBusinesses = [],
}: {
  children: ReactNode;
  initialBusinesses?: OwnerBusinessProfileDto[];
}) {
  const [businesses, setBusinesses] = useState<OwnerBusinessProfileDto[]>(initialBusinesses);
  const [activeBusinessId, setActiveBusinessIdState] = useState<string | null>(
    initialBusinesses[0]?.public_id ?? null
  );
  const [loading, setLoading] = useState(initialBusinesses.length === 0);
  const [error, setError] = useState<string | null>(null);

  const refreshBusinesses = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await bffFetch<OwnerBusinessProfileDto[]>('/api/owner/businesses');
      if (res.ok && res.payload?.status === 'success') {
        const list = res.payload.data;
        setBusinesses(list);

        setActiveBusinessIdState((prev) => {
          if (prev && list.some((b: OwnerBusinessProfileDto) => b.public_id === prev)) {
            return prev;
          }
          return list[0]?.public_id ?? null;
        });
      } else if (res.status === 401) {
        setBusinesses([]);
        setActiveBusinessIdState(null);
      } else {
        const errMsg =
          res.payload?.status === 'error'
            ? res.payload.error.message
            : 'Unable to load your business listings.';
        setError(errMsg);
      }
    } catch {
      setError('Network error while connecting to merchant workspace.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refreshBusinesses();
  }, [refreshBusinesses]);

  const setActiveBusinessId = useCallback((id: string) => {
    setActiveBusinessIdState(id);
  }, []);

  const updateActiveBusiness = useCallback((updated: OwnerBusinessProfileDto) => {
    setBusinesses((prev) =>
      prev.map((b) => (b.public_id === updated.public_id ? updated : b))
    );
  }, []);

  const activeBusiness =
    businesses.find((b) => b.public_id === activeBusinessId) ?? businesses[0] ?? null;

  return (
    <OwnerBusinessContext.Provider
      value={{
        businesses,
        activeBusiness,
        activeBusinessId,
        loading,
        error,
        setActiveBusinessId,
        refreshBusinesses,
        updateActiveBusiness,
      }}
    >
      {children}
    </OwnerBusinessContext.Provider>
  );
}

export function useOwnerBusiness() {
  const ctx = useContext(OwnerBusinessContext);
  if (!ctx) {
    throw new Error('useOwnerBusiness must be used within an OwnerBusinessProvider');
  }
  return ctx;
}

