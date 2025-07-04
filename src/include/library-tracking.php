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

/**
 * Library Tracking Implementation
 * 
 * This file adds functions to track which Plex libraries exist and detect when 
 * libraries have been removed from Plex. It enables proper orphaned detection
 * across all media types even when entire libraries disappear.
 */

// Define where to store the library tracking data
define('PLEX_LIBRARIES_FILE', dirname(__DIR__) . '/data/plex_libraries.json');

/**
 * Store information about currently available Plex libraries
 * 
 * @param array $libraries Array of library information from Plex
 * @param string $mediaType The media type (movies, shows, collections)
 * @return bool Success indicator
 */
function storeLibraryInfo($libraries, $mediaType)
{
    // Ensure data directory exists
    ensureDataDirectoryExists();

    // Get existing data
    $allLibraries = loadLibraryInfo();

    // Format libraries to store by ID
    $formattedLibraries = [];
    foreach ($libraries as $library) {
        if (isset($library['id'], $library['title'], $library['type'])) {
            $formattedLibraries[$library['id']] = [
                'id' => $library['id'],
                'title' => $library['title'],
                'type' => $library['type'],
                'lastSeen' => time()
            ];
        }
    }

    // Update only libraries for this media type
    if (!isset($allLibraries[$mediaType])) {
        $allLibraries[$mediaType] = [];
    }

    // Only replace libraries that match the current media type
    foreach ($formattedLibraries as $id => $library) {
        // For movies/shows, check library type directly
        if (
            ($mediaType === 'movies' && $library['type'] === 'movie') ||
            ($mediaType === 'shows' && $library['type'] === 'show')
        ) {
            $allLibraries[$mediaType][$id] = $library;
        }
        // For collections, store in both movie and show collections
        else if ($mediaType === 'collections') {
            if (!isset($allLibraries['collections'])) {
                $allLibraries['collections'] = [];
            }
            $allLibraries['collections'][$id] = $library;
        }
        // For seasons, they come from show libraries
        else if ($mediaType === 'seasons' && $library['type'] === 'show') {
            if (!isset($allLibraries['seasons'])) {
                $allLibraries['seasons'] = [];
            }
            $allLibraries['seasons'][$id] = $library;
        }
    }

    // Save updated library info
    $json = json_encode($allLibraries, JSON_PRETTY_PRINT);
    return file_put_contents(PLEX_LIBRARIES_FILE, $json) !== false;
}

/**
 * Load stored library information
 * 
 * @return array Stored library information
 */
function loadLibraryInfo()
{
    if (file_exists(PLEX_LIBRARIES_FILE)) {
        $content = file_get_contents(PLEX_LIBRARIES_FILE);
        if ($content) {
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
    }
    return [];
}

/**
 * Check for missing libraries and clear their stored IDs
 * 
 * @param array $currentLibraries Array of currently available library IDs
 * @param string $mediaType Type of media (movies, shows, seasons, collections)
 * @return array Information about cleared libraries
 */
function checkForMissingLibraries($currentLibraries, $mediaType)
{
    $result = [
        'cleared' => [],
        'count' => 0
    ];

    // Get stored library info
    $storedLibraries = loadLibraryInfo();

    // Extract just the library IDs from the current libraries
    $currentLibraryIds = array_map(function ($lib) {
        return $lib['id'];
    }, $currentLibraries);

    // Check if we have this media type stored
    if (isset($storedLibraries[$mediaType])) {
        foreach ($storedLibraries[$mediaType] as $id => $libraryInfo) {
            // If a library was previously seen but is not in the current list
            if (!in_array($id, $currentLibraryIds)) {
                // Clear the stored IDs for this missing library
                $cleared = clearStoredIds($mediaType, $id);
                if ($cleared) {
                    $result['cleared'][] = [
                        'id' => $id,
                        'title' => $libraryInfo['title'] ?? 'Unknown'
                    ];
                    $result['count']++;

                    // Remove from stored libraries
                    unset($storedLibraries[$mediaType][$id]);

                    logDebug("Detected and cleared missing library", [
                        'mediaType' => $mediaType,
                        'libraryId' => $id,
                        'libraryTitle' => $libraryInfo['title'] ?? 'Unknown'
                    ]);
                }
            }
        }

        // Save updated library info
        $json = json_encode($storedLibraries, JSON_PRETTY_PRINT);
        file_put_contents(PLEX_LIBRARIES_FILE, $json);
    }

    return $result;
}

/**
 * Enhanced function to handle orphaned detection that checks for missing libraries
 * 
 * @param string $mediaType Type of media (movies, shows, seasons, collections)
 * @param array $currentLibraries Array of currently available libraries from Plex
 * @return array Results of the missing library check
 */
function handleMissingLibraries($mediaType, $currentLibraries)
{
    // First, store the current libraries
    storeLibraryInfo($currentLibraries, $mediaType);

    // Then check for any missing libraries and clear their stored IDs
    return checkForMissingLibraries($currentLibraries, $mediaType);
}

/**
 * Updated function to detect and mark orphaned posters including handling missing libraries
 * 
 * @param string $targetDir Directory to check for orphaned posters
 * @param array $currentImportIds Current imported IDs
 * @param string $orphanedTag Tag to mark orphaned files with
 * @param string $libraryType Library type (movie/show) for collections
 * @param string $showTitle Show title for season filtering
 * @param string $mediaType Media type
 * @param string $libraryId Current library ID
 * @param bool $refreshMode Whether to replace existing IDs (true) or merge (false)
 * @param array $currentLibraries Array of current libraries from Plex API
 * @return array Results with counts of orphaned files
 */
function enhancedMarkOrphanedPosters(
    $targetDir,
    $currentImportIds,
    $orphanedTag = '--Orphaned--',
    $libraryType = '',
    $showTitle = '',
    $mediaType = '',
    $libraryId = '',
    $refreshMode = true,
    $currentLibraries = []
) {

    // First check for and clear any missing libraries
    if (!empty($currentLibraries) && !empty($mediaType)) {
        handleMissingLibraries($mediaType, $currentLibraries);
    }

    // Then perform the standard orphaned detection
    return improvedMarkOrphanedPosters(
        $targetDir,
        $currentImportIds,
        $orphanedTag,
        $libraryType,
        $showTitle,
        $mediaType,
        $libraryId,
        $refreshMode
    );
}

/**
 * Integration function to be called at the start of each import session
 * 
 * @param string $mediaType The type of media being imported (movies, shows, seasons, collections)
 * @param array $libraries The current libraries available in Plex
 */
function initializeImportSession($mediaType, $libraries)
{
    // Store the current libraries
    storeLibraryInfo($libraries, $mediaType);

    // Check for missing libraries and clear their IDs
    return handleMissingLibraries($mediaType, $libraries);
}
