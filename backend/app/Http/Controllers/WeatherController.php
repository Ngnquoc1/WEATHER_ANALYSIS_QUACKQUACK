<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use App\Services\ReverseGeocodeService;
use App\Services\GeminiService;

class WeatherController extends Controller
{
    protected $geminiService;
    protected $reverseGeocodeService;

    public function __construct(GeminiService $geminiService, ReverseGeocodeService $reverseGeocodeService)
    {
        $this->geminiService = $geminiService;
        $this->reverseGeocodeService = $reverseGeocodeService;
    }

    /**
     * Weather code descriptions mapping (Vietnamese)
     */
    private const WEATHER_CODES = [
        0 => 'Trời quang đãng',
        1 => 'Chủ yếu quang đãng',
        2 => 'Có mây một phần',
        3 => 'Nhiều mây',
        45 => 'Sương mù',
        48 => 'Sương mù đóng băng',
        51 => 'Mưa phùn nhẹ',
        53 => 'Mưa phùn vừa',
        55 => 'Mưa phùn dày đặc',
        61 => 'Mưa nhỏ',
        63 => 'Mưa vừa',
        65 => 'Mưa to',
        71 => 'Tuyết rơi nhẹ',
        73 => 'Tuyết rơi vừa',
        75 => 'Tuyết rơi nặng',
        77 => 'Tuyết dạng hạt',
        80 => 'Mưa rào nhẹ',
        81 => 'Mưa rào vừa',
        82 => 'Mưa rào dữ dội',
        85 => 'Tuyết rơi nhẹ',
        86 => 'Tuyết rơi nặng',
        95 => 'Giông bão',
        96 => 'Giông có mưa đá nhẹ',
        99 => 'Giông có mưa đá nặng',
    ];

