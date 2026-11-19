import type { Metadata } from 'next';
import { DashboardOverviewView } from '@/modules/business/components/DashboardOverviewView';

export const metadata: Metadata = {
  title: 'Dashboard Overview',
  description: 'Merchant dashboard overview with real-time storefront health and authoritative controls.',
};

export default function DashboardOverviewPage() {
  return <DashboardOverviewView />;
}
