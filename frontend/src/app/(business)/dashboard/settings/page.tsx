import { getServerAuthenticatedUser } from '@/lib/bff/session-cookie';
import { AccountSettingsClient } from '@/modules/business/components/AccountSettingsClient';

export default async function AccountAndDeviceSettingsPage() {
  const user = await getServerAuthenticatedUser();

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">
          Account &amp; Device Settings
        </h1>
        <p className="mt-1 text-sm text-zinc-600">
          Manage your verified identity, registered web/mobile devices, and active Sanctum sessions.
        </p>
      </div>

      <AccountSettingsClient initialUser={user} />
    </div>
  );
}
