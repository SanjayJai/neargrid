'use client';

import { CheckCircle2, ListChecks, XCircle } from 'lucide-react';
import type { PublicBusinessAttributeDto } from '@/shared/api/directory-types';

interface DynamicAttributeSectionCardProps {
  id: string;
  title: string;
  attributes: PublicBusinessAttributeDto[];
}

export function DynamicAttributeSectionCard({
  id,
  title,
  attributes,
}: DynamicAttributeSectionCardProps) {
  if (attributes.length === 0) {
    return null;
  }

  return (
    <section
      id={id}
      data-testid={`dynamic-section-${id}`}
      className="scroll-mt-32 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
    >
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <ListChecks className="h-4 w-4 text-brand-600" aria-hidden="true" />
          <h2 className="text-lg font-bold text-ink">{title}</h2>
        </div>
        <span className="text-xs font-medium text-ink-muted">
          {attributes.length} detail{attributes.length === 1 ? '' : 's'}
        </span>
      </div>

      <dl className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
        {attributes.map((attr) => (
          <div
            key={attr.definition_public_id}
            className="rounded-xl border border-gray-100 bg-gray-50/70 px-3.5 py-3"
          >
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
              {attr.label}
            </dt>
            <dd className="mt-1 text-sm font-medium text-ink">
              {attr.data_type === 'boolean' && typeof attr.value === 'boolean' ? (
                <span
                  className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                    attr.value
                      ? 'bg-trust-50 text-trust-800'
                      : 'bg-gray-200 text-ink-muted'
                  }`}
                >
                  {attr.value ? (
                    <CheckCircle2 className="h-3.5 w-3.5 text-trust-600" aria-hidden="true" />
                  ) : (
                    <XCircle className="h-3.5 w-3.5 text-gray-500" aria-hidden="true" />
                  )}
                  <span>{attr.formatted_value}</span>
                </span>
              ) : attr.data_type === 'multi_enum' && Array.isArray(attr.value) ? (
                <div className="flex flex-wrap gap-1.5 pt-0.5">
                  {attr.value.map((item) => (
                    <span
                      key={item}
                      className="rounded-md border border-gray-200 bg-white px-2 py-0.5 text-xs font-medium text-ink"
                    >
                      {item}
                    </span>
                  ))}
                </div>
              ) : (
                <span>{attr.formatted_value}</span>
              )}
            </dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
