<?php
/**
 * GPS / geofence logic.
 *
 * The Haversine formula gives the great-circle distance between two points on a
 * sphere. It is used to decide whether a student's phone was inside the lecture
 * room geofence (default 50 m) when the QR code was scanned.
 */

function haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;              // mean radius of the Earth in metres

    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dPhi = deg2rad($lat2 - $lat1);
    $dLam = deg2rad($lng2 - $lng1);

    $a = sin($dPhi / 2) ** 2
       + cos($phi1) * cos($phi2) * sin($dLam / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * @return array{distance: float, inside: bool}
 */
function check_geofence(
    float $studentLat,
    float $studentLng,
    float $refLat,
    float $refLng,
    int $radius
): array {
    $distance = haversine_m($refLat, $refLng, $studentLat, $studentLng);

    return [
        'distance' => $distance,
        'inside'   => $distance <= $radius,
    ];
}

function valid_coordinates(float $lat, float $lng): bool
{
    return $lat >= -90.0 && $lat <= 90.0
        && $lng >= -180.0 && $lng <= 180.0
        && !($lat === 0.0 && $lng === 0.0);
}
