<?php

declare(strict_types=1);

namespace Database\Seeders\Development;

use App\Domains\Identity\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Strictly non-production sample accounts for local/staging development.
 * Never seeds fabricated real-world businesses or runs in production.
 */
class DevSampleSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DevSampleSeeder must never run in production.');
        }

        $samples = [
            [
                'email' => 'dev.customer@localbiz.test',
                'phone_e164' => '+919000000001',
                'name' => 'Dev Customer',
                'role' => 'customer',
            ],
            [
                'email' => 'dev.owner@localbiz.test',
                'phone_e164' => '+919000000002',
                'name' => 'Dev Business Owner',
                'role' => 'business_owner',
            ],
            [
                'email' => 'dev.admin@localbiz.test',
                'phone_e164' => '+919000000003',
                'name' => 'Dev Super Admin',
                'role' => 'super_admin',
            ],
        ];

        foreach ($samples as $sample) {
            $user = User::query()->updateOrCreate(
                ['email' => $sample['email']],
                [
                    'name' => $sample['name'],
                    'phone_e164' => $sample['phone_e164'],
                    'phone_verified_at' => now(),
                    'email_verified_at' => now(),
                    'password' => 'DevPassword!2026',
                    'status' => 'active',
                ]
            );

            $role = Role::query()->where('name', $sample['role'])->first();
            if ($role !== null) {
                $user->roles()->syncWithoutDetaching([$role->id]);
            }

            if ($sample['role'] === 'business_owner') {
                $biz = \App\Domains\Directory\Models\Business::first();
                if ($biz !== null) {
                    $biz->owner_user_id = $user->id;
                    $biz->is_claimed = true;
                    $biz->is_verified = true;
                    $biz->verified_at = now();
                    $biz->is_trusted = true;
                    $biz->completeness_score = 85;
                    $biz->save();
                }
            }
        }
    }
}
