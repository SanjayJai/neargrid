'use client';

import { useState } from 'react';
import { Check, Copy, Share2 } from 'lucide-react';
import { SaveBusinessButton } from './SaveBusinessButton';

interface BusinessProfileActionsProps {
  businessPublicId?: string;
  businessName: string;
  canonicalUrl: string;
}

/**
 * Accessible Save, Share (Web Share API with clipboard fallback) & Copy URL actions.
 */
export function BusinessProfileActions({
  businessPublicId,
  businessName,
  canonicalUrl,
}: BusinessProfileActionsProps) {
  const [copied, setCopied] = useState(false);
  const [shareFeedback, setShareFeedback] = useState<string | null>(null);

  function getShareUrl(): string {
    if (typeof window !== 'undefined' && window.location?.href) {
      return window.location.href;
    }
    return canonicalUrl;
  }

  async function copyToClipboard(url: string) {
    try {
      if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(url);
      } else {
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
      }
      setCopied(true);
      setShareFeedback('Profile link copied');
      setTimeout(() => {
        setCopied(false);
        setShareFeedback(null);
      }, 2500);
    } catch {
      setShareFeedback('Unable to copy link');
      setTimeout(() => setShareFeedback(null), 2500);
    }
  }

  async function handleShare() {
    const url = getShareUrl();
    if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
      try {
        await navigator.share({
          title: `${businessName} | NearGrid`,
          text: `Check out ${businessName} on NearGrid`,
          url,
        });
        return;
      } catch (err: unknown) {
        if ((err as Error)?.name === 'AbortError') return;
        // Fallback to clipboard copy if share fails
      }
    }
    await copyToClipboard(url);
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      {businessPublicId && (
        <SaveBusinessButton
          businessPublicId={businessPublicId}
          businessName={businessName}
          size="md"
        />
      )}

      <button
        type="button"
        onClick={handleShare}
        className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-ink transition-colors hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
      >
        <Share2 className="h-3.5 w-3.5" aria-hidden="true" />
        <span>Share</span>
      </button>

      <button
        type="button"
        onClick={() => copyToClipboard(getShareUrl())}
        className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-ink transition-colors hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
      >
        {copied ? (
          <>
            <Check className="h-3.5 w-3.5 text-trust-700" aria-hidden="true" />
            <span className="text-trust-700">Copied</span>
          </>
        ) : (
          <>
            <Copy className="h-3.5 w-3.5" aria-hidden="true" />
            <span>Copy Link</span>
          </>
        )}
      </button>

      {shareFeedback && (
        <span role="status" aria-live="polite" className="sr-only">
          {shareFeedback}
        </span>
      )}
    </div>
  );
}
