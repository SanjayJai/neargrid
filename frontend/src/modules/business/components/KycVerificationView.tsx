'use client';

import { FormEvent, useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  Check,
  Clock,
  Download,
  FileText,
  Loader2,
  Lock,
  Plus,
  ShieldCheck,
  Trash2,
  UploadCloud,
  X,
} from 'lucide-react';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';
import { bffFetch, bffUploadFormData } from '@/shared/api/bff-client';
import type {
  BusinessVerificationDto,
  KycDocumentDto,
  VerificationDocumentTypeDto,
} from '@/shared/api/directory-types';

const DOCUMENT_TYPES: Array<{ value: VerificationDocumentTypeDto; label: string; description: string }> = [
  { value: 'gstin', label: 'GSTIN Certificate', description: 'Goods & Services Tax Registration certificate' },
  { value: 'trade_license', label: 'Trade License', description: 'Municipal Corporation or local panchayat trade license' },
  { value: 'shop_act', label: 'Shop & Establishment Act', description: 'State Labour Department registration certificate' },
  { value: 'incorporation_cert', label: 'Certificate of Incorporation', description: 'MCA RoC incorporation or partnership deed' },
  { value: 'utility_bill', label: 'Commercial Utility Bill', description: 'Recent electricity or piped gas bill in business name' },
  { value: 'pan', label: 'Business PAN Card', description: 'Income Tax Department PAN card of entity or proprietor' },
];

