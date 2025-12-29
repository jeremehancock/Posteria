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

require_once './plex-id-storage.php';
require_once './library-tracking.php';

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

// Set headers
header('Content-Type: application/json');

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

    // Create data directory if it doesn't exist
    $dataDir = '../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    file_put_contents('../data/plex-debug.log', $logMessage . "\n\n", FILE_APPEND);
}

/**
 * Simple one-time solution to register all existing Plex IDs
 * This prevents orphaned detection issues with library names
 */
function registerExistingPlexIds()
{
    // Only run this once
    $flagFile = __DIR__ . '/ids_registered.flag';
    if (file_exists($flagFile)) {
        return;
    }

    try {
        logDebug("Starting one-time registration of all existing Plex files");

        // Define media types and directories
        $mediaTypes = [
            'movies' => '../posters/movies/',
            'shows' => '../posters/tv-shows/',
            'seasons' => '../posters/tv-seasons/',
            'collections' => '../posters/collections/'
        ];

        // Load the current stored IDs
        $storedIds = loadValidIdsFromStorage();
        $registered = 0;
        $byType = [];

        // Process each media type
        foreach ($mediaTypes as $mediaType => $directory) {
            if (!isset($byType[$mediaType])) {
                $byType[$mediaType] = 0;
            }

            if (!is_dir($directory)) {
                continue;
            }

            // Make sure the structure exists
            if (!isset($storedIds[$mediaType])) {
                $storedIds[$mediaType] = [];
            }

            // Create a "legacy" library ID for old files without library names
            if (!isset($storedIds[$mediaType]['legacy'])) {
                $storedIds[$mediaType]['legacy'] = [];
            }

            // Get all Plex files
            $files = glob($directory . '/*');
            foreach ($files as $file) {
                if (!is_file($file) || strpos(basename($file), '--Plex--') === false) {
                    continue;
                }

                // Extract ID
                $idMatch = [];
                if (preg_match('/\[([a-f0-9]+)\]/', basename($file), $idMatch)) {
                    $fileId = $idMatch[1];

                    // Add to legacy library if not already there
                    if (!in_array($fileId, $storedIds[$mediaType]['legacy'])) {
                        $storedIds[$mediaType]['legacy'][] = $fileId;
                        $registered++;
                        $byType[$mediaType]++;
                    }
                }
            }
        }

        // Save the updated stored IDs
        saveValidIdsToStorage($storedIds);

        // Create flag file to mark this as done
        file_put_contents($flagFile, time());

        // Also initialize session from updated storage
        $_SESSION['valid_plex_ids'] = $storedIds;

        logDebug("Completed one-time registration of Plex files", [
            'total' => $registered,
            'byType' => $byType
        ]);
    } catch (Exception $e) {
        logDebug("Error during Plex ID registration", [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

// Call the function early in the execution
registerExistingPlexIds();

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

    // Initialize session data from persistent storage
    initializeSessionFromStorage();

    // Helper Functions
    function sanitizeFilename($filename)
    {
        // Specifically disallow only unsafe characters for filenames
        // Unsafe characters: / \ : * ? " < > |
        $filename = preg_replace('/[\/\\\:\*\?"<>\|]/', '', $filename);

        // Replace multiple spaces with a single space
        $filename = preg_replace('/\s+/', ' ', $filename);

        // Trim the result
        return trim($filename);
    }

    function generatePlexFilename($title, $id, $extension, $mediaType = '', $libraryType = '', $libraryName = '', $year = '', $addedAt = '')
    {
        $basename = sanitizeFilename($title);

        // Add year in parentheses if available and it's a movie
        if (!empty($year) && $mediaType === 'movies') {
            $basename .= " ({$year})";
        }

        // Add the ID
        if (!empty($id)) {
            $basename .= " [{$id}]";
        }

        // Add addedAt timestamp if available (in format (A1234567890))
        if (!empty($addedAt)) {
            $basename .= " (A{$addedAt})"; // A prefix to distinguish from other numbers
        }

        // For collections, don't add the library name
        if ($mediaType === 'collections') {
            // Add collection marker if not already in the name
            $collectionLabel = (!stripos($basename, 'Collection')) ? " Collection" : "";

            // Add type marker based on library type
            if ($libraryType === 'movie') {
                $basename .= "{$collectionLabel} (Movies) --Plex--";
            } else if ($libraryType === 'show') {
                $basename .= "{$collectionLabel} (TV) --Plex--";
            } else {
                // If library type unknown, just use a generic marker
                $basename .= "{$collectionLabel} --Plex--";
            }
        } else {
            // For regular items (movies, shows, seasons), add library name
            $libraryNameStr = !empty($libraryName) ? " [[{$libraryName}]]" : "";
            $basename .= "{$libraryNameStr} --Plex--";
        }

        return $basename . '.' . $extension;
    }

    /**
     * Check if there's an existing file without library name that needs to be upgraded
     */
    function findExistingPosterByRatingKey($directory, $ratingKey)
    {
        if (!is_dir($directory)) {
            return false;
        }

        $files = glob($directory . '/*');
        foreach ($files as $file) {
            if (is_file($file) && strpos($file, "--Plex--") !== false) {
                // Look for the rating key pattern [ratingKey]
                $pattern = '/\[' . preg_quote($ratingKey, '/') . '\]/';
                if (preg_match($pattern, basename($file))) {
                    return basename($file);
                }
            }
        }

        return false;
    }

    /**
     * Add library name to an existing filename without modifying media type information
     */
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

    function handleExistingFile($targetPath, $overwriteOption, $filename, $extension)
    {
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

    function getPlexHeaders($token, $start = 0, $size = 50)
    {
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

    function validatePlexConnection($serverUrl, $token)
    {
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

            return [
                'success' => true,
                'data' => [
                    'identifier' => $data['MediaContainer']['machineIdentifier'],
                    'version' => $data['MediaContainer']['version'] ?? 'Unknown'
                ]
            ];
        } catch (Exception $e) {
            logDebug("Plex connection validation failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

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

    /**
     * Get the library name from the library ID
     */
    function getLibraryNameById($serverUrl, $token, $libraryId)
    {
        $libraries = getPlexLibraries($serverUrl, $token);
        if ($libraries['success']) {
            foreach ($libraries['data'] as $library) {
                if ($library['id'] == $libraryId) {
                    return $library['title'];
                }
            }
        }
        return '';
    }

    function getPlexMovies($serverUrl, $token, $libraryId, $start = 0, $size = 50)
    {
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
                        'ratingKey' => $movie['ratingKey'],
                        'addedAt' => $movie['addedAt'] ?? '' // Extract addedAt timestamp
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
    function getAllPlexMovies($serverUrl, $token, $libraryId)
    {
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

    function getPlexShows($serverUrl, $token, $libraryId, $start = 0, $size = 50)
    {
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
                        'ratingKey' => $show['ratingKey'],
                        'addedAt' => $show['addedAt'] ?? '' // Extract addedAt timestamp
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
    function getAllPlexShows($serverUrl, $token, $libraryId)
    {
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

    function getPlexSeasons($serverUrl, $token, $showKey, $start = 0, $size = 50)
    {
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
                        'ratingKey' => $season['ratingKey'],
                        'addedAt' => $season['addedAt'] ?? '' // Extract addedAt timestamp
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
    function getAllPlexSeasons($serverUrl, $token, $showKey)
    {
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

    function getPlexCollections($serverUrl, $token, $libraryId, $start = 0, $size = 50)
    {
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
                        'ratingKey' => $collection['ratingKey'],
                        'addedAt' => $collection['addedAt'] ?? '' // Extract addedAt timestamp
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
    function getAllPlexCollections($serverUrl, $token, $libraryId)
    {
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

    // UPDATED: Function to download and save Plex image to file
    function downloadPlexImage($serverUrl, $token, $thumb, $targetPath)
    {
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
    function compareAndSaveImage($imageData, $targetPath)
    {
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
    function processBatch($items, $serverUrl, $token, $targetDir, $overwriteOption, $mediaType = '', $libraryType = '', $libraryName = '')
    {
        $results = [
            'successful' => 0,
            'skipped' => 0,
            'unchanged' => 0,
            'renamed' => 0,  // Counter for renamed files
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

        logDebug("Starting batch processing", [
            'total_items' => count($items),
            'media_type' => $mediaType,
            'library_type' => $libraryType,
            'library_name' => $libraryName,
            'overwrite_option' => $overwriteOption
        ]);

        foreach ($items as $index => $item) {
            // DEBUG LOGGING - This will show you exactly which item is being processed
            logDebug("=== PROCESSING ITEM ===", [
                'index' => $index,
                'total_items' => count($items),
                'title' => isset($item['title']) ? $item['title'] : 'UNKNOWN',
                'id' => isset($item['id']) ? $item['id'] : 'UNKNOWN',
                'thumb' => isset($item['thumb']) ? $item['thumb'] : 'UNKNOWN',
                'memory_usage' => formatBytes(memory_get_usage(true))
            ]);

            // Check if the item is well-formed
            if (!isset($item['title']) || !isset($item['id']) || !isset($item['thumb'])) {
                logDebug("Skipping malformed item in processBatch", $item);
                $results['failed']++;

                // Create detailed error message for browser console
                $malformedDetails = [
                    'index' => $index,
                    'missing_fields' => [],
                    'available_fields' => array_keys($item),
                    'item_data' => $item
                ];

                if (!isset($item['title']))
                    $malformedDetails['missing_fields'][] = 'title';
                if (!isset($item['id']))
                    $malformedDetails['missing_fields'][] = 'id';
                if (!isset($item['thumb']))
                    $malformedDetails['missing_fields'][] = 'thumb';

                $results['errors'][] = "MALFORMED ITEM at index {$index}: Missing fields [" .
                    implode(', ', $malformedDetails['missing_fields']) .
                    "] - Available fields: [" . implode(', ', $malformedDetails['available_fields']) .
                    "] - Data: " . json_encode($item);
                continue;
            }

            $title = $item['title'];
            $id = $item['id'];
            $thumb = $item['thumb'];

            // LOG BEFORE API CALLS
            logDebug("About to process poster download", [
                'item_title' => $title,
                'item_id' => $id,
                'poster_url' => $thumb,
                'full_url' => rtrim($serverUrl, '/') . $thumb
            ]);

            // Get the year if available (for movies)
            $year = isset($item['year']) ? $item['year'] : '';

            // Extract the addedAt timestamp if available
            $addedAt = isset($item['addedAt']) ? $item['addedAt'] : '';

            // First, check if there's an existing file for this rating key without library name
            $existingFile = findExistingPosterByRatingKey($targetDir, $id);

            // Generate target filename - now with library name, year for movies, and addedAt timestamp
            $extension = 'jpg'; // Plex thumbnails are usually JPG
            $filename = generatePlexFilename($title, $id, $extension, $mediaType, $libraryType, $libraryName, $year, $addedAt);
            $targetPath = $targetDir . $filename;

            logDebug("Generated filename", [
                'original_title' => $title,
                'generated_filename' => $filename,
                'target_path' => $targetPath,
                'existing_file' => $existingFile
            ]);

            // If we found an existing file without library name, handle it
            if ($existingFile && $existingFile !== $filename) {
                $oldPath = $targetDir . $existingFile;

                // Check if we're just upgrading the filename by adding library name or year
                if (
                    (strpos($filename, $libraryName) !== false && !strpos($existingFile, $libraryName)) ||
                    ($mediaType === 'movies' && !empty($year) && strpos($filename, "({$year})") !== false && !strpos($existingFile, "({$year})"))
                ) {
                    // Rename the file to include library name and/or year
                    if (rename($oldPath, $targetPath)) {
                        $results['renamed']++;
                        $results['successful']++;  // Also count as successful
                        $results['importedIds'][] = $id;
                        logDebug("Renamed file to include library name/year: {$existingFile} -> {$filename}");
                        continue;
                    } else {
                        // If rename failed, proceed with normal download
                        logDebug("Failed to rename file, will try regular download: {$oldPath}");
                    }
                }
            }

            // Rest of the function remains the same...
            // Handle existing file based on overwrite option
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

                    logDebug("About to download image (copy mode)", [
                        'title' => $title,
                        'target_path' => $targetPath,
                        'thumb_url' => $thumb
                    ]);

                    // For 'copy', we'll download directly
                    $downloadResult = downloadPlexImage($serverUrl, $token, $thumb, $targetPath);

                    if ($downloadResult['success']) {
                        $results['successful']++;
                        $results['importedIds'][] = $id;
                        logDebug("Successfully downloaded (copy mode): {$title}");
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Failed to download {$title}: {$downloadResult['error']}";
                        logDebug("Failed to download (copy mode)", [
                            'title' => $title,
                            'error' => $downloadResult['error']
                        ]);
                    }
                    continue;
                } else if ($overwriteOption === 'overwrite') {
                    logDebug("About to get image data (overwrite mode)", [
                        'title' => $title,
                        'thumb_url' => $thumb
                    ]);

                    // For overwrite, we'll check if content has changed
                    $imageResult = getPlexImageData($serverUrl, $token, $thumb);

                    if (!$imageResult['success']) {
                        $results['failed']++;
                        $results['errors'][] = "Failed to download {$title}: {$imageResult['error']}";
                        logDebug("Failed to get image data (overwrite mode)", [
                            'title' => $title,
                            'error' => $imageResult['error']
                        ]);
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
                        logDebug("Failed to save (overwrite mode)", [
                            'title' => $title,
                            'error' => $saveResult['error']
                        ]);
                    }
                    continue;
                }
            } else {
                logDebug("About to download new file", [
                    'title' => $title,
                    'target_path' => $targetPath,
                    'thumb_url' => $thumb
                ]);

                // File doesn't exist, download directly
                $downloadResult = downloadPlexImage($serverUrl, $token, $thumb, $targetPath);

                if ($downloadResult['success']) {
                    $results['successful']++;
                    $results['importedIds'][] = $id;
                    logDebug("Successfully downloaded new file: {$title}");
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to download {$title}: {$downloadResult['error']}";
                    logDebug("Failed to download new file", [
                        'title' => $title,
                        'error' => $downloadResult['error']
                    ]);
                }
            }

            logDebug("Completed processing item", [
                'title' => $title,
                'index' => $index,
                'memory_after' => formatBytes(memory_get_usage(true))
            ]);
        }

        logDebug("Batch processing complete", [
            'total_processed' => count($items),
            'successful' => $results['successful'],
            'skipped' => $results['skipped'],
            'unchanged' => $results['unchanged'],
            'renamed' => $results['renamed'],
            'failed' => $results['failed'],
            'final_memory' => formatBytes(memory_get_usage(true))
        ]);

        return $results;
    }

    function formatBytes($size, $precision = 2)
    {
        $base = log($size, 1024);
        $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }

    function getExistingPosters($directory, $type = '--Plex--')
    {
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
     * Main function to handle the detection and marking of orphaned posters
     * for any media type at the end of a batch import process.
     * 
     * @param string $mediaType Type of media (movies, shows, seasons, collections)
     * @param string $libraryId The library ID being processed
     * @param array $importedIds IDs imported in the current session
     * @param string $targetDir Directory to check for orphaned posters
     * @param string $showTitle Optional - for seasons, only process files for this show
     * @param string $libraryType Optional - for collections, the library type (movie/show)
     * @return array Results with count of orphaned files
     */
    function handleOrphanedPosters($mediaType, $libraryId, $importedIds, $targetDir, $showTitle = '', $libraryType = '')
    {
        // First, store the imported IDs in persistent storage with replace mode = true
        storeValidIds($importedIds, $mediaType, $libraryId, true);

        // Synchronize session to storage to ensure persistence
        syncSessionToStorage();

        // Now detect and mark orphaned posters
        $orphanedResults = improvedMarkOrphanedPosters(
            $targetDir,
            $importedIds,
            '--Orphaned--',
            $libraryType,
            $showTitle,
            $mediaType,
            $libraryId,
            true // Refresh mode = true
        );

        return $orphanedResults;
    }

    /**
     * Handle orphaned detection at the end of movie batch processing
     */
    function handleMovieOrphanedDetection($libraryId, $allImportedIds)
    {
        $targetDir = '../posters/movies/';
        return handleOrphanedPosters('movies', $libraryId, $allImportedIds, $targetDir);
    }

    /**
     * Handle orphaned detection at the end of TV show batch processing
     */
    function handleShowOrphanedDetection($libraryId, $allImportedIds)
    {
        $targetDir = '../posters/tv-shows/';
        return handleOrphanedPosters('shows', $libraryId, $allImportedIds, $targetDir);
    }

    /**
     * Handle orphaned detection at the end of season batch processing
     */
    function handleSeasonOrphanedDetection($libraryId, $allImportedIds, $showTitle = '')
    {
        $targetDir = '../posters/tv-seasons/';
        return handleOrphanedPosters('seasons', $libraryId, $allImportedIds, $targetDir, $showTitle);
    }

    /**
     * Handle orphaned detection at the end of collection batch processing
     */
    function handleCollectionOrphanedDetection($libraryId, $allImportedIds, $libraryType)
    {
        $targetDir = '../posters/collections/';
        return handleOrphanedPosters('collections', $libraryId, $allImportedIds, $targetDir, '', $libraryType);
    }

    /**
     * Ensure session is cleared properly after processing completes
     */
    function cleanupAfterImport($mediaType)
    {
        // Clean up session variables for this media type
        if (isset($_SESSION['import_' . $mediaType . '_ids'])) {
            unset($_SESSION['import_' . $mediaType . '_ids']);
        }

        // Also clean up show title and library type if needed
        if (isset($_SESSION['current_show_title'])) {
            unset($_SESSION['current_show_title']);
        }

        if (isset($_SESSION['current_library_type'])) {
            unset($_SESSION['current_library_type']);
        }

        // Synchronize changes to persistent storage
        syncSessionToStorage();
    }

    // Add this helper function to check if a file belongs to a specific library
    function fileMatchesLibrary($filename, $libraryName)
    {
        // Look for library name in double brackets [[LibraryName]]
        return strpos($filename, "[[" . $libraryName . "]]") !== false;
    }

    /**
     * Improved function to detect and mark orphaned posters
     * Complete fix for all media types including special handling for collections
     */
    function improvedMarkOrphanedPosters(
        $targetDir,
        $currentImportIds,
        $orphanedTag = '--Orphaned--',
        $libraryType = '',
        $showTitle = '',
        $mediaType = '',
        $libraryId = '',
        $refreshMode = true
    ) {
        $results = [
            'orphaned' => 0,
            'unmarked' => 0,
            'oldFormat' => 0, // Counter for files with missing timestamp
            'details' => []
        ];

        if (!is_dir($targetDir)) {
            return $results;
        }

        // Check if this is a single-library import
        $libraryIds = isset($_POST['libraryIds']) ? explode(',', $_POST['libraryIds']) : [];
        $isSingleLibrary = count($libraryIds) === 1;

        // Get the library name and type for the current library
        $currentLibraryName = '';
        $currentLibraryType = '';
        if ($isSingleLibrary) {
            // For single library imports, determine both library name and type
            $librariesResult = getPlexLibraries(
                $GLOBALS['plex_config']['server_url'],
                $GLOBALS['plex_config']['token']
            );

            if ($librariesResult['success'] && !empty($librariesResult['data'])) {
                foreach ($librariesResult['data'] as $lib) {
                    if ($lib['id'] == $libraryId) {
                        $currentLibraryName = $lib['title'];
                        $currentLibraryType = $lib['type']; // 'movie' or 'show'
                        break;
                    }
                }
            }

            // If libraryType wasn't passed but we found it, use it
            if (empty($libraryType) && !empty($currentLibraryType)) {
                $libraryType = $currentLibraryType;
            }
        }

        // Log diagnostic information
        logDebug("Orphan detection diagnostic", [
            'mediaType' => $mediaType,
            'isSingleLibrary' => $isSingleLibrary,
            'libraryId' => $libraryId,
            'libraryName' => $currentLibraryName,
            'libraryType' => $libraryType,
            'validIdCount' => count($currentImportIds)
        ]);

        // Get valid IDs based on import type
        $allValidIds = [];
        if ($isSingleLibrary) {
            // For single library, only use current import IDs
            $allValidIds = $currentImportIds;
        } else {
            // For multi-library, use all valid IDs
            $allValidIds = getAllValidIds($mediaType);
            $allValidIds = array_merge($allValidIds, $currentImportIds);
        }
        $allValidIds = array_unique($allValidIds);

        // Process all files in two separate passes
        $files = glob($targetDir . '/*');
        $processedFiles = []; // Track which files were processed in first pass

        // SPECIAL HANDLING FOR COLLECTIONS:
        // For collections, we need to handle them differently in single vs multi-library imports
        $isCollections = ($mediaType === 'collections');

        // For collections and single library, we'll do a special direct timestamp check
        // bypassing the standard orphan detection
        if ($isCollections && $isSingleLibrary) {
            logDebug("Collections special handling for single library import", [
                'libraryType' => $libraryType,
                'fileCount' => count($files)
            ]);

            foreach ($files as $file) {
                if (!is_file($file))
                    continue;

                $filename = basename($file);
                if (strpos($filename, $orphanedTag) !== false)
                    continue;
                if (strpos($filename, '--Plex--') === false)
                    continue;

                // Filter by collection type (Movies/TV) for this library
                $isMovieCollection = strpos($filename, '(Movies)') !== false;
                $isShowCollection = strpos($filename, '(TV)') !== false;

                // Skip collections that don't match the current library type
                if (
                    ($libraryType === 'movie' && !$isMovieCollection) ||
                    ($libraryType === 'show' && !$isShowCollection)
                ) {
                    continue;
                }

                // Check if file has a timestamp
                $hasTimestamp = preg_match('/\(A\d{8,12}\)/', $filename);

                // If no timestamp, mark as orphan regardless of ID validity
                if (!$hasTimestamp) {
                    $newFilename = str_replace('--Plex--', $orphanedTag, $filename);
                    $newPath = $targetDir . '/' . $newFilename;

                    logDebug("Found collection without timestamp", [
                        'oldName' => $filename,
                        'isMovieCollection' => $isMovieCollection,
                        'isShowCollection' => $isShowCollection
                    ]);

                    if (rename($file, $newPath)) {
                        $results['orphaned']++;
                        $results['oldFormat']++;
                        $results['details'][] = [
                            'oldName' => $filename,
                            'newName' => $newFilename,
                            'reason' => 'collection_missing_timestamp'
                        ];

                        logDebug("Marked collection as orphan due to missing timestamp", [
                            'oldName' => $filename,
                            'newName' => $newFilename
                        ]);
                    } else {
                        $results['unmarked']++;
                    }

                    // Track that this file has been processed
                    $processedFiles[$file] = true;
                }
            }
        }

        // FIRST PASS: Standard orphan detection (check if IDs are valid)
        // This applies to all media types and multi-library collections
        foreach ($files as $file) {
            if (!is_file($file))
                continue;

            // Skip files already processed in collections special handling
            if (isset($processedFiles[$file]))
                continue;

            $filename = basename($file);
            if (strpos($filename, $orphanedTag) !== false)
                continue;
            if (strpos($filename, '--Plex--') === false)
                continue;

            // Skip files that don't match library/show filtering
            if (!shouldProcessFile($filename, $mediaType, $isSingleLibrary, $currentLibraryName, $libraryType, $showTitle)) {
                continue;
            }

            // Extract ID for validation
            $fileId = null;
            if (preg_match('/\[([a-f0-9]+)\]/', $filename, $idMatch)) {
                $fileId = $idMatch[1];
            } else {
                continue; // Skip files without valid IDs
            }

            // Standard orphan check (ID not in valid list)
            if (!in_array($fileId, $allValidIds)) {
                $newFilename = str_replace('--Plex--', $orphanedTag, $filename);
                $newPath = $targetDir . '/' . $newFilename;

                if (rename($file, $newPath)) {
                    $results['orphaned']++;
                    $results['details'][] = [
                        'oldName' => $filename,
                        'newName' => $newFilename,
                        'reason' => 'id_not_valid',
                        'mediaType' => $mediaType
                    ];

                    logDebug("Marked as orphan due to invalid ID", [
                        'oldName' => $filename,
                        'id' => $fileId
                    ]);
                } else {
                    $results['unmarked']++;
                }

                // Add to processed files
                $processedFiles[$file] = true;
            }
        }

        // SECOND PASS: Timestamp check for all non-collections media types 
        // and multi-library collections
        if (!($isCollections && $isSingleLibrary)) {
            foreach ($files as $file) {
                if (!is_file($file))
                    continue;

                // Skip files already processed
                if (isset($processedFiles[$file]))
                    continue;

                $filename = basename($file);
                if (strpos($filename, $orphanedTag) !== false)
                    continue;
                if (strpos($filename, '--Plex--') === false)
                    continue;

                // Skip files that don't match library/show filtering
                if (!shouldProcessFile($filename, $mediaType, $isSingleLibrary, $currentLibraryName, $libraryType, $showTitle)) {
                    continue;
                }

                // Check if file has a timestamp
                $hasTimestamp = preg_match('/\(A\d{8,12}\)/', $filename);

                // If no timestamp, mark as orphan regardless of ID validity
                if (!$hasTimestamp) {
                    $newFilename = str_replace('--Plex--', $orphanedTag, $filename);
                    $newPath = $targetDir . '/' . $newFilename;

                    logDebug("Found file without timestamp", [
                        'oldName' => $filename,
                        'mediaType' => $mediaType
                    ]);

                    if (rename($file, $newPath)) {
                        $results['orphaned']++;
                        $results['oldFormat']++;
                        $results['details'][] = [
                            'oldName' => $filename,
                            'newName' => $newFilename,
                            'reason' => 'missing_timestamp',
                            'mediaType' => $mediaType
                        ];

                        logDebug("Marked as orphan due to missing timestamp", [
                            'oldName' => $filename,
                            'newName' => $newFilename
                        ]);
                    } else {
                        $results['unmarked']++;
                        logDebug("Failed to rename file without timestamp", [
                            'oldName' => $filename,
                            'newPath' => $newPath
                        ]);
                    }
                }
            }
        }

        logDebug("Completed orphan detection with timestamp check", [
            'totalOrphaned' => $results['orphaned'],
            'oldFormatDetected' => $results['oldFormat'],
            'unmarked' => $results['unmarked'],
            'mediaType' => $mediaType
        ]);

        return $results;
    }

    /**
     * Helper function to determine if a file should be processed
     * Centralizes the logic for library, collection type, and show filtering
     */
    function shouldProcessFile($filename, $mediaType, $isSingleLibrary, $libraryName, $libraryType, $showTitle)
    {
        // For single library import, check library name matching
        if ($isSingleLibrary && !empty($libraryName)) {
            // Special case for collections - check by media type marker instead
            if ($mediaType === 'collections') {
                $isMovieCollection = strpos($filename, '(Movies)') !== false;
                $isShowCollection = strpos($filename, '(TV)') !== false;

                if (
                    ($libraryType === 'movie' && !$isMovieCollection) ||
                    ($libraryType === 'show' && !$isShowCollection)
                ) {
                    return false;
                }
            }
            // For non-collections, check library name
            else if (!fileMatchesLibrary($filename, $libraryName)) {
                return false;
            }
        }

        // For multi-library collection import, check collection type
        if (!$isSingleLibrary && $mediaType === 'collections' && !empty($libraryType)) {
            $isMovieCollection = strpos($filename, '(Movies)') !== false;
            $isShowCollection = strpos($filename, '(TV)') !== false;

            if (
                ($libraryType === 'movie' && !$isMovieCollection) ||
                ($libraryType === 'show' && !$isShowCollection)
            ) {
                return false;
            }
        }

        // For seasons, check show title if provided
        if ($mediaType === 'seasons' && !empty($showTitle)) {
            if (strpos($filename, $showTitle) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Integration function - Replace existing markOrphanedPosters calls with this one
     * This wrapper ensures backward compatibility while using the improved logic
     */
    function markOrphanedPosters(
        $targetDir,
        $validIds,
        $orphanedTag = '--Orphaned--',
        $libraryType = '',
        $showTitle = '',
        $mediaType = ''
    ) {
        // Get libraryId from session - this should be set during the import process
        $libraryId = isset($_SESSION['current_library_id']) ? $_SESSION['current_library_id'] : '';

        // Default to refresh mode = true for backward compatibility
        return improvedMarkOrphanedPosters($targetDir, $validIds, $orphanedTag, $libraryType, $showTitle, $mediaType, $libraryId, true);
    }

    /**
     * Helper function to update the library ID in session
     * Call this before starting a new import process
     */
    function setCurrentLibraryId($libraryId)
    {
        $_SESSION['current_library_id'] = $libraryId;
        logDebug("Set current library ID: " . $libraryId);
    }

    // API Endpoints

    if (isset($_POST['action']) && $_POST['action'] === 'get_plex_libraries') {
        logDebug("Processing get_plex_libraries action");
        $result = getPlexLibraries($plex_config['server_url'], $plex_config['token']);

        // Filter out excluded libraries
        if ($result['success'] && !empty($result['data'])) {
            $result['data'] = array_filter($result['data'], function ($library) use ($auto_import_config) {
                return !in_array($library['title'], $auto_import_config['excluded_libraries']);
            });
            // Re-index the array after filtering
            $result['data'] = array_values($result['data']);

            // Store all libraries for reference
            storeLibraryInfo($result['data'], 'all');
        }

        echo json_encode($result);
        logDebug("Response sent", $result);
        exit;
    }

    // Test Plex Connection
    if (isset($_POST['action']) && $_POST['action'] === 'test_plex_connection') {
        logDebug("Processing test_plex_connection action");
        $result = validatePlexConnection($plex_config['server_url'], $plex_config['token']);
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
// Complete code for the import_plex_posters action handler
    if (isset($_POST['action']) && $_POST['action'] === 'import_plex_posters') {
        if (!isset($_POST['type'], $_POST['libraryIds'], $_POST['contentType'], $_POST['overwriteOption'])) {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            exit;
        }

        $type = $_POST['type']; // 'movies', 'shows', 'seasons', 'collections'
        $libraryIds = explode(',', $_POST['libraryIds']); // Parse comma-separated list
        $contentType = $_POST['contentType']; // This will be the directory key
        $overwriteOption = $_POST['overwriteOption']; // 'overwrite', 'copy', 'skip'

        // Get the current library index and ID
        $libraryIndex = isset($_POST['libraryIndex']) ? (int) $_POST['libraryIndex'] : 0;

        // Make sure we don't exceed array bounds
        if ($libraryIndex >= count($libraryIds)) {
            echo json_encode(['success' => false, 'error' => 'Invalid library index']);
            exit;
        }

        $currentLibraryId = $libraryIds[$libraryIndex];

        // Get the library name to include in filenames
        $libraryName = getLibraryNameById($plex_config['server_url'], $plex_config['token'], $currentLibraryId);

        // Set the current library ID in session for orphan detection
        setCurrentLibraryId($currentLibraryId);

        // IMPORTANT - Clear stored IDs for this library and type when starting a new import
        // This ensures that removed items are properly detected as orphaned
        if (isset($_POST['startIndex']) && (int) $_POST['startIndex'] === 0 && $libraryIndex === 0) {
            // Only clear all stored IDs on the very first batch of the first library
            clearStoredIds($type, $currentLibraryId);
            logDebug("Starting new import - cleared stored IDs for {$type}, library {$currentLibraryId}");
        }

        // Fetch all available libraries for this media type to track what libraries exist
        $availableLibraries = [];
        $librariesResult = getPlexLibraries($plex_config['server_url'], $plex_config['token']);
        if ($librariesResult['success']) {
            // Filter libraries based on media type and exclusion list
            foreach ($librariesResult['data'] as $lib) {
                // Skip excluded libraries
                if (in_array($lib['title'], $auto_import_config['excluded_libraries'])) {
                    continue;
                }

                if (
                    ($type === 'movies' && $lib['type'] === 'movie') ||
                    (($type === 'shows' || $type === 'seasons') && $lib['type'] === 'show') ||
                    ($type === 'collections' && in_array($lib['type'], ['movie', 'show']))
                ) {
                    $availableLibraries[] = $lib;
                }
            }

            // Initialize the import session and handle missing libraries
            $missingLibraryResult = initializeImportSession($type, $availableLibraries);
            logDebug("Checked for missing libraries", [
                'mediaType' => $type,
                'cleared' => count($missingLibraryResult['cleared']),
                'details' => $missingLibraryResult['cleared']
            ]);
        }

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
            'renamed' => 0,   // Added renamed counter
            'failed' => 0,
            'errors' => []
        ];

        try {
            switch ($type) {
                case 'movies':
                    // Handle batch processing for movies
                    if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                        $startIndex = (int) $_POST['startIndex'];
                        $batchSize = $plex_config['import_batch_size'];

                        // Get all movies using pagination for the current library
                        $result = getAllPlexMovies($plex_config['server_url'], $plex_config['token'], $currentLibraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $allMovies = $result['data'];

                        // Process this batch
                        $currentBatch = array_slice($allMovies, $startIndex, $batchSize);
                        $endIndex = $startIndex + count($currentBatch);
                        $isComplete = $endIndex >= count($allMovies);

                        // Check if we need to move to the next library when done with current one
                        $moveToNextLibrary = $isComplete && ($libraryIndex < count($libraryIds) - 1);
                        $isCompleteAll = $isComplete && !$moveToNextLibrary;

                        // Process the batch
                        $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type, '', $libraryName);

                        // Handle orphaned posters if this is the final batch of the final library
                        $orphanedResults = null;
                        if ($isCompleteAll) {
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

                            // Use the enhanced orphan detection that checks for missing libraries
                            $orphanedResults = enhancedMarkOrphanedPosters(
                                $targetDir,
                                $allImportedIds,
                                '--Orphaned--',
                                '',
                                '',
                                'movies',
                                $currentLibraryId,
                                true, // This is the refresh mode parameter
                                $availableLibraries // Pass the current available libraries
                            );

                            // Synchronize session data to persistent storage
                            syncSessionToStorage();

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

                        // Get accumulated totals from previous libraries/batches if available
                        if (isset($_POST['totalSuccessful']))
                            $totalStats['successful'] = (int) $_POST['totalSuccessful'];
                        if (isset($_POST['totalSkipped']))
                            $totalStats['skipped'] = (int) $_POST['totalSkipped'];
                        if (isset($_POST['totalUnchanged']))
                            $totalStats['unchanged'] = (int) $_POST['totalUnchanged'];
                        if (isset($_POST['totalRenamed']))
                            $totalStats['renamed'] = (int) $_POST['totalRenamed'];
                        if (isset($_POST['totalFailed']))
                            $totalStats['failed'] = (int) $_POST['totalFailed'];

                        // Add current batch results to totals
                        $totalStats['successful'] += $batchResults['successful'] ?? 0;
                        $totalStats['skipped'] += $batchResults['skipped'] ?? 0;
                        $totalStats['unchanged'] += $batchResults['unchanged'] ?? 0;
                        $totalStats['renamed'] += $batchResults['renamed'] ?? 0;
                        $totalStats['failed'] += $batchResults['failed'] ?? 0;

                        // Respond with batch results and progress
                        echo json_encode([
                            'success' => true,
                            'batchComplete' => true,
                            'progress' => [
                                'processed' => $endIndex,
                                'total' => count($allMovies),
                                'percentage' => round(($endIndex / count($allMovies)) * 100),
                                'isComplete' => $isCompleteAll,
                                'nextIndex' => $isComplete ? 0 : $endIndex, // Reset to 0 if moving to next library
                                'moveToNextLibrary' => $moveToNextLibrary,
                                'nextLibraryIndex' => $moveToNextLibrary ? $libraryIndex + 1 : $libraryIndex,
                                'totalLibraries' => count($libraryIds),
                                'currentLibraryIndex' => $libraryIndex,
                                'currentLibrary' => $libraryName
                            ],
                            'results' => $batchResults,
                            'orphanedResults' => $orphanedResults,
                            'totalStats' => $totalStats
                        ]);
                        exit;
                    } else {
                        // Process all movies at once
                        $result = getAllPlexMovies($plex_config['server_url'], $plex_config['token'], $currentLibraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $items = $result['data'];
                    }
                    break;

                case 'shows':
                    // Handle batch processing for shows
                    if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                        $startIndex = (int) $_POST['startIndex'];
                        $batchSize = $plex_config['import_batch_size'];

                        // Get all shows using pagination for the current library
                        $result = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $currentLibraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $allShows = $result['data'];

                        // Process this batch
                        $currentBatch = array_slice($allShows, $startIndex, $batchSize);
                        $endIndex = $startIndex + count($currentBatch);
                        $isComplete = $endIndex >= count($allShows);

                        // Check if we need to move to the next library when done with current one
                        $moveToNextLibrary = $isComplete && ($libraryIndex < count($libraryIds) - 1);
                        $isCompleteAll = $isComplete && !$moveToNextLibrary;

                        // Process the batch
                        $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type, '', $libraryName);

                        // Handle orphaned posters if this is the final batch of the final library
                        $orphanedResults = null;
                        if ($isCompleteAll) {
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

                            // Use the enhanced orphan detection that checks for missing libraries
                            $orphanedResults = enhancedMarkOrphanedPosters(
                                $targetDir,
                                $allImportedIds,
                                '--Orphaned--',
                                '',
                                '',
                                'shows',
                                $currentLibraryId,
                                true, // Refresh mode parameter
                                $availableLibraries // Pass the current available libraries
                            );

                            // Synchronize session data to persistent storage
                            syncSessionToStorage();

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

                        // Get accumulated totals from previous libraries/batches if available
                        if (isset($_POST['totalSuccessful']))
                            $totalStats['successful'] = (int) $_POST['totalSuccessful'];
                        if (isset($_POST['totalSkipped']))
                            $totalStats['skipped'] = (int) $_POST['totalSkipped'];
                        if (isset($_POST['totalUnchanged']))
                            $totalStats['unchanged'] = (int) $_POST['totalUnchanged'];
                        if (isset($_POST['totalRenamed']))
                            $totalStats['renamed'] = (int) $_POST['totalRenamed'];
                        if (isset($_POST['totalFailed']))
                            $totalStats['failed'] = (int) $_POST['totalFailed'];

                        // Add current batch results to totals
                        $totalStats['successful'] += $batchResults['successful'] ?? 0;
                        $totalStats['skipped'] += $batchResults['skipped'] ?? 0;
                        $totalStats['unchanged'] += $batchResults['unchanged'] ?? 0;
                        $totalStats['renamed'] += $batchResults['renamed'] ?? 0;
                        $totalStats['failed'] += $batchResults['failed'] ?? 0;

                        // Respond with batch results and progress
                        echo json_encode([
                            'success' => true,
                            'batchComplete' => true,
                            'progress' => [
                                'processed' => $endIndex,
                                'total' => count($allShows),
                                'percentage' => round(($endIndex / count($allShows)) * 100),
                                'isComplete' => $isCompleteAll,
                                'nextIndex' => $isComplete ? 0 : $endIndex, // Reset to 0 if moving to next library
                                'moveToNextLibrary' => $moveToNextLibrary,
                                'nextLibraryIndex' => $moveToNextLibrary ? $libraryIndex + 1 : $libraryIndex,
                                'totalLibraries' => count($libraryIds),
                                'currentLibraryIndex' => $libraryIndex,
                                'currentLibrary' => $libraryName
                            ],
                            'results' => $batchResults,
                            'orphanedResults' => $orphanedResults,
                            'totalStats' => $totalStats
                        ]);
                        exit;
                    } else {
                        // Process all shows at once
                        $result = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $currentLibraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }
                        $items = $result['data'];
                    }
                    break;

                // ===== For the TV Seasons case =====
                case 'seasons':
                    // Check if we're importing all seasons
                    $importAllSeasons = isset($_POST['importAllSeasons']) && $_POST['importAllSeasons'] === 'true';

                    if ($importAllSeasons) {
                        // Get all shows first
                        $showsResult = getAllPlexShows($plex_config['server_url'], $plex_config['token'], $currentLibraryId);
                        if (!$showsResult['success']) {
                            throw new Exception($showsResult['error']);
                        }
                        $shows = $showsResult['data'];

                        // Handle batch processing for shows to get all seasons
                        if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                            $startIndex = (int) $_POST['startIndex'];

                            // If we're processing shows in batches and handling all shows' seasons
                            if ($startIndex < count($shows)) {
                                // Process seasons for this show
                                $show = $shows[$startIndex];
                                $seasonsResult = getAllPlexSeasons($plex_config['server_url'], $plex_config['token'], $show['ratingKey']);

                                // Get running totals from previous batches if available
                                if (isset($_POST['totalSuccessful']))
                                    $totalStats['successful'] = (int) $_POST['totalSuccessful'];
                                if (isset($_POST['totalSkipped']))
                                    $totalStats['skipped'] = (int) $_POST['totalSkipped'];
                                if (isset($_POST['totalUnchanged']))
                                    $totalStats['unchanged'] = (int) $_POST['totalUnchanged'];
                                if (isset($_POST['totalRenamed']))
                                    $totalStats['renamed'] = (int) $_POST['totalRenamed'];
                                if (isset($_POST['totalFailed']))
                                    $totalStats['failed'] = (int) $_POST['totalFailed'];

                                if ($seasonsResult['success'] && !empty($seasonsResult['data'])) {
                                    $items = $seasonsResult['data'];
                                    // Process seasons for this show
                                    $batchResults = processBatch($items, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type, '', $libraryName);

                                    // Update running totals
                                    $totalStats['successful'] += $batchResults['successful'] ?? 0;
                                    $totalStats['skipped'] += $batchResults['skipped'] ?? 0;
                                    $totalStats['unchanged'] += $batchResults['unchanged'] ?? 0;
                                    $totalStats['renamed'] += $batchResults['renamed'] ?? 0;
                                    $totalStats['failed'] += $batchResults['failed'] ?? 0;

                                    if (!empty($batchResults['errors'])) {
                                        $totalStats['errors'] = array_merge($totalStats['errors'], $batchResults['errors']);
                                    }
                                } else {
                                    $items = []; // No seasons for this show
                                    $batchResults = [
                                        'successful' => 0,
                                        'skipped' => 0,
                                        'unchanged' => 0,
                                        'renamed' => 0,
                                        'failed' => 0,
                                        'errors' => [],
                                        'importedIds' => []
                                    ];
                                }

                                // Check if this is the final show we're processing in this library
                                $isComplete = ($startIndex + 1) >= count($shows);

                                // Check if we need to move to the next library when done with current one
                                $moveToNextLibrary = $isComplete && ($libraryIndex < count($libraryIds) - 1);
                                $isCompleteAll = $isComplete && !$moveToNextLibrary;

                                // Handle orphaned posters if this is the final batch of all libraries
                                $orphanedResults = null;
                                if ($isCompleteAll) {
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

                                    // Use the enhanced orphan detection that checks for missing libraries
                                    $orphanedResults = enhancedMarkOrphanedPosters(
                                        $targetDir,
                                        $allImportedIds,
                                        '--Orphaned--',
                                        '',
                                        '',
                                        'seasons',
                                        $currentLibraryId,
                                        true, // Refresh mode parameter
                                        $availableLibraries // Pass the current available libraries
                                    );

                                    // Synchronize session data to persistent storage
                                    syncSessionToStorage();

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
                                        'isComplete' => $isCompleteAll,
                                        'nextIndex' => $isComplete ? 0 : $startIndex + 1,
                                        'moveToNextLibrary' => $moveToNextLibrary,
                                        'nextLibraryIndex' => $moveToNextLibrary ? $libraryIndex + 1 : $libraryIndex,
                                        'totalLibraries' => count($libraryIds),
                                        'currentLibraryIndex' => $libraryIndex,
                                        'currentLibrary' => $libraryName,
                                        'currentShow' => $show['title'],
                                        'seasonCount' => count($items)
                                    ],
                                    'results' => $batchResults,
                                    'orphanedResults' => $orphanedResults,
                                    'totalStats' => $totalStats
                                ]);
                                exit;
                            } else {
                                // All done with this library, check if there are more libraries
                                $moveToNextLibrary = $libraryIndex < count($libraryIds) - 1;
                                $isCompleteAll = !$moveToNextLibrary;

                                echo json_encode([
                                    'success' => true,
                                    'batchComplete' => true,
                                    'progress' => [
                                        'processed' => count($shows),
                                        'total' => count($shows),
                                        'percentage' => 100,
                                        'isComplete' => $isCompleteAll,
                                        'moveToNextLibrary' => $moveToNextLibrary,
                                        'nextLibraryIndex' => $moveToNextLibrary ? $libraryIndex + 1 : $libraryIndex,
                                        'totalLibraries' => count($libraryIds),
                                        'currentLibraryIndex' => $libraryIndex,
                                        'currentLibrary' => $libraryName,
                                        'nextIndex' => 0
                                    ],
                                    'results' => [
                                        'successful' => 0,
                                        'skipped' => 0,
                                        'unchanged' => 0,
                                        'renamed' => 0,
                                        'failed' => 0,
                                        'errors' => []
                                    ],
                                    'orphanedResults' => null,
                                    'totalStats' => $totalStats
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
                            $startIndex = (int) $_POST['startIndex'];
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

                            // Check if we need to move to the next library when done with current one
                            $moveToNextLibrary = $isComplete && ($libraryIndex < count($libraryIds) - 1);
                            $isCompleteAll = $isComplete && !$moveToNextLibrary;

                            // Process the batch
                            $batchResults = processBatch($currentBatch, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type, '', $libraryName);

                            // Handle orphaned posters if this is the final batch of the final library
                            $orphanedResults = null;
                            if ($isCompleteAll) {
                                // Safely get imported IDs from current batch
                                $allImportedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds'])
                                    ? $batchResults['importedIds']
                                    : [];

                                // Retrieve IDs from previous batches with proper null checks
                                if (isset($_SESSION['import_season_ids']) && is_array($_SESSION['import_season_ids'])) {
                                    $allImportedIds = array_merge($allImportedIds, $_SESSION['import_season_ids']);
                                }

                                // Use the enhanced orphan detection that checks for missing libraries
                                $orphanedResults = enhancedMarkOrphanedPosters(
                                    $targetDir,
                                    $allImportedIds,
                                    '--Orphaned--',
                                    '',
                                    $showTitle,
                                    'seasons',
                                    $currentLibraryId,
                                    true, // Refresh mode parameter
                                    $availableLibraries // Pass the current available libraries
                                );

                                // Synchronize session data to persistent storage
                                syncSessionToStorage();

                                // Clear the session
                                unset($_SESSION['import_season_ids']);
                                // Also clean up the show title after processing is complete
                                if (isset($_SESSION['current_show_title'])) {
                                    unset($_SESSION['current_show_title']);
                                }
                            } else {
                                // Ensure we have an array in the session
                                if (!isset($_SESSION['import_season_ids']) || !is_array($_SESSION['import_season_ids'])) {
                                    $_SESSION['import_season_ids'] = [];
                                }

                                // Ensure we're merging arrays
                                $importedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds'])
                                    ? $batchResults['importedIds']
                                    : [];

                                $_SESSION['import_season_ids'] = array_merge($_SESSION['import_season_ids'], $importedIds);
                            }

                            // Get accumulated totals from previous libraries/batches if available
                            if (isset($_POST['totalSuccessful']))
                                $totalStats['successful'] = (int) $_POST['totalSuccessful'];
                            if (isset($_POST['totalSkipped']))
                                $totalStats['skipped'] = (int) $_POST['totalSkipped'];
                            if (isset($_POST['totalUnchanged']))
                                $totalStats['unchanged'] = (int) $_POST['totalUnchanged'];
                            if (isset($_POST['totalRenamed']))
                                $totalStats['renamed'] = (int) $_POST['totalRenamed'];
                            if (isset($_POST['totalFailed']))
                                $totalStats['failed'] = (int) $_POST['totalFailed'];

                            // Add current batch results to totals
                            $totalStats['successful'] += $batchResults['successful'] ?? 0;
                            $totalStats['skipped'] += $batchResults['skipped'] ?? 0;
                            $totalStats['unchanged'] += $batchResults['unchanged'] ?? 0;
                            $totalStats['renamed'] += $batchResults['renamed'] ?? 0;
                            $totalStats['failed'] += $batchResults['failed'] ?? 0;

                            // Respond with batch results and progress
                            echo json_encode([
                                'success' => true,
                                'batchComplete' => true,
                                'progress' => [
                                    'processed' => $endIndex,
                                    'total' => count($allSeasons),
                                    'percentage' => round(($endIndex / count($allSeasons)) * 100),
                                    'isComplete' => $isCompleteAll,
                                    'nextIndex' => $isComplete ? 0 : $endIndex,
                                    'moveToNextLibrary' => $moveToNextLibrary,
                                    'nextLibraryIndex' => $moveToNextLibrary ? $libraryIndex + 1 : $libraryIndex,
                                    'totalLibraries' => count($libraryIds),
                                    'currentLibraryIndex' => $libraryIndex,
                                    'currentLibrary' => $libraryName
                                ],
                                'results' => $batchResults,
                                'orphanedResults' => $orphanedResults,
                                'totalStats' => $totalStats
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

                // ===== For the Collections case =====
                case 'collections':
                    // Handle batch processing
                    if (isset($_POST['batchProcessing']) && $_POST['batchProcessing'] === 'true' && isset($_POST['startIndex'])) {
                        $startIndex = (int) $_POST['startIndex'];
                        $batchSize = $plex_config['import_batch_size'];

                        try {
                            // Get all collections using pagination
                            $result = getAllPlexCollections($plex_config['server_url'], $plex_config['token'], $currentLibraryId);
                            if (!$result['success']) {
                                throw new Exception($result['error']);
                            }

                            // Make sure we have an array of collections, never null
                            $allCollections = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];

                            // Get the library type (movie or show) for the CURRENT library only
                            $libraryType = '';
                            $librariesResult = getPlexLibraries($plex_config['server_url'], $plex_config['token']);
                            if ($librariesResult['success'] && !empty($librariesResult['data'])) {
                                foreach ($librariesResult['data'] as $lib) {
                                    if ($lib['id'] == $currentLibraryId) {
                                        $libraryType = $lib['type']; // 'movie' or 'show'
                                        $_SESSION['current_library_type'] = $libraryType; // Store in session
                                        break;
                                    }
                                }
                            }

                            logDebug("Processing collections for library {$currentLibraryId}", [
                                'libraryName' => $libraryName,
                                'libraryType' => $libraryType,
                                'collections_count' => count($allCollections)
                            ]);

                            // Process this batch - make sure we don't go out of bounds
                            $currentBatch = [];
                            if ($startIndex < count($allCollections)) {
                                $currentBatch = array_slice($allCollections, $startIndex, $batchSize);
                            }

                            $endIndex = $startIndex + count($currentBatch);
                            $isComplete = $endIndex >= count($allCollections);

                            // Check if we need to move to the next library when done with current one
                            $moveToNextLibrary = $isComplete && ($libraryIndex < count($libraryIds) - 1);
                            $isCompleteAll = $isComplete && !$moveToNextLibrary;

                            // Process the batch with library type information and library name
                            $batchResults = processBatch(
                                $currentBatch,
                                $plex_config['server_url'],
                                $plex_config['token'],
                                $targetDir,
                                $overwriteOption,
                                $type,
                                $libraryType,
                                $libraryName
                            );

                            // Ensure batchResults is properly structured
                            if (!isset($batchResults['importedIds']) || !is_array($batchResults['importedIds'])) {
                                $batchResults['importedIds'] = [];
                            }

                            // Create a collection-specific key for session storage that includes library type
                            $collectionSessionKey = 'import_collection_ids_' . $libraryType;

                            // Handle orphaned posters if this is the final batch
                            $orphanedResults = null;
                            if ($isCompleteAll) {
                                // Only run orphan detection on the very last library of a multi-library import

                                // Safely get imported IDs from current batch
                                $allImportedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds'])
                                    ? $batchResults['importedIds']
                                    : [];

                                // Retrieve IDs from previous batches with proper null checks - use type-specific key
                                if (isset($_SESSION[$collectionSessionKey]) && is_array($_SESSION[$collectionSessionKey])) {
                                    $allImportedIds = array_merge($allImportedIds, $_SESSION[$collectionSessionKey]);
                                }

                                // Store these IDs in persistent storage
                                storeValidIds($allImportedIds, $mediaType, $currentLibraryId, true);

                                // Use the enhanced orphan detection that checks for missing libraries
                                $orphanedResults = enhancedMarkOrphanedPosters(
                                    $targetDir,
                                    $allImportedIds,
                                    '--Orphaned--',
                                    $libraryType,
                                    '',
                                    'collections',
                                    $currentLibraryId,
                                    true, // Refresh mode parameter
                                    $availableLibraries // Pass the current available libraries
                                );

                                // Synchronize session data to persistent storage
                                syncSessionToStorage();

                                // Clear only the temporary session for the current import
                                unset($_SESSION[$collectionSessionKey]);

                                // Also clean up the library type after processing is complete
                                if (isset($_SESSION['current_library_type'])) {
                                    unset($_SESSION['current_library_type']);
                                }
                            } else {
                                // For middle libraries in a multi-library import, DON'T run orphan detection yet
                                // Just accumulate the IDs for the final detection

                                // Ensure we have an array in the session for this library type
                                if (!isset($_SESSION[$collectionSessionKey]) || !is_array($_SESSION[$collectionSessionKey])) {
                                    $_SESSION[$collectionSessionKey] = [];
                                }

                                // Ensure we're merging arrays, with proper null checks
                                $importedIds = isset($batchResults['importedIds']) && is_array($batchResults['importedIds'])
                                    ? $batchResults['importedIds']
                                    : [];

                                $_SESSION[$collectionSessionKey] = array_merge($_SESSION[$collectionSessionKey], $importedIds);

                                // IMPORTANT: Still store the valid IDs in persistent storage
                                storeValidIds($importedIds, $mediaType, $currentLibraryId, true);

                                // Set empty orphan results to avoid null/undefined issues
                                $orphanedResults = [
                                    'orphaned' => 0,
                                    'unmarked' => 0,
                                    'details' => []
                                ];
                            }

                            // Get accumulated totals from previous libraries/batches if available
                            if (isset($_POST['totalSuccessful']))
                                $totalStats['successful'] = (int) $_POST['totalSuccessful'];
                            if (isset($_POST['totalSkipped']))
                                $totalStats['skipped'] = (int) $_POST['totalSkipped'];
                            if (isset($_POST['totalUnchanged']))
                                $totalStats['unchanged'] = (int) $_POST['totalUnchanged'];
                            if (isset($_POST['totalRenamed']))
                                $totalStats['renamed'] = (int) $_POST['totalRenamed'];
                            if (isset($_POST['totalFailed']))
                                $totalStats['failed'] = (int) $_POST['totalFailed'];

                            // Add current batch results to totals
                            $totalStats['successful'] += $batchResults['successful'] ?? 0;
                            $totalStats['skipped'] += $batchResults['skipped'] ?? 0;
                            $totalStats['unchanged'] += $batchResults['unchanged'] ?? 0;
                            $totalStats['renamed'] += $batchResults['renamed'] ?? 0;
                            $totalStats['failed'] += $batchResults['failed'] ?? 0;

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
                                    'isComplete' => $isCompleteAll,
                                    'nextIndex' => $isComplete ? 0 : $endIndex,
                                    'moveToNextLibrary' => $moveToNextLibrary,
                                    'nextLibraryIndex' => $moveToNextLibrary ? $libraryIndex + 1 : $libraryIndex,
                                    'totalLibraries' => count($libraryIds),
                                    'currentLibraryIndex' => $libraryIndex,
                                    'currentLibrary' => $libraryName
                                ],
                                'results' => $batchResults,
                                'orphanedResults' => $orphanedResults,
                                'totalStats' => $totalStats
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
                        // Process all collections at once (non-batch method)
                        $result = getAllPlexCollections($plex_config['server_url'], $plex_config['token'], $currentLibraryId);
                        if (!$result['success']) {
                            throw new Exception($result['error']);
                        }

                        // Get the library type (movie or show) for collection labeling
                        $libraryType = '';
                        $librariesResult = getPlexLibraries($plex_config['server_url'], $plex_config['token']);
                        if ($librariesResult['success'] && !empty($librariesResult['data'])) {
                            foreach ($librariesResult['data'] as $lib) {
                                if ($lib['id'] == $currentLibraryId) {
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
            $results = processBatch($items, $plex_config['server_url'], $plex_config['token'], $targetDir, $overwriteOption, $type, $libraryType ?? '', $libraryName);

            // For non-batch processing, also use enhanced orphan detection
            $orphanedResults = enhancedMarkOrphanedPosters(
                $targetDir,
                isset($results['importedIds']) ? $results['importedIds'] : [],
                '--Orphaned--',
                $libraryType ?? '',
                $showTitle ?? '',
                $type,
                $currentLibraryId,
                true,
                $availableLibraries // Pass the current available libraries
            );

            echo json_encode([
                'success' => true,
                'complete' => true,
                'processed' => count($items),
                'results' => $results,
                'orphanedResults' => $orphanedResults,
                'totalStats' => [
                    'successful' => $results['successful'],
                    'skipped' => $results['skipped'],
                    'unchanged' => $results['unchanged'],
                    'renamed' => $results['renamed'],
                    'failed' => $results['failed'],
                    'orphaned' => ($orphanedResults['orphaned'] ?? 0) + ($orphanedResults['unmarked'] ?? 0)
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