import Link from 'next/link';
import { ArrowUpRight, CheckCircle2, Eye, Inbox, Star } from 'lucide-react';
import { DEMO_BUSINESSES, DEMO_ENQUIRIES } from '@/shared/data/demo-directory';
import { Badge } from '@/shared/ui/Badge';
import { DemoBanner } from '@/shared/ui/DemoBanner';

const DEMO_KPIS = [
  { label: 'Profile Views (30d)', value: '1,420', note: 'Demo metric', icon: Eye },
  { label: 'Consented Enquiries', value: '38', note: 'Demo metric', icon: Inbox },
  { label: 'Average Rating', value: '4.9', note: '214 demo reviews', icon: Star },
  { label: 'Profile Completeness', value: '92%', note: 'Eligible for SEO', icon: CheckCircle2 },
];

export default function DashboardOverviewPage() {
  const sampleBusiness = DEMO_BUSINESSES[0];

  return (
    <div className="space-y-6">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Dashboard Overview</h1>
          <p className="mt-1 text-sm text-zinc-600">
            Visual foundation for merchant analytics, storefront health, and inbound enquiries.
          </p>
        </div>
        <Link
          href={`/${sampleBusiness.citySlug}/business/${sampleBusiness.slug}`}
          className="inline-flex items-center gap-1.5 rounded-xl border border-zinc-300 bg-white px-4 py-2 text-xs font-semibold text-zinc-900 hover:bg-zinc-50"
        >
          Preview Public Profile
          <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
        </Link>
      </div>

      <DemoBanner description="Overview KPIs and enquiries below use fictional demo fixtures. Account & Device Settings (/dashboard/settings) is live-wired to the Laravel 13 Identity API via Next.js BFF." />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {DEMO_KPIS.map((kpi) => {
          const Icon = kpi.icon;
          return (
            <div key={kpi.label} className="rounded-2xl border border-zinc-200 bg-white p-5">
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-zinc-500">{kpi.label}</span>
                <Icon className="h-4 w-4 text-zinc-400" aria-hidden="true" />
              </div>
              <p className="mt-3 text-2xl font-semibold tracking-tight text-zinc-950">{kpi.value}</p>
              <p className="mt-1 text-xs text-zinc-500">{kpi.note}</p>
            </div>
          );
        })}
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <section className="rounded-2xl border border-zinc-200 bg-white p-6 lg:col-span-8">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-semibold text-zinc-950">Recent Consented Enquiries</h2>
            <Link href="/dashboard/enquiries" className="text-xs font-semibold text-zinc-900 hover:underline">
              Manage all
            </Link>
          </div>
          <div className="mt-4 divide-y divide-zinc-100">
            {DEMO_ENQUIRIES.map((enq) => (
              <div key={enq.id} className="flex flex-col justify-between gap-2 py-3.5 sm:flex-row sm:items-center">
                <div>
                  <p className="text-sm font-semibold text-zinc-950">
                    {enq.customerName} <span className="font-normal text-zinc-500">· {enq.locality}</span>
                  </p>
                  <p className="text-xs text-zinc-600">
                    {enq.service} · Consent {enq.consentVersion} · {enq.receivedLabel}
                  </p>
                </div>
                <Badge variant="neutral">{enq.status}</Badge>
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-2xl border border-zinc-200 bg-white p-6 lg:col-span-4">
          <h2 className="text-base font-semibold text-zinc-950">Storefront Quick Links</h2>
          <ul className="mt-4 space-y-2.5 text-sm">
            {[
              { href: '/dashboard/profile', label: 'Edit Business Profile (Demo)' },
              { href: '/dashboard/media-services', label: 'Manage Photos & Services (Demo)' },
              { href: '/dashboard/hours', label: 'Update Opening Hours (Demo)' },
              { href: '/dashboard/settings', label: 'Account, Devices & Sessions (Live API)' },
            ].map((item) => (
              <li key={item.href}>
                <Link
                  href={item.href}
                  className="flex items-center justify-between rounded-xl border border-zinc-200 px-3.5 py-2.5 font-medium text-zinc-800 transition-colors hover:border-zinc-950 hover:text-zinc-950"
                >
                  <span>{item.label}</span>
                  <ArrowUpRight className="h-4 w-4 text-zinc-400" aria-hidden="true" />
                </Link>
              </li>
            ))}
          </ul>
        </section>
      </div>
    </div>
  );
}
