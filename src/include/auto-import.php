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


// Ensure this script can only be run from CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// Basic setup
define('BASE_PATH', dirname(__DIR__));
define('INCLUDE_PATH', BASE_PATH . '/include');
define('LOG_PATH', BASE_PATH . '/data');

// Set up error handling
ini_set('display_errors', 1);
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

// Make sure log directory exists
if (!is_dir(LOG_PATH)) {
    mkdir(LOG_PATH, 0755, true);
}

// Load required files
require_once INCLUDE_PATH . '/config.php';
require_once INCLUDE_PATH . '/plex-id-storage.php';
require_once INCLUDE_PATH . '/library-tracking.php';

// Default auto-import configuration, will be overridden if set in config.php
if (!isset($auto_import_config)) {
    $auto_import_config = [
        'enabled' => true,
        'schedule' => '24h',  // Default to 24 hours
        'import_movies' => true,
        'import_shows' => true,
        'import_seasons' => true,
        'import_collections' => true
    ];
}

// Initialize a lock file to prevent multiple instances
$lockFile = LOG_PATH . '/auto-import.lock';
$logFile = LOG_PATH . '/auto-import.log';

// Define logDebug function if it doesn't exist
if (!function_exists('logDebug')) {
    function logDebug($message, $data = null)
    {
        logMessage("DEBUG: $message", $data);
    }
}

// Log function
function logMessage($message, $data = null)
{
    global $logFile;

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}";

    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $logEntry .= "\nData: " . print_r($data, true);
        } else {
            $logEntry .= "\nData: {$data}";
        }
    }

    $logEntry .= "\n";

    // Log to file
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    // Also output to console
    echo $logEntry;
}

// Check for lock file
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    $currentTime = time();

    // If lock file is older than 2 hours, it might be stale
    if (($currentTime - $lockTime) < 7200) {
        logMessage("Another import process is already running. Exiting.");
        exit(0);
    } else {
        logMessage("Found a stale lock file. Removing and continuing.");
        unlink($lockFile);
    }
}

// Create lock file
file_put_contents($lockFile, date('Y-m-d H:i:s'));

// Register cleanup function to remove lock file when script exits
register_shutdown_function(function () use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
    logMessage("Import process completed.");
});

/**
 * Check if an import should run based on the last run timestamp and scheduled interval
 */
function shouldRunImport()
{
    global $auto_import_config;

    $lastRunFile = LOG_PATH . '/last_auto_import.txt';

    // If the last run file doesn't exist, we should run
    if (!file_exists($lastRunFile)) {
        return true;
    }

    // Read the last run timestamp
    $lastRun = (int) file_get_contents($lastRunFile);
    $currentTime = time();

    // Get the interval in seconds
    $intervalSeconds = parseInterval($auto_import_config['schedule']);

    // Check if enough time has passed
    return ($currentTime - $lastRun) >= $intervalSeconds;
}

/**
 * Update the last run timestamp
 */
function updateLastRun()
{
    $lastRunFile = LOG_PATH . '/last_auto_import.txt';
    file_put_contents($lastRunFile, time());
}

/**
 * Parse a human-readable interval like "24h" into seconds
 */
function parseInterval($interval)
{
    $number = (int) $interval;
    $unit = substr($interval, -1);

    switch ($unit) {
        case 'h': // hours
            return $number * 3600;
        case 'd': // days
            return $number * 86400;
        case 'w': // weeks
            return $number * 604800;
        case 'm': // minutes
            return $number * 60;
        default:
            return 86400; // default to 24 hours
    }
}

/**
 * Make an API request using cURL
 */
function makeApiRequest($url, $headers, $expectJson = true)
{
    global $plex_config;

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
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("API request failed: " . $error);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("API request returned HTTP code: " . $httpCode);
    }

    // Validate JSON if expected
    if ($expectJson) {
        $jsonTest = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg());
        }
    }

    return $response;
}

/**
 * Get Plex headers for API requests
 */
function getPlexHeaders($token, $start = 0, $size = 50)
{
    return [
        'Accept: application/json',
        'X-Plex-Token: ' . $token,
        'X-Plex-Client-Identifier: Posteria-AutoImport',
        'X-Plex-Product: Posteria',
        'X-Plex-Version: 1.0',
        'X-Plex-Container-Start: ' . $start,
        'X-Plex-Container-Size: ' . $size
    ];
}

/**
 * Get all Plex libraries
 */