    /**
     * Get comprehensive weather data for a location
     * Includes current weather, forecasts, anomaly detection, and recommendations
     *
     * @param Request $request
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWeatherData(Request $request, $lat, $lon)
    {
        try {
            // Validate latitude and longitude
            if (!is_numeric($lat) || !is_numeric($lon)) {
                return response()->json([
                    'error' => 'Invalid latitude or longitude',
                    'message' => 'Latitude and longitude must be numeric values'
                ], 400);
            }

            // Validate ranges
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return response()->json([
                    'error' => 'Coordinates out of range',
                    'message' => 'Latitude must be between -90 and 90, longitude between -180 and 180'
                ], 400);
            }

            // Build Open-Meteo API URL with all required parameters
            $apiUrl = "https://api.open-meteo.com/v1/forecast";
            $params = [
                'latitude' => $lat,
                'longitude' => $lon,
                'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,precipitation',
                'hourly' => 'temperature_2m,weather_code,precipitation_probability',
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,temperature_2m_mean,precipitation_sum,precipitation_probability_max,rain_sum,showers_sum,snowfall_sum,windspeed_10m_max,winddirection_10m_dominant,pressure_msl_max,pressure_msl_min,pressure_msl_mean,relative_humidity_2m_max,relative_humidity_2m_min,relative_humidity_2m_mean,uv_index_max,uv_index_clear_sky_max',
                'timezone' => 'auto',
                'past_days' => 30,
                'forecast_days' => 7
            ];

            // Make HTTP request to Open-Meteo API using Guzzle
            $client = new Client([
                'timeout' => 10,
                'verify' => false  // Disable SSL verification for development
            ]);
            $response = $client->get($apiUrl, ['query' => $params]);
            $data = json_decode($response->getBody(), true);

            // Process and structure the response data
            $processedData = [
                'location' => [
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'timezone' => $data['timezone'],
                    'elevation' => $data['elevation'],
                    'name' => $this->reverseGeocodeService->reverse((float)$lat, (float)$lon),
                    'details' => $this->reverseGeocodeService->reverseDetailed((float)$lat, (float)$lon)
                ],
                'current_weather' => $this->processCurrentWeather($data['current']),
                'hourly_forecast' => $this->processHourlyForecast($data['hourly']),
                'daily_forecast' => $this->processDailyForecast($data['daily']),
                'anomaly' => $this->detectAnomaly($data['current'], $data['daily']),
                'recommendation' => $this->generateRecommendation($data['current'], $data['daily'])
            ];

            return response()->json($processedData);

        } catch (GuzzleException $e) {
            Log::error('Weather API Error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch weather data',
                'message' => 'Could not connect to weather service. Please try again later.'
            ], 503);
        } catch (\Exception $e) {
            Log::error('Unexpected Error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Server error',
                'message' => 'An unexpected error occurred'
            ], 500);
        }
    }

    /**
     * Compare weather data between two locations
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function compareLocations(Request $request)
    {
        try {
            // Validate request data
            $validated = $request->validate([
                'location1' => 'required|array',
                'location1.lat' => 'required|numeric|between:-90,90',
                'location1.lon' => 'required|numeric|between:-180,180',
                'location1.name' => 'sometimes|string',
                'location2' => 'required|array',
                'location2.lat' => 'required|numeric|between:-90,90',
                'location2.lon' => 'required|numeric|between:-180,180',
                'location2.name' => 'sometimes|string',
            ]);

            $location1 = $validated['location1'];
            $location2 = $validated['location2'];

            $location1Name = $location1['name'] ?? $this->reverseGeocodeService->reverse((float)$location1['lat'], (float)$location1['lon']);
            $location2Name = $location2['name'] ?? $this->reverseGeocodeService->reverse((float)$location2['lat'], (float)$location2['lon']);

            // Fetch weather data for both locations
            $weather1 = $this->fetchWeatherForComparison($location1['lat'], $location1['lon']);
            $weather2 = $this->fetchWeatherForComparison($location2['lat'], $location2['lon']);

            // Structure comparison data
            $comparison = [
                'location1' => [
                    'name' => $location1['name'] ?? "Location 1",
                    'coordinates' => ['lat' => $location1['lat'], 'lon' => $location1['lon']],
                    'current_weather' => $weather1['current_weather'],
                    'daily_summary' => $weather1['daily_summary']
                ],
                'location2' => [
                    'name' => $location2['name'] ?? "Location 2",
                    'coordinates' => ['lat' => $location2['lat'], 'lon' => $location2['lon']],
                    'current_weather' => $weather2['current_weather'],
                    'daily_summary' => $weather2['daily_summary']
                ],
                'differences' => $this->calculateDifferences($weather1['current_weather'], $weather2['current_weather'])
            ];

            return response()->json($comparison);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Location Comparison Error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Comparison failed',
                'message' => 'Could not compare locations'
            ], 500);
        }
    }

    /**
     * Generate detailed weather report using Gemini AI
     *
     * @param Request $request
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDetailedReport(Request $request, $lat, $lon)
    {
        try {
            // Validate coordinates
            if (!is_numeric($lat) || !is_numeric($lon)) {
                return response()->json([
                    'error' => 'Invalid coordinates',
                    'message' => 'Latitude and longitude must be numeric'
                ], 400);
            }

            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return response()->json([
                    'error' => 'Coordinates out of range',
                    'message' => 'Invalid coordinate values'
                ], 400);
            }

            // First, get the weather data
            $apiUrl = "https://api.open-meteo.com/v1/forecast";
            $params = [
                'latitude' => $lat,
                'longitude' => $lon,
                'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,precipitation',
                'hourly' => 'temperature_2m,weather_code,precipitation_probability',
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,uv_index_max,precipitation_sum',
                'timezone' => 'auto',
                'past_days' => 30,
                'forecast_days' => 7
            ];

            $client = new Client([
                'timeout' => 10,
                'verify' => false
            ]);
            $response = $client->get($apiUrl, ['query' => $params]);
            $data = json_decode($response->getBody(), true);

            // Process weather data
            $weatherData = [
                'location' => [
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'timezone' => $data['timezone'],
                    'elevation' => $data['elevation']
                ],
                'current_weather' => $this->processCurrentWeather($data['current']),
                'daily_forecast' => $this->processDailyForecast($data['daily']),
                'anomaly' => $this->detectAnomaly($data['current'], $data['daily'])
            ];

            // Generate detailed report using Gemini AI
            $reportData = $this->geminiService->generateDetailedReport($weatherData, $lat, $lon);

            return response()->json($reportData);

        } catch (GuzzleException $e) {
            Log::error('Detailed Report - Weather API Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch weather data',
                'message' => 'Could not connect to weather service'
            ], 503);
        } catch (\Exception $e) {
            Log::error('Detailed Report Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate report',
                'message' => 'An error occurred while generating the report'
            ], 500);
        }
    }

    /**
     * Fetch weather data specifically for comparison purposes
     *
     * @param float $lat
     * @param float $lon
     * @return array
     */
    private function fetchWeatherForComparison($lat, $lon)
    {
        $apiUrl = "https://api.open-meteo.com/v1/forecast";
        $params = [
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,precipitation',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,temperature_2m_mean,precipitation_sum,precipitation_probability_max,rain_sum,showers_sum,snowfall_sum,windspeed_10m_max,winddirection_10m_dominant,pressure_msl_max,pressure_msl_min,pressure_msl_mean,relative_humidity_2m_max,relative_humidity_2m_min,relative_humidity_2m_mean,uv_index_max,uv_index_clear_sky_max',
            'timezone' => 'auto',
            'forecast_days' => 1
        ];

        $client = new Client([
            'timeout' => 10,
            'verify' => false  // Disable SSL verification for development
        ]);
        $response = $client->get($apiUrl, ['query' => $params]);
        $data = json_decode($response->getBody(), true);

        return [
            'current_weather' => $this->processCurrentWeather($data['current']),
            'daily_summary' => [
                'max_temp' => $data['daily']['temperature_2m_max'][0],
                'min_temp' => $data['daily']['temperature_2m_min'][0],
                'uv_index' => $data['daily']['uv_index_max'][0],
                'weather_description' => $this->getWeatherDescription($data['daily']['weather_code'][0])
            ]
        ];
    }

