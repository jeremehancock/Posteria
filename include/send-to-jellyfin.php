<?php
# Posteria: A Media Poster Collection App
# Jellyfin Web-Style Upload - Mimics the web interface
#
# Developed by Jereme Hancock
# https://github.com/jeremehancock/Posteria
#
# MIT License
#
# Copyright (c) 2024 Jereme Hancock

// Set headers
header('Content-Type: application/json');

// Enable error display for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Debug logging with more details
function logDebug($message, $data = null) {
    $logDir = './logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/jellyfin-debug-' . date('Y-m-d') . '.log';
    
    $logMessage = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        $logMessage .= "\nData: " . print_r($data, true);
    }
    file_put_contents($logFile, $logMessage . "\n\n", FILE_APPEND);
}

// Load configuration
function loadConfig() {
    global $auth_config, $jellyfin_config;
    
    if (file_exists('./config.php')) {
        require_once './config.php';
        logDebug("Config loaded from ./config.php");
    } elseif (file_exists('./include/config.php')) {
        require_once './include/config.php';
        logDebug("Config loaded from ./include/config.php");
    } else {
        logDebug("Config file not found");
        throw new Exception("Config file not found");
    }
    
    if (!isset($auth_config) || !isset($jellyfin_config)) {
        logDebug("Missing configuration variables");
        throw new Exception("Missing configuration variables");
    }
    
    if (empty($jellyfin_config['api_key'])) {
        logDebug("Jellyfin API key is not configured");
        throw new Exception("Jellyfin API key is not configured");
    }
    
    // Log the configuration for debugging (mask sensitive parts)
    logDebug("Jellyfin configuration", [
        'server_url' => $jellyfin_config['server_url'],
        'api_key' => substr($jellyfin_config['api_key'], 0, 3) . '...' . substr($jellyfin_config['api_key'], -3)
    ]);
    
    return $jellyfin_config;
}

// Function to send debugging info in response
function respondWithDebug($success, $message, $debugInfo = []) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if (!empty($debugInfo)) {
        $response['debug'] = $debugInfo;
    }
    
    echo json_encode($response);
    exit;
}

