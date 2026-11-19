import type { Metadata } from 'next';
import { MediaAndServicesView } from '@/modules/business/components/MediaAndServicesView';

export const metadata: Metadata = {
  title: 'Photos & Services',
  description: 'Manage storefront photos, sanitized WebP variants, and transparent service pricing.',
};

export default function MediaAndServicesPage() {
  return <MediaAndServicesView />;
}
