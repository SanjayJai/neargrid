'use client';

import { FormEvent, useEffect, useState } from 'react';
import { Building2, Loader2, Plus, X } from 'lucide-react';
import { bffFetch } from '@/shared/api/bff-client';
import type {
  OwnerBusinessProfileDto,
  ReferenceCategoryDto,
  ReferenceCityDto,
  ReferenceLocalityDto,
} from '@/shared/api/directory-types';

interface CreateBusinessModalProps {
  isOpen: boolean;
  onClose: () => void;
  onCreated: (business: OwnerBusinessProfileDto) => void;
}

export function CreateBusinessModal({
  isOpen,
  onClose,
  onCreated,
}: CreateBusinessModalProps) {
  const [name, setName] = useState('');
  const [tagline, setTagline] = useState('');
  const [primaryCategorySlug, setPrimaryCategorySlug] = useState('');
  const [citySlug, setCitySlug] = useState('chennai');
  const [localitySlug, setLocalitySlug] = useState('');
  const [addressLine1, setAddressLine1] = useState('');
  const [pincode, setPincode] = useState('');
  const [primaryPhone, setPrimaryPhone] = useState('+91');
  const [description, setDescription] = useState('');

  const [cities, setCities] = useState<ReferenceCityDto[]>([]);
  const [localities, setLocalities] = useState<ReferenceLocalityDto[]>([]);
  const [categories, setCategories] = useState<ReferenceCategoryDto[]>([]);
  const [loadingRef, setLoadingRef] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Load cities and categories on open
  useEffect(() => {
    if (!isOpen) return;

    let cancelled = false;
    async function loadTaxonomy() {
      setLoadingRef(true);
      try {
        const [citiesRes, catRes] = await Promise.all([
          fetch('/api/public/locations/cities').then((r) => r.json()),
          fetch('/api/public/categories').then((r) => r.json()),
        ]);

        if (!cancelled) {
          if (citiesRes?.data) setCities(citiesRes.data);
          if (catRes?.data) {
            setCategories(catRes.data);
            if (catRes.data[0]?.slug && !primaryCategorySlug) {
              setPrimaryCategorySlug(catRes.data[0].slug);
            }
          }
        }
      } catch {
        // Fallback gracefully
      } finally {
        if (!cancelled) setLoadingRef(false);
      }
    }

    loadTaxonomy();
    return () => {
      cancelled = true;
    };
  }, [isOpen, primaryCategorySlug]);

  // Load localities when citySlug changes
  useEffect(() => {
    if (!isOpen || !citySlug) return;

    let cancelled = false;
    async function loadLocalities() {
      try {
        const res = await fetch(`/api/public/locations/cities/${encodeURIComponent(citySlug)}/localities`).then((r) => r.json());
        if (!cancelled && res?.data) {
          setLocalities(res.data);
          if (res.data[0]?.slug) {
            setLocalitySlug(res.data[0].slug);
            if (res.data[0].pincode && !pincode) {
              setPincode(res.data[0].pincode);
            }
          }
        }
      } catch {
        // Fallback
      }
    }

    loadLocalities();
    return () => {
      cancelled = true;
    };
  }, [isOpen, citySlug, pincode]);

  if (!isOpen) return null;

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setErrorMessage(null);

    let formattedPhone = primaryPhone.trim();
    if (!formattedPhone.startsWith('+')) {
      formattedPhone = `+91${formattedPhone.replace(/^0+/, '')}`;
    }

    setSubmitting(true);
    try {
      const res = await bffFetch<OwnerBusinessProfileDto>('/api/owner/businesses', {
        method: 'POST',
        body: {
          name: name.trim(),
          tagline: tagline.trim() || null,
          primary_category_slug: primaryCategorySlug,
          city_slug: citySlug,
          locality_slug: localitySlug,
          address_line_1: addressLine1.trim(),
          pincode: pincode.trim(),
          primary_phone_e164: formattedPhone,
          description: description.trim() || null,
        },
      });

      if (res.ok && res.payload?.status === 'success') {
        onCreated(res.payload.data);
        onClose();
        return;
      }

      const err = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to create business listing.';
      setErrorMessage(err);
    } catch {
      setErrorMessage('Network error while creating listing.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="create-biz-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="relative max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
        <button
          type="button"
          onClick={onClose}
          aria-label="Close dialog"
          className="absolute right-4 top-4 rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700"
        >
          <X className="h-5 w-5" aria-hidden="true" />
        </button>

        <div className="mb-4 pr-6">
          <span className="inline-flex items-center gap-1 rounded-full border border-brand-200 bg-brand-50 px-2.5 py-0.5 text-[11px] font-semibold text-brand-700">
            <Building2 className="h-3 w-3" aria-hidden="true" />
            New Merchant Listing
          </span>
          <h2 id="create-biz-title" className="mt-2 text-lg font-bold text-zinc-950">
            List Your Business on NearGrid
          </h2>
          <p className="mt-1 text-xs text-zinc-600">
            Create a verified local business profile to start receiving discovery traffic.
          </p>
        </div>

        {errorMessage && (
          <div
            role="alert"
            className="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900"
          >
            {errorMessage}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label htmlFor="cb-name" className="mb-1 block text-xs font-semibold text-zinc-900">
              Business Trading Name <span className="text-signal-600">*</span>
            </label>
            <input
              id="cb-name"
              type="text"
              required
              minLength={2}
              maxLength={180}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Apex Dental Studio"
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
            />
          </div>

          <div>
            <label htmlFor="cb-tagline" className="mb-1 block text-xs font-semibold text-zinc-900">
              Tagline <span className="text-zinc-400 font-normal">(Optional)</span>
            </label>
            <input
              id="cb-tagline"
              type="text"
              maxLength={200}
              value={tagline}
              onChange={(e) => setTagline(e.target.value)}
              placeholder="e.g. Modern family dental clinic in T. Nagar"
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
            />
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label htmlFor="cb-category" className="mb-1 block text-xs font-semibold text-zinc-900">
                Primary Category <span className="text-signal-600">*</span>
              </label>
              <select
                id="cb-category"
                required
                value={primaryCategorySlug}
                onChange={(e) => setPrimaryCategorySlug(e.target.value)}
                className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
              >
                {categories.length === 0 ? (
                  <option value="">Loading categories...</option>
                ) : (
                  categories.map((c) => (
                    <option key={c.slug} value={c.slug}>
                      {c.name}
                    </option>
                  ))
                )}
              </select>
            </div>

            <div>
              <label htmlFor="cb-phone" className="mb-1 block text-xs font-semibold text-zinc-900">
                Primary Phone <span className="text-signal-600">*</span>
              </label>
              <input
                id="cb-phone"
                type="tel"
                required
                value={primaryPhone}
                onChange={(e) => setPrimaryPhone(e.target.value)}
                placeholder="+919840123456"
                className="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label htmlFor="cb-city" className="mb-1 block text-xs font-semibold text-zinc-900">
                City <span className="text-signal-600">*</span>
              </label>
              <select
                id="cb-city"
                required
                value={citySlug}
                onChange={(e) => setCitySlug(e.target.value)}
                className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
              >
                {cities.map((city) => (
                  <option key={city.slug} value={city.slug}>
                    {city.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label htmlFor="cb-locality" className="mb-1 block text-xs font-semibold text-zinc-900">
                Locality <span className="text-signal-600">*</span>
              </label>
              <select
                id="cb-locality"
                required
                value={localitySlug}
                onChange={(e) => setLocalitySlug(e.target.value)}
                className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
              >
                {localities.length === 0 ? (
                  <option value="">No localities found</option>
                ) : (
                  localities.map((loc) => (
                    <option key={loc.slug} value={loc.slug}>
                      {loc.name} {loc.pincode ? `(${loc.pincode})` : ''}
                    </option>
                  ))
                )}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div className="sm:col-span-2">
              <label htmlFor="cb-address" className="mb-1 block text-xs font-semibold text-zinc-900">
                Street Address <span className="text-signal-600">*</span>
              </label>
              <input
                id="cb-address"
                type="text"
                required
                minLength={4}
                maxLength={255}
                value={addressLine1}
                onChange={(e) => setAddressLine1(e.target.value)}
                placeholder="e.g. 14 Usman Road, 2nd Floor"
                className="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
              />
            </div>

            <div>
              <label htmlFor="cb-pincode" className="mb-1 block text-xs font-semibold text-zinc-900">
                Pincode <span className="text-signal-600">*</span>
              </label>
              <input
                id="cb-pincode"
                type="text"
                required
                minLength={4}
                maxLength={12}
                value={pincode}
                onChange={(e) => setPincode(e.target.value)}
                placeholder="600017"
                className="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
              />
            </div>
          </div>

          <div>
            <label htmlFor="cb-description" className="mb-1 block text-xs font-semibold text-zinc-900">
              Description <span className="text-zinc-400 font-normal">(Optional)</span>
            </label>
            <textarea
              id="cb-description"
              rows={3}
              maxLength={5000}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Tell customers about your services, specializations, and background..."
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
            />
          </div>

          <div className="flex items-center justify-end gap-2.5 border-t border-zinc-100 pt-3">
            <button
              type="button"
              onClick={onClose}
              disabled={submitting}
              className="rounded-xl border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={submitting || loadingRef}
              className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
            >
              {submitting ? (
                <>
                  <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                  <span>Creating Listing...</span>
                </>
              ) : (
                <>
                  <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                  <span>Create Listing</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
