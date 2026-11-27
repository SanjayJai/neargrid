'use client';

import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  ListChecks,
  Loader2,
  Save,
  Trash2,
} from 'lucide-react';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';
import { bffFetch } from '@/shared/api/bff-client';
import type {
  OwnerBusinessAttributeItemDto,
  OwnerBusinessAttributesResponseDto,
} from '@/shared/api/directory-types';

function extractChoiceList(attr: OwnerBusinessAttributeItemDto): Array<{ value: string; label: string }> {
  const raw = attr.options_json?.choices ?? attr.options_json?.options ?? [];
  if (!Array.isArray(raw)) return [];

  const result: Array<{ value: string; label: string }> = [];
  for (const item of raw) {
    if (typeof item === 'string' && item.trim() !== '') {
      result.push({ value: item.trim(), label: item.trim() });
    } else if (item && typeof item === 'object') {
      const val = String(item.value ?? item.key ?? '').trim();
      const lbl = String(item.label ?? val).trim();
      if (val !== '') {
        result.push({ value: val, label: lbl });
      }
    }
  }
  return result;
}

export function BusinessAttributesEditor() {
  const { activeBusiness } = useOwnerBusiness();

  const [data, setData] = useState<OwnerBusinessAttributesResponseDto | null>(null);
  const [draftValues, setDraftValues] = useState<Record<string, string | number | boolean | string[] | null>>({});
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [validating, setValidating] = useState(false);
  const [clearingOrphans, setClearingOrphans] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [statusMessage, setStatusMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const hydrateDrafts = useCallback((payload: OwnerBusinessAttributesResponseDto) => {
    const next: Record<string, string | number | boolean | string[] | null> = {};
    for (const attr of payload.attributes) {
      next[attr.definition_public_id] = attr.value;
    }
    setDraftValues(next);
  }, []);

  const loadAttributes = useCallback(async (businessId: string) => {
    setLoading(true);
    setLoadError(null);
    setStatusMessage(null);
    setFieldErrors({});
    try {
      const res = await bffFetch<OwnerBusinessAttributesResponseDto>(
        `/api/owner/businesses/${encodeURIComponent(businessId)}/attributes`
      );
      if (!res.ok || !res.payload || !('data' in res.payload)) {
        const err = res.payload && 'error' in res.payload ? res.payload.error : undefined;
        setLoadError(err?.message ?? 'Unable to load category attributes.');
        setData(null);
        return;
      }
      setData(res.payload.data);
      hydrateDrafts(res.payload.data);
    } catch {
      setLoadError('Network error while loading category attributes.');
    } finally {
      setLoading(false);
    }
  }, [hydrateDrafts]);

  useEffect(() => {
    if (!activeBusiness) {
      setData(null);
      return;
    }
    void loadAttributes(activeBusiness.public_id);
  }, [activeBusiness, loadAttributes]);

  const groupedSections = useMemo(() => {
    if (!data) return [];

    const sectionMap = new Map<string, { key: string; label: string; attributes: OwnerBusinessAttributeItemDto[] }>();
    for (const sec of data.sections) {
      sectionMap.set(sec.section_key, {
        key: sec.section_key,
        label: sec.display_label_en,
        attributes: [],
      });
    }

    const ungrouped: OwnerBusinessAttributeItemDto[] = [];
    for (const attr of data.attributes) {
      if (attr.section_key && sectionMap.has(attr.section_key)) {
        sectionMap.get(attr.section_key)!.attributes.push(attr);
      } else {
        ungrouped.push(attr);
      }
    }

    const groups = Array.from(sectionMap.values()).filter((g) => g.attributes.length > 0);
    if (ungrouped.length > 0) {
      groups.push({
        key: '__general',
        label: 'Category Specifications',
        attributes: ungrouped,
      });
    }
    return groups;
  }, [data]);

  if (!activeBusiness) {
    return null;
  }

  function updateDraft(defId: string, val: string | number | boolean | string[] | null) {
    setDraftValues((prev) => ({ ...prev, [defId]: val }));
  }

  async function handleSave(e: FormEvent, validateRequired = false) {
    e.preventDefault();
    if (!activeBusiness || !data) return;

    setSaving(true);
    setStatusMessage(null);
    setFieldErrors({});

    const items = data.attributes.map((attr) => {
      const raw = draftValues[attr.definition_public_id];
      let normalized: string | number | boolean | string[] | null = raw ?? null;

      if (attr.data_type === 'number' && typeof raw === 'string') {
        normalized = raw.trim() === '' ? null : Number(raw);
      } else if (attr.data_type === 'string' && typeof raw === 'string' && raw.trim() === '') {
        normalized = null;
      } else if (attr.data_type === 'enum' && typeof raw === 'string' && raw.trim() === '') {
        normalized = null;
      } else if (attr.data_type === 'multi_enum' && Array.isArray(raw) && raw.length === 0) {
        normalized = null;
      }

      return {
        definition_public_id: attr.definition_public_id,
        attribute_key: attr.attribute_key,
        value: normalized,
      };
    });

    try {
      const res = await bffFetch<OwnerBusinessAttributesResponseDto>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/attributes`,
        {
          method: 'PUT',
          body: JSON.stringify({
            validate_required: validateRequired,
            attributes: items,
          }),
        }
      );

      if (!res.ok || !res.payload || !('data' in res.payload)) {
        const err = res.payload && 'error' in res.payload ? res.payload.error : undefined;
        const nextErrors: Record<string, string> = {};
        const details = err?.details as Record<string, string[]> | undefined;
        if (details && typeof details === 'object') {
          for (const [key, messages] of Object.entries(details)) {
            if (Array.isArray(messages) && messages.length > 0) {
              nextErrors[key] = messages[0];
            }
          }
        }
        setFieldErrors(nextErrors);
        setStatusMessage({
          type: 'error',
          text: err?.message ?? 'Failed to save category attributes.',
        });
        return;
      }

      setData(res.payload.data);
      hydrateDrafts(res.payload.data);
      setStatusMessage({
        type: 'success',
        text: validateRequired
          ? 'Attributes saved and verified for publishing.'
          : 'Category attributes saved.',
      });
    } catch {
      setStatusMessage({ type: 'error', text: 'Network error while saving attributes.' });
    } finally {
      setSaving(false);
    }
  }

  async function handleValidateForPublish() {
    if (!activeBusiness) return;
    setValidating(true);
    setStatusMessage(null);
    setFieldErrors({});
    try {
      const res = await bffFetch<{ is_complete_for_publish: boolean }>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/attributes/validate`,
        { method: 'POST' }
      );
      if (!res.ok || !res.payload || !('data' in res.payload)) {
        const err = res.payload && 'error' in res.payload ? res.payload.error : undefined;
        const nextErrors: Record<string, string> = {};
        const details = err?.details as Record<string, string[]> | undefined;
        if (details && typeof details === 'object') {
          for (const [key, messages] of Object.entries(details)) {
            if (Array.isArray(messages) && messages.length > 0) {
              nextErrors[key] = messages[0];
            }
          }
        }
        setFieldErrors(nextErrors);
        setStatusMessage({
          type: 'error',
          text: err?.message ?? 'Required category attributes are incomplete.',
        });
        return;
      }
      setStatusMessage({
        type: 'success',
        text: 'All required category attributes are complete.',
      });
    } catch {
      setStatusMessage({ type: 'error', text: 'Network error while validating attributes.' });
    } finally {
      setValidating(false);
    }
  }

  async function handleClearOrphans(valuePublicIds?: string[]) {
    if (!activeBusiness) return;
    setClearingOrphans(true);
    setStatusMessage(null);
    try {
      const res = await bffFetch<{ deleted_count: number; attributes: OwnerBusinessAttributesResponseDto }>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/attributes/orphans`,
        {
          method: 'DELETE',
          ...(valuePublicIds && valuePublicIds.length > 0
            ? { body: JSON.stringify({ value_public_ids: valuePublicIds }) }
            : {}),
        }
      );
      if (!res.ok || !res.payload || !('data' in res.payload)) {
        const err = res.payload && 'error' in res.payload ? res.payload.error : undefined;
        setStatusMessage({
          type: 'error',
          text: err?.message ?? 'Failed to remove archived attribute values.',
        });
        return;
      }
      setData(res.payload.data.attributes);
      hydrateDrafts(res.payload.data.attributes);
      setStatusMessage({
        type: 'success',
        text: `Removed ${res.payload.data.deleted_count} archived attribute value${res.payload.data.deleted_count === 1 ? '' : 's'}.`,
      });
    } catch {
      setStatusMessage({ type: 'error', text: 'Network error while removing archived values.' });
    } finally {
      setClearingOrphans(false);
    }
  }

  return (
    <div className="space-y-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-base font-bold text-zinc-950">
            <ListChecks className="h-4 w-4 text-brand-600" aria-hidden="true" />
            Category-Specific Attributes
          </h2>
          <p className="mt-1 text-xs text-zinc-600">
            Structured specifications driven by your business&apos;s assigned categories.
          </p>
        </div>

        {data && (
          <div className="flex items-center gap-2">
            <span
              className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
                data.is_complete_for_publish
                  ? 'bg-emerald-50 text-emerald-700'
                  : 'bg-amber-50 text-amber-800'
              }`}
            >
              {data.is_complete_for_publish
                ? 'Required attributes complete'
                : `${data.missing_required.length} required missing`}
            </span>
            <button
              type="button"
              onClick={handleValidateForPublish}
              disabled={validating || loading}
              className="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-50 disabled:opacity-50"
            >
              {validating ? 'Validating...' : 'Check Required'}
            </button>
          </div>
        )}
      </div>

      {statusMessage && (
        <div
          role="status"
          className={`flex items-center gap-2 rounded-xl border px-4 py-3 text-sm ${
            statusMessage.type === 'success'
              ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
              : 'border-rose-200 bg-rose-50 text-rose-900'
          }`}
        >
          {statusMessage.type === 'success' ? (
            <CheckCircle2 className="h-4 w-4 shrink-0" aria-hidden="true" />
          ) : (
            <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
          )}
          <span>{statusMessage.text}</span>
        </div>
      )}

      {Object.keys(fieldErrors).length > 0 && (
        <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
          <ul className="list-disc space-y-1 pl-4">
            {Object.entries(fieldErrors).map(([key, msg]) => (
              <li key={key}>{msg}</li>
            ))}
          </ul>
        </div>
      )}

      {loading ? (
        <div className="flex items-center gap-2 py-6 text-sm text-zinc-500">
          <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          <span>Loading category attributes...</span>
        </div>
      ) : loadError ? (
        <div className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
          {loadError}
        </div>
      ) : data && data.attributes.length === 0 ? (
        <p className="text-sm text-zinc-500">
          No custom category attributes are configured for this business&apos;s categories yet.
        </p>
      ) : (
        <form onSubmit={(e) => handleSave(e, false)} className="space-y-6">
          {groupedSections.map((group) => (
            <div key={group.key} className="space-y-4 border-t border-zinc-100 pt-4 first:border-t-0 first:pt-0">
              <h3 className="text-xs font-bold uppercase tracking-wider text-zinc-500">
                {group.label}
              </h3>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {group.attributes.map((attr) => {
                  const val = draftValues[attr.definition_public_id] ?? null;
                  const inputId = `attr-${attr.definition_public_id}`;
                  const choices = extractChoiceList(attr);

                  return (
                    <div key={attr.definition_public_id} className="space-y-1.5">
                      <label htmlFor={inputId} className="block text-xs font-semibold text-zinc-900">
                        {attr.label_en}
                        {attr.unit ? ` (${attr.unit})` : ''}
                        {attr.is_required && <span className="ml-1 text-rose-600">*</span>}
                      </label>

                      {attr.data_type === 'boolean' && (
                        <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-zinc-200 px-3 py-2 text-sm text-zinc-900">
                          <input
                            id={inputId}
                            type="checkbox"
                            checked={val === true}
                            onChange={(e) => updateDraft(attr.definition_public_id, e.target.checked)}
                            className="h-4 w-4 rounded border-zinc-300 text-brand-600 focus:ring-brand-600"
                          />
                          <span>{val === true ? 'Yes' : 'No'}</span>
                        </label>
                      )}

                      {attr.data_type === 'string' && (
                        <input
                          id={inputId}
                          type="text"
                          maxLength={attr.options_json?.max_length ?? 500}
                          value={typeof val === 'string' ? val : ''}
                          onChange={(e) => updateDraft(attr.definition_public_id, e.target.value)}
                          className="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                        />
                      )}

                      {attr.data_type === 'number' && (
                        <input
                          id={inputId}
                          type="number"
                          min={attr.options_json?.min}
                          max={attr.options_json?.max}
                          step={attr.options_json?.integer_only ? 1 : attr.options_json?.step ?? 'any'}
                          value={val === null || val === undefined ? '' : String(val)}
                          onChange={(e) => updateDraft(attr.definition_public_id, e.target.value)}
                          className="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                        />
                      )}

                      {attr.data_type === 'enum' && (
                        <select
                          id={inputId}
                          value={typeof val === 'string' ? val : ''}
                          onChange={(e) => updateDraft(attr.definition_public_id, e.target.value)}
                          className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2 text-sm text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                        >
                          <option value="">Select an option</option>
                          {choices.map((c) => (
                            <option key={c.value} value={c.value}>
                              {c.label}
                            </option>
                          ))}
                        </select>
                      )}

                      {attr.data_type === 'multi_enum' && (
                        <div className="flex flex-wrap gap-2 pt-1">
                          {choices.map((c) => {
                            const selected = Array.isArray(val) && val.includes(c.value);
                            return (
                              <label
                                key={c.value}
                                className={`inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium ${
                                  selected
                                    ? 'border-brand-300 bg-brand-50 text-brand-800'
                                    : 'border-zinc-200 bg-white text-zinc-700'
                                }`}
                              >
                                <input
                                  type="checkbox"
                                  checked={selected}
                                  onChange={(e) => {
                                    const current = Array.isArray(val) ? val : [];
                                    const next = e.target.checked
                                      ? Array.from(new Set([...current, c.value]))
                                      : current.filter((item) => item !== c.value);
                                    updateDraft(attr.definition_public_id, next);
                                  }}
                                  className="h-3.5 w-3.5 rounded border-zinc-300 text-brand-600"
                                />
                                <span>{c.label}</span>
                              </label>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            </div>
          ))}

          <div className="flex flex-wrap items-center justify-end gap-3 border-t border-zinc-100 pt-4">
            <button
              type="submit"
              disabled={saving}
              className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
            >
              <Save className="h-4 w-4" aria-hidden="true" />
              <span>{saving ? 'Saving...' : 'Save Attributes'}</span>
            </button>
          </div>
        </form>
      )}

      {data && data.orphaned_values.length > 0 && (
        <div
          data-testid="orphaned-attributes-panel"
          className="rounded-xl border border-amber-200 bg-amber-50/70 p-4"
        >
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h3 className="flex items-center gap-1.5 text-sm font-bold text-amber-950">
                <AlertTriangle className="h-4 w-4 text-amber-700" aria-hidden="true" />
                Archived / Category-Changed Values ({data.orphaned_values.length})
              </h3>
              <p className="mt-0.5 text-xs text-amber-900">
                These values are hidden from your public storefront because the business category or schema changed.
              </p>
            </div>
            <button
              type="button"
              onClick={() => handleClearOrphans()}
              disabled={clearingOrphans}
              className="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100 disabled:opacity-50"
            >
              <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
              <span>Clear All Archived</span>
            </button>
          </div>

          <ul className="mt-3 divide-y divide-amber-200/70">
            {data.orphaned_values.map((orphan) => (
              <li key={orphan.public_id} className="flex items-center justify-between gap-3 py-2 text-xs">
                <div>
                  <span className="font-semibold text-amber-950">
                    {orphan.label_en ?? orphan.attribute_key ?? 'Archived Attribute'}
                  </span>
                  <span className="ml-2 text-amber-800">
                    ({orphan.reason === 'category_changed' ? 'Category changed' : 'Definition inactive'})
                  </span>
                  <div className="mt-0.5 text-amber-900">
                    Saved value:{' '}
                    <span className="font-medium">
                      {Array.isArray(orphan.value)
                        ? orphan.value.join(', ')
                        : typeof orphan.value === 'boolean'
                          ? orphan.value
                            ? 'Yes'
                            : 'No'
                          : String(orphan.value ?? '')}
                    </span>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => handleClearOrphans([orphan.public_id])}
                  disabled={clearingOrphans}
                  className="rounded-md border border-amber-300 bg-white px-2.5 py-1 font-semibold text-amber-900 hover:bg-amber-100 disabled:opacity-50"
                >
                  Remove
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
