<?php

declare(strict_types=1);

namespace App\Domains\Search\Services;

final class GeoSpatialHelper
{
    private const EARTH_RADIUS_KM = 6371.0088;

    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2) ** 2);

        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return round(self::EARTH_RADIUS_KM * $c, 3);
    }

    /**
     * Compute a safe WGS-84 bounding box around a coordinate for MySQL 8 SPATIAL INDEX pre-filtering.
     *
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}
     */
    public function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        $cosLat = max(0.01, cos(deg2rad($lat)));
        $lngDelta = rad2deg($radiusKm / (self::EARTH_RADIUS_KM * $cosLat));

        return [
            'min_lat' => max(-89.999999, $lat - $latDelta),
            'max_lat' => min(89.999999, $lat + $latDelta),
            'min_lng' => max(-179.999999, $lng - $lngDelta),
            'max_lng' => min(179.999999, $lng + $lngDelta),
        ];
    }

    /**
     * MySQL 8.0 SRID 4326 WKT Point in (latitude longitude) axis order.
     */
    public function pointWkt(float $lat, float $lng): string
    {
        return sprintf('POINT(%.7F %.7F)', $lat, $lng);
    }

    /**
     * MySQL 8.0 SRID 4326 WKT Polygon in (latitude longitude) axis order for MBRContains().
     */
    public function boundingPolygonWkt(float $lat, float $lng, float $radiusKm): string
    {
        $box = $this->boundingBox($lat, $lng, $radiusKm);

        return sprintf(
            'POLYGON((%.7F %.7F, %.7F %.7F, %.7F %.7F, %.7F %.7F, %.7F %.7F))',
            $box['min_lat'],
            $box['min_lng'],
            $box['min_lat'],
            $box['max_lng'],
            $box['max_lat'],
            $box['max_lng'],
            $box['max_lat'],
            $box['min_lng'],
            $box['min_lat'],
            $box['min_lng'],
        );
    }
}
