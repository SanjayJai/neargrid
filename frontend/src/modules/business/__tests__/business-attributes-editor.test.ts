import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockRequireActiveBffSession = vi.fn();
const mockEnforceMutationSecurity = vi.fn();
const mockCallLaravelApi = vi.fn();

vi.mock('@/lib/bff/route-helpers', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/bff/route-helpers')>();
  return {
    ...actual,
    requireActiveBffSession: mockRequireActiveBffSession,
    enforceMutationSecurity: mockEnforceMutationSecurity,
  };
});

vi.mock('@/lib/bff/laravel-client', () => ({
  callLaravelApi: mockCallLaravelApi,
}));

describe('Owner Business Attributes BFF routes', () => {
  const validBusinessId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

  beforeEach(() => {
    vi.clearAllMocks();
    mockEnforceMutationSecurity.mockReturnValue(null);
    mockRequireActiveBffSession.mockResolvedValue({
      ok: true,
      opaqueSessionId: 'opaque-sess-1',
      record: { accessToken: 'secret-sanctum-bearer' },
    });
  });

  it('rejects invalid ULID business identifiers with 400', async () => {
    const { GET } = await import('@/app/api/owner/businesses/[businessId]/attributes/route');
    const res = await GET(new Request('http://localhost/api/owner/businesses/bad-id/attributes'), {
      params: { businessId: 'bad-id' },
    });

    expect(res.status).toBe(400);
    expect(mockCallLaravelApi).not.toHaveBeenCalled();
  });

  it('forwards GET, PUT validation errors, POST validate, and DELETE orphans cleanly', async () => {
    const { GET, PUT } = await import('@/app/api/owner/businesses/[businessId]/attributes/route');
    const { POST } = await import('@/app/api/owner/businesses/[businessId]/attributes/validate/route');
    const { DELETE } = await import('@/app/api/owner/businesses/[businessId]/attributes/orphans/route');

    mockCallLaravelApi.mockResolvedValueOnce({
      ok: true,
      status: 200,
      payload: {
        status: 'success',
        data: {
          business_public_id: validBusinessId,
          sections: [],
          attributes: [],
          orphaned_values: [],
          missing_required: [],
          is_complete_for_publish: true,
        },
      },
    });

    const getRes = await GET(new Request(`http://localhost/api/owner/businesses/${validBusinessId}/attributes`), {
      params: { businessId: validBusinessId },
    });
    expect(getRes.status).toBe(200);
    expect(mockCallLaravelApi).toHaveBeenCalledWith(
      `/api/v1/owner/businesses/${validBusinessId}/attributes`,
      expect.objectContaining({
        method: 'GET',
        bearerToken: 'secret-sanctum-bearer',
      })
    );

    // PUT forwards 422 validation errors from Laravel
    mockCallLaravelApi.mockResolvedValueOnce({
      ok: false,
      status: 422,
      payload: {
        status: 'error',
        error: {
          code: 'VALIDATION_FAILED',
          message: 'The given data was invalid.',
          details: { 'attributes.0.value': ['Bed Count must be at most 2000.'] },
        },
      },
    });

    const putRes = await PUT(
      new Request(`http://localhost/api/owner/businesses/${validBusinessId}/attributes`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ attributes: [{ attribute_key: 'bed_count', value: 99999 }] }),
      }),
      { params: { businessId: validBusinessId } }
    );
    expect(putRes.status).toBe(422);
    const putJson = await putRes.json();
    expect(putJson.error.details['attributes.0.value'][0]).toContain('2000');

    // POST /validate
    mockCallLaravelApi.mockResolvedValueOnce({
      ok: false,
      status: 422,
      payload: {
        status: 'error',
        error: {
          code: 'VALIDATION_FAILED',
          message: 'The given data was invalid.',
          details: { 'required_attributes.reg_no': ['Required attribute must be provided.'] },
        },
      },
    });

    const valRes = await POST(
      new Request(`http://localhost/api/owner/businesses/${validBusinessId}/attributes/validate`, {
        method: 'POST',
      }),
      { params: { businessId: validBusinessId } }
    );
    expect(valRes.status).toBe(422);

    // DELETE /orphans
    mockCallLaravelApi.mockResolvedValueOnce({
      ok: true,
      status: 200,
      payload: { status: 'success', data: { deleted_count: 1 } },
    });

    const delRes = await DELETE(
      new Request(`http://localhost/api/owner/businesses/${validBusinessId}/attributes/orphans`, {
        method: 'DELETE',
      }),
      { params: { businessId: validBusinessId } }
    );
    expect(delRes.status).toBe(200);
  });
});
