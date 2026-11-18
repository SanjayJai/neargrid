'use client';

import { useState } from 'react';
import { DemoBanner } from '@/shared/ui/DemoBanner';

interface DaySchedule {
  day: string;
  isClosed: boolean;
  is24Hours: boolean;
  open: string;
  close: string;
}

const INITIAL_SCHEDULE: DaySchedule[] = [
  { day: 'Monday', isClosed: false, is24Hours: false, open: '09:30', close: '20:30' },
  { day: 'Tuesday', isClosed: false, is24Hours: false, open: '09:30', close: '20:30' },
  { day: 'Wednesday', isClosed: false, is24Hours: false, open: '09:30', close: '20:30' },
  { day: 'Thursday', isClosed: false, is24Hours: false, open: '09:30', close: '20:30' },
  { day: 'Friday', isClosed: false, is24Hours: false, open: '09:30', close: '20:30' },
  { day: 'Saturday', isClosed: false, is24Hours: false, open: '09:30', close: '18:00' },
  { day: 'Sunday', isClosed: true, is24Hours: false, open: '10:00', close: '14:00' },
];

export default function OpeningHoursPage() {
  const [schedule, setSchedule] = useState<DaySchedule[]>(INITIAL_SCHEDULE);
  const [savedNotice, setSavedNotice] = useState(false);

  function updateDay(index: number, patch: Partial<DaySchedule>) {
    setSavedNotice(false);
    setSchedule((prev) =>
      prev.map((row, i) => (i === index ? { ...row, ...patch } : row))
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Opening Hours</h1>
        <p className="mt-1 text-sm text-zinc-600">
          Configure weekly availability powering real-time “Open Now” badges in customer search.
        </p>
      </div>

      <DemoBanner description="Schedule changes update local browser state only. Live PUT /api/v1/owner/businesses/{id}/hours persistence (including split shifts & midnight rollovers) connects in Phase 3B/3C." />

      {savedNotice && (
        <div role="status" className="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-800">
          Opening hours preview saved locally.
        </div>
      )}

      <div className="rounded-2xl border border-zinc-200 bg-white p-6">
        <div className="divide-y divide-zinc-100">
          {schedule.map((row, idx) => (
            <div
              key={row.day}
              className="flex flex-col justify-between gap-3 py-4 sm:flex-row sm:items-center"
            >
              <div className="w-32 text-sm font-semibold text-zinc-950">{row.day}</div>

              <div className="flex flex-wrap items-center gap-4 text-xs">
                <label className="inline-flex cursor-pointer items-center gap-1.5 text-zinc-700">
                  <input
                    type="checkbox"
                    checked={row.isClosed}
                    onChange={(e) => updateDay(idx, { isClosed: e.target.checked, is24Hours: false })}
                    className="h-4 w-4 accent-zinc-950"
                  />
                  Closed
                </label>

                <label className="inline-flex cursor-pointer items-center gap-1.5 text-zinc-700">
                  <input
                    type="checkbox"
                    checked={row.is24Hours}
                    disabled={row.isClosed}
                    onChange={(e) => updateDay(idx, { is24Hours: e.target.checked })}
                    className="h-4 w-4 accent-zinc-950"
                  />
                  Open 24h
                </label>
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="time"
                  aria-label={`${row.day} opening time`}
                  disabled={row.isClosed || row.is24Hours}
                  value={row.open}
                  onChange={(e) => updateDay(idx, { open: e.target.value })}
                  className="rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs disabled:bg-zinc-100 disabled:text-zinc-400"
                />
                <span className="text-xs text-zinc-400">to</span>
                <input
                  type="time"
                  aria-label={`${row.day} closing time`}
                  disabled={row.isClosed || row.is24Hours}
                  value={row.close}
                  onChange={(e) => updateDay(idx, { close: e.target.value })}
                  className="rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs disabled:bg-zinc-100 disabled:text-zinc-400"
                />
              </div>
            </div>
          ))}
        </div>

        <div className="mt-5 flex justify-end border-t border-zinc-100 pt-4">
          <button
            type="button"
            onClick={() => setSavedNotice(true)}
            className="rounded-xl bg-zinc-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800"
          >
            Save Preview Hours
          </button>
        </div>
      </div>
    </div>
  );
}
