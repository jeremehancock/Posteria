<?php

# Posteria: A Media Poster Collection App
# Save all your favorite custom media server posters in one convenient place
#
# Developed by Jereme Hancock
# https://github.com/jeremehancock/Posteria
#
# MIT License
#
# Copyright (c) 2024 Jereme Hancock
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in all
# copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
# SOFTWARE.

// Make sure we catch all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logDebug("PHP Error", [
        'errno' => $errno,
        'errstr' => $errstr,
        'errfile' => $errfile,
        'errline' => $errline
    ]);
    
    // Return true to prevent the standard PHP error handler from running
    return true;
});

// Make sure all exceptions are caught
set_exception_handler(function($exception) {
    logDebug("Uncaught Exception", [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
    
    // Send a JSON response with the error
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unhandled exception: ' . $exception->getMessage()]);
    exit;
});


// Prevent direct output of errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set headers
header('Content-Type: application/json');

// Define helper functions
function getEnvWithFallback($key, $default) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

function getIntEnvWithFallback($key, $default) {
    $value = getenv($key);
    return $value !== false ? intval($value) : $default;
}

function calculatePercentageSafely($processed, $total) {
    return $total > 0 ? round(($processed / $total) * 100) : 100;
}

// Log function for debugging
function logDebug($message, $data = null) {
    $logMessage = date('Y-m-d H:i:s') . ": " . $message;
    if ($data !== null) {
        $logMessage .= "\nData: " . print_r($data, true);
    }
    file_put_contents('jellyfin-debug.log', $logMessage . "\n\n", FILE_APPEND);
}

try {
    // Start session
    if (!session_id()) {
        session_start();
    }

    // Log the request
    logDebug("Request received", [
        'POST' => $_POST,
        'SESSION' => $_SESSION
    ]);

    // Include configuration
    try {
        if (file_exists('./config.php')) {
            require_once './config.php';
            logDebug("Config file loaded successfully from ./include/config.php");
        } else {
            throw new Exception("Config file not found in any of the expected locations");
        }
    } catch (Exception $e) {
        logDebug("Config file error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Config file error: ' . $e->getMessage()]);
        exit;
    }

    // Log config
    logDebug("Configurations loaded", [
        'auth_config_exists' => isset($auth_config),
        'jellyfin_config_exists' => isset($jellyfin_config),
        'jellyfin_config' => isset($jellyfin_config) ? $jellyfin_config : null
    ]);

    // Check if auth_config and jellyfin_config exist
    if (!isset($auth_config) || !isset($jellyfin_config)) {
        logDebug("Missing configuration variables");
        echo json_encode(['success' => false, 'error' => 'Configuration not properly loaded']);
        exit;
    }

    // Check if Jellyfin API key is set
    if (empty($jellyfin_config['api_key'])) {
        logDebug("Jellyfin API key is not set");
        echo json_encode(['success' => false, 'error' => 'Jellyfin API key is not configured. Please add your API key to config.php']);
        exit;
    }

    // Check authentication
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        logDebug("Authentication required");
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    // Refresh session time
    $_SESSION['login_time'] = time();

    // Helper Functions
    function sanitizeFilename($filename) {
        // Remove any character that isn't alphanumeric, space, underscore, dash, or dot
        $filename = preg_replace('/[^\w\s\.-]/', '', $filename);
        $filename = preg_replace('/\s+/', ' ', $filename); // Remove multiple spaces
        return trim($filename);
    }

    function generateJellyfinFilename($title, $id, $extension, $mediaType = '') {
        $basename = sanitizeFilename($title);
        if (!empty($id)) {
            $basename .= " [{$id}]";
        }
        
        // Add "Collection" before "Jellyfin" for collection posters
        if ($mediaType === 'collections') {
            // Check if "Collection" is not already in the basename
            if (!stripos($basename, 'Collection')) {
                $basename .= " Collection **Jellyfin**";
            } else {
                $basename .= " **Jellyfin**";
            }
        } else {
            $basename .= " **Jellyfin**";
        }
        
        return $basename . '.' . $extension;
    }
    
    function handleExistingFile($targetPath, $overwriteOption, $filename, $extension) {
        if (!file_exists($targetPath)) {
            return $targetPath; // File doesn't exist, no handling needed
        }
        
        switch ($overwriteOption) {
            case 'overwrite':
                return $targetPath; // Will overwrite existing
            case 'copy':
                $dir = dirname($targetPath);
                $basename = pathinfo($filename, PATHINFO_FILENAME);
                $counter = 1;
                $newPath = $targetPath;
                
                while (file_exists($newPath)) {
                    $newName = $basename . " ({$counter})." . $extension;
                    $newPath = $dir . '/' . $newName;
                    $counter++;
                }
                return $newPath;
            case 'skip':
            default:
                return false; // Signal to skip
        }
    }

    function getJellyfinHeaders($apiKey) {
        return [
            'Accept' => 'application/json',
            'X-Emby-Token' => $apiKey,
            'Content-Type' => 'application/json'
        ];
    }

    function makeApiRequest($url, $headers, $expectJson = true) {
        global $jellyfin_config;
        
        logDebug("Making API request", [
            'url' => $url,
            'headers' => $headers,
            'expectJson' => $expectJson
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => $jellyfin_config['connect_timeout'],
            CURLOPT_TIMEOUT => $jellyfin_config['request_timeout'],
            CURLOPT_VERBOSE => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        // Log the response info
        logDebug("API response", [
            'http_code' => $httpCode,
            'curl_error' => $error,
            'response_length' => strlen($response),
            'curl_info' => $info,
            'response_preview' => $expectJson ? substr($response, 0, 500) . (strlen($response) > 500 ? '...' : '') : '[BINARY DATA]'
        ]);
        
        if ($response === false) {
            logDebug("API request failed: " . $error);
            throw new Exception("API request failed: " . $error);
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            logDebug("API request returned HTTP code: " . $httpCode . ", response: " . $response);
            throw new Exception("API request returned HTTP code: " . $httpCode . " - " . $response);
        }
        
        // Only validate JSON if we expect JSON
        if ($expectJson) {
            // Try to parse JSON to verify it's valid
            $jsonTest = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                logDebug("Invalid JSON response: " . json_last_error_msg() . ", response: " . $response);
                throw new Exception("Invalid JSON response: " . json_last_error_msg());
            }
        }
        
        return $response;
    }
    
    function validateJellyfinConnection($serverUrl, $apiKey) {
        try {
            logDebug("Validating Jellyfin connection", [
                'server_url' => $serverUrl,
                'api_key_length' => strlen($apiKey)
            ]);
            
            $url = rtrim($serverUrl, '/') . "/System/Info?api_key=" . $apiKey;
            $headers = [];
            foreach (getJellyfinHeaders($apiKey) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['Id'])) {
                logDebug("Invalid Jellyfin server response - missing Id");
                return ['success' => false, 'error' => 'Invalid Jellyfin server response'];
            }
            
            logDebug("Jellyfin connection validated successfully", [
                'id' => $data['Id'],
                'version' => $data['Version'] ?? 'Unknown'
            ]);
            
            return ['success' => true, 'data' => [
                'id' => $data['Id'],
                'version' => $data['Version'] ?? 'Unknown'
            ]];
        } catch (Exception $e) {
            logDebug("Jellyfin connection validation failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function getJellyfinLibraries($serverUrl, $apiKey) {
        try {
            logDebug("Getting Jellyfin libraries", ['server_url' => $serverUrl]);
            
            // Get user ID first - most Jellyfin API calls require a user ID
            $userUrl = rtrim($serverUrl, '/') . "/Users?api_key=" . $apiKey;
            $headers = [];
            foreach (getJellyfinHeaders($apiKey) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $userResponse = makeApiRequest($userUrl, $headers);
            $userData = json_decode($userResponse, true);
            
            if (!is_array($userData) || empty($userData)) {
                logDebug("No users found in Jellyfin");
                return ['success' => false, 'error' => 'Could not retrieve user information'];
            }
            
            // Get the first admin user, or just the first user if no admin is found
            $userId = null;
            foreach ($userData as $user) {
                if (isset($user['Id'])) {
                    if (isset($user['Policy']['IsAdministrator']) && $user['Policy']['IsAdministrator']) {
                        $userId = $user['Id'];
                        break;
                    } elseif ($userId === null) {
                        $userId = $user['Id'];
                    }
                }
            }
            
            if ($userId === null) {
                logDebug("Could not find a valid user ID");
                return ['success' => false, 'error' => 'No valid user ID found'];
            }
            
            // Now get the libraries (views) for this user
            $url = rtrim($serverUrl, '/') . "/Users/" . $userId . "/Views?api_key=" . $apiKey;
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['Items']) || !is_array($data['Items'])) {
                logDebug("No libraries found in Jellyfin response");
                return ['success' => false, 'error' => 'No libraries found'];
            }
            
            $libraries = [];
            foreach ($data['Items'] as $lib) {
                // Determine the library type
                $type = 'unknown';
                
                // Check the collection type if available
                if (isset($lib['CollectionType'])) {
                    if ($lib['CollectionType'] === 'movies') {
                        $type = 'movie';
                    } elseif ($lib['CollectionType'] === 'tvshows') {
                        $type = 'show';
                    }
                }
                
                // Try to determine by name if type is still unknown
                if ($type === 'unknown' && isset($lib['Name'])) {
                    $name = strtolower($lib['Name']);
                    if (strpos($name, 'movie') !== false) {
                        $type = 'movie';
                    } elseif (strpos($name, 'tv') !== false || strpos($name, 'show') !== false) {
                        $type = 'show';
                    }
                }
                
                // Only include movie and show libraries
                if (in_array($type, ['movie', 'show']) && $lib['Name'] !== 'Live TV') {
                    $libraries[] = [
                        'id' => $lib['Id'],
                        'title' => $lib['Name'],
                        'type' => $type
                    ];
                }
            }
            
            logDebug("Found Jellyfin libraries", ['count' => count($libraries), 'libraries' => $libraries]);
            
            return ['success' => true, 'data' => $libraries];
        } catch (Exception $e) {
            logDebug("Error getting Jellyfin libraries: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function getJellyfinMovies($serverUrl, $apiKey, $libraryId, $startIndex = 0, $limit = 50) {
        try {
            // First, construct the URL for getting items from a specific library
            $url = rtrim($serverUrl, '/') . "/Items";
            $url .= "?ParentId=" . urlencode($libraryId);
            $url .= "&IncludeItemTypes=Movie";
            $url .= "&Fields=PrimaryImageAspectRatio,ProductionYear";
            $url .= "&ImageTypeLimit=1";
            $url .= "&EnableImageTypes=Primary";
            $url .= "&StartIndex=" . $startIndex;
            $url .= "&Limit=" . $limit;
            $url .= "&api_key=" . $apiKey;
            
            $headers = [];
            foreach (getJellyfinHeaders($apiKey) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['Items']) || !is_array($data['Items'])) {
                return ['success' => false, 'error' => 'No movies found'];
            }
            
            $movies = [];
            foreach ($data['Items'] as $movie) {
                // Check if the movie has a primary image
                if (isset($movie['ImageTags']) && isset($movie['ImageTags']['Primary'])) {
                    $movies[] = [
                        'title' => $movie['Name'],
                        'id' => $movie['Id'],
                        'imagetag' => $movie['ImageTags']['Primary'],
                        'year' => $movie['ProductionYear'] ?? '',
                        'ratingKey' => $movie['Id'] // For compatibility with the Plex processing code
                    ];
                }
            }
            
            // Get total count for pagination
            $totalCount = $data['TotalRecordCount'] ?? count($movies);
            $moreAvailable = ($startIndex + count($movies)) < $totalCount;
            
            return [
                'success' => true, 
                'data' => $movies,
                'pagination' => [
                    'start' => $startIndex,
                    'size' => count($movies),
                    'totalSize' => $totalCount,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all Jellyfin movies with pagination
    function getAllJellyfinMovies($serverUrl, $apiKey, $libraryId) {
        $allMovies = [];
        $startIndex = 0;
        $limit = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getJellyfinMovies($serverUrl, $apiKey, $libraryId, $startIndex, $limit);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allMovies = array_merge($allMovies, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $startIndex += $limit;
        }
        
        return ['success' => true, 'data' => $allMovies];
    }

    function getJellyfinShows($serverUrl, $apiKey, $libraryId, $startIndex = 0, $limit = 50) {
        try {
            // First, construct the URL for getting shows from a specific library
            $url = rtrim($serverUrl, '/') . "/Items";
            $url .= "?ParentId=" . urlencode($libraryId);
            $url .= "&IncludeItemTypes=Series";
            $url .= "&Fields=PrimaryImageAspectRatio,ProductionYear";
            $url .= "&ImageTypeLimit=1";
            $url .= "&EnableImageTypes=Primary";
            $url .= "&StartIndex=" . $startIndex;
            $url .= "&Limit=" . $limit;
            $url .= "&api_key=" . $apiKey;
            
            $headers = [];
            foreach (getJellyfinHeaders($apiKey) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['Items']) || !is_array($data['Items'])) {
                return ['success' => false, 'error' => 'No shows found'];
            }
            
            $shows = [];
            foreach ($data['Items'] as $show) {
                // Check if the show has a primary image
                if (isset($show['ImageTags']) && isset($show['ImageTags']['Primary'])) {
                    $shows[] = [
                        'title' => $show['Name'],
                        'id' => $show['Id'],
                        'imagetag' => $show['ImageTags']['Primary'],
                        'year' => $show['ProductionYear'] ?? '',
                        'ratingKey' => $show['Id'] // For compatibility with the Plex processing code
                    ];
                }
            }
            
            // Get total count for pagination
            $totalCount = $data['TotalRecordCount'] ?? count($shows);
            $moreAvailable = ($startIndex + count($shows)) < $totalCount;
            
            return [
                'success' => true, 
                'data' => $shows,
                'pagination' => [
                    'start' => $startIndex,
                    'size' => count($shows),
                    'totalSize' => $totalCount,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all Jellyfin shows with pagination
    function getAllJellyfinShows($serverUrl, $apiKey, $libraryId) {
        $allShows = [];
        $startIndex = 0;
        $limit = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getJellyfinShows($serverUrl, $apiKey, $libraryId, $startIndex, $limit);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allShows = array_merge($allShows, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $startIndex += $limit;
        }
        
        return ['success' => true, 'data' => $allShows];
    }

    function getJellyfinSeasons($serverUrl, $apiKey, $showId, $startIndex = 0, $limit = 50) {
        try {
            logDebug("Getting seasons for show ID: " . $showId);
            
            // First, construct the URL for getting seasons
            $url = rtrim($serverUrl, '/') . "/Shows/" . urlencode($showId) . "/Seasons";
            // Remove the problematic "UserId=null" parameter
            $url .= "?Fields=PrimaryImageAspectRatio";
            $url .= "&ImageTypeLimit=1";
            $url .= "&EnableImageTypes=Primary";
            $url .= "&StartIndex=" . $startIndex;
            $url .= "&Limit=" . $limit;
            $url .= "&api_key=" . $apiKey;
            
            logDebug("Getting Jellyfin seasons with URL: " . $url);
            
            $headers = [];
            foreach (getJellyfinHeaders($apiKey) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['Items']) || !is_array($data['Items'])) {
                logDebug("No seasons found in response", $data);
                return ['success' => false, 'error' => 'No seasons found'];
            }
            
            // Get the show details to include in the season title - MODIFIED REQUEST
            // Instead of using /Items endpoint, use /Users/{userId}/Items to get the show details
            
            // First get a valid user ID
            $userUrl = rtrim($serverUrl, '/') . "/Users?api_key=" . $apiKey;
            $userResponse = makeApiRequest($userUrl, $headers);
            $userData = json_decode($userResponse, true);
            
            if (!is_array($userData) || empty($userData)) {
                logDebug("No users found in Jellyfin");
                $showTitle = 'Unknown Show';
            } else {
                // Get the first user
                $userId = $userData[0]['Id'];
                
                // Now get the show details using the user context
                $showUrl = rtrim($serverUrl, '/') . "/Users/" . $userId . "/Items/" . urlencode($showId) . "?api_key=" . $apiKey;
                
                try {
                    $showResponse = makeApiRequest($showUrl, $headers);
                    $showData = json_decode($showResponse, true);
                    $showTitle = isset($showData['Name']) ? $showData['Name'] : 'Unknown Show';
                    logDebug("Got show title: " . $showTitle);
                } catch (Exception $e) {
                    logDebug("Error getting show details: " . $e->getMessage());
                    $showTitle = 'Show ' . $showId; // Fallback title
                }
            }
            
            $seasons = [];
            foreach ($data['Items'] as $season) {
                // Check if the season has a primary image
                if (isset($season['ImageTags']) && isset($season['ImageTags']['Primary'])) {
                    $seasonTitle = $season['Name'] ?? 'Season ' . ($season['IndexNumber'] ?? 'Unknown');
                    $seasons[] = [
                        'title' => $showTitle . ' - ' . $seasonTitle,
                        'id' => $season['Id'],
                        'imagetag' => $season['ImageTags']['Primary'],
                        'index' => $season['IndexNumber'] ?? 0,
                        'ratingKey' => $season['Id'] // For compatibility with the Plex processing code
                    ];
                }
            }
            
            // Get total count for pagination
            $totalCount = $data['TotalRecordCount'] ?? count($seasons);
            $moreAvailable = ($startIndex + count($seasons)) < $totalCount;
            
            logDebug("Found " . count($seasons) . " seasons with images");
            return [
                'success' => true, 
                'data' => $seasons,
                'pagination' => [
                    'start' => $startIndex,
                    'size' => count($seasons),
                    'totalSize' => $totalCount,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            logDebug("Error getting Jellyfin seasons: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    function getAllJellyfinSeasons($serverUrl, $apiKey, $showId) {
        logDebug("Getting all seasons for show ID: " . $showId);
        
        $allSeasons = [];
        $startIndex = 0;
        $limit = 50;
        $moreAvailable = true;
        
        try {
            while ($moreAvailable) {
                $result = getJellyfinSeasons($serverUrl, $apiKey, $showId, $startIndex, $limit);
                
                if (!$result['success']) {
                    logDebug("Error getting seasons batch: " . ($result['error'] ?? 'Unknown error'));
                    return $result;
                }
                
                if (empty($result['data'])) {
                    logDebug("No seasons found in this batch");
                    // If no seasons found but request was successful, we can return an empty success
                    return ['success' => true, 'data' => []];
                }
                
                $allSeasons = array_merge($allSeasons, $result['data']);
                $moreAvailable = $result['pagination']['moreAvailable'];
                $startIndex += $limit;
            }
            
            logDebug("Successfully retrieved all " . count($allSeasons) . " seasons");
            return ['success' => true, 'data' => $allSeasons];
        } catch (Exception $e) {
            logDebug("Error in getAllJellyfinSeasons: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function getJellyfinCollections($serverUrl, $apiKey, $libraryId, $startIndex = 0, $limit = 50) {
        try {
            // First, determine the library type (movie or show)
            $libraryType = null;
            
            // Get the library details
            $libraryUrl = rtrim($serverUrl, '/') . "/Users?api_key=" . $apiKey;
            $headers = [];
            foreach (getJellyfinHeaders($apiKey) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $userResponse = makeApiRequest($libraryUrl, $headers);
            $userData = json_decode($userResponse, true);
            
            if (!is_array($userData) || empty($userData)) {
                logDebug("No users found in Jellyfin");
                return ['success' => false, 'error' => 'Could not retrieve user information'];
            }
            
            $userId = $userData[0]['Id'];
            
            // Get the library details to determine its type
            $viewUrl = rtrim($serverUrl, '/') . "/Users/" . $userId . "/Views?api_key=" . $apiKey;
            $viewResponse = makeApiRequest($viewUrl, $headers);
            $viewData = json_decode($viewResponse, true);
            
            if (isset($viewData['Items'])) {
                foreach ($viewData['Items'] as $view) {
                    if ($view['Id'] == $libraryId) {
                        if (isset($view['CollectionType'])) {
                            if ($view['CollectionType'] === 'movies') {
                                $libraryType = 'movie';
                            } elseif ($view['CollectionType'] === 'tvshows') {
                                $libraryType = 'show';
                            }
                        }
                        break;
                    }
                }
            }
            
            logDebug("Library type detected for collections", [
                'libraryId' => $libraryId,
                'type' => $libraryType
            ]);
            
            // Now construct URL for getting collections
            $url = rtrim($serverUrl, '/') . "/Items";
            $url .= "?Recursive=true";
            $url .= "&IncludeItemTypes=BoxSet";
            $url .= "&Fields=PrimaryImageAspectRatio,Path";
            $url .= "&ImageTypeLimit=1";
            $url .= "&EnableImageTypes=Primary";
            $url .= "&StartIndex=" . $startIndex;
            $url .= "&Limit=" . $limit;
            $url .= "&api_key=" . $apiKey;
            
            if ($userId) {
                $url .= "&userId=" . $userId;
            }
            
            $headers = [];
            foreach (getJellyfinHeaders($apiKey) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            // Collections might be empty, which is OK
            if (!isset($data['Items']) || !is_array($data['Items'])) {
                return ['success' => true, 'data' => []];
            }
            
            $collections = [];
            foreach ($data['Items'] as $collection) {
                // Check if the collection has a primary image
                if (isset($collection['ImageTags']) && isset($collection['ImageTags']['Primary'])) {
                    // If we know the library type, we can filter collections
                    $shouldInclude = true;
                    
                    if ($libraryType) {
                        // Check if this collection is of the right type
                        // Get the collection details to check its contents
                        $collectionUrl = rtrim($serverUrl, '/') . "/Users/" . $userId . "/Items?ParentId=" . $collection['Id'] . "&api_key=" . $apiKey;
                        $collectionResponse = makeApiRequest($collectionUrl, $headers);
                        $collectionData = json_decode($collectionResponse, true);
                        
                        // Set default to exclude
                        $shouldInclude = false;
                        
                        if (isset($collectionData['Items']) && !empty($collectionData['Items'])) {
                            // Check the first few items to determine collection type
                            foreach (array_slice($collectionData['Items'], 0, 3) as $item) {
                                $itemType = $item['Type'] ?? '';
                                
                                if ($libraryType === 'movie' && $itemType === 'Movie') {
                                    $shouldInclude = true;
                                    break;
                                } else if ($libraryType === 'show' && ($itemType === 'Series' || $itemType === 'Season' || $itemType === 'Episode')) {
                                    $shouldInclude = true;
                                    break;
                                }
                            }
                        }
                        
                        logDebug("Collection type detection", [
                            'collection' => $collection['Name'],
                            'shouldInclude' => $shouldInclude,
                            'libraryType' => $libraryType,
                            'itemCount' => isset($collectionData['Items']) ? count($collectionData['Items']) : 0
                        ]);
                    }
                    
                    if ($shouldInclude) {
                        $collections[] = [
                            'title' => $collection['Name'],
                            'id' => $collection['Id'],
                            'imagetag' => $collection['ImageTags']['Primary'],
                            'ratingKey' => $collection['Id'] // For compatibility with the Plex processing code
                        ];
                    }
                }
            }
            
            // Get total count for pagination
            $totalCount = $data['TotalRecordCount'] ?? count($collections);
            $moreAvailable = ($startIndex + count($collections)) < $totalCount;
            
            return [
                'success' => true, 
                'data' => $collections,
                'pagination' => [
                    'start' => $startIndex,
                    'size' => count($collections),
                    'totalSize' => $totalCount,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            logDebug("Error getting collections: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all collections with pagination
    function getAllJellyfinCollections($serverUrl, $apiKey, $libraryId) {
        $allCollections = [];
        $startIndex = 0;
        $limit = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getJellyfinCollections($serverUrl, $apiKey, $libraryId, $startIndex, $limit);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allCollections = array_merge($allCollections, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $startIndex += $limit;
        }
        
        return ['success' => true, 'data' => $allCollections];
    }

    // Function to get image data without saving it
    function getJellyfinImageData($serverUrl, $apiKey, $itemId, $imageTag) {
        try {
            // Construct the URL for getting the primary image
            $url = rtrim($serverUrl, '/') . "/Items/" . urlencode($itemId) . "/Images/Primary";
            if (!empty($imageTag)) {
                $url .= "?tag=" . urlencode($imageTag);
            }
            $url .= "&api_key=" . $apiKey;
            
            $headers = [];
            foreach (getJellyfinHeaders($apiKey) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            // Pass false to indicate we don't expect JSON for image downloads
            $imageData = makeApiRequest($url, $headers, false);
            return ['success' => true, 'data' => $imageData];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Function to download and save Jellyfin image to file
    function downloadJellyfinImage($serverUrl, $apiKey, $itemId, $imageTag, $targetPath) {
        try {
            // Get the image data
            $result = getJellyfinImageData($serverUrl, $apiKey, $itemId, $imageTag);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            $imageData = $result['data'];
            
            if (!file_put_contents($targetPath, $imageData)) {
                throw new Exception("Failed to save image to: " . $targetPath);
            }
            
            chmod($targetPath, 0644);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Function to compare image data with existing file
    function compareAndSaveImage($imageData, $targetPath) {
        // Check if the file exists and compare content
        if (file_exists($targetPath)) {
            $existingData = file_get_contents($targetPath);
            
            // If content is identical, no need to save
            if ($existingData === $imageData) {
                return ['success' => true, 'unchanged' => true];
            }
        }
        
        // Content is different or file doesn't exist, save it
        if (!file_put_contents($targetPath, $imageData)) {
            return ['success' => false, 'error' => "Failed to save image to: {$targetPath}"];
        }
        
        chmod($targetPath, 0644);
        return ['success' => true, 'unchanged' => false];
    }

    // Process a batch of items with smart overwrite
    function processBatch($items, $serverUrl, $apiKey, $targetDir, $overwriteOption, $mediaType = '') {
        $results = [
            'successful' => 0,
            'skipped' => 0,
            'unchanged' => 0, // Counter for files that were checked but not modified because content was identical
            'failed' => 0,
            'errors' => [],
            'skippedDetails' => [], // Track reasons for skipped files
            'importedIds' => [] // Track IDs that were successfully processed
        ];
        
        // Make sure $items is an array
        if (!is_array($items)) {
            logDebug("Error: items is not an array in processBatch", [
                'type' => gettype($items)
            ]);
            return $results;
        }
        
        foreach ($items as $item) {
            // Check if the item is well-formed
            if (!isset($item['title']) || !isset($item['id']) || !isset($item['imagetag'])) {
                logDebug("Skipping malformed item in processBatch", $item);
                continue;
            }
            
            $title = $item['title'];
            $id = $item['id'];
            $imageTag = $item['imagetag']; // Jellyfin uses imagetags
            
            // Generate target filename - passing mediaType parameter
            $extension = 'jpg'; // Jellyfin thumbnails are usually JPG
            $filename = generateJellyfinFilename($title, $id, $extension, $mediaType);
            $targetPath = $targetDir . $filename;
            
            // Handle existing file based on overwrite option
            if (file_exists($targetPath)) {
                if ($overwriteOption === 'skip') {
                    $results['importedIds'][] = $id; // Still track this ID as processed
                    $results['skipped']++;
                    $results['skippedDetails'][] = [
                        'file' => $filename,
                        'reason' => 'skip_option',
                        'message' => "Skipped {$title} - file already exists and skip option selected"
                    ];
                    logDebug("Skipped file (skip option): {$targetPath}");
                    continue; // Skip this file
                } else if ($overwriteOption === 'copy') {
                    // Create a new filename with counter
                    $dir = dirname($targetPath);
                    $basename = pathinfo($filename, PATHINFO_FILENAME);
                    $counter = 1;
                    $newPath = $targetPath;
                    
                    while (file_exists($newPath)) {
                        $newName = $basename . " ({$counter})." . $extension;
                        $newPath = $dir . '/' . $newName;
                        $counter++;
                    }
                    $targetPath = $newPath;
                    
                    // For 'copy', we'll download directly
                    $downloadResult = downloadJellyfinImage($serverUrl, $apiKey, $id, $imageTag, $targetPath);
                    
                    if ($downloadResult['success']) {
                        $results['successful']++;
                        $results['importedIds'][] = $id;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Failed to download {$title}: {$downloadResult['error']}";
                    }
                    continue;
                } else if ($overwriteOption === 'overwrite') {
                    // For overwrite, we'll check if content has changed
                    $imageResult = getJellyfinImageData($serverUrl, $apiKey, $id, $imageTag);
                    
                    if (!$imageResult['success']) {
                        $results['failed']++;
                        $results['errors'][] = "Failed to download {$title}: {$imageResult['error']}";
                        continue;
                    }
                    
                    // Compare and save if different
                    $saveResult = compareAndSaveImage($imageResult['data'], $targetPath);
                    
                    if ($saveResult['success']) {
                        if (isset($saveResult['unchanged']) && $saveResult['unchanged']) {
                            // Count as skipped for UI consistency, but track the reason
                            $results['skipped']++;
                            $results['unchanged']++;
                            $results['importedIds'][] = $id;
                            $results['skippedDetails'][] = [
                                'file' => $filename,
                                'reason' => 'unchanged',
                                'message' => "Skipped {$title} - content identical to existing file"
                            ];
                            logDebug("Skipped file (unchanged content): {$targetPath}");
                        } else {
                            $results['successful']++;
                            $results['importedIds'][] = $id;
                            logDebug("Updated file (content changed): {$targetPath}");
                        }
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Failed to save {$title}: {$saveResult['error']}";
                    }
                    continue;
                }
            } else {
                // File doesn't exist, download directly
                $downloadResult = downloadJellyfinImage($serverUrl, $apiKey, $id, $imageTag, $targetPath);
                
                if ($downloadResult['success']) {
                    $results['successful']++;
                    $results['importedIds'][] = $id;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to download {$title}: {$downloadResult['error']}";
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Get all existing posters in a directory with Plex or Jellyfin identifiers
     * 
     * @param string $directory Directory path to search
     * @param string $type Type of server ('Plex' or 'Jellyfin')
     * @return array Associative array of ID => filename
     */
    function getExistingPosters($directory, $type = '**Jellyfin**') {
        $posters = [];
        
        // Check if directory exists
        if (!is_dir($directory)) {
            logDebug("getExistingPosters: Directory does not exist: {$directory}");
            return $posters;
        }
        
        // Check if directory is readable
        if (!is_readable($directory)) {
            logDebug("getExistingPosters: Directory is not readable: {$directory}");
            return $posters;
        }
        
        try {
            logDebug("Searching for existing posters in: " . $directory);
            
            $handle = @opendir($directory);
            if ($handle === false) {
                logDebug("getExistingPosters: Failed to open directory: {$directory}");
                return $posters;
            }
            
            while (($file = readdir($handle)) !== false) {
                if (is_file($directory . $file) && strpos($file, $type) !== false) {
                    // Extract the ID if present (format: "Title [ID] Jellyfin.jpg")
                    preg_match('/\[([a-f0-9]+)\]/', $file, $matches);
                    $id = isset($matches[1]) ? $matches[1] : null;
                    if ($id) {
                        $posters[$id] = $file;
                    }
                }
            }
            closedir($handle);
            
            logDebug("Found " . count($posters) . " existing posters");
        } catch (Exception $e) {
            logDebug("Error in getExistingPosters: " . $e->getMessage());
        }
        
        return $posters;
    }

    /**
     * Mark orphaned posters in a directory
     * 
     * @param string $directory Directory path to search
     * @param array $importedIds List of IDs that were successfully imported
     * @param string $type Type of server ('Plex' or 'Jellyfin')
     * @return array Results with count of orphaned posters and details
     */
    function markOrphanedPosters($directory, $importedIds, $type = '**Jellyfin**') {
        // Make sure directory ends with a slash
        if (substr($directory, -1) !== '/') {
            $directory .= '/';
        }
        
        // Ensure importedIds is always an array
        if (!is_array($importedIds)) {
            $importedIds = [];
            logDebug("Warning: importedIds was not an array, initialized to empty array");
        }
        
        logDebug("Marking orphaned posters", [
            'directory' => $directory,
            'importedIds_count' => count($importedIds),
            'type' => $type,
            'directory_exists' => is_dir($directory),
            'directory_writable' => is_dir($directory) ? is_writable($directory) : false
        ]);
        
        $results = [
            'orphaned' => 0,
            'unmarked' => 0,
            'details' => []
        ];
        
        // Verify directory exists
        if (!is_dir($directory)) {
            logDebug("Directory does not exist: {$directory}");
            return $results;
        }
        
        // Verify directory is readable
        if (!is_readable($directory)) {
            logDebug("Directory is not readable: {$directory}");
            return $results;
        }
        
        try {
            // 1. Find posters with IDs that are no longer in Jellyfin
            $existing = getExistingPosters($directory, $type);
            logDebug("Found existing posters", ['count' => count($existing)]);
            
            foreach ($existing as $id => $filename) {
                try {
                    if (!in_array($id, $importedIds)) {
                        // This poster is orphaned - it has an ID but the ID wasn't in the imported set
                        $newFilename = preg_replace('/\s*\[[a-f0-9]+\]\s*/', ' ', $filename); // Remove ID
                        $newFilename = str_replace($type, 'Orphaned', $newFilename); // Replace Jellyfin with Orphaned
                        
                        // Make sure the new filename doesn't have double spaces
                        $newFilename = preg_replace('/\s+/', ' ', $newFilename);
                        $newFilename = trim($newFilename);
                        
                        logDebug("Renaming orphaned poster", [
                            'old' => $filename,
                            'new' => $newFilename
                        ]);
                        
                        // Check if the file exists and is writable
                        if (!file_exists($directory . $filename)) {
                            logDebug("File doesn't exist: {$directory}{$filename}");
                            continue;
                        }
                        
                        if (!is_writable($directory . $filename)) {
                            logDebug("File is not writable: {$directory}{$filename}");
                            continue;
                        }
                        
                        // Rename the file
                        if (rename($directory . $filename, $directory . $newFilename)) {
                            $results['orphaned']++;
                            $results['details'][] = [
                                'oldName' => $filename,
                                'newName' => $newFilename,
                                'reason' => 'No longer in ' . $type
                            ];
                        } else {
                            logDebug("Failed to rename: {$directory}{$filename}");
                        }
                    }
                } catch (Exception $e) {
                    logDebug("Error processing file {$filename}: " . $e->getMessage());
                    continue; // Skip this file and continue with others
                }
            }
            
            // 2. Now find files without Plex, Jellyfin, or Orphaned in the name
            if ($handle = opendir($directory)) {
                while (($file = readdir($handle)) !== false) {
                    try {
                        if (is_file($directory . $file) && 
                            strpos($file, '**Plex**') === false && 
                            strpos($file, '**Jellyfin**') === false && 
                            strpos($file, 'Orphaned') === false) {
                            
                            // Check if file is writable
                            if (!is_writable($directory . $file)) {
                                logDebug("File is not writable: {$directory}{$file}");
                                continue;
                            }
                            
                            // This file doesn't have a server designation - mark it as orphaned
                            $baseFilename = pathinfo($file, PATHINFO_FILENAME);
                            $extension = pathinfo($file, PATHINFO_EXTENSION);
                            $newFilename = $baseFilename . ' Orphaned.' . $extension;
                            
                            logDebug("Marking file as orphaned", [
                                'old' => $file,
                                'new' => $newFilename
                            ]);
                            
                            // Rename the file
                            if (rename($directory . $file, $directory . $newFilename)) {
                                $results['unmarked']++;
                                $results['details'][] = [
                                    'oldName' => $file,
                                    'newName' => $newFilename,
                                    'reason' => 'Missing server designation'
                                ];
                            } else {
                                logDebug("Failed to rename: {$directory}{$file}");
                            }
                        }
                    } catch (Exception $e) {
                        logDebug("Error processing unmarked file {$file}: " . $e->getMessage());
                        continue; // Skip this file and continue with others
                    }
                }
                closedir($handle);
            }
        } catch (Exception $e) {
            logDebug("Error in markOrphanedPosters: " . $e->getMessage());
        }
        
        logDebug("Orphaned poster results", $results);
        return $results;
    }

    // API Endpoints

    // Test Jellyfin Connection
    if (isset($_POST['action']) && $_POST['action'] === 'test_jellyfin_connection') {
        logDebug("Processing test_jellyfin_connection action");
        $result = validateJellyfinConnection($jellyfin_config['server_url'], $jellyfin_config['api_key']);
        echo json_encode($result);
        logDebug("Response sent", $result);
        exit;
    }

    // Get Jellyfin Libraries
    if (isset($_POST['action']) && $_POST['action'] === 'get_jellyfin_libraries') {
        logDebug("Processing get_jellyfin_libraries action");
        $result = getJellyfinLibraries($jellyfin_config['server_url'], $jellyfin_config['api_key']);
        echo json_encode($result);
        logDebug("Response sent", $result);
        exit;
    }
    
    // Get Shows for Season Import
    if (isset($_POST['action']) && $_POST['action'] === 'get_jellyfin_shows_for_seasons') {
        if (!isset($_POST['libraryId'])) {
            echo json_encode(['success' => false, 'error' => 'Missing library ID']);
            exit;
        }
        
        $libraryId = $_POST['libraryId'];
        $result = getAllJellyfinShows($jellyfin_config['server_url'], $jellyfin_config['api_key'], $libraryId);
        echo json_encode($result);
        exit;
    }

    // Import Jellyfin Posters
    if (isset($_POST['action']) && $_POST['action'] === 'import_jellyfin_posters') {
        if (!isset($_POST['type'], $_POST['libraryId'], $_POST['contentType'], $_POST['overwriteOption'])) {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            exit;
        }
        
        $type = $_POST['type']; // 'movies', 'shows', 'seasons', 'collections'
        $libraryId = $_POST['libraryId'];
        $contentType = $_POST['contentType']; // This will be the directory key
        $overwriteOption = $_POST['overwriteOption']; // 'overwrite', 'copy', 'skip'
        
        // Optional parameters
        $showKey = isset($_POST['showKey']) ? $_POST['showKey'] : null; // For single show seasons import
        $importAllSeasons = isset($_POST['importAllSeasons']) && $_POST['importAllSeasons'] === 'true'; // New parameter
        
        // Validate contentType maps to a directory
        $directories = [
            'movies' => '../posters/movies/',
            'tv-shows' => '../posters/tv-shows/',
            'tv-seasons' => '../posters/tv-seasons/',
            'collections' => '../posters/collections/'
        ];
        
        if (!isset($directories[$contentType])) {
            echo json_encode(['success' => false, 'error' => 'Invalid content type']);
            exit;
        }
        
        $targetDir = $directories[$contentType];
        
        // Ensure directory exists and is writable
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                echo json_encode(['success' => false, 'error' => 'Failed to create directory: ' . $targetDir]);
                exit;
            }
        }
        
        if (!is_writable($targetDir)) {
            echo json_encode(['success' => false, 'error' => 'Directory is not writable: ' . $targetDir]);
            exit;
        }
        
        // Start import process based on content type
        $items = [];
        $error = null;
        $totalStats = [
            'successful' => 0,
            'skipped' => 0,
            'unchanged' => 0, // Added unchanged counter
            'failed' => 0,
            'errors' => []
        ];
        
        try {
            switch ($type) {
                case 'movies':
                    // Handle batch processing
                    if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                        $startIndex = (int)$_POST['startIndex'];
                        $batchSize = $jellyfin_config['import_batch_size'];
                        
                        // Get all movies using pagination
                        $result = getAllJellyfinMovies($jellyfin_config['server_url'], $jellyfin_config['api_key'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $allMovies = $result['data'];
                        
                        // Process this batch
                        $currentBatch = array_slice($allMovies, $startIndex, $batchSize);
                        $endIndex = $startIndex + count($currentBatch);
                        $isComplete = $endIndex >= count($allMovies);
                        
                        // Process the batch
                        $batchResults = processBatch($currentBatch, $jellyfin_config['server_url'], $jellyfin_config['api_key'], $targetDir, $overwriteOption, $type);
                        
                        // Handle orphaned posters if this is the final batch
                        $orphanedResults = null;
                        if ($isComplete) {
                            // Safely get imported IDs from current batch
                            $allImportedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
                                ? $batchResults['importedIds'] 
                                : [];
                            
                            logDebug("Movies: Current batch imported IDs", [
                                'count' => count($allImportedIds),
                                'isArray' => is_array($allImportedIds)
                            ]);
                            
                            // Retrieve IDs from previous batches with proper null checks
                            if (isset($_SESSION['import_movie_ids']) && is_array($_SESSION['import_movie_ids'])) {
                                $allImportedIds = array_merge($allImportedIds, $_SESSION['import_movie_ids']);
                                logDebug("Movies: Added IDs from session", [
                                    'session_count' => count($_SESSION['import_movie_ids']),
                                    'total_count' => count($allImportedIds)
                                ]);
                            }
                            
                            // Process orphaned posters with proper checking
                            if (is_array($allImportedIds)) {
                                $orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Jellyfin**');
                            } else {
                                logDebug("Movies: Error: allImportedIds is not an array", [
                                    'type' => gettype($allImportedIds),
                                    'value' => $allImportedIds
                                ]);
                                $orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
                            }
                            
                            // Clear the session
                            unset($_SESSION['import_movie_ids']);
                        } else {
                            // Ensure we have an array in the session
                            if (!isset($_SESSION['import_movie_ids']) || !is_array($_SESSION['import_movie_ids'])) {
                                $_SESSION['import_movie_ids'] = [];
                            }
                            
                            // Ensure we're merging arrays, with proper null checks
                            $importedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
                                ? $batchResults['importedIds'] 
                                : [];
                            
                            $_SESSION['import_movie_ids'] = array_merge($_SESSION['import_movie_ids'], $importedIds);
                            
                            logDebug("Movies: Stored IDs for next batch", [
                                'batch_count' => count($importedIds),
                                'session_total' => count($_SESSION['import_movie_ids'])
                            ]);
                        }
                        
                        // Respond with batch results and progress
                        echo json_encode([
                            'success' => true,
                            'batchComplete' => true,
                            'progress' => [
                                'processed' => $endIndex,
                                'total' => count($allMovies),
                                'percentage' => round(($endIndex / count($allMovies)) * 100),
                                'isComplete' => $isComplete,
                                'nextIndex' => $isComplete ? null : $endIndex
                            ],
                            'results' => $batchResults,
                            'orphanedResults' => $orphanedResults,
                            'totalStats' => [
                                'successful' => $batchResults['successful'] ?? 0,
                                'skipped' => $batchResults['skipped'] ?? 0,
                                'unchanged' => $batchResults['unchanged'] ?? 0,
                                'failed' => $batchResults['failed'] ?? 0,
                                'orphaned' => $orphanedResults ? (($orphanedResults['orphaned'] ?? 0) + ($orphanedResults['unmarked'] ?? 0)) : 0
                            ]
                        ]);
                        exit;
                    } else {
                        // Process all movies at once
                        $result = getAllJellyfinMovies($jellyfin_config['server_url'], $jellyfin_config['api_key'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $items = $result['data'];
                    }
                    break;
                
                case 'shows':
                    // Handle batch processing
                    if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                        $startIndex = (int)$_POST['startIndex'];
                        $batchSize = $jellyfin_config['import_batch_size'];
                        
                        // Get all shows using pagination
                        $result = getAllJellyfinShows($jellyfin_config['server_url'], $jellyfin_config['api_key'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $allShows = $result['data'];
                        
                        // Process this batch
                        $currentBatch = array_slice($allShows, $startIndex, $batchSize);
                        $endIndex = $startIndex + count($currentBatch);
                        $isComplete = $endIndex >= count($allShows);
                        
                        // Process the batch
                        $batchResults = processBatch($currentBatch, $jellyfin_config['server_url'], $jellyfin_config['api_key'], $targetDir, $overwriteOption, $type);
                        
                        // Handle orphaned posters if this is the final batch
                        $orphanedResults = null;
                        if ($isComplete) {
                            // Safely get imported IDs from current batch
                            $allImportedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
                                ? $batchResults['importedIds'] 
                                : [];
                            
                            logDebug("Shows: Current batch imported IDs", [
                                'count' => count($allImportedIds),
                                'isArray' => is_array($allImportedIds)
                            ]);
                            
                            // Retrieve IDs from previous batches with proper null checks
                            if (isset($_SESSION['import_show_ids']) && is_array($_SESSION['import_show_ids'])) {
                                $allImportedIds = array_merge($allImportedIds, $_SESSION['import_show_ids']);
                                logDebug("Shows: Added IDs from session", [
                                    'session_count' => count($_SESSION['import_show_ids']),
                                    'total_count' => count($allImportedIds)
                                ]);
                            }
                            
                            // Process orphaned posters with proper checking
                            if (is_array($allImportedIds)) {
                                $orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Jellyfin**');
                            } else {
                                logDebug("Shows: Error: allImportedIds is not an array", [
                                    'type' => gettype($allImportedIds),
                                    'value' => $allImportedIds
                                ]);
                                $orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
                            }
                            
                            // Clear the session
                            unset($_SESSION['import_show_ids']);
                        } else {
                            // Ensure we have an array in the session
                            if (!isset($_SESSION['import_show_ids']) || !is_array($_SESSION['import_show_ids'])) {
                                $_SESSION['import_show_ids'] = [];
                            }
                            
                            // Ensure we're merging arrays, with proper null checks
                            $importedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
                                ? $batchResults['importedIds'] 
                                : [];
                            
                            $_SESSION['import_show_ids'] = array_merge($_SESSION['import_show_ids'], $importedIds);
                            
                            logDebug("Shows: Stored IDs for next batch", [
                                'batch_count' => count($importedIds),
                                'session_total' => count($_SESSION['import_show_ids'])
                            ]);
                        }
                        
                        // Respond with batch results and progress
                        echo json_encode([
                            'success' => true,
                            'batchComplete' => true,
                            'progress' => [
                                'processed' => $endIndex,
                                'total' => count($allShows),
                                'percentage' => round(($endIndex / count($allShows)) * 100),
                                'isComplete' => $isComplete,
                                'nextIndex' => $isComplete ? null : $endIndex
                            ],
                            'results' => $batchResults,
                            'orphanedResults' => $orphanedResults,
                            'totalStats' => [
                                'successful' => $batchResults['successful'] ?? 0,
                                'skipped' => $batchResults['skipped'] ?? 0,
                                'unchanged' => $batchResults['unchanged'] ?? 0,
                                'failed' => $batchResults['failed'] ?? 0,
                                'orphaned' => $orphanedResults ? (($orphanedResults['orphaned'] ?? 0) + ($orphanedResults['unmarked'] ?? 0)) : 0
                            ]
                        ]);
                        exit;
                    } else {
                        // Process all shows at once
                        $result = getAllJellyfinShows($jellyfin_config['server_url'], $jellyfin_config['api_key'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $items = $result['data'];
                    }
                    break;
                    
                    case 'seasons':
                    try {
                        // Check if we're importing all seasons
                        $importAllSeasons = isset($_POST['importAllSeasons']) && $_POST['importAllSeasons'] === 'true';
                        
                        logDebug("Seasons import requested", [
                            'importAllSeasons' => $importAllSeasons,
                            'showKey' => isset($_POST['showKey']) ? $_POST['showKey'] : 'not set',
                            'libraryId' => $libraryId
                        ]);
                        
                        if ($importAllSeasons) {
                            // Get all shows first
                            $showsResult = getAllJellyfinShows($jellyfin_config['server_url'], $jellyfin_config['api_key'], $libraryId);
                            if (!$showsResult['success']) {
                                throw new Exception($showsResult['error']);
                            }
                            $shows = $showsResult['data'];
                            logDebug("Found " . count($shows) . " shows for all seasons import");
                            
                            if (empty($shows)) {
                                echo json_encode([
                                    'success' => false,
                                    'error' => 'No shows found in the selected library'
                                ]);
                                exit;
                            }
                            
                            // Handle batch processing for shows to get all seasons
                            if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                                $startIndex = (int)$_POST['startIndex'];
                                
                                // If we're processing shows in batches and handling all shows' seasons
                                if ($startIndex < count($shows)) {
                                    // Process seasons for this show
                                    $show = $shows[$startIndex];
                                    logDebug("Processing seasons for show: " . $show['title'] . " (ID: " . $show['id'] . ")");
                                    
                                    try {
                                        $seasonsResult = getAllJellyfinSeasons($jellyfin_config['server_url'], $jellyfin_config['api_key'], $show['id']);
                                        
                                        // Initialize totalStats if not set
                                        $totalStats = [
                                            'successful' => isset($_POST['totalSuccessful']) ? (int)$_POST['totalSuccessful'] : 0,
                                            'skipped' => isset($_POST['totalSkipped']) ? (int)$_POST['totalSkipped'] : 0,
                                            'unchanged' => isset($_POST['totalUnchanged']) ? (int)$_POST['totalUnchanged'] : 0,
                                            'failed' => isset($_POST['totalFailed']) ? (int)$_POST['totalFailed'] : 0,
                                            'errors' => [],
                                            'skippedDetails' => []
                                        ];
                                        
                                        if ($seasonsResult['success']) {
                                            $items = $seasonsResult['data']; 
                                            logDebug("Found " . count($items) . " seasons with images for show " . $show['title']);
                                            
                                            if (!empty($items)) {
                                                // Process seasons for this show
                                                $batchResults = processBatch($items, $jellyfin_config['server_url'], $jellyfin_config['api_key'], $targetDir, $overwriteOption, $type);
                                                
                                                // Update running totals
                                                $totalStats['successful'] += $batchResults['successful'];
                                                $totalStats['skipped'] += $batchResults['skipped'];
                                                $totalStats['unchanged'] += $batchResults['unchanged'];
                                                $totalStats['failed'] += $batchResults['failed'];
                                                
                                                if (!empty($batchResults['errors'])) {
                                                    $totalStats['errors'] = array_merge($totalStats['errors'], $batchResults['errors']);
                                                }
                                                
                                                if (!empty($batchResults['skippedDetails'])) {
                                                    $totalStats['skippedDetails'] = array_merge($totalStats['skippedDetails'], $batchResults['skippedDetails']);
                                                }
                                                
                                                // Store imported IDs for orphaned processing
                                                $importedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
                                                    ? $batchResults['importedIds'] 
                                                    : [];
                                                
                                                // Ensure we have an array in the session
                                                if (!isset($_SESSION['import_season_ids']) || !is_array($_SESSION['import_season_ids'])) {
                                                    $_SESSION['import_season_ids'] = [];
                                                }
                                                
                                                $_SESSION['import_season_ids'] = array_merge($_SESSION['import_season_ids'], $importedIds);
                                            } else {
                                                logDebug("No seasons with images found for show: " . $show['title']);
                                            }
                                        } else {
                                            logDebug("Error getting seasons for show: " . $show['title'] . " - " . $seasonsResult['error']);
                                            $totalStats['errors'][] = "Error getting seasons for " . $show['title'] . ": " . $seasonsResult['error'];
                                        }
                                        
                                        // Check if this is the last show - if so, process orphaned posters
                                        $isComplete = ($startIndex + 1) >= count($shows);
                                        $orphanedResults = null;
                                        
                                        if ($isComplete && isset($_SESSION['import_season_ids']) && is_array($_SESSION['import_season_ids'])) {
                                            $orphanedResults = markOrphanedPosters($targetDir, $_SESSION['import_season_ids'], '**Jellyfin**');
                                            unset($_SESSION['import_season_ids']);
                                        }
                                        
                                        // Return batch progress information
                                        echo json_encode([
                                            'success' => true,
                                            'batchComplete' => true,
                                            'progress' => [
                                                'processed' => $startIndex + 1,
                                                'total' => count($shows),
                                                'percentage' => round((($startIndex + 1) / count($shows)) * 100),
                                                'isComplete' => $isComplete,
                                                'nextIndex' => $isComplete ? null : $startIndex + 1,
                                                'currentShow' => $show['title'],
                                                'seasonCount' => isset($items) ? count($items) : 0
                                            ],
                                            'results' => isset($batchResults) ? $batchResults : [
                                                'successful' => 0,
                                                'skipped' => 0,
                                                'unchanged' => 0,
                                                'failed' => 0,
                                                'errors' => [],
                                                'skippedDetails' => []
                                            ],
                                            'orphanedResults' => $orphanedResults,
                                            'totalStats' => [
                                                'successful' => $totalStats['successful'],
                                                'skipped' => $totalStats['skipped'],
                                                'unchanged' => $totalStats['unchanged'],
                                                'failed' => $totalStats['failed'],
                                                'orphaned' => $orphanedResults ? (($orphanedResults['orphaned'] ?? 0) + ($orphanedResults['unmarked'] ?? 0)) : 0
                                            ]
                                        ]);
                                    } catch (Exception $e) {
                                        logDebug("Exception processing show " . $show['title'] . ": " . $e->getMessage());
                                        
                                        // Return error but continue with next show
                                        echo json_encode([
                                            'success' => true, // Still return success to continue processing
                                            'batchComplete' => true,
                                            'progress' => [
                                                'processed' => $startIndex + 1,
                                                'total' => count($shows),
                                                'percentage' => round((($startIndex + 1) / count($shows)) * 100),
                                                'isComplete' => ($startIndex + 1) >= count($shows),
                                                'nextIndex' => ($startIndex + 1) >= count($shows) ? null : $startIndex + 1,
                                                'currentShow' => $show['title'],
                                                'seasonCount' => 0
                                            ],
                                            'results' => [
                                                'successful' => 0,
                                                'skipped' => 0,
                                                'unchanged' => 0,
                                                'failed' => 1,
                                                'errors' => [$e->getMessage()],
                                                'skippedDetails' => []
                                            ],
                                            'totalStats' => $totalStats
                                        ]);
                                    }
                                    exit;
                                }
                            }
                        } else {
                            // Just get seasons for one show
                            if (empty($_POST['showKey'])) {
                                throw new Exception('Show key is required for single-show seasons import');
                            }
                            
                            $showKey = $_POST['showKey'];
                            logDebug("Getting seasons for specific show: " . $showKey);
                            
                            // Handle batch processing for a single show's seasons
                            if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                                $startIndex = (int)$_POST['startIndex'];
                                $batchSize = $jellyfin_config['import_batch_size'];
                                
                                // Get all seasons for this show
                                $result = getAllJellyfinSeasons($jellyfin_config['server_url'], $jellyfin_config['api_key'], $showKey);
                                if (!$result['success']) {
                                    throw new Exception($result['error']);
                                }
                                $allSeasons = $result['data'];
                                
                                // Process this batch
                                $currentBatch = array_slice($allSeasons, $startIndex, $batchSize);
                                $endIndex = $startIndex + count($currentBatch);
                                $isComplete = $endIndex >= count($allSeasons);
                                
                                // Process the batch
                                $batchResults = processBatch($currentBatch, $jellyfin_config['server_url'], $jellyfin_config['api_key'], $targetDir, $overwriteOption, $type);
                                
                                // Handle orphaned posters if this is the final batch
                                $orphanedResults = null;
                                if ($isComplete) {
                                    // Safely get imported IDs from current batch
                                    $allImportedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
                                        ? $batchResults['importedIds'] 
                                        : [];
                                    
                                    // Retrieve IDs from previous batches with proper null checks
                                    if (isset($_SESSION['import_single_show_season_ids']) && is_array($_SESSION['import_single_show_season_ids'])) {
                                        $allImportedIds = array_merge($allImportedIds, $_SESSION['import_single_show_season_ids']);
                                    }
                                    
                                    // Process orphaned posters with proper checking
                                    if (is_array($allImportedIds)) {
                                        $orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Jellyfin**');
                                    } else {
                                        $orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
                                    }
                                    
                                    // Clear the session
                                    unset($_SESSION['import_single_show_season_ids']);
                                } else {
                                    // Ensure we have an array in the session
                                    if (!isset($_SESSION['import_single_show_season_ids']) || !is_array($_SESSION['import_single_show_season_ids'])) {
                                        $_SESSION['import_single_show_season_ids'] = [];
                                    }
                                    
                                    // Ensure we're merging arrays, with proper null checks
                                    $importedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
                                        ? $batchResults['importedIds'] 
                                        : [];
                                    
                                    $_SESSION['import_single_show_season_ids'] = array_merge($_SESSION['import_single_show_season_ids'], $importedIds);
                                }
                                
                                // Respond with batch results and progress
                                echo json_encode([
                                    'success' => true,
                                    'batchComplete' => true,
                                    'progress' => [
                                        'processed' => $endIndex,
                                        'total' => count($allSeasons),
                                        'percentage' => round(($endIndex / count($allSeasons)) * 100),
                                        'isComplete' => $isComplete,
                                        'nextIndex' => $isComplete ? null : $endIndex
                                    ],
                                    'results' => $batchResults,
                                    'orphanedResults' => $orphanedResults,
                                    'totalStats' => [
                                        'successful' => $batchResults['successful'] ?? 0,
                                        'skipped' => $batchResults['skipped'] ?? 0,
                                        'unchanged' => $batchResults['unchanged'] ?? 0,
                                        'failed' => $batchResults['failed'] ?? 0,
                                        'orphaned' => $orphanedResults ? (($orphanedResults['orphaned'] ?? 0) + ($orphanedResults['unmarked'] ?? 0)) : 0
                                    ]
                                ]);
                                exit;
                            } else {
                                $result = getAllJellyfinSeasons($jellyfin_config['server_url'], $jellyfin_config['api_key'], $showKey);
                                if (!$result['success']) {
                                    throw new Exception($result['error']);
                                }
                                
                                if (empty($result['data'])) {
                                    echo json_encode([
                                        'success' => false,
                                        'error' => 'No seasons found with images for the selected show'
                                    ]);
                                    exit;
                                }
                                
                                $items = $result['data'];
                            }
                        }
                    } catch (Exception $e) {
                        logDebug("Exception in seasons import: " . $e->getMessage());
                        echo json_encode([
                            'success' => false,
                            'error' => 'Error importing seasons: ' . $e->getMessage()
                        ]);
                        exit;
                    }
                    break;
                    
                    case 'collections':
                    // Handle batch processing
                    if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                        $startIndex = (int)$_POST['startIndex'];
                        $batchSize = $jellyfin_config['import_batch_size'];
                        
                        try {
                            // Get all collections using pagination
                            $result = getAllJellyfinCollections($jellyfin_config['server_url'], $jellyfin_config['api_key'], $libraryId);
                            if (!$result['success']) {
                                throw new Exception($result['error']);
                            }
                            
                            // Make sure we have an array of collections, never null
                            $allCollections = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
                            
                            // Log what we found
                            logDebug("Collections batch processing", [
                                'collections_count' => count($allCollections),
                                'startIndex' => $startIndex,
                                'batchSize' => $batchSize
                            ]);
                            
                            // Check if there are any collections
                            if (empty($allCollections)) {
                                // No collections found, return empty result
                                echo json_encode([
                                    'success' => true,
                                    'batchComplete' => true,
                                    'progress' => [
                                        'processed' => 0,
                                        'total' => 0,
                                        'percentage' => 100, // Use 100% when there are no items
                                        'isComplete' => true,
                                        'nextIndex' => null
                                    ],
                                    'results' => [
                                        'successful' => 0,
                                        'skipped' => 0,
                                        'unchanged' => 0,
                                        'failed' => 0,
                                        'errors' => [],
                                        'skippedDetails' => []
                                    ],
                                    'totalStats' => [
                                        'successful' => 0,
                                        'skipped' => 0,
                                        'unchanged' => 0,
                                        'failed' => 0,
                                        'orphaned' => 0
                                    ]
                                ]);
                                exit;
                            }
                            
                            // Process this batch - make sure we don't go out of bounds
                            $currentBatch = [];
                            if ($startIndex < count($allCollections)) {
                                $currentBatch = array_slice($allCollections, $startIndex, $batchSize);
                            }
                            
                            $endIndex = $startIndex + count($currentBatch);
                            $isComplete = $endIndex >= count($allCollections);
                            
                            // Process the batch
                            $batchResults = processBatch($currentBatch, $jellyfin_config['server_url'], $jellyfin_config['api_key'], $targetDir, $overwriteOption, 'collections');
                            
                            // Ensure batchResults is properly structured
                            if (!isset($batchResults['importedIds']) || !is_array($batchResults['importedIds'])) {
                                $batchResults['importedIds'] = [];
                            }
                            
                            // Handle orphaned posters if this is the final batch
                            $orphanedResults = null;
                            if ($isComplete) {
                                // Safely get imported IDs from current batch
                                $allImportedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
                                    ? $batchResults['importedIds'] 
                                    : [];
                                
                                // Retrieve IDs from previous batches with proper null checks
                                if (isset($_SESSION['import_collection_ids']) && is_array($_SESSION['import_collection_ids'])) {
                                    $allImportedIds = array_merge($allImportedIds, $_SESSION['import_collection_ids']);
                                }
                                
                                // Verify target directory exists
                                if (!is_dir($targetDir)) {
                                    logDebug("Collections: Target directory does not exist", [
                                        'targetDir' => $targetDir
                                    ]);
                                    
                                    if (!mkdir($targetDir, 0755, true)) {
                                        logDebug("Collections: Failed to create target directory");
                                        $orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
                                    } else {
                                        logDebug("Collections: Created target directory");
                                        // Process orphaned posters with proper checking
                                        $orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Jellyfin**');
                                    }
                                } else {
                                    // Process orphaned posters with proper checking
                                    $orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Jellyfin**');
                                }
                                
                                // Clear the session
                                unset($_SESSION['import_collection_ids']);
                            } else {
                                // Ensure we have an array in the session
                                if (!isset($_SESSION['import_collection_ids']) || !is_array($_SESSION['import_collection_ids'])) {
                                    $_SESSION['import_collection_ids'] = [];
                                }
                                
                                // Ensure we're merging arrays, with proper null checks
                                $importedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
                                    ? $batchResults['importedIds'] 
                                    : [];
                                
                                $_SESSION['import_collection_ids'] = array_merge($_SESSION['import_collection_ids'], $importedIds);
                            }
                            
                            // Make sure these values are never null for the JSON response
                            if (!isset($orphanedResults) || !is_array($orphanedResults)) {
                                $orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
                            }
                            
                            // Make sure the progress values are valid
                            $totalCollections = count($allCollections);
                            $processedCount = $endIndex;
                            $percentage = $totalCollections > 0 ? round(($processedCount / $totalCollections) * 100) : 100;
                            
                            // Respond with batch results and progress
                            echo json_encode([
                                'success' => true,
                                'batchComplete' => true,
                                'progress' => [
                                    'processed' => $processedCount,
                                    'total' => $totalCollections,
                                    'percentage' => $percentage,
                                    'isComplete' => $isComplete,
                                    'nextIndex' => $isComplete ? null : $endIndex
                                ],
                                'results' => $batchResults,
                                'orphanedResults' => $orphanedResults,
                                'totalStats' => [
                                    'successful' => $batchResults['successful'] ?? 0,
                                    'skipped' => $batchResults['skipped'] ?? 0,
                                    'unchanged' => $batchResults['unchanged'] ?? 0,
                                    'failed' => $batchResults['failed'] ?? 0,
                                    'orphaned' => ($orphanedResults['orphaned'] ?? 0) + ($orphanedResults['unmarked'] ?? 0)
                                ]
                            ]);
                            exit;
                            
                        } catch (Exception $e) {
                            // Log the error and provide detailed information
                            logDebug("Collections batch processing error", [
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                            
                            echo json_encode([
                                'success' => false,
                                'error' => 'Error processing collections: ' . $e->getMessage()
                            ]);
                            exit;
                        }
                    } else {
                        // Process all collections at once
                        $result = getAllJellyfinCollections($jellyfin_config['server_url'], $jellyfin_config['api_key'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $items = $result['data'];
                    }
                    break;
                
                default:
                    throw new Exception('Invalid import type');
            }
            
            // This code will only execute for non-batch processing, which is not recommended for large libraries
            // Process all items
            $results = processBatch($items, $jellyfin_config['server_url'], $jellyfin_config['api_key'], $targetDir, $overwriteOption, $type);
            
            // Mark orphaned posters if processing entire library at once
            $orphanedResults = null;
            if (is_array($results['importedIds']) && !empty($results['importedIds'])) {
                $orphanedResults = markOrphanedPosters($targetDir, $results['importedIds'], '**Jellyfin**');
            }
            
            echo json_encode([
                'success' => true,
                'complete' => true,
                'processed' => count($items),
                'results' => $results,
                'orphanedResults' => $orphanedResults,
                'totalStats' => [
                    'successful' => $results['successful'] ?? 0,
                    'skipped' => $results['skipped'] ?? 0,
                    'unchanged' => $results['unchanged'] ?? 0,
                    'failed' => $results['failed'] ?? 0,
                    'orphaned' => $orphanedResults ? (($orphanedResults['orphaned'] ?? 0) + ($orphanedResults['unmarked'] ?? 0)) : 0
                ]
            ]);
            exit;
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Default response if no action matched
    logDebug("No matching action found");
    echo json_encode(['success' => false, 'error' => 'Invalid action requested']);

} catch (Exception $e) {
    // Log the error
    logDebug("Unhandled exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
