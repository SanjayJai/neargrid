<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

trait HasSpatialCenterPoint
{
    public static function bootHasSpatialCenterPoint(): void
    {
        static::saving(function (Model $model): void {
            if ($model->getConnection()->getDriverName() !== 'mysql') {
                return;
            }

            $lat = (float) $model->getAttribute('latitude');
            $lng = (float) $model->getAttribute('longitude');

            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                throw new InvalidArgumentException('Coordinates out of valid WGS-84 bounds.');
            }

            // MySQL 8.0 SRID 4326 uses (latitude longitude) axis order by default.
            $wkt = sprintf('POINT(%.7F %.7F)', $lat, $lng);
            $column = method_exists($model, 'spatialPointColumn')
                ? $model->spatialPointColumn()
                : 'center_point';
            $model->setAttribute($column, DB::raw("ST_GeomFromText('{$wkt}', 4326)"));
        });
    }
}
