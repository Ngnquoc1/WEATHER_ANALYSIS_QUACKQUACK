import axios from 'axios';

// Base URL for the Laravel backend API
// Automatically detect environment and use appropriate URL
const API_BASE_URL = "http://localhost:8000/api"
// const API_BASE_URL = "http://backend:80/api"

/**
 * Weather Service
 * Handles all API calls to the Laravel backend
 */

/**
 * Search for locations by name using Open-Meteo Geocoding API
 * @param {string} query - City name, country name, or address
 * @returns {Promise} - Array of location results with coordinates
 */
/**
 * Search for locations by name using Laravel backend proxy
 * (Proxied to avoid CORS issues with Open-Meteo Geocoding API)
 * @param {string} query - City name, country name, or address
 * @returns {Promise} - Array of location results with coordinates
 */
export const fetchLocationByName = async (query) => {
    try {
        console.log('🌐 Calling Geocoding API (via Laravel proxy) for:', query);
        
        // Use Laravel backend as proxy to avoid CORS issues
        const response = await axios.get(`${API_BASE_URL}/location/search`, {
            params: {
                query: query
            },
            timeout: 10000
        });

        console.log('📦 API Response:', response.data);

        if (!response.data.results || response.data.results.length === 0) {
            throw new Error('Không tìm thấy địa điểm nào');
        }

        // Transform results to match our application format
        const transformedResults = response.data.results.map(location => ({
            id: location.id,
            name: location.name,
            latitude: location.latitude,
            longitude: location.longitude,
            country: location.country,
            admin1: location.admin1,  // State/Province
            displayName: `${location.name}${location.admin1 ? ', ' + location.admin1 : ''}, ${location.country}`
        }));
        
        console.log('✨ Transformed results:', transformedResults);
        return transformedResults;
    } catch (error) {
        console.error('❌ Error searching location:', error);
        console.error('Error details:', {
            message: error.message,
            code: error.code,
            response: error.response?.data,
            status: error.response?.status
        });
        
        if (error.message === 'Không tìm thấy địa điểm nào') {
            throw error;
        } else if (error.code === 'ECONNABORTED') {
            throw new Error('Hết thời gian chờ - vui lòng thử lại');
        } else if (error.response?.status === 404) {
            throw new Error('Không tìm thấy địa điểm nào');
        } else if (error.response?.status === 500) {
            throw new Error('Lỗi server. Vui lòng thử lại sau.');
        } else if (error.code === 'ERR_NETWORK') {
            throw new Error('Không thể kết nối với server. Kiểm tra backend đang chạy.');
        } else {
            throw new Error(`Không thể tìm kiếm địa điểm: ${error.message}`);
        }
    }
};

/**
 * Fetch comprehensive weather data for a specific location
 * @param {number} lat - Latitude
 * @param {number} lon - Longitude
 * @returns {Promise} - Weather data including current, forecast, anomaly, and recommendations
 */
export const fetchWeatherData = async (lat, lon) => {
    try {
        const response = await axios.get(`${API_BASE_URL}/weather/${lat}/${lon}`, {
            timeout: 10000, // 10 second timeout
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            }
        });
        return response.data;
    } catch (error) {
        console.error('Error fetching weather data:', error);
        if (error.code === 'ECONNABORTED') {
            throw new Error('Request timeout - please try again');
        } else if (error.response?.status === 404) {
            throw new Error('Weather data not found for this location');
        } else if (error.response?.status >= 500) {
            throw new Error('Server error - please try again later');
        } else {
            throw new Error('Failed to fetch weather data');
        }
    }
};

/**
 * Fetch detailed AI-generated weather report
 * @param {number} lat - Latitude
 * @param {number} lon - Longitude
 * @returns {Promise} - Detailed weather report with analysis
 */
export const fetchDetailedReport = async (lat, lon) => {
    try {
        const response = await axios.get(`${API_BASE_URL}/weather/report/${lat}/${lon}`, {
            timeout: 30000, // 30 second timeout for AI generation
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            }
        });
        return response.data;
    } catch (error) {
        console.error('Error fetching detailed report:', error);
        if (error.code === 'ECONNABORTED') {
            throw new Error('Hết thời gian chờ - vui lòng thử lại');
        } else if (error.response?.status >= 500) {
            throw new Error('Lỗi server - vui lòng thử lại sau');
        } else {
            throw new Error('Không thể tạo báo cáo chi tiết');
        }
    }
};

