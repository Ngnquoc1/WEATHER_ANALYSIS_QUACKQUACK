<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WeatherController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Authentication routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Public Weather API Routes - allow guest access
Route::prefix('weather')->group(function () {
    // Get comprehensive weather data for a specific location
    // Available to both guests and authenticated users
    Route::get('/{lat}/{lon}', [WeatherController::class, 'getWeatherData']);
    // Generate detailed AI-powered weather report
    Route::get('/report/{lat}/{lon}', [WeatherController::class, 'getDetailedReport']);
});

// Location search API - proxy for Open-Meteo Geocoding API to avoid CORS
Route::get('/location/search', [WeatherController::class, 'searchLocation']);

// Reverse geocode API - get detailed location info from coordinates
Route::get('/location/reverse/{lat}/{lon}', [WeatherController::class, 'reverseGeocode']);

// Affiliate product recommendations based on weather
Route::get('/recommendations', [RecommendationController::class, 'getRecommendations']);

/*
|--------------------------------------------------------------------------
| Protected Routes (requires authentication)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    
    // Protected Weather API Routes - requires authentication
    Route::prefix('weather')->group(function () {
        // Get weather data for multiple cities in bulk (for RainMap)
        // Returns weather data for 24 major cities worldwide
        Route::get('/bulk', [WeatherController::class, 'getBulkWeatherData']);
        
        // Compare weather data between two locations
        // Expects JSON body: { "location1": {"lat": ..., "lon": ..., "name": "..."}, "location2": {"lat": ..., "lon": ..., "name": "..."} }
        Route::post('/comparison', [WeatherController::class, 'compareLocations']);
    });
    
    // Stories routes - available to all authenticated users
    Route::prefix('stories')->group(function () {
        Route::get('/', [StoryController::class, 'getStories']);
        Route::get('/hot', [StoryController::class, 'getHotStories']);
        
        // Admin only routes
        Route::middleware('role:admin')->group(function () {
            Route::get('/statistics', [StoryController::class, 'getStoryStatistics']);
            Route::get('/search', [StoryController::class, 'searchNews']);
            Route::post('/check', [StoryController::class, 'checkStoriesExist']);
            Route::post('/', [StoryController::class, 'createStory']);
            Route::put('/{id}/status', [StoryController::class, 'updateStoryStatus']);
            Route::put('/{id}', [StoryController::class, 'updateStory']);
            Route::delete('/{id}', [StoryController::class, 'deleteStory']);
        });
    });
    
    // Admin only routes
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/users', function (Request $request) {
            return response()->json([
                'success' => true,
                'users' => \App\Models\User::all()
            ]);
        });
    });
    
    // Admin Product Management (Protected by auth:sanctum + admin middleware)
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/products', [AdminProductController::class, 'index']);
        Route::post('/products', [AdminProductController::class, 'store']);
        Route::get('/products/{id}', [AdminProductController::class, 'show']);
        Route::put('/products/{id}', [AdminProductController::class, 'update']);
        Route::delete('/products/{id}', [AdminProductController::class, 'destroy']);
        Route::patch('/products/{id}/toggle-active', [AdminProductController::class, 'toggleActive']);
    });
    
});
