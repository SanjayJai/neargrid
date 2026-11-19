import type {
  BusinessHourSlotDto,
  BusinessServiceDto,
  PublicBusinessMediaDto,
  PublicBusinessProfileDto,
} from '@/shared/api/directory-types';
import { isValidCoordinatePair } from './search-url';

export const DEFAULT_BUSINESS_TIMEZONE = 'Asia/Kolkata';

const DAY_META: Record<
  number,
  { name: string; shortName: string; schemaDay: string }
> = {
  0: { name: 'Sunday', shortName: 'Sun', schemaDay: 'Sunday' },
  1: { name: 'Monday', shortName: 'Mon', schemaDay: 'Monday' },
  2: { name: 'Tuesday', shortName: 'Tue', schemaDay: 'Tuesday' },
  3: { name: 'Wednesday', shortName: 'Wed', schemaDay: 'Wednesday' },
  4: { name: 'Thursday', shortName: 'Thu', schemaDay: 'Thursday' },
  5: { name: 'Friday', shortName: 'Fri', schemaDay: 'Friday' },
  6: { name: 'Saturday', shortName: 'Sat', schemaDay: 'Saturday' },
};

// Display order Monday -> Sunday (matches standard Indian business directory schedules)
const DISPLAY_DAY_ORDER = [1, 2, 3, 4, 5, 6, 0] as const;

const ALLOWED_SCHEMA_TYPES = new Set([
  'LocalBusiness',
  'Dentist',
  'Hospital',
  'Pharmacy',
  'DiagnosticLab',
  'MedicalClinic',
  'Physician',
  'Restaurant',
  'Bakery',
  'CafeOrCoffeeShop',
  'FoodEstablishment',
  'Plumber',
  'Electrician',
  'HVACBusiness',
  'HomeAndConstructionBusiness',
  'AutoRepair',
  'AutoDealer',
  'School',
  'EducationalOrganization',
  'BeautySalon',
  'HealthAndBeautyBusiness',
  'LegalService',
  'AccountingService',
  'RealEstateAgent',
  'LodgingBusiness',
  'Hotel',
  'Store',
  'GroceryStore',
  'HardwareStore',
  'ElectronicsStore',
  'EventVenue',
  'SportsActivityLocation',
  'TravelAgency',
  'ProfessionalService',
]);

/**
 * Safely serializes JSON-LD to prevent script breakout (`</script>`) and HTML injection.
 */
export function serializeSafeJsonLd(data: Record<string, unknown>): string {
  return JSON.stringify(data)
    .replace(/</g, '\\u003c')
    .replace(/>/g, '\\u003e')
    .replace(/&/g, '\\u0026')
    .replace(/\u2028/g, '\\u2028')
    .replace(/\u2029/g, '\\u2029');
}

/**
 * Validates that a 301 redirect target path returned by Laravel is a safe internal relative path.
 */
export function isSafeInternalRedirectPath(path?: string | null): path is string {
  if (!path) return false;
  const trimmed = path.trim();
  if (!trimmed.startsWith('/') || trimmed.startsWith('//') || trimmed.includes('\\')) {
    return false;
  }
  return /^\/[a-zA-Z0-9/_-]+$/.test(trimmed);
}

/**
 * Checks whether a hostname belongs to an RFC 2606 / RFC 6761 reserved test domain
 * or known placeholder/sample domain (e.g. *.example.in, *.example.com, *.test, *.invalid, *.localhost).
 */
export function isPlaceholderDomain(hostname: string): boolean {
  const host = hostname.toLowerCase();
  return (
    host === 'example.com' ||
    host.endsWith('.example.com') ||
    host === 'example.org' ||
    host.endsWith('.example.org') ||
    host === 'example.net' ||
    host.endsWith('.example.net') ||
    host === 'example.in' ||
    host.endsWith('.example.in') ||
    host === 'example' ||
    host.endsWith('.example') ||
    host.endsWith('.test') ||
    host.endsWith('.invalid') ||
    host === 'localhost' ||
    host.endsWith('.localhost')
  );
}

