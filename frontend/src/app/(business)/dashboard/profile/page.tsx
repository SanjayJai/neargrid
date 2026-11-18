'use client';

import { FormEvent, useState } from 'react';
import { DEMO_BUSINESSES, DEMO_CATEGORIES, DEMO_CITIES } from '@/shared/data/demo-directory';
import { DemoBanner } from '@/shared/ui/DemoBanner';

export default function BusinessProfileManagementPage() {
  const initial = DEMO_BUSINESSES[0];
  const [name, setName] = useState(initial.name);
  const [tagline, setTagline] = useState(initial.tagline);
  const [category, setCategory] = useState(initial.categorySlug);
  const [city, setCity] = useState(initial.citySlug);
  const [locality, setLocality] = useState(initial.localityName);
  const [address, setAddress] = useState(initial.address);
  const [pincode, setPincode] = useState(initial.pincode);
  const [description, setDescription] = useState(initial.description);
  const [savedNotice, setSavedNotice] = useState(false);

  function handleSave(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSavedNotice(true);
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Business Profile</h1>
        <p className="mt-1 text-sm text-zinc-600">
          Manage your public storefront details, category taxonomy, and verified address.
        </p>
      </div>

      <DemoBanner description="Edits here update local browser preview state only. Persistent Laravel Directory API endpoints (PUT /api/v1/owner/businesses/{id}) will be implemented in Phase 3B/3C." />

      {savedNotice && (
        <div role="status" className="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-800">
          Preview state updated locally (not persisted to backend yet).
        </div>
      )}

      <form onSubmit={handleSave} className="rounded-2xl border border-zinc-200 bg-white p-6">
        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <label htmlFor="biz-name" className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-zinc-600">
              Business Trading Name
            </label>
            <input
              id="biz-name"
              type="text"
              required
              value={name}
              onChange={(e) => { setName(e.target.value); setSavedNotice(false); }}
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2.5 text-sm text-zinc-950"
            />
          </div>

          <div className="sm:col-span-2">
            <label htmlFor="biz-tagline" className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-zinc-600">
              Tagline
            </label>
            <input
              id="biz-tagline"
              type="text"
              value={tagline}
              onChange={(e) => { setTagline(e.target.value); setSavedNotice(false); }}
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2.5 text-sm text-zinc-950"
            />
          </div>

          <div>
            <label htmlFor="biz-cat" className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-zinc-600">
              Primary Category
            </label>
            <select
              id="biz-cat"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-950"
            >
              {DEMO_CATEGORIES.map((c) => (
                <option key={c.slug} value={c.slug}>{c.name} ({c.nameTa})</option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="biz-city" className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-zinc-600">
              City
            </label>
            <select
              id="biz-city"
              value={city}
              onChange={(e) => setCity(e.target.value)}
              className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-950"
            >
              {DEMO_CITIES.map((c) => (
                <option key={c.slug} value={c.slug}>{c.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="biz-loc" className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-zinc-600">
              Locality
            </label>
            <input
              id="biz-loc"
              type="text"
              value={locality}
              onChange={(e) => setLocality(e.target.value)}
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2.5 text-sm text-zinc-950"
            />
          </div>

          <div>
            <label htmlFor="biz-pin" className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-zinc-600">
              Pincode
            </label>
            <input
              id="biz-pin"
              type="text"
              value={pincode}
              onChange={(e) => setPincode(e.target.value)}
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2.5 text-sm text-zinc-950"
            />
          </div>

          <div className="sm:col-span-2">
            <label htmlFor="biz-addr" className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-zinc-600">
              Street Address
            </label>
            <input
              id="biz-addr"
              type="text"
              value={address}
              onChange={(e) => setAddress(e.target.value)}
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2.5 text-sm text-zinc-950"
            />
          </div>

          <div className="sm:col-span-2">
            <label htmlFor="biz-desc" className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-zinc-600">
              About Business
            </label>
            <textarea
              id="biz-desc"
              rows={4}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2.5 text-sm text-zinc-950"
            />
          </div>
        </div>

        <div className="mt-6 flex justify-end border-t border-zinc-100 pt-4">
          <button
            type="submit"
            className="rounded-xl bg-zinc-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800"
          >
            Save Preview Changes
          </button>
        </div>
      </form>
    </div>
  );
}