    /**
     * Process current weather data into a structured format
     *
     * @param array $current
     * @return array
     */
    private function processCurrentWeather($current)
    {
        $weatherCode = $current['weather_code'];
        return [
            'time' => $current['time'],
            'temperature' => round($current['temperature_2m'], 1),
            'apparent_temperature' => round($current['apparent_temperature'], 1),
            'humidity' => $current['relative_humidity_2m'],
            'wind_speed' => round($current['wind_speed_10m'], 1),
            'precipitation' => $current['precipitation'] ?? 0,
            'weather_code' => $weatherCode,
            'weather_description' => $this->getWeatherDescription($weatherCode),
            'weather_main' => $this->getWeatherMain($weatherCode)
        ];
    }

    /**
     * Process hourly forecast data - returns next 24 hours
     * Converts time-series data into array of objects
     *
     * @param array $hourly
     * @return array
     */
    private function processHourlyForecast($hourly)
    {
        $forecast = [];
        $currentHourIndex = $this->findCurrentHourIndex($hourly['time']);
        
        // Get next 24 hours starting from current hour
        for ($i = $currentHourIndex; $i < $currentHourIndex + 24 && $i < count($hourly['time']); $i++) {
            $forecast[] = [
                'time' => $hourly['time'][$i],
                'temperature' => round($hourly['temperature_2m'][$i], 1),
                'weather_code' => $hourly['weather_code'][$i],
                'weather_description' => $this->getWeatherDescription($hourly['weather_code'][$i]),
                'precipitation_probability' => $hourly['precipitation_probability'][$i] ?? 0
            ];
        }

        return $forecast;
    }