export function KycVerificationView() {
  const { activeBusiness, refreshBusinesses } = useOwnerBusiness();

  // Document lists and verifications
  const [documents, setDocuments] = useState<KycDocumentDto[]>([]);
  const [verifications, setVerifications] = useState<BusinessVerificationDto[]>([]);
  const [loadingDocs, setLoadingDocs] = useState(false);
  const [statusMessage, setStatusMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Upload modal state
  const [uploadModalOpen, setUploadModalOpen] = useState(false);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [documentType, setDocumentType] = useState<VerificationDocumentTypeDto>('gstin');
  const [documentNumber, setDocumentNumber] = useState('');
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  const loadData = useCallback(async () => {
    if (!activeBusiness) return;
    setLoadingDocs(true);
    setStatusMessage(null);
    try {
      const [docsRes, verifRes] = await Promise.all([
        bffFetch<KycDocumentDto[]>(
          `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/kyc-documents`
        ),
        bffFetch<BusinessVerificationDto[]>(
          `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/verifications`
        ),
      ]);

      if (docsRes.ok && docsRes.payload?.status === 'success') {
        setDocuments(docsRes.payload.data);
      }
      if (verifRes.ok && verifRes.payload?.status === 'success') {
        setVerifications(verifRes.payload.data);
      }
    } catch {
      setStatusMessage({ type: 'error', text: 'Network error while loading KYC verification data.' });
    } finally {
      setLoadingDocs(false);
    }
  }, [activeBusiness]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  if (!activeBusiness) {
    return (
      <div className="rounded-2xl border border-zinc-200 bg-white p-8 text-center text-xs text-zinc-500">
        Please select or create a business listing to manage KYC and verification.
      </div>
    );
  }

  async function handleUpload(e: FormEvent) {
    e.preventDefault();
    if (!activeBusiness || !selectedFile) return;

    setUploading(true);
    setUploadError(null);

    const formData = new FormData();
    formData.append('file', selectedFile);
    formData.append('document_type', documentType);
    if (documentNumber.trim()) {
      formData.append('document_number', documentNumber.trim());
    }

    try {
      const res = await bffUploadFormData<KycDocumentDto>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/kyc-documents`,
        formData
      );

      if (res.ok && res.payload?.status === 'success') {
        setUploadModalOpen(false);
        setSelectedFile(null);
        setDocumentNumber('');
        setStatusMessage({
          type: 'success',
          text: 'KYC document uploaded securely to encrypted private storage.',
        });
        await loadData();
        await refreshBusinesses();
      } else {
        const err = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to upload KYC document.';
        setUploadError(err);
      }
    } catch {
      setUploadError('Network error while uploading KYC document.');
    } finally {
      setUploading(false);
    }
  }

  async function handleDeleteDoc(doc: KycDocumentDto) {
    if (!activeBusiness || !confirm('Are you sure you want to permanently delete this KYC document?')) {
      return;
    }

    try {
      const res = await bffFetch(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/kyc-documents/${encodeURIComponent(doc.public_id)}`,
        { method: 'DELETE' }
      );

      if (res.ok) {
        setDocuments((prev) => prev.filter((d) => d.public_id !== doc.public_id));
        setStatusMessage({
          type: 'success',
          text: 'KYC document removed.',
        });
      } else {
        const errMsg = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to delete KYC document.';
        alert(errMsg);
      }
    } catch {
      alert('Network error while deleting KYC document.');
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Verification &amp; KYC</h1>
          <p className="mt-1 text-sm text-zinc-600">
            Submit statutory business registrations to earn the NearGrid Verified Merchant badge.
          </p>
        </div>

        <button
          type="button"
          onClick={() => {
            setUploadModalOpen(true);
            setUploadError(null);
          }}
          className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-700"
        >
          <Plus className="h-4 w-4" aria-hidden="true" />
          <span>Upload KYC Document</span>
        </button>
      </div>

      {statusMessage && (
        <div
          role={statusMessage.type === 'error' ? 'alert' : 'status'}
          className={`flex items-center gap-2 rounded-xl border p-4 text-xs font-medium ${
            statusMessage.type === 'success'
              ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
              : 'border-rose-200 bg-rose-50 text-rose-900'
          }`}
        >
          {statusMessage.type === 'success' ? (
            <Check className="h-4 w-4 text-emerald-600 shrink-0" aria-hidden="true" />
          ) : (
            <AlertTriangle className="h-4 w-4 text-rose-600 shrink-0" aria-hidden="true" />
          )}
          <span>{statusMessage.text}</span>
        </div>
      )}

      {/* Verification Status Overview Banner */}
      <div className="rounded-2xl border border-zinc-200 bg-white p-5 sm:p-6 space-y-4">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
          <div className="flex items-start gap-3">
            {activeBusiness.is_verified ? (
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 shrink-0">
                <ShieldCheck className="h-6 w-6" aria-hidden="true" />
              </div>
            ) : (
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700 shrink-0">
                <Clock className="h-6 w-6" aria-hidden="true" />
              </div>
            )}
            <div>
              <div className="flex items-center gap-2">
                <h2 className="text-base font-bold text-zinc-950">
                  {activeBusiness.is_verified ? 'Verified Merchant' : 'Verification In Progress / Pending Documents'}
                </h2>
                {activeBusiness.is_trusted && (
                  <span className="rounded-full bg-sky-50 border border-sky-200 px-2 py-0.5 text-[10px] font-semibold text-sky-800">
                    Trusted
                  </span>
                )}
              </div>
              <p className="mt-1 text-xs text-zinc-600 leading-relaxed">
                {activeBusiness.is_verified
                  ? `Your business was verified on ${activeBusiness.verified_at ? new Date(activeBusiness.verified_at).toLocaleDateString() : 'Record'}. The Verified badge is active on your public profile.`
                  : 'Upload statutory documents such as your GSTIN, Trade License, or Shop Act certificate. Our review team verifies documents within 1 business day.'}
              </p>
            </div>
          </div>

          <div className="text-right sm:border-l sm:border-zinc-100 sm:pl-6">
            <span className="text-[11px] font-medium text-zinc-500 uppercase tracking-wider">Completeness</span>
            <p className="text-xl font-bold text-brand-600">{activeBusiness.completeness_score}%</p>
          </div>
        </div>

        {/* Security Disclosure */}
        <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600 flex items-center gap-2.5">
          <Lock className="h-4 w-4 text-zinc-500 shrink-0" aria-hidden="true" />
          <span>
            KYC documents are encrypted at rest in private storage and strictly protected by Laravel authorization policies. Private paths and signed credentials are never exposed to browser code.
          </span>
        </div>
      </div>

      {/* KYC Documents Section */}
      <section className="rounded-2xl border border-zinc-200 bg-white overflow-hidden" aria-labelledby="kyc-docs-heading">
        <div className="border-b border-zinc-100 p-5">
          <h2 id="kyc-docs-heading" className="text-base font-semibold text-zinc-950">
            Uploaded Private KYC Documents ({documents.length})
          </h2>
          <p className="mt-0.5 text-xs text-zinc-500">
            Documents submitted to prove business ownership and regulatory compliance.
          </p>
        </div>

        {loadingDocs ? (
          <div className="p-8 text-center text-xs text-zinc-400">Loading documents...</div>
        ) : documents.length === 0 ? (
          <div className="p-8 text-center sm:p-12">
            <UploadCloud className="mx-auto h-8 w-8 text-zinc-400" aria-hidden="true" />
            <h3 className="mt-3 text-sm font-semibold text-zinc-950">No KYC documents uploaded</h3>
            <p className="mt-1 text-xs text-zinc-500 max-w-sm mx-auto">
              Please upload a valid business certificate (GSTIN, Trade License, or Incorporation) to request verification.
            </p>
            <button
              type="button"
              onClick={() => setUploadModalOpen(true)}
              className="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-zinc-950 px-3.5 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
            >
              <Plus className="h-3.5 w-3.5" aria-hidden="true" />
              <span>Upload Document</span>
            </button>
          </div>
        ) : (
          <div className="divide-y divide-zinc-200">
            {documents.map((doc) => {
              const downloadUrl = `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/kyc-documents/${encodeURIComponent(doc.public_id)}/download`;
              return (
                <div
                  key={doc.public_id}
                  className="flex flex-col justify-between gap-3 p-4 sm:flex-row sm:items-center sm:px-6"
                >
                  <div className="space-y-1">
                    <div className="flex items-center gap-2">
                      <FileText className="h-4 w-4 text-zinc-500" aria-hidden="true" />
                      <span className="text-sm font-semibold text-zinc-950 uppercase">
                        {doc.document_type.replace('_', ' ')}
                      </span>
                      <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-medium text-zinc-600">
                        {doc.upload_status}
                      </span>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 text-xs text-zinc-500">
                      <span>{(doc.file_size_bytes / 1024).toFixed(0)} KB</span>
                      <span>·</span>
                      <span className="font-mono text-[11px]">
                        SHA: {doc.sha256_checksum.slice(0, 12)}...
                      </span>
                      {doc.created_at && (
                        <>
                          <span>·</span>
                          <span>Uploaded {new Date(doc.created_at).toLocaleDateString()}</span>
                        </>
                      )}
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    {/* Secure server-side streaming download */}
                    <a
                      href={downloadUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-50"
                    >
                      <Download className="h-3.5 w-3.5" aria-hidden="true" />
                      <span>Download</span>
                    </a>

                    <button
                      type="button"
                      onClick={() => handleDeleteDoc(doc)}
                      aria-label="Delete KYC document"
                      className="rounded-lg border border-rose-200 p-1.5 text-rose-600 hover:bg-rose-50"
                    >
                      <Trash2 className="h-4 w-4" aria-hidden="true" />
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </section>

      {/* Verification History Section */}
      {verifications.length > 0 && (
        <section className="rounded-2xl border border-zinc-200 bg-white p-5 sm:p-6 space-y-3" aria-labelledby="history-heading">
          <h2 id="history-heading" className="text-base font-semibold text-zinc-950">
            Verification Review History
          </h2>
          <div className="divide-y divide-zinc-100 text-xs">
            {verifications.map((v) => (
              <div key={v.public_id} className="py-2.5 flex items-center justify-between">
                <div>
                  <span className="font-semibold text-zinc-900 uppercase">
                    {v.document_type.replace('_', ' ')}
                  </span>
                  {v.rejection_reason && (
                    <p className="mt-0.5 text-rose-600">{v.rejection_reason}</p>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <span
                    className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                      v.status === 'approved'
                        ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
                        : v.status === 'rejected'
                        ? 'bg-rose-50 text-rose-800 border border-rose-200'
                        : 'bg-amber-50 text-amber-800 border border-amber-200'
                    }`}
                  >
                    {v.status.toUpperCase()}
                  </span>
                  {v.reviewed_at && (
                    <span className="text-zinc-400">
                      {new Date(v.reviewed_at).toLocaleDateString()}
                    </span>
                  )}
                </div>
              </div>
            ))}
          </div>
        </section>
      )}

      {/* --- MODAL: Upload KYC Document --- */}
      {uploadModalOpen && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="upload-kyc-title"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
          onClick={(e) => {
            if (e.target === e.currentTarget) setUploadModalOpen(false);
          }}
        >
          <div className="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
            <button
              type="button"
              onClick={() => setUploadModalOpen(false)}
              aria-label="Close dialog"
              className="absolute right-4 top-4 rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700"
            >
              <X className="h-5 w-5" aria-hidden="true" />
            </button>

            <h3 id="upload-kyc-title" className="text-base font-bold text-zinc-950">
              Upload KYC Proof
            </h3>
            <p className="mt-1 text-xs text-zinc-500">
              Upload a clear PDF or scanned document (JPG, PNG, WebP up to 10MB).
            </p>

            {uploadError && (
              <div role="alert" className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-2.5 text-xs text-rose-900">
                {uploadError}
              </div>
            )}

            <form onSubmit={handleUpload} className="mt-4 space-y-4">
              <div>
                <label htmlFor="kyc-doc-type" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Document Type <span className="text-rose-600">*</span>
                </label>
                <select
                  id="kyc-doc-type"
                  required
                  value={documentType}
                  onChange={(e) => setDocumentType(e.target.value as VerificationDocumentTypeDto)}
                  className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                >
                  {DOCUMENT_TYPES.map((dt) => (
                    <option key={dt.value} value={dt.value}>
                      {dt.label}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label htmlFor="kyc-doc-number" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Certificate / Registration Number <span className="text-zinc-400 font-normal">(Optional)</span>
                </label>
                <input
                  id="kyc-doc-number"
                  type="text"
                  maxLength={64}
                  value={documentNumber}
                  onChange={(e) => setDocumentNumber(e.target.value)}
                  placeholder="e.g. 33AAAAA0000A1Z5"
                  className="w-full rounded-xl border border-zinc-300 px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600 font-mono"
                />
              </div>

              <div>
                <label htmlFor="kyc-file" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Document File (PDF / Scanned Image) <span className="text-rose-600">*</span>
                </label>
                <input
                  id="kyc-file"
                  type="file"
                  required
                  accept="application/pdf,image/jpeg,image/png,image/webp"
                  onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) setSelectedFile(f);
                  }}
                  className="w-full text-xs text-zinc-700 file:mr-3 file:rounded-xl file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-zinc-900 hover:file:bg-zinc-200"
                />
              </div>

              <div className="flex items-center justify-end gap-2.5 border-t border-zinc-100 pt-3">
                <button
                  type="button"
                  onClick={() => setUploadModalOpen(false)}
                  disabled={uploading}
                  className="rounded-xl border border-zinc-300 px-3.5 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={uploading || !selectedFile}
                  className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
                >
                  {uploading ? (
                    <>
                      <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                      <span>Uploading...</span>
                    </>
                  ) : (
                    <span>Upload &amp; Save</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