function getPlexLibraries($serverUrl, $token)
{
    try {
        $url = rtrim($serverUrl, '/') . "/library/sections";
        $headers = getPlexHeaders($token);

        $response = makeApiRequest($url, $headers);
        $data = json_decode($response, true);

        if (!isset($data['MediaContainer']['Directory'])) {
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

        return ['success' => true, 'data' => $libraries];
    } catch (Exception $e) {
        logMessage("Error getting Plex libraries: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Update the getAllPlexMovies function to include addedAt timestamps
 */
function getAllPlexMovies($serverUrl, $token, $libraryId)
{
    $allMovies = [];
    $start = 0;
    $size = 50;
    $moreAvailable = true;

    while ($moreAvailable) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/all";
            $headers = getPlexHeaders($token, $start, $size);

            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);

            if (!isset($data['MediaContainer']['Metadata'])) {
                break;
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
                        'addedAt' => $movie['addedAt'] ?? '' // Include addedAt timestamp
                    ];
                }
            }

            $allMovies = array_merge($allMovies, $movies);

            // Check if there are more movies to fetch
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($movies);
            $moreAvailable = ($start + count($movies)) < $totalSize;
            $start += $size;

            if (count($movies) === 0) {
                $moreAvailable = false;
            }
        } catch (Exception $e) {
            logMessage("Error fetching movies: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    return ['success' => true, 'data' => $allMovies];
}

/**
 * Update the getAllPlexShows function to include addedAt timestamps
 */
function getAllPlexShows($serverUrl, $token, $libraryId)
{
    $allShows = [];
    $start = 0;
    $size = 50;
    $moreAvailable = true;

    while ($moreAvailable) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/all";
            $headers = getPlexHeaders($token, $start, $size);

            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);

            if (!isset($data['MediaContainer']['Metadata'])) {
                break;
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
                        'addedAt' => $show['addedAt'] ?? '' // Include addedAt timestamp
                    ];
                }
            }

            $allShows = array_merge($allShows, $shows);

            // Check if there are more shows to fetch
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($shows);
            $moreAvailable = ($start + count($shows)) < $totalSize;
            $start += $size;

            if (count($shows) === 0) {
                $moreAvailable = false;
            }
        } catch (Exception $e) {
            logMessage("Error fetching shows: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    return ['success' => true, 'data' => $allShows];
}

/**
 * Update the getAllPlexSeasons function to include addedAt timestamps
 */
function getAllPlexSeasons($serverUrl, $token, $showKey)
{
    $allSeasons = [];
    $start = 0;
    $size = 50;
    $moreAvailable = true;

    while ($moreAvailable) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/metadata/{$showKey}/children";
            $headers = getPlexHeaders($token, $start, $size);

            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);

            if (!isset($data['MediaContainer']['Metadata'])) {
                break;
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
                        'addedAt' => $season['addedAt'] ?? '' // Include addedAt timestamp
                    ];
                }
            }

            $allSeasons = array_merge($allSeasons, $seasons);

            // Check if there are more seasons to fetch
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($seasons);
            $moreAvailable = ($start + count($seasons)) < $totalSize;
            $start += $size;

            if (count($seasons) === 0) {
                $moreAvailable = false;
            }
        } catch (Exception $e) {
            logMessage("Error fetching seasons: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    return ['success' => true, 'data' => $allSeasons];
}

/**
 * Update the getAllPlexCollections function to include addedAt timestamps
 */
function getAllPlexCollections($serverUrl, $token, $libraryId)
{
    $allCollections = [];
    $start = 0;
    $size = 50;
    $moreAvailable = true;

    while ($moreAvailable) {
        try {
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/collections";
            $headers = getPlexHeaders($token, $start, $size);

            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);

            if (!isset($data['MediaContainer']['Metadata'])) {
                // Collections might be empty
                break;
            }

            $collections = [];
            foreach ($data['MediaContainer']['Metadata'] as $collection) {
                if (isset($collection['thumb'])) {
                    $collections[] = [
                        'title' => $collection['title'],
                        'id' => $collection['ratingKey'],
                        'thumb' => $collection['thumb'],
                        'ratingKey' => $collection['ratingKey'],
                        'addedAt' => $collection['addedAt'] ?? '' // Include addedAt timestamp
                    ];
                }
            }

            $allCollections = array_merge($allCollections, $collections);

            // Check if there are more collections to fetch
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($collections);
            $moreAvailable = ($start + count($collections)) < $totalSize;
            $start += $size;

            if (count($collections) === 0) {
                $moreAvailable = false;
            }
        } catch (Exception $e) {
            logMessage("Error fetching collections: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    return ['success' => true, 'data' => $allCollections];
}

/**
 * Download and save a Plex image
 */
function downloadPlexImage($serverUrl, $token, $thumb, $targetPath)
{
    try {
        $url = rtrim($serverUrl, '/') . $thumb;
        $headers = getPlexHeaders($token);

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

/**
 * Find existing poster file by rating key
 */
function findExistingPosterByRatingKey($directory, $ratingKey)
{
    if (!is_dir($directory)) {
        return false;
    }

    $files = glob($directory . '/*');
    foreach ($files as $file) {
        if (is_file($file) && strpos($file, "--Plex--") !== false) {
            $pattern = '/\[' . preg_quote($ratingKey, '/') . '\]/';
            if (preg_match($pattern, basename($file))) {
                return basename($file);
            }
        }
    }

    return false;
}

/**
 * Updated generatePlexFilename function to include addedAt timestamp
 */
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
 * Sanitize filename to remove invalid characters
 */
function sanitizeFilename($filename)
{
    // Remove only characters that are unsafe for filenames
    // Unsafe characters: / \ : * ? " < > |
    $filename = preg_replace('/[\/\\\:\*\?"<>\|]/', '', $filename);

    // Replace multiple spaces with a single space
    $filename = preg_replace('/\s+/', ' ', $filename);

    // Trim the result
    return trim($filename);
}

/**
 * Process multiple libraries without orphaning posters from other libraries
 * 
 * This function should be called at the beginning of the auto-import process
 * before processing individual libraries to gather all valid IDs
 */
function gatherAllValidIds($serverUrl, $token)
{
    global $_SESSION;

    logMessage("Gathering all valid IDs from all libraries to prevent incorrect orphaning");

    // Initialize container for all valid IDs by media type
    $allValidIdsByType = [
        'movies' => [],
        'shows' => [],
        'seasons' => [],
        'collections' => []
    ];

    // Get all libraries from Plex
    $libraries = getPlexLibraries($serverUrl, $token);

    if (!$libraries['success'] || empty($libraries['data'])) {
        logMessage("Failed to retrieve libraries from Plex or no libraries found.");
        return $allValidIdsByType;
    }

    $libraryCount = count($libraries['data']);
    logMessage("Found {$libraryCount} libraries to scan for valid IDs");

    // Process each library to gather IDs but not import yet
    foreach ($libraries['data'] as $library) {
        $libraryId = $library['id'];
        $libraryName = $library['title'];
        $libraryType = $library['type'];

        logMessage("Scanning library: {$libraryName} (ID: {$libraryId}, Type: {$libraryType})");

        // Gather movie IDs
        if ($libraryType === 'movie') {
            $result = getAllPlexMovies($serverUrl, $token, $libraryId);
            if ($result['success'] && !empty($result['data'])) {
                foreach ($result['data'] as $movie) {
                    if (isset($movie['id'])) {
                        $allValidIdsByType['movies'][] = $movie['id'];
                    }
                }
                logMessage("Added " . count($result['data']) . " movie IDs from {$libraryName}");
            }

            // Gather collection IDs for this library
            $collectionsResult = getAllPlexCollections($serverUrl, $token, $libraryId);
            if ($collectionsResult['success'] && !empty($collectionsResult['data'])) {
                foreach ($collectionsResult['data'] as $collection) {
                    if (isset($collection['id'])) {
                        $allValidIdsByType['collections'][] = $collection['id'];
                    }
                }
                logMessage("Added " . count($collectionsResult['data']) . " collection IDs from {$libraryName}");
            }
        }

        // Gather show IDs
        if ($libraryType === 'show') {
            $result = getAllPlexShows($serverUrl, $token, $libraryId);
            if ($result['success'] && !empty($result['data'])) {
                foreach ($result['data'] as $show) {
                    if (isset($show['id'])) {
                        $allValidIdsByType['shows'][] = $show['id'];
                    }
                }
                logMessage("Added " . count($result['data']) . " show IDs from {$libraryName}");

                // Gather season IDs for each show
                foreach ($result['data'] as $show) {
                    $showKey = $show['ratingKey'];
                    $seasonsResult = getAllPlexSeasons($serverUrl, $token, $showKey);
                    if ($seasonsResult['success'] && !empty($seasonsResult['data'])) {
                        foreach ($seasonsResult['data'] as $season) {
                            if (isset($season['id'])) {
                                $allValidIdsByType['seasons'][] = $season['id'];
                            }
                        }
                    }
                }
                logMessage("Added " . count($allValidIdsByType['seasons']) . " season IDs from {$libraryName}");
            }

            // Gather collection IDs for this library
            $collectionsResult = getAllPlexCollections($serverUrl, $token, $libraryId);
            if ($collectionsResult['success'] && !empty($collectionsResult['data'])) {
                foreach ($collectionsResult['data'] as $collection) {
                    if (isset($collection['id'])) {
                        $allValidIdsByType['collections'][] = $collection['id'];
                    }
                }
                logMessage("Added " . count($collectionsResult['data']) . " collection IDs from {$libraryName}");
            }
        }
    }

    // Store all valid IDs in session for later use
    foreach ($allValidIdsByType as $mediaType => $ids) {
        if (!empty($ids)) {
            logMessage("Storing " . count($ids) . " valid IDs for $mediaType");
            $_SESSION['all_valid_' . $mediaType . '_ids'] = $ids;
        }
    }

    return $allValidIdsByType;
}

/**
 * Helper function to check if a media ID is valid using both our gathered IDs and the storage system
 * 
 * @param string $mediaType Type of media (movies, shows, seasons, collections)
 * @param string $id The media ID to check
 * @return bool True if the ID is valid, false otherwise
 */
function isValidMediaId($mediaType, $id)
{
    global $_SESSION;

    // First, check in the pre-gathered IDs
    $allValidIdsKey = 'all_valid_' . $mediaType . '_ids';
    if (isset($_SESSION[$allValidIdsKey]) && is_array($_SESSION[$allValidIdsKey])) {
        if (in_array($id, $_SESSION[$allValidIdsKey])) {
            return true;
        }
    }

    // Then, check in the persistent storage system
    $persistentIds = getAllValidIds($mediaType);
    return in_array($id, $persistentIds);
}

/**
 * Modified processBatch function to better handle collections timestamp renaming
 */
function processBatch($items, $serverUrl, $token, $targetDir, $mediaType = '', $libraryType = '', $libraryName = '')
{
    $results = [
        'successful' => 0,
        'skipped' => 0,
        'unchanged' => 0,
        'renamed' => 0,
        'failed' => 0,
        'errors' => [],
        'importedIds' => []
    ];

    if (!is_array($items)) {
        logMessage("Error: items is not an array in processBatch");
        return $results;
    }

    // Define overwrite option (forced to 'overwrite' in this auto-import script)
    $overwriteOption = 'overwrite';

    // Process all items
    $totalItems = count($items);

    foreach ($items as $item) {
        if (!isset($item['title']) || !isset($item['id']) || !isset($item['thumb'])) {
            logMessage("Skipping malformed item in batch");
            continue;
        }

        $title = $item['title'];
        $id = $item['id'];
        $thumb = $item['thumb'];
        $year = isset($item['year']) ? $item['year'] : '';
        $addedAt = isset($item['addedAt']) ? $item['addedAt'] : ''; // Extract addedAt timestamp

        // Track that we've seen this ID regardless of any other processing
        $results['importedIds'][] = $id;

        // Check for existing file
        $existingFile = findExistingPosterByRatingKey($targetDir, $id);

        // Generate target filename with library name, year for movies, and addedAt timestamp
        $extension = 'jpg';
        $filename = generatePlexFilename($title, $id, $extension, $mediaType, $libraryType, $libraryName, $year, $addedAt);
        $targetPath = $targetDir . $filename;

        // Handle existing file
        if ($existingFile) {
            $oldPath = $targetDir . $existingFile;

            // If filenames are different, we may need to rename
            if ($existingFile !== $filename) {
                // Check if file needs an update: library name, year, or missing timestamp
                $needsUpdate = false;

                // Check if the timestamp is missing in the old filename
                $hasTimestamp = preg_match('/\(A\d{8,12}\)/', $existingFile);

                // Special aggressive check for collections - if it's a collection without timestamp, always update
                if ($mediaType === 'collections' && !$hasTimestamp && !empty($addedAt)) {
                    $needsUpdate = true;
                    logDebug("Collection needs timestamp update: $existingFile");
                }
                // For other media types, or if it's not a timestamp issue for collections
                else if (!$hasTimestamp && !empty($addedAt)) {
                    $needsUpdate = true;
                    logDebug("File needs timestamp update: $existingFile");
                }

                // For standard items (not collections)
                if ($mediaType !== 'collections') {
                    // Check if library name tag doesn't exist in old file but does in new file
                    $libraryNameTag = "[[{$libraryName}]]";
                    if (strpos($filename, $libraryNameTag) !== false && strpos($existingFile, $libraryNameTag) === false) {
                        $needsUpdate = true;
                    }

                    // Check if year tag doesn't exist in old file but does in new file
                    if ($mediaType === 'movies' && !empty($year)) {
                        $yearTag = "({$year})";
                        if (strpos($filename, $yearTag) !== false && strpos($existingFile, $yearTag) === false) {
                            $needsUpdate = true;
                        }
                    }
                }

                // For collections
                if ($mediaType === 'collections') {
                    // Check if library type marker exists
                    $libraryTypeMarker = ($libraryType === 'movie') ? "(Movies)" : "(TV)";
                    if (strpos($filename, $libraryTypeMarker) !== false && strpos($existingFile, $libraryTypeMarker) === false) {
                        $needsUpdate = true;
                    }
                }

                // Perform rename if needed
                if ($needsUpdate) {
                    logDebug("Renaming file to update information: $existingFile -> $filename");

                    if (rename($oldPath, $targetPath)) {
                        $results['renamed']++;
                        $results['successful']++;
                        logDebug("Successfully renamed file for $title (ID: $id)");
                        continue;
                    } else {
                        logDebug("Failed to rename file for $title (ID: $id)");
                        $results['failed']++;
                        $results['errors'][] = "Failed to rename file for {$title}";
                    }
                } else {
                    // Not a rename case, treat as normal
                    logDebug("Not a rename case for $existingFile (ID: $id)");
                }
            } else {
                // File exists and already has the right name
                // Handle file download/update based on overwrite option
                if ($overwriteOption === 'overwrite') {
                    logDebug("Overwriting existing file with same name: $filename");
                    $downloadResult = downloadPlexImage($serverUrl, $token, $thumb, $targetPath);

                    if ($downloadResult['success']) {
                        $results['successful']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Failed to download {$title}: {$downloadResult['error']}";
                    }
                } else {
                    // Skip the file since it exists and overwrite is not set
                    $results['skipped']++;
                }
                continue;
            }
        }

        // If we get here, either there was no existing file, or we couldn't rename
        // File doesn't exist, download it
        $downloadResult = downloadPlexImage($serverUrl, $token, $thumb, $targetPath);

        if ($downloadResult['success']) {
            $results['successful']++;
        } else {
            $results['failed']++;
            $results['errors'][] = "Failed to download {$title}: {$downloadResult['error']}";
        }
    }

    return $results;
}

/**
 * Updated handleOrphanedPosters function to properly handle library names
 */
function handleOrphanedPosters(
    $targetDir,
    $currentImportIds,
    $orphanedTag = '--Orphaned--',
    $libraryType = '',
    $showTitle = '',
    $mediaType = '',
    $libraryId = ''
) {
    $results = [
        'orphaned' => 0,
        'oldFormat' => 0, // Counter for files without timestamps
        'unmarked' => 0,
        'details' => []
    ];

    if (!is_dir($targetDir)) {
        logMessage("Target directory does not exist: {$targetDir}");
        return $results;
    }

    // Skip orphaning process if we're not storing IDs
    if (empty($libraryId) || empty($mediaType)) {
        logMessage("Skipping orphan check because libraryId or mediaType is empty");
        return $results;
    }

    // Get library name for filtering if this is a single library import
    $libraryName = '';
    if (!empty($libraryId)) {
        // Try to get the library name from the stored libraries
        $libraries = getPlexLibraries($GLOBALS['plex_config']['server_url'], $GLOBALS['plex_config']['token']);
        if ($libraries['success'] && !empty($libraries['data'])) {
            foreach ($libraries['data'] as $lib) {
                if ($lib['id'] == $libraryId) {
                    $libraryName = $lib['title'];
                    break;
                }
            }
        }
    }

    // Log the current import IDs for debugging
    logDebug("Current import IDs for library $libraryId count: " . count($currentImportIds));
    if (!empty($currentImportIds)) {
        logDebug("First few import IDs: " . implode(", ", array_slice($currentImportIds, 0, 5)));
    }

    // Store the current import IDs for this library
    if (!empty($currentImportIds)) {
        // Using the existing storage function from plex-id-storage.php
        logDebug("Storing " . count($currentImportIds) . " valid IDs for $mediaType library $libraryId");
        storeValidIds($currentImportIds, $mediaType, $libraryId, true);

        // Make sure to sync to storage
        syncSessionToStorage();
    }

    // Get all files in the directory
    $files = glob($targetDir . '/*');
    if (empty($files)) {
        logMessage("No files found in directory: {$targetDir}");
        return $results;
    }

    logDebug("Found " . count($files) . " files in $targetDir");

    // Process files and check for orphans - in two passes
    $plexTag = '--Plex--';
    $processedFiles = [];

    // First pass: Standard orphan detection (check if IDs are valid)
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

        // Extract the ID from the filename
        $idMatch = [];
        if (preg_match('/\[([a-f0-9]+)\]/', $filename, $idMatch)) {
            $fileId = $idMatch[1];

            // Check against ALL valid IDs using our helper function
            if (!isValidMediaId($mediaType, $fileId)) {
                // Don't mark as orphaned if it's in the current import
                if (!in_array($fileId, $currentImportIds)) {
                    logDebug("Marking as orphaned: $filename (ID: $fileId not found in valid IDs)");

                    // Replace --Plex-- with --Orphaned-- in the filename
                    $newFilename = str_replace($plexTag, $orphanedTag, $filename);
                    $newPath = $targetDir . '/' . $newFilename;

                    if (rename($file, $newPath)) {
                        $results['orphaned']++;
                        $results['details'][] = [
                            'oldName' => $filename,
                            'newName' => $newFilename,
                            'reason' => 'id_not_valid'
                        ];
                        $processedFiles[$file] = true;
                    } else {
                        $results['unmarked']++;
                    }
                } else {
                    logDebug("ID $fileId found in current import IDs, not marking as orphaned");
                }
            }
        }
    }

    // Special handling for collections in single-library mode
    $isCollections = ($mediaType === 'collections');

    if ($isCollections) {
        logDebug("Special collections handling for orphan detection");

        // Process all files again to look for missing timestamps in collections
        foreach ($files as $file) {
            if (!is_file($file) || isset($processedFiles[$file])) {
                continue;
            }

            $filename = basename($file);

            // Skip already orphaned files
            if (strpos($filename, $orphanedTag) !== false) {
                continue;
            }

            // Skip non-Plex files
            if (strpos($filename, $plexTag) === false) {
                continue;
            }

            // Check for the correct collection type if libraryType is specified
            if (!empty($libraryType)) {
                $isMovieCollection = strpos($filename, '(Movies)') !== false;
                $isShowCollection = strpos($filename, '(TV)') !== false;

                // Skip collections that don't match the library type
                if (
                    ($libraryType === 'movie' && !$isMovieCollection) ||
                    ($libraryType === 'show' && !$isShowCollection)
                ) {
                    continue;
                }
            }

            // Check if the file has a timestamp
            $hasTimestamp = preg_match('/\(A\d{8,12}\)/', $filename);

            if (!$hasTimestamp) {
                // Mark as orphaned if it doesn't have a timestamp
                $newFilename = str_replace($plexTag, $orphanedTag, $filename);
                $newPath = $targetDir . '/' . $newFilename;

                logDebug("Found collection without timestamp: $filename");

                if (rename($file, $newPath)) {
                    $results['orphaned']++;
                    $results['oldFormat']++;
                    $results['details'][] = [
                        'oldName' => $filename,
                        'newName' => $newFilename,
                        'reason' => 'missing_timestamp'
                    ];

                    logDebug("Marked collection as orphaned due to missing timestamp: $filename");
                } else {
                    $results['unmarked']++;
                }
            }
        }
    } else {
        // For non-collections media types
        // Second pass: Mark files without timestamps as orphaned
        foreach ($files as $file) {
            if (!is_file($file) || isset($processedFiles[$file])) {
                continue;
            }

            $filename = basename($file);

            // Skip already orphaned files
            if (strpos($filename, $orphanedTag) !== false) {
                continue;
            }

            // Skip non-Plex files
            if (strpos($filename, $plexTag) === false) {
                continue;
            }

            // Check if file should be processed based on media type and library
            // Using the fixed shouldProcessFile function with the retrieved library name
            if (!shouldProcessFile($filename, $mediaType, !empty($libraryName), $libraryName, $libraryType, $showTitle)) {
                continue;
            }

            // Check if the file has a timestamp
            $hasTimestamp = preg_match('/\(A\d{8,12}\)/', $filename);

            if (!$hasTimestamp) {
                // Mark as orphaned if it doesn't have a timestamp
                $newFilename = str_replace($plexTag, $orphanedTag, $filename);
                $newPath = $targetDir . '/' . $newFilename;

                logDebug("Found file without timestamp: $filename");

                if (rename($file, $newPath)) {
                    $results['orphaned']++;
                    $results['oldFormat']++;
                    $results['details'][] = [
                        'oldName' => $filename,
                        'newName' => $newFilename,
                        'reason' => 'missing_timestamp'
                    ];

                    logDebug("Marked as orphaned due to missing timestamp: $filename");
                } else {
                    $results['unmarked']++;
                }
            }
        }
    }

    logDebug("Orphan detection complete: " .
        $results['orphaned'] . " orphaned, " .
        $results['oldFormat'] . " marked orphaned due to missing timestamp");

    return $results;
}

/**
 * Helper function to determine if a file should be processed
 * This centralizes the library and show filtering logic
 */
function shouldProcessFile($filename, $mediaType, $isSingleLibrary, $libraryName = '', $libraryType = '', $showTitle = '')
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
        else if (strpos($filename, "[[" . $libraryName . "]]") === false) {
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
 * Import movies for a library
 */
function importMovies($serverUrl, $token, $libraryId, $libraryName)
{
    logMessage("Importing movies from library: {$libraryName}");

    $targetDir = BASE_PATH . '/posters/movies/';

    // Ensure directory exists
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            logMessage("Failed to create directory: {$targetDir}");
            return false;
        }
    }

    // Get all movies
    $result = getAllPlexMovies($serverUrl, $token, $libraryId);
    if (!$result['success']) {
        logMessage("Failed to get movies: " . $result['error']);
        return false;
    }

    logMessage("Found " . count($result['data']) . " movies in library: {$libraryName}");

    // Process the movies
    $batchResults = processBatch($result['data'], $serverUrl, $token, $targetDir, 'movies', '', $libraryName);

    // Handle orphaned posters
    $orphanedResults = handleOrphanedPosters(
        $targetDir,
        $batchResults['importedIds'],
        '--Orphaned--',
        '',
        '',
        'movies',
        $libraryId
    );

    // Log the results
    logMessage("Movie import results for {$libraryName}: " .
        "Processed: " . count($result['data']) . ", " .
        "Successful: " . $batchResults['successful'] . ", " .
        "Renamed: " . $batchResults['renamed'] . ", " .
        "Failed: " . $batchResults['failed'] . ", " .
        "Orphaned: " . $orphanedResults['orphaned']);

    return true;
}

/**
 * Import TV shows for a library
 */
function importShows($serverUrl, $token, $libraryId, $libraryName)
{
    logMessage("Importing TV shows from library: {$libraryName}");

    $targetDir = BASE_PATH . '/posters/tv-shows/';

    // Ensure directory exists
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            logMessage("Failed to create directory: {$targetDir}");
            return false;
        }
    }

    // Get all shows
    $result = getAllPlexShows($serverUrl, $token, $libraryId);
    if (!$result['success']) {
        logMessage("Failed to get shows: " . $result['error']);
        return false;
    }

    logMessage("Found " . count($result['data']) . " TV shows in library: {$libraryName}");

    // Process the shows
    $batchResults = processBatch($result['data'], $serverUrl, $token, $targetDir, 'shows', '', $libraryName);

    // Handle orphaned posters
    $orphanedResults = handleOrphanedPosters(
        $targetDir,
        $batchResults['importedIds'],
        '--Orphaned--',
        '',
        '',
        'shows',
        $libraryId
    );

    // Log the results
    logMessage("TV show import results for {$libraryName}: " .
        "Processed: " . count($result['data']) . ", " .
        "Successful: " . $batchResults['successful'] . ", " .
        "Renamed: " . $batchResults['renamed'] . ", " .
        "Failed: " . $batchResults['failed'] . ", " .
        "Orphaned: " . $orphanedResults['orphaned']);

    return true;
}

/**
 * Import seasons for all shows in a library
 */
function importAllSeasons($serverUrl, $token, $libraryId, $libraryName)
{
    logMessage("Importing TV seasons from library: {$libraryName}");

    $targetDir = BASE_PATH . '/posters/tv-seasons/';

    // Ensure directory exists
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            logMessage("Failed to create directory: {$targetDir}");
            return false;
        }
    }

    // Get all shows first
    $showsResult = getAllPlexShows($serverUrl, $token, $libraryId);
    if (!$showsResult['success']) {
        logMessage("Failed to get shows: " . $showsResult['error']);
        return false;
    }

    $shows = $showsResult['data'];
    $totalShows = count($shows);
    $processedShows = 0;
    $allSeasonIds = [];

    logMessage("Found {$totalShows} shows to process for seasons");

    // Process each show
    foreach ($shows as $show) {
        $processedShows++;
        $showKey = $show['ratingKey'];
        $showTitle = $show['title'];

        // Get all seasons for this show
        $seasonsResult = getAllPlexSeasons($serverUrl, $token, $showKey);
        if (!$seasonsResult['success']) {
            logMessage("Error fetching seasons for show {$showTitle}");
            continue;
        }

        $seasons = $seasonsResult['data'];
        if (empty($seasons)) {
            logMessage("No seasons found for show: {$showTitle}");
            continue;
        }

        // Process the seasons
        $batchResults = processBatch($seasons, $serverUrl, $token, $targetDir, 'seasons', '', $libraryName);

        // Collect all season IDs
        if (!empty($batchResults['importedIds'])) {
            $allSeasonIds = array_merge($allSeasonIds, $batchResults['importedIds']);
        }
    }

    // Handle orphaned season posters at the end
    $orphanedResults = handleOrphanedPosters(
        $targetDir,
        $allSeasonIds,
        '--Orphaned--',
        '',
        '',
        'seasons',
        $libraryId
    );

    // Log the results
    logMessage("Season import results for {$libraryName}: " .
        "Processed shows: " . $processedShows . "/" . $totalShows . ", " .
        "Total seasons: " . count($allSeasonIds) . ", " .
        "Orphaned: " . $orphanedResults['orphaned']);

    return true;
}

