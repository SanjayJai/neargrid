'use client';

import { useEffect, useRef, useState } from 'react';
import { AdFormatRenderers, type DeliveredAdItem, type DeliveredPlacement } from './AdFormatRenderers';

export interface AdSlotContext {
  pageType: 'home' | 'search' | 'category' | 'city_hub' | 'business_profile' | 'account' | 'global';
  routePath?: string;
  locale?: 'en' | 'ta';
  citySlug?: string;
  localitySlug?: string;
  categorySlug?: string;
}

interface AdPlacementSlotProps {
  placementKey: string;
  context: AdSlotContext;
  className?: string;
}

function detectDevice(): 'desktop' | 'tablet' | 'mobile' {
  if (typeof window === 'undefined') return 'desktop';
  const w = window.innerWidth;
  if (w < 768) return 'mobile';
  if (w < 1024) return 'tablet';
  return 'desktop';
}

export function AdPlacementSlot({ placementKey, context, className = '' }: AdPlacementSlotProps) {
  const [placement, setPlacement] = useState<DeliveredPlacement | null>(null);
  const [loaded, setLoaded] = useState(false);
  const containerRef = useRef<HTMLDivElement | null>(null);
  const impressedTokensRef = useRef<Set<string>>(new Set());

  useEffect(() => {
    let active = true;
    const qs = new URLSearchParams();
    qs.set('page_type', context.pageType);
    qs.append('placement_keys[]', placementKey);
    qs.set('device', detectDevice());
    if (context.locale) qs.set('locale', context.locale);
    if (context.routePath) qs.set('route_path', context.routePath);
    if (context.citySlug) qs.set('city_slug', context.citySlug);
    if (context.localitySlug) qs.set('locality_slug', context.localitySlug);
    if (context.categorySlug) qs.set('category_slug', context.categorySlug);

    fetch(`/api/public/ads/deliver?${qs.toString()}`, { cache: 'no-store' })
      .then((res) => (res.ok ? res.json() : null))
      .then((json) => {
        if (!active) return;
        const slot = json?.data?.placements?.[placementKey] ?? null;
        setPlacement(slot);
        setLoaded(true);
      })
      .catch(() => {
        if (active) setLoaded(true);
      });

    return () => {
      active = false;
    };
  }, [
    placementKey,
    context.pageType,
    context.locale,
    context.routePath,
    context.citySlug,
    context.localitySlug,
    context.categorySlug,
  ]);

  useEffect(() => {
    if (!placement || placement.ads.length === 0 || !containerRef.current) return;
    if (typeof IntersectionObserver === 'undefined') return;

    const el = containerRef.current;
    let timer: ReturnType<typeof setTimeout> | null = null;
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
            if (!timer) {
              timer = setTimeout(() => {
                for (const ad of placement.ads) {
                  if (!impressedTokensRef.current.has(ad.tracking_token)) {
                    impressedTokensRef.current.add(ad.tracking_token);
                    void fetch('/api/public/ads/events', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ event_type: 'impression', token: ad.tracking_token }),
                      keepalive: true,
                    }).catch(() => undefined);
                  }
                }
                observer.disconnect();
              }, 1000);
            }
          } else if (timer) {
            clearTimeout(timer);
            timer = null;
          }
        }
      },
      { threshold: 0.5 }
    );

    observer.observe(el);
    return () => {
      if (timer) clearTimeout(timer);
      observer.disconnect();
    };
  }, [placement]);

  function handleAdClick(ad: DeliveredAdItem) {
    void fetch('/api/public/ads/events', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ event_type: 'click', token: ad.tracking_token }),
      keepalive: true,
    }).catch(() => undefined);
  }

  if (!loaded || !placement || placement.ads.length === 0) {
    return null;
  }

  return (
    <div ref={containerRef} data-ad-placement={placementKey} className={className}>
      <AdFormatRenderers placement={placement} onAdClick={handleAdClick} />
    </div>
  );
}
