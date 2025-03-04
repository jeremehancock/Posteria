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

// Log function for debugging
function logDebug($message, $data = null) {
    $logMessage = date('Y-m-d H:i:s') . ": " . $message;
    if ($data !== null) {
        $logMessage .= "\nData: " . print_r($data, true);
    }
    file_put_contents('import-from-plex-debug.log', $logMessage . "\n\n", FILE_APPEND);
}

try {
    // Start session
    if (!session_id()) {
        session_start();
    }

    // Log the request
    logDebug("Import from Plex request received", [
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

    // Helper Functions (reused from plex-import.php)
    function getPlexHeaders($token) {
        return [
            'Accept' => 'application/json',
            'X-Plex-Token' => $token,
            'X-Plex-Client-Identifier' => 'Posteria',
            'X-Plex-Product' => 'Posteria',
            'X-Plex-Version' => '1.0'
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

    // Get image data from Plex
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

    // Single poster import from Plex
    if (isset($_POST['action']) && $_POST['action'] === 'import_from_plex') {
        if (!isset($_POST['filename'])) {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            exit;
        }
        
        $filename = $_POST['filename'];
        
        // Extract the ratingKey from the filename
        // Format is typically "Title [ratingKey] Plex.jpg"
        $matches = [];
        if (!preg_match('/\[([a-zA-Z0-9]+)\]/', $filename, $matches)) {
            echo json_encode(['success' => false, 'error' => 'Could not extract ratingKey from filename']);
            exit;
        }
        
        $ratingKey = $matches[1];
        logDebug("Extracted ratingKey for import", ['ratingKey' => $ratingKey, 'filename' => $filename]);
        
        // Determine the media type based on filename or directory
        $mediaType = isset($_POST['directory']) ? $_POST['directory'] : '';
        
        // Determine the endpoint based on the media type
        $endpoint = '';
        
        // URL to get metadata from Plex
        $plexServerUrl = rtrim($plex_config['server_url'], '/');
        $metadataUrl = "{$plexServerUrl}/library/metadata/{$ratingKey}";
        
        // Headers for the Plex API request
        $headers = [];
        foreach (getPlexHeaders($plex_config['token']) as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }
        
        try {
            // Get the item metadata to find the thumb URL
            $response = makeApiRequest($metadataUrl, $headers);
            $metadata = json_decode($response, true);
            
            if (!isset($metadata['MediaContainer']['Metadata'][0]['thumb'])) {
                // For collections, try with collections endpoint
                if ($mediaType === 'collections') {
                    $collectionsUrl = "{$plexServerUrl}/library/collections/{$ratingKey}";
                    $response = makeApiRequest($collectionsUrl, $headers);
                    $metadata = json_decode($response, true);
                }
                
                // If still no thumb, try with art instead of thumb
                if (!isset($metadata['MediaContainer']['Metadata'][0]['thumb'])) {
                    if (isset($metadata['MediaContainer']['Metadata'][0]['art'])) {
                        $thumb = $metadata['MediaContainer']['Metadata'][0]['art'];
                    } else {
                        echo json_encode(['success' => false, 'error' => 'No poster found for this item in Plex']);
                        exit;
                    }
                } else {
                    $thumb = $metadata['MediaContainer']['Metadata'][0]['thumb'];
                }
            } else {
                $thumb = $metadata['MediaContainer']['Metadata'][0]['thumb'];
            }
            
            // Get the poster image data
            $imageResult = getPlexImageData($plexServerUrl, $plex_config['token'], $thumb);
            
            if (!$imageResult['success']) {
                echo json_encode(['success' => false, 'error' => 'Failed to download poster from Plex: ' . $imageResult['error']]);
                exit;
            }
            
            // Define directories based on your existing code
            $directories = [
                'movies' => '../posters/movies/',
                'tv-shows' => '../posters/tv-shows/',
                'tv-seasons' => '../posters/tv-seasons/',
                'collections' => '../posters/collections/'
            ];
            
            // Make sure we have a valid directory
            if (!isset($directories[$mediaType])) {
                echo json_encode(['success' => false, 'error' => 'Invalid directory type']);
                exit;
            }
            
            // Generate the target path (use the existing filename)
            $targetPath = $directories[$mediaType] . $filename;
            
            // Save the image file
            if (file_put_contents($targetPath, $imageResult['data'])) {
                chmod($targetPath, 0644);
                echo json_encode(['success' => true, 'message' => 'Poster successfully imported from Plex']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save poster file']);
            }
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error importing from Plex: ' . $e->getMessage()]);
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
