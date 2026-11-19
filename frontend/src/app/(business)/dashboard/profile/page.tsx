import type { Metadata } from 'next';
import { BusinessProfileForm } from '@/modules/business/components/BusinessProfileForm';

export const metadata: Metadata = {
  title: 'Edit Business Profile',
  description: 'Manage live storefront details, category taxonomy, and verified address on NearGrid.',
};

export default function BusinessProfilePage() {
  return <BusinessProfileForm />;
}
