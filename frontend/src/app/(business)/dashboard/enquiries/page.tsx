'use client';

import Link from 'next/link';
import {
  CalendarCheck2,
  Globe,
  Mail,
  MessageCircle,
  Phone,
  Sparkles,
} from 'lucide-react';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';

export default function EnquiryManagementPage() {
  const { activeBusiness } = useOwnerBusiness();

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Customer Enquiries</h1>
        <p className="mt-1 text-sm text-zinc-600">
          Inbound customer leads and direct customer contact channel overview.
        </p>
      </div>

      {/* Honest Phase 3C Lead CRM Notice */}
      <div className="rounded-2xl border border-brand-200 bg-brand-50/50 p-6 sm:p-8">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
          <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-600 text-white shrink-0">
            <CalendarCheck2 className="h-6 w-6" aria-hidden="true" />
          </div>
          <div className="space-y-3">
            <div className="inline-flex items-center gap-1.5 rounded-full border border-brand-300 bg-white px-2.5 py-0.5 text-[11px] font-semibold text-brand-700">
              <Sparkles className="h-3 w-3" aria-hidden="true" />
              Phase 3C Feature Preview
            </div>
            <h2 className="text-lg font-bold text-zinc-950">
              Consented Inbound Lead CRM in Active Development
            </h2>
            <p className="text-xs leading-relaxed text-zinc-700 sm:text-sm max-w-2xl">
              In NearGrid Phase 3C, customers will be able to submit structured quote requests and callback inquiries protected by explicit Digital Personal Data Protection (DPDP) consent and OTP verification.
            </p>
            <div className="rounded-xl border border-brand-200 bg-white p-4 text-xs text-zinc-600 space-y-2">
              <p className="font-semibold text-zinc-900">How customers connect with your business right now:</p>
              <p>
                Customers currently discover your business through our high-performance local directory search and call or message you directly through the verified contact channels displayed on your public storefront.
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Direct Contact Channels configured on active business */}
      {activeBusiness && (
        <section className="rounded-2xl border border-zinc-200 bg-white p-6 space-y-4" aria-labelledby="live-contacts-heading">
          <div className="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
            <div>
              <h2 id="live-contacts-heading" className="text-base font-semibold text-zinc-950">
                Active Direct Contact Channels for {activeBusiness.name}
              </h2>
              <p className="text-xs text-zinc-500">
                Ensure these contact methods are current so customers can reach you immediately.
              </p>
            </div>
            <Link
              href="/dashboard/profile"
              className="inline-flex items-center gap-1 text-xs font-semibold text-brand-700 hover:text-brand-800 hover:underline"
            >
              Update contact info &rarr;
            </Link>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-3.5">
              <div className="flex items-center gap-2 text-zinc-600">
                <Phone className="h-4 w-4" aria-hidden="true" />
                <span className="text-xs font-semibold uppercase tracking-wider">Primary Phone</span>
              </div>
              <p className="mt-2 text-sm font-bold text-zinc-950">
                {activeBusiness.contact.primary_phone_e164 || 'Not configured'}
              </p>
              <p className="mt-0.5 text-[11px] text-zinc-500">Available on Call Now buttons</p>
            </div>

            <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-3.5">
              <div className="flex items-center gap-2 text-zinc-600">
                <MessageCircle className="h-4 w-4" aria-hidden="true" />
                <span className="text-xs font-semibold uppercase tracking-wider">WhatsApp</span>
              </div>
              <p className="mt-2 text-sm font-bold text-zinc-950">
                {activeBusiness.contact.whatsapp_e164 || 'Not configured'}
              </p>
              <p className="mt-0.5 text-[11px] text-zinc-500">Direct chat with customers</p>
            </div>

            <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-3.5">
              <div className="flex items-center gap-2 text-zinc-600">
                <Mail className="h-4 w-4" aria-hidden="true" />
                <span className="text-xs font-semibold uppercase tracking-wider">Email</span>
              </div>
              <p className="mt-2 text-sm font-bold text-zinc-950 truncate">
                {activeBusiness.contact.email || 'Not configured'}
              </p>
              <p className="mt-0.5 text-[11px] text-zinc-500">Inbound inquiries</p>
            </div>

            <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-3.5">
              <div className="flex items-center gap-2 text-zinc-600">
                <Globe className="h-4 w-4" aria-hidden="true" />
                <span className="text-xs font-semibold uppercase tracking-wider">Website</span>
              </div>
              <p className="mt-2 text-sm font-bold text-zinc-950 truncate">
                {activeBusiness.contact.website_url || 'Not configured'}
              </p>
              <p className="mt-0.5 text-[11px] text-zinc-500">Official website clickout</p>
            </div>
          </div>
        </section>
      )}
    </div>
  );
}
