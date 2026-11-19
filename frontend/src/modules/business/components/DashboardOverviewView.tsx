'use client';

import { useState } from 'react';
import Link from 'next/link';
import {
  ArrowUpRight,
  Building2,
  CalendarCheck2,
  CheckCircle2,
  Clock,
  FileCheck2,
  Images,
  Layers,
  MapPin,
  Phone,
  Plus,
  RefreshCw,
  ShieldAlert,
  ShieldCheck,
  Sparkles,
  Users,
} from 'lucide-react';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';
import { CreateBusinessModal } from '@/modules/business/components/CreateBusinessModal';
import { Badge } from '@/shared/ui/Badge';

export function DashboardOverviewView() {
  const {
    businesses,
    activeBusiness,
    loading,
    error,
    refreshBusinesses,
    setActiveBusinessId,
  } = useOwnerBusiness();

  const [createModalOpen, setCreateModalOpen] = useState(false);

  if (loading) {
    return (
      <div className="space-y-6 animate-pulse" aria-busy="true" aria-label="Loading dashboard">
        <div className="h-8 w-64 rounded-xl bg-zinc-200" />
        <div className="h-32 rounded-2xl bg-zinc-100" />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="h-28 rounded-2xl bg-zinc-100" />
          ))}
        </div>
        <div className="h-64 rounded-2xl bg-zinc-100" />
      </div>
    );
  }

  if (error) {
    return (
      <div role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-rose-950">
        <div className="flex items-start gap-3">
          <ShieldAlert className="mt-0.5 h-5 w-5 text-rose-600 shrink-0" aria-hidden="true" />
          <div className="space-y-2">
            <h2 className="text-base font-semibold">Unable to Load Merchant Dashboard</h2>
            <p className="text-sm text-rose-800">{error}</p>
            <button
              type="button"
              onClick={() => refreshBusinesses()}
              className="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white hover:bg-rose-700"
            >
              <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
              Retry Connection
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (businesses.length === 0 || !activeBusiness) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Dashboard Overview</h1>
          <p className="mt-1 text-sm text-zinc-600">
            Welcome to the NearGrid Merchant Workspace. Connect your local business to start receiving customer discovery.
          </p>
        </div>

        <div className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center sm:p-12">
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
            <Building2 className="h-7 w-7" aria-hidden="true" />
          </div>
          <h2 className="mt-4 text-lg font-bold text-zinc-950">No Business Listings Found</h2>
          <p className="mx-auto mt-1.5 max-w-md text-xs leading-relaxed text-zinc-600 sm:text-sm">
            You do not currently manage any verified business listings. Create your first profile to manage storefront hours, services, photos, team access, and KYC verification.
          </p>
          <div className="mt-6 flex justify-center">
            <button
              type="button"
              onClick={() => setCreateModalOpen(true)}
              className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-700"
            >
              <Plus className="h-4 w-4" aria-hidden="true" />
              <span>Create Your First Listing</span>
            </button>
          </div>
        </div>

        <CreateBusinessModal
          isOpen={createModalOpen}
          onClose={() => setCreateModalOpen(false)}
          onCreated={async (newBiz) => {
            await refreshBusinesses();
            setActiveBusinessId(newBiz.public_id);
          }}
        />
      </div>
    );
  }

  const citySlug = activeBusiness.address.city_slug ?? 'chennai';
  const publicStorefrontHref = `/${citySlug}/business/${activeBusiness.slug}`;
  const activeHoursCount = activeBusiness.hours?.filter((h) => !h.is_closed).length ?? 0;
  const servicesCount = activeBusiness.services?.length ?? 0;

  return (
    <div className="space-y-6">
      {/* Top Banner & Header */}
      <div className="flex flex-col justify-between gap-4 rounded-2xl border border-zinc-200 bg-white p-5 sm:flex-row sm:items-center sm:p-6">
        <div className="space-y-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-bold tracking-tight text-zinc-950 sm:text-2xl">
              {activeBusiness.name}
            </h1>
            <Badge variant={activeBusiness.status === 'active' ? 'open' : 'neutral'}>
              {activeBusiness.status.replace('_', ' ').toUpperCase()}
            </Badge>
            {activeBusiness.is_verified && (
              <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">
                <CheckCircle2 className="h-3 w-3 text-emerald-600" aria-hidden="true" />
                Verified
              </span>
            )}
            {activeBusiness.is_trusted && (
              <span className="inline-flex items-center gap-1 rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-800">
                <ShieldCheck className="h-3 w-3 text-sky-600" aria-hidden="true" />
                Trusted
              </span>
            )}
            {activeBusiness.is_claimed && (
              <span className="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-700">
                Claimed
              </span>
            )}
          </div>

          <p className="text-xs text-zinc-600 sm:text-sm">
            {activeBusiness.tagline || (
              <span className="italic text-zinc-400">No tagline set. Add a brief subtitle in profile settings.</span>
            )}
          </p>

          <div className="flex flex-wrap items-center gap-3 pt-1 text-xs text-zinc-500">
            {activeBusiness.primary_category && (
              <span className="font-medium text-zinc-700">
                {activeBusiness.primary_category.name}
              </span>
            )}
            {activeBusiness.address.locality_name && (
              <>
                <span>·</span>
                <span>{activeBusiness.address.locality_name}, {activeBusiness.address.city_name}</span>
              </>
            )}
            {activeBusiness.year_established && (
              <>
                <span>·</span>
                <span>Est. {activeBusiness.year_established}</span>
              </>
            )}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <Link
            href={publicStorefrontHref}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 rounded-xl border border-zinc-300 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-900 shadow-sm hover:bg-zinc-50"
          >
            <span>Live Storefront</span>
            <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
          <button
            type="button"
            onClick={() => refreshBusinesses()}
            aria-label="Refresh business metrics"
            className="inline-flex items-center justify-center rounded-xl border border-zinc-200 p-2 text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950"
          >
            <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
          </button>
        </div>
      </div>

      {/* Profile Completeness Score Card */}
      <section className="rounded-2xl border border-zinc-200 bg-white p-5 sm:p-6" aria-labelledby="completeness-heading">
        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
          <div>
            <div className="flex items-center gap-2">
              <Sparkles className="h-4 w-4 text-brand-600" aria-hidden="true" />
              <h2 id="completeness-heading" className="text-sm font-bold text-zinc-950 sm:text-base">
                Storefront Profile Completeness: {activeBusiness.completeness_score}%
              </h2>
            </div>
            <p className="mt-1 text-xs text-zinc-600">
              Higher completeness scores improve local search rank and unlock Verified Merchant status.
            </p>
          </div>
          <Link
            href="/dashboard/profile"
            className="inline-flex items-center text-xs font-semibold text-brand-700 hover:text-brand-800 hover:underline"
          >
            Complete profile details &rarr;
          </Link>
        </div>

        <div className="mt-4 h-2.5 w-full overflow-hidden rounded-full bg-zinc-100">
          <div
            className="h-full rounded-full bg-brand-600 transition-all duration-500 ease-out"
            style={{ width: `${Math.min(100, Math.max(0, activeBusiness.completeness_score))}%` }}
          />
        </div>

        <div className="mt-4 grid grid-cols-2 gap-2 text-xs text-zinc-600 sm:grid-cols-3 lg:grid-cols-6">
          <div className="flex items-center gap-1.5">
            <CheckCircle2
              className={`h-3.5 w-3.5 ${
                activeBusiness.name && activeBusiness.primary_category ? 'text-emerald-600' : 'text-zinc-300'
              }`}
            />
            <span>Basic Info</span>
          </div>
          <div className="flex items-center gap-1.5">
            <CheckCircle2
              className={`h-3.5 w-3.5 ${
                activeBusiness.address.line_1 && activeBusiness.address.pincode ? 'text-emerald-600' : 'text-zinc-300'
              }`}
            />
            <span>Full Address</span>
          </div>
          <div className="flex items-center gap-1.5">
            <CheckCircle2
              className={`h-3.5 w-3.5 ${
                activeBusiness.contact.primary_phone_e164 ? 'text-emerald-600' : 'text-zinc-300'
              }`}
            />
            <span>Phone Contact</span>
          </div>
          <div className="flex items-center gap-1.5">
            <CheckCircle2
              className={`h-3.5 w-3.5 ${activeHoursCount > 0 ? 'text-emerald-600' : 'text-zinc-300'}`}
            />
            <span>Weekly Hours</span>
          </div>
          <div className="flex items-center gap-1.5">
            <CheckCircle2
              className={`h-3.5 w-3.5 ${servicesCount > 0 ? 'text-emerald-600' : 'text-zinc-300'}`}
            />
            <span>Service Catalog</span>
          </div>
          <div className="flex items-center gap-1.5">
            <CheckCircle2
              className={`h-3.5 w-3.5 ${activeBusiness.is_verified ? 'text-emerald-600' : 'text-zinc-300'}`}
            />
            <span>KYC Verified</span>
          </div>
        </div>
      </section>

      {/* Real Live Metrics (Zero fake demo data) */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div className="rounded-2xl border border-zinc-200 bg-white p-5">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-zinc-500">Services Offered</span>
            <Layers className="h-4 w-4 text-zinc-400" aria-hidden="true" />
          </div>
          <p className="mt-3 text-2xl font-semibold tracking-tight text-zinc-950">
            {servicesCount}
          </p>
          <p className="mt-1 text-xs text-zinc-500">
            {servicesCount === 0 ? 'No services added yet' : 'Active catalog offerings'}
          </p>
        </div>

        <div className="rounded-2xl border border-zinc-200 bg-white p-5">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-zinc-500">Weekly Schedule</span>
            <Clock className="h-4 w-4 text-zinc-400" aria-hidden="true" />
          </div>
          <p className="mt-3 text-2xl font-semibold tracking-tight text-zinc-950">
            {activeHoursCount > 0 ? `${activeHoursCount} Days` : 'Not Set'}
          </p>
          <p className="mt-1 text-xs text-zinc-500">
            {activeHoursCount > 0 ? 'Powers real-time Open Now badge' : 'Configure operating hours'}
          </p>
        </div>

        <div className="rounded-2xl border border-zinc-200 bg-white p-5">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-zinc-500">Verification Status</span>
            <FileCheck2 className="h-4 w-4 text-zinc-400" aria-hidden="true" />
          </div>
          <p className="mt-3 text-2xl font-semibold tracking-tight text-zinc-950">
            {activeBusiness.is_verified ? 'Verified' : 'Pending KYC'}
          </p>
          <p className="mt-1 text-xs text-zinc-500">
            {activeBusiness.is_verified
              ? `Verified on ${activeBusiness.verified_at ? new Date(activeBusiness.verified_at).toLocaleDateString() : 'Record'}`
              : 'Upload GSTIN or Trade License'}
          </p>
        </div>

        <div className="rounded-2xl border border-zinc-200 bg-white p-5">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-zinc-500">Account Access</span>
            <Users className="h-4 w-4 text-zinc-400" aria-hidden="true" />
          </div>
          <p className="mt-3 text-2xl font-semibold tracking-tight text-zinc-950">
            {activeBusiness.my_membership?.role ? activeBusiness.my_membership.role.toUpperCase() : 'OWNER'}
          </p>
          <p className="mt-1 text-xs text-zinc-500">
            {activeBusiness.my_membership?.is_owner
              ? 'Primary listing owner'
              : 'Authorized business staff'}
          </p>
        </div>
      </div>

      {/* Two Column Layout: Storefront Snapshot & Quick Actions */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        {/* Left Column: Contact & Address Snapshot */}
        <section className="rounded-2xl border border-zinc-200 bg-white p-6 lg:col-span-7 space-y-5">
          <div>
            <h2 className="text-base font-semibold text-zinc-950">Verified Storefront Snapshot</h2>
            <p className="mt-0.5 text-xs text-zinc-500">
              Information presented directly to customers searching on NearGrid.
            </p>
          </div>

          <div className="space-y-4 divide-y divide-zinc-100 text-xs">
            <div className="pt-2">
              <span className="font-semibold text-zinc-700 flex items-center gap-1.5">
                <MapPin className="h-3.5 w-3.5 text-zinc-400" aria-hidden="true" />
                Physical Address
              </span>
              <p className="mt-1 text-zinc-900 leading-relaxed">
                {activeBusiness.address.line_1}
                {activeBusiness.address.line_2 && `, ${activeBusiness.address.line_2}`}
                {activeBusiness.address.landmark && ` (Near ${activeBusiness.address.landmark})`}
                <br />
                {activeBusiness.address.locality_name}, {activeBusiness.address.city_name} — {activeBusiness.address.pincode}
              </p>
              <p className="mt-1 text-zinc-400 text-[11px]">
                Coordinates: {activeBusiness.address.latitude.toFixed(5)}, {activeBusiness.address.longitude.toFixed(5)}
              </p>
            </div>

            <div className="pt-3">
              <span className="font-semibold text-zinc-700 flex items-center gap-1.5">
                <Phone className="h-3.5 w-3.5 text-zinc-400" aria-hidden="true" />
                Customer Contact Channels
              </span>
              <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-2.5">
                  <span className="text-[10px] uppercase font-semibold text-zinc-500">Primary Phone</span>
                  <p className="mt-0.5 text-xs font-semibold text-zinc-950">
                    {activeBusiness.contact.primary_phone_e164}
                  </p>
                </div>
                <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-2.5">
                  <span className="text-[10px] uppercase font-semibold text-zinc-500">WhatsApp</span>
                  <p className="mt-0.5 text-xs font-semibold text-zinc-950">
                    {activeBusiness.contact.whatsapp_e164 || <span className="font-normal text-zinc-400">Not configured</span>}
                  </p>
                </div>
                <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-2.5">
                  <span className="text-[10px] uppercase font-semibold text-zinc-500">Email</span>
                  <p className="mt-0.5 text-xs font-semibold text-zinc-950 truncate">
                    {activeBusiness.contact.email || <span className="font-normal text-zinc-400">Not configured</span>}
                  </p>
                </div>
                <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-2.5">
                  <span className="text-[10px] uppercase font-semibold text-zinc-500">Website</span>
                  <p className="mt-0.5 text-xs font-semibold text-zinc-950 truncate">
                    {activeBusiness.contact.website_url || <span className="font-normal text-zinc-400">Not configured</span>}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* Right Column: Storefront Quick Links */}
        <section className="rounded-2xl border border-zinc-200 bg-white p-6 lg:col-span-5">
          <h2 className="text-base font-semibold text-zinc-950">Merchant Controls</h2>
          <p className="mt-0.5 text-xs text-zinc-500">
            Authoritative actions secured by Laravel Policies.
          </p>

          <ul className="mt-4 space-y-2 text-xs sm:text-sm">
            {[
              {
                href: '/dashboard/profile',
                title: 'Business Profile',
                desc: 'Edit name, description, address & contacts',
                icon: Building2,
              },
              {
                href: '/dashboard/media-services',
                title: 'Photos & Services',
                desc: 'Upload storefront images & manage catalog prices',
                icon: Images,
              },
              {
                href: '/dashboard/hours',
                title: 'Opening Hours',
                desc: 'Configure weekly shifts & split hours',
                icon: Clock,
              },
              {
                href: '/dashboard/team',
                title: 'Team & Staff',
                desc: 'Manage staff access, roles & permissions',
                icon: Users,
              },
              {
                href: '/dashboard/verification',
                title: 'Verification & KYC',
                desc: 'Upload trade licenses & download records securely',
                icon: ShieldCheck,
              },
              {
                href: '/dashboard/enquiries',
                title: 'Customer Enquiries',
                desc: 'Consented lead CRM roadmap notice',
                icon: CalendarCheck2,
              },
            ].map((item) => {
              const Icon = item.icon;
              return (
                <li key={item.href}>
                  <Link
                    href={item.href}
                    className="flex items-center justify-between rounded-xl border border-zinc-200 p-3 font-medium text-zinc-800 transition-colors hover:border-zinc-950 hover:bg-zinc-50 hover:text-zinc-950"
                  >
                    <div className="flex items-center gap-3">
                      <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-zinc-100 text-zinc-700">
                        <Icon className="h-4 w-4" aria-hidden="true" />
                      </div>
                      <div>
                        <span className="block font-semibold text-zinc-900">{item.title}</span>
                        <span className="block text-[11px] font-normal text-zinc-500">{item.desc}</span>
                      </div>
                    </div>
                    <ArrowUpRight className="h-4 w-4 text-zinc-400 shrink-0" aria-hidden="true" />
                  </Link>
                </li>
              );
            })}
          </ul>
        </section>
      </div>
    </div>
  );
}
