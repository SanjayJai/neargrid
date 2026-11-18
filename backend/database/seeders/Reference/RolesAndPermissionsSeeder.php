<?php

declare(strict_types=1);

namespace Database\Seeders\Reference;

use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'reviews.create', 'label' => 'Create Customer Reviews', 'group_name' => 'reviews'],
            ['name' => 'enquiries.create', 'label' => 'Submit Consented Enquiries', 'group_name' => 'leads'],
            ['name' => 'bookmarks.manage', 'label' => 'Manage Saved Bookmarks', 'group_name' => 'customer'],
            ['name' => 'business.create', 'label' => 'Register Business Listing', 'group_name' => 'business'],
            ['name' => 'business.manage_own', 'label' => 'Manage Owned Business Profile', 'group_name' => 'business'],
            ['name' => 'leads.manage_own', 'label' => 'Manage Inbound Leads for Owned Business', 'group_name' => 'leads'],
            ['name' => 'reviews.reply_own', 'label' => 'Reply to Reviews on Owned Business', 'group_name' => 'reviews'],
            ['name' => 'billing.manage_own', 'label' => 'Manage Subscriptions & Invoices for Owned Business', 'group_name' => 'billing'],
            ['name' => 'kyc.review', 'label' => 'Review Merchant KYC Documents', 'group_name' => 'verification'],
            ['name' => 'badges.manage', 'label' => 'Grant or Revoke Verified/Trust Badges', 'group_name' => 'verification'],
            ['name' => 'reviews.moderate', 'label' => 'Moderate Flagged Reviews & Media', 'group_name' => 'moderation'],
            ['name' => 'taxonomy.manage', 'label' => 'Manage Categories & Translations', 'group_name' => 'taxonomy'],
            ['name' => 'locations.manage', 'label' => 'Manage States, Cities & Localities', 'group_name' => 'location'],
            ['name' => 'audit_logs.view', 'label' => 'View Immutable Audit Logs', 'group_name' => 'governance'],
            ['name' => 'roles.manage', 'label' => 'Assign Roles & Permissions', 'group_name' => 'identity'],
        ];

        $permissionIds = [];
        foreach ($permissions as $permission) {
            $model = Permission::query()->updateOrCreate(
                ['name' => $permission['name']],
                ['label' => $permission['label'], 'group_name' => $permission['group_name']]
            );
            $permissionIds[$permission['name']] = $model->id;
        }

        $roles = [
            'customer' => [
                'label' => 'Customer',
                'description' => 'Standard consumer account for enquiries, reviews, and bookmarks.',
                'permissions' => ['reviews.create', 'enquiries.create', 'bookmarks.manage', 'business.create'],
            ],
            'business_owner' => [
                'label' => 'Business Owner',
                'description' => 'Merchant managing owned listings, inbound leads, reviews, and billing.',
                'permissions' => [
                    'reviews.create',
                    'enquiries.create',
                    'bookmarks.manage',
                    'business.create',
                    'business.manage_own',
                    'leads.manage_own',
                    'reviews.reply_own',
                    'billing.manage_own',
                ],
            ],
            'kyc_verifier' => [
                'label' => 'KYC Verifier',
                'description' => 'Admin operator auditing KYC submissions and verification badges.',
                'permissions' => ['kyc.review', 'badges.manage', 'audit_logs.view'],
            ],
            'moderator' => [
                'label' => 'Content Moderator',
                'description' => 'Admin operator moderating flagged reviews, photos, and taxonomy.',
                'permissions' => ['reviews.moderate', 'taxonomy.manage', 'locations.manage'],
            ],
            'support' => [
                'label' => 'Customer Support',
                'description' => 'Support staff handling user and merchant inquiries.',
                'permissions' => ['reviews.moderate', 'audit_logs.view'],
            ],
            'super_admin' => [
                'label' => 'Super Administrator',
                'description' => 'Full platform administrative authority.',
                'permissions' => array_keys($permissionIds),
            ],
        ];

        foreach ($roles as $name => $definition) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name],
                ['label' => $definition['label'], 'description' => $definition['description']]
            );

            $ids = array_map(
                static fn (string $perm): int => $permissionIds[$perm],
                $definition['permissions']
            );

            $role->permissions()->sync($ids);
        }
    }
}
