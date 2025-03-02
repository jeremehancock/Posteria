<?php
# Posteria: A Media Poster Collection App
# Export to Plex Functionality
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

// Log function for debugging
function logDebug($message, $data = null) {
    $logMessage = date('Y-m-d H:i:s') . ": " . $message;
    if ($data !== null) {
        $logMessage .= "\nData: " . print_r($data, true);
    }
    file_put_contents('plex-export-debug.log', $logMessage . "\n\n", FILE_APPEND);
}

try {
    // Start session
    if (!session_id()) {
        session_start();
    }

    // Log the request
    logDebug("Export to Plex request received", [
        'POST' => $_POST,
        'SESSION' => $_SESSION
    ]);

    // Include configuration
    try {
        if (file_exists('./config.php')) {
            require_once './config.php';
            logDebug("Config file loaded successfully from ./config.php");
        } else if (file_exists('config.php')) {
            require_once 'config.php';
            logDebug("Config file loaded successfully from config.php");
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

    // Helper functions (reused from plex-import.php)
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

    function makeApiRequest($url, $headers, $method = 'GET', $data = null, $expectJson = true) {
        global $plex_config;
        
        logDebug("Making API request", [
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'expectJson' => $expectJson
        ]);
        
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => $plex_config['connect_timeout'],
            CURLOPT_TIMEOUT => $plex_config['request_timeout'],
            CURLOPT_VERBOSE => true
        ];
        
        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            if ($data !== null) {
                $curlOptions[CURLOPT_POSTFIELDS] = $data;
            }
        } else if ($method === 'PUT') {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = 'PUT';
            if ($data !== null) {
                $curlOptions[CURLOPT_POSTFIELDS] = $data;
            }
        } else if ($method === 'DELETE') {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }
        
        curl_setopt_array($ch, $curlOptions);
        
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

    // Extract Plex ID from filename
    function extractPlexId($filename) {
        $matches = [];
        if (preg_match('/\[([^\]]+)\]/', $filename, $matches)) {
            return $matches[1];
        }
        return null;
    }

    // Check if filename contains "Plex"
    function isPlexFile($filename) {
        return stripos($filename, 'plex') !== false;
    }

    // Send image to Plex
    function sendImageToPlex($plexServerUrl, $token, $imageData, $ratingKey, $mediaType) {
        try {
            logDebug("Sending image to Plex", [
                'ratingKey' => $ratingKey,
                'mediaType' => $mediaType
            ]);
            
            if ($mediaType === 'collections' || $mediaType === 'collection') {
                // For collections we need a more comprehensive approach
                $boundary = md5(uniqid());
                
                // Let's try multiple methods and endpoints
                $methods = [
                    // Method 1: Upload to posters endpoint with POST (most common method)
                    [
                        'url' => "{$plexServerUrl}/library/collections/{$ratingKey}/posters",
                        'method' => 'POST',
                        'content_type' => 'image/jpeg'
                    ],
                    // Method 2: Upload directly to poster endpoint with PUT
                    [
                        'url' => "{$plexServerUrl}/library/collections/{$ratingKey}/poster?X-Plex-Token={$token}",
                        'method' => 'PUT',
                        'content_type' => 'image/jpeg'
                    ],
                    // Method 3: Upload to arts endpoint with POST
                    [
                        'url' => "{$plexServerUrl}/library/collections/{$ratingKey}/arts",
                        'method' => 'POST',
                        'content_type' => 'image/jpeg'
                    ],
                    // Method 4: Try with multipart form data
                    [
                        'url' => "{$plexServerUrl}/library/collections/{$ratingKey}/posters",
                        'method' => 'POST',
                        'content_type' => "multipart/form-data; boundary={$boundary}",
                        'multipart' => true
                    ]
                ];
                
                $success = false;
                $lastHttpCode = 0;
                $lastError = '';
                
                // Try each method until one succeeds
                foreach ($methods as $index => $method) {
                    logDebug("Trying collection poster upload method " . ($index + 1), $method);
                    
                    // Create headers for the Plex API request for collections
                    $headers = [];
                    foreach (getPlexHeaders($token) as $key => $value) {
                        $headers[] = $key . ': ' . $value;
                    }
                    
                    // Add content type header
                    $headers[] = 'Content-Type: ' . $method['content_type'];
                    
                    // Prepare data based on whether we're using multipart or not
                    $postData = $imageData;
                    if (isset($method['multipart']) && $method['multipart']) {
                        // Prepare multipart form data
                        $postData = "--{$boundary}\r\n";
                        $postData .= "Content-Disposition: form-data; name=\"file\"; filename=\"poster.jpg\"\r\n";
                        $postData .= "Content-Type: image/jpeg\r\n\r\n";
                        $postData .= $imageData . "\r\n";
                        $postData .= "--{$boundary}--\r\n";
                    }
                    
                    try {
                        // Now make the API request
                        $response = makeApiRequest($method['url'], $headers, $method['method'], $postData, false);
                        $success = true;
                        logDebug("Method " . ($index + 1) . " succeeded");
                        break;
                    } catch (Exception $e) {
                        // Remember the last error
                        $lastError = $e->getMessage();
                        logDebug("Method " . ($index + 1) . " failed: " . $lastError);
                    }
                }
                
                // If successful, try to trigger a refresh
                if ($success) {
                    // Wait briefly for Plex to process the upload
                    sleep(1);
                    
                    // Attempt to refresh the collection to apply the change
                    $refreshUrl = "{$plexServerUrl}/library/metadata/{$ratingKey}/refresh";
                    $refreshHeaders = [];
                    foreach (getPlexHeaders($token) as $key => $value) {
                        $refreshHeaders[] = $key . ': ' . $value;
                    }
                    
                    try {
                        makeApiRequest($refreshUrl, $refreshHeaders, 'PUT', null, false);
                        logDebug("Collection refresh succeeded");
                    } catch (Exception $e) {
                        logDebug("Collection refresh failed: " . $e->getMessage());
                    }
                    
                    return ['success' => true];
                } else {
                    throw new Exception("All collection poster upload methods failed. Last error: {$lastError}");
                }
            } else {
                // For movies, shows, and seasons use standard method
                $uploadUrl = "{$plexServerUrl}/library/metadata/{$ratingKey}/posters";
                
                // Create headers for the Plex API request
                $headers = [];
                foreach (getPlexHeaders($token) as $key => $value) {
                    $headers[] = $key . ': ' . $value;
                }
                
                // Add content type for image upload
                $headers[] = 'Content-Type: image/jpeg';
                
                // Make the API request
                makeApiRequest($uploadUrl, $headers, 'POST', $imageData, false);
                
                // Try to refresh metadata
                try {
                    $refreshUrl = "{$plexServerUrl}/library/metadata/{$ratingKey}/refresh";
                    makeApiRequest($refreshUrl, $headers, 'PUT', null, false);
                    logDebug("Metadata refresh succeeded");
                } catch (Exception $e) {
                    logDebug("Metadata refresh failed: " . $e->getMessage());
                }
                
                return ['success' => true];
            }
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get all Plex posters from directory
    function getPlexPosters($directory) {
        $posters = [];
        
        if (is_dir($directory)) {
            $files = scandir($directory);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                
                // Check if it's a Plex poster (has "Plex" in the name)
                if (isPlexFile($file)) {
                    $posters[] = [
                        'filename' => $file,
                        'path' => $directory . '/' . $file
                    ];
                }
            }
        }
        
        return $posters;
    }

    // Process a batch of posters
    function processBatch($posters, $plexServerUrl, $token, $mediaType) {
        $results = [
            'successful' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($posters as $poster) {
            $filename = $poster['filename'];
            $path = $poster['path'];
            
            // Extract Plex ID from filename
            $plexId = extractPlexId($filename);
            if (!$plexId) {
                $results['skipped']++;
                logDebug("Skipped poster - no Plex ID found", ['filename' => $filename]);
                continue;
            }
            
            // Read the image file
            $imageData = file_get_contents($path);
            if ($imageData === false) {
                $results['failed']++;
                $results['errors'][] = "Failed to read image file: {$filename}";
                logDebug("Failed to read image file", ['filename' => $filename]);
                continue;
            }
            
            // Send image to Plex
            $result = sendImageToPlex($plexServerUrl, $token, $imageData, $plexId, $mediaType);
            
            if ($result['success']) {
                $results['successful']++;
                logDebug("Successfully sent poster to Plex", [
                    'filename' => $filename,
                    'plexId' => $plexId
                ]);
            } else {
                $results['failed']++;
                $results['errors'][] = "Failed to send {$filename} to Plex: {$result['error']}";
                logDebug("Failed to send poster to Plex", [
                    'filename' => $filename,
                    'plexId' => $plexId,
                    'error' => $result['error']
                ]);
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

    // Export Plex Posters
    if (isset($_POST['action']) && $_POST['action'] === 'export_plex_posters') {
        if (!isset($_POST['type'])) {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            exit;
        }
        
        $type = $_POST['type']; // 'movies', 'shows', 'seasons', 'collections'
        
        // Map type to directory and media type
        $typeMap = [
            'movies' => [
                'directory' => '../posters/movies',
                'mediaType' => 'movie'
            ],
            'shows' => [
                'directory' => '../posters/tv-shows',
                'mediaType' => 'show'
            ],
            'seasons' => [
                'directory' => '../posters/tv-seasons',
                'mediaType' => 'season'
            ],
            'collections' => [
                'directory' => '../posters/collections',
                'mediaType' => 'collections'
            ]
        ];
        
        if (!isset($typeMap[$type])) {
            echo json_encode(['success' => false, 'error' => 'Invalid poster type']);
            exit;
        }
        
        $directory = $typeMap[$type]['directory'];
        $mediaType = $typeMap[$type]['mediaType'];
        
        // Get all Plex posters from the directory
        $allPosters = getPlexPosters($directory);
        logDebug("Found Plex posters", [
            'type' => $type,
            'directory' => $directory,
            'count' => count($allPosters)
        ]);
        
        // Handle batch processing
        if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
            $startIndex = (int)$_POST['startIndex'];
            $batchSize = 25; // We'll process 25 posters at a time to avoid timeout issues
            
            // Process this batch
            $currentBatch = array_slice($allPosters, $startIndex, $batchSize);
            $endIndex = $startIndex + count($currentBatch);
            $isComplete = $endIndex >= count($allPosters);
            
            if (empty($currentBatch)) {
                // No posters to process
                echo json_encode([
                    'success' => true,
                    'batchComplete' => true,
                    'progress' => [
                        'processed' => count($allPosters),
                        'total' => count($allPosters),
                        'percentage' => 100,
                        'isComplete' => true,
                        'nextIndex' => null
                    ],
                    'results' => [
                        'successful' => 0,
                        'skipped' => 0,
                        'failed' => 0,
                        'errors' => []
                    ]
                ]);
                exit;
            }
            
            // Process the batch
            $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $mediaType);
            
            // Calculate progress percentage
            $processed = min($endIndex, count($allPosters));
            $percentage = count($allPosters) > 0 ? round(($processed / count($allPosters)) * 100) : 100;
            
            // Respond with batch results and progress
            echo json_encode([
                'success' => true,
                'batchComplete' => true,
                'progress' => [
                    'processed' => $processed,
                    'total' => count($allPosters),
                    'percentage' => $percentage,
                    'isComplete' => $isComplete,
                    'nextIndex' => $isComplete ? null : $endIndex
                ],
                'results' => $batchResults
            ]);
            exit;
        } else {
            // Process all posters at once (not recommended for large libraries)
            $results = processBatch($allPosters, $plex_config['server_url'], $plex_config['token'], $mediaType);
            
            echo json_encode([
                'success' => true,
                'complete' => true,
                'processed' => count($allPosters),
                'results' => $results
            ]);
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
