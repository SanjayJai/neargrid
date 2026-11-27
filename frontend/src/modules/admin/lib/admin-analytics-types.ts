import type { AdminAuditLogEntryDto } from './admin-access';

export interface AdminAnalyticsComparisonDto {
  current: number;
  previous: number;
  delta_count: number;
  delta_percent: number | null;
}

export interface AdminDailyTrendPointDto {
  date: string;
  businesses: number;
  users: number;
  enquiries: number;
  reviews: number;
  searches: number;
}

export interface AdminUnavailableIntegrationDto {
  key: string;
  label: string;
  status: 'not_configured' | 'pending_milestone';
  reason: string;
}

export interface AdminAnalyticsDto {
  period: {
    range: '7d' | '30d' | '90d' | 'custom';
    from: string;
    to: string;
    previous_from: string;
    previous_to: string;
    days: number;
    city_slug: string | null;
  };
  summary: {
    businesses: {
      total: number;
      active: number;
      pending_review: number;
      verified: number;
      trusted: number;
      comparison: AdminAnalyticsComparisonDto;
    };
    users: {
      total: number;
      active: number;
      suspended: number;
      comparison: AdminAnalyticsComparisonDto;
    };
    enquiries: {
      total: number;
      dispatched: number;
      pending_otp: number;
      closed: number;
      dispatch_rate_percent: number;
      comparison: AdminAnalyticsComparisonDto;
    };
    reviews: {
      total: number;
      approved: number;
      pending_moderation: number;
      flagged: number;
      avg_rating: number | null;
      open_reports: number;
      comparison: AdminAnalyticsComparisonDto;
    };
    kyc_and_claims: {
      pending_verifications: number;
      approved_verifications: number;
      rejected_verifications: number;
      pending_claims: number;
    };
    domains: {
      total: number;
      active: number;
      verified: number;
      ssl_active: number;
    };
    categories: {
      total: number;
      published: number;
      collections: number;
    };
    search: {
      total_searches: number;
      avg_duration_ms: number;
      zero_result_searches: number;
      comparison: AdminAnalyticsComparisonDto;
    };
  };
  daily_trends: AdminDailyTrendPointDto[];
  top_queries: Array<{
    query: string;
    search_count: number;
    avg_results: number;
  }>;
  top_cities: Array<{
    city_slug: string;
    search_count: number;
  }>;
  recent_activity: AdminAuditLogEntryDto[];
  unavailable_integrations: AdminUnavailableIntegrationDto[];
}
