<?php

declare(strict_types=1);

namespace App\Domains\Governance\Services;

use App\Domains\Governance\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        ?User $actor,
        string $action,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $actorRole = null,
        ?string $ipAddress = null,
    ): AuditLog {
        $resolvedRole = $actorRole;
        if ($resolvedRole === null && $actor !== null) {
            $resolvedRole = $actor->roles()->pluck('name')->first();
        }

        return AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_role' => $resolvedRole,
            'action' => $action,
            'auditable_type' => $auditable::class,
            'auditable_id' => (int) $auditable->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress ?? request()?->ip(),
        ]);
    }
}