/**
 * Validates public website URLs to strictly allow http(s) schemes (blocks javascript:/data:).
 * In production environments, placeholder/test domains (e.g. *.example.in, *.example.com)
 * are rejected so that dummy or sample data never surfaces on real public business profiles.
 */
export function getSafeWebsiteUrl(
  raw?: string | null,
  options?: { allowPlaceholders?: boolean }
): string | null {
  if (!raw) return null;
  const trimmed = raw.trim();
  try {
    const parsed = new URL(trimmed);
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
      return null;
    }
    const allowPlaceholders =
      options?.allowPlaceholders ?? process.env.NODE_ENV !== 'production';
    if (!allowPlaceholders && isPlaceholderDomain(parsed.hostname)) {
      return null;
    }
    return parsed.toString();
  } catch {
    return null;
  }
}

/**
 * Returns true if coordinates are valid non-zero WGS-84 coordinates.
 */
export function hasValidBusinessCoordinates(
  coords?: { lat?: number | null; lng?: number | null } | null
): coords is { lat: number; lng: number } {
  if (!coords) return false;
  const { lat, lng } = coords;
  if (!isValidCoordinatePair(lat, lng)) return false;
  if (lat === 0 && lng === 0) return false;
  return true;
}

/**
 * Generates Google Maps directions URL using verified real business coordinates.
 */