/**
 * Add a special pre-processing function specifically for collections
 * This function runs before any orphan detection to fix collections that need timestamps
 */
function updateCollectionsWithTimestamps($serverUrl, $token, $libraryId, $libraryName, $libraryType)
{
    $targetDir = BASE_PATH . '/posters/collections/';
    $results = [
        'updated' => 0,
        'failed' => 0
    ];

    // Skip if the directory doesn't exist
    if (!is_dir($targetDir)) {
        return $results;
    }

    logMessage("Running special collections timestamp update for library: {$libraryName}");

    // Get all collections for this library
    $collectionsResult = getAllPlexCollections($serverUrl, $token, $libraryId);
    if (!$collectionsResult['success'] || empty($collectionsResult['data'])) {
        logMessage("No collections found or error retrieving collections");
        return $results;
    }

    // Create a lookup table for collection IDs -> addedAt timestamps
    $collectionTimestamps = [];
    foreach ($collectionsResult['data'] as $collection) {
        if (isset($collection['id']) && isset($collection['addedAt'])) {
            $collectionTimestamps[$collection['id']] = $collection['addedAt'];
        }
    }

    // Get all collection files in the directory
    $files = glob($targetDir . '/*');

    foreach ($files as $file) {
        if (!is_file($file))
            continue;

        $filename = basename($file);

        // Skip non-Plex files
        if (strpos($filename, '--Plex--') === false)
            continue;

        // Skip already-orphaned files
        if (strpos($filename, '--Orphaned--') !== false)
            continue;

        // Check if this is a collection of the right type for this library
        $isMovieCollection = strpos($filename, '(Movies)') !== false;
        $isShowCollection = strpos($filename, '(TV)') !== false;

        // Skip if this collection doesn't match the library type
        if (
            ($libraryType === 'movie' && !$isMovieCollection) ||
            ($libraryType === 'show' && !$isShowCollection)
        ) {
            continue;
        }

        // Check if the file already has a timestamp
        $hasTimestamp = preg_match('/\(A\d{8,12}\)/', $filename);
        if ($hasTimestamp)
            continue;

        // Extract the ID from the filename
        $idMatch = [];
        if (preg_match('/\[([a-f0-9]+)\]/', $filename, $idMatch)) {
            $fileId = $idMatch[1];

            // Check if we have a timestamp for this ID
            if (isset($collectionTimestamps[$fileId])) {
                $addedAt = $collectionTimestamps[$fileId];

                // Build the new filename by inserting the timestamp before the --Plex-- marker
                $beforePlex = substr($filename, 0, strpos($filename, '--Plex--'));
                $afterPlex = substr($filename, strpos($filename, '--Plex--'));

                // If there's a space before --Plex--, we don't need to add one
                if (substr($beforePlex, -1) === ' ') {
                    $newFilename = $beforePlex . "(A{$addedAt}) " . $afterPlex;
                } else {
                    $newFilename = $beforePlex . " (A{$addedAt}) " . $afterPlex;
                }

                $newPath = $targetDir . $newFilename;

                logDebug("Attempting to update collection with timestamp: $filename -> $newFilename");

                if (rename($file, $newPath)) {
                    $results['updated']++;
                    logMessage("Successfully updated collection with timestamp: $filename");
                } else {
                    $results['failed']++;
                    logMessage("Failed to update collection with timestamp: $filename");
                }
            }
        }
    }

    logMessage("Collections timestamp update complete: {$results['updated']} updated, {$results['failed']} failed");
    return $results;
}

