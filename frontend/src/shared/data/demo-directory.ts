/**
 * Clearly marked fictional DEMO DATA for Phase 3A Milestone 4 visual foundation.
 * None of these listings represent real-world businesses or live backend Directory API records.
 * Will be replaced by Laravel 13 Directory & Search endpoints in Phase 3B/3C.
 */

export interface DemoCity {
  slug: string;
  name: string;
  nameTa: string;
  state: string;
  localities: string[];
}

export interface DemoCategory {
  slug: string;
  name: string;
  nameTa: string;
  icon: string;
  listingCountDemo: number;
  subcategories: string[];
}

export interface DemoBusinessService {
  id: string;
  name: string;
  description: string;
  priceLabel: string;
}

export interface DemoBusinessHour {
  day: string;
  slots: string;
  isClosed?: boolean;
}

export interface DemoReview {
  id: string;
  author: string;
  rating: number;
  dateLabel: string;
  title: string;
  body: string;
  ownerReply?: string;
}

export interface DemoBusiness {
  publicId: string;
  slug: string;
  citySlug: string;
  cityName: string;
  localityName: string;
  name: string;
  tagline: string;
  description: string;
  categorySlug: string;
  categoryName: string;
  isDemo: true;
  isSponsored: boolean;
  isVerified: boolean;
  isTrusted: boolean;
  yearEstablished: number;
  ratingAvg: number;
  reviewCount: number;
  distanceKm: number;
  isOpenNow: boolean;
  hoursSummary: string;
  address: string;
  pincode: string;
  phoneMasked: string;
  hasWhatsapp: boolean;
  amenities: string[];
  paymentMethods: string[];
  accentPalette: string;
  services: DemoBusinessService[];
  openingHours: DemoBusinessHour[];
  reviews: DemoReview[];
  faqs: { q: string; a: string }[];
}

export interface DemoEnquiry {
  id: string;
  customerName: string;
  phoneMasked: string;
  service: string;
  locality: string;
  urgency: 'Immediate' | 'Within a week' | 'Planned';
  channel: 'Direct Profile' | 'Category Broadcast';
  consentVersion: string;
  receivedLabel: string;
  status: 'New' | 'Contacted' | 'Quoted' | 'Converted';
  notes: string;
}

export const DEMO_CITIES: DemoCity[] = [
  {
    slug: 'chennai',
    name: 'Chennai',
    nameTa: 'சென்னை',
    state: 'Tamil Nadu',
    localities: ['T. Nagar', 'Adyar', 'Anna Nagar', 'Velachery'],
  },
  {
    slug: 'coimbatore',
    name: 'Coimbatore',
    nameTa: 'கோயம்புத்தூர்',
    state: 'Tamil Nadu',
    localities: ['R.S. Puram', 'Gandhipuram', 'Peelamedu'],
  },
  {
    slug: 'madurai',
    name: 'Madurai',
    nameTa: 'மதுரை',
    state: 'Tamil Nadu',
    localities: ['Anna Nagar', 'K.K. Nagar'],
  },
  {
    slug: 'bengaluru',
    name: 'Bengaluru',
    nameTa: 'பெங்களூரு',
    state: 'Karnataka',
    localities: ['Indiranagar', 'Koramangala'],
  },
  {
    slug: 'mumbai',
    name: 'Mumbai',
    nameTa: 'மும்பை',
    state: 'Maharashtra',
    localities: ['Andheri West', 'Bandra West'],
  },
];

