import type { Metadata } from 'next';
import { TeamManagerView } from '@/modules/business/components/TeamManagerView';

export const metadata: Metadata = {
  title: 'Team & Staff Management',
  description: 'Manage staff member roles and granular permissions for your NearGrid business listing.',
};

export default function TeamManagementPage() {
  return <TeamManagerView />;
}
