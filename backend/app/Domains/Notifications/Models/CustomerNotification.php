<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Notifications\Enums\NotificationCategory;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @property string $id
 * @property string $type
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property NotificationCategory $category
 * @property string|null $deduplication_key
 * @property array<string, mixed> $data
 * @property CarbonImmutable|Carbon|null $read_at
 * @property CarbonImmutable|Carbon|null $deleted_at
 * @property CarbonImmutable|Carbon|null $created_at
 * @property CarbonImmutable|Carbon|null $updated_at
 */
class CustomerNotification extends DatabaseNotification
{
    use SoftDeletes;

    /**
     * @var string
     */
    protected $table = 'notifications';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'deleted_at' => 'datetime',
            'category' => NotificationCategory::class,
        ];
    }
}