export const DEMO_CATEGORIES: DemoCategory[] = [
  {
    slug: 'dentists',
    name: 'Dentists',
    nameTa: 'பல் மருத்துவர்கள்',
    icon: 'stethoscope',
    listingCountDemo: 184,
    subcategories: ['Cosmetic Dentistry', 'Root Canal', 'Orthodontics'],
  },
  {
    slug: 'restaurants',
    name: 'Restaurants',
    nameTa: 'உணவகங்கள்',
    icon: 'utensils',
    listingCountDemo: 412,
    subcategories: ['South Indian', 'Family Dining', 'Cafes'],
  },
  {
    slug: 'ac-repair-services',
    name: 'AC Repair & Service',
    nameTa: 'ஏசி பழுதுபார்ப்பு',
    icon: 'wind',
    listingCountDemo: 129,
    subcategories: ['Split AC Servicing', 'Gas Refill', 'Installation'],
  },
  {
    slug: 'plumbers',
    name: 'Plumbers',
    nameTa: 'குழாய் பழுதுபார்ப்பு',
    icon: 'wrench',
    listingCountDemo: 96,
    subcategories: ['Leak Repair', 'Bathroom Fitting', 'Motor Servicing'],
  },
  {
    slug: 'electricians',
    name: 'Electricians',
    nameTa: 'மின்சார சேவை',
    icon: 'zap',
    listingCountDemo: 114,
    subcategories: ['Home Wiring', 'Inverter Setup', 'Panel Upgrade'],
  },
  {
    slug: 'car-repair-and-service',
    name: 'Car Repair & Service',
    nameTa: 'கார் சர்வீஸ்',
    icon: 'car',
    listingCountDemo: 152,
    subcategories: ['Periodic Service', 'Wheel Alignment', 'Detailing'],
  },
  {
    slug: 'coaching-institutes',
    name: 'Coaching Institutes',
    nameTa: 'பயிற்சி மையங்கள்',
    icon: 'graduation-cap',
    listingCountDemo: 88,
    subcategories: ['Competitive Exams', 'Software Training', 'Languages'],
  },
  {
    slug: 'packers-and-movers',
    name: 'Packers & Movers',
    nameTa: 'பேக்கர்ஸ் & மூவர்ஸ்',
    icon: 'truck',
    listingCountDemo: 74,
    subcategories: ['Home Shifting', 'Office Relocation', 'Storage'],
  },
];