    /**
     * Process daily forecast data - returns next 7 days
     * Converts time-series data into array of objects
     *
     * @param array $daily
     * @return array
     */
    private function processDailyForecast($daily)
    {
        $forecast = [];
        $totalDays = count($daily['time']);
        // Get the last 7 days (future forecast)
        $startIndex = max(0, $totalDays - 7);

        for ($i = $startIndex; $i < $totalDays; $i++) {
            $forecast[] = [
                'date' => $daily['time'][$i],
                'weather_code' => $daily['weather_code'][$i],
                'weather_description' => $this->getWeatherDescription($daily['weather_code'][$i]),
                
                // Nhiệt độ
                'temperature_2m_max' => round($daily['temperature_2m_max'][$i], 1),
                'temperature_2m_min' => round($daily['temperature_2m_min'][$i], 1),
                'temperature_2m_mean' => isset($daily['temperature_2m_mean'][$i]) ? round($daily['temperature_2m_mean'][$i], 1) : null,
                
                // Mưa
                'precipitation_sum' => round($daily['precipitation_sum'][$i] ?? 0, 1),
                'precipitation_probability_max' => $daily['precipitation_probability_max'][$i] ?? 0,
                'rain_sum' => round($daily['rain_sum'][$i] ?? 0, 1),
                'showers_sum' => round($daily['showers_sum'][$i] ?? 0, 1),
                'snowfall_sum' => round($daily['snowfall_sum'][$i] ?? 0, 1),
                
                // Gió
                'windspeed_10m_max' => round($daily['windspeed_10m_max'][$i] ?? 0, 1),
                'winddirection_10m_dominant' => $daily['winddirection_10m_dominant'][$i] ?? 0,
                
                // Áp suất
                'pressure_msl_max' => round($daily['pressure_msl_max'][$i] ?? 0, 1),
                'pressure_msl_min' => round($daily['pressure_msl_min'][$i] ?? 0, 1),
                'pressure_msl_mean' => isset($daily['pressure_msl_mean'][$i]) ? round($daily['pressure_msl_mean'][$i], 1) : null,
                
                // Độ ẩm
                'relative_humidity_2m_max' => $daily['relative_humidity_2m_max'][$i] ?? 0,
                'relative_humidity_2m_min' => $daily['relative_humidity_2m_min'][$i] ?? 0,
                'relative_humidity_2m_mean' => isset($daily['relative_humidity_2m_mean'][$i]) ? $daily['relative_humidity_2m_mean'][$i] : null,
                
                // UV
                'uv_index_max' => round($daily['uv_index_max'][$i] ?? 0, 1),
                'uv_index_clear_sky_max' => round($daily['uv_index_clear_sky_max'][$i] ?? 0, 1),
                
                // Backward compatibility
                'max_temperature' => round($daily['temperature_2m_max'][$i], 1),
                'min_temperature' => round($daily['temperature_2m_min'][$i], 1),
                'uv_index' => round($daily['uv_index_max'][$i] ?? 0, 1)
            ];
        }

        return $forecast;
    }

    /**
     * Detect temperature anomalies by comparing current temp with 30-day average
     * This is the anomaly detection algorithm
     *
     * @param array $current
     * @param array $daily
     * @return array
     */
    private function detectAnomaly($current, $daily)
    {
        // Calculate average max temperature from past 30 days (historical data)
        $totalDays = count($daily['temperature_2m_max']);
        // Get historical data (excluding forecast days)
        $historicalData = array_slice($daily['temperature_2m_max'], 0, min(30, $totalDays - 7));
        
        if (empty($historicalData)) {
            return [
                'is_anomaly' => false,
                'message' => 'Không đủ dữ liệu lịch sử để phát hiện bất thường'
            ];
        }

        // Calculate average
        $avgMaxTemp = array_sum($historicalData) / count($historicalData);
        $currentTemp = $current['temperature_2m'];
        $difference = $currentTemp - $avgMaxTemp;

        // Consider it an anomaly if difference is greater than 5°C
        if (abs($difference) > 5) {
            $direction = $difference > 0 ? 'cao hơn' : 'thấp hơn';
            return [
                'is_anomaly' => true,
                'type' => $difference > 0 ? 'hot' : 'cold',
                'difference' => round(abs($difference), 1),
                'average_temp' => round($avgMaxTemp, 1),
                'current_temp' => round($currentTemp, 1),
                'message' => "⚠️ Nhiệt độ hiện tại {$direction} " . round(abs($difference), 1) . "°C so với trung bình 30 ngày qua (" . round($avgMaxTemp, 1) . "°C)"
            ];
        }

        return [
            'is_anomaly' => false,
            'average_temp' => round($avgMaxTemp, 1),
            'current_temp' => round($currentTemp, 1),
            'message' => 'Nhiệt độ hiện tại nằm trong mức bình thường'
        ];
    }