// Main execution
try {
    // Start session
    session_start();
    
    // Check authentication
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        logDebug("Authentication required");
        respondWithDebug(false, "Authentication required");
    }
    
    // Refresh session
    $_SESSION['login_time'] = time();
    
    // Log request
    logDebug("Request received", [
        'POST' => $_POST,
        'FILES' => isset($_FILES) ? count($_FILES) . ' files' : 'No files'
    ]);
    
    // Load configuration
    $jellyfin_config = loadConfig();
    
    // Handle the send to Jellyfin request
    if (isset($_POST['action']) && $_POST['action'] === 'send_to_jellyfin') {
        if (!isset($_POST['filename'], $_POST['directory'])) {
            logDebug("Missing required parameters");
            respondWithDebug(false, "Missing required parameters");
        }
        
        $filename = $_POST['filename'];
        $directory = $_POST['directory'];
        
        // Define directories
        $directories = [
            'movies' => '../posters/movies/',
            'tv-shows' => '../posters/tv-shows/',
            'tv-seasons' => '../posters/tv-seasons/',
            'collections' => '../posters/collections/'
        ];
        
        // Check directory
        if (!isset($directories[$directory])) {
            logDebug("Invalid directory", ['directory' => $directory]);
            respondWithDebug(false, "Invalid directory");
        }
        
        $filePath = $directories[$directory] . $filename;
        
        // Check file exists
        if (!file_exists($filePath)) {
            logDebug("File not found", ['path' => $filePath]);
            respondWithDebug(false, "File not found: $filePath");
        }
        
        // Extract itemId
        if (!preg_match('/\[([^\]]+)\]/', $filename, $matches)) {
            logDebug("Could not extract itemId from filename", ['filename' => $filename]);
            respondWithDebug(false, "Could not extract itemId from filename");
        }
        
        $itemId = trim($matches[1]);
        logDebug("Processing item", ['id' => $itemId, 'file' => $filePath, 'size' => filesize($filePath)]);
        
        // Read the image file
        $imageFile = $filePath;
        $imageType = mime_content_type($imageFile);
        
        // ----------------------------------------------------
        // APPROACH: Web Upload - Mimicking Jellyfin Web Interface
        // ----------------------------------------------------
        
        // Base Jellyfin server URL
        $jellyfinUrl = rtrim($jellyfin_config['server_url'], '/');
        $apiKey = $jellyfin_config['api_key'];
        
        // Create a temporary file with the right extension
        $extension = pathinfo($imageFile, PATHINFO_EXTENSION);
        $tempFile = tempnam(sys_get_temp_dir(), 'jellyfin_') . '.' . $extension;
        copy($imageFile, $tempFile);
        
        $tempFileInfo = [
            'path' => $tempFile,
            'size' => filesize($tempFile),
            'type' => mime_content_type($tempFile)
        ];
        logDebug("Created temporary file", $tempFileInfo);
        
        // Initialize a debugging array to return to the client
        $debug = [];
        
        // Try Method 1: Web-style multipart form upload
        $curl = curl_init();
        
        // Prepare the file for upload
        if (class_exists('CURLFile')) {
            $uploadFile = new CURLFile($tempFile, $imageType, basename($tempFile));
            
            // Create a multipart/form-data POST similar to what the web UI would do
            $postFields = [
                'file' => $uploadFile
            ];
            
            // The URL for uploading primary images in Jellyfin
            $uploadUrl = "{$jellyfinUrl}/Items/{$itemId}/Images/Primary?api_key={$apiKey}";
            
            curl_setopt_array($curl, [
                CURLOPT_URL => $uploadUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_HTTPHEADER => [
                    "X-Emby-Token: {$apiKey}",
                    "Accept: application/json"
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_VERBOSE => true
            ]);
            
            // Create a temporary file for curl verbose output
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($curl, CURLOPT_STDERR, $verbose);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            
            // Get the verbose information
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            fclose($verbose);
            
            $requestInfo = curl_getinfo($curl);
            curl_close($curl);
            
            // Add to the debug array
            $debug['method1'] = [
                'url' => $uploadUrl,
                'http_code' => $httpCode,
                'curl_error' => $error,
                'headers_sent' => $requestInfo['request_header'],
                'headers_received' => $headers,
                'body' => $body,
                'verbose' => $verboseLog
            ];
            
            logDebug("Method 1 Response", $debug['method1']);
            
            // Check if successful
            if ($httpCode >= 200 && $httpCode < 300) {
                // Clean up and return success
                unlink($tempFile);
                logDebug("Upload successful");
                respondWithDebug(true, "Poster successfully sent to Jellyfin", ['method' => 'web-style']);
            }
        } else {
            $debug['method1'] = [
                'error' => 'CURLFile class not available'
            ];
            logDebug("CURLFile not available - skipping Method 1");
        }
        
        // Try Method 2: Use a direct PUT request without multipart
        $curl = curl_init();
        
        // Read the image data
        $imageData = file_get_contents($tempFile);
        
        // The URL for uploading primary images in Jellyfin using PUT
        $putUrl = "{$jellyfinUrl}/Items/{$itemId}/Images/Primary?api_key={$apiKey}";
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $putUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_POSTFIELDS => $imageData,
            CURLOPT_HTTPHEADER => [
                "X-Emby-Token: {$apiKey}",
                "Content-Type: {$imageType}",
                "Content-Length: " . strlen($imageData)
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_VERBOSE => true
        ]);
        
        // Create a temporary file for curl verbose output
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($curl, CURLOPT_STDERR, $verbose);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Get the verbose information
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        fclose($verbose);
        
        $requestInfo = curl_getinfo($curl);
        curl_close($curl);
        
        // Add to the debug array
        $debug['method2'] = [
            'url' => $putUrl,
            'http_code' => $httpCode,
            'curl_error' => $error,
            'headers_sent' => $requestInfo['request_header'],
            'headers_received' => $headers,
            'body' => $body,
            'verbose' => $verboseLog
        ];
        
        logDebug("Method 2 Response", $debug['method2']);
        
        // Check if successful
        if ($httpCode >= 200 && $httpCode < 300) {
            // Clean up and return success
            unlink($tempFile);
            logDebug("Upload successful");
            respondWithDebug(true, "Poster successfully sent to Jellyfin", ['method' => 'put-request']);
        }
        
        // Clean up
        unlink($tempFile);
        
        // If we got here, both methods failed
        logDebug("All upload methods failed");
        respondWithDebug(false, "Error uploading to Jellyfin: All methods failed", $debug);
    }
    
    // Default response
    logDebug("Invalid action");
    respondWithDebug(false, "Invalid action");
    
} catch (Exception $e) {
    logDebug("Exception", [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    respondWithDebug(false, $e->getMessage(), ['exception' => $e->getTraceAsString()]);
}
?>