export const DEMO_BUSINESSES: DemoBusiness[] = [
  {
    publicId: 'DEMO-01JCG4M100000000000001',
    slug: 'marina-dental-studio-adyar',
    citySlug: 'chennai',
    cityName: 'Chennai',
    localityName: 'Adyar',
    name: 'Marina Dental Studio (Demo)',
    tagline: 'Preventive, Cosmetic & Implant Dental Practice',
    description:
      'Fictional demo storefront illustrating the NearGrid business profile layout. Features digital X-ray diagnostics, microscopic endodontics, transparent treatment estimates, and flexible evening appointments.',
    categorySlug: 'dentists',
    categoryName: 'Dentists',
    isDemo: true,
    isSponsored: true,
    isVerified: true,
    isTrusted: true,
    yearEstablished: 2015,
    ratingAvg: 4.9,
    reviewCount: 214,
    distanceKm: 1.4,
    isOpenNow: true,
    hoursSummary: 'Open until 8:30 PM',
    address: 'No. 18, 2nd Main Road, Gandhi Nagar, Adyar, Chennai',
    pincode: '600020',
    phoneMasked: '+91-98400-XXXXX',
    hasWhatsapp: true,
    amenities: ['Air Conditioned', 'Digital X-Ray', 'Wheelchair Accessible', 'Parking Available'],
    paymentMethods: ['UPI', 'Credit / Debit Card', 'Cash', 'Insurance Billing'],
    accentPalette: 'from-zinc-900 to-zinc-700',
    services: [
      { id: 'srv-1', name: 'Comprehensive Consultation & Digital X-Ray', description: 'Initial exam with intraoral imaging and care plan.', priceLabel: '₹500' },
      { id: 'srv-2', name: 'Microscopic Root Canal Treatment', description: 'Single or two-sitting painless endodontic procedure.', priceLabel: 'From ₹4,500' },
      { id: 'srv-3', name: 'Clear Aligner Assessment', description: '3D scan and orthodontic timeline review.', priceLabel: '₹1,200' },
    ],
    openingHours: [
      { day: 'Monday', slots: '09:30 AM – 01:30 PM, 04:30 PM – 08:30 PM' },
      { day: 'Tuesday', slots: '09:30 AM – 01:30 PM, 04:30 PM – 08:30 PM' },
      { day: 'Wednesday', slots: '09:30 AM – 01:30 PM, 04:30 PM – 08:30 PM' },
      { day: 'Thursday', slots: '09:30 AM – 01:30 PM, 04:30 PM – 08:30 PM' },
      { day: 'Friday', slots: '09:30 AM – 01:30 PM, 04:30 PM – 08:30 PM' },
      { day: 'Saturday', slots: '09:30 AM – 06:00 PM' },
      { day: 'Sunday', slots: 'Closed', isClosed: true },
    ],
    reviews: [
      {
        id: 'rev-1',
        author: 'Ananya K. (Demo Review)',
        rating: 5,
        dateLabel: '2 weeks ago',
        title: 'Clear explanation and zero waiting time',
        body: 'Booked an evening slot. The dentist walked through the scan clearly and shared an itemized quote before starting.',
        ownerReply: 'Thank you for visiting Marina Dental Studio (Demo). Glad we could make your visit comfortable.',
      },
      {
        id: 'rev-2',
        author: 'Raghav M. (Demo Review)',
        rating: 5,
        dateLabel: '1 month ago',
        title: 'Spotless clinic and attentive staff',
        body: 'Very clean sterilization protocols and courteous follow-up after the procedure.',
      },
    ],
    faqs: [
      { q: 'Do I need a prior appointment?', a: 'Appointments are recommended to minimize wait times, though urgent dental pain walk-ins are accommodated.' },
      { q: 'Are UPI and card payments accepted?', a: 'Yes, UPI, major credit/debit cards, and cash are accepted.' },
    ],
  },
  {
    publicId: 'DEMO-01JCG4M100000000000002',
    slug: 'coromandel-spice-kitchen-t-nagar',
    citySlug: 'chennai',
    cityName: 'Chennai',
    localityName: 'T. Nagar',
    name: 'Coromandel Spice Kitchen (Demo)',
    tagline: 'Regional South Indian & Coastal Family Dining',
    description:
      'Fictional demo restaurant entry showcasing clean listing cards, operating hours, and photo placeholders for Phase 3A visual verification.',
    categorySlug: 'restaurants',
    categoryName: 'Restaurants',
    isDemo: true,
    isSponsored: false,
    isVerified: true,
    isTrusted: false,
    yearEstablished: 2019,
    ratingAvg: 4.6,
    reviewCount: 389,
    distanceKm: 2.8,
    isOpenNow: true,
    hoursSummary: 'Open until 11:00 PM',
    address: 'No. 42, North Usman Road, T. Nagar, Chennai',
    pincode: '600017',
    phoneMasked: '+91-98401-XXXXX',
    hasWhatsapp: true,
    amenities: ['Air Conditioned', 'Family Seating', 'Valet Parking', 'Takeaway'],
    paymentMethods: ['UPI', 'Credit / Debit Card', 'Cash'],
    accentPalette: 'from-neutral-800 to-stone-700',
    services: [
      { id: 'srv-4', name: 'Traditional Banana Leaf Meals', description: 'Seasonal multi-course lunch thali.', priceLabel: '₹320' },
      { id: 'srv-5', name: 'Private Family Dining Table', description: 'Seating for groups of 8–16 guests.', priceLabel: 'No booking fee' },
    ],
    openingHours: [
      { day: 'Monday – Friday', slots: '11:30 AM – 03:30 PM, 06:30 PM – 11:00 PM' },
      { day: 'Saturday – Sunday', slots: '11:30 AM – 11:30 PM' },
    ],
    reviews: [
      {
        id: 'rev-3',
        author: 'Suresh V. (Demo Review)',
        rating: 5,
        dateLabel: '1 week ago',
        title: 'Consistent taste and quick service',
        body: 'Great family dinner atmosphere with polite table service and easy valet parking.',
      },
    ],
    faqs: [
      { q: 'Is parking available on weekends?', a: 'Complimentary valet parking is available throughout lunch and dinner hours.' },
    ],
  },
  {
    publicId: 'DEMO-01JCG4M100000000000003',
    slug: 'breezecraft-hvac-anna-nagar',
    citySlug: 'chennai',
    cityName: 'Chennai',
    localityName: 'Anna Nagar',
    name: 'BreezeCraft AC & Cooling (Demo)',
    tagline: 'Same-Day Split & Inverter AC Repair Specialists',
    description:
      'Fictional demo home-services business showing transparent pricing ranges, emergency callouts, and service areas.',
    categorySlug: 'ac-repair-services',
    categoryName: 'AC Repair & Service',
    isDemo: true,
    isSponsored: false,
    isVerified: true,
    isTrusted: true,
    yearEstablished: 2017,
    ratingAvg: 4.7,
    reviewCount: 162,
    distanceKm: 4.2,
    isOpenNow: true,
    hoursSummary: 'Open until 9:00 PM',
    address: 'Plot 114, 2nd Avenue, Anna Nagar East, Chennai',
    pincode: '600040',
    phoneMasked: '+91-98402-XXXXX',
    hasWhatsapp: true,
    amenities: ['Doorstep Service', '30-Day Service Warranty', 'Verified Technicians'],
    paymentMethods: ['UPI', 'Cash', 'Bank Transfer'],
    accentPalette: 'from-slate-900 to-slate-700',
    services: [
      { id: 'srv-6', name: 'Foam-Jet Split AC Deep Cleaning', description: 'Indoor & outdoor coil jet wash with drainage check.', priceLabel: '₹699' },
      { id: 'srv-7', name: 'Inverter PCB Diagnostic & Repair', description: 'On-site fault inspection and genuine spare replacement.', priceLabel: 'From ₹1,200' },
    ],
    openingHours: [
      { day: 'Monday – Sunday', slots: '08:00 AM – 09:00 PM' },
    ],
    reviews: [],
    faqs: [
      { q: 'Do you provide a warranty on repairs?', a: 'All demo service entries showcase a standard 30-day workmanship guarantee.' },
    ],
  },
  {
    publicId: 'DEMO-01JCG4M100000000000004',
    slug: 'precision-auto-care-velachery',
    citySlug: 'chennai',
    cityName: 'Chennai',
    localityName: 'Velachery',
    name: 'Precision Auto Care Hub (Demo)',
    tagline: 'Multi-Brand Car Diagnostics, Periodic Service & Detailing',
    description:
      'Fictional demo automotive service workshop illustrating filtering by rating, verified status, and locality.',
    categorySlug: 'car-repair-and-service',
    categoryName: 'Car Repair & Service',
    isDemo: true,
    isSponsored: false,
    isVerified: false,
    isTrusted: false,
    yearEstablished: 2021,
    ratingAvg: 4.3,
    reviewCount: 68,
    distanceKm: 6.1,
    isOpenNow: false,
    hoursSummary: 'Opens tomorrow at 9:00 AM',
    address: 'No. 7, Bypass Road, Velachery, Chennai',
    pincode: '600042',
    phoneMasked: '+91-98403-XXXXX',
    hasWhatsapp: false,
    amenities: ['OBD-II Diagnostics', 'Pickup & Drop', 'Customer Lounge'],
    paymentMethods: ['UPI', 'Credit / Debit Card', 'Cash'],
    accentPalette: 'from-zinc-800 to-neutral-900',
    services: [
      { id: 'srv-8', name: 'Periodic Maintenance Package', description: 'Engine oil, filter change, brake & 40-point inspection.', priceLabel: 'From ₹3,499' },
    ],
    openingHours: [
      { day: 'Monday – Saturday', slots: '09:00 AM – 07:30 PM' },
      { day: 'Sunday', slots: 'Closed', isClosed: true },
    ],
    reviews: [],
    faqs: [],
  },
];

