'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import {
  Award,
  Building2,
  Calendar,
  Camera,
  CheckCircle2,
  Clock,
  CreditCard,
  ExternalLink,
  Globe,
  HelpCircle,
  MapPin,
  MessageCircle,
  Navigation,
  Phone,
  Send,
  ShieldCheck,
  Sparkles,
  Tag,
} from 'lucide-react';
import type { PublicBusinessProfileDto } from '@/shared/api/directory-types';
import { Breadcrumbs, type BreadcrumbItem } from '@/shared/ui/Breadcrumbs';
import { FallbackImage } from '@/shared/ui/FallbackImage';
import { RatingPill } from '@/shared/ui/RatingPill';
import {
  OpenStatusBadge,
  TrustedBadge,
  VerifiedBadge,
} from '@/shared/ui/TrustBadges';
import {
  DEFAULT_BUSINESS_TIMEZONE,
  formatServicePrice,
  formatWeeklyHours,
  getDeliverablePublicMedia,
  getGoogleMapsDirectionsUrl,
  getSafeWebsiteUrl,
  getTodayHoursSummary,
  pickBusinessLogoMedia,
} from '@/modules/customer/lib/business-profile';
import { BusinessClaimModal } from './BusinessClaimModal';
import { BusinessMediaGallery } from './BusinessMediaGallery';
import { BusinessProfileActions } from './BusinessProfileActions';
import { CustomerReviewsSection } from './CustomerReviewsSection';
import { EnquiryModal } from './EnquiryModal';

interface BusinessProfileViewProps {
  profile: PublicBusinessProfileDto;
  citySlug: string;
  canonicalPath: string;
  canonicalUrl: string;
  breadcrumbs: BreadcrumbItem[];
  isAuthenticated: boolean;
  autoOpenClaim?: boolean;
}

type ProfileTabId = 'overview' | 'services' | 'photos' | 'hours' | 'faqs' | 'reviews';

const PROFILE_TABS: ReadonlyArray<{ id: ProfileTabId; label: string }> = [
  { id: 'overview', label: 'Overview' },
  { id: 'services', label: 'Services & Pricing' },
  { id: 'photos', label: 'Photos' },
  { id: 'hours', label: 'Opening Hours' },
  { id: 'faqs', label: 'FAQs' },
  { id: 'reviews', label: 'Reviews' },
];

