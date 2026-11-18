import { AlertTriangle, Clock, RefreshCw, SearchX } from 'lucide-react';
import type { ReactNode } from 'react';

interface EmptyStateProps {
  title: string;
  description: string;
  action?: ReactNode;
}

export function EmptyState({ title, description, action }: EmptyStateProps) {
  return (
    <div className="rounded-xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
      <SearchX className="mx-auto h-9 w-9 text-gray-400" aria-hidden="true" />
      <h2 className="mt-3 text-base font-semibold text-ink">{title}</h2>
      <p className="mx-auto mt-1 max-w-md text-sm text-ink-muted">{description}</p>
      {action && <div className="mt-5 flex justify-center">{action}</div>}
    </div>
  );
}

interface ErrorStateProps {
  title?: string;
  message: string;
  onRetry?: () => void;
}

export function ErrorState({
  title = 'Unable to load data',
  message,
  onRetry,
}: ErrorStateProps) {
  return (
    <div
      role="alert"
      className="rounded-xl border border-rose-200 bg-rose-50/70 px-5 py-6 text-center"
    >
      <AlertTriangle className="mx-auto h-8 w-8 text-rose-600" aria-hidden="true" />
      <h2 className="mt-2.5 text-base font-semibold text-rose-950">{title}</h2>
      <p className="mx-auto mt-1 max-w-md text-sm text-rose-800">{message}</p>
      {onRetry && (
        <button
          type="button"
          onClick={onRetry}
          className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
        >
          <RefreshCw className="h-4 w-4" aria-hidden="true" />
          Try Again
        </button>
      )}
    </div>
  );
}

interface PendingFeatureNoticeProps {
  title: string;
  description: string;
  phaseLabel?: string;
}

export function PendingFeatureNotice({
  title,
  description,
  phaseLabel = 'Phase 3C API Pending',
}: PendingFeatureNoticeProps) {
  return (
    <div
      role="note"
      className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3.5 text-sm text-amber-950"
    >
      <Clock className="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
      <div>
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-semibold">{title}</span>
          <span className="rounded bg-amber-200/80 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-950">
            {phaseLabel}
          </span>
        </div>
        <p className="mt-0.5 text-xs leading-relaxed text-amber-900">{description}</p>
      </div>
    </div>
  );
}
