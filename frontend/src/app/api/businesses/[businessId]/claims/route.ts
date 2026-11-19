import {
  bffError,
  enforceMutationSecurity,
  isValidPublicUlid,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

/**
 * Step 4 Security Review Notice:
 * Laravel `proof_file_path_private` represents a private storage disk path.
 * A secure, isolated document upload pipeline for non-owner claimants is not yet available.
 * To prevent submission of arbitrary unverified paths or cross-tenant storage references,
 * self-serve claim submission is kept disabled until the required upload capability exists.
 */
export async function POST(
  request: Request,
  context: { params: { businessId: string } }
) {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId } = context.params;
  if (!isValidPublicUlid(businessId)) {
    return bffError('INVALID_BUSINESS_ID', 'Invalid business identifier.', 400);
  }

  return bffError(
    'CLAIM_UPLOAD_UNAVAILABLE',
    'Self-serve claim submission is disabled pending secure claimant document upload pipeline integration. Please contact the platform administrator for manual verification.',
    501
  );
}
