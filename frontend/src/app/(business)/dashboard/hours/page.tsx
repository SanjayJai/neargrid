import type { Metadata } from 'next';
import { OpeningHoursEditor } from '@/modules/business/components/OpeningHoursEditor';

export const metadata: Metadata = {
  title: 'Opening Hours',
  description: 'Manage weekly schedule and split-shift opening hours for your verified storefront.',
};

export default function OpeningHoursPage() {
  return <OpeningHoursEditor />;
}