export function BusinessProfileView({
  profile,
  citySlug,
  canonicalPath,
  canonicalUrl,
  breadcrumbs,
  isAuthenticated,
  autoOpenClaim = false,
}: BusinessProfileViewProps) {
  const [activeTab, setActiveTab] = useState<ProfileTabId>('overview');
  const [lightboxIndex, setLightboxIndex] = useState<number | null>(null);
  const [claimModalOpen, setClaimModalOpen] = useState(
    autoOpenClaim && !profile.is_claimed
  );
  const [contactRevealed, setContactRevealed] = useState(false);
  const [isRevealingContact, setIsRevealingContact] = useState(false);
  const [enquiryModalOpen, setEnquiryModalOpen] = useState(false);

  async function handleContactReveal() {
    if (contactRevealed || isRevealingContact) return;
    setIsRevealingContact(true);
    try {
      let csrfToken: string | null = null;
      try {
        const csrfRes = await fetch('/api/auth/csrf');
        if (csrfRes.ok) {
          const json = await csrfRes.json();
          csrfToken = json?.data?.csrf_token ?? null;
        }
      } catch {
        // ignore network error
      }

      await fetch(`/api/public/businesses/${profile.public_id}/contact-reveal`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(csrfToken ? { 'x-csrf-token': csrfToken } : {}),
        },
        body: JSON.stringify({ channel: 'phone' }),
      });
      setContactRevealed(true);
    } catch {
      setContactRevealed(true);
    } finally {
      setIsRevealingContact(false);
    }
  }

  const deliverableMedia = getDeliverablePublicMedia(profile.media);
  const logoMedia = pickBusinessLogoMedia(profile.media);
  const weeklyHours = formatWeeklyHours(profile.hours);
  const todayHours = getTodayHoursSummary(profile.hours, DEFAULT_BUSINESS_TIMEZONE);
  const activeServices = (profile.services ?? []).filter((s) => s.is_active !== false);
  const safeWebsiteUrl = getSafeWebsiteUrl(profile.contact.website_url);
  const mapsDirectionsUrl = getGoogleMapsDirectionsUrl(profile.address.coordinates);

  const fullAddressParts = [
    profile.address.line_1,
    profile.address.line_2,
    profile.address.locality?.name,
    profile.address.city?.name,
    profile.address.pincode,
  ].filter((part): part is string => Boolean(part && part.trim() !== ''));
  const fullAddress = fullAddressParts.join(', ');

  useEffect(() => {
    if (typeof window === 'undefined' || typeof IntersectionObserver === 'undefined') {
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            const id = entry.target.id as ProfileTabId;
            if (PROFILE_TABS.some((t) => t.id === id)) {
              setActiveTab(id);
            }
          }
        }
      },
      { rootMargin: '-120px 0px -65% 0px', threshold: 0.05 }
    );

    for (const tab of PROFILE_TABS) {
      const el = document.getElementById(tab.id);
      if (el) observer.observe(el);
    }

    return () => observer.disconnect();
  }, []);

  function handleTabSelect(tabId: ProfileTabId) {
    setActiveTab(tabId);
    const el = document.getElementById(tabId);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  return (
    <div className="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
      {/* Breadcrumbs with automated BreadcrumbList JSON-LD */}
      <div className="mb-4">
        <Breadcrumbs items={breadcrumbs} />
      </div>

      {/* Storefront Header Card */}
      <section
        aria-label="Business header"
        className="mb-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
      >
        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
          <div className="flex min-w-0 items-start gap-4">
            {/* Optional Approved Logo Thumbnail */}
            {logoMedia && (
              <div className="relative h-16 w-16 shrink-0 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 sm:h-20 sm:w-20">
                <FallbackImage
                  src={logoMedia.urls.thumb ?? logoMedia.urls.card ?? logoMedia.urls.full}
                  alt={logoMedia.alt_text || `${profile.name} logo`}
                  fallbackLabel={profile.name}
                  className="h-full w-full object-contain p-1"
                />
              </div>
            )}

            <div className="min-w-0 flex-1">
              {/* Trust & Status Badges */}
              <div className="flex flex-wrap items-center gap-1.5">
                {profile.is_verified && <VerifiedBadge />}
                {profile.is_trusted && <TrustedBadge />}
                {profile.is_claimed ? (
                  <span className="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">
                    <Award className="h-3 w-3 text-emerald-600" aria-hidden="true" />
                    Claimed Business
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-ink-muted">
                    Unclaimed Listing
                  </span>
                )}
                {profile.year_established && (
                  <span className="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-ink-muted">
                    <Calendar className="h-3 w-3 text-gray-500" aria-hidden="true" />
                    Est. {profile.year_established}
                  </span>
                )}
                {profile.primary_category && (
                  <Link
                    href={`/${encodeURIComponent(citySlug)}/${encodeURIComponent(profile.primary_category.slug)}`}
                    className="rounded-md bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700 hover:bg-brand-100"
                  >
                    {profile.primary_category.name}
                  </Link>
                )}
              </div>

              <h1 className="mt-2 text-2xl font-bold tracking-tight text-ink sm:text-3xl">
                {profile.name}
              </h1>

              {profile.tagline && (
                <p className="mt-1 text-sm text-ink-muted">{profile.tagline}</p>
              )}

              {/* Rating + Hours + Location Summary Row */}
              <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs sm:text-sm">
                <RatingPill
                  ratingAvg={profile.rating_avg}
                  reviewCount={profile.review_count}
                  size="md"
                />

                <div className="flex flex-wrap items-center gap-2">
                  <OpenStatusBadge isOpenNow={profile.is_open_now} />
                  <span className="text-xs text-ink-muted">
                    <span className="font-semibold text-ink">{todayHours.dayName}:</span>{' '}
                    {todayHours.summaryText}
                  </span>
                </div>
              </div>

              {fullAddress && (
                <p className="mt-2.5 flex items-start gap-1.5 text-xs text-ink-muted sm:text-sm">
                  <MapPin
                    className="mt-0.5 h-4 w-4 shrink-0 text-signal-600"
                    aria-hidden="true"
                  />
                  <span>{fullAddress}</span>
                </p>
              )}
            </div>
          </div>

          {/* Right Header Actions: Save, Share & Copy Link */}
          <div className="flex shrink-0 flex-wrap items-center gap-2 lg:flex-col lg:items-end">
            <BusinessProfileActions
              businessPublicId={profile.public_id}
              businessName={profile.name}
              canonicalUrl={canonicalUrl}
            />
            <button
              type="button"
              onClick={() => setEnquiryModalOpen(true)}
              className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-700 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
            >
              <Send className="h-3.5 w-3.5" aria-hidden="true" />
              <span>Enquire</span>
            </button>
            {!profile.is_claimed && (
              <button
                type="button"
                onClick={() => setClaimModalOpen(true)}
                className="inline-flex items-center gap-1.5 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-600 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
              >
                <Building2 className="h-3.5 w-3.5" aria-hidden="true" />
                <span>Claim This Business</span>
              </button>
            )}
          </div>
        </div>
      </section>

      {/* Approved Public Media Gallery */}
      <section aria-label="Storefront photos" className="mb-5">
        <BusinessMediaGallery
          businessName={profile.name}
          media={profile.media}
          lightboxIndex={lightboxIndex}
          onLightboxChange={setLightboxIndex}
        />
      </section>

      {/* Sticky Section Navigation Tabs */}
      <nav
        aria-label="Business profile sections"
        className="sticky top-16 z-30 mb-6 -mx-4 border-y border-gray-200 bg-white/95 px-4 backdrop-blur-sm sm:mx-0 sm:rounded-xl sm:border sm:px-2 sm:shadow-sm"
      >
        <div
          role="tablist"
          aria-label="Profile sections"
          className="flex items-center gap-1 overflow-x-auto py-1.5"
        >
          {PROFILE_TABS.map((tab) => {
            const isActive = activeTab === tab.id;
            const badgeCount =
              tab.id === 'services'
                ? activeServices.length
                : tab.id === 'photos'
                  ? deliverableMedia.length
                  : tab.id === 'faqs'
                    ? profile.faqs.length
                    : null;

            return (
              <button
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={isActive}
                aria-controls={tab.id}
                onClick={() => handleTabSelect(tab.id)}
                className={`shrink-0 rounded-lg px-3 py-2 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 sm:text-sm ${
                  isActive
                    ? 'bg-brand-600 text-white shadow-2xs'
                    : 'text-ink-muted hover:bg-gray-100 hover:text-ink'
                }`}
              >
                <span>{tab.label}</span>
                {badgeCount !== null && badgeCount > 0 && (
                  <span
                    className={`ml-1.5 rounded-full px-1.5 py-0.5 text-[10px] font-bold ${
                      isActive
                        ? 'bg-white/20 text-white'
                        : 'bg-gray-200 text-ink-muted'
                    }`}
                  >
                    {badgeCount}
                  </span>
                )}
              </button>
            );
          })}
        </div>
      </nav>

      {/* Main 2-Column Storefront Layout */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        {/* Left Main Content Column (8 cols on desktop) */}
        <div className="space-y-6 lg:col-span-8">
          {/* 1. Overview */}
          <section
            id="overview"
            className="scroll-mt-32 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
          >
            <h2 className="text-lg font-bold text-ink">Overview</h2>
            {profile.description ? (
              <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-ink-muted">
                {profile.description}
              </p>
            ) : (
              <p className="mt-3 text-sm text-ink-subtle">
                No business description has been published for {profile.name} yet.
              </p>
            )}

            {/* Categories */}
            {profile.categories.length > 0 && (
              <div className="mt-5 border-t border-gray-100 pt-4">
                <h3 className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-ink-muted">
                  <Tag className="h-3.5 w-3.5 text-brand-600" aria-hidden="true" />
                  Categories
                </h3>
                <div className="mt-2.5 flex flex-wrap gap-2">
                  {profile.categories.map((cat) => (
                    <Link
                      key={cat.public_id}
                      href={`/${encodeURIComponent(citySlug)}/${encodeURIComponent(cat.slug)}`}
                      className={`rounded-lg border px-2.5 py-1 text-xs font-medium transition-colors ${
                        cat.is_primary
                          ? 'border-brand-200 bg-brand-50 text-brand-700 hover:bg-brand-100'
                          : 'border-gray-200 bg-gray-50 text-ink hover:border-brand-300 hover:text-brand-700'
                      }`}
                    >
                      {cat.name}
                    </Link>
                  ))}
                </div>
              </div>
            )}

            {/* Amenities & Accepted Payments */}
            {(profile.amenities.length > 0 || profile.payment_methods.length > 0) && (
              <div className="mt-5 grid grid-cols-1 gap-5 border-t border-gray-100 pt-4 sm:grid-cols-2">
                {profile.amenities.length > 0 && (
                  <div>
                    <h3 className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-ink-muted">
                      <Sparkles className="h-3.5 w-3.5 text-brand-600" aria-hidden="true" />
                      Amenities
                    </h3>
                    <ul className="mt-2.5 flex flex-wrap gap-1.5">
                      {profile.amenities.map((amenity) => (
                        <li
                          key={amenity.slug}
                          className="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-ink"
                        >
                          <CheckCircle2
                            className="h-3 w-3 text-trust-600"
                            aria-hidden="true"
                          />
                          {amenity.name}
                        </li>
                      ))}
                    </ul>
                  </div>
                )}

                {profile.payment_methods.length > 0 && (
                  <div>
                    <h3 className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-ink-muted">
                      <CreditCard className="h-3.5 w-3.5 text-brand-600" aria-hidden="true" />
                      Accepted Payment Methods
                    </h3>
                    <ul className="mt-2.5 flex flex-wrap gap-1.5">
                      {profile.payment_methods.map((pm) => (
                        <li
                          key={pm.slug}
                          className="rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-ink"
                        >
                          {pm.name}
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            )}
          </section>

          {/* 2. Services & Pricing */}
          <section
            id="services"
            className="scroll-mt-32 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
          >
            <div className="flex items-center justify-between gap-2">
              <h2 className="text-lg font-bold text-ink">Services &amp; Pricing</h2>
              {activeServices.length > 0 && (
                <span className="text-xs font-medium text-ink-muted">
                  {activeServices.length} service{activeServices.length === 1 ? '' : 's'}
                </span>
              )}
            </div>

            {activeServices.length === 0 ? (
              <p className="mt-3 text-sm text-ink-subtle">
                This business has not listed individual services or indicative prices yet.
              </p>
            ) : (
              <div className="mt-4 divide-y divide-gray-100">
                {activeServices.map((service) => (
                  <div
                    key={service.public_id}
                    className="flex flex-col justify-between gap-2 py-3.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center"
                  >
                    <div className="min-w-0">
                      <h3 className="text-sm font-semibold text-ink">{service.name}</h3>
                      {service.description && (
                        <p className="mt-0.5 text-xs leading-relaxed text-ink-muted">
                          {service.description}
                        </p>
                      )}
                    </div>
                    <span className="shrink-0 rounded-lg bg-brand-50 px-2.5 py-1 text-xs font-bold text-brand-800 self-start sm:self-center">
                      {formatServicePrice(service)}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </section>

          {/* 3. Photos */}
          <section
            id="photos"
            className="scroll-mt-32 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
          >
            <div className="flex items-center justify-between gap-2">
              <h2 className="text-lg font-bold text-ink">Photos</h2>
              {deliverableMedia.length > 0 && (
                <span className="text-xs font-medium text-ink-muted">
                  {deliverableMedia.length} approved photo{deliverableMedia.length === 1 ? '' : 's'}
                </span>
              )}
            </div>

            {deliverableMedia.length === 0 ? (
              <div className="mt-4 flex items-center gap-3 rounded-xl border border-dashed border-gray-200 bg-gray-50 p-4 text-xs text-ink-muted">
                <Camera className="h-5 w-5 shrink-0 text-gray-400" aria-hidden="true" />
                <span>No approved public photos are available for this business yet.</span>
              </div>
            ) : (
              <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                {deliverableMedia.map((item, idx) => (
                  <button
                    key={item.public_id}
                    type="button"
                    onClick={() => setLightboxIndex(idx)}
                    aria-label={`Open photo ${idx + 1} in fullscreen`}
                    className="group relative aspect-[4/3] overflow-hidden rounded-xl border border-gray-200 bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
                  >
                    <FallbackImage
                      src={item.urls.card ?? item.urls.thumb ?? item.urls.full}
                      alt={item.alt_text || `${profile.name} photo ${idx + 1}`}
                      className="h-full w-full object-cover transition-transform duration-200 group-hover:scale-105"
                    />
                  </button>
                ))}
              </div>
            )}
          </section>

          {/* 4. Opening Hours */}
          <section
            id="hours"
            className="scroll-mt-32 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <Clock className="h-4 w-4 text-brand-600" aria-hidden="true" />
                <h2 className="text-lg font-bold text-ink">Opening Hours</h2>
              </div>
              <div className="flex items-center gap-2">
                <OpenStatusBadge isOpenNow={profile.is_open_now} />
                <span className="text-[11px] text-ink-muted">IST ({DEFAULT_BUSINESS_TIMEZONE})</span>
              </div>
            </div>

            {profile.hours.length === 0 ? (
              <p className="mt-3 text-sm text-ink-subtle">
                Opening hours have not been provided by this business yet.
              </p>
            ) : (
              <dl className="mt-4 divide-y divide-gray-100 text-sm">
                {weeklyHours.map((day) => {
                  const isToday = day.dayOfWeek === todayHours.dayOfWeek;
                  return (
                    <div
                      key={day.dayOfWeek}
                      className={`flex flex-col justify-between gap-1 py-2.5 sm:flex-row sm:items-center ${
                        isToday
                          ? '-mx-2 rounded-lg bg-brand-50/70 px-2 font-semibold'
                          : ''
                      }`}
                    >
                      <dt className="flex items-center gap-2 text-ink">
                        <span>{day.dayName}</span>
                        {isToday && (
                          <span className="rounded bg-brand-600 px-1.5 py-0.5 text-[10px] font-bold uppercase text-white">
                            Today
                          </span>
                        )}
                      </dt>
                      <dd
                        className={
                          day.isClosed
                            ? 'text-xs font-medium text-ink-subtle sm:text-sm'
                            : day.is24Hours
                              ? 'text-xs font-semibold text-trust-700 sm:text-sm'
                              : 'text-xs text-ink sm:text-sm'
                        }
                      >
                        {day.intervals.length > 1 ? (
                          <span className="flex flex-wrap gap-1.5 sm:justify-end">
                            {day.intervals.map((interval, idx) => (
                              <span
                                key={idx}
                                className="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-ink"
                              >
                                {interval.label}
                              </span>
                            ))}
                          </span>
                        ) : (
                          day.summaryText
                        )}
                      </dd>
                    </div>
                  );
                })}
              </dl>
            )}
          </section>

          {/* 5. FAQs */}
          <section
            id="faqs"
            className="scroll-mt-32 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
          >
            <div className="flex items-center gap-2">
              <HelpCircle className="h-4 w-4 text-brand-600" aria-hidden="true" />
              <h2 className="text-lg font-bold text-ink">Frequently Asked Questions</h2>
            </div>

            {profile.faqs.length === 0 ? (
              <p className="mt-3 text-sm text-ink-subtle">
                No frequently asked questions have been published for {profile.name} yet.
              </p>
            ) : (
              <div className="mt-4 divide-y divide-gray-100">
                {profile.faqs.map((faq, idx) => (
                  <details
                    key={`${faq.question}-${idx}`}
                    open={idx === 0}
                    className="group py-3 first:pt-0 last:pb-0"
                  >
                    <summary className="cursor-pointer list-none font-semibold text-sm text-ink hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 rounded">
                      <span className="flex items-center justify-between gap-2">
                        <span>{faq.question}</span>
                        <span className="text-xs text-ink-muted group-open:rotate-180 transition-transform">
                          ▾
                        </span>
                      </span>
                    </summary>
                    <p className="mt-2 text-xs leading-relaxed text-ink-muted sm:text-sm">
                      {faq.answer}
                    </p>
                  </details>
                ))}
              </div>
            )}
          </section>

          {/* 6. Reviews (Live Customer Reviews & Submissions) */}
          <section
            id="reviews"
            className="scroll-mt-32 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
          >
            <CustomerReviewsSection
              businessId={profile.public_id}
              businessName={profile.name}
              citySlug={citySlug}
              initialRatingAvg={profile.rating_avg}
              initialReviewCount={profile.review_count}
            />
          </section>
        </div>

        {/* Right Sticky Contact & Claim Sidebar (4 cols on desktop) */}
        <aside
          aria-label="Contact details and actions"
          className="space-y-5 lg:col-span-4 lg:sticky lg:top-32 lg:self-start"
        >
          {/* Contact & Directions Card */}
          <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 className="text-base font-bold text-ink">Contact &amp; Address</h2>

            <div className="mt-4 space-y-3">
              {/* Privacy-Safe Masked Phone Button */}
              {profile.contact.phone_masked && (
                <button
                  type="button"
                  onClick={handleContactReveal}
                  disabled={isRevealingContact}
                  aria-label={`Masked phone number: ${profile.contact.phone_masked}. Click to record contact intent.`}
                  className="flex w-full items-center justify-between rounded-xl bg-trust-700 px-3.5 py-2.5 text-white transition-opacity hover:opacity-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-trust-500"
                >
                  <span className="inline-flex items-center gap-2 text-sm font-semibold">
                    <Phone className="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{profile.contact.phone_masked}</span>
                  </span>
                  <span className="text-[10px] font-medium uppercase tracking-wider text-trust-100">
                    {contactRevealed ? 'Intent Logged' : 'Masked'}
                  </span>
                </button>
              )}

              {/* WhatsApp Availability Badge (non-interactive; private number never exposed) */}
              {profile.contact.has_whatsapp && (
                <div
                  aria-label="WhatsApp available"
                  className="flex items-center gap-2 rounded-xl border border-trust-200 bg-trust-50 px-3.5 py-2 text-xs font-semibold text-trust-800"
                >
                  <MessageCircle className="h-4 w-4 shrink-0 text-trust-700" aria-hidden="true" />
                  <span>WhatsApp Available</span>
                </div>
              )}

              {/* Public Website Link */}
              {safeWebsiteUrl && (
                <a
                  href={safeWebsiteUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center justify-between gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-xs font-semibold text-brand-700 transition-colors hover:border-brand-300 hover:bg-brand-50"
                >
                  <span className="inline-flex min-w-0 items-center gap-2">
                    <Globe className="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span className="truncate">{safeWebsiteUrl.replace(/^https?:\/\//, '')}</span>
                  </span>
                  <ExternalLink className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
              )}
            </div>

            {/* Structured Address, Landmark & Pincode */}
            <div className="mt-4 space-y-2 border-t border-gray-100 pt-4 text-xs text-ink-muted">
              <div className="flex items-start gap-2">
                <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-signal-600" aria-hidden="true" />
                <div className="space-y-1">
                  <p className="font-medium text-ink leading-relaxed">{fullAddress}</p>
                  {profile.address.landmark && (
                    <p>
                      <span className="font-semibold text-ink">Landmark:</span>{' '}
                      {profile.address.landmark}
                    </p>
                  )}
                  {profile.address.pincode && (
                    <p>
                      <span className="font-semibold text-ink">Pincode:</span>{' '}
                      {profile.address.pincode}
                    </p>
                  )}
                </div>
              </div>

              {mapsDirectionsUrl && (
                <a
                  href={mapsDirectionsUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="mt-3 flex w-full items-center justify-center gap-1.5 rounded-xl border border-brand-200 bg-brand-50 px-3.5 py-2.5 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-600 hover:text-white"
                >
                  <Navigation className="h-3.5 w-3.5" aria-hidden="true" />
                  <span>Get Directions on Google Maps</span>
                  <ExternalLink className="h-3 w-3" aria-hidden="true" />
                </a>
              )}
            </div>
          </div>

          {/* Direct Customer Enquiry Card */}
          <div className="rounded-2xl border border-brand-200 bg-white p-5 shadow-sm">
            <div className="flex items-start gap-2.5">
              <Send className="mt-0.5 h-5 w-5 shrink-0 text-brand-600" aria-hidden="true" />
              <div className="min-w-0 flex-1">
                <h2 className="text-sm font-bold text-ink">Send Direct Enquiry</h2>
                <p className="mt-1 text-xs text-ink-muted leading-relaxed">
                  Have questions or need pricing? Send your requirement directly to {profile.name}. Privacy protected with verified OTP.
                </p>
                <button
                  type="button"
                  onClick={() => setEnquiryModalOpen(true)}
                  className="mt-3.5 flex w-full items-center justify-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-700 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
                >
                  <Send className="h-3.5 w-3.5" aria-hidden="true" />
                  <span>Enquire Now</span>
                </button>
              </div>
            </div>
          </div>

          {/* Claim This Business Card (only when unclaimed) */}
          {!profile.is_claimed ? (
            <div className="rounded-2xl border border-brand-200 bg-brand-50/60 p-5 shadow-sm">
              <div className="flex items-start gap-2.5">
                <Building2
                  className="mt-0.5 h-5 w-5 shrink-0 text-brand-700"
                  aria-hidden="true"
                />
                <div>
                  <h2 className="text-sm font-bold text-ink">Own this business?</h2>
                  <p className="mt-1 text-xs leading-relaxed text-ink-muted">
                    Submit an ownership claim to manage storefront details, hours, photos, and
                    services after admin verification.
                  </p>
                  <button
                    type="button"
                    onClick={() => setClaimModalOpen(true)}
                    className="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
                  >
                    <ShieldCheck className="h-4 w-4" aria-hidden="true" />
                    <span>Claim This Business</span>
                  </button>
                </div>
              </div>
            </div>
          ) : (
            <div className="rounded-2xl border border-gray-200 bg-white p-4 text-xs text-ink-muted shadow-sm">
              <div className="flex items-center gap-2 font-semibold text-ink">
                <ShieldCheck className="h-4 w-4 text-trust-700" aria-hidden="true" />
                <span>Claimed Listing</span>
              </div>
              <p className="mt-1 leading-relaxed">
                This business listing is managed by a claimed NearGrid merchant account.
              </p>
            </div>
          )}
        </aside>
      </div>

      {/* Live Claim This Business Modal */}
      {!profile.is_claimed && (
        <BusinessClaimModal
          isOpen={claimModalOpen}
          onClose={() => setClaimModalOpen(false)}
          businessPublicId={profile.public_id}
          businessName={profile.name}
          profilePath={canonicalPath}
          isAuthenticated={isAuthenticated}
        />
      )}

      {/* Customer Direct Enquiry Modal */}
      <EnquiryModal
        isOpen={enquiryModalOpen}
        onClose={() => setEnquiryModalOpen(false)}
        businessName={profile.name}
        businessSlug={profile.slug}
        categorySlug={profile.primary_category?.slug ?? 'general'}
        categoryName={profile.primary_category?.name ?? 'General'}
        citySlug={citySlug}
        cityName={profile.address.city?.name}
        localitySlug={profile.address.locality?.slug}
        localityName={profile.address.locality?.name}
        phoneMasked={profile.contact.phone_masked ?? undefined}
      />
    </div>
  );
}
