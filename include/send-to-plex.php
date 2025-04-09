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
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
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
set_exception_handler(function ($exception) {
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
function getEnvWithFallback($key, $default)
{
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

function getIntEnvWithFallback($key, $default)
{
    $value = getenv($key);
    return $value !== false ? intval($value) : $default;
}

// Log function for debugging
function logDebug($message, $data = null)
{
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
    logDebug("Send to Plex request received", [
        'POST' => $_POST,
        'SESSION' => $_SESSION,
        'FILES' => isset($_FILES) ? 'File upload present' : 'No files'
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

    // Helper functions (reused from your existing code)
    function getPlexHeaders($token)
    {
        return [
            'Accept' => 'application/json',
            'X-Plex-Token' => $token,
            'X-Plex-Client-Identifier' => 'Posteria',
            'X-Plex-Product' => 'Posteria',
            'X-Plex-Version' => '1.0'
        ];
    }

    function makeApiRequest($url, $headers, $method = 'GET', $data = null, $expectJson = true)
    {
        global $plex_config;

        logDebug("Making API request", [
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'data' => $data,
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

    // Function to remove Overlay label by executing the bash script
    function removeOverlayLabel($ratingKey, $mediaType)
    {
        global $plex_config;

        // Check if the feature is enabled
        if (empty($plex_config['remove_overlay_label'])) {
            return [
                'success' => false,
                'error' => 'Overlay label removal is disabled in configuration'
            ];
        }

        try {
            // Define the path to the script - adjust this to match your actual path
            $scriptPath = __DIR__ . '/remove-overlay-label.sh';

            // Check if the script exists
            if (!file_exists($scriptPath)) {
                return [
                    'success' => false,
                    'error' => 'Overlay label removal script not found at: ' . $scriptPath
                ];
            }

            // Ensure the script is executable
            if (!is_executable($scriptPath)) {
                chmod($scriptPath, 0755);
            }

            // Ensure Plex server URL doesn't have trailing slash
            $plexServerUrl = rtrim($plex_config['server_url'], '/');

            // Build the command to execute
            $command = escapeshellcmd($scriptPath) . ' ' .
                escapeshellarg($ratingKey) . ' ' .
                escapeshellarg($plexServerUrl) . ' ' .
                escapeshellarg($plex_config['token']) . ' 2>&1';

            // Execute the script and capture output
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            // Log the complete output
            $outputStr = implode("\n", $output);
            logDebug("Overlay label removal output: " . $outputStr);

            // Check return code
            if ($returnCode === 0) {
                return [
                    'success' => true,
                    'message' => 'Overlay label successfully removed',
                    'output' => $outputStr
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to remove Overlay label: ' . $outputStr,
                    'output' => $outputStr
                ];
            }
        } catch (Exception $e) {
            logDebug("Exception while removing Overlay label: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error removing Overlay label: ' . $e->getMessage()
            ];
        }
    }

    // Function to lock a poster in Plex after uploading
    function lockPosterInPlex($ratingKey, $mediaType)
    {
        global $plex_config;

        try {
            logDebug("Attempting to lock poster", [
                'ratingKey' => $ratingKey,
                'mediaType' => $mediaType
            ]);

            // Map media type to Plex type parameter
            $typeMap = [
                'movie' => 1,
                'show' => 2,
                'season' => 3,
                'collection' => 18
            ];

            if (!isset($typeMap[$mediaType])) {
                logDebug("Unsupported media type for locking", ['mediaType' => $mediaType]);
                return [
                    'success' => false,
                    'error' => "Unsupported media type for locking: $mediaType"
                ];
            }

            $type = $typeMap[$mediaType];

            // First, get the library section ID for this item
            $plexServerUrl = rtrim($plex_config['server_url'], '/');
            $metadataUrl = "{$plexServerUrl}/library/metadata/{$ratingKey}";

            $headers = [];
            foreach (getPlexHeaders($plex_config['token']) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }

            // Get the item's metadata to find its library section ID
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $metadataUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_CONNECTTIMEOUT => $plex_config['connect_timeout'],
                CURLOPT_TIMEOUT => $plex_config['request_timeout']
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $httpCode < 200 || $httpCode >= 300) {
                logDebug("Failed to retrieve metadata", [
                    'httpCode' => $httpCode,
                    'error' => $error
                ]);
                return [
                    'success' => false,
                    'error' => "Failed to retrieve metadata for item: $ratingKey"
                ];
            }

            // Parse the XML response to extract the librarySectionID
            $xml = simplexml_load_string($response);
            if (!$xml) {
                logDebug("Failed to parse XML response");
                return [
                    'success' => false,
                    'error' => "Failed to parse metadata response for item: $ratingKey"
                ];
            }

            // Get the librarySectionID (different structure based on media type)
            $librarySectionID = null;

            if (isset($xml->Video)) {
                // For movies
                $librarySectionID = (string) $xml->Video[0]['librarySectionID'];
            } elseif (isset($xml->Directory)) {
                // For shows, seasons, collections
                $librarySectionID = (string) $xml->Directory[0]['librarySectionID'];
            }

            if (empty($librarySectionID)) {
                logDebug("Could not determine library section ID", ['xml' => $xml->asXML()]);
                return [
                    'success' => false,
                    'error' => "Could not determine library section ID for item: $ratingKey"
                ];
            }

            // Construct the URL to lock the poster
            $lockUrl = "{$plexServerUrl}/library/sections/{$librarySectionID}/all?type={$type}&id={$ratingKey}&thumb.locked=1";
            logDebug("Locking poster with URL", ['url' => $lockUrl]);

            // Make the lock request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $lockUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_CONNECTTIMEOUT => $plex_config['connect_timeout'],
                CURLOPT_TIMEOUT => $plex_config['request_timeout']
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                logDebug("Successfully locked poster", ['httpCode' => $httpCode]);
                return [
                    'success' => true,
                    'message' => "Poster locked successfully"
                ];
            } else {
                logDebug("Failed to lock poster", [
                    'httpCode' => $httpCode,
                    'error' => $error
                ]);
                return [
                    'success' => false,
                    'error' => "Failed to lock poster. HTTP code: {$httpCode}"
                ];
            }
        } catch (Exception $e) {
            logDebug("Exception while locking poster", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'error' => "Error locking poster: " . $e->getMessage()
            ];
        }
    }

    // Now modify the Send to Plex functionality to include locking
    if (isset($_POST['action']) && $_POST['action'] === 'send_to_plex') {
        if (!isset($_POST['filename'], $_POST['directory'])) {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            exit;
        }

        $filename = $_POST['filename'];
        $directory = $_POST['directory'];

        // Define directories based on your existing code
        $directories = [
            'movies' => '../posters/movies/',
            'tv-shows' => '../posters/tv-shows/',
            'tv-seasons' => '../posters/tv-seasons/',
            'collections' => '../posters/collections/'
        ];

        // Check if the directory is valid
        if (!isset($directories[$directory])) {
            echo json_encode(['success' => false, 'error' => 'Invalid directory']);
            exit;
        }

        $filePath = $directories[$directory] . $filename;

        // Check if the file exists
        if (!file_exists($filePath)) {
            echo json_encode(['success' => false, 'error' => 'File not found: ' . $filePath]);
            exit;
        }

        // Extract ratingKey from filename - assuming format "Title [ratingKey] Plex.ext"
        $matches = [];
        if (!preg_match('/\[([^\]]+)\]/', $filename, $matches)) {
            echo json_encode(['success' => false, 'error' => 'Could not extract ratingKey from filename']);
            exit;
        }

        $ratingKey = $matches[1];
        logDebug("Extracted ratingKey", ['ratingKey' => $ratingKey]);

        // Determine media type based on directory
        $mediaType = '';
        switch ($directory) {
            case 'movies':
                $mediaType = 'movie';
                break;
            case 'tv-shows':
                $mediaType = 'show';
                break;
            case 'tv-seasons':
                $mediaType = 'season';
                break;
            case 'collections':
                $mediaType = 'collection';
                break;
            default:
                echo json_encode(['success' => false, 'error' => 'Unsupported media type']);
                exit;
        }

        // Read the image file
        $imageData = file_get_contents($filePath);
        if ($imageData === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to read image file']);
            exit;
        }

        // Construct URL for poster upload based on media type
        $plexServerUrl = rtrim($plex_config['server_url'], '/');
        $uploadUrl = "";
        $uploadSuccess = false;

        // For collections we need a more comprehensive approach
        if ($mediaType === 'collection') {
            // Create a unique boundary for multipart data
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
                    'url' => "{$plexServerUrl}/library/collections/{$ratingKey}/poster?X-Plex-Token=" . $plex_config['token'],
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
                foreach (getPlexHeaders($plex_config['token']) as $key => $value) {
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

                // Now we set up the curl request
                $ch = curl_init();
                $curlOptions = [
                    CURLOPT_URL => $method['url'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_CONNECTTIMEOUT => $plex_config['connect_timeout'],
                    CURLOPT_TIMEOUT => $plex_config['request_timeout'],
                    CURLOPT_VERBOSE => true
                ];

                if ($method['method'] === 'POST') {
                    $curlOptions[CURLOPT_POST] = true;
                    $curlOptions[CURLOPT_POSTFIELDS] = $postData;
                } else if ($method['method'] === 'PUT') {
                    $curlOptions[CURLOPT_CUSTOMREQUEST] = 'PUT';
                    $curlOptions[CURLOPT_POSTFIELDS] = $postData;
                }

                curl_setopt_array($ch, $curlOptions);

                // Execute the request for collection
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                // Log the result
                logDebug("Method " . ($index + 1) . " result", [
                    'http_code' => $httpCode,
                    'error' => $error,
                    'response_preview' => substr($response, 0, 200)
                ]);

                // Check if this method succeeded
                if ($httpCode >= 200 && $httpCode < 300) {
                    $success = true;
                    $uploadSuccess = true;
                    logDebug("Method " . ($index + 1) . " succeeded");
                    break;
                } else {
                    // Remember the last error
                    $lastHttpCode = $httpCode;
                    $lastError = $error;
                }
            }

            // If successful, try to trigger a refresh of the collection
            if ($success) {
                // Wait briefly for Plex to process the upload
                sleep(1);

                // Attempt to refresh the collection to apply the change
                $refreshUrl = "{$plexServerUrl}/library/metadata/{$ratingKey}/refresh";
                $refreshHeaders = [];
                foreach (getPlexHeaders($plex_config['token']) as $key => $value) {
                    $refreshHeaders[] = $key . ': ' . $value;
                }

                // Make the refresh request
                $refreshCh = curl_init();
                curl_setopt_array($refreshCh, [
                    CURLOPT_URL => $refreshUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_HTTPHEADER => $refreshHeaders,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false
                ]);

                $refreshResponse = curl_exec($refreshCh);
                $refreshHttpCode = curl_getinfo($refreshCh, CURLINFO_HTTP_CODE);
                curl_close($refreshCh);

                logDebug("Collection refresh attempt", [
                    'http_code' => $refreshHttpCode
                ]);

                // Now try to lock the poster
                $lockResult = [
                    'success' => false,
                    'attempted' => false
                ];

                logDebug("Attempting to lock collection poster", [
                    'ratingKey' => $ratingKey
                ]);

                $lockResult = lockPosterInPlex($ratingKey, $mediaType);
                $lockResult['attempted'] = true;

                // Check if we should attempt to remove overlay label
                $labelResult = [
                    'success' => false,
                    'attempted' => false
                ];

                if (!empty($plex_config['remove_overlay_label'])) {
                    logDebug("Attempting to remove Overlay label for collection: $ratingKey");
                    $labelResult = removeOverlayLabel($ratingKey, $mediaType);
                    $labelResult['attempted'] = true;
                }

                // Prepare response message
                $message = 'Collection poster successfully sent to Plex.';

                // Add lock status to the message
                if ($lockResult['attempted']) {
                    if ($lockResult['success']) {
                        $message .= ' Poster was locked.';
                    } else {
                        $message .= ' However, failed to lock poster: ' .
                            (isset($lockResult['error']) ? $lockResult['error'] : 'Unknown error');
                    }
                }

                // Add label removal status to the message
                if ($labelResult['attempted']) {
                    if ($labelResult['success']) {
                        $message .= ' Overlay label was also removed.';
                    } else {
                        $message .= ' However, failed to remove Overlay label: ' .
                            (isset($labelResult['error']) ? $labelResult['error'] : 'Unknown error');
                    }
                }

                // Return result with lock and label removal info
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'poster_locked' => isset($lockResult['success']) ? $lockResult['success'] : false,
                    'lock_message' => isset($lockResult['message']) ? $lockResult['message'] : '',
                    'lock_error' => isset($lockResult['error']) ? $lockResult['error'] : '',
                    'label_removal' => isset($labelResult['success']) ? $labelResult['success'] : false,
                    'label_message' => isset($labelResult['message']) ? $labelResult['message'] : '',
                    'label_error' => isset($labelResult['error']) ? $labelResult['error'] : ''
                ]);
            } else {
                // All methods failed
                logDebug("All collection poster upload methods failed", [
                    'last_http_code' => $lastHttpCode,
                    'last_error' => $lastError
                ]);
                echo json_encode(['success' => false, 'error' => "Failed to upload collection poster. HTTP code: {$lastHttpCode}"]);
            }

            exit;
        } else {
            // For movies, shows, and seasons
            $uploadUrl = "{$plexServerUrl}/library/metadata/{$ratingKey}/posters";

            // Create headers for the Plex API request
            $headers = [];
            foreach (getPlexHeaders($plex_config['token']) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }

            // Add content type for image upload
            $headers[] = 'Content-Type: image/jpeg';

            logDebug("Uploading poster to Plex", [
                'url' => $uploadUrl,
                'mediaType' => $mediaType,
                'ratingKey' => $ratingKey,
                'imageSize' => strlen($imageData)
            ]);

            try {
                // Use makeApiRequest for the upload
                $response = makeApiRequest($uploadUrl, $headers, 'POST', $imageData, false);
                $uploadSuccess = true;

                // Now try to lock the poster
                $lockResult = [
                    'success' => false,
                    'attempted' => false
                ];

                logDebug("Attempting to lock poster", [
                    'ratingKey' => $ratingKey,
                    'mediaType' => $mediaType
                ]);

                $lockResult = lockPosterInPlex($ratingKey, $mediaType);
                $lockResult['attempted'] = true;

                // Check if we should attempt to remove overlay label
                $labelResult = [
                    'success' => false,
                    'attempted' => false
                ];

                if (!empty($plex_config['remove_overlay_label'])) {
                    logDebug("Attempting to remove Overlay label for item: $ratingKey ($mediaType)");
                    $labelResult = removeOverlayLabel($ratingKey, $mediaType);
                    $labelResult['attempted'] = true;
                }

                // Prepare response message
                $message = 'Poster successfully sent to Plex.';

                // Add lock status to the message
                if ($lockResult['attempted']) {
                    if ($lockResult['success']) {
                        $message .= ' Poster was locked.';
                    } else {
                        $message .= ' However, failed to lock poster: ' .
                            (isset($lockResult['error']) ? $lockResult['error'] : 'Unknown error');
                    }
                }

                // Add label removal status to the message
                if ($labelResult['attempted']) {
                    if ($labelResult['success']) {
                        $message .= ' Overlay label was also removed.';
                    } else {
                        $message .= ' However, failed to remove Overlay label: ' .
                            (isset($labelResult['error']) ? $labelResult['error'] : 'Unknown error');
                    }
                }

                // Return result with lock and label removal info
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'poster_locked' => isset($lockResult['success']) ? $lockResult['success'] : false,
                    'lock_message' => isset($lockResult['message']) ? $lockResult['message'] : '',
                    'lock_error' => isset($lockResult['error']) ? $lockResult['error'] : '',
                    'label_removal' => isset($labelResult['success']) ? $labelResult['success'] : false,
                    'label_message' => isset($labelResult['message']) ? $labelResult['message'] : '',
                    'label_error' => isset($labelResult['error']) ? $labelResult['error'] : ''
                ]);

            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Error uploading to Plex: ' . $e->getMessage()]);
            }

            exit;
        }
    }

    // Default response if no action matched
    echo json_encode(['success' => false, 'error' => 'Invalid action requested']);

} catch (Exception $e) {
    // Log the error
    logDebug("Unhandled exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>