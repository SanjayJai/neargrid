'use client';

import { useCallback, useEffect, useState } from 'react';
import {
  AlertCircle,
  Archive,
  ArrowLeft,
  CheckCircle2,
  Clock,
  ExternalLink,
  Inbox,
  Loader2,
  Mail,
  MapPin,
  MessageCircle,
  Phone,
  RefreshCw,
  Save,
  Tag,
  Users,
} from 'lucide-react';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';
import { bffFetch } from '@/shared/api/bff-client';
import type {
  BusinessMemberDto,
  LeadStatusDto,
  OwnerLeadDto,
} from '@/shared/api/directory-types';

type FilterTab = 'all' | 'new' | 'contacted' | 'converted' | 'archived';

const FILTER_TABS: Array<{ id: FilterTab; label: string }> = [
  { id: 'all', label: 'All Leads' },
  { id: 'new', label: 'New' },
  { id: 'contacted', label: 'Contacted' },
  { id: 'converted', label: 'Converted' },
  { id: 'archived', label: 'Archived' },
];

export default function EnquiryManagementPage() {
  const { activeBusiness, loading: businessLoading } = useOwnerBusiness();

  const [leads, setLeads] = useState<OwnerLeadDto[]>([]);
  const [selectedLeadId, setSelectedLeadId] = useState<string | null>(null);
  const [filter, setFilter] = useState<FilterTab>('all');
  const [isLoading, setIsLoading] = useState(true);
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Staff members for assignment dropdown
  const [staffMembers, setStaffMembers] = useState<BusinessMemberDto[]>([]);
  const [notesInput, setNotesInput] = useState('');
  const [isSavingNotes, setIsSavingNotes] = useState(false);
  const [isUpdatingStatus, setIsUpdatingStatus] = useState(false);
  const [isAssigningStaff, setIsAssigningStaff] = useState(false);
  const [actionFeedback, setActionFeedback] = useState<string | null>(null);

  // Mobile list vs detail view toggle
  const [mobileDetailOpen, setMobileDetailOpen] = useState(false);

  const selectedLead = leads.find((l) => l.public_id === selectedLeadId) ?? leads[0] ?? null;

  // Sync internal notes text when selected lead changes
  useEffect(() => {
    if (selectedLead) {
      setNotesInput(selectedLead.merchant_notes || '');
      setActionFeedback(null);
    }
  }, [selectedLeadId, selectedLead]);

  // Fetch staff members for active business
  useEffect(() => {
    if (!activeBusiness?.public_id) return;

    let isMounted = true;
    bffFetch<BusinessMemberDto[]>(`/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/members`)
      .then((res) => {
        if (isMounted && res.ok && res.payload?.status === 'success') {
          setStaffMembers(res.payload.data ?? []);
        }
      })
      .catch(() => {
        // Non-critical; staff assignment will fall back gracefully
      });

    return () => {
      isMounted = false;
    };
  }, [activeBusiness?.public_id]);

  // Load leads
  const fetchLeads = useCallback(
    async (reset = true) => {
      if (!activeBusiness?.public_id) return;

      if (reset) {
        setIsLoading(true);
        setErrorMessage(null);
      } else {
        setIsLoadingMore(true);
      }

      try {
        const query = new URLSearchParams();
        if (filter !== 'all') {
          query.set('status', filter);
        }
        query.set('per_page', '15');
        if (!reset && nextCursor) {
          query.set('cursor', nextCursor);
        }

        const url = `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/leads?${query.toString()}`;
        const res = await bffFetch<OwnerLeadDto[]>(url);

        if (res.ok && res.payload?.status === 'success') {
          const fetchedItems = res.payload.data ?? [];
          const cursor = res.payload.meta?.pagination?.next_cursor ?? null;

          if (reset) {
            setLeads(fetchedItems);
            if (fetchedItems.length > 0) {
              setSelectedLeadId(fetchedItems[0].public_id);
            } else {
              setSelectedLeadId(null);
            }
          } else {
            setLeads((prev) => [...prev, ...fetchedItems]);
          }

          setNextCursor(cursor);
        } else {
          const errMsg =
            res.payload && res.payload.status === 'error'
              ? res.payload.error.message
              : 'Failed to load inbound leads.';
          setErrorMessage(errMsg);
        }
      } catch {
        setErrorMessage('Unable to connect to lead management service.');
      } finally {
        setIsLoading(false);
        setIsLoadingMore(false);
      }
    },
    [activeBusiness?.public_id, filter, nextCursor]
  );

  useEffect(() => {
    fetchLeads(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeBusiness?.public_id, filter]);

  // Update lead status
  async function handleStatusChange(newStatus: LeadStatusDto) {
    if (!activeBusiness?.public_id || !selectedLead || isUpdatingStatus) return;

    setIsUpdatingStatus(true);
    setActionFeedback(null);

    try {
      const res = await bffFetch<OwnerLeadDto>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/leads/${encodeURIComponent(selectedLead.public_id)}`,
        {
          method: 'PATCH',
          body: { lead_status: newStatus },
        }
      );

      if (res.ok && res.payload?.status === 'success') {
        const updated = res.payload.data;
        setLeads((prev) => prev.map((l) => (l.public_id === updated.public_id ? updated : l)));
        setActionFeedback(`Status updated to ${newStatus}.`);
      } else {
        setActionFeedback('Unable to update lead status.');
      }
    } catch {
      setActionFeedback('Network error updating status.');
    } finally {
      setIsUpdatingStatus(false);
    }
  }

  // Save internal notes
  async function handleSaveNotes() {
    if (!activeBusiness?.public_id || !selectedLead || isSavingNotes) return;

    setIsSavingNotes(true);
    setActionFeedback(null);

    try {
      const res = await bffFetch<OwnerLeadDto>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/leads/${encodeURIComponent(selectedLead.public_id)}`,
        {
          method: 'PATCH',
          body: { merchant_notes: notesInput.trim() || null },
        }
      );

      if (res.ok && res.payload?.status === 'success') {
        const updated = res.payload.data;
        setLeads((prev) => prev.map((l) => (l.public_id === updated.public_id ? updated : l)));
        setActionFeedback('Internal notes saved.');
      } else {
        setActionFeedback('Failed to save notes.');
      }
    } catch {
      setActionFeedback('Network error saving notes.');
    } finally {
      setIsSavingNotes(false);
    }
  }

  // Assign staff
  async function handleStaffAssign(memberPublicId: string) {
    if (!activeBusiness?.public_id || !selectedLead || isAssigningStaff) return;

    setIsAssigningStaff(true);
    setActionFeedback(null);

    try {
      const res = await bffFetch<OwnerLeadDto>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/leads/${encodeURIComponent(selectedLead.public_id)}`,
        {
          method: 'PATCH',
          body: { assigned_user_public_id: memberPublicId || null },
        }
      );

      if (res.ok && res.payload?.status === 'success') {
        const updated = res.payload.data;
        setLeads((prev) => prev.map((l) => (l.public_id === updated.public_id ? updated : l)));
        setActionFeedback(memberPublicId ? 'Lead assigned to staff member.' : 'Lead unassigned.');
      } else {
        setActionFeedback('Unable to assign staff member.');
      }
    } catch {
      setActionFeedback('Network error updating assignment.');
    } finally {
      setIsAssigningStaff(false);
    }
  }

  // Contact click: automatically update status from 'new' to 'contacted'
  function handleContactClick() {
    if (selectedLead && selectedLead.lead_status === 'new') {
      handleStatusChange('contacted');
    }
  }

  function getStatusBadge(status: LeadStatusDto) {
    switch (status) {
      case 'new':
        return (
          <span className="inline-flex items-center gap-1 rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">
            <span className="h-1.5 w-1.5 rounded-full bg-blue-600" />
            New
          </span>
        );
      case 'contacted':
        return (
          <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
            <Clock className="h-3 w-3 text-amber-600" aria-hidden="true" />
            Contacted
          </span>
        );
      case 'converted':
        return (
          <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">
            <CheckCircle2 className="h-3 w-3 text-emerald-600" aria-hidden="true" />
            Converted
          </span>
        );
      case 'archived':
        return (
          <span className="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-600">
            <Archive className="h-3 w-3 text-zinc-500" aria-hidden="true" />
            Archived
          </span>
        );
    }
  }

  function getUrgencyBadge(urgency: 'immediate' | 'within_week' | 'flexible') {
    switch (urgency) {
      case 'immediate':
        return (
          <span className="rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 border border-red-200">
            Immediate Need
          </span>
        );
      case 'within_week':
        return (
          <span className="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 border border-amber-200">
            Within a week
          </span>
        );
      case 'flexible':
        return (
          <span className="rounded bg-zinc-50 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 border border-zinc-200">
            Flexible
          </span>
        );
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">
            Inbound Customer Leads &amp; CRM
          </h1>
          <p className="mt-1 text-sm text-zinc-600">
            Live customer requirements matched with{' '}
            <span className="font-semibold text-zinc-900">{activeBusiness?.name || 'your business'}</span>.
          </p>
        </div>

        <button
          type="button"
          onClick={() => fetchLeads(true)}
          disabled={isLoading}
          className="inline-flex items-center gap-1.5 self-start rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-700 shadow-sm transition-colors hover:bg-zinc-50 hover:text-zinc-900 disabled:opacity-50"
        >
          <RefreshCw className={`h-3.5 w-3.5 ${isLoading ? 'animate-spin' : ''}`} aria-hidden="true" />
          <span>Refresh</span>
        </button>
      </div>

      {/* Filter Tabs */}
      <div className="flex flex-wrap items-center gap-1.5 border-b border-zinc-200 pb-3">
        {FILTER_TABS.map((tab) => (
          <button
            key={tab.id}
            type="button"
            onClick={() => setFilter(tab.id)}
            className={`rounded-lg px-3.5 py-1.5 text-xs font-semibold transition-colors ${
              filter === tab.id
                ? 'bg-brand-600 text-white shadow-sm'
                : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Main CRM Grid */}
      {businessLoading || isLoading ? (
        <div className="flex flex-col items-center justify-center rounded-2xl border border-zinc-200 bg-white p-12 text-center">
          <Loader2 className="h-8 w-8 animate-spin text-brand-600" aria-hidden="true" />
          <p className="mt-3 text-xs font-medium text-zinc-500">Loading inbound customer leads...</p>
        </div>
      ) : errorMessage ? (
        <div className="rounded-2xl border border-red-200 bg-red-50 p-6 text-center">
          <AlertCircle className="mx-auto h-8 w-8 text-red-600" aria-hidden="true" />
          <h2 className="mt-2 text-sm font-semibold text-red-950">Unable to load leads</h2>
          <p className="mt-1 text-xs text-red-700">{errorMessage}</p>
          <button
            type="button"
            onClick={() => fetchLeads(true)}
            className="mt-3 rounded-lg bg-red-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-red-700"
          >
            Try Again
          </button>
        </div>
      ) : leads.length === 0 ? (
        <div className="rounded-2xl border border-zinc-200 bg-white p-12 text-center">
          <Inbox className="mx-auto h-12 w-12 text-zinc-300" aria-hidden="true" />
          <h2 className="mt-3 text-base font-semibold text-zinc-900">No leads found in this view</h2>
          <p className="mt-1 text-xs text-zinc-500 max-w-sm mx-auto">
            {filter === 'all'
              ? 'When verified customers submit requirements or request quotes matching your services, their inquiries will appear here.'
              : `No leads currently have the status "${filter}". Try selecting "All Leads" to view all records.`}
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
          {/* Left Column: Lead List (5 cols on desktop, hidden on mobile if detail active) */}
          <div
            className={`space-y-3 lg:col-span-5 ${
              mobileDetailOpen ? 'hidden lg:block' : 'block'
            }`}
          >
            <div className="flex items-center justify-between text-xs text-zinc-500 px-1">
              <span>Showing {leads.length} lead{leads.length === 1 ? '' : 's'}</span>
              <span>Sorted by newest</span>
            </div>

            <div className="space-y-2.5">
              {leads.map((lead) => {
                const isSelected = selectedLead?.public_id === lead.public_id;
                const formattedDate = new Date(lead.created_at).toLocaleDateString('en-IN', {
                  day: 'numeric',
                  month: 'short',
                  hour: '2-digit',
                  minute: '2-digit',
                });

                return (
                  <div
                    key={lead.public_id}
                    onClick={() => {
                      setSelectedLeadId(lead.public_id);
                      setMobileDetailOpen(true);
                    }}
                    role="button"
                    tabIndex={0}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ' ') {
                        setSelectedLeadId(lead.public_id);
                        setMobileDetailOpen(true);
                      }
                    }}
                    className={`relative cursor-pointer rounded-2xl border p-4 text-left transition-all ${
                      isSelected
                        ? 'border-brand-600 bg-brand-50/40 ring-1 ring-brand-600 shadow-sm'
                        : 'border-zinc-200 bg-white hover:border-zinc-300 hover:bg-zinc-50/60'
                    }`}
                  >
                    {!lead.is_read && (
                      <span
                        className="absolute right-3.5 top-3.5 h-2 w-2 rounded-full bg-brand-600"
                        title="Unread lead"
                        aria-label="Unread"
                      />
                    )}

                    <div className="flex items-center justify-between gap-2 pr-4">
                      <h3 className="font-semibold text-zinc-950 text-sm truncate">
                        {lead.enquiry.customer_name}
                      </h3>
                      {getStatusBadge(lead.lead_status)}
                    </div>

                    <p className="mt-1.5 text-xs text-zinc-600 line-clamp-2 leading-relaxed">
                      {lead.enquiry.requirement}
                    </p>

                    <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-2.5 text-[11px] text-zinc-500">
                      <div className="flex items-center gap-1.5">
                        {getUrgencyBadge(lead.enquiry.urgency)}
                        <span className="text-zinc-400">•</span>
                        <span>{lead.enquiry.category.name}</span>
                      </div>
                      <span className="text-zinc-400">{formattedDate}</span>
                    </div>

                    {lead.assigned_user && (
                      <div className="mt-2 flex items-center gap-1 text-[10px] text-zinc-500">
                        <Users className="h-3 w-3 text-zinc-400" aria-hidden="true" />
                        <span>Assigned to: {lead.assigned_user.name || lead.assigned_user.email}</span>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>

            {nextCursor && (
              <button
                type="button"
                onClick={() => fetchLeads(false)}
                disabled={isLoadingMore}
                className="w-full rounded-xl border border-zinc-200 bg-white py-2.5 text-xs font-semibold text-zinc-700 shadow-sm hover:bg-zinc-50 disabled:opacity-50"
              >
                {isLoadingMore ? 'Loading more...' : 'Load More Leads'}
              </button>
            )}
          </div>

          {/* Right Column: Lead Detail (7 cols on desktop, full on mobile when active) */}
          {selectedLead && (
            <div
              className={`space-y-5 lg:col-span-7 ${
                mobileDetailOpen ? 'block' : 'hidden lg:block'
              }`}
            >
              {/* Mobile Back Button */}
              <div className="lg:hidden">
                <button
                  type="button"
                  onClick={() => setMobileDetailOpen(false)}
                  className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline mb-2"
                >
                  <ArrowLeft className="h-3.5 w-3.5" aria-hidden="true" />
                  <span>Back to leads list</span>
                </button>
              </div>

              {/* Main Lead Card */}
              <div className="rounded-2xl border border-zinc-200 bg-white p-5 sm:p-6 shadow-sm space-y-5">
                {/* Header */}
                <div className="flex flex-col gap-2 border-b border-zinc-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <div className="flex items-center gap-2">
                      <h2 className="text-lg font-bold text-zinc-950">
                        {selectedLead.enquiry.customer_name}
                      </h2>
                      {getStatusBadge(selectedLead.lead_status)}
                    </div>
                    <p className="mt-0.5 text-xs text-zinc-500">
                      Lead ID: <span className="font-mono">{selectedLead.public_id}</span>
                    </p>
                  </div>

                  <div className="flex items-center gap-1.5 self-start sm:self-auto">
                    {getUrgencyBadge(selectedLead.enquiry.urgency)}
                    <span className="rounded bg-brand-50 px-2 py-0.5 text-[10px] font-semibold text-brand-700 border border-brand-200 capitalize">
                      {selectedLead.enquiry.enquiry_type} Lead
                    </span>
                  </div>
                </div>

                {/* Direct Customer Contact Actions */}
                <div className="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 space-y-3">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold text-emerald-950">
                      Authorized Customer Contact
                    </span>
                    <span className="text-[10px] font-medium text-emerald-800">
                      DPDP Consented Disclosure
                    </span>
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    <a
                      href={`tel:${selectedLead.enquiry.phone_e164}`}
                      onClick={handleContactClick}
                      className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700 transition-colors"
                    >
                      <Phone className="h-3.5 w-3.5" aria-hidden="true" />
                      <span>Call {selectedLead.enquiry.phone_e164}</span>
                    </a>

                    <a
                      href={`https://wa.me/${selectedLead.enquiry.phone_e164.replace(/\D/g, '')}?text=${encodeURIComponent(
                        `Hi ${selectedLead.enquiry.customer_name}, thank you for your enquiry on NearGrid regarding ${selectedLead.enquiry.category.name}.`
                      )}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      onClick={handleContactClick}
                      className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3.5 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100 transition-colors"
                    >
                      <MessageCircle className="h-3.5 w-3.5 text-emerald-600" aria-hidden="true" />
                      <span>WhatsApp</span>
                      <ExternalLink className="h-3 w-3" aria-hidden="true" />
                    </a>

                    {selectedLead.enquiry.email && (
                      <a
                        href={`mailto:${selectedLead.enquiry.email}?subject=${encodeURIComponent(
                          `Quote from ${activeBusiness?.name || 'NearGrid Merchant'}`
                        )}`}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 transition-colors"
                      >
                        <Mail className="h-3.5 w-3.5" aria-hidden="true" />
                        <span>Email</span>
                      </a>
                    )}
                  </div>
                </div>

                {/* Customer Requirement Details */}
                <div className="space-y-3">
                  <h3 className="text-xs font-bold uppercase tracking-wider text-zinc-500">
                    Requirement &amp; Location
                  </h3>
                  <div className="rounded-xl border border-zinc-100 bg-zinc-50 p-4 text-xs space-y-2.5">
                    <p className="text-zinc-900 leading-relaxed font-medium whitespace-pre-wrap">
                      {selectedLead.enquiry.requirement}
                    </p>

                    <div className="flex flex-wrap items-center gap-4 border-t border-zinc-200/60 pt-2 text-zinc-600">
                      <div className="flex items-center gap-1">
                        <Tag className="h-3.5 w-3.5 text-zinc-400" aria-hidden="true" />
                        <span>Category: {selectedLead.enquiry.category.name}</span>
                      </div>
                      <div className="flex items-center gap-1">
                        <MapPin className="h-3.5 w-3.5 text-zinc-400" aria-hidden="true" />
                        <span>
                          {selectedLead.enquiry.locality?.name
                            ? `${selectedLead.enquiry.locality.name}, ${selectedLead.enquiry.city.name}`
                            : selectedLead.enquiry.city.name}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>

                {/* Workflow Controls: Status & Staff Assignment */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 border-t border-zinc-100 pt-4">
                  {/* Lead Status Select */}
                  <div>
                    <label htmlFor="lead-status-select" className="block text-xs font-semibold text-zinc-700 mb-1.5">
                      Pipeline Status
                    </label>
                    <select
                      id="lead-status-select"
                      value={selectedLead.lead_status}
                      disabled={isUpdatingStatus}
                      onChange={(e) => handleStatusChange(e.target.value as LeadStatusDto)}
                      className="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-medium text-zinc-900 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                    >
                      <option value="new">New</option>
                      <option value="contacted">Contacted</option>
                      <option value="converted">Converted</option>
                      <option value="archived">Archived</option>
                    </select>
                  </div>

                  {/* Staff Assignment */}
                  <div>
                    <label htmlFor="lead-staff-select" className="block text-xs font-semibold text-zinc-700 mb-1.5">
                      Assign to Staff Member
                    </label>
                    <select
                      id="lead-staff-select"
                      value={selectedLead.assigned_user?.public_id || ''}
                      disabled={isAssigningStaff}
                      onChange={(e) => handleStaffAssign(e.target.value)}
                      className="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-medium text-zinc-900 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                    >
                      <option value="">Unassigned</option>
                      {staffMembers
                        .filter((m) => m.user !== null)
                        .map((member) => (
                          <option key={member.public_id} value={member.user!.public_id}>
                            {member.user!.name || member.user!.phone_e164 || 'Staff'} ({member.role})
                          </option>
                        ))}
                    </select>
                  </div>
                </div>

                {/* Internal Merchant Notes */}
                <div className="border-t border-zinc-100 pt-4 space-y-2">
                  <div className="flex items-center justify-between">
                    <label htmlFor="merchant-notes" className="text-xs font-semibold text-zinc-700">
                      Internal Team Notes
                    </label>
                    <span className="text-[10px] text-zinc-400">Private to your team</span>
                  </div>
                  <textarea
                    id="merchant-notes"
                    rows={3}
                    value={notesInput}
                    onChange={(e) => setNotesInput(e.target.value)}
                    placeholder="Add follow-up notes, appointment timings, or customer quote discussion..."
                    className="w-full rounded-lg border border-zinc-300 p-2.5 text-xs text-zinc-900 placeholder:text-zinc-400 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                  />
                  <div className="flex items-center justify-between pt-1">
                    {actionFeedback ? (
                      <span className="text-xs font-medium text-brand-700">{actionFeedback}</span>
                    ) : (
                      <span />
                    )}
                    <button
                      type="button"
                      onClick={handleSaveNotes}
                      disabled={isSavingNotes || notesInput === (selectedLead.merchant_notes || '')}
                      className="inline-flex items-center gap-1 rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-zinc-800 disabled:opacity-40"
                    >
                      {isSavingNotes ? (
                        <>
                          <Loader2 className="h-3 w-3 animate-spin" aria-hidden="true" />
                          <span>Saving...</span>
                        </>
                      ) : (
                        <>
                          <Save className="h-3 w-3" aria-hidden="true" />
                          <span>Save Notes</span>
                        </>
                      )}
                    </button>
                  </div>
                </div>

                {/* Audit & Timestamps Footer */}
                <div className="border-t border-zinc-100 pt-3 text-[11px] text-zinc-400 flex flex-wrap justify-between gap-2">
                  <span>Received: {new Date(selectedLead.created_at).toLocaleString('en-IN')}</span>
                  {selectedLead.contacted_at && (
                    <span>
                      First Contacted: {new Date(selectedLead.contacted_at).toLocaleString('en-IN')}
                    </span>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
