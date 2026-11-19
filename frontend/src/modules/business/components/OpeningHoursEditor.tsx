'use client';

import { FormEvent, useEffect, useState } from 'react';
import {
  AlertTriangle,
  Check,
  Copy,
  Loader2,
  Plus,
  Save,
  Trash2,
} from 'lucide-react';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';
import { bffFetch } from '@/shared/api/bff-client';
import type {
  OwnerHoursUpdateResponseDto,
} from '@/shared/api/directory-types';

interface DayConfig {
  dayOfWeek: number; // 0 = Sun, 1 = Mon ... 6 = Sat
  name: string;
  isClosed: boolean;
  is24Hours: boolean;
  shift1Open: string;
  shift1Close: string;
  hasSplitShift: boolean;
  shift2Open: string;
  shift2Close: string;
}

const DAYS_ORDER: Array<{ dayOfWeek: number; name: string }> = [
  { dayOfWeek: 1, name: 'Monday' },
  { dayOfWeek: 2, name: 'Tuesday' },
  { dayOfWeek: 3, name: 'Wednesday' },
  { dayOfWeek: 4, name: 'Thursday' },
  { dayOfWeek: 5, name: 'Friday' },
  { dayOfWeek: 6, name: 'Saturday' },
  { dayOfWeek: 0, name: 'Sunday' },
];

function defaultSchedule(): DayConfig[] {
  return DAYS_ORDER.map((d) => ({
    dayOfWeek: d.dayOfWeek,
    name: d.name,
    isClosed: d.dayOfWeek === 0, // Sunday closed by default
    is24Hours: false,
    shift1Open: '09:00',
    shift1Close: '18:00',
    hasSplitShift: false,
    shift2Open: '18:30',
    shift2Close: '21:00',
  }));
}

