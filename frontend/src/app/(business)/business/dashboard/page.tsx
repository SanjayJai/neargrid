import type { Metadata } from 'next';
import { DashboardOverviewView } from '@/modules/business/components/DashboardOverviewView';

export const metadata: Metadata = {
  title: 'Business Dashboard',
  description: 'Merchant dashboard overview with real-time storefront health and authoritative controls.',
};

export default function BusinessDashboardPage() {
  return <DashboardOverviewView />;
}
