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
    file_put_contents('import-from-plex-debug.log', $logMessage . "\n\n", FILE_APPEND);
}

/**
 * Add timestamp to a filename in a consistent position (after the ID, before the library name)
 * 
 * @param string $filename The filename to modify
 * @param string $timestamp The timestamp to add in format 1234567890
 * @return string Modified filename with timestamp
 */
function addTimestampToFilename($filename, $timestamp)
{
    // Format timestamp with A prefix
    $formattedTimestamp = "(A{$timestamp})";

    // Check if timestamp already exists in the filename
    if (preg_match('/\(A\d{8,12}\)/', $filename)) {
        // Replace existing timestamp
        return preg_replace('/\(A\d{8,12}\)/', $formattedTimestamp, $filename);
    }

    // Find the ID position
    if (preg_match('/\[([a-zA-Z0-9]+)\]/', $filename, $matches, PREG_OFFSET_CAPTURE)) {
        // Get the position after the ID bracket
        $idEndPos = $matches[0][1] + strlen($matches[0][0]);

        // Insert the timestamp immediately after the ID
        $beforeTimestamp = substr($filename, 0, $idEndPos);
        $afterTimestamp = substr($filename, $idEndPos);

        return $beforeTimestamp . ' ' . $formattedTimestamp . $afterTimestamp;
    }

    // If no ID found, insert before --Plex-- as fallback
    $plexPos = strpos($filename, '--Plex--');
    if ($plexPos !== false) {
        // Insert the timestamp before "--Plex--"
        $beforePlex = substr($filename, 0, $plexPos);
        $afterPlex = substr($filename, $plexPos);
        return $beforePlex . ' ' . $formattedTimestamp . ' ' . $afterPlex;
    }

    // Last resort - just append before the extension
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $baseFilename = pathinfo($filename, PATHINFO_FILENAME);
    return $baseFilename . ' ' . $formattedTimestamp . '.' . $ext;
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

    // Helper Functions
    function sanitizeFilename($filename)
    {
        // Remove any character that isn't alphanumeric, space, underscore, dash, or dot
        $filename = preg_replace('/[^\w\s\.-]/', '', $filename);
        $filename = preg_replace('/\s+/', ' ', $filename); // Remove multiple spaces
        return trim($filename);
    }

    // Copy of the function from plex-import.php to handle library names
    function addLibraryNameToFilename($filename, $libraryName)
    {
        // Format library name with double brackets
        $bracketedLibraryName = "[[{$libraryName}]]";

        // Don't add if it's already there
        if (strpos($filename, $bracketedLibraryName) !== false) {
            return $filename;
        }

        // Find the position of "--Plex--" in the filename
        $plexPos = strpos($filename, '--Plex--');
        if ($plexPos === false) {
            // If there's no "--Plex--" marker, just append the library name before the extension
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $baseFilename = pathinfo($filename, PATHINFO_FILENAME);
            return $baseFilename . ' ' . $bracketedLibraryName . '.' . $ext;
        }

        // Insert the library name before "--Plex--"
        $beforePlex = substr($filename, 0, $plexPos);
        $afterPlex = substr($filename, $plexPos);
        return $beforePlex . $bracketedLibraryName . ' ' . $afterPlex;
    }

    // Helper Functions (reused from plex-import.php)
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

    function makeApiRequest($url, $headers, $expectJson = true)
    {
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
    function getPlexImageData($serverUrl, $token, $thumb)
    {
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

    // Function to get library details from Plex
    function getPlexLibraries($serverUrl, $token)
    {
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

    // Function to find the library for a media item
    function findLibraryForMediaItem($serverUrl, $token, $ratingKey)
    {
        try {
            // First, get the metadata to determine what kind of item this is
            $metadataUrl = rtrim($serverUrl, '/') . "/library/metadata/{$ratingKey}";
            $headers = [];
            foreach (getPlexHeaders($token) as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }

            $response = makeApiRequest($metadataUrl, $headers);
            $metadata = json_decode($response, true);

            if (!isset($metadata['MediaContainer']['Metadata'][0])) {
                return ['success' => false, 'error' => 'Item metadata not found'];
            }

            $item = $metadata['MediaContainer']['Metadata'][0];

            // For collections, we need to handle them differently
            if (isset($item['type']) && $item['type'] === 'collection') {
                // Collections don't have a direct library association in the API
                // We need to check what type of items it contains
                if (isset($item['librarySectionID'])) {
                    $libraryId = $item['librarySectionID'];

                    // Get the library details
                    $libraries = getPlexLibraries($serverUrl, $token);
                    if ($libraries['success']) {
                        foreach ($libraries['data'] as $library) {
                            if ($library['id'] == $libraryId) {
                                return ['success' => true, 'library' => $library];
                            }
                        }
                    }
                }

                return ['success' => false, 'error' => 'Could not determine library for collection'];
            }

            // For regular items, get the library section ID
            if (isset($item['librarySectionID'])) {
                $libraryId = $item['librarySectionID'];

                // Get the library details
                $libraries = getPlexLibraries($serverUrl, $token);
                if ($libraries['success']) {
                    foreach ($libraries['data'] as $library) {
                        if ($library['id'] == $libraryId) {
                            return ['success' => true, 'library' => $library];
                        }
                    }
                }
            }

            // If we get here, we couldn't find the library
            return ['success' => false, 'error' => 'Library not found for media item'];

        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Error finding library: ' . $e->getMessage()];
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

            // NEW CODE: Get the library information for this item
            $libraryResult = findLibraryForMediaItem($plexServerUrl, $plex_config['token'], $ratingKey);

            $libraryName = '';
            if ($libraryResult['success']) {
                $libraryName = $libraryResult['library']['title'];
                logDebug("Found library for media item", [
                    'libraryName' => $libraryName,
                    'libraryId' => $libraryResult['library']['id'],
                    'libraryType' => $libraryResult['library']['type']
                ]);
            } else {
                logDebug("Could not find library for media item", [
                    'error' => $libraryResult['error']
                ]);
                // Continue anyway, just without a library name
            }

            // NEW CODE: Update the filename to include the library name if it's not already there except for collections
            if (!empty($libraryName)) {
                if ($mediaType === 'collections') {
                    logDebug("Skipping library name addition for collection", [
                        'collectionName' => $filename,
                        'libraryName' => $libraryName
                    ]);
                } else {
                    $updatedFilename = addLibraryNameToFilename($filename, $libraryName);

                    // If the filename was updated, log it
                    if ($updatedFilename !== $filename) {
                        logDebug("Updated filename to include library name", [
                            'oldFilename' => $filename,
                            'newFilename' => $updatedFilename
                        ]);
                        $filename = $updatedFilename;
                    }
                }
            }

            // NEW CODE: Extract the timestamp (addedAt) from Plex metadata
            $addedAt = '';
            if (isset($metadata['MediaContainer']['Metadata'][0]['addedAt'])) {
                $addedAt = $metadata['MediaContainer']['Metadata'][0]['addedAt'];
                logDebug("Found addedAt timestamp", ['addedAt' => $addedAt]);

                // Update the filename to include the timestamp
                if (!empty($addedAt)) {
                    $updatedFilename = addTimestampToFilename($filename, $addedAt);

                    // If the filename was updated, log it
                    if ($updatedFilename !== $filename) {
                        logDebug("Updated filename to include timestamp", [
                            'oldFilename' => $filename,
                            'newFilename' => $updatedFilename
                        ]);
                        $filename = $updatedFilename;
                    }
                }
            }

            // Handle the existing file
            $originalFilename = $_POST['filename']; // Original filename from the request
            $originalPath = $directories[$mediaType] . $originalFilename;

            // Check if we need to rename the file due to adding library name or timestamp
            $needsRename = ($filename !== $originalFilename);

            if ($needsRename) {
                $targetPath = $directories[$mediaType] . $filename;

                // First, try to rename the existing file if it exists
                if (file_exists($originalPath)) {
                    logDebug("Renaming file to include library name and/or timestamp", [
                        'originalPath' => $originalPath,
                        'newPath' => $targetPath
                    ]);

                    // Rename first, then we'll update the content
                    if (!rename($originalPath, $targetPath)) {
                        logDebug("Failed to rename file", [
                            'originalPath' => $originalPath,
                            'newPath' => $targetPath,
                            'error' => error_get_last()
                        ]);

                        // If rename fails, continue with the original path
                        $targetPath = $originalPath;
                        $filename = $originalFilename;
                        $needsRename = false;
                    }
                } else {
                    logDebug("Original file not found for renaming", [
                        'originalPath' => $originalPath
                    ]);

                    // Original file doesn't exist, use the new path with library name and timestamp
                    $targetPath = $directories[$mediaType] . $filename;
                }
            } else {
                // No rename needed, use the original path
                $targetPath = $originalPath;
            }

            // Save the image file (either to the renamed path or the original path)
            if (file_put_contents($targetPath, $imageResult['data'])) {
                chmod($targetPath, 0644);

                // Add timestamp to avoid browser caching
                $timestamp = time();
                $filePathWithTimestamp = $targetPath . "?t=" . $timestamp;

                echo json_encode([
                    'success' => true,
                    'message' => 'Poster successfully imported from Plex' . ($needsRename ? ' and renamed to include library name and timestamp' : ''),
                    'filename' => $filename,
                    'originalFilename' => $originalFilename,
                    'newPath' => $filePathWithTimestamp, // Include the full path with timestamp
                    'renamed' => $needsRename,
                    'libraryName' => $libraryName,
                    'addedAt' => $addedAt
                ]);
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