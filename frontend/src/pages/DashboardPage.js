import React, { useState, useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { fetchWeatherData, getCurrentLocation } from '../services/weatherService';
import authService from '../services/authService';
import Header from '../components/Header';
import CurrentWeather from '../components/CurrentWeather';
import HourlyForecastChart from '../components/HourlyForecastChart';
import AnomalyDisplay from '../components/AnomalyDisplay';
import Recommendation from '../components/Recommendation';
import LocationComparator from '../components/LocationComparator';
import Stories from '../components/Stories';
import LoginPrompt from '../components/LoginPrompt';
import LoginModal from '../components/LoginModal';
import ProductRecommendations from '../components/ProductRecommendations';
import './DashboardPage.css';

/**
 * DashboardPage Component
 * Main dashboard that displays all weather information and components
 */
const DashboardPage = () => {
    const location = useLocation();
    // State for selected location (default: null - will be set to user's location)
    const [selectedLocation, setSelectedLocation] = useState(null);

    // State for weather data
    const [weatherData, setWeatherData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [locationError, setLocationError] = useState(null);
    const [isAuthenticated, setIsAuthenticated] = useState(authService.isAuthenticated());
    const [isLoginModalOpen, setIsLoginModalOpen] = useState(false);
    
    // Listen for authentication changes
    useEffect(() => {
        const checkAuth = () => {
            setIsAuthenticated(authService.isAuthenticated());
        };
        
        // Check auth on mount
        checkAuth();
        
        // Listen for storage changes (when user logs in/out)
        const handleStorageChange = () => {
            checkAuth();
        };
        
        window.addEventListener('storage', handleStorageChange);
        
        return () => {
            window.removeEventListener('storage', handleStorageChange);
        };
    }, []);


    // Get user's current location on component mount or handle location from router state
    useEffect(() => {
        const initializeLocation = async () => {
            try {
                setLoading(true);
                setLocationError(null);
                
                // Check if location data was passed from SearchPage
                if (location.state?.selectedLocation) {
                    setSelectedLocation(location.state.selectedLocation);
                    return;
                }
                
                // Try to get user's current location
                const currentLocation = await getCurrentLocation();
                setSelectedLocation(currentLocation);
            } catch (err) {
                console.error('Error getting current location:', err);
                setLocationError(err.message);
                
                // Fallback to default location (Dĩ An)
                const defaultLocation = {
                    name: 'Dĩ An',
                    lat: 10.98,
                    lon: 106.75
                };
                setSelectedLocation(defaultLocation);
            }
        };

        initializeLocation();
    }, [location.state]);

    const [resolvedLocationName, setResolvedLocationName] = useState(null);
    const [locationDetails, setLocationDetails] = useState(null);

    // Fetch weather data when component mounts or location changes
    useEffect(() => {
        if (!selectedLocation) return; // Don't fetch if location is not set yet
        const loadWeatherData = async () => {
            setLoading(true);
            setError(null);

            try {
                const data = await fetchWeatherData(selectedLocation.lat, selectedLocation.lon);
                setWeatherData(data);
                
                // Get detailed location info
                if (data?.location?.details) {
                    setLocationDetails(data.location.details);
                    setResolvedLocationName(data.location.details.display_name);
                } else {
                    setLocationDetails(null);
                    setResolvedLocationName(data?.location?.name || selectedLocation?.name);
                }
            } catch (err) {
                setError('Không thể tải dữ liệu thời tiết. Vui lòng thử lại sau.');
                console.error('Error loading weather data:', err);
            } finally {
                setLoading(false);
            }
        };

        loadWeatherData();
    }, [selectedLocation]);


    // Handle location selection from Header dropdown
    const handleLocationSelect = async (locationData) => {
        try {
            setError(null);
            setLocationError(null);
            if (locationData?.name) {
                setResolvedLocationName(locationData.name);
            }
            setSelectedLocation(locationData);
            // Weather data will be refreshed by the effect watching selectedLocation
        } catch (err) {
            console.error('Error fetching weather data:', err);
            setError('Không thể tải dữ liệu thời tiết. Vui lòng thử lại.');
        }
    };

    const handleLoginSuccess = (user) => {
        setIsAuthenticated(true);
        setIsLoginModalOpen(false);
    };

    return (
        <div className="dashboard-page">
            {/* Header with Dropdown */}
            <Header 
                onLocationSelect={handleLocationSelect}
                currentLocation={resolvedLocationName ? { ...selectedLocation, name: resolvedLocationName } : selectedLocation}
            />

            {/* Location Error Alert */}
            {locationError && (
                <div className="location-error-banner">
                    <div className="error-icon">⚠️</div>
                    <div className="error-content">
                        <p>{locationError}</p>
                        <p className="error-note">Đang sử dụng vị trí mặc định: Dĩ An</p>
                    </div>
                </div>
            )}

            {/* Main Content */}
            <main className="dashboard-content">
                {loading && (
                    <div className="loading-container">
                        <div className="loading-spinner"></div>
                        <p>
                            {selectedLocation
                                ? (resolvedLocationName
                                    ? `Đang tải dữ liệu thời tiết cho ${resolvedLocationName}...`
                                    : 'Đang tải dữ liệu thời tiết...')
                                : 'Đang lấy vị trí hiện tại...'
                            }
                        </p>
                    </div>
                )}

                {error && (
                    <div className="error-container">
                        <div className="error-icon">⚠️</div>
                        <p>{error}</p>
                        <button onClick={() => window.location.reload()} className="retry-button">
                            Thử lại
                        </button>
                    </div>
                )}

                {!loading && !error && weatherData && (
                    <>
                        {/* Current Location Display */}
                        <div className="current-location-banner">
                            <h2>📍 {resolvedLocationName || selectedLocation?.name}</h2>
                            {locationDetails?.address && Object.keys(locationDetails.address).length > 0 && (
                                <div className="location-details">
                                    {locationDetails.address.road && (
                                        <p><strong>Đường:</strong> {locationDetails.address.road}</p>
                                    )}
                                    {locationDetails.address.suburb && (
                                        <p><strong>Phường/Xã:</strong> {locationDetails.address.suburb}</p>
                                    )}
                                    {locationDetails.address.city && (
                                        <p><strong>Thành phố/Quận:</strong> {locationDetails.address.city}</p>
                                    )}
                                    {locationDetails.address.postcode && (
                                        <p><strong>Mã bưu điện:</strong> {locationDetails.address.postcode}</p>
                                    )}
                                </div>
                            )}
                            <p>
                                Vĩ độ: {weatherData.location?.latitude}° | 
                                Kinh độ: {weatherData.location?.longitude}° | 
                                Múi giờ: {weatherData.location?.timezone}
                            </p>
                        </div>

                        {/* Login Prompt for Guest Users */}
                        {!isAuthenticated && (
                            <div className="grid-row">
                                <LoginPrompt onLoginClick={() => setIsLoginModalOpen(true)} />
                            </div>
                        )}

                        {/* Stories Section - Only for authenticated users */}
                        {isAuthenticated && (
                            <div className="grid-row">
                                <Stories location={selectedLocation?.name} />
                            </div>
                        )}

                        {/* Section 1: Current Weather - Available for all users */}
                        <div className="grid-row">
                            <CurrentWeather data={weatherData.current_weather} />
                        </div>

                        {/* Product Recommendations - Available for all users */}
                        <div className="grid-row">
                            <ProductRecommendations weatherData={weatherData} />
                        </div>

                        {/* Hourly Forecast Chart - Only for authenticated users */}
                        {isAuthenticated && (
                            <div className="grid-row">
                                <HourlyForecastChart 
                                    data={weatherData.hourly_forecast} 
                                    dailyData={weatherData.daily_forecast}
                                />
                            </div>
                        )}

                        {/* Section 2: Anomaly Analysis - Only for authenticated users */}
                        {isAuthenticated && (
                            <div className="grid-row">
                                <AnomalyDisplay 
                                anomalyData={weatherData.anomaly} 
                                location={selectedLocation}
                            />
                            </div>
                        )}

                        {/* Section 3: Smart Recommendations - Only for authenticated users */}
                        {isAuthenticated && (
                            <div className="grid-row">
                                <Recommendation recommendation={weatherData.recommendation} />
                            </div>
                        )}

                        {/* Location Comparator - Only for authenticated users */}
                        {isAuthenticated && (
                            <div className="grid-row">
                                <LocationComparator />
                            </div>
                        )}
                    </>
                )}
            </main>

            {/* Footer */}
            <footer className="dashboard-footer">
                <p>
                    Dữ liệu thời tiết được cung cấp bởi{' '}
                    <a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">
                        Open-Meteo API
                    </a>
                </p>
                <p className="footer-note">
                    Weather Analysis Dashboard © 2025 | Cập nhật thời gian thực
                </p>
            </footer>

            {/* Login Modal */}
            <LoginModal 
                isOpen={isLoginModalOpen}
                onClose={() => setIsLoginModalOpen(false)}
                onLoginSuccess={handleLoginSuccess}
            />
        </div>
    );
};

export default DashboardPage;
