import type { Metadata } from 'next';
import { KycVerificationView } from '@/modules/business/components/KycVerificationView';

export const metadata: Metadata = {
  title: 'Verification & KYC',
  description: 'Upload business statutory certificates and manage KYC verification status securely.',
};

export default function VerificationPage() {
  return <KycVerificationView />;
}
