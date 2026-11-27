'use client';

import { useCallback, useEffect, useState } from 'react';
import { FileKey2 } from 'lucide-react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

interface VerificationRow {
  public_id: string;
  document_type: string;
  status: string;
  rejection_reason: string | null;
  business: { public_id: string; name: string; slug: string; city_name?: string } | null;
  kyc_document_public_id?: string | null;
  submitted_by: { public_id: string; name: string; email: string } | null;
  created_at: string | null;
}

interface ClaimRow {
  public_id: string;
  status: string;
  claim_notes: string | null;
  review_notes: string | null;
  business: { public_id: string; name: string; slug: string; city_name?: string | null; is_claimed: boolean } | null;
  claimant: { public_id: string; name: string; email: string } | null;
  created_at: string | null;
}

export function AdminKycClaimsView() {
  const [tab, setTab] = useState<'verifications' | 'claims'>('verifications');
  const [statusFilter, setStatusFilter] = useState('pending');
  const [page, setPage] = useState(1);

  const [verifications, setVerifications] = useState<VerificationRow[]>([]);
  const [claims, setClaims] = useState<ClaimRow[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [selectedVerif, setSelectedVerif] = useState<VerificationRow | null>(null);
  const [selectedClaim, setSelectedClaim] = useState<ClaimRow | null>(null);
  const [notes, setNotes] = useState('');
  const [grantTrust, setGrantTrust] = useState(false);
  const [signedUrlInfo, setSignedUrlInfo] = useState<{ url: string; expiresAt: string } | null>(null);
  const [feedback, setFeedback] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirmReject, setConfirmReject] = useState<{ kind: 'verif' | 'claim' } | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ page: String(page) });
      if (statusFilter) qs.set('status', statusFilter);

      if (tab === 'verifications') {
        const res = await adminFetch<VerificationRow[]>(`verifications?${qs.toString()}`);
        setVerifications(Array.isArray(res.data) ? res.data : []);
        if (res.meta) setMeta(res.meta);
      } else {
        const res = await adminFetch<ClaimRow[]>(`claims?${qs.toString()}`);
        setClaims(Array.isArray(res.data) ? res.data : []);
        if (res.meta) setMeta(res.meta);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load queue.');
    } finally {
      setLoading(false);
    }
  }, [tab, statusFilter, page]);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleReviewVerification(decision: 'approved' | 'rejected') {
    if (!selectedVerif) return;
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`verifications/${selectedVerif.public_id}/review`, {
        method: 'POST',
        body: {
          decision,
          rejection_reason: decision === 'rejected' ? notes.trim() || 'Rejected by KYC reviewer' : undefined,
          grant_trust_badge: decision === 'approved' ? grantTrust : false,
        },
      });
      setSelectedVerif(null);
      setConfirmReject(null);
      setNotes('');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Verification review failed.');
    } finally {
      setBusy(false);
    }
  }

  async function handleReviewClaim(decision: 'approved' | 'rejected') {
    if (!selectedClaim) return;
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`claims/${selectedClaim.public_id}/review`, {
        method: 'POST',
        body: { decision, review_notes: notes.trim() || undefined },
      });
      setSelectedClaim(null);
      setConfirmReject(null);
      setNotes('');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Claim review failed.');
    } finally {
      setBusy(false);
    }
  }

  async function fetchSignedUrl(v: VerificationRow) {
    if (!v.business?.public_id || !v.kyc_document_public_id) return;
    setBusy(true);
    setFeedback(null);
    try {
      const res = await adminFetch<{ download_url: string; expires_at: string }>(
        `businesses/${v.business.public_id}/kyc-documents/${v.kyc_document_public_id}/signed-url`
      );
      setSignedUrlInfo({ url: res.data.download_url, expiresAt: res.data.expires_at });
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Unable to issue signed document URL.');
    } finally {
      setBusy(false);
    }
  }

  const verifCols: AdminTableColumn<VerificationRow>[] = [
    {
      key: 'business',
      label: 'Business',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.business?.name ?? '—'}</div>
          <div className="text-xs text-slate-500">{r.document_type}</div>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (r) => <AdminStatusBadge status={r.status} />,
    },
    {
      key: 'submitted_by',
      label: 'Submitted By',
      render: (r) => <span className="text-xs">{r.submitted_by?.name ?? '—'}</span>,
    },
  ];

  const claimCols: AdminTableColumn<ClaimRow>[] = [
    {
      key: 'business',
      label: 'Business',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.business?.name ?? '—'}</div>
          <div className="text-xs text-slate-500">{r.business?.city_name ?? '—'}</div>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (r) => <AdminStatusBadge status={r.status} />,
    },
    {
      key: 'claimant',
      label: 'Claimant',
      render: (r) => <span className="text-xs">{r.claimant?.email ?? '—'}</span>,
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
            KYC &amp; Ownership Claims
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Process protected KYC document verifications and business ownership claims with actor-bound signed access.
          </p>
        </div>

        <div className="flex rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
          <button
            type="button"
            onClick={() => {
              setTab('verifications');
              setPage(1);
            }}
            className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${
              tab === 'verifications' ? 'bg-slate-950 text-white dark:bg-white dark:text-slate-950' : 'text-slate-600'
            }`}
          >
            KYC Verifications
          </button>
          <button
            type="button"
            onClick={() => {
              setTab('claims');
              setPage(1);
            }}
            className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${
              tab === 'claims' ? 'bg-slate-950 text-white dark:bg-white dark:text-slate-950' : 'text-slate-600'
            }`}
          >
            Ownership Claims
          </button>
        </div>
      </div>

      {tab === 'verifications' ? (
        <AdminDataTable
          columns={verifCols}
          rows={verifications}
          rowKey={(r) => r.public_id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          page={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={setPage}
          onRowClick={(r) => {
            setSelectedVerif(r);
            setSignedUrlInfo(null);
            setFeedback(null);
            setNotes('');
          }}
          toolbarExtra={
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter verification status"
              className="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
              <option value="">All</option>
              <option value="pending">Pending</option>
              <option value="under_review">Under Review</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          }
        />
      ) : (
        <AdminDataTable
          columns={claimCols}
          rows={claims}
          rowKey={(r) => r.public_id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          page={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={setPage}
          onRowClick={(r) => {
            setSelectedClaim(r);
            setFeedback(null);
            setNotes('');
          }}
          toolbarExtra={
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter claim status"
              className="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
              <option value="">All</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          }
        />
      )}

      <AdminDrawer
        open={selectedVerif !== null}
        title={selectedVerif?.business?.name ?? 'KYC Verification'}
        subtitle={selectedVerif ? `${selectedVerif.document_type} · ${selectedVerif.status}` : undefined}
        onClose={() => setSelectedVerif(null)}
      >
        {selectedVerif && (
          <div className="space-y-4 text-sm">
            {feedback && (
              <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                {feedback}
              </div>
            )}
            {selectedVerif.kyc_document_public_id && (
              <div className="space-y-2 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void fetchSignedUrl(selectedVerif)}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50 dark:border-slate-700"
                >
                  <FileKey2 className="h-3.5 w-3.5" />
                  Issue Actor-Bound Signed URL
                </button>
                {signedUrlInfo && (
                  <div className="text-xs text-slate-600 dark:text-slate-300">
                    <a
                      href={signedUrlInfo.url}
                      target="_blank"
                      rel="noreferrer"
                      className="font-semibold text-sky-600 underline"
                    >
                      Open Protected Document
                    </a>{' '}
                    (expires {signedUrlInfo.expiresAt})
                  </div>
                )}
              </div>
            )}

            <label className="flex items-center gap-2 text-xs">
              <input
                type="checkbox"
                checked={grantTrust}
                onChange={(e) => setGrantTrust(e.target.checked)}
              />
              Grant Trust Badge upon approval
            </label>
            <textarea
              rows={3}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Rejection reason (required if rejecting)"
              className="w-full rounded-lg border border-slate-200 p-2.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
            <div className="flex gap-2">
              <button
                type="button"
                disabled={busy}
                onClick={() => void handleReviewVerification('approved')}
                className="rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white disabled:opacity-50"
              >
                Approve KYC
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => setConfirmReject({ kind: 'verif' })}
                className="rounded-lg bg-rose-600 px-3.5 py-2 text-xs font-semibold text-white disabled:opacity-50"
              >
                Reject KYC
              </button>
            </div>
          </div>
        )}
      </AdminDrawer>

      <AdminDrawer
        open={selectedClaim !== null}
        title={selectedClaim?.business?.name ?? 'Ownership Claim'}
        subtitle={selectedClaim ? `Claim ${selectedClaim.public_id} · ${selectedClaim.status}` : undefined}
        onClose={() => setSelectedClaim(null)}
      >
        {selectedClaim && (
          <div className="space-y-4 text-sm">
            {feedback && (
              <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                {feedback}
              </div>
            )}
            <div className="rounded-xl border border-slate-200 p-3 text-xs dark:border-slate-800">
              <div className="text-slate-500">Claimant</div>
              <div className="mt-0.5 font-semibold">{selectedClaim.claimant?.name} ({selectedClaim.claimant?.email})</div>
              {selectedClaim.claim_notes && (
                <p className="mt-2 text-slate-600 dark:text-slate-300">{selectedClaim.claim_notes}</p>
              )}
            </div>
            <textarea
              rows={3}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Review notes"
              className="w-full rounded-lg border border-slate-200 p-2.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
            <div className="flex gap-2">
              <button
                type="button"
                disabled={busy}
                onClick={() => void handleReviewClaim('approved')}
                className="rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white disabled:opacity-50"
              >
                Approve &amp; Transfer Ownership
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => setConfirmReject({ kind: 'claim' })}
                className="rounded-lg bg-rose-600 px-3.5 py-2 text-xs font-semibold text-white disabled:opacity-50"
              >
                Reject Claim
              </button>
            </div>
          </div>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={confirmReject !== null}
        title="Confirm Rejection"
        description="This decision is recorded in the audit log and notifies the applicant."
        confirmLabel="Confirm Reject"
        loading={busy}
        onConfirm={() => {
          if (confirmReject?.kind === 'verif') void handleReviewVerification('rejected');
          if (confirmReject?.kind === 'claim') void handleReviewClaim('rejected');
        }}
        onCancel={() => setConfirmReject(null)}
      />
    </div>
  );
}