export function getGoogleMapsDirectionsUrl(
  coords?: { lat?: number | null; lng?: number | null } | null
): string | null {
  if (!hasValidBusinessCoordinates(coords)) return null;
  const lat = coords.lat.toFixed(6);
  const lng = coords.lng.toFixed(6);
  return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(`${lat},${lng}`)}`;
}

/**
 * Checks whether a media URL is a safe publicly deliverable URL (http/https/relative path).
 */
function isSafePublicMediaUrl(url?: string | null): url is string {
  if (!url) return false;
  const trimmed = url.trim();
  if (trimmed.startsWith('/') && !trimmed.startsWith('//')) return true;
  try {
    const parsed = new URL(trimmed);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:';
  } catch {
    return false;
  }
}

/**
 * Defensively filters public media returned by Laravel to items with valid deliverable public URLs,
 * preserving cover-first ordering and sort_order. Never includes private storage keys.
 */
export function getDeliverablePublicMedia(
  media: PublicBusinessMediaDto[] | null | undefined
): PublicBusinessMediaDto[] {
  if (!Array.isArray(media)) return [];

  const valid = media
    .filter((item) => {
      if (!item || !item.urls) return false;
      return (
        isSafePublicMediaUrl(item.urls.full) ||
        isSafePublicMediaUrl(item.urls.card) ||
        isSafePublicMediaUrl(item.urls.thumb)
      );
    })
    .map((item) => ({
      ...item,
      urls: {
        thumb: isSafePublicMediaUrl(item.urls.thumb) ? item.urls.thumb : null,
        card: isSafePublicMediaUrl(item.urls.card) ? item.urls.card : null,
        full: isSafePublicMediaUrl(item.urls.full) ? item.urls.full : null,
      },
    }));

  const typePriority = (t: PublicBusinessMediaDto['media_type']): number => {
    if (t === 'cover') return 0;
    if (t === 'exterior' || t === 'interior' || t === 'gallery') return 1;
    if (t === 'product' || t === 'menu' || t === 'certificate') return 2;
    if (t === 'logo') return 3;
    return 4;
  };

  return [...valid].sort((a, b) => {
    const pDiff = typePriority(a.media_type) - typePriority(b.media_type);
    if (pDiff !== 0) return pDiff;
    return (a.sort_order ?? 0) - (b.sort_order ?? 0);
  });
}

export function pickBusinessLogoMedia(
  media: PublicBusinessMediaDto[] | null | undefined
): PublicBusinessMediaDto | null {
  const deliverable = getDeliverablePublicMedia(media);
  return deliverable.find((m) => m.media_type === 'logo') ?? null;
}

/**
 * Formats 24h SQL time (`"09:30:00"` or `"09:30"`) into readable 12-hour format (`"9:30 AM"`).
 */
export function formatTime12Hour(timeStr?: string | null): string {
  if (!timeStr) return '';
  const match = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(timeStr.trim());
  if (!match) return timeStr;
  const hour24 = Number(match[1]);
  const minutes = match[2];
  if (!Number.isFinite(hour24) || hour24 < 0 || hour24 > 23) return timeStr;

  const period = hour24 >= 12 ? 'PM' : 'AM';
  const hour12 = hour24 % 12 === 0 ? 12 : hour24 % 12;
  return `${hour12}:${minutes} ${period}`;
}

export interface FormattedDaySchedule {
  dayOfWeek: number;
  dayName: string;
  shortName: string;
  isClosed: boolean;
  is24Hours: boolean;
  intervals: Array<{ open: string; close: string; label: string }>;
  summaryText: string;
}

/**
 * Groups raw business hours into a Mon–Sun weekly schedule supporting split shifts & 24h days.
 */
export function formatWeeklyHours(
  hours: BusinessHourSlotDto[] | null | undefined
): FormattedDaySchedule[] {
  const safeHours = Array.isArray(hours) ? hours : [];

  return DISPLAY_DAY_ORDER.map((day) => {
    const meta = DAY_META[day];
    const daySlots = safeHours
      .filter((s) => s.day_of_week === day)
      .sort((a, b) => a.slot_index - b.slot_index);

    if (daySlots.length === 0) {
      return {
        dayOfWeek: day,
        dayName: meta.name,
        shortName: meta.shortName,
        isClosed: true,
        is24Hours: false,
        intervals: [],
        summaryText: 'Hours not listed',
      };
    }

    if (daySlots.some((s) => s.is_24_hours && !s.is_closed)) {
      return {
        dayOfWeek: day,
        dayName: meta.name,
        shortName: meta.shortName,
        isClosed: false,
        is24Hours: true,
        intervals: [{ open: '00:00', close: '23:59', label: 'Open 24 Hours' }],
        summaryText: 'Open 24 Hours',
      };
    }

    const activeSlots = daySlots.filter(
      (s) => !s.is_closed && s.open_time && s.close_time
    );

    if (activeSlots.length === 0) {
      return {
        dayOfWeek: day,
        dayName: meta.name,
        shortName: meta.shortName,
        isClosed: true,
        is24Hours: false,
        intervals: [],
        summaryText: 'Closed',
      };
    }

    const intervals = activeSlots.map((s) => {
      const open = formatTime12Hour(s.open_time);
      const close = formatTime12Hour(s.close_time);
      return {
        open,
        close,
        label: `${open} – ${close}`,
      };
    });

    return {
      dayOfWeek: day,
      dayName: meta.name,
      shortName: meta.shortName,
      isClosed: false,
      is24Hours: false,
      intervals,
      summaryText: intervals.map((i) => i.label).join(', '),
    };
  });
}

/**
 * Resolves the current day of week (0=Sun..6=Sat) in the business timezone (`Asia/Kolkata`).
 */
export function getTodayDayOfWeekInTimezone(
  timezone = DEFAULT_BUSINESS_TIMEZONE,
  now: Date = new Date()
): number {
  try {
    const weekday = new Intl.DateTimeFormat('en-US', {
      timeZone: timezone,
      weekday: 'short',
    }).format(now);
    const map: Record<string, number> = {
      Sun: 0,
      Mon: 1,
      Tue: 2,
      Wed: 3,
      Thu: 4,
      Fri: 5,
      Sat: 6,
    };
    return map[weekday] ?? now.getDay();
  } catch {
    return now.getDay();
  }
}

export function getTodayHoursSummary(
  hours: BusinessHourSlotDto[] | null | undefined,
  timezone = DEFAULT_BUSINESS_TIMEZONE,
  now: Date = new Date()
): { dayOfWeek: number; dayName: string; summaryText: string } {
  const today = getTodayDayOfWeekInTimezone(timezone, now);
  const weekly = formatWeeklyHours(hours);
  const found = weekly.find((d) => d.dayOfWeek === today);
  return {
    dayOfWeek: today,
    dayName: found?.dayName ?? DAY_META[today]?.name ?? 'Today',
    summaryText: found?.summaryText ?? 'Hours not listed',
  };
}

/**
 * Formats minor-unit service pricing (e.g., paise -> INR) cleanly without inventing figures.
 */
export function formatServicePrice(service: BusinessServiceDto): string {
  const currency = service.currency || 'INR';
  const formatAmount = (minor: number): string => {
    const major = minor / 100;
    try {
      return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency,
        maximumFractionDigits: major % 1 === 0 ? 0 : 2,
      }).format(major);
    } catch {
      return `${currency} ${major.toFixed(major % 1 === 0 ? 0 : 2)}`;
    }
  };

  const hasMin = typeof service.price_min_minor === 'number' && Number.isFinite(service.price_min_minor);
  const hasMax = typeof service.price_max_minor === 'number' && Number.isFinite(service.price_max_minor);

  if (!hasMin && !hasMax) {
    return 'Price on enquiry';
  }

  let base = '';
  if (hasMin && hasMax && service.price_min_minor !== service.price_max_minor) {
    base = `${formatAmount(service.price_min_minor!)} – ${formatAmount(service.price_max_minor!)}`;
  } else if (hasMin) {
    base = formatAmount(service.price_min_minor!);
  } else {
    base = `Up to ${formatAmount(service.price_max_minor!)}`;
  }

  return service.price_unit ? `${base} / ${service.price_unit}` : base;
}

/**
 * Evaluates canonical URL & indexability for `/{city}/business/{slug}` per docs/12-seo-architecture.md.
 * Profiles with `completeness_score < 40` must emit `noindex, follow`.
 */
export function evaluateBusinessProfileSeo(
  profile: PublicBusinessProfileDto,
  fallbackCitySlug: string,
  siteUrl?: string
): {
  canonicalPath: string;
  canonicalUrl: string;
  isIndexable: boolean;
  robots: { index: boolean; follow: boolean };
} {
  const normalizedSiteUrl = (
    siteUrl ??
    process.env.NEXT_PUBLIC_SITE_URL ??
    'http://localhost:3000'
  ).replace(/\/$/, '');

  const citySlug = profile.address.city?.slug ?? fallbackCitySlug;
  const canonicalPath = `/${encodeURIComponent(citySlug)}/business/${encodeURIComponent(profile.slug)}`;
  const isIndexable = (profile.completeness_score ?? 0) >= 40;

  return {
    canonicalPath,
    canonicalUrl: `${normalizedSiteUrl}${canonicalPath}`,
    isIndexable,
    robots: {
      index: isIndexable,
      follow: true,
    },
  };
}

/**
 * Builds Schema.org OpeningHoursSpecification[] from real business hours.
 */
export function buildOpeningHoursSpecification(
  hours: BusinessHourSlotDto[] | null | undefined
): Array<Record<string, unknown>> {
  if (!Array.isArray(hours) || hours.length === 0) return [];

  const specs: Array<Record<string, unknown>> = [];
  for (const slot of hours) {
    if (slot.is_closed) continue;
    const meta = DAY_META[slot.day_of_week];
    if (!meta) continue;

    if (slot.is_24_hours) {
      specs.push({
        '@type': 'OpeningHoursSpecification',
        dayOfWeek: `https://schema.org/${meta.schemaDay}`,
        opens: '00:00',
        closes: '23:59',
      });
    } else if (slot.open_time && slot.close_time) {
      specs.push({
        '@type': 'OpeningHoursSpecification',
        dayOfWeek: `https://schema.org/${meta.schemaDay}`,
        opens: slot.open_time.slice(0, 5),
        closes: slot.close_time.slice(0, 5),
      });
    }
  }

  return specs;
}