export function OpeningHoursEditor() {
  const { activeBusiness, updateActiveBusiness } = useOwnerBusiness();
  const [schedule, setSchedule] = useState<DayConfig[]>(defaultSchedule());
  const [saving, setSaving] = useState(false);
  const [statusMessage, setStatusMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Initialize schedule from activeBusiness.hours
  useEffect(() => {
    if (!activeBusiness?.hours || activeBusiness.hours.length === 0) return;

    const loaded = DAYS_ORDER.map((dayDef) => {
      const slots = activeBusiness.hours.filter((h) => h.day_of_week === dayDef.dayOfWeek);
      const slot1 = slots.find((s) => s.slot_index === 1) || slots[0];
      const slot2 = slots.find((s) => s.slot_index === 2);

      const isClosed = slot1?.is_closed ?? false;
      const is24Hours = slot1?.is_24_hours ?? false;

      return {
        dayOfWeek: dayDef.dayOfWeek,
        name: dayDef.name,
        isClosed,
        is24Hours,
        shift1Open: slot1?.open_time ? slot1.open_time.slice(0, 5) : '09:00',
        shift1Close: slot1?.close_time ? slot1.close_time.slice(0, 5) : '18:00',
        hasSplitShift: Boolean(slot2 && !isClosed && !is24Hours),
        shift2Open: slot2?.open_time ? slot2.open_time.slice(0, 5) : '18:30',
        shift2Close: slot2?.close_time ? slot2.close_time.slice(0, 5) : '21:00',
      };
    });

    setSchedule(loaded);
  }, [activeBusiness]);

  if (!activeBusiness) {
    return (
      <div className="rounded-2xl border border-zinc-200 bg-white p-8 text-center text-xs text-zinc-500">
        Please select or create a business listing to manage opening hours.
      </div>
    );
  }

  function updateDay(dayOfWeek: number, patch: Partial<DayConfig>) {
    setStatusMessage(null);
    setSchedule((prev) =>
      prev.map((d) => (d.dayOfWeek === dayOfWeek ? { ...d, ...patch } : d))
    );
  }

  function copyMondayToWeekdays() {
    const monday = schedule.find((d) => d.dayOfWeek === 1);
    if (!monday) return;

    setSchedule((prev) =>
      prev.map((d) => {
        // Weekdays: Tue (2), Wed (3), Thu (4), Fri (5)
        if (d.dayOfWeek >= 2 && d.dayOfWeek <= 5) {
          return {
            ...d,
            isClosed: monday.isClosed,
            is24Hours: monday.is24Hours,
            shift1Open: monday.shift1Open,
            shift1Close: monday.shift1Close,
            hasSplitShift: monday.hasSplitShift,
            shift2Open: monday.shift2Open,
            shift2Close: monday.shift2Close,
          };
        }
        return d;
      })
    );
    setStatusMessage({
      type: 'success',
      text: 'Copied Monday schedule to Tuesday through Friday. Click Save Changes to apply.',
    });
  }

  async function handleSave(e: FormEvent) {
    e.preventDefault();
    if (!activeBusiness) return;

    setSaving(true);
    setStatusMessage(null);

    // Build payload: array of BusinessHourSlotDto
    const slotsPayload: Array<{
      day_of_week: number;
      slot_index: number;
      is_closed: boolean;
      is_24_hours: boolean;
      open_time: string | null;
      close_time: string | null;
    }> = [];

    for (const day of schedule) {
      if (day.isClosed) {
        slotsPayload.push({
          day_of_week: day.dayOfWeek,
          slot_index: 1,
          is_closed: true,
          is_24_hours: false,
          open_time: null,
          close_time: null,
        });
      } else if (day.is24Hours) {
        slotsPayload.push({
          day_of_week: day.dayOfWeek,
          slot_index: 1,
          is_closed: false,
          is_24_hours: true,
          open_time: null,
          close_time: null,
        });
      } else {
        // Shift 1
        slotsPayload.push({
          day_of_week: day.dayOfWeek,
          slot_index: 1,
          is_closed: false,
          is_24_hours: false,
          open_time: day.shift1Open,
          close_time: day.shift1Close,
        });

        // Shift 2 (split shift)
        if (day.hasSplitShift) {
          slotsPayload.push({
            day_of_week: day.dayOfWeek,
            slot_index: 2,
            is_closed: false,
            is_24_hours: false,
            open_time: day.shift2Open,
            close_time: day.shift2Close,
          });
        }
      }
    }

    try {
      const res = await bffFetch<OwnerHoursUpdateResponseDto>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/hours`,
        {
          method: 'PUT',
          body: { hours: slotsPayload },
        }
      );

      if (res.ok && res.payload?.status === 'success') {
        const result = res.payload.data;
        updateActiveBusiness({
          ...activeBusiness,
          hours: result.hours,
          completeness_score: result.completeness_score,
        });
        setStatusMessage({
          type: 'success',
          text: `Weekly opening hours saved successfully. Completeness score is now ${result.completeness_score}%.`,
        });
      } else {
        const err = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to update opening hours.';
        setStatusMessage({
          type: 'error',
          text: err,
        });
      }
    } catch {
      setStatusMessage({ type: 'error', text: 'Network error while saving opening hours.' });
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Opening Hours</h1>
          <p className="mt-1 text-sm text-zinc-600">
            Configure weekly business hours powering real-time “Open Now” badges and customer search filters.
          </p>
        </div>

        <button
          type="button"
          onClick={copyMondayToWeekdays}
          className="inline-flex items-center gap-1.5 rounded-xl border border-zinc-300 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-800 shadow-sm hover:bg-zinc-50"
        >
          <Copy className="h-3.5 w-3.5" aria-hidden="true" />
          <span>Copy Mon &rarr; Weekdays</span>
        </button>
      </div>

      {statusMessage && (
        <div
          role={statusMessage.type === 'error' ? 'alert' : 'status'}
          className={`flex items-center gap-2 rounded-xl border p-4 text-xs font-medium ${
            statusMessage.type === 'success'
              ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
              : 'border-rose-200 bg-rose-50 text-rose-900'
          }`}
        >
          {statusMessage.type === 'success' ? (
            <Check className="h-4 w-4 text-emerald-600 shrink-0" aria-hidden="true" />
          ) : (
            <AlertTriangle className="h-4 w-4 text-rose-600 shrink-0" aria-hidden="true" />
          )}
          <span>{statusMessage.text}</span>
        </div>
      )}

      <form onSubmit={handleSave} className="rounded-2xl border border-zinc-200 bg-white p-5 sm:p-6 space-y-6">
        <div className="divide-y divide-zinc-200">
          {schedule.map((day) => (
            <div key={day.dayOfWeek} className="py-4 first:pt-0 last:pb-0 space-y-3">
              <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div className="w-28 text-sm font-bold text-zinc-950">{day.name}</div>

                <div className="flex flex-wrap items-center gap-4 text-xs">
                  <label className="inline-flex items-center gap-1.5 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={day.isClosed}
                      onChange={(e) => updateDay(day.dayOfWeek, { isClosed: e.target.checked })}
                      className="h-4 w-4 rounded border-zinc-300 text-rose-600 focus:ring-rose-600"
                    />
                    <span className={day.isClosed ? 'font-semibold text-rose-600' : 'text-zinc-700'}>
                      Closed
                    </span>
                  </label>

                  {!day.isClosed && (
                    <label className="inline-flex items-center gap-1.5 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={day.is24Hours}
                        onChange={(e) => updateDay(day.dayOfWeek, { is24Hours: e.target.checked })}
                        className="h-4 w-4 rounded border-zinc-300 text-brand-600 focus:ring-brand-600"
                      />
                      <span className={day.is24Hours ? 'font-semibold text-brand-600' : 'text-zinc-700'}>
                        Open 24 Hours
                      </span>
                    </label>
                  )}
                </div>
              </div>

              {!day.isClosed && !day.is24Hours && (
                <div className="pl-0 sm:pl-28 space-y-3">
                  {/* Shift 1 */}
                  <div className="flex flex-wrap items-center gap-3 text-xs">
                    <span className="w-14 font-medium text-zinc-500">Shift 1:</span>
                    <div className="flex items-center gap-2">
                      <input
                        type="time"
                        required
                        value={day.shift1Open}
                        onChange={(e) => updateDay(day.dayOfWeek, { shift1Open: e.target.value })}
                        className="rounded-lg border border-zinc-300 px-2.5 py-1 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none"
                      />
                      <span className="text-zinc-400">to</span>
                      <input
                        type="time"
                        required
                        value={day.shift1Close}
                        onChange={(e) => updateDay(day.dayOfWeek, { shift1Close: e.target.value })}
                        className="rounded-lg border border-zinc-300 px-2.5 py-1 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none"
                      />
                    </div>

                    {!day.hasSplitShift && (
                      <button
                        type="button"
                        onClick={() => updateDay(day.dayOfWeek, { hasSplitShift: true })}
                        className="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-600 hover:text-brand-800"
                      >
                        <Plus className="h-3 w-3" aria-hidden="true" />
                        <span>Add Split Shift</span>
                      </button>
                    )}
                  </div>

                  {/* Shift 2 (Split Shift) */}
                  {day.hasSplitShift && (
                    <div className="flex flex-wrap items-center gap-3 text-xs">
                      <span className="w-14 font-medium text-zinc-500">Shift 2:</span>
                      <div className="flex items-center gap-2">
                        <input
                          type="time"
                          required
                          value={day.shift2Open}
                          onChange={(e) => updateDay(day.dayOfWeek, { shift2Open: e.target.value })}
                          className="rounded-lg border border-zinc-300 px-2.5 py-1 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none"
                        />
                        <span className="text-zinc-400">to</span>
                        <input
                          type="time"
                          required
                          value={day.shift2Close}
                          onChange={(e) => updateDay(day.dayOfWeek, { shift2Close: e.target.value })}
                          className="rounded-lg border border-zinc-300 px-2.5 py-1 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none"
                        />
                      </div>
                      <button
                        type="button"
                        onClick={() => updateDay(day.dayOfWeek, { hasSplitShift: false })}
                        className="text-zinc-400 hover:text-rose-600"
                        aria-label={`Remove split shift for ${day.name}`}
                      >
                        <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                      </button>
                    </div>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>

        <div className="flex items-center justify-end gap-3 border-t border-zinc-100 pt-4">
          <button
            type="submit"
            disabled={saving}
            className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-50"
          >
            {saving ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                <span>Saving Schedule...</span>
              </>
            ) : (
              <>
                <Save className="h-4 w-4" aria-hidden="true" />
                <span>Save Opening Hours</span>
              </>
            )}
          </button>
        </div>
      </form>
    </div>
  );
}
