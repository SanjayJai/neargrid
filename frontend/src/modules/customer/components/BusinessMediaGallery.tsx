'use client';

import { useCallback, useEffect, useState } from 'react';
import {
  Camera,
  ChevronLeft,
  ChevronRight,
  Expand,
  X,
} from 'lucide-react';
import type { PublicBusinessMediaDto } from '@/shared/api/directory-types';
import { FallbackImage } from '@/shared/ui/FallbackImage';
import { getDeliverablePublicMedia } from '@/modules/customer/lib/business-profile';

interface BusinessMediaGalleryProps {
  businessName: string;
  media: PublicBusinessMediaDto[];
  lightboxIndex?: number | null;
  onLightboxChange?: (index: number | null) => void;
}

const MEDIA_TYPE_LABELS: Record<PublicBusinessMediaDto['media_type'], string> = {
  logo: 'Logo',
  cover: 'Cover Photo',
  gallery: 'Photo',
  interior: 'Interior',
  exterior: 'Exterior',
  product: 'Product',
  menu: 'Menu',
  certificate: 'Certificate',
};

/**
 * Approved public WebP media gallery with thumbnail navigation and accessible keyboard lightbox.
 * Only renders deliverable public URLs; displays an honest empty state when no approved photos exist.
 */
export function BusinessMediaGallery({
  businessName,
  media,
  lightboxIndex: controlledLightboxIndex,
  onLightboxChange,
}: BusinessMediaGalleryProps) {
  const deliverable = getDeliverablePublicMedia(media);
  const [selectedIndex, setSelectedIndex] = useState(0);
  const [internalLightboxIndex, setInternalLightboxIndex] = useState<number | null>(null);

  const isControlled = controlledLightboxIndex !== undefined;
  const activeLightboxIndex = isControlled
    ? controlledLightboxIndex
    : internalLightboxIndex;

  const setLightboxIndex = useCallback(
    (next: number | null) => {
      if (onLightboxChange) {
        onLightboxChange(next);
      }
      if (!isControlled) {
        setInternalLightboxIndex(next);
      }
    },
    [isControlled, onLightboxChange]
  );

  const safeSelectedIndex =
    deliverable.length > 0 ? Math.min(selectedIndex, deliverable.length - 1) : 0;

  const handleNext = useCallback(() => {
    if (deliverable.length <= 1 || activeLightboxIndex === null) return;
    setLightboxIndex((activeLightboxIndex + 1) % deliverable.length);
  }, [activeLightboxIndex, deliverable.length, setLightboxIndex]);

  const handlePrev = useCallback(() => {
    if (deliverable.length <= 1 || activeLightboxIndex === null) return;
    setLightboxIndex((activeLightboxIndex - 1 + deliverable.length) % deliverable.length);
  }, [activeLightboxIndex, deliverable.length, setLightboxIndex]);

  useEffect(() => {
    if (activeLightboxIndex === null) return;

    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setLightboxIndex(null);
      } else if (event.key === 'ArrowRight') {
        handleNext();
      } else if (event.key === 'ArrowLeft') {
        handlePrev();
      }
    }

    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.body.style.overflow = prevOverflow;
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [activeLightboxIndex, handleNext, handlePrev, setLightboxIndex]);

  if (deliverable.length === 0) {
    return (
      <div
        data-testid="media-gallery-empty"
        className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-10 text-center"
      >
        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-600">
          <Camera className="h-6 w-6" aria-hidden="true" />
        </div>
        <h2 className="mt-3 text-sm font-semibold text-ink">
          No approved photos available yet
        </h2>
        <p className="mt-1 max-w-md text-xs text-ink-muted">
          Public storefront photos for {businessName} will appear here once uploaded and
          approved by moderation.
        </p>
      </div>
    );
  }

  const activeItem = deliverable[safeSelectedIndex];
  const heroSrc =
    activeItem.urls.full ?? activeItem.urls.card ?? activeItem.urls.thumb;
  const lightboxItem =
    activeLightboxIndex !== null ? deliverable[activeLightboxIndex] ?? null : null;

  return (
    <div className="space-y-3" data-testid="media-gallery">
      {/* Main Hero & Side Preview Grid */}
      <div className="grid grid-cols-1 gap-3 md:grid-cols-12">
        <div className="relative overflow-hidden rounded-2xl border border-gray-200 bg-gray-100 aspect-[16/10] md:col-span-8 md:aspect-[16/9]">
          <FallbackImage
            src={heroSrc}
            alt={activeItem.alt_text || `${businessName} ${MEDIA_TYPE_LABELS[activeItem.media_type]}`}
            fallbackLabel={businessName}
            priority
          />

          <div className="pointer-events-none absolute inset-x-0 bottom-0 flex items-end justify-between bg-gradient-to-t from-black/70 via-black/25 to-transparent p-4">
            <div className="text-white">
              <span className="inline-block rounded bg-black/50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide">
                {MEDIA_TYPE_LABELS[activeItem.media_type]}
              </span>
              {activeItem.alt_text && (
                <p className="mt-1 text-xs text-gray-100 line-clamp-1">
                  {activeItem.alt_text}
                </p>
              )}
            </div>
            <button
              type="button"
              onClick={() => setLightboxIndex(safeSelectedIndex)}
              className="pointer-events-auto inline-flex items-center gap-1.5 rounded-lg bg-white/95 px-3 py-1.5 text-xs font-semibold text-ink shadow-sm transition-colors hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
              aria-label={`Open fullscreen gallery (${deliverable.length} photo${deliverable.length === 1 ? '' : 's'})`}
            >
              <Expand className="h-3.5 w-3.5" aria-hidden="true" />
              <span>
                View All ({deliverable.length})
              </span>
            </button>
          </div>
        </div>

        {/* Right Thumbnail Mosaic (Desktop/Tablet) */}
        <div className="hidden gap-3 md:col-span-4 md:grid md:grid-cols-2">
          {deliverable.slice(0, 4).map((item, idx) => {
            const thumbSrc = item.urls.card ?? item.urls.thumb ?? item.urls.full;
            const isSelected = idx === safeSelectedIndex;
            const isLastTileWithMore = idx === 3 && deliverable.length > 4;

            return (
              <button
                key={item.public_id}
                type="button"
                onClick={() => {
                  if (isLastTileWithMore) {
                    setLightboxIndex(idx);
                  } else {
                    setSelectedIndex(idx);
                  }
                }}
                aria-label={`View photo ${idx + 1}: ${item.alt_text || MEDIA_TYPE_LABELS[item.media_type]}`}
                aria-pressed={isSelected}
                className={`relative overflow-hidden rounded-xl border bg-gray-100 aspect-[4/3] transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 ${
                  isSelected
                    ? 'border-brand-600 ring-2 ring-brand-600/30'
                    : 'border-gray-200 hover:border-brand-400'
                }`}
              >
                <FallbackImage
                  src={thumbSrc}
                  alt={item.alt_text || `${businessName} photo ${idx + 1}`}
                  fallbackLabel={MEDIA_TYPE_LABELS[item.media_type]}
                />
                {isLastTileWithMore && (
                  <div className="absolute inset-0 flex items-center justify-center bg-black/60 text-sm font-bold text-white">
                    +{deliverable.length - 4} more
                  </div>
                )}
              </button>
            );
          })}
        </div>
      </div>

      {/* Horizontal Thumbnail Strip (Mobile + All Photos Quick Nav) */}
      {deliverable.length > 1 && (
        <div
          role="list"
          aria-label="Gallery thumbnails"
          className="flex gap-2 overflow-x-auto pb-1"
        >
          {deliverable.map((item, idx) => {
            const thumbSrc = item.urls.thumb ?? item.urls.card ?? item.urls.full;
            const isSelected = idx === safeSelectedIndex;
            return (
              <button
                key={item.public_id}
                type="button"
                onClick={() => setSelectedIndex(idx)}
                aria-label={`Select photo ${idx + 1}`}
                aria-pressed={isSelected}
                className={`relative h-14 w-20 shrink-0 overflow-hidden rounded-lg border bg-gray-100 transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 ${
                  isSelected
                    ? 'border-brand-600 ring-2 ring-brand-600/30'
                    : 'border-gray-200 opacity-80 hover:opacity-100'
                }`}
              >
                <FallbackImage
                  src={thumbSrc}
                  alt={item.alt_text || `${businessName} thumbnail ${idx + 1}`}
                />
              </button>
            );
          })}
        </div>
      )}

      {/* Accessible Fullscreen Lightbox */}
      {lightboxItem && activeLightboxIndex !== null && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label={`${businessName} photo lightbox`}
          className="fixed inset-0 z-50 flex flex-col items-center justify-center bg-black/90 p-4 backdrop-blur-sm"
          onClick={(e) => {
            if (e.target === e.currentTarget) setLightboxIndex(null);
          }}
        >
          <div className="flex w-full max-w-5xl items-center justify-between pb-3 text-white">
            <div className="text-xs sm:text-sm font-semibold">
              {MEDIA_TYPE_LABELS[lightboxItem.media_type]} · {activeLightboxIndex + 1} /{' '}
              {deliverable.length}
            </div>
            <button
              type="button"
              onClick={() => setLightboxIndex(null)}
              aria-label="Close photo lightbox"
              className="inline-flex items-center gap-1 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
            >
              <X className="h-4 w-4" aria-hidden="true" />
              <span>Close</span>
            </button>
          </div>

          <div className="relative flex w-full max-w-5xl flex-1 items-center justify-center min-h-0">
            {deliverable.length > 1 && (
              <button
                type="button"
                onClick={handlePrev}
                aria-label="Previous photo"
                className="absolute left-2 z-10 rounded-full bg-black/60 p-2.5 text-white transition-colors hover:bg-black/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
              >
                <ChevronLeft className="h-6 w-6" aria-hidden="true" />
              </button>
            )}

            <div className="relative max-h-[75vh] w-full overflow-hidden rounded-xl bg-black flex items-center justify-center">
              <FallbackImage
                src={
                  lightboxItem.urls.full ??
                  lightboxItem.urls.card ??
                  lightboxItem.urls.thumb
                }
                alt={
                  lightboxItem.alt_text ||
                  `${businessName} ${MEDIA_TYPE_LABELS[lightboxItem.media_type]}`
                }
                className="max-h-[75vh] w-auto max-w-full object-contain"
              />
            </div>

            {deliverable.length > 1 && (
              <button
                type="button"
                onClick={handleNext}
                aria-label="Next photo"
                className="absolute right-2 z-10 rounded-full bg-black/60 p-2.5 text-white transition-colors hover:bg-black/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
              >
                <ChevronRight className="h-6 w-6" aria-hidden="true" />
              </button>
            )}
          </div>

          {lightboxItem.alt_text && (
            <p className="mt-3 max-w-3xl text-center text-xs text-gray-200">
              {lightboxItem.alt_text}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