    /**
     * Generate smart recommendations based on weather conditions
     * Now powered by Gemini AI for context-aware suggestions
     *
     * @param array $current
     * @param array $daily
     * @return string
     */
    private function generateRecommendation($current, $daily)
    {
        try {
            // Use Gemini AI for smart recommendations
            return $this->geminiService->generateRecommendation($current, $daily);
        } catch (\Exception $e) {
            Log::error('Recommendation generation error: ' . $e->getMessage());
            
            // Fallback to simple rule-based recommendations
            $recommendations = [];
            
            $todayUV = $daily['uv_index_max'][count($daily['uv_index_max']) - 7] ?? 0;
            if ($todayUV >= 8) {
                $recommendations[] = "☀️ Chỉ số UV rất cao ({$todayUV}). Nên sử dụng kem chống nắng SPF 50+, đội mũ và đeo kính râm.";
            } elseif ($todayUV >= 6) {
                $recommendations[] = "☀️ Chỉ số UV cao ({$todayUV}). Nên sử dụng kem chống nắng và hạn chế ra ngoài vào giữa trưa.";
            }

            $temp = $current['temperature_2m'];
            if ($temp >= 35) {
                $recommendations[] = "🌡️ Nhiệt độ rất cao ({$temp}°C). Hãy uống nhiều nước, tránh hoạt động ngoài trời.";
            } elseif ($temp <= 15) {
                $recommendations[] = "🧥 Nhiệt độ khá thấp ({$temp}°C). Nên mặc áo ấm khi ra ngoài.";
            }

            $weatherCode = $current['weather_code'];
            if (in_array($weatherCode, [61, 63, 65, 80, 81, 82])) {
                $recommendations[] = "☔ Trời đang mưa. Nhớ mang theo áo mưa hoặc ô.";
            } elseif (in_array($weatherCode, [95, 96, 99])) {
                $recommendations[] = "⛈️ Cảnh báo giông bão. Nên ở trong nhà.";
            }

            if (empty($recommendations)) {
                $recommendations[] = "✅ Thời tiết thuận lợi cho các hoạt động ngoài trời!";
            }

            return implode(" ", $recommendations);
        }
    }

    /**
     * Calculate differences between two locations' weather
     *
     * @param array $weather1
     * @param array $weather2
     * @return array
     */
    private function calculateDifferences($weather1, $weather2)
    {
        return [
            'temperature_diff' => round($weather1['temperature'] - $weather2['temperature'], 1),
            'humidity_diff' => $weather1['humidity'] - $weather2['humidity'],
            'wind_speed_diff' => round($weather1['wind_speed'] - $weather2['wind_speed'], 1),
            'apparent_temperature_diff' => round($weather1['apparent_temperature'] - $weather2['apparent_temperature'], 1)
        ];
    }

    /**
     * Get weather description in Vietnamese based on WMO weather code
     *
     * @param int $code
     * @return string
     */
    private function getWeatherDescription($code)
    {
        return self::WEATHER_CODES[$code] ?? 'Không xác định';
    }

    /**
     * Get weather main category (Rain, Clear, Clouds, etc.)
     * Used for product recommendations
     *
     * @param int $code Weather code
     * @return string
     */
    private function getWeatherMain($code)
    {
        // Map weather codes to main categories
        if ($code === 0) return 'Clear';
        if ($code >= 1 && $code <= 3) return 'Clouds';
        if ($code >= 45 && $code <= 48) return 'Fog';
        if ($code >= 51 && $code <= 55) return 'Drizzle';
        if ($code >= 61 && $code <= 65) return 'Rain';
        if ($code >= 71 && $code <= 77) return 'Snow';
        if ($code >= 80 && $code <= 82) return 'Rain';
        if ($code >= 85 && $code <= 86) return 'Snow';
        if ($code >= 95 && $code <= 99) return 'Thunderstorm';
        return 'Unknown';
    }

