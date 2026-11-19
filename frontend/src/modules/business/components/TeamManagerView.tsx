'use client';

import { FormEvent, useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  Check,
  Loader2,
  Lock,
  Shield,
  Trash2,
  UserPlus,
  Users,
  X,
} from 'lucide-react';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';
import { bffFetch } from '@/shared/api/bff-client';
import type {
  BusinessMemberDto,
  BusinessMemberRoleDto,
} from '@/shared/api/directory-types';

const ASSIGNABLE_ROLES: Array<{
  role: BusinessMemberRoleDto;
  name: string;
  description: string;
  defaultPermissions: string[];
}> = [
  {
    role: 'manager',
    name: 'Business Manager',
    description: 'Can edit profile, manage services, upload photos, manage leads, and submit verification.',
    defaultPermissions: [
      'business.view',
      'profile.edit',
      'hours.edit',
      'services.edit',
      'media.manage',
      'leads.manage',
      'reviews.reply',
      'verification.submit',
    ],
  },
  {
    role: 'editor',
    name: 'Storefront Editor',
    description: 'Can edit profile details, update opening hours, curate services, and upload photos.',
    defaultPermissions: [
      'business.view',
      'profile.edit',
      'hours.edit',
      'services.edit',
      'media.manage',
    ],
  },
  {
    role: 'lead_agent',
    name: 'Lead & Enquiries Agent',
    description: 'Can view business profile, respond to customer enquiries, and reply to reviews.',
    defaultPermissions: [
      'business.view',
      'leads.manage',
      'reviews.reply',
    ],
  },
];

const AVAILABLE_PERMISSIONS: Array<{ key: string; label: string }> = [
  { key: 'profile.edit', label: 'Edit Profile Information' },
  { key: 'hours.edit', label: 'Update Opening Hours' },
  { key: 'services.edit', label: 'Manage Services & Pricing' },
  { key: 'leads.manage', label: 'Manage Inbound Enquiries' },
  { key: 'reviews.reply', label: 'Reply to Customer Reviews' },
  { key: 'verification.submit', label: 'Submit KYC Documents' },
];

