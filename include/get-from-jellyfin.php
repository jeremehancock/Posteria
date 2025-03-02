<?php
# Posteria: A Media Poster Collection App
# Import From Jellyfin Functionality (Single Poster)
#
# Developed by Jereme Hancock
# https://github.com/jeremehancock/Posteria
#
# MIT License
#
# Copyright (c) 2024 Jereme Hancock

// Set headers
header('Content-Type: application/json');

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

// Define helper functions
function getEnvWithFallback($key, $default) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

function getIntEnvWithFallback($key, $default) {
    $value = getenv($key);
    return $value !== false ? intval($value) : $default;
}

// Log function for debugging - log to a separate file for Jellyfin
function logDebug($message, $data = null) {
    $logMessage = date('Y-m-d H:i:s') . ": " . $message;
    if ($data !== null) {
        $logMessage .= "\nData: " . print_r($data, true);
    }
    file_put_contents('import-from-jellyfin-debug.log', $logMessage . "\n\n", FILE_APPEND);
}

try {
    // Start session
    if (!session_id()) {
        session_start();
    }

    // Log the request
    logDebug("Import from Jellyfin request received", [
        'POST' => $_POST,
        'SESSION' => $_SESSION
    ]);

    // Include configuration
    try {
        if (file_exists('./config.php')) {
            require_once './config.php';
            logDebug("Config file loaded successfully from ./config.php");
        } else if (file_exists('./include/config.php')) {
            require_once './include/config.php';
            logDebug("Config file loaded successfully from ./include/config.php");
        } else {
            throw new Exception("Config file not found in any of the expected locations");
        }
    } catch (Exception $e) {
        logDebug("Config file error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Config file error: ' . $e->getMessage()]);
        exit;
    }

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
    
    // Check if Jellyfin server URL is set
    if (empty($jellyfin_config['server_url'])) {
        logDebug("Jellyfin server URL is not set");
        echo json_encode(['success' => false, 'error' => 'Jellyfin server URL is not configured. Please add the server URL to config.php']);
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

    // Helper function to prepare API headers for Jellyfin
    function getJellyfinHeaders($apiKey) {
        // Try both authentication methods as Jellyfin sometimes requires different formats
        return [
            'Accept' => 'application/json',
            'X-Emby-Token' => $apiKey,
            'X-MediaBrowser-Token' => $apiKey,
            'Authorization' => 'MediaBrowser Token="' . $apiKey . '"'
        ];
    }

    // Generic function to make API requests
    function makeApiRequest($url, $headers, $expectJson = true) {
        global $jellyfin_config;
        
        // Convert headers array to proper format for curl
        $headerArray = [];
        foreach ($headers as $key => $value) {
            $headerArray[] = $key . ': ' . $value;
        }
        
        logDebug("Making API request", [
            'url' => $url,
            'headers' => $headerArray,
            'expectJson' => $expectJson
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headerArray,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => $jellyfin_config['connect_timeout'] ?? 10,
            CURLOPT_TIMEOUT => $jellyfin_config['request_timeout'] ?? 30,
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
        if ($expectJson && !empty($response)) {
            // Try to parse JSON to verify it's valid
            $jsonTest = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                logDebug("Invalid JSON response: " . json_last_error_msg());
                throw new Exception("Invalid JSON response: " . json_last_error_msg());
            }
        }
        
        return $response;
    }

    // Attempt to directly download image using the server URL and item ID
    function directImageDownload($serverUrl, $apiKey, $itemId) {
        // Try various image formats and paths
        $possiblePaths = [
            "/Items/{$itemId}/Images/Primary",
            "/Items/{$itemId}/Images/Primary?format=jpg&quality=90",
            "/Items/{$itemId}/Images/Primary?api_key={$apiKey}",
            "/emby/Items/{$itemId}/Images/Primary",
            "/emby/Items/{$itemId}/Images/Primary?api_key={$apiKey}"
        ];
        
        $headers = getJellyfinHeaders($apiKey);
        
        // Try each path until one works
        foreach ($possiblePaths as $path) {
            $url = rtrim($serverUrl, '/') . $path;
            
            try {
                logDebug("Trying direct image download", ['url' => $url]);
                
                // Set up curl for image download
                $ch = curl_init();
                
                // Convert headers to array format
                $headerArray = [];
                foreach ($headers as $key => $value) {
                    $headerArray[] = $key . ': ' . $value;
                }
                
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER => $headerArray,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                $error = curl_error($ch);
                curl_close($ch);
                
                logDebug("Direct image download response", [
                    'url' => $url,
                    'http_code' => $httpCode,
                    'content_type' => $contentType,
                    'content_length' => strlen($response),
                    'error' => $error
                ]);
                
                // Check if we got an image response
                if ($httpCode == 200 && $response !== false && 
                    (str_starts_with($contentType, 'image/') || strlen($response) > 1000)) {
                    return ['success' => true, 'data' => $response];
                }
            } catch (Exception $e) {
                logDebug("Error in direct image download: " . $e->getMessage());
                // Continue to the next path
                continue;
            }
        }
        
        // If we've tried all paths and none worked
        return ['success' => false, 'error' => 'Failed to download image from any known Jellyfin path'];
    }

    // Function to get item metadata from Jellyfin
    function getJellyfinItemMetadata($serverUrl, $apiKey, $itemId) {
        // Try different metadata paths
        $possiblePaths = [
            "/Items/{$itemId}",
            "/emby/Items/{$itemId}",
            "/Items/{$itemId}?api_key={$apiKey}",
            "/emby/Items/{$itemId}?api_key={$apiKey}"
        ];
        
        $headers = getJellyfinHeaders($apiKey);
        
        // Try each path until one works
        foreach ($possiblePaths as $path) {
            $url = rtrim($serverUrl, '/') . $path;
            
            try {
                $response = makeApiRequest($url, $headers);
                $metadata = json_decode($response, true);
                
                // Check if we got valid metadata
                if (isset($metadata['Name'])) {
                    return ['success' => true, 'data' => $metadata];
                }
            } catch (Exception $e) {
                logDebug("Error fetching metadata: " . $e->getMessage());
                // Continue to the next path
                continue;
            }
        }
        
        // If we've tried all paths and none worked
        return ['success' => false, 'error' => 'Failed to retrieve item metadata from Jellyfin'];
    }

    // Single poster import from Jellyfin
    if (isset($_POST['action']) && $_POST['action'] === 'import_from_jellyfin') {
        if (!isset($_POST['filename']) || !isset($_POST['directory'])) {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            exit;
        }
        
        $filename = $_POST['filename'];
        $directory = $_POST['directory'];
        
        logDebug("Processing import request", [
            'filename' => $filename, 
            'directory' => $directory
        ]);
        
        // Extract the itemId from the filename
        // Format is typically "Title [itemId] Jellyfin.jpg"
        $matches = [];
        if (!preg_match('/\[([a-zA-Z0-9]+)\]/', $filename, $matches)) {
            echo json_encode(['success' => false, 'error' => 'Could not extract itemId from filename']);
            exit;
        }
        
        $itemId = $matches[1];
        logDebug("Extracted itemId for import", ['itemId' => $itemId]);
        
        // Define directories based on your existing code
        $directories = [
            'movies' => '../posters/movies/',
            'tv-shows' => '../posters/tv-shows/',
            'tv-seasons' => '../posters/tv-seasons/',
            'collections' => '../posters/collections/'
        ];
        
        // Make sure we have a valid directory
        if (!isset($directories[$directory])) {
            echo json_encode(['success' => false, 'error' => 'Invalid directory type']);
            exit;
        }
        
        try {
            // First try direct image download - simplest approach
            $imageResult = directImageDownload(
                $jellyfin_config['server_url'], 
                $jellyfin_config['api_key'], 
                $itemId
            );
            
            // If direct image download failed, try getting metadata first
            if (!$imageResult['success']) {
                logDebug("Direct image download failed, trying metadata approach");
                
                $metadataResult = getJellyfinItemMetadata(
                    $jellyfin_config['server_url'], 
                    $jellyfin_config['api_key'], 
                    $itemId
                );
                
                if (!$metadataResult['success']) {
                    echo json_encode(['success' => false, 'error' => 'Failed to retrieve item information from Jellyfin']);
                    exit;
                }
                
                // Try again with image endpoint now that we've confirmed the item exists
                $imageResult = directImageDownload(
                    $jellyfin_config['server_url'], 
                    $jellyfin_config['api_key'], 
                    $itemId
                );
                
                if (!$imageResult['success']) {
                    echo json_encode(['success' => false, 'error' => 'Failed to download poster from Jellyfin even after confirming item exists']);
                    exit;
                }
            }
            
            // Generate the target path (use the existing filename)
            $targetPath = $directories[$directory] . $filename;
            
            logDebug("Saving image to path", ['targetPath' => $targetPath]);
            
            // Save the image file
            if (file_put_contents($targetPath, $imageResult['data'])) {
                chmod($targetPath, 0644);
                echo json_encode(['success' => true, 'message' => 'Poster successfully imported from Jellyfin']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save poster file']);
            }
            
        } catch (Exception $e) {
            logDebug("Error during import", ['error' => $e->getMessage()]);
            echo json_encode(['success' => false, 'error' => 'Error importing from Jellyfin: ' . $e->getMessage()]);
        }
        
        exit;
    }

    // Default response if no action matched
    echo json_encode(['success' => false, 'error' => 'Invalid action requested']);

} catch (Exception $e) {
    // Log the error
    logDebug("Unhandled exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
