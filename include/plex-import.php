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

// Log function for debugging
function logDebug($message, $data = null) {
    $logMessage = date('Y-m-d H:i:s') . ": " . $message;
    if ($data !== null) {
        $logMessage .= "\nData: " . print_r($data, true);
    }
    file_put_contents('plex-debug.log', $logMessage . "\n\n", FILE_APPEND);
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
        'plex_config_exists' => isset($plex_config),
        'plex_config' => isset($plex_config) ? $plex_config : null
    ]);

    // Check if auth_config and plex_config exist
    if (!isset($auth_config) || !isset($plex_config)) {
        logDebug("Missing configuration variables");
        echo json_encode(['success' => false, 'error' => 'Configuration not properly loaded']);
        exit;
    }

    // Check if Plex token is set
    if (empty($plex_config['token'])) {
        logDebug("Plex token is not set");
        echo json_encode(['success' => false, 'error' => 'Plex token is not configured. Please add your token to config.php']);
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

	function generatePlexFilename($title, $id, $extension, $mediaType = '', $libraryType = '') {
		$basename = sanitizeFilename($title);
		if (!empty($id)) {
		    $basename .= " [{$id}]";
		}
		
		// For collections, add movie/TV markers based on the library type
		if ($mediaType === 'collections') {
		    // Add collection marker if not already in the name
		    $collectionLabel = (!stripos($basename, 'Collection')) ? " Collection" : "";
		    
		    // Add type marker based on library type
		    if ($libraryType === 'movie') {
		        $basename .= "{$collectionLabel} (Movies) **Plex**";
		    } else if ($libraryType === 'show') {
		        $basename .= "{$collectionLabel} (TV) **Plex**";
		    } else {
		        // If library type unknown, just use a generic marker
		        $basename .= "{$collectionLabel} **Plex**";
		    }
		} else {
		    // For regular items (movies, shows, seasons)
		    $basename .= " **Plex**";
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

    function getPlexHeaders($token, $start = 0, $size = 50) {
        return [
            'Accept' => 'application/json',
            'X-Plex-Token' => $token,
            'X-Plex-Client-Identifier' => 'Posteria',
            'X-Plex-Product' => 'Posteria',
            'X-Plex-Version' => '1.0',
            'X-Plex-Container-Start' => $start,
            'X-Plex-Container-Size' => $size
        ];
    }

    function makeApiRequest($url, $headers, $expectJson = true) {
        global $plex_config;
        
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
            CURLOPT_CONNECTTIMEOUT => $plex_config['connect_timeout'],
            CURLOPT_TIMEOUT => $plex_config['request_timeout'],
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
            logDebug("API request returned HTTP code: " . $httpCode);
            throw new Exception("API request returned HTTP code: " . $httpCode);
        }
        
        // Only validate JSON if we expect JSON
        if ($expectJson) {
            // Try to parse JSON to verify it's valid
            $jsonTest = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                logDebug("Invalid JSON response: " . json_last_error_msg());
                throw new Exception("Invalid JSON response: " . json_last_error_msg());
            }
        }
        
        return $response;
    }

    function validatePlexConnection($serverUrl, $token) {
        try {
            logDebug("Validating Plex connection", [
                'server_url' => $serverUrl,
                'token_length' => strlen($token)
            ]);
            
            $url = rtrim($serverUrl, '/') . "/identity";
            $headers = [];
            foreach (getPlexHeaders($token) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['machineIdentifier'])) {
                logDebug("Invalid Plex server response - missing machineIdentifier");
                return ['success' => false, 'error' => 'Invalid Plex server response'];
            }
            
            logDebug("Plex connection validated successfully", [
                'identifier' => $data['MediaContainer']['machineIdentifier'],
                'version' => $data['MediaContainer']['version'] ?? 'Unknown'
            ]);
            
            return ['success' => true, 'data' => [
                'identifier' => $data['MediaContainer']['machineIdentifier'],
                'version' => $data['MediaContainer']['version'] ?? 'Unknown'
            ]];
        } catch (Exception $e) {
            logDebug("Plex connection validation failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function getPlexLibraries($serverUrl, $token) {
        try {
            logDebug("Getting Plex libraries", ['server_url' => $serverUrl]);
            
            $url = rtrim($serverUrl, '/') . "/library/sections";
            $headers = [];
            foreach (getPlexHeaders($token) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Directory'])) {
                logDebug("No libraries found in Plex response");
                return ['success' => false, 'error' => 'No libraries found'];
            }
            
            $libraries = [];
            foreach ($data['MediaContainer']['Directory'] as $lib) {
                $type = $lib['type'] ?? '';
                if (in_array($type, ['movie', 'show'])) {
                    $libraries[] = [
                        'id' => $lib['key'],
                        'title' => $lib['title'],
                        'type' => $type
                    ];
                }
            }
            
            logDebug("Found Plex libraries", ['count' => count($libraries), 'libraries' => $libraries]);
            
            return ['success' => true, 'data' => $libraries];
        } catch (Exception $e) {
            logDebug("Error getting Plex libraries: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    function getPlexMovies($serverUrl, $token, $libraryId, $start = 0, $size = 50) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/all";
            $headers = [];
            foreach (getPlexHeaders($token, $start, $size) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                return ['success' => false, 'error' => 'No movies found'];
            }
            
            $movies = [];
            foreach ($data['MediaContainer']['Metadata'] as $movie) {
                if (isset($movie['thumb'])) {
                    $movies[] = [
                        'title' => $movie['title'],
                        'id' => $movie['ratingKey'],
                        'thumb' => $movie['thumb'],
                        'year' => $movie['year'] ?? '',
                        'ratingKey' => $movie['ratingKey']
                    ];
                }
            }
            
            // Get total count for pagination
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($movies);
            $moreAvailable = ($start + count($movies)) < $totalSize;
            
            return [
                'success' => true, 
                'data' => $movies,
                'pagination' => [
                    'start' => $start,
                    'size' => count($movies),
                    'totalSize' => $totalSize,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all Plex movies with pagination
    function getAllPlexMovies($serverUrl, $token, $libraryId) {
        $allMovies = [];
        $start = 0;
        $size = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getPlexMovies($serverUrl, $token, $libraryId, $start, $size);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allMovies = array_merge($allMovies, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $start += $size;
        }
        
        return ['success' => true, 'data' => $allMovies];
    }

    function getPlexShows($serverUrl, $token, $libraryId, $start = 0, $size = 50) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/all";
            $headers = [];
            foreach (getPlexHeaders($token, $start, $size) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                return ['success' => false, 'error' => 'No shows found'];
            }
            
            $shows = [];
            foreach ($data['MediaContainer']['Metadata'] as $show) {
                if (isset($show['thumb'])) {                  
                    $shows[] = [
                        'title' => $show['title'],
                        'id' => $show['ratingKey'],
                        'thumb' => $show['thumb'],
                        'year' => $show['year'] ?? '',
                        'ratingKey' => $show['ratingKey']
                    ];
                }
            }
            
            // Get total count for pagination
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($shows);
            $moreAvailable = ($start + count($shows)) < $totalSize;
            
            return [
                'success' => true, 
                'data' => $shows,
                'pagination' => [
                    'start' => $start,
                    'size' => count($shows),
                    'totalSize' => $totalSize,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all Plex shows with pagination
    function getAllPlexShows($serverUrl, $token, $libraryId) {
        $allShows = [];
        $start = 0;
        $size = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getPlexShows($serverUrl, $token, $libraryId, $start, $size);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allShows = array_merge($allShows, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $start += $size;
        }
        
        return ['success' => true, 'data' => $allShows];
    }

    function getPlexSeasons($serverUrl, $token, $showKey, $start = 0, $size = 50) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/metadata/{$showKey}/children";
            $headers = [];
            foreach (getPlexHeaders($token, $start, $size) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                return ['success' => false, 'error' => 'No seasons found'];
            }
            
            $seasons = [];
            foreach ($data['MediaContainer']['Metadata'] as $season) {
                if (isset($season['thumb']) && isset($season['index'])) {
                    $seasons[] = [
                        'title' => $season['parentTitle'] . ' - ' . $season['title'],
                        'id' => $season['ratingKey'],
                        'thumb' => $season['thumb'],
                        'index' => $season['index'],
                        'ratingKey' => $season['ratingKey']
                    ];
                }
            }
            
            // Get total count for pagination
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($seasons);
            $moreAvailable = ($start + count($seasons)) < $totalSize;
            
            return [
                'success' => true, 
                'data' => $seasons,
                'pagination' => [
                    'start' => $start,
                    'size' => count($seasons),
                    'totalSize' => $totalSize,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all seasons for a show with pagination
    function getAllPlexSeasons($serverUrl, $token, $showKey) {
        $allSeasons = [];
        $start = 0;
        $size = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getPlexSeasons($serverUrl, $token, $showKey, $start, $size);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allSeasons = array_merge($allSeasons, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $start += $size;
        }
        
        return ['success' => true, 'data' => $allSeasons];
    }

    function getPlexCollections($serverUrl, $token, $libraryId, $start = 0, $size = 50) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/collections";
            $headers = [];
            foreach (getPlexHeaders($token, $start, $size) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                return ['success' => true, 'data' => []]; // Collections might be empty
            }
            
            $collections = [];
            foreach ($data['MediaContainer']['Metadata'] as $collection) {
                if (isset($collection['thumb'])) {
                    $collections[] = [
                        'title' => $collection['title'],
                        'id' => $collection['ratingKey'],
                        'thumb' => $collection['thumb'],
                        'ratingKey' => $collection['ratingKey']
                    ];
                }
            }
            
            // Get total count for pagination
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($collections);
            $moreAvailable = ($start + count($collections)) < $totalSize;
            
            return [
                'success' => true, 
                'data' => $collections,
                'pagination' => [
                    'start' => $start,
                    'size' => count($collections),
                    'totalSize' => $totalSize,
                    'moreAvailable' => $moreAvailable
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all collections with pagination
    function getAllPlexCollections($serverUrl, $token, $libraryId) {
        $allCollections = [];
        $start = 0;
        $size = 50;
        $moreAvailable = true;
        
        while ($moreAvailable) {
            $result = getPlexCollections($serverUrl, $token, $libraryId, $start, $size);
            
            if (!$result['success']) {
                return $result;
            }
            
            $allCollections = array_merge($allCollections, $result['data']);
            $moreAvailable = $result['pagination']['moreAvailable'];
            $start += $size;
        }
        
        return ['success' => true, 'data' => $allCollections];
    }

    // NEW FUNCTION: Get image data without saving it
    function getPlexImageData($serverUrl, $token, $thumb) {
        try {
            $url = rtrim($serverUrl, '/') . $thumb;
            $headers = [];
            foreach (getPlexHeaders($token) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            // Pass false to indicate we don't expect JSON for image downloads
            $imageData = makeApiRequest($url, $headers, false);
            return ['success' => true, 'data' => $imageData];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // UPDATED: Function to download and save Plex image to file
    function downloadPlexImage($serverUrl, $token, $thumb, $targetPath) {
        try {
            $url = rtrim($serverUrl, '/') . $thumb;
            $headers = [];
            foreach (getPlexHeaders($token) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            
            // Pass false to indicate we don't expect JSON for image downloads
            $imageData = makeApiRequest($url, $headers, false);
            
            if (!file_put_contents($targetPath, $imageData)) {
                throw new Exception("Failed to save image to: " . $targetPath);
            }
            
            chmod($targetPath, 0644);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // NEW FUNCTION: Compare image data with existing file
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

    // UPDATED: Process a batch of items with smart overwrite
	function processBatch($items, $serverUrl, $token, $targetDir, $overwriteOption, $mediaType = '', $libraryType = '') {
		$results = [
		    'successful' => 0,
		    'skipped' => 0,
		    'unchanged' => 0,
		    'failed' => 0,
		    'errors' => [],
		    'skippedDetails' => [],
		    'importedIds' => [] // Initialize this array
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
		    if (!isset($item['title']) || !isset($item['id']) || !isset($item['thumb'])) {
		        logDebug("Skipping malformed item in processBatch", $item);
		        continue;
		    }
		    
		    $title = $item['title'];
		    $id = $item['id'];
		    $thumb = $item['thumb'];
		    
		    // Generate target filename - now with library type
		    $extension = 'jpg'; // Plex thumbnails are usually JPG
		    $filename = generatePlexFilename($title, $id, $extension, $mediaType, $libraryType);
		    $targetPath = $targetDir . $filename;
		    
		    // Handle existing file based on overwrite option - rest of the function remains the same
		    if (file_exists($targetPath)) {
		        if ($overwriteOption === 'skip') {
		            $results['importedIds'][] = $id;
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
		            $downloadResult = downloadPlexImage($serverUrl, $token, $thumb, $targetPath);
		            
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
		            $imageResult = getPlexImageData($serverUrl, $token, $thumb);
		            
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
		        $downloadResult = downloadPlexImage($serverUrl, $token, $thumb, $targetPath);
		        
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
	 * Get all existing posters in a directory 
	 * 
	 * @param string $directory Directory path to search
	 * @param string $type Type of server ('Plex')
	 * @return array Associative array of ID => filename
	 */
	function getExistingPosters($directory, $type = '**Plex**') {
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
		            // Extract the ID if present (format: "Title [ID] Plex.jpg")
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
	 * Marks orphaned poster files in the specified directory
	 * 
	 * @param string $targetDir Directory to check for orphaned posters
	 * @param array $validIds List of valid IDs that should not be marked as orphaned
	 * @param string $orphanedTag Tag to replace **Plex** with in orphaned filenames
	 * @param string $libraryType The library type (movie/show) for collections
	 * @param string $showTitle The show title for seasons processing
	 * @param string $mediaType The type of media being processed (movies, shows, seasons, collections)
	 * @return array Results with counts and details of orphaned files
	 */
	function markOrphanedPosters($targetDir, $validIds, $orphanedTag = '**Orphaned**', $libraryType = '', $showTitle = '', $mediaType = '') {
		$results = [
		    'orphaned' => 0,
		    'unmarked' => 0,
		    'details' => []
		];
		
		if (!is_dir($targetDir)) {
		    return $results;
		}
		
		// Get all files in the directory
		$files = glob($targetDir . '/*');
		$plexTag = '**Plex**';
		
		foreach ($files as $file) {
		    if (!is_file($file)) {
		        continue;
		    }
		    
		    $filename = basename($file);
		    
		    // Skip files that are already marked as orphaned
		    if (strpos($filename, $orphanedTag) !== false) {
		        continue;
		    }
		    
		    // Skip files that don't have the Plex tag
		    if (strpos($filename, $plexTag) === false) {
		        continue;
		    }
		    
		    // Special handling for different media types
		    if ($mediaType === 'collections') {
		        // Process collection files differently based on library type
		        if (!empty($libraryType)) {
		            // Check if this is a collection file
		            $isCollection = strpos($filename, 'Collection') !== false || 
		                           strpos($filename, '(Movies)') !== false || 
		                           strpos($filename, '(TV)') !== false;
		            
		            if ($isCollection) {
		                // Check if this collection matches our library type
		                $isMatch = false;
		                
		                if ($libraryType === 'movie' && strpos($filename, '(Movies)') !== false) {
		                    $isMatch = true;
		                } else if ($libraryType === 'show' && strpos($filename, '(TV)') !== false) {
		                    $isMatch = true;
		                } else if (strpos($filename, '(Movies)') === false && 
		                          strpos($filename, '(TV)') === false) {
		                    // Generic collection without type marker - consider it a match
		                    $isMatch = true;
		                }
		                
		                // Only process matching collections
		                if (!$isMatch) {
		                    continue;
		                }
		            }
		        }
		    } else if ($mediaType === 'seasons') {
		        // For seasons, only process if they belong to the show we're currently handling
		        if (!empty($showTitle)) {
		            // If we have a show title, check if the filename contains it
		            if (stripos($filename, $showTitle) === false) {
		                // This season belongs to a different show, skip it
		                continue;
		            }
		        }
		    }
		    
		    // Extract the ID from the filename
		    $idMatch = [];
		    if (preg_match('/\[([a-f0-9]+)\]/', $filename, $idMatch)) {
		        $fileId = $idMatch[1];
		        
		        // Check if the ID is in our valid list
		        if (!in_array($fileId, $validIds)) {
		            // Replace **Plex** with **Orphaned** in the filename
		            $newFilename = str_replace($plexTag, $orphanedTag, $filename);
		            $newPath = $targetDir . '/' . $newFilename;
		            
		            if (rename($file, $newPath)) {
		                $results['orphaned']++;
		                $results['details'][] = [
		                    'oldName' => $filename,
		                    'newName' => $newFilename
		                ];
		            } else {
		                $results['unmarked']++;
		            }
		        }
		    }
		}
		
		return $results;
	}

    // API Endpoints

    // Test Plex Connection
    if (isset($_POST['action']) && $_POST['action'] === 'test_plex_connection') {
        logDebug("Processing test_plex_connection action");
        $result = validatePlexConnection($plex_config['server_url'], $plex_config['token']);
        echo json_encode($result);
        logDebug("Response sent", $result);
        exit;
    }

    // Get Plex Libraries
    if (isset($_POST['action']) && $_POST['action'] === 'get_plex_libraries') {
        logDebug("Processing get_plex_libraries action");
        $result = getPlexLibraries($plex_config['server_url'], $plex_config['token']);
        echo json_encode($result);
        logDebug("Response sent", $result);
        exit;
    }

    // Get Shows for Season Import
    if (isset($_POST['action']) && $_POST['action'] === 'get_plex_shows_for_seasons') {
        if (!isset($_POST['libraryId'])) {
            echo json_encode(['success' => false, 'error' => 'Missing library ID']);
            exit;
        }
        
        $libraryId = $_POST['libraryId'];
        $result = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $libraryId);
        echo json_encode($result);
        exit;
    }

    // Import Plex Posters
    if (isset($_POST['action']) && $_POST['action'] === 'import_plex_posters') {
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
                        $batchSize = $plex_config['import_batch_size'];
                        
                        // Get all movies using pagination
                        $result = getAllPlexMovies($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $allMovies = $result['data'];
                        
                        // Process this batch
                        $currentBatch = array_slice($allMovies, $startIndex, $batchSize);
                        $endIndex = $startIndex + count($currentBatch);
                        $isComplete = $endIndex >= count($allMovies);
                        
                        // Process the batch
                        $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type);
                        
						// Handle orphaned posters if this is the final batch
						$orphanedResults = null;
						if ($isComplete) {
							// Safely get imported IDs from current batch
							$allImportedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
								? $batchResults['importedIds'] 
								: [];
							
							logDebug("Current batch imported IDs", [
								'count' => count($allImportedIds),
								'isArray' => is_array($allImportedIds)
							]);
							
							// Retrieve IDs from previous batches with proper null checks
							if (isset($_SESSION['import_movie_ids']) && is_array($_SESSION['import_movie_ids'])) {
								$allImportedIds = array_merge($allImportedIds, $_SESSION['import_movie_ids']);
								logDebug("Added IDs from session", [
									'session_count' => count($_SESSION['import_movie_ids']),
									'total_count' => count($allImportedIds)
								]);
							}
							
							// Process orphaned posters with proper checking
							if (is_array($allImportedIds)) {
								$orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Orphaned**', '', '', 'movies');
							} else {
								logDebug("Error: allImportedIds is not an array", [
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
							
							logDebug("Stored IDs for next batch", [
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
                        $result = getAllPlexMovies($plex_config['server_url'], $plex_config['token'], $libraryId);
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
                        $batchSize = $plex_config['import_batch_size'];
                        
                        // Get all shows using pagination
                        $result = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $allShows = $result['data'];
                        
                        // Process this batch
                        $currentBatch = array_slice($allShows, $startIndex, $batchSize);
                        $endIndex = $startIndex + count($currentBatch);
                        $isComplete = $endIndex >= count($allShows);
                        
                        // Process the batch
                        $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type);
                        
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
								$orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Orphaned**', '', '', 'shows');
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
                        $result = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $items = $result['data'];
                    }
                    break;
                
                case 'seasons':
                    // Check if we're importing all seasons
                    $importAllSeasons = isset($_POST['importAllSeasons']) && $_POST['importAllSeasons'] === 'true';
                    
                    if ($importAllSeasons) {
                        // Get all shows first
                        $showsResult = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $libraryId);
                        if (!$showsResult['success']) {
                            throw new Exception($showsResult['error']);
                        }
                        $shows = $showsResult['data'];
                        
                        // Handle batch processing for shows to get all seasons
						if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
							$startIndex = (int)$_POST['startIndex'];
							
							// If we're processing shows in batches and handling all shows' seasons
							if ($startIndex < count($shows)) {
								// Process seasons for this show
								$show = $shows[$startIndex];
								$seasonsResult = getAllPlexSeasons($plex_config['server_url'], $plex_config['token'], $show['ratingKey']);
								
								// Get running totals from previous batches if available
								$totalStats['successful'] = isset($_POST['totalSuccessful']) ? (int)$_POST['totalSuccessful'] : 0;
								$totalStats['skipped'] = isset($_POST['totalSkipped']) ? (int)$_POST['totalSkipped'] : 0;
								$totalStats['unchanged'] = isset($_POST['totalUnchanged']) ? (int)$_POST['totalUnchanged'] : 0;
								$totalStats['failed'] = isset($_POST['totalFailed']) ? (int)$_POST['totalFailed'] : 0;
								$totalStats['skippedDetails'] = isset($_POST['skippedDetails']) ? json_decode($_POST['skippedDetails'], true) : [];
								
								if ($seasonsResult['success'] && !empty($seasonsResult['data'])) {
									$items = $seasonsResult['data'];
									// Process seasons for this show
									$batchResults = processBatch($items, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type);
									
									// Update running totals
									$totalStats['successful'] += $batchResults['successful'];
									$totalStats['skipped'] += $batchResults['skipped'];
									$totalStats['unchanged'] += $batchResults['unchanged']; // Added unchanged count
									$totalStats['failed'] += $batchResults['failed'];
									
									if (!empty($batchResults['errors'])) {
										$totalStats['errors'] = array_merge($totalStats['errors'], $batchResults['errors']);
									}
								} else {
									$items = []; // No seasons for this show
									$batchResults = [
										'successful' => 0,
										'skipped' => 0,
										'unchanged' => 0,
										'failed' => 0,
										'errors' => [],
										'importedIds' => []
									];
								}
								
								// Check if this is the final show we're processing
								$isComplete = ($startIndex + 1) >= count($shows);
								
								// Handle orphaned posters if this is the final batch
								$orphanedResults = null;
								if ($isComplete) {
									// Safely get imported IDs from current batch
									$allImportedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
										? $batchResults['importedIds'] 
										: [];
									
									logDebug("Seasons: Current batch imported IDs", [
										'count' => count($allImportedIds),
										'isArray' => is_array($allImportedIds)
									]);
									
									// Retrieve IDs from previous batches with proper null checks
									if (isset($_SESSION['import_season_ids']) && is_array($_SESSION['import_season_ids'])) {
										$allImportedIds = array_merge($allImportedIds, $_SESSION['import_season_ids']);
										logDebug("Seasons: Added IDs from session", [
											'session_count' => count($_SESSION['import_season_ids']),
											'total_count' => count($allImportedIds)
										]);
									}
									
									// Process orphaned posters with proper checking
									if (is_array($allImportedIds)) {
										// For all-seasons import, don't filter by show title
										$orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Orphaned**', '', '', 'seasons');
									} else {
										logDebug("Seasons: Error: allImportedIds is not an array", [
											'type' => gettype($allImportedIds),
											'value' => $allImportedIds
										]);
										$orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
									}
									
									// Clear the session
									unset($_SESSION['import_season_ids']);
									if (isset($_SESSION['current_show_title'])) {
										unset($_SESSION['current_show_title']);
									}
								} else {
									// Ensure we have an array in the session
									if (!isset($_SESSION['import_season_ids']) || !is_array($_SESSION['import_season_ids'])) {
										$_SESSION['import_season_ids'] = [];
									}
									
									// Ensure we're merging arrays, with proper null checks
									$importedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
										? $batchResults['importedIds'] 
										: [];
									
									$_SESSION['import_season_ids'] = array_merge($_SESSION['import_season_ids'], $importedIds);
									
									logDebug("Seasons: Stored IDs for next batch", [
										'batch_count' => count($importedIds),
										'session_total' => count($_SESSION['import_season_ids'])
									]);
								}
								
								// Return batch progress information for the controller
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
										'seasonCount' => count($items)
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
								// All done
								echo json_encode([
									'success' => true,
									'batchComplete' => true,
									'progress' => [
										'processed' => count($shows),
										'total' => count($shows),
										'percentage' => 100,
										'isComplete' => true,
										'nextIndex' => null
									],
									'results' => [
										'successful' => 0,
										'skipped' => 0,
										'unchanged' => 0,
										'failed' => 0,
										'errors' => []
									],
									'orphanedResults' => null,
									'totalStats' => [
										'successful' => isset($_POST['totalSuccessful']) ? (int)$_POST['totalSuccessful'] : 0,
										'skipped' => isset($_POST['totalSkipped']) ? (int)$_POST['totalSkipped'] : 0,
										'unchanged' => isset($_POST['totalUnchanged']) ? (int)$_POST['totalUnchanged'] : 0,
										'failed' => isset($_POST['totalFailed']) ? (int)$_POST['totalFailed'] : 0,
										'orphaned' => 0
									]
								]);
								exit;
							}
						} else {
                            // Non-batch processing or initial call - not recommended for large libraries
                            $allSeasons = [];
                            foreach ($shows as $show) {
                                $seasonsResult = getAllPlexSeasons($plex_config['server_url'], $plex_config['token'], $show['ratingKey']);
                                if ($seasonsResult['success'] && !empty($seasonsResult['data'])) {
                                    $allSeasons = array_merge($allSeasons, $seasonsResult['data']);
                                }
                            }
                            $items = $allSeasons;
                        }
                    } else {
                        // Just get seasons for one show (original behavior)
                        if (empty($showKey)) {
                            throw new Exception('Show key is required for single-show seasons import');
                        }
                        
						// Get the show title based on the showKey
						$showTitle = '';
						$showDetailsUrl = rtrim($plex_config['server_url'], '/') . "/library/metadata/{$showKey}";
						$headers = [];
						foreach (getPlexHeaders($plex_config['token']) as $key => $value) {
							$headers[] = $key . ': ' . $value;
						}

						try {
							$response = makeApiRequest($showDetailsUrl, $headers);
							$data = json_decode($response, true);
							
							if (isset($data['MediaContainer']['Metadata'][0]['title'])) {
								$showTitle = $data['MediaContainer']['Metadata'][0]['title'];
								// Store in session for batch processing
								$_SESSION['current_show_title'] = $showTitle;
								logDebug("Retrieved show title for season import: {$showTitle}");
							} else {
								logDebug("Could not retrieve show title for showKey: {$showKey}");
							}
						} catch (Exception $e) {
							logDebug("Error retrieving show title: " . $e->getMessage());
						}
                        
                        if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                            $startIndex = (int)$_POST['startIndex'];
                            $batchSize = $plex_config['import_batch_size'];
                            
                            // Get all seasons for this show
                            $result = getAllPlexSeasons($plex_config['server_url'], $plex_config['token'], $showKey);
                            if (!$result['success']) {
                                throw new Exception($result['error']);
                            }
                            $allSeasons = $result['data'];
                            
                            // Process this batch
                            $currentBatch = array_slice($allSeasons, $startIndex, $batchSize);
                            $endIndex = $startIndex + count($currentBatch);
                            $isComplete = $endIndex >= count($allSeasons);
                            
                            // Process the batch
                            $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption);
                            
							// Handle orphaned posters if this is the final batch
							$orphanedResults = null;
							if ($isComplete) {
								// Safely get imported IDs from current batch
								$allImportedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
									? $batchResults['importedIds'] 
									: [];
								
								logDebug("Seasons: Current batch imported IDs", [
									'count' => count($allImportedIds),
									'isArray' => is_array($allImportedIds)
								]);
								
								// Retrieve IDs from previous batches with proper null checks
								if (isset($_SESSION['import_season_ids']) && is_array($_SESSION['import_season_ids'])) {
									$allImportedIds = array_merge($allImportedIds, $_SESSION['import_season_ids']);
									logDebug("Seasons: Added IDs from session", [
										'session_count' => count($_SESSION['import_season_ids']),
										'total_count' => count($allImportedIds)
									]);
								}
								
								// Process orphaned posters with proper checking
								if (is_array($allImportedIds)) {
									// Get the show title from session if not set directly
									if (empty($showTitle) && isset($_SESSION['current_show_title'])) {
										$showTitle = $_SESSION['current_show_title'];
									}
									
									// Now call with the show title and media type parameters
									$orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Orphaned**', '', $showTitle, 'seasons');
								} else {
									logDebug("Seasons: Error: allImportedIds is not an array", [
										'type' => gettype($allImportedIds),
										'value' => $allImportedIds
									]);
									$orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
								}
								
								// Clear the session
							// Also clean up the show title after processing is complete
							if (isset($_SESSION['current_show_title'])) {
								unset($_SESSION['current_show_title']);
							}
							} else {
								// Ensure we have an array in the session
								if (!isset($_SESSION['import_season_ids']) || !is_array($_SESSION['import_season_ids'])) {
									$_SESSION['import_season_ids'] = [];
								}
								
								// Ensure we're merging arrays, with proper null checks
								$importedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
									? $batchResults['importedIds'] 
									: [];
								
								$_SESSION['import_season_ids'] = array_merge($_SESSION['import_season_ids'], $importedIds);
								
								logDebug("Seasons: Stored IDs for next batch", [
									'batch_count' => count($importedIds),
									'session_total' => count($_SESSION['import_season_ids'])
								]);
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
                            // Get all seasons for this show
                            $result = getAllPlexSeasons($plex_config['server_url'], $plex_config['token'], $showKey);
                            if (!$result['success']) {
                                throw new Exception($result['error']);
                            }
                            $items = $result['data'];
                        }
                    }
                    break;
                
					case 'collections':
						// Handle batch processing
						if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
							$startIndex = (int)$_POST['startIndex'];
							$batchSize = $plex_config['import_batch_size'];
							
							try {
								// Get all collections using pagination
								$result = getAllPlexCollections($plex_config['server_url'], $plex_config['token'], $libraryId);
								if (!$result['success']) {
									throw new Exception($result['error']);
								}
								
								// Make sure we have an array of collections, never null
								$allCollections = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
								
								// Get the library type (movie or show) to correctly label collections
								$libraryType = '';
								// First check if we already stored the library type in the session
								if (isset($_SESSION['current_library_type'])) {
									$libraryType = $_SESSION['current_library_type'];
								} else {
									// Otherwise, fetch the library details to determine its type
									$librariesResult = getPlexLibraries($plex_config['server_url'], $plex_config['token']);
									if ($librariesResult['success'] && !empty($librariesResult['data'])) {
										foreach ($librariesResult['data'] as $lib) {
										    if ($lib['id'] == $libraryId) {
										        $libraryType = $lib['type']; // 'movie' or 'show'
										        // Store in session for subsequent batch calls
										        $_SESSION['current_library_type'] = $libraryType;
										        break;
										    }
										}
									}
								}
								
								logDebug("Collections batch processing", [
									'collections_count' => count($allCollections),
									'startIndex' => $startIndex,
									'batchSize' => $batchSize,
									'libraryType' => $libraryType
								]);
								
								// Process this batch - make sure we don't go out of bounds
								$currentBatch = [];
								if ($startIndex < count($allCollections)) {
									$currentBatch = array_slice($allCollections, $startIndex, $batchSize);
								}
								
								$endIndex = $startIndex + count($currentBatch);
								$isComplete = $endIndex >= count($allCollections);
								
								// Process the batch with library type information
								$batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], 
										                  $targetDir, $overwriteOption, $type, $libraryType);
								
								// Ensure batchResults is properly structured
								if (!isset($batchResults['importedIds']) || !is_array($batchResults['importedIds'])) {
									$batchResults['importedIds'] = [];
								}
								
								// Create a collection-specific key for session storage that includes library type
								$collectionSessionKey = 'import_collection_ids_' . $libraryType;
								
								// Handle orphaned posters if this is the final batch
								$orphanedResults = null;
								if ($isComplete) {
									// Safely get imported IDs from current batch
									$allImportedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
										? $batchResults['importedIds'] 
										: [];
									
									logDebug("Collections: Current batch imported IDs", [
										'count' => count($allImportedIds),
										'isArray' => is_array($allImportedIds),
										'type' => $type,
										'libraryType' => $libraryType
									]);
									
									// Retrieve IDs from previous batches with proper null checks - use type-specific key
									if (isset($_SESSION[$collectionSessionKey]) && is_array($_SESSION[$collectionSessionKey])) {
										$allImportedIds = array_merge($allImportedIds, $_SESSION[$collectionSessionKey]);
										logDebug("Collections: Added IDs from session", [
										    'session_count' => count($_SESSION[$collectionSessionKey]),
										    'total_count' => count($allImportedIds),
										    'library_type' => $libraryType
										]);
									}
									
									// Store the imported IDs by type in the session for future reference
									if (!isset($_SESSION['all_imported_collections']) || !is_array($_SESSION['all_imported_collections'])) {
										$_SESSION['all_imported_collections'] = [];
									}
									
									// Store the current collection type's IDs
									$_SESSION['all_imported_collections'][$libraryType] = $allImportedIds;
									
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
										    
										    // Process orphaned posters with proper library type
										if (is_array($allImportedIds)) {
											if (is_array($allImportedIds)) {
												$orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Orphaned**', $libraryType, '', 'collections');
											} else {
												logDebug("Collections: Error: allImportedIds is not an array", [
													'type' => gettype($allImportedIds),
													'value' => $allImportedIds
												]);
												$orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
											}
										} else {
											logDebug("Collections: Error: allImportedIds is not an array", [
												'type' => gettype($allImportedIds),
												'value' => $allImportedIds
											]);
											$orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
										}
										}
									} else {
										// Process orphaned posters with proper library type
									if (is_array($allImportedIds)) {
										if (is_array($allImportedIds)) {
											$orphanedResults = markOrphanedPosters($targetDir, $allImportedIds, '**Orphaned**', $libraryType, '', 'collections');
										} else {
											logDebug("Collections: Error: allImportedIds is not an array", [
												'type' => gettype($allImportedIds),
												'value' => $allImportedIds
											]);
											$orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
										}
									} else {
										logDebug("Collections: Error: allImportedIds is not an array", [
											'type' => gettype($allImportedIds),
											'value' => $allImportedIds
										]);
										$orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
									}
									}
									
									// Clear only the temporary session for the current import
									unset($_SESSION[$collectionSessionKey]);
									// Also clean up the library type after processing is complete
									if (isset($_SESSION['current_library_type'])) {
										unset($_SESSION['current_library_type']);
									}
								} else {
									// Ensure we have an array in the session for this library type
									if (!isset($_SESSION[$collectionSessionKey]) || !is_array($_SESSION[$collectionSessionKey])) {
										$_SESSION[$collectionSessionKey] = [];
									}
									
									// Ensure we're merging arrays, with proper null checks
									$importedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds']) 
										? $batchResults['importedIds'] 
										: [];
									
									$_SESSION[$collectionSessionKey] = array_merge($_SESSION[$collectionSessionKey], $importedIds);
									
									logDebug("Collections: Stored IDs for next batch", [
										'batch_count' => count($importedIds),
										'session_total' => count($_SESSION[$collectionSessionKey]),
										'type' => $type,
										'libraryType' => $libraryType
									]);
								}
								
								// Make sure these values are never null for the JSON response
								if (!isset($orphanedResults) || !is_array($orphanedResults)) {
									$orphanedResults = ['orphaned' => 0, 'unmarked' => 0, 'details' => []];
								}
								
								// Make sure the progress values are valid
								$totalCollections = count($allCollections);
								$processedCount = $endIndex;
								$percentage = $totalCollections > 0 ? round(($processedCount / $totalCollections) * 100) : 100;
								
								// Respond with batch results and progress - with extensive null checks
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
							$result = getAllPlexCollections($plex_config['server_url'], $plex_config['token'], $libraryId);
							if (!$result['success']) {
								throw new Exception($result['error']);
							}
							
							// Get the library type (movie or show) for collection labeling
							$libraryType = '';
							$librariesResult = getPlexLibraries($plex_config['server_url'], $plex_config['token']);
							if ($librariesResult['success'] && !empty($librariesResult['data'])) {
								foreach ($librariesResult['data'] as $lib) {
									if ($lib['id'] == $libraryId) {
										$libraryType = $lib['type']; // 'movie' or 'show'
										break;
									}
								}
							}
							
							$items = $result['data'];
							
							// If we're not batch processing, we need to store the library type for processBatch
							$_SESSION['current_library_type'] = $libraryType;
						}
						break;
                
                default:
                    throw new Exception('Invalid import type');
            }
            
            // This code will only execute for non-batch processing, which is not recommended for large libraries
            // Process all items
            $results = processBatch($items, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type);
            
            echo json_encode([
                'success' => true,
                'complete' => true,
                'processed' => count($items),
                'results' => $results,
                'totalStats' => [
                    'successful' => $results['successful'],
                    'skipped' => $results['skipped'],
                    'unchanged' => $results['unchanged'], // Added unchanged count
                    'failed' => $results['failed']
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