/**
 * Update the importCollections function to use the new pre-processing function
 */
function importCollections($serverUrl, $token, $libraryId, $libraryName, $libraryType)
{
    logMessage("Importing collections from library: {$libraryName}");

    $targetDir = BASE_PATH . '/posters/collections/';

    // Ensure directory exists
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            logMessage("Failed to create directory: {$targetDir}");
            return false;
        }
    }

    // CRITICAL NEW STEP: First, update existing collections with timestamps
    // This helps prevent them from being marked as orphans
    $updateResults = updateCollectionsWithTimestamps($serverUrl, $token, $libraryId, $libraryName, $libraryType);

    // Get all collections
    $result = getAllPlexCollections($serverUrl, $token, $libraryId);
    if (!$result['success']) {
        logMessage("Failed to get collections: " . $result['error']);
        return false;
    }

    logMessage("Found " . count($result['data']) . " collections in library: {$libraryName}");

    // Process the collections
    $batchResults = processBatch($result['data'], $serverUrl, $token, $targetDir, 'collections', $libraryType, $libraryName);

    // Handle orphaned collection posters
    $orphanedResults = handleOrphanedPosters(
        $targetDir,
        $batchResults['importedIds'],
        '--Orphaned--',
        $libraryType,
        '',
        'collections',
        $libraryId
    );

    // Log the results
    logMessage("Collection import results for {$libraryName}: " .
        "Processed: " . count($result['data']) . ", " .
        "Successful: " . $batchResults['successful'] . ", " .
        "Renamed: " . $batchResults['renamed'] . ", " .
        "Timestamp updates: " . $updateResults['updated'] . ", " .
        "Failed: " . $batchResults['failed'] . ", " .
        "Orphaned: " . $orphanedResults['orphaned']);

    return true;
}

