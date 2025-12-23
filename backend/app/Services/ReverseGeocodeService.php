<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReverseGeocodeService
{
    /**
     * Reverse geocode lat/lon to human-readable name using Nominatim (cached).
     * Returns detailed location information from display_name.
     */
    public function reverse(float $lat, float $lon): string
    {
        $lat = round($lat, 4);
        $lon = round($lon, 4);
        $cacheKey = "reverse_geocode:{$lat}:{$lon}";
        $ttl = config('services.reverse_geocode_cache_ttl', 86400);

        return Cache::remember($cacheKey, $ttl, function () use ($lat, $lon) {
            try {
                $response = Http::timeout(5)
                    ->withHeaders([
                        'User-Agent' => config('app.name', 'Weather-Dashboard') . '/1.0'
                    ])
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'format' => 'jsonv2',
                        'lat' => $lat,
                        'lon' => $lon,
                        'addressdetails' => 1,
                        'accept-language' => 'vi',
                        'zoom' => 18
                    ]);

                if (!$response->ok()) {
                    return $this->fallbackName($lat, $lon);
                }

                $data = $response->json();
                
                // Use display_name for detailed address
                if (isset($data['display_name'])) {
                    return $data['display_name'];
                }
                
                // Fallback: construct from address components
                if (isset($data['address'])) {
                    $address = $data['address'];
                    $parts = [];
                    
                    // Build detailed address from components
                    if (isset($address['house_number'])) {
                        $parts[] = $address['house_number'];
                    }
                    if (isset($address['road'])) {
                        $parts[] = $address['road'];
                    }
                    if (isset($address['neighbourhood'])) {
                        $parts[] = $address['neighbourhood'];
                    }
                    if (isset($address['suburb'])) {
                        $parts[] = $address['suburb'];
                    }
                    if (isset($address['city'])) {
                        $parts[] = $address['city'];
                    }
                    if (isset($address['state'])) {
                        $parts[] = $address['state'];
                    }
                    if (isset($address['country'])) {
                        $parts[] = $address['country'];
                    }
                    
                    return !empty($parts) ? implode(', ', $parts) : $this->fallbackName($lat, $lon);
                }

                return $this->fallbackName($lat, $lon);
            } catch (\Throwable $e) {
                Log::warning('Reverse geocode failed', [
                    'lat' => $lat,
                    'lon' => $lon,
                    'error' => $e->getMessage(),
                ]);

                return $this->fallbackName($lat, $lon);
            }
        });
    }

    /**
     * Get detailed location information (full address object)
     * Returns array with display_name and address components
     */
    public function reverseDetailed(float $lat, float $lon): array
    {
        $lat = round($lat, 4);
        $lon = round($lon, 4);
        $cacheKey = "reverse_geocode_detailed:{$lat}:{$lon}";
        $ttl = config('services.reverse_geocode_cache_ttl', 86400);

        return Cache::remember($cacheKey, $ttl, function () use ($lat, $lon) {
            try {
                $response = Http::timeout(5)
                    ->withHeaders([
                        'User-Agent' => config('app.name', 'Weather-Dashboard') . '/1.0'
                    ])
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'format' => 'jsonv2',
                        'lat' => $lat,
                        'lon' => $lon,
                        'addressdetails' => 1,
                        'accept-language' => 'vi',
                        'zoom' => 18
                    ]);

                if (!$response->ok()) {
                    return [
                        'display_name' => $this->fallbackName($lat, $lon),
                        'address' => []
                    ];
                }

                $data = $response->json();
                
                return [
                    'display_name' => $data['display_name'] ?? $this->fallbackName($lat, $lon),
                    'address' => $data['address'] ?? [],
                    'name' => $data['name'] ?? null,
                    'place_id' => $data['place_id'] ?? null
                ];
            } catch (\Throwable $e) {
                Log::warning('Detailed reverse geocode failed', [
                    'lat' => $lat,
                    'lon' => $lon,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'display_name' => $this->fallbackName($lat, $lon),
                    'address' => []
                ];
            }
        });
    }

    private function fallbackName(float $lat, float $lon): string
    {
        return "Vị trí ({$lat}, {$lon})";
    }
}

