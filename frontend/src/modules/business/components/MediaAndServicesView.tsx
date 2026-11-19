'use client';

import { FormEvent, useCallback, useEffect, useState } from 'react';
import Image from 'next/image';
import {
  ImagePlus,
  Loader2,
  Plus,
  Tag,
  Trash2,
  UploadCloud,
  X,
} from 'lucide-react';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';
import { bffFetch, bffUploadFormData } from '@/shared/api/bff-client';
import type {
  BusinessMediaTypeDto,
  BusinessServiceDto,
  OwnerBusinessMediaDto,
} from '@/shared/api/directory-types';

const MEDIA_TYPES: Array<{ value: BusinessMediaTypeDto; label: string }> = [
  { value: 'logo', label: 'Brand Logo' },
  { value: 'cover', label: 'Cover Banner' },
  { value: 'gallery', label: 'Storefront Gallery' },
  { value: 'interior', label: 'Interior / Clinic' },
  { value: 'exterior', label: 'Exterior / Building' },
  { value: 'product', label: 'Product / Showcase' },
  { value: 'menu', label: 'Menu / Price Board' },
];

export function MediaAndServicesView() {
  const { activeBusiness, refreshBusinesses } = useOwnerBusiness();

  // Active section tab
  const [activeTab, setActiveTab] = useState<'photos' | 'services'>('photos');

  // Media state
  const [mediaList, setMediaList] = useState<OwnerBusinessMediaDto[]>([]);
  const [loadingMedia, setLoadingMedia] = useState(false);
  const [uploadModalOpen, setUploadModalOpen] = useState(false);
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploadType, setUploadType] = useState<BusinessMediaTypeDto>('gallery');
  const [uploadAltText, setUploadAltText] = useState('');
  const [uploading, setUploading] = useState(false);
  const [mediaError, setMediaError] = useState<string | null>(null);

  // Services state
  const [servicesList, setServicesList] = useState<BusinessServiceDto[]>([]);
  const [serviceModalOpen, setServiceModalOpen] = useState(false);
  const [editingService, setEditingService] = useState<BusinessServiceDto | null>(null);
  const [serviceName, setServiceName] = useState('');
  const [serviceDescription, setServiceDescription] = useState('');
  const [servicePriceMin, setServicePriceMin] = useState('');
  const [servicePriceMax, setServicePriceMax] = useState('');
  const [servicePriceUnit, setServicePriceUnit] = useState('');
  const [serviceIsActive, setServiceIsActive] = useState(true);
  const [savingService, setSavingService] = useState(false);
  const [serviceError, setServiceError] = useState<string | null>(null);

  // Load media from live BFF endpoint
  const loadMedia = useCallback(async () => {
    if (!activeBusiness) return;
    setLoadingMedia(true);
    setMediaError(null);
    try {
      const res = await bffFetch<OwnerBusinessMediaDto[]>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/media`
      );
      if (res.ok && res.payload?.status === 'success') {
        setMediaList(res.payload.data);
      } else {
        const err = res.payload?.status === 'error' ? res.payload.error.message : 'Unable to load photos.';
        setMediaError(err);
      }
    } catch {
      setMediaError('Network error while loading storefront media.');
    } finally {
      setLoadingMedia(false);
    }
  }, [activeBusiness]);

  useEffect(() => {
    loadMedia();
  }, [loadMedia]);

  // Sync services from active business
  useEffect(() => {
    if (activeBusiness?.services) {
      setServicesList(activeBusiness.services);
    }
  }, [activeBusiness]);

  if (!activeBusiness) {
    return (
      <div className="rounded-2xl border border-zinc-200 bg-white p-8 text-center text-xs text-zinc-500">
        Please select or create a business listing to manage photos and services.
      </div>
    );
  }

  // --- Photo Upload Handler ---
  async function handleMediaUpload(e: FormEvent) {
    e.preventDefault();
    if (!activeBusiness || !uploadFile) return;

    setUploading(true);
    setMediaError(null);

    const formData = new FormData();
    formData.append('file', uploadFile);
    formData.append('media_type', uploadType);
    if (uploadAltText.trim()) {
      formData.append('alt_text', uploadAltText.trim());
    }

    try {
      const res = await bffUploadFormData<OwnerBusinessMediaDto>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/media`,
        formData
      );

      if (res.ok && res.payload?.status === 'success') {
        setUploadModalOpen(false);
        setUploadFile(null);
        setUploadAltText('');
        await loadMedia();
        await refreshBusinesses();
      } else {
        const err = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to upload photo.';
        setMediaError(err);
      }
    } catch {
      setMediaError('Network error during photo upload.');
    } finally {
      setUploading(false);
    }
  }

  // --- Photo Delete Handler ---
  async function handleMediaDelete(mediaPublicId: string) {
    if (!activeBusiness || !confirm('Are you sure you want to delete this photo?')) return;

    try {
      const res = await bffFetch(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/media/${encodeURIComponent(mediaPublicId)}`,
        { method: 'DELETE' }
      );

      if (res.ok) {
        setMediaList((prev) => prev.filter((m) => m.public_id !== mediaPublicId));
        await refreshBusinesses();
      } else {
        const errMsg = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to delete photo.';
        alert(errMsg);
      }
    } catch {
      alert('Network error while deleting photo.');
    }
  }

  // --- Service Open Edit Modal ---
  function openAddService() {
    setEditingService(null);
    setServiceName('');
    setServiceDescription('');
    setServicePriceMin('');
    setServicePriceMax('');
    setServicePriceUnit('');
    setServiceIsActive(true);
    setServiceError(null);
    setServiceModalOpen(true);
  }

  function openEditService(service: BusinessServiceDto) {
    setEditingService(service);
    setServiceName(service.name);
    setServiceDescription(service.description || '');
    setServicePriceMin(service.price_min_minor != null ? String(service.price_min_minor / 100) : '');
    setServicePriceMax(service.price_max_minor != null ? String(service.price_max_minor / 100) : '');
    setServicePriceUnit(service.price_unit || '');
    setServiceIsActive(service.is_active);
    setServiceError(null);
    setServiceModalOpen(true);
  }

  // --- Service Save Handler ---
  async function handleSaveService(e: FormEvent) {
    e.preventDefault();
    if (!activeBusiness) return;

    setSavingService(true);
    setServiceError(null);

    const minPaise = servicePriceMin ? Math.round(parseFloat(servicePriceMin) * 100) : null;
    const maxPaise = servicePriceMax ? Math.round(parseFloat(servicePriceMax) * 100) : null;

    if (minPaise !== null && maxPaise !== null && maxPaise < minPaise) {
      setServiceError('Maximum price cannot be less than minimum price.');
      setSavingService(false);
      return;
    }

    const payload = {
      name: serviceName.trim(),
      description: serviceDescription.trim() || null,
      price_min_minor: minPaise,
      price_max_minor: maxPaise,
      currency: 'INR',
      price_unit: servicePriceUnit.trim() || null,
      is_active: serviceIsActive,
    };

    try {
      const isEdit = Boolean(editingService);
      const url = isEdit
        ? `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/services/${encodeURIComponent(editingService!.public_id)}`
        : `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/services`;

      const method = isEdit ? 'PUT' : 'POST';

      const res = await bffFetch<BusinessServiceDto>(url, {
        method,
        body: payload,
      });

      if (res.ok && res.payload?.status === 'success') {
        const saved = res.payload.data;
        if (isEdit) {
          setServicesList((prev) => prev.map((s) => (s.public_id === saved.public_id ? saved : s)));
        } else {
          setServicesList((prev) => [...prev, saved]);
        }
        setServiceModalOpen(false);
        await refreshBusinesses();
      } else {
        const err = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to save service.';
        setServiceError(err);
      }
    } catch {
      setServiceError('Network error while saving service.');
    } finally {
      setSavingService(false);
    }
  }

  // --- Service Delete Handler ---
  async function handleDeleteService(servicePublicId: string) {
    if (!activeBusiness || !confirm('Are you sure you want to delete this service?')) return;

    try {
      const res = await bffFetch(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/services/${encodeURIComponent(servicePublicId)}`,
        { method: 'DELETE' }
      );

      if (res.ok) {
        setServicesList((prev) => prev.filter((s) => s.public_id !== servicePublicId));
        await refreshBusinesses();
      } else {
        const errMsg = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to delete service.';
        alert(errMsg);
      }
    } catch {
      alert('Network error while deleting service.');
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Photos &amp; Services</h1>
          <p className="mt-1 text-sm text-zinc-600">
            Curate visual storefront imagery and transparent service pricing shown on your public profile.
          </p>
        </div>

        {/* Tab switch */}
        <div className="inline-flex rounded-xl border border-zinc-200 bg-white p-1">
          <button
            type="button"
            onClick={() => setActiveTab('photos')}
            className={`rounded-lg px-4 py-1.5 text-xs font-semibold transition-colors ${
              activeTab === 'photos' ? 'bg-zinc-950 text-white' : 'text-zinc-600 hover:text-zinc-950'
            }`}
          >
            Storefront Photos ({mediaList.length})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('services')}
            className={`rounded-lg px-4 py-1.5 text-xs font-semibold transition-colors ${
              activeTab === 'services' ? 'bg-zinc-950 text-white' : 'text-zinc-600 hover:text-zinc-950'
            }`}
          >
            Services Catalog ({servicesList.length})
          </button>
        </div>
      </div>

      {/* --- TAB 1: STOREFRONT PHOTOS --- */}
      {activeTab === 'photos' && (
        <section className="space-y-4" aria-labelledby="photos-heading">
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-zinc-200 bg-white p-5">
            <div>
              <h2 id="photos-heading" className="text-base font-semibold text-zinc-950">
                Uploaded Storefront Photos
              </h2>
              <p className="mt-0.5 text-xs text-zinc-500">
                Uploaded images are sanitized (EXIF metadata stripped) and converted to responsive WebP variants.
              </p>
            </div>
            <button
              type="button"
              onClick={() => {
                setUploadModalOpen(true);
                setMediaError(null);
              }}
              className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-700"
            >
              <ImagePlus className="h-4 w-4" aria-hidden="true" />
              <span>Upload Photo</span>
            </button>
          </div>

          {mediaError && (
            <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
              {mediaError}
            </div>
          )}

          {loadingMedia ? (
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 animate-pulse">
              {[1, 2, 3, 4].map((i) => (
                <div key={i} className="aspect-square rounded-2xl bg-zinc-200" />
              ))}
            </div>
          ) : mediaList.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center sm:p-12">
              <UploadCloud className="mx-auto h-8 w-8 text-zinc-400" aria-hidden="true" />
              <h3 className="mt-3 text-sm font-semibold text-zinc-950">No photos uploaded yet</h3>
              <p className="mt-1 text-xs text-zinc-500 max-w-sm mx-auto">
                Profiles with exterior and interior photos receive higher engagement and improve verification eligibility.
              </p>
              <button
                type="button"
                onClick={() => setUploadModalOpen(true)}
                className="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-zinc-950 px-3.5 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
              >
                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                <span>Upload First Photo</span>
              </button>
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              {mediaList.map((item) => (
                <div
                  key={item.public_id}
                  className="group relative overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm"
                >
                  <div className="relative aspect-video w-full bg-zinc-100">
                    <Image
                      src={item.urls.card || item.urls.thumb || item.urls.full || '/icon-192.png'}
                      alt={item.alt_text || activeBusiness.name}
                      fill
                      sizes="(max-width: 768px) 50vw, 25vw"
                      className="object-cover"
                    />
                    <div className="absolute right-2 top-2">
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold backdrop-blur-sm ${
                          item.moderation_status === 'approved'
                            ? 'bg-emerald-600/90 text-white'
                            : item.moderation_status === 'rejected'
                            ? 'bg-rose-600/90 text-white'
                            : 'bg-amber-600/90 text-white'
                        }`}
                      >
                        {item.moderation_status.toUpperCase()}
                      </span>
                    </div>
                  </div>

                  <div className="p-3">
                    <div className="flex items-center justify-between gap-1 text-[11px]">
                      <span className="font-semibold uppercase tracking-wider text-zinc-700">
                        {item.media_type}
                      </span>
                      <span className="text-zinc-400">
                        {(item.file_size_bytes / 1024).toFixed(0)} KB
                      </span>
                    </div>
                    {item.alt_text && (
                      <p className="mt-1 truncate text-xs text-zinc-500">{item.alt_text}</p>
                    )}
                    <div className="mt-3 flex items-center justify-between border-t border-zinc-100 pt-2 text-[11px]">
                      <span className="text-zinc-400">
                        {item.exif_stripped ? 'EXIF Clean' : ''}
                      </span>
                      <button
                        type="button"
                        onClick={() => handleMediaDelete(item.public_id)}
                        aria-label={`Delete ${item.media_type} photo`}
                        className="text-rose-600 hover:text-rose-800"
                      >
                        <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </section>
      )}

      {/* --- TAB 2: SERVICES CATALOG --- */}
      {activeTab === 'services' && (
        <section className="space-y-4" aria-labelledby="services-heading">
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-zinc-200 bg-white p-5">
            <div>
              <h2 id="services-heading" className="text-base font-semibold text-zinc-950">
                Services &amp; Pricing Catalog
              </h2>
              <p className="mt-0.5 text-xs text-zinc-500">
                List the specific treatments, procedures, or services offered with transparent pricing.
              </p>
            </div>
            <button
              type="button"
              onClick={openAddService}
              className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-700"
            >
              <Plus className="h-4 w-4" aria-hidden="true" />
              <span>Add Service</span>
            </button>
          </div>

          {servicesList.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center sm:p-12">
              <Tag className="mx-auto h-8 w-8 text-zinc-400" aria-hidden="true" />
              <h3 className="mt-3 text-sm font-semibold text-zinc-950">No services configured yet</h3>
              <p className="mt-1 text-xs text-zinc-500 max-w-sm mx-auto">
                Adding your key services and pricing tiers builds customer trust and improves search ranking.
              </p>
              <button
                type="button"
                onClick={openAddService}
                className="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-zinc-950 px-3.5 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
              >
                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                <span>Add First Service</span>
              </button>
            </div>
          ) : (
            <div className="divide-y divide-zinc-200 rounded-2xl border border-zinc-200 bg-white overflow-hidden">
              {servicesList.map((service) => {
                const minRupees = service.price_min_minor != null ? service.price_min_minor / 100 : null;
                const maxRupees = service.price_max_minor != null ? service.price_max_minor / 100 : null;

                let priceLabel = 'Price on request';
                if (minRupees !== null && maxRupees !== null) {
                  priceLabel = minRupees === maxRupees ? `₹${minRupees.toLocaleString('en-IN')}` : `₹${minRupees.toLocaleString('en-IN')} - ₹${maxRupees.toLocaleString('en-IN')}`;
                } else if (minRupees !== null) {
                  priceLabel = `Starts from ₹${minRupees.toLocaleString('en-IN')}`;
                }

                if (service.price_unit) {
                  priceLabel += ` (${service.price_unit})`;
                }

                return (
                  <div
                    key={service.public_id}
                    className="flex flex-col justify-between gap-3 p-4 sm:flex-row sm:items-center sm:px-6"
                  >
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold text-zinc-950">{service.name}</span>
                        {!service.is_active && (
                          <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-medium text-zinc-600">
                            Inactive
                          </span>
                        )}
                      </div>
                      {service.description && (
                        <p className="text-xs text-zinc-600 line-clamp-1">{service.description}</p>
                      )}
                      <p className="text-xs font-semibold text-emerald-800">{priceLabel}</p>
                    </div>

                    <div className="flex items-center gap-2">
                      <button
                        type="button"
                        onClick={() => openEditService(service)}
                        className="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDeleteService(service.public_id)}
                        aria-label={`Delete ${service.name}`}
                        className="rounded-lg border border-rose-200 p-1.5 text-rose-600 hover:bg-rose-50"
                      >
                        <Trash2 className="h-4 w-4" aria-hidden="true" />
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </section>
      )}

      {/* --- MODAL 1: Upload Photo --- */}
      {uploadModalOpen && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="upload-photo-title"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
          onClick={(e) => {
            if (e.target === e.currentTarget) setUploadModalOpen(false);
          }}
        >
          <div className="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
            <button
              type="button"
              onClick={() => setUploadModalOpen(false)}
              aria-label="Close dialog"
              className="absolute right-4 top-4 rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700"
            >
              <X className="h-5 w-5" aria-hidden="true" />
            </button>

            <h3 id="upload-photo-title" className="text-base font-bold text-zinc-950">
              Upload Storefront Photo
            </h3>
            <p className="mt-1 text-xs text-zinc-500">
              Select an image file (JPG, PNG, WebP up to 10MB). Image metadata will be sanitized.
            </p>

            {mediaError && (
              <div role="alert" className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-2.5 text-xs text-rose-900">
                {mediaError}
              </div>
            )}

            <form onSubmit={handleMediaUpload} className="mt-4 space-y-4">
              <div>
                <label htmlFor="photo-file" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Image File <span className="text-rose-600">*</span>
                </label>
                <input
                  id="photo-file"
                  type="file"
                  required
                  accept="image/jpeg,image/png,image/webp"
                  onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) setUploadFile(f);
                  }}
                  className="w-full text-xs text-zinc-700 file:mr-3 file:rounded-xl file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-zinc-900 hover:file:bg-zinc-200"
                />
              </div>

              <div>
                <label htmlFor="photo-type" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Media Category <span className="text-rose-600">*</span>
                </label>
                <select
                  id="photo-type"
                  required
                  value={uploadType}
                  onChange={(e) => setUploadType(e.target.value as BusinessMediaTypeDto)}
                  className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                >
                  {MEDIA_TYPES.map((t) => (
                    <option key={t.value} value={t.value}>
                      {t.label}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label htmlFor="photo-alt" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Alt Text / Caption <span className="text-zinc-400 font-normal">(Optional)</span>
                </label>
                <input
                  id="photo-alt"
                  type="text"
                  maxLength={255}
                  value={uploadAltText}
                  onChange={(e) => setUploadAltText(e.target.value)}
                  placeholder="e.g. Modern reception waiting lounge"
                  className="w-full rounded-xl border border-zinc-300 px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                />
              </div>

              <div className="flex items-center justify-end gap-2.5 border-t border-zinc-100 pt-3">
                <button
                  type="button"
                  onClick={() => setUploadModalOpen(false)}
                  disabled={uploading}
                  className="rounded-xl border border-zinc-300 px-3.5 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={uploading || !uploadFile}
                  className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
                >
                  {uploading ? (
                    <>
                      <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                      <span>Uploading...</span>
                    </>
                  ) : (
                    <span>Upload</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* --- MODAL 2: Add / Edit Service --- */}
      {serviceModalOpen && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="service-modal-title"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
          onClick={(e) => {
            if (e.target === e.currentTarget) setServiceModalOpen(false);
          }}
        >
          <div className="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
            <button
              type="button"
              onClick={() => setServiceModalOpen(false)}
              aria-label="Close dialog"
              className="absolute right-4 top-4 rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700"
            >
              <X className="h-5 w-5" aria-hidden="true" />
            </button>

            <h3 id="service-modal-title" className="text-base font-bold text-zinc-950">
              {editingService ? 'Edit Service' : 'Add New Service'}
            </h3>
            <p className="mt-1 text-xs text-zinc-500">
              Specify transparent pricing and details for your public storefront catalog.
            </p>

            {serviceError && (
              <div role="alert" className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-2.5 text-xs text-rose-900">
                {serviceError}
              </div>
            )}

            <form onSubmit={handleSaveService} className="mt-4 space-y-4">
              <div>
                <label htmlFor="srv-name" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Service Name <span className="text-rose-600">*</span>
                </label>
                <input
                  id="srv-name"
                  type="text"
                  required
                  minLength={2}
                  maxLength={150}
                  value={serviceName}
                  onChange={(e) => setServiceName(e.target.value)}
                  placeholder="e.g. Dental Cleaning & Scaling"
                  className="w-full rounded-xl border border-zinc-300 px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                />
              </div>

              <div>
                <label htmlFor="srv-desc" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Description <span className="text-zinc-400 font-normal">(Optional, max 500 chars)</span>
                </label>
                <textarea
                  id="srv-desc"
                  rows={2}
                  maxLength={500}
                  value={serviceDescription}
                  onChange={(e) => setServiceDescription(e.target.value)}
                  placeholder="Includes ultrasonic plaque removal and enamel polishing..."
                  className="w-full rounded-xl border border-zinc-300 px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label htmlFor="srv-pmin" className="mb-1 block text-xs font-semibold text-zinc-900">
                    Min Price (₹) <span className="text-zinc-400 font-normal">(Optional)</span>
                  </label>
                  <input
                    id="srv-pmin"
                    type="number"
                    min={0}
                    step={1}
                    value={servicePriceMin}
                    onChange={(e) => setServicePriceMin(e.target.value)}
                    placeholder="e.g. 500"
                    className="w-full rounded-xl border border-zinc-300 px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                  />
                </div>

                <div>
                  <label htmlFor="srv-pmax" className="mb-1 block text-xs font-semibold text-zinc-900">
                    Max Price (₹) <span className="text-zinc-400 font-normal">(Optional)</span>
                  </label>
                  <input
                    id="srv-pmax"
                    type="number"
                    min={0}
                    step={1}
                    value={servicePriceMax}
                    onChange={(e) => setServicePriceMax(e.target.value)}
                    placeholder="e.g. 1500"
                    className="w-full rounded-xl border border-zinc-300 px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                  />
                </div>
              </div>

              <div>
                <label htmlFor="srv-unit" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Price Unit / Qualifier <span className="text-zinc-400 font-normal">(Optional)</span>
                </label>
                <input
                  id="srv-unit"
                  type="text"
                  maxLength={40}
                  value={servicePriceUnit}
                  onChange={(e) => setServicePriceUnit(e.target.value)}
                  placeholder="e.g. per session, starts from, per tooth"
                  className="w-full rounded-xl border border-zinc-300 px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                />
              </div>

              <div className="flex items-center gap-2">
                <input
                  id="srv-active"
                  type="checkbox"
                  checked={serviceIsActive}
                  onChange={(e) => setServiceIsActive(e.target.checked)}
                  className="h-4 w-4 rounded border-zinc-300 text-brand-600 focus:ring-brand-600"
                />
                <label htmlFor="srv-active" className="text-xs font-medium text-zinc-800">
                  Visible on public storefront (Active)
                </label>
              </div>

              <div className="flex items-center justify-end gap-2.5 border-t border-zinc-100 pt-3">
                <button
                  type="button"
                  onClick={() => setServiceModalOpen(false)}
                  disabled={savingService}
                  className="rounded-xl border border-zinc-300 px-3.5 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={savingService}
                  className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
                >
                  {savingService ? (
                    <>
                      <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                      <span>Saving...</span>
                    </>
                  ) : (
                    <span>{editingService ? 'Update Service' : 'Add Service'}</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
