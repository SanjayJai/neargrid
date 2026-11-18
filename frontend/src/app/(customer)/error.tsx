'use client';

import { ErrorState } from '@/shared/ui/StateFeedback';

export default function CustomerError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <div className="mx-auto max-w-2xl px-4 py-16 sm:px-6">
      <ErrorState
        title="Something went wrong loading this page"
        message={
          error.message ||
          'An unexpected error occurred while loading the NearGrid directory. Please try again.'
        }
        onRetry={reset}
      />
    </div>
  );
}
