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

    // Helper functions
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
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($response === false) {
                throw new Exception($error);
            }
            
            if ($httpCode < 200 || $httpCode >= 300) {
                throw new Exception("HTTP error: " . $httpCode);
            }
            
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON response");
            }
            
            if (!isset($data['MediaContainer']['machineIdentifier'])) {
                throw new Exception("Missing machineIdentifier in response");
            }
            
            return [
                'success' => true,
                'data' => [
                    'identifier' => $data['MediaContainer']['machineIdentifier'],
                    'version' => $data['MediaContainer']['version'] ?? 'Unknown'
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to connect to Plex server: ' . $e->getMessage()];
        }
    }

    // Check if filename contains "Plex"
    function isPlexFile($filename) {
        return stripos($filename, '**plex**') !== false;
    }

    // Extract Plex ID from filename
    function extractPlexId($filename) {
        $matches = [];
        if (preg_match('/\[([^\]]+)\]/', $filename, $matches)) {
            return $matches[1];
        }
        return null;
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
                    $plexId = extractPlexId($file);
                    if ($plexId) {
                        $posters[] = [
                            'filename' => $file,
                            'path' => $directory . '/' . $file,
                            'plexId' => $plexId
                        ];
                    }
                }
            }
        }
        
        return $posters;
    }

    // Send to Plex functionality
    function sendToPlex($filename, $plexId, $mediaType) {
        global $plex_config;
        
        logDebug("Sending to Plex", [
            'filename' => $filename,
            'plexId' => $plexId,
            'mediaType' => $mediaType
        ]);
        
        // Ensure Plex server URL doesn't have trailing slash
        $plexServerUrl = rtrim($plex_config['server_url'], '/');
        
        // Determine the API endpoint based on media type
        $uploadUrl = "";
        switch ($mediaType) {
            case 'movie':
            case 'show':
            case 'season':
                $uploadUrl = "{$plexServerUrl}/library/metadata/{$plexId}/posters";
                break;
            case 'collection':
                $uploadUrl = "{$plexServerUrl}/library/collections/{$plexId}/posters";
                break;
            default:
                return ['success' => false, 'error' => 'Unsupported media type: ' . $mediaType];
        }
        
        // Get the image data from the file
        $filePath = '../posters/' . $mediaType . 's/' . $filename;
        if ($mediaType === 'collection') {
            $filePath = '../posters/collections/' . $filename;
        }
        
        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found: ' . $filePath];
        }
        
        $imageData = file_get_contents($filePath);
        if ($imageData === false) {
            return ['success' => false, 'error' => 'Failed to read image file'];
        }
        
        // Create headers for the Plex API request
        $headers = [];
        foreach (getPlexHeaders($plex_config['token']) as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }
        
        // Add content type for image upload
        $headers[] = 'Content-Type: image/jpeg';
        
        // Upload to Plex
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $imageData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            return ['success' => false, 'error' => 'API request failed: ' . $error];
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => 'API request returned HTTP code: ' . $httpCode];
        }
        
        // Attempt to refresh metadata
        try {
            $refreshUrl = "{$plexServerUrl}/library/metadata/{$plexId}/refresh";
            $refreshHeaders = [];
            foreach (getPlexHeaders($plex_config['token']) as $key => $value) {
                $refreshHeaders[] = $key . ': ' . $value;
            }
            
            $refreshCh = curl_init();
            curl_setopt_array($refreshCh, [
                CURLOPT_URL => $refreshUrl,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => $refreshHeaders,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            
            curl_exec($refreshCh);
            curl_close($refreshCh);
        } catch (Exception $e) {
            // Refresh error shouldn't fail the whole operation
            logDebug("Metadata refresh failed: " . $e->getMessage());
        }
        
        // If we're removing overlay labels...
        if (!empty($plex_config['remove_overlay_label'])) {
            sleep(1); // Small delay to let Plex process the upload
            
            $scriptPath = __DIR__ . '/remove-overlay-label.sh';
            
            // Check if the script exists
            if (!file_exists($scriptPath)) {
                return [
                    'success' => true,
                    'label_removal' => false,
                    'label_error' => 'Overlay label removal script not found at: ' . $scriptPath
                ];
            }
            
            // Ensure the script is executable
            if (!is_executable($scriptPath)) {
                chmod($scriptPath, 0755);
            }
            
            // Build the command to execute
            $command = escapeshellcmd($scriptPath) . ' ' . 
                       escapeshellarg($plexId) . ' ' . 
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
                    'label_removal' => true,
                    'label_message' => 'Overlay label successfully removed'
                ];
            } else {
                return [
                    'success' => true,
                    'label_removal' => false,
                    'label_error' => 'Failed to remove Overlay label: ' . $outputStr
                ];
            }
        }
        
        return ['success' => true];
    }

    // Handle actions
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'test_plex_connection':
                logDebug("Processing test_plex_connection action");
                $result = validatePlexConnection($plex_config['server_url'], $plex_config['token']);
                echo json_encode($result);
                break;
                
            case 'export_plex_posters':
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
                        'mediaType' => 'collection'
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
                    $batchSize = 3; // Process fewer at a time to avoid timeouts
                    
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
                                'failed' => 0,
                                'skipped' => 0,
                                'labels_removed' => 0,
                                'labels_failed' => 0,
                                'errors' => []
                            ],
                            'label_removal' => !empty($plex_config['remove_overlay_label'])
                        ]);
                        exit;
                    }
                    
                    // Process each poster in the batch
                    $batchResults = [
                        'successful' => 0,
                        'failed' => 0,
                        'skipped' => 0,
                        'labels_removed' => 0,
                        'labels_failed' => 0,
                        'errors' => []
                    ];
                    
                    foreach ($currentBatch as $poster) {
                        $filename = $poster['filename'];
                        $plexId = $poster['plexId'];
                        
                        if (!$plexId) {
                            $batchResults['skipped']++;
                            continue;
                        }
                        
                        $result = sendToPlex($filename, $plexId, $mediaType);
                        
                        if ($result['success']) {
                            $batchResults['successful']++;
                            
                            if (isset($result['label_removal'])) {
                                if ($result['label_removal']) {
                                    $batchResults['labels_removed']++;
                                } else {
                                    $batchResults['labels_failed']++;
                                    $errorMsg = isset($result['label_error']) ? $result['label_error'] : 'Unknown error';
                                    $batchResults['errors'][] = "Failed to remove overlay label for {$filename}: {$errorMsg}";
                                }
                            }
                        } else {
                            $batchResults['failed']++;
                            $batchResults['errors'][] = "Failed to send {$filename} to Plex: " . 
                                (isset($result['error']) ? $result['error'] : 'Unknown error');
                        }
                    }
                    
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
                        'results' => $batchResults,
                        'label_removal' => !empty($plex_config['remove_overlay_label'])
                    ]);
                } else {
                    // Not recommended for large libraries - process all at once
                    $results = [
                        'successful' => 0,
                        'failed' => 0,
                        'skipped' => 0,
                        'labels_removed' => 0,
                        'labels_failed' => 0,
                        'errors' => []
                    ];
                    
                    foreach ($allPosters as $poster) {
                        $filename = $poster['filename'];
                        $plexId = $poster['plexId'];
                        
                        if (!$plexId) {
                            $results['skipped']++;
                            continue;
                        }
                        
                        $result = sendToPlex($filename, $plexId, $mediaType);
                        
                        if ($result['success']) {
                            $results['successful']++;
                            
                            if (isset($result['label_removal'])) {
                                if ($result['label_removal']) {
                                    $results['labels_removed']++;
                                } else {
                                    $results['labels_failed']++;
                                    $errorMsg = isset($result['label_error']) ? $result['label_error'] : 'Unknown error';
                                    $results['errors'][] = "Failed to remove overlay label for {$filename}: {$errorMsg}";
                                }
                            }
                        } else {
                            $results['failed']++;
                            $results['errors'][] = "Failed to send {$filename} to Plex: " . 
                                (isset($result['error']) ? $result['error'] : 'Unknown error');
                        }
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'complete' => true,
                        'processed' => count($allPosters),
                        'results' => $results,
                        'label_removal' => !empty($plex_config['remove_overlay_label'])
                    ]);
                }
                break;
                
            default:
                logDebug("Invalid action requested: " . $_POST['action']);
                echo json_encode(['success' => false, 'error' => 'Invalid action requested']);
        }
    } else {
        // No action provided - this is probably an initial page load
        // Return a simple success response to avoid errors
        echo json_encode(['success' => true, 'status' => 'Plex Export Script Ready']);
    }

} catch (Exception $e) {
    // Log the error
    logDebug("Unhandled exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
