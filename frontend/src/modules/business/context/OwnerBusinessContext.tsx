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
import type {
  OwnerBusinessProfileDto,
  OwnerOrganizationBranchDto,
  OwnerOrganizationDto,
} from '@/shared/api/directory-types';

export interface OwnerBusinessContextValue {
  businesses: OwnerBusinessProfileDto[];
  activeBusiness: OwnerBusinessProfileDto | null;
  activeBusinessId: string | null;
  organizations: OwnerOrganizationDto[];
  activeOrganization: OwnerOrganizationDto | null;
  activeOrganizationId: string | null;
  orgBranches: OwnerOrganizationBranchDto[];
  loading: boolean;
  branchSwitching: boolean;
  error: string | null;
  branchSwitchError: string | null;
  setActiveBusinessId: (id: string) => void;
  selectOrganization: (orgPublicId: string) => Promise<void>;
  selectBranch: (businessPublicId: string, orgPublicId?: string | null) => Promise<boolean>;
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
  const [organizations, setOrganizations] = useState<OwnerOrganizationDto[]>([]);
  const [activeOrganizationId, setActiveOrganizationId] = useState<string | null>(null);
  const [orgBranches, setOrgBranches] = useState<OwnerOrganizationBranchDto[]>([]);
  const [loading, setLoading] = useState(initialBusinesses.length === 0);
  const [branchSwitching, setBranchSwitching] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [branchSwitchError, setBranchSwitchError] = useState<string | null>(null);

  const loadBranchesForOrg = useCallback(async (orgPublicId: string) => {
    try {
      const res = await bffFetch<OwnerOrganizationBranchDto[]>(
        `/api/owner/organizations/${encodeURIComponent(orgPublicId)}/branches`
      );
      if (res.ok && res.payload?.status === 'success') {
        setOrgBranches(res.payload.data);
      } else {
        setOrgBranches([]);
      }
    } catch {
      setOrgBranches([]);
    }
  }, []);

  const refreshBusinesses = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [bizRes, orgRes] = await Promise.all([
        bffFetch<OwnerBusinessProfileDto[]>('/api/owner/businesses'),
        bffFetch<OwnerOrganizationDto[]>('/api/owner/organizations').catch(() => null),
      ]);

      if (bizRes.ok && bizRes.payload?.status === 'success') {
        const list = bizRes.payload.data;
        setBusinesses(list);

        setActiveBusinessIdState((prev) => {
          if (prev && list.some((b: OwnerBusinessProfileDto) => b.public_id === prev)) {
            return prev;
          }
          return list[0]?.public_id ?? null;
        });
      } else if (bizRes.status === 401) {
        setBusinesses([]);
        setActiveBusinessIdState(null);
      } else {
        const errMsg =
          bizRes.payload?.status === 'error'
            ? bizRes.payload.error.message
            : 'Unable to load your business listings.';
        setError(errMsg);
      }

      if (orgRes && orgRes.ok && orgRes.payload?.status === 'success') {
        const orgList = orgRes.payload.data;
        setOrganizations(orgList);
        const firstOrgId = orgList[0]?.public_id ?? null;
        setActiveOrganizationId((prev) =>
          prev && orgList.some((o) => o.public_id === prev) ? prev : firstOrgId
        );
        const targetOrg =
          orgList.find((o) => o.public_id === firstOrgId)?.public_id ?? null;
        if (targetOrg) {
          await loadBranchesForOrg(targetOrg);
        } else {
          setOrgBranches([]);
        }
      }
    } catch {
      setError('Network error while connecting to merchant workspace.');
    } finally {
      setLoading(false);
    }
  }, [loadBranchesForOrg]);

  useEffect(() => {
    refreshBusinesses();
  }, [refreshBusinesses]);

  const selectOrganization = useCallback(
    async (orgPublicId: string) => {
      setBranchSwitchError(null);
      setActiveOrganizationId(orgPublicId);
      setOrgBranches([]);
      await loadBranchesForOrg(orgPublicId);
    },
    [loadBranchesForOrg]
  );

  /**
   * Authoritatively validates branch access with Laravel via BFF before switching activeBusinessId.
   * Prevents stale data or unauthorized client-side ID switching.
   */
  const selectBranch = useCallback(
    async (businessPublicId: string, orgPublicId?: string | null): Promise<boolean> => {
      setBranchSwitching(true);
      setBranchSwitchError(null);

      try {
        const res = await bffFetch<OwnerBusinessProfileDto>(
          `/api/owner/businesses/${encodeURIComponent(businessPublicId)}`
        );

        if (!res.ok || res.payload?.status !== 'success') {
          const msg =
            res.payload?.status === 'error'
              ? res.payload.error.message
              : 'You are not authorized to access the selected branch.';
          setBranchSwitchError(msg);
          return false;
        }

        const verifiedBusiness = res.payload.data;
        setBusinesses((prev) => {
          const exists = prev.some((b) => b.public_id === verifiedBusiness.public_id);
          return exists
            ? prev.map((b) => (b.public_id === verifiedBusiness.public_id ? verifiedBusiness : b))
            : [verifiedBusiness, ...prev];
        });
        setActiveBusinessIdState(verifiedBusiness.public_id);

        if (orgPublicId) {
          setActiveOrganizationId(orgPublicId);
        }

        return true;
      } catch {
        setBranchSwitchError('Network error while verifying branch permissions.');
        return false;
      } finally {
        setBranchSwitching(false);
      }
    },
    []
  );

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

  const activeOrganization =
    organizations.find((o) => o.public_id === activeOrganizationId) ?? null;

  return (
    <OwnerBusinessContext.Provider
      value={{
        businesses,
        activeBusiness,
        activeBusinessId: activeBusiness?.public_id ?? null,
        organizations,
        activeOrganization,
        activeOrganizationId: activeOrganization?.public_id ?? null,
        orgBranches,
        loading,
        branchSwitching,
        error,
        branchSwitchError,
        setActiveBusinessId,
        selectOrganization,
        selectBranch,
        refreshBusinesses,
        updateActiveBusiness,
      }}
    >
      {children}
    </OwnerBusinessContext.Provider>
  );
}

export function useOwnerBusiness(): OwnerBusinessContextValue {
  const ctx = useContext(OwnerBusinessContext);
  if (!ctx) {
    throw new Error('useOwnerBusiness must be used within OwnerBusinessProvider');
  }
  return ctx;
}
