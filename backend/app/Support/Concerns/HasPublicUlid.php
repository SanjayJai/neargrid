<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicUlid
{
    public static function bootHasPublicUlid(): void
    {
        static::creating(function (Model $model): void {
            $column = $model->publicUlidColumn();

            if (empty($model->getAttribute($column))) {
                $model->setAttribute($column, (string) Str::ulid());
            }
        });
    }

    public function publicUlidColumn(): string
    {
        return 'public_id';
    }

    public function getRouteKeyName(): string
    {
        return $this->publicUlidColumn();
    }

    public static function findByPublicId(string $publicId): ?static
    {
        $instance = new static;

        return static::query()
            ->where($instance->publicUlidColumn(), $publicId)
            ->first();
    }
}