export const DEMO_ENQUIRIES: DemoEnquiry[] = [
  {
    id: 'ENQ-DEMO-101',
    customerName: 'Divya S. (Demo)',
    phoneMasked: '+91-98409-XX210',
    service: 'Microscopic Root Canal Treatment',
    locality: 'Adyar',
    urgency: 'Immediate',
    channel: 'Direct Profile',
    consentVersion: 'v1.0',
    receivedLabel: '18 mins ago',
    status: 'New',
    notes: 'Requested evening slot after 6 PM.',
  },
  {
    id: 'ENQ-DEMO-102',
    customerName: 'Prakash N. (Demo)',
    phoneMasked: '+91-98408-XX771',
    service: 'Clear Aligner Assessment',
    locality: 'T. Nagar',
    urgency: 'Within a week',
    channel: 'Category Broadcast',
    consentVersion: 'v1.0',
    receivedLabel: '3 hours ago',
    status: 'Contacted',
    notes: 'Shared consultation fee and available Saturday morning slots.',
  },
  {
    id: 'ENQ-DEMO-103',
    customerName: 'Meenakshi R. (Demo)',
    phoneMasked: '+91-98407-XX094',
    service: 'Comprehensive Consultation & Digital X-Ray',
    locality: 'Velachery',
    urgency: 'Planned',
    channel: 'Direct Profile',
    consentVersion: 'v1.0',
    receivedLabel: 'Yesterday',
    status: 'Quoted',
    notes: 'Awaiting confirmation for Thursday 5 PM appointment.',
  },
];

export function findDemoBusiness(citySlug: string, slug: string): DemoBusiness | undefined {
  return DEMO_BUSINESSES.find(
    (b) => b.slug === slug && b.citySlug.toLowerCase() === citySlug.toLowerCase()
  );
}
