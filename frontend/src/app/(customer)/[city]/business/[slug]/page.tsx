import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import {
  CheckCircle2,
  ChevronRight,
  Clock,
  CreditCard,
  MapPin,
  MessageSquare,
  Phone,
  ShieldCheck,
  Star,
} from 'lucide-react';
import { EnquiryConsentPreview } from '@/modules/customer/components/EnquiryConsentPreview';
import { DEMO_BUSINESSES, findDemoBusiness } from '@/shared/data/demo-directory';
import { Badge } from '@/shared/ui/Badge';
import { DemoBanner } from '@/shared/ui/DemoBanner';

interface BusinessProfilePageProps {
  params: {
    city: string;
    slug: string;
  };
}

export function generateMetadata({ params }: BusinessProfilePageProps): Metadata {
  const business = findDemoBusiness(params.city, params.slug);
  if (!business) {
    return { title: 'Business Not Found' };
  }

  return {
    title: `${business.name} — ${business.localityName}, ${business.cityName}`,
    description: business.description,
  };
}

export default function BusinessProfilePage({ params }: BusinessProfilePageProps) {
  const business = findDemoBusiness(params.city, params.slug) ?? DEMO_BUSINESSES[0];
  if (!findDemoBusiness(params.city, params.slug) && params.slug !== DEMO_BUSINESSES[0].slug) {
    notFound();
  }

  return (
    <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      {/* Breadcrumb */}
      <nav aria-label="Breadcrumb" className="mb-5 flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
        <Link href="/" className="hover:text-zinc-950">Home</Link>
        <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
        <Link href={`/search?city=${business.citySlug}`} className="hover:text-zinc-950">
          {business.cityName}
        </Link>
        <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
        <Link href={`/search?city=${business.citySlug}&category=${business.categorySlug}`} className="hover:text-zinc-950">
          {business.categoryName}
        </Link>
        <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
        <span className="font-medium text-zinc-900">{business.name}</span>
      </nav>

      <div className="mb-6">
        <DemoBanner description="This profile page displays fictional demo storefront data for Phase 3A visual layout verification. Live business records, media galleries, and reviews will connect in Phase 3B/3C." />
      </div>

      {/* Clean editorial photo mosaic placeholder */}
      <div className="mb-8 grid grid-cols-1 gap-3 md:grid-cols-12">
        <div className={`flex h-56 flex-col justify-end rounded-2xl bg-gradient-to-br p-6 text-white sm:h-64 md:col-span-7 ${business.accentPalette}`}>
          <span className="text-xs font-medium uppercase tracking-wider text-zinc-300">
            Storefront Showcase (Demo Visual)
          </span>
          <p className="mt-1 text-xl font-semibold">{business.name}</p>
        </div>
        <div className="grid grid-cols-2 gap-3 md:col-span-5">
          {['Interior View', 'Service Area', 'Equipment', 'Certifications'].map((label) => (
            <div
              key={label}
              className="flex h-28 items-end rounded-2xl border border-zinc-200 bg-zinc-50 p-4 sm:h-auto"
            >
              <span className="text-xs font-medium text-zinc-600">{label} (Demo)</span>
            </div>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-12">
        {/* Main profile column */}
        <div className="space-y-8 lg:col-span-8">
          <section className="rounded-2xl border border-zinc-200 bg-white p-6">
            <div className="flex flex-wrap items-center gap-2">
              {business.isSponsored && <Badge variant="sponsored">Sponsored</Badge>}
              {business.isVerified && (
                <Badge variant="verified">
                  <CheckCircle2 className="h-3 w-3" aria-hidden="true" />
                  Verified Business
                </Badge>
              )}
              {business.isTrusted && (
                <Badge variant="trusted">
                  <ShieldCheck className="h-3 w-3" aria-hidden="true" />
                  Trusted Partner
                </Badge>
              )}
              <Badge variant="demo">Demo Profile</Badge>
              <Badge variant="neutral">Est. {business.yearEstablished}</Badge>
            </div>

            <h1 className="mt-3 text-3xl font-semibold tracking-tight text-zinc-950">
              {business.name}
            </h1>
            <p className="mt-1 text-base text-zinc-600">{business.tagline}</p>

            <div className="mt-5 flex flex-wrap items-center gap-4 border-y border-zinc-100 py-4 text-sm">
              <span className="inline-flex items-center gap-1.5 rounded-lg bg-zinc-950 px-3 py-1 font-semibold text-white">
                <Star className="h-3.5 w-3.5 fill-current" aria-hidden="true" />
                {business.ratingAvg.toFixed(1)}
                <span className="font-normal text-zinc-300">({business.reviewCount} reviews)</span>
              </span>

              <span className="inline-flex items-center gap-1.5 text-zinc-700">
                <Clock className="h-4 w-4 text-zinc-400" aria-hidden="true" />
                <strong className={business.isOpenNow ? 'text-emerald-700' : 'text-zinc-700'}>
                  {business.hoursSummary}
                </strong>
              </span>

              <span className="inline-flex items-center gap-1.5 text-zinc-600">
                <MapPin className="h-4 w-4 text-zinc-400" aria-hidden="true" />
                {business.localityName}, {business.cityName}
              </span>
            </div>

            <div className="mt-5 flex flex-wrap gap-3">
              <span className="inline-flex items-center gap-2 rounded-xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white">
                <Phone className="h-4 w-4" aria-hidden="true" />
                {business.phoneMasked}
              </span>
              {business.hasWhatsapp && (
                <a
                  href="#enquire"
                  className="inline-flex items-center gap-2 rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold text-zinc-900 hover:bg-zinc-50"
                >
                  <MessageSquare className="h-4 w-4" aria-hidden="true" />
                  Request Callback
                </a>
              )}
            </div>

            <div className="mt-6">
              <h2 className="text-sm font-semibold uppercase tracking-wider text-zinc-500">About</h2>
              <p className="mt-2 text-sm leading-relaxed text-zinc-700">{business.description}</p>
            </div>

            <div className="mt-6 grid grid-cols-1 gap-6 border-t border-zinc-100 pt-5 sm:grid-cols-2">
              <div>
                <h3 className="text-xs font-semibold uppercase tracking-wider text-zinc-500">
                  Amenities
                </h3>
                <div className="mt-2.5 flex flex-wrap gap-1.5">
                  {business.amenities.map((item) => (
                    <Badge key={item} variant="neutral">{item}</Badge>
                  ))}
                </div>
              </div>
              <div>
                <h3 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-zinc-500">
                  <CreditCard className="h-3.5 w-3.5" aria-hidden="true" />
                  Accepted Payments
                </h3>
                <div className="mt-2.5 flex flex-wrap gap-1.5">
                  {business.paymentMethods.map((item) => (
                    <Badge key={item} variant="neutral">{item}</Badge>
                  ))}
                </div>
              </div>
            </div>
          </section>

          {/* Services & pricing */}
          <section className="rounded-2xl border border-zinc-200 bg-white p-6">
            <h2 className="text-lg font-semibold text-zinc-950">Services &amp; Indicative Pricing</h2>
            <div className="mt-4 divide-y divide-zinc-100">
              {business.services.map((service) => (
                <div key={service.id} className="flex flex-col justify-between gap-2 py-3.5 sm:flex-row sm:items-center">
                  <div>
                    <h3 className="text-sm font-semibold text-zinc-950">{service.name}</h3>
                    <p className="text-xs text-zinc-600">{service.description}</p>
                  </div>
                  <span className="shrink-0 text-sm font-semibold text-zinc-950">{service.priceLabel}</span>
                </div>
              ))}
            </div>
          </section>

          {/* Opening hours */}
          <section className="rounded-2xl border border-zinc-200 bg-white p-6">
            <h2 className="text-lg font-semibold text-zinc-950">Opening Hours</h2>
            <dl className="mt-4 divide-y divide-zinc-100 text-sm">
              {business.openingHours.map((item) => (
                <div key={item.day} className="flex items-center justify-between py-2.5">
                  <dt className="font-medium text-zinc-900">{item.day}</dt>
                  <dd className={item.isClosed ? 'text-zinc-400' : 'text-zinc-700'}>{item.slots}</dd>
                </div>
              ))}
            </dl>
          </section>

          {/* Reviews */}
          <section className="rounded-2xl border border-zinc-200 bg-white p-6">
            <div className="flex items-center justify-between">
              <h2 className="text-lg font-semibold text-zinc-950">Customer Reviews</h2>
              <span className="text-xs font-medium text-zinc-500">Demo Review Samples</span>
            </div>

            {business.reviews.length === 0 ? (
              <p className="mt-4 text-sm text-zinc-500">No demo reviews attached to this listing yet.</p>
            ) : (
              <div className="mt-4 space-y-4">
                {business.reviews.map((review) => (
                  <article key={review.id} className="rounded-xl border border-zinc-100 bg-zinc-50/70 p-4">
                    <div className="flex items-center justify-between gap-2">
                      <div>
                        <p className="text-sm font-semibold text-zinc-950">{review.author}</p>
                        <p className="text-xs text-zinc-500">{review.dateLabel}</p>
                      </div>
                      <span className="inline-flex items-center gap-1 rounded-md bg-zinc-950 px-2 py-0.5 text-xs font-semibold text-white">
                        <Star className="h-3 w-3 fill-current" aria-hidden="true" />
                        {review.rating}.0
                      </span>
                    </div>
                    <h3 className="mt-2.5 text-sm font-semibold text-zinc-900">{review.title}</h3>
                    <p className="mt-1 text-sm text-zinc-700">{review.body}</p>
                    {review.ownerReply && (
                      <div className="mt-3 rounded-lg border border-zinc-200 bg-white p-3 text-xs text-zinc-700">
                        <span className="font-semibold text-zinc-950">Owner Response: </span>
                        {review.ownerReply}
                      </div>
                    )}
                  </article>
                ))}
              </div>
            )}
          </section>

          {/* FAQs */}
          {business.faqs.length > 0 && (
            <section className="rounded-2xl border border-zinc-200 bg-white p-6">
              <h2 className="text-lg font-semibold text-zinc-950">Frequently Asked Questions</h2>
              <dl className="mt-4 space-y-4">
                {business.faqs.map((faq) => (
                  <div key={faq.q} className="rounded-xl border border-zinc-100 bg-zinc-50 p-4">
                    <dt className="text-sm font-semibold text-zinc-950">{faq.q}</dt>
                    <dd className="mt-1 text-sm text-zinc-600">{faq.a}</dd>
                  </div>
                ))}
              </dl>
            </section>
          )}
        </div>

        {/* Sticky Sidebar */}
        <aside className="space-y-6 lg:col-span-4">
          <div className="lg:sticky lg:top-24 space-y-6">
            <EnquiryConsentPreview businessName={business.name} />

            <div className="rounded-2xl border border-zinc-200 bg-white p-6">
              <h2 className="text-sm font-semibold uppercase tracking-wider text-zinc-500">
                Location &amp; Address
              </h2>
              <p className="mt-2.5 text-sm leading-relaxed text-zinc-800">
                {business.address} – {business.pincode}
              </p>
              <div className="mt-4 flex h-36 items-center justify-center rounded-xl border border-dashed border-zinc-300 bg-zinc-50 text-xs text-zinc-500">
                Interactive Map Embed (Phase 3B)
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
}
