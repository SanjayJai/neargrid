'use client';

import { useState } from 'react';
import { DEMO_ENQUIRIES, type DemoEnquiry } from '@/shared/data/demo-directory';
import { Badge } from '@/shared/ui/Badge';
import { DemoBanner } from '@/shared/ui/DemoBanner';

const STATUSES: Array<'All' | DemoEnquiry['status']> = [
  'All',
  'New',
  'Contacted',
  'Quoted',
  'Converted',
];

export default function EnquiryManagementPage() {
  const [enquiries, setEnquiries] = useState<DemoEnquiry[]>(DEMO_ENQUIRIES);
  const [filter, setFilter] = useState<'All' | DemoEnquiry['status']>('All');

  const visible = filter === 'All'
    ? enquiries
    : enquiries.filter((item) => item.status === filter);

  function updateStatus(id: string, nextStatus: DemoEnquiry['status']) {
    setEnquiries((prev) =>
      prev.map((item) => (item.id === id ? { ...item, status: nextStatus } : item))
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Enquiry Management</h1>
        <p className="mt-1 text-sm text-zinc-600">
          Track explicitly consented customer enquiries and follow-up pipeline stages.
        </p>
      </div>

      <DemoBanner description="Inbound enquiries below are fictional demo leads. Live cursor-paginated Lead CRM (/api/v1/owner/leads) connects in Phase 3C." />

      <div className="flex flex-wrap gap-2">
        {STATUSES.map((status) => (
          <button
            key={status}
            type="button"
            onClick={() => setFilter(status)}
            className={`rounded-full px-3.5 py-1.5 text-xs font-semibold transition-colors ${
              filter === status
                ? 'bg-zinc-950 text-white'
                : 'border border-zinc-200 bg-white text-zinc-700 hover:border-zinc-400'
            }`}
          >
            {status}
          </button>
        ))}
      </div>

      <div className="space-y-4">
        {visible.map((enquiry) => (
          <article key={enquiry.id} className="rounded-2xl border border-zinc-200 bg-white p-5">
            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <h2 className="text-base font-semibold text-zinc-950">{enquiry.customerName}</h2>
                  <Badge variant="neutral">{enquiry.channel}</Badge>
                  <Badge variant="verified">Consent {enquiry.consentVersion}</Badge>
                  <Badge variant="demo">Demo Lead</Badge>
                </div>
                <p className="mt-1 text-sm text-zinc-700">{enquiry.service}</p>
                <p className="mt-1 text-xs text-zinc-500">
                  {enquiry.locality} · {enquiry.phoneMasked} · Urgency: {enquiry.urgency} ·{' '}
                  {enquiry.receivedLabel}
                </p>
                <p className="mt-2 rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-700">
                  <span className="font-semibold text-zinc-900">Notes: </span>
                  {enquiry.notes}
                </p>
              </div>

              <div className="flex items-center gap-2">
                <label htmlFor={`status-${enquiry.id}`} className="sr-only">
                  Update status for {enquiry.customerName}
                </label>
                <select
                  id={`status-${enquiry.id}`}
                  value={enquiry.status}
                  onChange={(e) => updateStatus(enquiry.id, e.target.value as DemoEnquiry['status'])}
                  className="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs font-semibold text-zinc-900"
                >
                  <option value="New">New</option>
                  <option value="Contacted">Contacted</option>
                  <option value="Quoted">Quoted</option>
                  <option value="Converted">Converted</option>
                </select>
              </div>
            </div>
          </article>
        ))}
      </div>
    </div>
  );
}