    /**
     * Get weather data for multiple cities in bulk
     * This endpoint reduces API calls by fetching data for all cities at once
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBulkWeatherData(Request $request)
    {
        try {
            // Define major cities with their coordinates
            $majorCities = [
                ['name' => 'Hà Nội', 'lat' => 21.0285, 'lon' => 105.8542, 'country' => 'Vietnam'],
                ['name' => 'TP.HCM', 'lat' => 10.8231, 'lon' => 106.6297, 'country' => 'Vietnam'],
                ['name' => 'Đà Nẵng', 'lat' => 16.0544, 'lon' => 108.2022, 'country' => 'Vietnam'],
                ['name' => 'Tokyo', 'lat' => 35.6762, 'lon' => 139.6503, 'country' => 'Japan'],
                ['name' => 'Seoul', 'lat' => 37.5665, 'lon' => 126.9780, 'country' => 'South Korea'],
                ['name' => 'Beijing', 'lat' => 39.9042, 'lon' => 116.4074, 'country' => 'China'],
                ['name' => 'Shanghai', 'lat' => 31.2304, 'lon' => 121.4737, 'country' => 'China'],
                ['name' => 'New York', 'lat' => 40.7128, 'lon' => -74.0060, 'country' => 'USA'],
                ['name' => 'Los Angeles', 'lat' => 34.0522, 'lon' => -118.2437, 'country' => 'USA'],
                ['name' => 'London', 'lat' => 51.5074, 'lon' => -0.1278, 'country' => 'UK'],
                ['name' => 'Paris', 'lat' => 48.8566, 'lon' => 2.3522, 'country' => 'France'],
                ['name' => 'Berlin', 'lat' => 52.5200, 'lon' => 13.4050, 'country' => 'Germany'],
                ['name' => 'Rome', 'lat' => 41.9028, 'lon' => 12.4964, 'country' => 'Italy'],
                ['name' => 'Sydney', 'lat' => -33.8688, 'lon' => 151.2093, 'country' => 'Australia'],
                ['name' => 'Melbourne', 'lat' => -37.8136, 'lon' => 144.9631, 'country' => 'Australia'],
                ['name' => 'Mumbai', 'lat' => 19.0760, 'lon' => 72.8777, 'country' => 'India'],
                ['name' => 'Delhi', 'lat' => 28.7041, 'lon' => 77.1025, 'country' => 'India'],
                ['name' => 'Dubai', 'lat' => 25.2048, 'lon' => 55.2708, 'country' => 'UAE'],
                ['name' => 'São Paulo', 'lat' => -23.5505, 'lon' => -46.6333, 'country' => 'Brazil'],
                ['name' => 'Mexico City', 'lat' => 19.4326, 'lon' => -99.1332, 'country' => 'Mexico'],
                ['name' => 'Cairo', 'lat' => 30.0444, 'lon' => 31.2357, 'country' => 'Egypt'],
                ['name' => 'Bangkok', 'lat' => 13.7563, 'lon' => 100.5018, 'country' => 'Thailand'],
                ['name' => 'Singapore', 'lat' => 1.3521, 'lon' => 103.8198, 'country' => 'Singapore'],
                ['name' => 'Jakarta', 'lat' => -6.2088, 'lon' => 106.8456, 'country' => 'Indonesia']
            ];

            $client = new Client([
                'timeout' => 30, // Increased timeout for bulk request
                'verify' => false
            ]);

            $bulkData = [];
            $errors = [];

            // Process cities in batches to avoid overwhelming the API
            $batchSize = 5;
            $batches = array_chunk($majorCities, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                $promises = [];
                
                foreach ($batch as $city) {
                    $promises[$city['name']] = $this->createWeatherPromise($client, $city);
                }

                // Execute batch requests
                try {
                    $responses = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
                    
                    foreach ($responses as $cityName => $response) {
                        if ($response['state'] === 'fulfilled') {
                            $weatherData = json_decode($response['value']->getBody(), true);
                            $cityData = $this->findCityByName($majorCities, $cityName);
                            
                            $bulkData[] = [
                                'name' => $cityData['name'],
                                'lat' => $cityData['lat'],
                                'lon' => $cityData['lon'],
                                'country' => $cityData['country'],
                                'precipitation' => $weatherData['current']['precipitation'] ?? 0,
                                'temperature' => round($weatherData['current']['temperature_2m'] ?? 0, 1),
                                'humidity' => $weatherData['current']['relative_humidity_2m'] ?? 0,
                                'wind_speed' => round($weatherData['current']['wind_speed_10m'] ?? 0, 1),
                                'weather_description' => $this->getWeatherDescription($weatherData['current']['weather_code'] ?? 0)
                            ];
                        } else {
                            $errors[] = [
                                'city' => $cityName,
                                'error' => $response['reason']->getMessage()
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Batch {$batchIndex} failed: " . $e->getMessage());
                    $errors[] = [
                        'batch' => $batchIndex,
                        'error' => $e->getMessage()
                    ];
                }

                // Small delay between batches to be respectful to the API
                if ($batchIndex < count($batches) - 1) {
                    usleep(200000); // 200ms delay
                }
            }

            return response()->json([
                'success' => true,
                'data' => $bulkData,
                'total_cities' => count($bulkData),
                'errors' => $errors,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk Weather API Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch bulk weather data',
                'message' => 'An error occurred while fetching weather data for multiple cities',
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    /**
     * Create a promise for weather data request
     *
     * @param Client $client
     * @param array $city
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function createWeatherPromise($client, $city)
    {
        $apiUrl = "https://api.open-meteo.com/v1/forecast";
        $params = [
            'latitude' => $city['lat'],
            'longitude' => $city['lon'],
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,precipitation',
            'timezone' => 'auto'
        ];

        return $client->getAsync($apiUrl, ['query' => $params]);
    }

    /**
     * Find city data by name
     *
     * @param array $cities
     * @param string $name
     * @return array|null
     */
    private function findCityByName($cities, $name)
    {
        foreach ($cities as $city) {
            if ($city['name'] === $name) {
                return $city;
            }
        }
        return null;
    }

