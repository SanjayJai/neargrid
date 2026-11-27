export type AdminNavGroupKey =
  | 'overview'
  | 'directory'
  | 'taxonomy'
  | 'growth'
  | 'content'
  | 'governance';

export interface AdminNavItem {
  key: string;
  label: string;
  href: string;
  group: AdminNavGroupKey;
  description: string;
  keywords: string[];
  milestone: 'M1' | 'M2' | 'M3' | 'M4' | 'M5' | 'M6' | 'M7' | 'M8' | 'M9';
  enabled: boolean;
}

export interface AdminNavGroup {
  key: AdminNavGroupKey;
  label: string;
  items: AdminNavItem[];
}

export const ADMIN_NAV_GROUPS: AdminNavGroup[] = [
  {
    key: 'overview',
    label: 'Overview',
    items: [
      {
        key: 'dashboard',
        label: 'Control Centre',
        href: '/admin',
        group: 'overview',
        description: 'Platform KPIs, moderation queues & live activity',
        keywords: ['home', 'overview', 'kpi', 'control'],
        milestone: 'M1',
        enabled: true,
      },
      {
        key: 'analytics',
        label: 'Platform Analytics',
        href: '/admin/analytics',
        group: 'overview',
        description: 'Traffic, conversions, business & ad reporting',
        keywords: ['metrics', 'reports', 'charts', 'ga4'],
        milestone: 'M2',
        enabled: true,
      },
    ],
  },
  {
    key: 'directory',
    label: 'Directory & Operations',
    items: [
      {
        key: 'businesses',
        label: 'Businesses',
        href: '/admin/businesses',
        group: 'directory',
        description: 'Listings, completeness, verification & visibility',
        keywords: ['listings', 'shops', 'merchants'],
        milestone: 'M3',
        enabled: false,
      },
      {
        key: 'organizations',
        label: 'Organizations & Branches',
        href: '/admin/organizations',
        group: 'directory',
        description: 'Multi-branch HQ, company branches & franchises',
        keywords: ['hq', 'branches', 'franchises'],
        milestone: 'M3',
        enabled: false,
      },
      {
        key: 'kyc',
        label: 'KYC & Claims',
        href: '/admin/kyc',
        group: 'directory',
        description: 'Document verification queue & ownership claims',
        keywords: ['verification', 'documents', 'claims'],
        milestone: 'M3',
        enabled: false,
      },
      {
        key: 'reviews',
        label: 'Reviews & Disputes',
        href: '/admin/reviews',
        group: 'directory',
        description: 'Customer review moderation & abuse reports',
        keywords: ['ratings', 'moderation', 'reports'],
        milestone: 'M3',
        enabled: false,
      },
      {
        key: 'enquiries',
        label: 'CRM & Enquiries',
        href: '/admin/enquiries',
        group: 'directory',
        description: 'Lead routing, follow-ups & conversion tracking',
        keywords: ['leads', 'crm', 'routing'],
        milestone: 'M3',
        enabled: false,
      },
    ],
  },
  {
    key: 'taxonomy',
    label: 'Taxonomy & Geography',
    items: [
      {
        key: 'categories',
        label: 'Categories & Schemas',
        href: '/admin/categories',
        group: 'taxonomy',
        description: '5-level tree, attributes, filters & collections',
        keywords: ['taxonomy', 'attributes', 'collections'],
        milestone: 'M3',
        enabled: false,
      },
      {
        key: 'locations',
        label: 'Locations',
        href: '/admin/locations',
        group: 'taxonomy',
        description: 'States, cities, localities & spatial coordinates',
        keywords: ['states', 'cities', 'localities', 'geo'],
        milestone: 'M3',
        enabled: false,
      },
      {
        key: 'domains',
        label: 'Domains & SSL',
        href: '/admin/domains',
        group: 'taxonomy',
        description: 'Multi-domain host registry, DNS & SSL health',
        keywords: ['dns', 'ssl', 'hosts', 'canonical'],
        milestone: 'M3',
        enabled: false,
      },
    ],
  },
  {
    key: 'growth',
    label: 'Growth, Ads & Engagement',
    items: [
      {
        key: 'ad-placements',
        label: 'Ad Placements (30+)',
        href: '/admin/ads/placements',
        group: 'growth',
        description: 'Configurable placement slots & visibility rules',
        keywords: ['ads', 'slots', 'banners'],
        milestone: 'M5',
        enabled: false,
      },
      {
        key: 'ad-campaigns',
        label: 'Campaigns & Banners',
        href: '/admin/ads/campaigns',
        group: 'growth',
        description: 'Creatives, targeting, budgets & CTR performance',
        keywords: ['promotions', 'sponsored', 'ctr'],
        milestone: 'M5',
        enabled: false,
      },
      {
        key: 'popups',
        label: 'Popups & Announcements',
        href: '/admin/popups',
        group: 'growth',
        description: 'Modals, frequency caps & dismissal rules',
        keywords: ['modals', 'notices', 'offers'],
        milestone: 'M6',
        enabled: false,
      },
      {
        key: 'notifications',
        label: 'Notification Centre',
        href: '/admin/notifications',
        group: 'growth',
        description: 'Templates, broadcasts & delivery analytics',
        keywords: ['email', 'sms', 'push', 'alerts'],
        milestone: 'M7',
        enabled: false,
      },
    ],
  },
  {
    key: 'content',
    label: 'Content & SEO',
    items: [
      {
        key: 'cms-pages',
        label: 'Pages & Blocks',
        href: '/admin/content/pages',
        group: 'content',
        description: 'Homepage sections, legal pages & menus',
        keywords: ['cms', 'banners', 'faq', 'legal'],
        milestone: 'M8',
        enabled: false,
      },
      {
        key: 'media-library',
        label: 'Media Library',
        href: '/admin/content/media',
        group: 'content',
        description: 'Approved creative assets & documents',
        keywords: ['images', 'uploads', 'assets'],
        milestone: 'M8',
        enabled: false,
      },
      {
        key: 'seo',
        label: 'SEO & Redirects',
        href: '/admin/seo',
        group: 'content',
        description: 'Metadata, JSON-LD, 301 redirects & sitemaps',
        keywords: ['canonical', 'redirects', 'sitemap'],
        milestone: 'M3',
        enabled: false,
      },
    ],
  },
  {
    key: 'governance',
    label: 'Governance & Security',
    items: [
      {
        key: 'users',
        label: 'Users & Sessions',
        href: '/admin/users',
        group: 'governance',
        description: 'Accounts, login history, devices & suspensions',
        keywords: ['accounts', 'sessions', 'security'],
        milestone: 'M4',
        enabled: false,
      },
      {
        key: 'roles',
        label: 'Roles & Permissions',
        href: '/admin/roles',
        group: 'governance',
        description: 'RBAC permission matrix & role assignments',
        keywords: ['rbac', 'access', 'permissions'],
        milestone: 'M4',
        enabled: false,
      },
      {
        key: 'audit-logs',
        label: 'Audit Logs',
        href: '/admin/audit-logs',
        group: 'governance',
        description: 'Immutable security & admin activity trail',
        keywords: ['trail', 'events', 'compliance'],
        milestone: 'M4',
        enabled: false,
      },
      {
        key: 'settings',
        label: 'Settings & Health',
        href: '/admin/settings',
        group: 'governance',
        description: 'Platform flags, maintenance, cache & queues',
        keywords: ['config', 'queues', 'health'],
        milestone: 'M9',
        enabled: false,
      },
    ],
  },
];

export const ALL_ADMIN_NAV_ITEMS: AdminNavItem[] = ADMIN_NAV_GROUPS.flatMap(
  (group) => group.items
);

export function filterAdminNavigation(query: string): AdminNavItem[] {
  const trimmed = query.trim().toLowerCase();
  if (!trimmed) {
    return ALL_ADMIN_NAV_ITEMS;
  }

  return ALL_ADMIN_NAV_ITEMS.filter(
    (item) =>
      item.label.toLowerCase().includes(trimmed) ||
      item.description.toLowerCase().includes(trimmed) ||
      item.keywords.some((kw) => kw.includes(trimmed))
  );
}
