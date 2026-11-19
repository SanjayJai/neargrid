<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class AuditLogPolicy
{
    public function viewAdmin(User $actor): bool
    {
        return $actor->hasPermission('audit_logs.view') || $actor->hasRole('super_admin');
    }
}