// Initialize session data if needed
if (!isset($_SESSION)) {
    $_SESSION = [];
}

// Load stored IDs
initializeSessionFromStorage();

/**
 * Improved function to fix collections and clean up duplicates
 */
function fixCollectionsTimestamps($serverUrl, $token)
{
    $collectionsDir = BASE_PATH . '/posters/collections/';

    // Skip if collections directory doesn't exist
    if (!is_dir($collectionsDir)) {
        logMessage("Collections directory not found: " . $collectionsDir);
        return false;
    }

    logMessage("Pre-processing collections to add timestamps and remove duplicates");

    // Get all libraries first
    $libraries = getPlexLibraries($serverUrl, $token);
    if (!$libraries['success'] || empty($libraries['data'])) {
        logMessage("Failed to get libraries or no libraries found");
        return false;
    }

    // Track results
    $results = [
        'updated' => 0,
        'deleted_duplicates' => 0,
        'failed' => 0,
        'skipped' => 0
    ];

    // Create a mapping of all collection IDs to their details (with timestamps)
    $collectionDetails = [];

    // For each library, get collections and build our lookup table
    foreach ($libraries['data'] as $library) {
        $libraryId = $library['id'];
        $libraryType = $library['type'];

        // Get collections for this library
        $collectionsResult = getAllPlexCollections($serverUrl, $token, $libraryId);

        if ($collectionsResult['success'] && !empty($collectionsResult['data'])) {
            foreach ($collectionsResult['data'] as $collection) {
                // Store collection details with library type for reference
                if (isset($collection['id']) && isset($collection['addedAt'])) {
                    $collectionDetails[$collection['id']] = [
                        'addedAt' => $collection['addedAt'],
                        'libraryType' => $libraryType,
                        'title' => $collection['title']
                    ];
                }
            }

            logMessage("Found " . count($collectionsResult['data']) .
                " collections in " . $library['title'] . " (" . $libraryType . ")");
        }
    }

    if (empty($collectionDetails)) {
        logMessage("No collections found with timestamps");
        return false;
    }

    logMessage("Built lookup table with " . count($collectionDetails) . " collections");

    // First: Group all files by their collection ID to identify duplicates
    $filesByCollectionId = [];
    $allFiles = glob($collectionsDir . '*');

    foreach ($allFiles as $file) {
        if (!is_file($file))
            continue;

        $filename = basename($file);

        // Extract ID from filename
        $idMatch = [];
        if (preg_match('/\[([a-f0-9]+)\]/', $filename, $idMatch)) {
            $fileId = $idMatch[1];

            if (!isset($filesByCollectionId[$fileId])) {
                $filesByCollectionId[$fileId] = [];
            }

            $filesByCollectionId[$fileId][] = [
                'path' => $file,
                'filename' => $filename,
                'has_timestamp' => preg_match('/\(A\d{8,12}\)/', $filename) ? true : false,
                'is_orphaned' => strpos($filename, '--Orphaned--') !== false ? true : false
            ];
        }
    }

    // Process each collection ID and handle its files
    foreach ($filesByCollectionId as $collectionId => $files) {
        // Skip if no files for this ID
        if (empty($files))
            continue;

        // Check if we have Plex details for this collection
        $hasPlexDetails = isset($collectionDetails[$collectionId]);
        $addedAt = $hasPlexDetails ? $collectionDetails[$collectionId]['addedAt'] : '';
        $libraryType = $hasPlexDetails ? $collectionDetails[$collectionId]['libraryType'] : '';
        $title = $hasPlexDetails ? $collectionDetails[$collectionId]['title'] : '';

        // If we have multiple files for the same collection ID
        if (count($files) > 1) {
            logMessage("Found " . count($files) . " files for collection ID: " . $collectionId);

            // Determine which file to keep based on specific criteria
            $fileToKeep = null;
            $filesToDelete = [];

            // First priority: Keep file that's not orphaned and has timestamp
            foreach ($files as $file) {
                if (!$file['is_orphaned'] && $file['has_timestamp']) {
                    $fileToKeep = $file;
                    break;
                }
            }

            // Second priority: Keep file that's not orphaned
            if ($fileToKeep === null) {
                foreach ($files as $file) {
                    if (!$file['is_orphaned']) {
                        $fileToKeep = $file;
                        break;
                    }
                }
            }

            // Third priority: Keep file that has timestamp
            if ($fileToKeep === null) {
                foreach ($files as $file) {
                    if ($file['has_timestamp']) {
                        $fileToKeep = $file;
                        break;
                    }
                }
            }

            // Last resort: Keep first file
            if ($fileToKeep === null && !empty($files)) {
                $fileToKeep = $files[0];
            }

            // Mark all other files for deletion
            foreach ($files as $file) {
                if ($file !== $fileToKeep) {
                    $filesToDelete[] = $file;
                }
            }

            // Delete duplicate files
            foreach ($filesToDelete as $file) {
                logMessage("Deleting duplicate: " . $file['filename']);
                if (unlink($file['path'])) {
                    $results['deleted_duplicates']++;
                } else {
                    logMessage("Failed to delete duplicate: " . $file['filename']);
                    $results['failed']++;
                }
            }

            // If the file to keep is orphaned but we have Plex details, fix it
            if ($fileToKeep['is_orphaned'] && $hasPlexDetails) {
                $oldPath = $fileToKeep['path'];
                $oldFilename = $fileToKeep['filename'];

                // Generate a standardized filename with consistent timestamp placement
                $typeMarker = ($libraryType === 'movie') ? "(Movies)" : "(TV)";

                // Build filename components
                $titlePart = sanitizeFilename($title);
                $idPart = "[" . $collectionId . "]";
                $timestampPart = "(A" . $addedAt . ")";

                // Only add "Collection" text if it's not already in the title
                $collectionPart = (stripos($titlePart, 'Collection') === false) ? " Collection" : "";

                // Assemble the new filename with timestamp right after ID
                $newFilename = $titlePart . " " . $idPart . " " . $timestampPart .
                    $collectionPart . " " . $typeMarker . " --Plex--.jpg";

                $newPath = $collectionsDir . $newFilename;

                logMessage("Rescuing orphaned collection and standardizing filename: " . $oldFilename);

                if (rename($oldPath, $newPath)) {
                    $results['updated']++;
                    logMessage("Successfully standardized: " . $newFilename);
                } else {
                    $results['failed']++;
                    logMessage("Failed to standardize: " . $oldFilename);
                }
            }
            // If file to keep doesn't have timestamp but we have Plex details, add it
            else if (!$fileToKeep['has_timestamp'] && $hasPlexDetails) {
                $oldPath = $fileToKeep['path'];
                $oldFilename = $fileToKeep['filename'];

                // Generate a standardized filename with consistent timestamp placement
                $typeMarker = ($libraryType === 'movie') ? "(Movies)" : "(TV)";

                // Build filename components
                $titlePart = sanitizeFilename($title);
                $idPart = "[" . $collectionId . "]";
                $timestampPart = "(A" . $addedAt . ")";

                // Only add "Collection" text if it's not already in the title
                $collectionPart = (stripos($titlePart, 'Collection') === false) ? " Collection" : "";

                // Assemble the new filename with timestamp right after ID
                $newFilename = $titlePart . " " . $idPart . " " . $timestampPart .
                    $collectionPart . " " . $typeMarker . " --Plex--.jpg";

                $newPath = $collectionsDir . $newFilename;

                logMessage("Standardizing collection filename: " . $oldFilename);

                if (rename($oldPath, $newPath)) {
                    $results['updated']++;
                    logMessage("Successfully standardized: " . $newFilename);
                } else {
                    $results['failed']++;
                    logMessage("Failed to standardize: " . $oldFilename);
                }
            }
        }
        // Single file for this collection ID
        else if (count($files) == 1) {
            $file = $files[0];

            // If file is orphaned but we have Plex details, fix it
            if ($file['is_orphaned'] && $hasPlexDetails) {
                $oldPath = $file['path'];
                $oldFilename = $file['filename'];

                // Generate a standardized filename with consistent timestamp placement
                $typeMarker = ($libraryType === 'movie') ? "(Movies)" : "(TV)";

                // Build filename components
                $titlePart = sanitizeFilename($title);
                $idPart = "[" . $collectionId . "]";
                $timestampPart = "(A" . $addedAt . ")";

                // Only add "Collection" text if it's not already in the title
                $collectionPart = (stripos($titlePart, 'Collection') === false) ? " Collection" : "";

                // Assemble the new filename with timestamp right after ID
                $newFilename = $titlePart . " " . $idPart . " " . $timestampPart .
                    $collectionPart . " " . $typeMarker . " --Plex--.jpg";

                $newPath = $collectionsDir . $newFilename;

                logMessage("Rescuing orphaned collection: " . $oldFilename);

                if (rename($oldPath, $newPath)) {
                    $results['updated']++;
                    logMessage("Successfully rescued: " . $newFilename);
                } else {
                    $results['failed']++;
                    logMessage("Failed to rescue: " . $oldFilename);
                }
            }
            // If file doesn't have timestamp but we have Plex details, add it
            else if (!$file['has_timestamp'] && $hasPlexDetails) {
                $oldPath = $file['path'];
                $oldFilename = $file['filename'];

                // Generate a standardized filename with consistent timestamp placement
                $typeMarker = ($libraryType === 'movie') ? "(Movies)" : "(TV)";

                // Build filename components
                $titlePart = sanitizeFilename($title);
                $idPart = "[" . $collectionId . "]";
                $timestampPart = "(A" . $addedAt . ")";

                // Only add "Collection" text if it's not already in the title
                $collectionPart = (stripos($titlePart, 'Collection') === false) ? " Collection" : "";

                // Assemble the new filename with timestamp right after ID
                $newFilename = $titlePart . " " . $idPart . " " . $timestampPart .
                    $collectionPart . " " . $typeMarker . " --Plex--.jpg";

                $newPath = $collectionsDir . $newFilename;

                logMessage("Standardizing collection filename: " . $oldFilename);

                if (rename($oldPath, $newPath)) {
                    $results['updated']++;
                    logMessage("Successfully standardized: " . $newFilename);
                } else {
                    $results['failed']++;
                    logMessage("Failed to standardize: " . $oldFilename);
                }
            } else {
                $results['skipped']++;
            }
        }
    }

    logMessage("Collections pre-processing complete: " .
        $results['updated'] . " updated, " .
        $results['deleted_duplicates'] . " duplicates removed, " .
        $results['failed'] . " failed, " .
        $results['skipped'] . " skipped");

    return true;
}