/**
 * Fetch weather data for multiple cities in bulk (for RainMap)
 * This reduces API calls from 24 individual requests to 1 bulk request
 * @returns {Promise} - Bulk weather data for all major cities
 */
export const fetchBulkWeatherData = async () => {
    try {
        const response = await axios.get(`${API_BASE_URL}/weather/bulk`, {
            timeout: 30000, // 30 second timeout for bulk request
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            }
        });
        return response.data;
    } catch (error) {
        console.error('Error fetching bulk weather data:', error);
        if (error.code === 'ECONNABORTED') {
            throw new Error('Bulk request timeout - please try again');
        } else if (error.response?.status === 404) {
            throw new Error('Bulk weather endpoint not found');
        } else if (error.response?.status >= 500) {
            throw new Error('Server error - please try again later');
        } else {
            throw new Error('Failed to fetch bulk weather data');
        }
    }
};

/**
 * Compare weather data between two locations
 * @param {Object} location1 - First location {lat, lon, name}
 * @param {Object} location2 - Second location {lat, lon, name}
 * @returns {Promise} - Comparison data
 */
export const fetchComparisonData = async (location1, location2) => {
    try {
        const response = await axios.post(`${API_BASE_URL}/weather/comparison`, {
            location1,
            location2
        });
        return response.data;
    } catch (error) {
        console.error('Error fetching comparison data:', error);
        throw error;
    }
};

/**
 * Get user's current location using Geolocation API
 * @returns {Promise} - Promise that resolves with {lat, lon, name}
 */
export const getCurrentLocation = () => {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('Geolocation is not supported by this browser'));
            return;
        }

        navigator.geolocation.getCurrentPosition(
            async (position) => {
                const { latitude, longitude } = position.coords;
                
                try {
                    // Optional: Get location name using reverse geocoding
                    const locationName = await getLocationName(latitude, longitude);
                    resolve({
                        lat: latitude,
                        lon: longitude,
                        name: locationName || `Vị trí hiện tại (${latitude.toFixed(2)}, ${longitude.toFixed(2)})`
                    });
                } catch (error) {
                    // If reverse geocoding fails, still return coordinates
                    resolve({
                        lat: latitude,
                        lon: longitude,
                        name: `Vị trí hiện tại (${latitude.toFixed(2)}, ${longitude.toFixed(2)})`
                    });
                }
            },
            (error) => {
                let errorMessage = 'Không thể lấy vị trí hiện tại';
                switch (error.code) {
                    case error.PERMISSION_DENIED:
                        errorMessage = 'Người dùng đã từ chối quyền truy cập vị trí';
                        break;
                    case error.POSITION_UNAVAILABLE:
                        errorMessage = 'Thông tin vị trí không khả dụng';
                        break;
                    case error.TIMEOUT:
                        errorMessage = 'Yêu cầu vị trí đã hết thời gian chờ';
                        break;
                    default:
                        errorMessage = 'Lỗi không xác định khi lấy vị trí';
                        break;
                }
                reject(new Error(errorMessage));
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 300000 // 5 minutes
            }
        );
    });
};

/**
 * Get location name from coordinates using reverse geocoding
 * @param {number} lat - Latitude
 * @param {number} lon - Longitude
 * @returns {Promise<string>} - Location name
 */
const getLocationName = async (lat, lon) => {
    try {
        // Use backend endpoint to get location name
        const details = await getLocationDetails(lat, lon);
        return details?.display_name || `Vị trí (${lat.toFixed(2)}, ${lon.toFixed(2)})`;
    } catch (error) {
        console.warn('Location name generation failed:', error.message);
        return null; // Return null to use coordinates fallback
    }
};

/**
 * Get detailed location information from coordinates
 * @param {number} lat - Latitude
 * @param {number} lon - Longitude
 * @returns {Promise<Object>} - Detailed location info with display_name and address
 */
export const getLocationDetails = async (lat, lon) => {
    try {
        const response = await axios.get(`${API_BASE_URL}/location/reverse/${lat}/${lon}`, {
            timeout: 10000
        });
        return response.data;
    } catch (error) {
        console.error('Error getting location details:', error);
        return {
            display_name: `Vị trí (${lat.toFixed(4)}, ${lon.toFixed(4)})`,
            address: {}
        };
    }
};

const weatherService = {
    fetchWeatherData,
    fetchBulkWeatherData,
    fetchComparisonData,
    getCurrentLocation
};

export default weatherService;
