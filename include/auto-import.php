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

// Set up error handling
ini_set('display_errors', 1);
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

// Define the base path - adjust if needed based on your installation
define('BASE_PATH', dirname(__DIR__));
define('INCLUDE_PATH', BASE_PATH . '/include');
define('LOG_PATH', BASE_PATH . '/data');

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
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
    logMessage("Import process completed.");
});

/**
 * Initialize a fake session array for storage functions
 * This ensures the session storage functions work properly in CLI mode
 */
function initializeFakeSession() {
    global $_SESSION;
    
    // Create the $_SESSION variable if it doesn't exist (in CLI mode)
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }
    
    // Initialize valid_plex_ids from storage
    if (!isset($_SESSION['valid_plex_ids'])) {
        $_SESSION['valid_plex_ids'] = loadValidIdsFromStorage();
        logMessage("Initialized valid_plex_ids from persistent storage");
    }
    
    // Define logDebug function if it doesn't exist (needed for plex-id-storage.php)
    if (!function_exists('logDebug')) {
        function logDebug($message, $data = null) {
            logMessage($message, $data);
        }
    }
}

/**
 * Log a message to the log file
 */
function logMessage($message, $data = null) {
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

/**
 * Check if an import should run based on the last run timestamp and scheduled interval
 */
function shouldRunImport() {
    global $auto_import_config;
    
    $lastRunFile = LOG_PATH . '/last_auto_import.txt';
    
    // If the last run file doesn't exist, we should run
    if (!file_exists($lastRunFile)) {
        return true;
    }
    
    // Read the last run timestamp
    $lastRun = (int)file_get_contents($lastRunFile);
    $currentTime = time();
    
    // Get the interval in seconds
    $intervalSeconds = parseInterval($auto_import_config['schedule']);
    
    // Check if enough time has passed
    return ($currentTime - $lastRun) >= $intervalSeconds;
}

/**
 * Update the last run timestamp
 */
function updateLastRun() {
    $lastRunFile = LOG_PATH . '/last_auto_import.txt';
    file_put_contents($lastRunFile, time());
}

/**
 * Parse a human-readable interval like "24h" into seconds
 */
function parseInterval($interval) {
    $number = (int)$interval;
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
function makeApiRequest($url, $headers, $expectJson = true) {
    global $plex_config;
    
    logMessage("Making API request to: {$url}");
    
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
function getPlexHeaders($token, $start = 0, $size = 50) {
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
function getPlexLibraries($serverUrl, $token) {
    try {
        logMessage("Getting Plex libraries");
        
        $url = rtrim($serverUrl, '/') . "/library/sections";
        $headers = getPlexHeaders($token);
        
        $response = makeApiRequest($url, $headers);
        $data = json_decode($response, true);
        
        if (!isset($data['MediaContainer']['Directory'])) {
            logMessage("No libraries found in Plex response");
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
        
        logMessage("Found " . count($libraries) . " compatible Plex libraries");
        
        return ['success' => true, 'data' => $libraries];
    } catch (Exception $e) {
        logMessage("Error getting Plex libraries: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get all Plex movies with pagination
 */
function getAllPlexMovies($serverUrl, $token, $libraryId) {
    $allMovies = [];
    $start = 0;
    $size = 25; // Use a smaller batch size to be gentle with the Plex server
    $moreAvailable = true;
    
    logMessage("Starting to fetch movies from library ID {$libraryId} in batches of {$size}");
    $batchCount = 0;
    
    while ($moreAvailable) {
        try {
            $batchCount++;
            logMessage("Fetching movie batch #{$batchCount} (offset: {$start})");
            
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/all";
            $headers = getPlexHeaders($token, $start, $size);
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                logMessage("No movies found in this batch");
                break;
            }
            
            $moviesInBatch = [];
            foreach ($data['MediaContainer']['Metadata'] as $movie) {
                if (isset($movie['thumb'])) {
                    $moviesInBatch[] = [
                        'title' => $movie['title'],
                        'id' => $movie['ratingKey'],
                        'thumb' => $movie['thumb'],
                        'year' => $movie['year'] ?? '',
                        'ratingKey' => $movie['ratingKey']
                    ];
                }
            }
            
            $allMovies = array_merge($allMovies, $moviesInBatch);
            
            // Check if there are more movies to fetch
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($moviesInBatch);
            $moreAvailable = ($start + count($moviesInBatch)) < $totalSize;
            
            logMessage("Fetched " . count($moviesInBatch) . " movies in batch #{$batchCount}. Total: " . 
                      count($allMovies) . "/" . $totalSize . ". More available: " . 
                      ($moreAvailable ? "Yes" : "No"));
            
            $start += $size;
            
            if (count($moviesInBatch) === 0) {
                $moreAvailable = false;
            }
            
            // Add a small delay between API calls to be nice to the Plex server
            if ($moreAvailable) {
                usleep(500000); // 500ms delay
            }
            
        } catch (Exception $e) {
            logMessage("Error fetching movies: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    logMessage("Found " . count($allMovies) . " movies in library {$libraryId}");
    return ['success' => true, 'data' => $allMovies];
}

/**
 * Get all Plex TV shows with pagination
 */
function getAllPlexShows($serverUrl, $token, $libraryId) {
    $allShows = [];
    $start = 0;
    $size = 25; // Use a smaller batch size to be gentle with the Plex server
    $moreAvailable = true;
    
    logMessage("Starting to fetch TV shows from library ID {$libraryId} in batches of {$size}");
    $batchCount = 0;
    
    while ($moreAvailable) {
        try {
            $batchCount++;
            logMessage("Fetching TV show batch #{$batchCount} (offset: {$start})");
            
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/all";
            $headers = getPlexHeaders($token, $start, $size);
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                logMessage("No TV shows found in this batch");
                break;
            }
            
            $showsInBatch = [];
            foreach ($data['MediaContainer']['Metadata'] as $show) {
                if (isset($show['thumb'])) {
                    $showsInBatch[] = [
                        'title' => $show['title'],
                        'id' => $show['ratingKey'],
                        'thumb' => $show['thumb'],
                        'year' => $show['year'] ?? '',
                        'ratingKey' => $show['ratingKey']
                    ];
                }
            }
            
            $allShows = array_merge($allShows, $showsInBatch);
            
            // Check if there are more shows to fetch
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($showsInBatch);
            $moreAvailable = ($start + count($showsInBatch)) < $totalSize;
            
            logMessage("Fetched " . count($showsInBatch) . " shows in batch #{$batchCount}. Total: " . 
                      count($allShows) . "/" . $totalSize . ". More available: " . 
                      ($moreAvailable ? "Yes" : "No"));
            
            $start += $size;
            
            if (count($showsInBatch) === 0) {
                $moreAvailable = false;
            }
            
            // Add a small delay between API calls to be nice to the Plex server
            if ($moreAvailable) {
                usleep(500000); // 500ms delay
            }
            
        } catch (Exception $e) {
            logMessage("Error fetching TV shows: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    logMessage("Found " . count($allShows) . " TV shows in library {$libraryId}");
    return ['success' => true, 'data' => $allShows];
}

/**
 * Get all seasons for a TV show with pagination
 */
function getAllPlexSeasons($serverUrl, $token, $showKey) {
    $allSeasons = [];
    $start = 0;
    $size = 25; // Use a smaller batch size to be gentle with the Plex server
    $moreAvailable = true;
    
    logMessage("Starting to fetch seasons for show ID {$showKey} in batches of {$size}");
    $batchCount = 0;
    
    while ($moreAvailable) {
        try {
            $batchCount++;
            logMessage("Fetching season batch #{$batchCount} (offset: {$start})");
            
            $url = rtrim($serverUrl, '/') . "/library/metadata/{$showKey}/children";
            $headers = getPlexHeaders($token, $start, $size);
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                logMessage("No seasons found in this batch");
                break;
            }
            
            $seasonsInBatch = [];
            foreach ($data['MediaContainer']['Metadata'] as $season) {
                if (isset($season['thumb']) && isset($season['index'])) {
                    $seasonsInBatch[] = [
                        'title' => $season['parentTitle'] . ' - ' . $season['title'],
                        'id' => $season['ratingKey'],
                        'thumb' => $season['thumb'],
                        'index' => $season['index'],
                        'ratingKey' => $season['ratingKey']
                    ];
                }
            }
            
            $allSeasons = array_merge($allSeasons, $seasonsInBatch);
            
            // Check if there are more seasons to fetch
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($seasonsInBatch);
            $moreAvailable = ($start + count($seasonsInBatch)) < $totalSize;
            
            logMessage("Fetched " . count($seasonsInBatch) . " seasons in batch #{$batchCount}. Total: " . 
                      count($allSeasons) . "/" . $totalSize . ". More available: " . 
                      ($moreAvailable ? "Yes" : "No"));
            
            $start += $size;
            
            if (count($seasonsInBatch) === 0) {
                $moreAvailable = false;
            }
            
            // Add a small delay between API calls to be nice to the Plex server
            if ($moreAvailable) {
                usleep(500000); // 500ms delay
            }
            
        } catch (Exception $e) {
            logMessage("Error fetching seasons: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    logMessage("Found " . count($allSeasons) . " seasons for show {$showKey}");
    return ['success' => true, 'data' => $allSeasons];
}

/**
 * Get all collections for a library with pagination
 */
function getAllPlexCollections($serverUrl, $token, $libraryId) {
    $allCollections = [];
    $start = 0;
    $size = 25; // Use a smaller batch size to be gentle with the Plex server
    $moreAvailable = true;
    
    logMessage("Starting to fetch collections from library ID {$libraryId} in batches of {$size}");
    $batchCount = 0;
    
    while ($moreAvailable) {
        try {
            $batchCount++;
            logMessage("Fetching collection batch #{$batchCount} (offset: {$start})");
            
            $url = rtrim($serverUrl, '/') . "/library/sections/{$libraryId}/collections";
            $headers = getPlexHeaders($token, $start, $size);
            
            $response = makeApiRequest($url, $headers);
            $data = json_decode($response, true);
            
            if (!isset($data['MediaContainer']['Metadata'])) {
                // Collections might be empty
                logMessage("No collections found in this batch");
                break;
            }
            
            $collectionsInBatch = [];
            foreach ($data['MediaContainer']['Metadata'] as $collection) {
                if (isset($collection['thumb'])) {
                    $collectionsInBatch[] = [
                        'title' => $collection['title'],
                        'id' => $collection['ratingKey'],
                        'thumb' => $collection['thumb'],
                        'ratingKey' => $collection['ratingKey']
                    ];
                }
            }
            
            $allCollections = array_merge($allCollections, $collectionsInBatch);
            
            // Check if there are more collections to fetch
            $totalSize = $data['MediaContainer']['totalSize'] ?? $data['MediaContainer']['size'] ?? count($collectionsInBatch);
            $moreAvailable = ($start + count($collectionsInBatch)) < $totalSize;
            
            logMessage("Fetched " . count($collectionsInBatch) . " collections in batch #{$batchCount}. Total: " . 
                      count($allCollections) . "/" . $totalSize . ". More available: " . 
                      ($moreAvailable ? "Yes" : "No"));
            
            $start += $size;
            
            if (count($collectionsInBatch) === 0) {
                $moreAvailable = false;
            }
            
            // Add a small delay between API calls to be nice to the Plex server
            if ($moreAvailable) {
                usleep(500000); // 500ms delay
            }
            
        } catch (Exception $e) {
            logMessage("Error fetching collections: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    logMessage("Found " . count($allCollections) . " collections in library {$libraryId}");
    return ['success' => true, 'data' => $allCollections];
}

/**
 * Download and save a Plex image
 */
function downloadPlexImage($serverUrl, $token, $thumb, $targetPath) {
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
function findExistingPosterByRatingKey($directory, $ratingKey) {
    if (!is_dir($directory)) {
        return false;
    }
    
    $files = glob($directory . '/*');
    foreach ($files as $file) {
        if (is_file($file) && strpos($file, "**Plex**") !== false) {
            $pattern = '/\[' . preg_quote($ratingKey, '/') . '\]/';
            if (preg_match($pattern, basename($file))) {
                return basename($file);
            }
        }
    }
    
    return false;
}

/**
 * Generate Plex filename with proper formatting
 */
function generatePlexFilename($title, $id, $extension, $mediaType = '', $libraryType = '', $libraryName = '') {
    $basename = sanitizeFilename($title);
    if (!empty($id)) {
        $basename .= " [{$id}]";
    }
    
    // For collections, don't add the library name
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
        // For regular items (movies, shows, seasons), add library name
        $libraryNameStr = !empty($libraryName) ? " [[{$libraryName}]]" : "";
        $basename .= "{$libraryNameStr} **Plex**";
    }
    
    return $basename . '.' . $extension;
}

/**
 * Sanitize filename to remove invalid characters
 */
function sanitizeFilename($filename) {
    // Remove any character that isn't alphanumeric, space, underscore, dash, or dot
    $filename = preg_replace('/[^\w\s\.-]/', '', $filename);
    $filename = preg_replace('/\s+/', ' ', $filename); // Remove multiple spaces
    return trim($filename);
}

/**
 * Process a batch of items with smart handling of existing files
 */
function processBatch($items, $serverUrl, $token, $targetDir, $mediaType = '', $libraryType = '', $libraryName = '') {
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
    
    // Process items in smaller batches to be gentle with the file system
    $batchSize = 10;
    $totalItems = count($items);
    $processedCount = 0;
    
    logMessage("Processing {$totalItems} items in batches of {$batchSize} for {$mediaType}");
    
    for ($i = 0; $i < $totalItems; $i += $batchSize) {
        $currentBatch = array_slice($items, $i, $batchSize);
        $batchNum = floor($i / $batchSize) + 1;
        $totalBatches = ceil($totalItems / $batchSize);
        
        logMessage("Processing batch {$batchNum}/{$totalBatches} ({$mediaType})");
        
        foreach ($currentBatch as $item) {
            $processedCount++;
            
            if (!isset($item['title']) || !isset($item['id']) || !isset($item['thumb'])) {
                logMessage("Skipping malformed item in batch");
                continue;
            }
            
            $title = $item['title'];
            $id = $item['id'];
            $thumb = $item['thumb'];
            
            // Check for existing file
            $existingFile = findExistingPosterByRatingKey($targetDir, $id);
            
            // Generate target filename with library name
            $extension = 'jpg';
            $filename = generatePlexFilename($title, $id, $extension, $mediaType, $libraryType, $libraryName);
            $targetPath = $targetDir . $filename;
            
            logMessage("Processing item {$processedCount}/{$totalItems}: {$title}");
            
            // Handle existing file
            if ($existingFile && $existingFile !== $filename) {
                $oldPath = $targetDir . $existingFile;
                
                // Check if we're just upgrading the filename by adding library name
                if (strpos($filename, $libraryName) !== false && !strpos($existingFile, $libraryName)) {
                    // Rename the file to include library name
                    if (rename($oldPath, $targetPath)) {
                        $results['renamed']++;
                        $results['successful']++;
                        $results['importedIds'][] = $id;
                        logMessage("Renamed file to include library name: {$existingFile} -> {$filename}");
                        continue;
                    }
                }
            }
            
            // Handle file download/update
            if (file_exists($targetPath)) {
                // Always try to get fresh version when auto-importing
                $downloadResult = downloadPlexImage($serverUrl, $token, $thumb, $targetPath);
                
                if ($downloadResult['success']) {
                    $results['successful']++;
                    $results['importedIds'][] = $id;
                    logMessage("Downloaded new poster: {$filename}");
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to download {$title}: {$downloadResult['error']}";
                    logMessage("Failed to download: {$title}", $downloadResult['error']);
                }
            }
        }
        
        // Add a small delay between batches to be nice to the file system
        if ($i + $batchSize < $totalItems) {
            usleep(200000); // 200ms delay
        }
    }
    
    logMessage("Completed processing {$totalItems} items for {$mediaType}. " . 
              "Success: {$results['successful']}, Skipped: {$results['skipped']}, " . 
              "Renamed: {$results['renamed']}, Failed: {$results['failed']}");
    
    return $results;
}

/**
 * Main function to import movies for a library
 */
function importMovies($serverUrl, $token, $libraryId, $libraryName) {
    logMessage("Starting movie import for library: {$libraryName} (ID: {$libraryId})");
    
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
    
    // Process the movies
    $batchResults = processBatch($result['data'], $serverUrl, $token, $targetDir, 'movies', '', $libraryName);
    
    // Mark orphaned posters - passing current library ID to ensure proper tracking
    $orphanedResults = markOrphanedPosters($targetDir, $batchResults['importedIds'], 
                                         '**Orphaned**', '', '', 'movies', $libraryId);
    
    // Log the results
    logMessage("Movie import results for {$libraryName}", [
        'successful' => $batchResults['successful'],
        'skipped' => $batchResults['skipped'],
        'renamed' => $batchResults['renamed'],
        'failed' => $batchResults['failed'],
        'orphaned' => $orphanedResults['orphaned']
    ]);
    
    return true;
}

/**
 * Main function to import TV shows for a library
 */
function importShows($serverUrl, $token, $libraryId, $libraryName) {
    logMessage("Starting show import for library: {$libraryName} (ID: {$libraryId})");
    
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
    
    // Process the shows
    $batchResults = processBatch($result['data'], $serverUrl, $token, $targetDir, 'shows', '', $libraryName);
    
    // Mark orphaned posters - passing current library ID to ensure proper tracking
    $orphanedResults = markOrphanedPosters($targetDir, $batchResults['importedIds'], 
                                         '**Orphaned**', '', '', 'shows', $libraryId);
    
    // Log the results
    logMessage("Show import results for {$libraryName}", [
        'successful' => $batchResults['successful'],
        'skipped' => $batchResults['skipped'],
        'renamed' => $batchResults['renamed'],
        'failed' => $batchResults['failed'],
        'orphaned' => $orphanedResults['orphaned']
    ]);
    
    return true;
}

/**
 * Main function to import seasons for all shows in a library
 */
function importAllSeasons($serverUrl, $token, $libraryId, $libraryName) {
    logMessage("Starting season import for all shows in library: {$libraryName} (ID: {$libraryId})");
    
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
        
        logMessage("Processing seasons for show {$processedShows}/{$totalShows}: {$showTitle}");
        
        // Get all seasons for this show
        $seasonsResult = getAllPlexSeasons($serverUrl, $token, $showKey);
        
        if (!$seasonsResult['success']) {
            logMessage("Error fetching seasons for show {$showTitle}: " . $seasonsResult['error']);
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
        
        // Log the results for this show
        logMessage("Season import results for {$showTitle}", [
            'successful' => $batchResults['successful'],
            'skipped' => $batchResults['skipped'],
            'renamed' => $batchResults['renamed'],
            'failed' => $batchResults['failed'],
            'seasonsFound' => count($seasons)
        ]);
    }
    
    // Mark orphaned season posters at the end - passing library ID for proper tracking
    $orphanedResults = markOrphanedPosters($targetDir, $allSeasonIds, 
                                         '**Orphaned**', '', '', 'seasons', $libraryId);
    
    logMessage("Completed season import for all shows in library {$libraryName}", [
        'totalShows' => $totalShows,
        'processedShows' => $processedShows,
        'totalSeasonIds' => count($allSeasonIds),
        'orphaned' => $orphanedResults['orphaned']
    ]);
    
    return true;
}

/**
 * Main function to import collections for a library
 */
function importCollections($serverUrl, $token, $libraryId, $libraryName, $libraryType) {
    logMessage("Starting collection import for library: {$libraryName} (ID: {$libraryId}, Type: {$libraryType})");
    
    $targetDir = BASE_PATH . '/posters/collections/';
    
    // Ensure directory exists
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            logMessage("Failed to create directory: {$targetDir}");
            return false;
        }
    }
    
    // Get all collections
    $result = getAllPlexCollections($serverUrl, $token, $libraryId);
    if (!$result['success']) {
        logMessage("Failed to get collections: " . $result['error']);
        return false;
    }
    
    // Process the collections
    $batchResults = processBatch($result['data'], $serverUrl, $token, $targetDir, 'collections', $libraryType, $libraryName);
    
    // Mark orphaned collection posters - passing library ID for proper tracking
    $orphanedResults = markOrphanedPosters($targetDir, $batchResults['importedIds'], 
                                         '**Orphaned**', $libraryType, '', 'collections', $libraryId);
    
    // Log the results
    logMessage("Collection import results for {$libraryName}", [
        'successful' => $batchResults['successful'],
        'skipped' => $batchResults['skipped'],
        'renamed' => $batchResults['renamed'],
        'failed' => $batchResults['failed'],
        'orphaned' => $orphanedResults['orphaned']
    ]);
    
    return true;
}

/**
 * Mark orphaned posters for a specific media type, handling multiple libraries correctly
 * Reuses the existing functions from plex-id-storage.php
 */
function markOrphanedPosters($targetDir, $currentImportIds, $orphanedTag = '**Orphaned**', 
                            $libraryType = '', $showTitle = '', $mediaType = '', $currentLibraryId = '') {
    $results = [
        'orphaned' => 0,
        'unmarked' => 0,
        'details' => []
    ];
    
    if (!is_dir($targetDir)) {
        logMessage("Target directory does not exist: {$targetDir}");
        return $results;
    }
    
    // First, ensure the current import IDs are stored correctly using the existing function
    // Only store IDs if we have them and know which library they belong to
    if (!empty($currentImportIds) && !empty($currentLibraryId) && !empty($mediaType)) {
        storeValidIds($currentImportIds, $mediaType, $currentLibraryId, true);
        logMessage("Stored " . count($currentImportIds) . " valid IDs for {$mediaType} library {$currentLibraryId}");
        
        // Force storage sync after each library to prevent data loss
        syncSessionToStorage();
    }
    
    // IMPORTANT: Only check orphans at the very end of the entire process 
    // This check prevents orphan detection from running on each library
    $performOrphanDetection = isset($_SESSION['auto_import_final_library']) && 
                              $_SESSION['auto_import_final_library'] === $currentLibraryId;
    
    if (!$performOrphanDetection) {
        logMessage("Skipping orphan detection for this library - will check at the end of the process");
        return $results;
    }
    
    logMessage("Performing final orphan detection for {$mediaType}");
    
    // Get ALL valid IDs for this media type across ALL libraries using the existing function
    $allValidIds = getAllValidIds($mediaType);
    logMessage("Found " . count($allValidIds) . " total valid IDs across all libraries for {$mediaType}");
    
    // Get all files in the directory
    $files = glob($targetDir . '/*');
    if (empty($files)) {
        logMessage("No files found in directory: {$targetDir}");
        return $results;
    }
    
    $plexTag = '**Plex**';
    
    // For seasons with show titles, prepare normalized values for comparison
    $normalizedShowTitle = '';
    if ($mediaType === 'seasons' && !empty($showTitle)) {
        $decodedTitle = html_entity_decode($showTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalizedShowTitle = preg_replace('/[^a-zA-Z0-9\s]/', '', $decodedTitle);
        $normalizedShowTitle = trim(strtolower($normalizedShowTitle));
    }
    
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
                $isCollection = strpos($filename, 'Collection') !== false || 
                               strpos($filename, '(Movies)') !== false || 
                               strpos($filename, '(TV)') !== false;
                
                if ($isCollection) {
                    $isMatch = false;
                    
                    if ($libraryType === 'movie' && strpos($filename, '(Movies)') !== false) {
                        $isMatch = true;
                    } else if ($libraryType === 'show' && strpos($filename, '(TV)') !== false) {
                        $isMatch = true;
                    } else if (strpos($filename, '(Movies)') === false && 
                              strpos($filename, '(TV)') === false) {
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
            if (!empty($normalizedShowTitle)) {
                $decodedFilename = html_entity_decode($filename, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $normalizedFilename = preg_replace('/[^a-zA-Z0-9\s]/', '', $decodedFilename);
                $normalizedFilename = trim(strtolower($normalizedFilename));
                
                if (strpos($normalizedFilename, $normalizedShowTitle) === false) {
                    continue;
                }
            }
        }
        
        // Extract the ID from the filename
        $idMatch = [];
        if (preg_match('/\[([a-f0-9]+)\]/', $filename, $idMatch)) {
            $fileId = $idMatch[1];
            
            // Check against ALL valid IDs across ALL libraries
            if (!in_array($fileId, $allValidIds)) {
                // Replace **Plex** with **Orphaned** in the filename
                $newFilename = str_replace($plexTag, $orphanedTag, $filename);
                $newPath = $targetDir . '/' . $newFilename;
                
                logMessage("Marking file as orphaned: {$filename} -> {$newFilename}");
                
                if (rename($file, $newPath)) {
                    $results['orphaned']++;
                    $results['details'][] = [
                        'oldName' => $filename,
                        'newName' => $newFilename
                    ];
                } else {
                    $results['unmarked']++;
                    logMessage("Failed to rename orphaned file: {$file} -> {$newPath}");
                }
            }
        }
    }
    
    // Main execution logic
try {
    // Initialize fake session for storage functions
    initializeFakeSession();
    
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
    
    logMessage("Starting auto-import process with schedule: {$auto_import_config['schedule']}");
    
    // Get all libraries from Plex
    $libraries = getPlexLibraries($plex_config['server_url'], $plex_config['token']);
    
    if (!$libraries['success'] || empty($libraries['data'])) {
        logMessage("Failed to retrieve libraries from Plex or no libraries found.");
        exit(1);
    }
    
    $libraryCount = count($libraries['data']);
    logMessage("Found {$libraryCount} libraries to process");
    
    // Group libraries by type for easier tracking
    $movieLibraries = [];
    $showLibraries = [];
    
    foreach ($libraries['data'] as $library) {
        if ($library['type'] === 'movie') {
            $movieLibraries[] = $library;
        } else if ($library['type'] === 'show') {
            $showLibraries[] = $library;
        }
    }
    
    // Mark which libraries will be the "final" ones for each type
    // These will be the ones where we perform orphan detection
    if (!empty($movieLibraries)) {
        $lastMovieLibrary = end($movieLibraries);
        $_SESSION['auto_import_final_library'] = $lastMovieLibrary['id'];
        logMessage("Last movie library will be: {$lastMovieLibrary['title']} (ID: {$lastMovieLibrary['id']})");
    }
    
    if (!empty($showLibraries)) {
        $lastShowLibrary = end($showLibraries);
        $_SESSION['auto_import_final_library_show'] = $lastShowLibrary['id'];
        logMessage("Last show library will be: {$lastShowLibrary['title']} (ID: {$lastShowLibrary['id']})");
    }
    
    // Process each library
    foreach ($libraries['data'] as $library) {
        $libraryId = $library['id'];
        $libraryName = $library['title'];
        $libraryType = $library['type'];
        
        logMessage("Processing library: {$libraryName} (ID: {$libraryId}, Type: {$libraryType})");
        
        // Import movies if enabled and library type is 'movie'
        if ($auto_import_config['import_movies'] && $libraryType === 'movie') {
            importMovies($plex_config['server_url'], $plex_config['token'], $libraryId, $libraryName);
            // For non-final libraries, make sure we sync after each one
            if ($libraryId !== $_SESSION['auto_import_final_library']) {
                syncSessionToStorage();
            }
        }
        
        // Import shows if enabled and library type is 'show'
        if ($auto_import_config['import_shows'] && $libraryType === 'show') {
            // Update final library flag for shows
            if (isset($_SESSION['auto_import_final_library_show']) && $libraryId === $_SESSION['auto_import_final_library_show']) {
                $_SESSION['auto_import_final_library'] = $libraryId;
            }
            
            importShows($plex_config['server_url'], $plex_config['token'], $libraryId, $libraryName);
            
            // Reset flag after processing
            if (isset($_SESSION['auto_import_final_library_show']) && $libraryId === $_SESSION['auto_import_final_library_show']) {
                $_SESSION['auto_import_final_library'] = '';
            }
            
            // For non-final libraries, make sure we sync after each one
            if (!isset($_SESSION['auto_import_final_library_show']) || $libraryId !== $_SESSION['auto_import_final_library_show']) {
                syncSessionToStorage();
            }
        }
        
        // Import seasons if enabled and library type is 'show'
        if ($auto_import_config['import_seasons'] && $libraryType === 'show') {
            // Update final library flag for seasons
            if (isset($_SESSION['auto_import_final_library_show']) && $libraryId === $_SESSION['auto_import_final_library_show']) {
                $_SESSION['auto_import_final_library'] = $libraryId;
            }
            
            importAllSeasons($plex_config['server_url'], $plex_config['token'], $libraryId, $libraryName);
            
            // Reset flag after processing
            if (isset($_SESSION['auto_import_final_library_show']) && $libraryId === $_SESSION['auto_import_final_library_show']) {
                $_SESSION['auto_import_final_library'] = '';
            }
            
            // For non-final libraries, make sure we sync after each one
            if (!isset($_SESSION['auto_import_final_library_show']) || $libraryId !== $_SESSION['auto_import_final_library_show']) {
                syncSessionToStorage();
            }
        }
        
        // Import collections if enabled
        if ($auto_import_config['import_collections']) {
            // Update final library flag for collections of this type
            if (($libraryType === 'movie' && isset($_SESSION['auto_import_final_library']) && $libraryId === $_SESSION['auto_import_final_library']) ||
                ($libraryType === 'show' && isset($_SESSION['auto_import_final_library_show']) && $libraryId === $_SESSION['auto_import_final_library_show'])) {
                $_SESSION['auto_import_final_library'] = $libraryId;
            }
            
            importCollections($plex_config['server_url'], $plex_config['token'], $libraryId, $libraryName, $libraryType);
            
            // Reset flag after processing
            if (($libraryType === 'movie' && isset($_SESSION['auto_import_final_library']) && $libraryId === $_SESSION['auto_import_final_library']) ||
                ($libraryType === 'show' && isset($_SESSION['auto_import_final_library_show']) && $libraryId === $_SESSION['auto_import_final_library_show'])) {
                $_SESSION['auto_import_final_library'] = '';
            }
            
            // Always sync after collections
            syncSessionToStorage();
        }
    }
    
    // Perform additional cleanup
    unset($_SESSION['auto_import_final_library']);
    unset($_SESSION['auto_import_final_library_show']);
    
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
?>dResult['success']) {
                    $results['successful']++;
                    $results['importedIds'][] = $id;
                    logMessage("Updated existing poster: {$filename}");
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to download {$title}: {$downloadResult['error']}";
                    logMessage("Failed to download: {$title}", $downloadResult['error']);
                }
            } else {
                // File doesn't exist, download it
                $downloadResult = downloadPlexImage($serverUrl, $token, $thumb, $targetPath);
                
                if ($downloa
