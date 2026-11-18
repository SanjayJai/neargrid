'use client';

import { FormEvent, useState } from 'react';
import { ImagePlus, Plus, Trash2 } from 'lucide-react';
import { DEMO_BUSINESSES, type DemoBusinessService } from '@/shared/data/demo-directory';
import { DemoBanner } from '@/shared/ui/DemoBanner';

const DEMO_PHOTO_SLOTS = [
  { id: 'ph-1', category: 'Cover', label: 'Storefront Exterior (Demo)' },
  { id: 'ph-2', category: 'Interior', label: 'Reception & Waiting Area (Demo)' },
  { id: 'ph-3', category: 'Equipment', label: 'Diagnostic Suite (Demo)' },
  { id: 'ph-4', category: 'Certificate', label: 'Sanitation & Safety Notice (Demo)' },
];

export default function MediaAndServicesPage() {
  const [services, setServices] = useState<DemoBusinessService[]>(DEMO_BUSINESSES[0].services);
  const [newName, setNewName] = useState('');
  const [newDesc, setNewDesc] = useState('');
  const [newPrice, setNewPrice] = useState('');

  function handleAddService(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!newName.trim()) return;
    setServices((prev) => [
      ...prev,
      {
        id: `demo-srv-${Date.now()}`,
        name: newName.trim(),
        description: newDesc.trim() || 'Demo service entry',
        priceLabel: newPrice.trim() || 'On request',
      },
    ]);
    setNewName('');
    setNewDesc('');
    setNewPrice('');
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Photos &amp; Services</h1>
        <p className="mt-1 text-sm text-zinc-600">
          Curate storefront visuals and transparent service pricing shown on your public profile.
        </p>
      </div>

      <DemoBanner description="Photo upload slots and service catalog rows below run in local preview state. S3/WebP media transcoding and services CRUD APIs will connect in Phase 3B/3C." />

      {/* Photos section */}
      <section className="rounded-2xl border border-zinc-200 bg-white p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-base font-semibold text-zinc-950">Storefront Photo Gallery</h2>
            <p className="text-xs text-zinc-500">
              Uploaded images will be EXIF-stripped and converted to responsive WebP variants server-side.
            </p>
          </div>
          <button
            type="button"
            disabled
            className="inline-flex cursor-not-allowed items-center gap-1.5 rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2 text-xs font-semibold text-zinc-400"
          >
            <ImagePlus className="h-4 w-4" aria-hidden="true" />
            Upload Photos (Phase 3B)
          </button>
        </div>

        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {DEMO_PHOTO_SLOTS.map((slot) => (
            <div
              key={slot.id}
              className="flex h-36 flex-col justify-between rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-4"
            >
              <span className="inline-flex w-fit rounded-full bg-white px-2.5 py-0.5 text-[11px] font-medium text-zinc-700 border border-zinc-200">
                {slot.category}
              </span>
              <p className="text-xs font-medium text-zinc-600">{slot.label}</p>
            </div>
          ))}
        </div>
      </section>

      {/* Services section */}
      <section className="rounded-2xl border border-zinc-200 bg-white p-6">
        <h2 className="text-base font-semibold text-zinc-950">Services &amp; Pricing Catalog</h2>

        <div className="mt-4 divide-y divide-zinc-100">
          {services.map((srv) => (
            <div key={srv.id} className="flex items-center justify-between gap-4 py-3.5">
              <div>
                <p className="text-sm font-semibold text-zinc-950">{srv.name}</p>
                <p className="text-xs text-zinc-600">{srv.description}</p>
              </div>
              <div className="flex items-center gap-3">
                <span className="text-sm font-semibold text-zinc-900">{srv.priceLabel}</span>
                <button
                  type="button"
                  onClick={() => setServices((prev) => prev.filter((item) => item.id !== srv.id))}
                  aria-label={`Remove ${srv.name}`}
                  className="rounded-lg border border-zinc-200 p-1.5 text-zinc-500 hover:border-red-300 hover:text-red-700"
                >
                  <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
              </div>
            </div>
          ))}
        </div>

        <form onSubmit={handleAddService} className="mt-6 grid grid-cols-1 gap-3 border-t border-zinc-100 pt-5 sm:grid-cols-12">
          <input
            type="text"
            required
            placeholder="Service name"
            value={newName}
            onChange={(e) => setNewName(e.target.value)}
            className="rounded-xl border border-zinc-300 px-3 py-2 text-sm sm:col-span-4"
          />
          <input
            type="text"
            placeholder="Short description"
            value={newDesc}
            onChange={(e) => setNewDesc(e.target.value)}
            className="rounded-xl border border-zinc-300 px-3 py-2 text-sm sm:col-span-4"
          />
          <input
            type="text"
            placeholder="Price (e.g., ₹800)"
            value={newPrice}
            onChange={(e) => setNewPrice(e.target.value)}
            className="rounded-xl border border-zinc-300 px-3 py-2 text-sm sm:col-span-2"
          />
          <button
            type="submit"
            className="inline-flex items-center justify-center gap-1 rounded-xl bg-zinc-950 px-3 py-2 text-sm font-semibold text-white hover:bg-zinc-800 sm:col-span-2"
          >
            <Plus className="h-4 w-4" aria-hidden="true" />
            Add
          </button>
        </form>
      </section>
    </div>
  );
}