// Main execution logic
try {
    logMessage("AUTO-IMPORT STARTING");

    // Check if auto-import is enabled
    if (!$auto_import_config['enabled']) {
        logMessage("Auto-import is disabled in configuration. Exiting.");
        exit(0);
    }

    // Check if we should run based on schedule
    if (!shouldRunImport()) {
        logMessage("Not time to run import yet based on schedule ({$auto_import_config['schedule']}). Exiting.");
        exit(0);
    }

    // Validate Plex configuration
    if (empty($plex_config['server_url']) || empty($plex_config['token'])) {
        logMessage("Plex configuration is incomplete. Missing server URL or token.");
        exit(1);
    }

    logMessage("Starting auto-import process");

    // Get all libraries from Plex
    $libraries = getPlexLibraries($plex_config['server_url'], $plex_config['token']);

    if (!$libraries['success'] || empty($libraries['data'])) {
        logMessage("Failed to retrieve libraries from Plex or no libraries found.");
        exit(1);
    }

    $libraryCount = count($libraries['data']);
    logMessage("Found {$libraryCount} libraries to process");

    // Pre-process collections to fix timestamp issues before starting imports
    fixCollectionsTimestamps($plex_config['server_url'], $plex_config['token']);

    // First, gather all valid IDs from all libraries to prevent incorrect orphaning
    // This helps with the multi-library issue
    $allValidIdsByType = gatherAllValidIds($plex_config['server_url'], $plex_config['token']);
    logMessage("Pre-gathered valid IDs for all media types to prevent incorrect orphaning");

    // Process each library
    foreach ($libraries['data'] as $library) {
        $libraryId = $library['id'];
        $libraryName = $library['title'];
        $libraryType = $library['type'];

        logMessage("Processing library: {$libraryName} (ID: {$libraryId}, Type: {$libraryType})");

        // Check if this library is excluded
        if (in_array($libraryName, $auto_import_config['excluded_libraries'])) {
            logMessage("Skipping excluded library: {$libraryName}");
            continue;
        }

        // Import movies if enabled and library type is 'movie'
        if ($auto_import_config['import_movies'] && $libraryType === 'movie') {
            importMovies($plex_config['server_url'], $plex_config['token'], $libraryId, $libraryName);

            // Make sure to sync after each library to preserve IDs
            syncSessionToStorage();
        }

        // Import shows if enabled and library type is 'show'
        if ($auto_import_config['import_shows'] && $libraryType === 'show') {
            importShows($plex_config['server_url'], $plex_config['token'], $libraryId, $libraryName);

            // Make sure to sync after each library to preserve IDs
            syncSessionToStorage();
        }

        // Import seasons if enabled and library type is 'show'
        if ($auto_import_config['import_seasons'] && $libraryType === 'show') {
            importAllSeasons($plex_config['server_url'], $plex_config['token'], $libraryId, $libraryName);

            // Make sure to sync after each library to preserve IDs
            syncSessionToStorage();
        }

        // Import collections if enabled
        if ($auto_import_config['import_collections']) {
            importCollections($plex_config['server_url'], $plex_config['token'], $libraryId, $libraryName, $libraryType);

            // Make sure to sync after each library to preserve IDs
            syncSessionToStorage();
        }
    }

    // Make sure all changes are saved to persistent storage
    syncSessionToStorage();

    // Update the last run timestamp
    updateLastRun();

    logMessage("Auto-import completed successfully");
    exit(0);

} catch (Exception $e) {
    logMessage("Error during auto-import process: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
}
?>