/**
 * Builds Schema.org LocalBusiness (or supported schema_org_type) JSON-LD.
 * Strictly omits AggregateRating when review_count < 1, omits unmasked phone numbers,
 * and omits missing geo/hours data rather than fabricating values.
 */
export function buildLocalBusinessJsonLd(
  profile: PublicBusinessProfileDto,
  canonicalUrl: string
): Record<string, unknown> {
  const requestedType = profile.primary_category?.schema_org_type ?? 'LocalBusiness';
  const schemaType = ALLOWED_SCHEMA_TYPES.has(requestedType)
    ? requestedType
    : 'LocalBusiness';

  const deliverableMedia = getDeliverablePublicMedia(profile.media);
  const imageUrls = deliverableMedia
    .map((m) => m.urls.full ?? m.urls.card ?? m.urls.thumb)
    .filter((u): u is string => Boolean(u));

  const jsonLd: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': schemaType,
    name: profile.name,
    url: canonicalUrl,
  };

  if (profile.description && profile.description.trim() !== '') {
    jsonLd.description = profile.description.trim();
  }

  if (imageUrls.length > 0) {
    jsonLd.image = imageUrls;
  }

  const safeWebsite = getSafeWebsiteUrl(profile.contact.website_url);
  if (safeWebsite) {
    jsonLd.sameAs = [safeWebsite];
  }

  const streetParts = [profile.address.line_1, profile.address.line_2]
    .map((s) => s?.trim())
    .filter(Boolean);
  if (
    streetParts.length > 0 ||
    profile.address.city?.name ||
    profile.address.pincode
  ) {
    const postalAddress: Record<string, unknown> = {
      '@type': 'PostalAddress',
      addressCountry: 'IN',
    };
    if (streetParts.length > 0) postalAddress.streetAddress = streetParts.join(', ');
    if (profile.address.locality?.name) {
      postalAddress.addressLocality = profile.address.locality.name;
    } else if (profile.address.city?.name) {
      postalAddress.addressLocality = profile.address.city.name;
    }
    if (profile.address.city?.name) {
      postalAddress.addressRegion = profile.address.city.name;
    }
    if (profile.address.pincode) {
      postalAddress.postalCode = profile.address.pincode;
    }
    jsonLd.address = postalAddress;
  }

  if (hasValidBusinessCoordinates(profile.address.coordinates)) {
    jsonLd.geo = {
      '@type': 'GeoCoordinates',
      latitude: profile.address.coordinates.lat,
      longitude: profile.address.coordinates.lng,
    };
  }

  const openingHoursSpec = buildOpeningHoursSpecification(profile.hours);
  if (openingHoursSpec.length > 0) {
    jsonLd.openingHoursSpecification = openingHoursSpec;
  }

  // Google Structured Data rule: emit AggregateRating ONLY when review_count >= 1 & rating is valid
  if (
    typeof profile.review_count === 'number' &&
    profile.review_count >= 1 &&
    typeof profile.rating_avg === 'number' &&
    Number.isFinite(profile.rating_avg) &&
    profile.rating_avg > 0 &&
    profile.rating_avg <= 5
  ) {
    jsonLd.aggregateRating = {
      '@type': 'AggregateRating',
      ratingValue: Number(profile.rating_avg.toFixed(1)),
      reviewCount: profile.review_count,
      bestRating: 5,
      worstRating: 1,
    };
  }

  return jsonLd;
}

/**
 * Builds Schema.org FAQPage JSON-LD strictly when real FAQs exist.
 */
export function buildFaqPageJsonLd(
  profile: PublicBusinessProfileDto
): Record<string, unknown> | null {
  if (!Array.isArray(profile.faqs) || profile.faqs.length === 0) {
    return null;
  }

  const validFaqs = profile.faqs.filter(
    (faq) => faq.question?.trim() && faq.answer?.trim()
  );
  if (validFaqs.length === 0) {
    return null;
  }

  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: validFaqs.map((faq) => ({
      '@type': 'Question',
      name: faq.question.trim(),
      acceptedAnswer: {
        '@type': 'Answer',
        text: faq.answer.trim(),
      },
    })),
  };
}
