'use client';

import { useState } from 'react';
import { Building2 } from 'lucide-react';

interface FallbackImageProps {
  src?: string | null;
  alt: string;
  className?: string;
  fallbackLabel?: string;
  priority?: boolean;
}

/**
 * Renders approved public WebP business media with graceful loading and error fallback.
 */
export function FallbackImage({
  src,
  alt,
  className = 'h-full w-full object-cover',
  fallbackLabel,
  priority = false,
}: FallbackImageProps) {
  const [failed, setFailed] = useState(false);

  if (!src || failed) {
    return (
      <div
        role="img"
        aria-label={alt}
        className="flex h-full w-full flex-col items-center justify-center gap-1.5 bg-gradient-to-br from-brand-50 to-gray-100 p-3 text-center text-ink-subtle"
      >
        <Building2 className="h-7 w-7 text-brand-600/60" aria-hidden="true" />
        {fallbackLabel && (
          <span className="line-clamp-2 text-[11px] font-medium text-ink-subtle">
            {fallbackLabel}
          </span>
        )}
      </div>
    );
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={src}
      alt={alt}
      loading={priority ? 'eager' : 'lazy'}
      decoding="async"
      fetchPriority={priority ? 'high' : 'auto'}
      onError={() => setFailed(true)}
      className={className}
    />
  );
}