export function TeamManagerView() {
  const { activeBusiness } = useOwnerBusiness();
  const [members, setMembers] = useState<BusinessMemberDto[]>([]);
  const [loading, setLoading] = useState(false);
  const [statusMessage, setStatusMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Add Member Modal State
  const [addModalOpen, setAddModalOpen] = useState(false);
  const [targetUserUlid, setTargetUserUlid] = useState('');
  const [selectedRole, setSelectedRole] = useState<BusinessMemberRoleDto>('editor');
  const [selectedPermissions, setSelectedPermissions] = useState<string[]>([
    'profile.edit',
    'hours.edit',
    'services.edit',
  ]);
  const [submitting, setSubmitting] = useState(false);
  const [addError, setAddError] = useState<string | null>(null);

  // Edit Member Modal State
  const [editingMember, setEditingMember] = useState<BusinessMemberDto | null>(null);
  const [editRole, setEditRole] = useState<BusinessMemberRoleDto>('editor');
  const [editStatus, setEditStatus] = useState<'active' | 'revoked'>('active');
  const [savingEdit, setSavingEdit] = useState(false);
  const [editError, setEditError] = useState<string | null>(null);

  const isOwner = Boolean(activeBusiness?.my_membership?.is_owner);

  const loadMembers = useCallback(async () => {
    if (!activeBusiness) return;
    setLoading(true);
    setStatusMessage(null);
    try {
      const res = await bffFetch<BusinessMemberDto[]>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/members`
      );
      if (res.ok && res.payload?.status === 'success') {
        setMembers(res.payload.data);
      } else {
        const err = res.payload?.status === 'error' ? res.payload.error.message : 'Unable to load team members.';
        setStatusMessage({
          type: 'error',
          text: err,
        });
      }
    } catch {
      setStatusMessage({ type: 'error', text: 'Network error while loading team members.' });
    } finally {
      setLoading(false);
    }
  }, [activeBusiness]);

  useEffect(() => {
    loadMembers();
  }, [loadMembers]);

  if (!activeBusiness) {
    return (
      <div className="rounded-2xl border border-zinc-200 bg-white p-8 text-center text-xs text-zinc-500">
        Please select or create a business listing to manage staff access.
      </div>
    );
  }

  // Update permissions when role changes in Add Modal
  function handleRoleChange(role: BusinessMemberRoleDto) {
    setSelectedRole(role);
    const def = ASSIGNABLE_ROLES.find((r) => r.role === role);
    if (def) {
      setSelectedPermissions(def.defaultPermissions);
    }
  }

  function togglePermission(key: string) {
    setSelectedPermissions((prev) =>
      prev.includes(key) ? prev.filter((p) => p !== key) : [...prev, key]
    );
  }

  async function handleAddMember(e: FormEvent) {
    e.preventDefault();
    if (!activeBusiness) return;

    setSubmitting(true);
    setAddError(null);

    const payload = {
      user_public_id: targetUserUlid.trim(),
      role: selectedRole,
      permissions: selectedPermissions,
    };

    try {
      const res = await bffFetch<BusinessMemberDto>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/members`,
        {
          method: 'POST',
          body: payload,
        }
      );

      if (res.ok && res.payload?.status === 'success') {
        const newMember = res.payload.data;
        setMembers((prev) => [...prev, newMember]);
        setAddModalOpen(false);
        setTargetUserUlid('');
        setStatusMessage({
          type: 'success',
          text: 'Team member added successfully.',
        });
      } else {
        const err = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to add team member.';
        setAddError(err);
      }
    } catch {
      setAddError('Network error while adding team member.');
    } finally {
      setSubmitting(false);
    }
  }

  async function handleUpdateMember(e: FormEvent) {
    e.preventDefault();
    if (!activeBusiness || !editingMember) return;

    setSavingEdit(true);
    setEditError(null);

    const payload = {
      role: editRole,
      status: editStatus,
    };

    try {
      const res = await bffFetch<BusinessMemberDto>(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/members/${encodeURIComponent(editingMember.public_id)}`,
        {
          method: 'PATCH',
          body: payload,
        }
      );

      if (res.ok && res.payload?.status === 'success') {
        const updated = res.payload.data;
        setMembers((prev) => prev.map((m) => (m.public_id === updated.public_id ? updated : m)));
        setEditingMember(null);
        setStatusMessage({
          type: 'success',
          text: 'Staff member permissions updated.',
        });
      } else {
        const err = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to update member.';
        setEditError(err);
      }
    } catch {
      setEditError('Network error while updating member.');
    } finally {
      setSavingEdit(false);
    }
  }

  async function handleRemoveMember(member: BusinessMemberDto) {
    if (!activeBusiness || !confirm(`Are you sure you want to remove ${member.user?.name || 'this staff member'}?`)) {
      return;
    }

    try {
      const res = await bffFetch(
        `/api/owner/businesses/${encodeURIComponent(activeBusiness.public_id)}/members/${encodeURIComponent(member.public_id)}`,
        { method: 'DELETE' }
      );

      if (res.ok) {
        setMembers((prev) => prev.filter((m) => m.public_id !== member.public_id));
        setStatusMessage({
          type: 'success',
          text: 'Staff member removed from business.',
        });
      } else {
        const errMsg = res.payload?.status === 'error' ? res.payload.error.message : 'Failed to remove member.';
        alert(errMsg);
      }
    } catch {
      alert('Network error while removing member.');
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">Team &amp; Staff Management</h1>
          <p className="mt-1 text-sm text-zinc-600">
            Grant granular management roles to clinic managers, editors, and customer response agents.
          </p>
        </div>

        {isOwner ? (
          <button
            type="button"
            onClick={() => {
              setAddModalOpen(true);
              setAddError(null);
            }}
            className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-700"
          >
            <UserPlus className="h-4 w-4" aria-hidden="true" />
            <span>Add Team Member</span>
          </button>
        ) : (
          <span className="inline-flex items-center gap-1.5 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-medium text-zinc-600">
            <Lock className="h-3.5 w-3.5 text-zinc-400" aria-hidden="true" />
            Staff Management (Owner Only)
          </span>
        )}
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

      {/* Role explanation cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        {ASSIGNABLE_ROLES.map((r) => (
          <div key={r.role} className="rounded-2xl border border-zinc-200 bg-white p-4">
            <div className="flex items-center gap-2">
              <Shield className="h-4 w-4 text-brand-600" aria-hidden="true" />
              <h2 className="text-xs font-bold text-zinc-950 uppercase tracking-wider">{r.name}</h2>
            </div>
            <p className="mt-1.5 text-xs text-zinc-600 leading-relaxed">{r.description}</p>
          </div>
        ))}
      </div>

      {/* Staff members list */}
      <section className="rounded-2xl border border-zinc-200 bg-white overflow-hidden" aria-labelledby="members-heading">
        <div className="border-b border-zinc-100 p-5">
          <h2 id="members-heading" className="text-base font-semibold text-zinc-950">
            Authorized Listing Staff ({members.length})
          </h2>
          <p className="mt-0.5 text-xs text-zinc-500">
            All team modifications are audit-logged and protected by Laravel Directory Policies.
          </p>
        </div>

        {loading ? (
          <div className="p-8 text-center text-xs text-zinc-400">Loading team members...</div>
        ) : members.length === 0 ? (
          <div className="p-8 text-center sm:p-12">
            <Users className="mx-auto h-8 w-8 text-zinc-400" aria-hidden="true" />
            <h3 className="mt-3 text-sm font-semibold text-zinc-950">No additional staff members</h3>
            <p className="mt-1 text-xs text-zinc-500 max-w-sm mx-auto">
              As the primary owner, you currently hold all listing permissions. You can invite team members by their User ULID.
            </p>
          </div>
        ) : (
          <div className="divide-y divide-zinc-200">
            {members.map((member) => (
              <div
                key={member.public_id}
                className="flex flex-col justify-between gap-3 p-4 sm:flex-row sm:items-center sm:px-6"
              >
                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold text-zinc-950">
                      {member.user?.name || member.user?.phone_e164 || `Staff User (${member.public_id.slice(-6)})`}
                    </span>
                    <span className="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-zinc-800">
                      {member.role}
                    </span>
                    <span
                      className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${
                        member.status === 'active'
                          ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
                          : 'bg-rose-50 text-rose-800 border border-rose-200'
                      }`}
                    >
                      {member.status.toUpperCase()}
                    </span>
                  </div>

                  {member.user?.phone_e164 && member.user?.name && (
                    <p className="text-xs text-zinc-500">{member.user.phone_e164}</p>
                  )}

                  {member.capabilities && member.capabilities.length > 0 && (
                    <div className="flex flex-wrap gap-1 pt-1">
                      {member.capabilities.map((cap) => (
                        <span
                          key={cap}
                          className="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-600 font-mono"
                        >
                          {cap}
                        </span>
                      ))}
                    </div>
                  )}
                </div>

                {isOwner && member.role !== 'owner' && (
                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      onClick={() => {
                        setEditingMember(member);
                        setEditRole(member.role);
                        setEditStatus(member.status === 'revoked' ? 'revoked' : 'active');
                        setEditError(null);
                      }}
                      className="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50"
                    >
                      Change Role
                    </button>
                    <button
                      type="button"
                      onClick={() => handleRemoveMember(member)}
                      aria-label="Remove staff member"
                      className="rounded-lg border border-rose-200 p-1.5 text-rose-600 hover:bg-rose-50"
                    >
                      <Trash2 className="h-4 w-4" aria-hidden="true" />
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </section>

      {/* --- MODAL: Add Team Member --- */}
      {addModalOpen && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="add-member-title"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
          onClick={(e) => {
            if (e.target === e.currentTarget) setAddModalOpen(false);
          }}
        >
          <div className="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
            <button
              type="button"
              onClick={() => setAddModalOpen(false)}
              aria-label="Close dialog"
              className="absolute right-4 top-4 rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700"
            >
              <X className="h-5 w-5" aria-hidden="true" />
            </button>

            <h3 id="add-member-title" className="text-base font-bold text-zinc-950">
              Add Staff Member
            </h3>
            <p className="mt-1 text-xs text-zinc-500">
              Grant team access using the registered NearGrid User ULID identifier.
            </p>

            {addError && (
              <div role="alert" className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-2.5 text-xs text-rose-900">
                {addError}
              </div>
            )}

            <form onSubmit={handleAddMember} className="mt-4 space-y-4">
              <div>
                <label htmlFor="target-ulid" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Target User Public ULID <span className="text-rose-600">*</span>
                </label>
                <input
                  id="target-ulid"
                  type="text"
                  required
                  minLength={26}
                  maxLength={26}
                  value={targetUserUlid}
                  onChange={(e) => setTargetUserUlid(e.target.value)}
                  placeholder="e.g. 01ARZ3NDEKTSV4RRFFQ69G5FAV"
                  className="w-full rounded-xl border border-zinc-300 font-mono px-3.5 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                />
                <p className="mt-1 text-[11px] text-zinc-400">
                  Must be the 26-character identifier of an active registered user account.
                </p>
              </div>

              <div>
                <label htmlFor="member-role" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Assigned Staff Role <span className="text-rose-600">*</span>
                </label>
                <select
                  id="member-role"
                  required
                  value={selectedRole}
                  onChange={(e) => handleRoleChange(e.target.value as BusinessMemberRoleDto)}
                  className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                >
                  {ASSIGNABLE_ROLES.map((r) => (
                    <option key={r.role} value={r.role}>
                      {r.name} ({r.role})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <span className="mb-1.5 block text-xs font-semibold text-zinc-900">
                  Fine-Grained Permissions
                </span>
                <div className="grid grid-cols-1 gap-2 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs sm:grid-cols-2">
                  {AVAILABLE_PERMISSIONS.map((p) => {
                    const checked = selectedPermissions.includes(p.key);
                    return (
                      <label key={p.key} className="flex items-center gap-2 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() => togglePermission(p.key)}
                          className="h-4 w-4 rounded border-zinc-300 text-brand-600 focus:ring-brand-600"
                        />
                        <span className={checked ? 'font-medium text-zinc-900' : 'text-zinc-600'}>
                          {p.label}
                        </span>
                      </label>
                    );
                  })}
                </div>
              </div>

              <div className="flex items-center justify-end gap-2.5 border-t border-zinc-100 pt-3">
                <button
                  type="button"
                  onClick={() => setAddModalOpen(false)}
                  disabled={submitting}
                  className="rounded-xl border border-zinc-300 px-3.5 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
                >
                  {submitting ? (
                    <>
                      <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                      <span>Adding Member...</span>
                    </>
                  ) : (
                    <span>Add Member</span>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* --- MODAL: Edit Member Role --- */}
      {editingMember && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="edit-member-title"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
          onClick={(e) => {
            if (e.target === e.currentTarget) setEditingMember(null);
          }}
        >
          <div className="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
            <button
              type="button"
              onClick={() => setEditingMember(null)}
              aria-label="Close dialog"
              className="absolute right-4 top-4 rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700"
            >
              <X className="h-5 w-5" aria-hidden="true" />
            </button>

            <h3 id="edit-member-title" className="text-base font-bold text-zinc-950">
              Update Staff Member
            </h3>
            <p className="mt-1 text-xs text-zinc-500">
              Modifying permissions for {editingMember.user?.name || editingMember.user?.phone_e164 || editingMember.public_id}.
            </p>

            {editError && (
              <div role="alert" className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-2.5 text-xs text-rose-900">
                {editError}
              </div>
            )}

            <form onSubmit={handleUpdateMember} className="mt-4 space-y-4">
              <div>
                <label htmlFor="edit-role-select" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Role
                </label>
                <select
                  id="edit-role-select"
                  value={editRole}
                  onChange={(e) => setEditRole(e.target.value as BusinessMemberRoleDto)}
                  className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                >
                  {ASSIGNABLE_ROLES.map((r) => (
                    <option key={r.role} value={r.role}>
                      {r.name} ({r.role})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label htmlFor="edit-status-select" className="mb-1 block text-xs font-semibold text-zinc-900">
                  Status
                </label>
                <select
                  id="edit-status-select"
                  value={editStatus}
                  onChange={(e) => setEditStatus(e.target.value as 'active' | 'revoked')}
                  className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-950 focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
                >
                  <option value="active">Active (Access Allowed)</option>
                  <option value="revoked">Revoked (Access Suspended)</option>
                </select>
              </div>

              <div className="flex items-center justify-end gap-2.5 border-t border-zinc-100 pt-3">
                <button
                  type="button"
                  onClick={() => setEditingMember(null)}
                  disabled={savingEdit}
                  className="rounded-xl border border-zinc-300 px-3.5 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={savingEdit}
                  className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
                >
                  {savingEdit ? (
                    <>
                      <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                      <span>Saving...</span>
                    </>
                  ) : (
                    <span>Update</span>
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
