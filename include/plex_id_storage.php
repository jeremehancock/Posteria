<?php
/**
 * Persistent Storage Solution for Valid Plex IDs
 * 
 * This code provides a solution to maintain valid Plex IDs across sessions.
 * Instead of only using session storage, we'll use a combination of session
 * and file-based storage to ensure IDs are preserved when users log out and back in.
 */

// Define where to store the persistent data
define('PLEX_IDS_STORAGE_FILE', dirname(__DIR__) . '/data/plex_valid_ids.json');

/**
 * Ensures the data directory exists
 */
function ensureDataDirectoryExists() {
    $dir = dirname(PLEX_IDS_STORAGE_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

/**
 * Load valid IDs from persistent storage
 * 
 * @return array The stored valid IDs
 */
function loadValidIdsFromStorage() {
    if (file_exists(PLEX_IDS_STORAGE_FILE)) {
        $content = file_get_contents(PLEX_IDS_STORAGE_FILE);
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
 * Save valid IDs to persistent storage
 * 
 * @param array $ids The complete valid IDs structure to save
 * @return bool Success indicator
 */
function saveValidIdsToStorage($ids) {
    ensureDataDirectoryExists();
    $json = json_encode($ids, JSON_PRETTY_PRINT);
    return file_put_contents(PLEX_IDS_STORAGE_FILE, $json) !== false;
}

/**
 * Modified clearStoredIds function that clears both session and persistent storage
 * 
 * @param string $mediaType Type of media (movies, shows, seasons, collections)
 * @param string $libraryId The Plex library ID
 * @return bool Success indicator
 */
function clearStoredIds($mediaType, $libraryId = null) {
    // Clear from session
    if (isset($_SESSION['valid_plex_ids'])) {
        if ($libraryId === null) {
            // Clear all IDs for this media type
            if (isset($_SESSION['valid_plex_ids'][$mediaType])) {
                unset($_SESSION['valid_plex_ids'][$mediaType]);
                logDebug("Cleared all stored session IDs for media type: {$mediaType}");
            }
        } else {
            // Clear only IDs for this specific library
            if (isset($_SESSION['valid_plex_ids'][$mediaType][$libraryId])) {
                unset($_SESSION['valid_plex_ids'][$mediaType][$libraryId]);
                logDebug("Cleared stored session IDs for media type: {$mediaType}, library: {$libraryId}");
            }
        }
    }
    
    // Also clear from persistent storage
    $storedIds = loadValidIdsFromStorage();
    if (!empty($storedIds)) {
        if ($libraryId === null) {
            // Clear all IDs for this media type
            if (isset($storedIds[$mediaType])) {
                unset($storedIds[$mediaType]);
                logDebug("Cleared all stored persistent IDs for media type: {$mediaType}");
            }
        } else {
            // Clear only IDs for this specific library
            if (isset($storedIds[$mediaType][$libraryId])) {
                unset($storedIds[$mediaType][$libraryId]);
                logDebug("Cleared stored persistent IDs for media type: {$mediaType}, library: {$libraryId}");
            }
        }
        saveValidIdsToStorage($storedIds);
    }
    
    return true;
}

/**
 * Modified storeValidIds function that stores in both session and persistent storage
 * 
 * @param array $newIds Array of IDs that were just imported
 * @param string $mediaType Type of media (movies, shows, seasons, collections)
 * @param string $libraryId The Plex library ID these items came from
 * @param bool $replaceExisting Whether to replace existing IDs (true) or merge with them (false)
 */
function storeValidIds($newIds, $mediaType, $libraryId, $replaceExisting = false) {
    // Store in session for current browsing session
    if (!isset($_SESSION['valid_plex_ids'])) {
        $_SESSION['valid_plex_ids'] = [];
    }
    
    if (!isset($_SESSION['valid_plex_ids'][$mediaType])) {
        $_SESSION['valid_plex_ids'][$mediaType] = [];
    }
    
    // If replace mode, clear existing IDs for this library first
    if ($replaceExisting && isset($_SESSION['valid_plex_ids'][$mediaType][$libraryId])) {
        unset($_SESSION['valid_plex_ids'][$mediaType][$libraryId]);
    }
    
    // Store or update IDs for this specific library in session
    $_SESSION['valid_plex_ids'][$mediaType][$libraryId] = $newIds;
    
    // Also store in persistent storage
    $storedIds = loadValidIdsFromStorage();
    
    if (!isset($storedIds[$mediaType])) {
        $storedIds[$mediaType] = [];
    }
    
    // If replace mode, clear existing IDs for this library first
    if ($replaceExisting && isset($storedIds[$mediaType][$libraryId])) {
        unset($storedIds[$mediaType][$libraryId]);
    }
    
    // Store or update IDs for this specific library in persistent storage
    $storedIds[$mediaType][$libraryId] = $newIds;
    saveValidIdsToStorage($storedIds);
    
    logDebug("Stored valid IDs in both session and persistent storage", [
        'mediaType' => $mediaType,
        'libraryId' => $libraryId,
        'newCount' => count($newIds),
        'replaceMode' => $replaceExisting ? 'Yes' : 'No'
    ]);
    
    return true;
}

/**
 * Modified getAllValidIds function that checks both session and persistent storage
 * 
 * @param string $mediaType Type of media (movies, shows, seasons, collections)
 * @return array Array of all valid IDs for this media type
 */
function getAllValidIds($mediaType) {
    $allIds = [];
    
    // First check session
    if (isset($_SESSION['valid_plex_ids']) && isset($_SESSION['valid_plex_ids'][$mediaType])) {
        foreach ($_SESSION['valid_plex_ids'][$mediaType] as $libraryIds) {
            if (is_array($libraryIds)) {
                $allIds = array_merge($allIds, $libraryIds);
            }
        }
    }
    
    // Then check persistent storage
    $storedIds = loadValidIdsFromStorage();
    if (isset($storedIds[$mediaType])) {
        foreach ($storedIds[$mediaType] as $libraryIds) {
            if (is_array($libraryIds)) {
                // Use array_values to ensure we don't have duplicate keys
                $allIds = array_merge($allIds, array_values($libraryIds));
            }
        }
    }
    
    // Remove duplicates
    $allIds = array_unique($allIds);
    
    logDebug("Retrieved all valid IDs from both session and persistent storage", [
        'mediaType' => $mediaType,
        'totalIdCount' => count($allIds)
    ]);
    
    return $allIds;
}

/**
 * Modified getLibrarySpecificIds function that checks both session and persistent storage
 * 
 * @param string $mediaType Type of media (movies, shows, seasons, collections)
 * @param string $libraryId Specific library ID to get IDs for (optional)
 * @return array IDs for the specified library or all libraries
 */
function getLibrarySpecificIds($mediaType, $libraryId = null) {
    if ($libraryId === null) {
        return getAllValidIds($mediaType);
    }
    
    $libraryIds = [];
    
    // Check session first
    if (isset($_SESSION['valid_plex_ids']) && 
        isset($_SESSION['valid_plex_ids'][$mediaType]) && 
        isset($_SESSION['valid_plex_ids'][$mediaType][$libraryId])) {
        $libraryIds = $_SESSION['valid_plex_ids'][$mediaType][$libraryId];
    }
    
    // Then check persistent storage
    $storedIds = loadValidIdsFromStorage();
    if (isset($storedIds[$mediaType]) && 
        isset($storedIds[$mediaType][$libraryId])) {
        // Merge with session IDs
        $libraryIds = array_merge($libraryIds, $storedIds[$mediaType][$libraryId]);
        // Remove duplicates
        $libraryIds = array_unique($libraryIds);
    }
    
    return $libraryIds;
}

/**
 * Initializes session data from persistent storage on login
 * Call this function after user successfully logs in
 */
function initializeSessionFromStorage() {
    if (!isset($_SESSION['valid_plex_ids'])) {
        $_SESSION['valid_plex_ids'] = loadValidIdsFromStorage();
        logDebug("Initialized session valid_plex_ids from persistent storage");
    }
}

/**
 * Synchronizes session data to persistent storage
 * This should be called periodically during user's session
 */
function syncSessionToStorage() {
    if (isset($_SESSION['valid_plex_ids'])) {
        saveValidIdsToStorage($_SESSION['valid_plex_ids']);
        logDebug("Synchronized session valid_plex_ids to persistent storage");
    }
}

/**
 * Reset both persistent and session storage for testing
 */
function resetAllStoredIds() {
    if (isset($_SESSION['valid_plex_ids'])) {
        unset($_SESSION['valid_plex_ids']);
    }
    
    if (file_exists(PLEX_IDS_STORAGE_FILE)) {
        unlink(PLEX_IDS_STORAGE_FILE);
    }
    
    logDebug("Reset all stored valid IDs (both session and persistent)");
}
