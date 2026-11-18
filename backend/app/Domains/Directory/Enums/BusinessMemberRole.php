<?php

declare(strict_types=1);

namespace App\Domains\Directory\Enums;

enum BusinessMemberRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Editor = 'editor';
    case LeadAgent = 'lead_agent';

    /**
     * Default capability matrix per staff role.
     *
     * @return list<string>
     */
    public function defaultCapabilities(): array
    {
        return match ($this) {
            self::Owner => [
                'business.view',
                'profile.edit',
                'hours.edit',
                'services.edit',
                'leads.manage',
                'reviews.reply',
                'verification.submit',
                'staff.manage',
            ],
            self::Manager => [
                'business.view',
                'profile.edit',
                'hours.edit',
                'services.edit',
                'leads.manage',
                'reviews.reply',
                'verification.submit',
            ],
            self::Editor => [
                'business.view',
                'profile.edit',
                'hours.edit',
                'services.edit',
            ],
            self::LeadAgent => [
                'business.view',
                'leads.manage',
                'reviews.reply',
            ],
        };
    }

    /**
     * Roles that a business owner can assign to staff members.
     *
     * @return list<string>
     */
    public static function assignableStaffRoles(): array
    {
        return [
            self::Manager->value,
            self::Editor->value,
            self::LeadAgent->value,
        ];
    }
}