    /**
     * Find the index of the current hour in hourly forecast data
     *
     * @param array $timeArray
     * @return int
     */
    private function findCurrentHourIndex($timeArray)
    {
        $currentTime = now();
        
        foreach ($timeArray as $index => $time) {
            $forecastTime = \Carbon\Carbon::parse($time);
            
            // If forecast time is within the next hour, use this index
            if ($forecastTime->isAfter($currentTime) || $forecastTime->diffInMinutes($currentTime) <= 60) {
                return $index;
            }
        }
        
        // Fallback: return 0 if no suitable time found
        return 0;
    }

    /**
     * Proxy for Open-Meteo Geocoding API to avoid CORS issues
     * Search for locations by name
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchLocation(Request $request)
    {
        try {
            $query = $request->input('query');
            
            if (!$query || strlen($query) < 2) {
                return response()->json([
                    'error' => 'Query too short',
                    'message' => 'Search query must be at least 2 characters'
                ], 400);
            }

            // Call Open-Meteo Geocoding API
            $client = new Client([
                'timeout' => 10,
                'verify' => false
            ]);

            $response = $client->get('https://geocoding-api.open-meteo.com/v1/search', [
                'query' => [
                    'name' => $query,
                    'count' => 10,
                    'language' => 'vi',
                    'format' => 'json'
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            // Return results or empty array if no results
            return response()->json([
                'results' => $data['results'] ?? []
            ]);

        } catch (GuzzleException $e) {
            Log::error('Geocoding API error', [
                'message' => $e->getMessage(),
                'query' => $request->input('query')
            ]);

            return response()->json([
                'error' => 'Geocoding API error',
                'message' => 'Could not search for locations. Please try again.'
            ], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in searchLocation', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Server error',
                'message' => 'An unexpected error occurred'
            ], 500);
        }
    }

    /**
     * Reverse geocode coordinates to detailed location information
     *
     * @param Request $request
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return \Illuminate\Http\JsonResponse
     */
    public function reverseGeocode(Request $request, $lat, $lon)
    {
        try {
            // Validate coordinates
            if (!is_numeric($lat) || !is_numeric($lon)) {
                return response()->json([
                    'error' => 'Invalid coordinates',
                    'message' => 'Latitude and longitude must be numeric'
                ], 400);
            }

            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return response()->json([
                    'error' => 'Coordinates out of range',
                    'message' => 'Invalid coordinate values'
                ], 400);
            }

            $details = $this->reverseGeocodeService->reverseDetailed((float)$lat, (float)$lon);

            return response()->json($details);

        } catch (\Exception $e) {
            Log::error('Reverse geocode error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Reverse geocode failed',
                'message' => 'Could not get location details'
            ], 500);
        }
    }
}
