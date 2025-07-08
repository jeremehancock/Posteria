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

// ENHANCED: Better cache control to prevent repetition
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once '../include/config.php';

// Initialize Plex server connection
$plex_url = $plex_config['server_url'];
$plex_token = $plex_config['token'];

// Function to check for active streams - direct connection
function getActiveStreams($server_url, $token)
{
    $sessions_url = "{$server_url}/status/sessions?X-Plex-Token={$token}";
    error_log("Fetching active streams from: {$sessions_url}");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $sessions_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'] // Request JSON response
    ]);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("cURL error when fetching sessions: " . curl_error($ch));
        curl_close($ch);
        return [];
    }

    curl_close($ch);

    if (!$response) {
        error_log("Empty response when fetching sessions");
        return [];
    }

    // Parse JSON response
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parsing error: " . json_last_error_msg());

        // Try to parse as XML if JSON fails
        return parseXMLStreams($response);
    }

    return parseJSONStreams($data);
}

// Parse JSON formatted response
function parseJSONStreams($data)
{
    $streams = [];

    if (isset($data['MediaContainer']['Metadata'])) {
        foreach ($data['MediaContainer']['Metadata'] as $video) {
            // Skip live TV streams
            if (isset($video['live']) && $video['live'] == 1) {
                continue;
            }

            // Skip music tracks
            if (isset($video['type']) && $video['type'] == 'track') {
                continue;
            }

            $stream = [
                'title' => $video['title'] ?? '',
                'type' => $video['type'] ?? '',
                'thumb' => $video['thumb'] ?? '',
                'art' => $video['art'] ?? '',
                'year' => $video['year'] ?? '',
                'summary' => $video['summary'] ?? '',
                'duration' => $video['duration'] ?? 0,
                'viewOffset' => $video['viewOffset'] ?? 0,
                'user' => isset($video['User']['title']) ? $video['User']['title'] : 'Unknown User',
                'ratingKey' => $video['ratingKey'] ?? '', // ENHANCED: Add for better deduplication
                'addedAt' => $video['addedAt'] ?? ''
            ];

            // Add TV show specific information
            if ($stream['type'] == 'episode') {
                $stream['show_title'] = $video['grandparentTitle'] ?? '';
                $stream['season'] = $video['parentIndex'] ?? '';
                $stream['episode'] = $video['index'] ?? '';
                $stream['show_thumb'] = $video['grandparentThumb'] ?? '';
            }

            $streams[] = $stream;
        }
    }

    return $streams;
}

// Parse XML formatted response (fallback method)
function parseXMLStreams($response)
{
    // Try to parse the XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);

    if ($xml === false) {
        $errors = libxml_get_errors();
        foreach ($errors as $error) {
            error_log("XML parsing error: {$error->message}");
        }
        libxml_clear_errors();
        return [];
    }

    $streams = [];
    if ($xml && isset($xml->Video)) {
        foreach ($xml->Video as $video) {
            // Skip live TV streams
            if (isset($video['live']) && (string) $video['live'] == '1') {
                continue;
            }

            // Skip music tracks
            if (isset($video['type']) && (string) $video['type'] == 'track') {
                continue;
            }

            $stream = [
                'title' => (string) $video['title'],
                'type' => (string) $video['type'],
                'thumb' => (string) $video['thumb'],
                'art' => (string) $video['art'],
                'year' => (string) $video['year'],
                'summary' => (string) $video['summary'],
                'duration' => (int) $video['duration'],
                'viewOffset' => (int) $video['viewOffset'],
                'user' => (string) $video->User['title'],
                'ratingKey' => (string) $video['ratingKey'], // ENHANCED: Add for better deduplication
                'addedAt' => (string) $video['addedAt']
            ];

            // Add TV show specific information
            if ($stream['type'] == 'episode') {
                $stream['show_title'] = (string) $video['grandparentTitle'];
                $stream['season'] = (string) $video['parentIndex'];
                $stream['episode'] = (string) $video['index'];
                $stream['show_thumb'] = (string) $video['grandparentThumb'];
            }

            $streams[] = $stream;
        }
    }

    return $streams;
}

// ENHANCED: Function to get random library items with much better variety
function getRandomLibraryItems($server_url, $token, $count = 20, $batch = 1, $seed = null, $session = null)
{
    // ENHANCED: Better randomization using multiple factors
    $randomization_seed = $seed ?? (time() + $batch * 17 + crc32($session ?? '') + mt_rand(1, 10000));
    mt_srand($randomization_seed);

    error_log("Getting random items: count={$count}, batch={$batch}, seed={$randomization_seed}");

    // Get all libraries
    $libraries_url = "{$server_url}/library/sections?X-Plex-Token={$token}";
    error_log("Fetching libraries from: {$libraries_url}");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $libraries_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("cURL error when fetching libraries: " . curl_error($ch));
        curl_close($ch);
        return [];
    }

    curl_close($ch);

    if (!$response) {
        error_log("Empty response when fetching libraries");
        return [];
    }

    // Try to parse JSON
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parsing error when fetching libraries: " . json_last_error_msg());
        return getRandomLibraryItemsXML($response, $server_url, $token, $count, $batch, $seed, $session);
    }

    // Get library keys from JSON
    $library_keys = [];
    if (isset($data['MediaContainer']['Directory'])) {
        foreach ($data['MediaContainer']['Directory'] as $directory) {
            if ($directory['type'] == 'movie' || $directory['type'] == 'show') {
                $library_keys[] = $directory['key'];
            }
        }
    }

    if (empty($library_keys)) {
        error_log("No valid libraries found");
        return [];
    }

    // ENHANCED: Shuffle libraries to vary which ones we sample from
    shuffle($library_keys);
    error_log("Found " . count($library_keys) . " libraries");

    // ENHANCED: Fetch from multiple random points in each library
    $all_items = [];
    $target_items = $count * 3; // Get 3x what we need for better selection
    $items_per_library = ceil($target_items / count($library_keys));

    foreach ($library_keys as $library_index => $key) {
        // ENHANCED: Use multiple random offsets per library for variety
        $offsets = [
            mt_rand(0, 100),
            mt_rand(100, 300),
            mt_rand(300, 600),
            mt_rand(600, 1000)
        ];

        $library_items = [];

        foreach ($offsets as $offset_index => $offset) {
            $batch_size = ceil($items_per_library / count($offsets));
            $library_batch = fetchItemsFromLibrary($key, $server_url, $token, $batch_size, $offset);
            $library_items = array_merge($library_items, $library_batch);

            // Break early if we have enough items from this library
            if (count($library_items) >= $items_per_library) {
                break;
            }
        }

        $all_items = array_merge($all_items, $library_items);
        error_log("Fetched " . count($library_items) . " items from library {$key}");

        // Break if we have more than enough items
        if (count($all_items) >= $target_items) {
            break;
        }
    }

    // ENHANCED: Better deduplication using ratingKey
    $unique_items = [];
    $seen_keys = [];

    foreach ($all_items as $item) {
        $key = $item['ratingKey'] ?? ($item['title'] . '_' . $item['year']);
        if (!in_array($key, $seen_keys)) {
            $unique_items[] = $item;
            $seen_keys[] = $key;
        }
    }

    error_log("After deduplication: " . count($unique_items) . " unique items");

    // ENHANCED: Multiple shuffle passes for better randomization
    for ($i = 0; $i < 5; $i++) {
        shuffle($unique_items);
    }

    // ENHANCED: Add some randomness to the final count
    $final_count = min($count + mt_rand(0, 5), count($unique_items));
    $final_items = array_slice($unique_items, 0, $final_count);

    error_log("Returning " . count($final_items) . " final items");
    return $final_items;
}

// ENHANCED: Helper function to fetch items from a single library with offset support
function fetchItemsFromLibrary($library_key, $server_url, $token, $items_count, $offset = 0)
{
    // ENHANCED: Add offset parameter for pagination
    $items_url = "{$server_url}/library/sections/{$library_key}/all?X-Plex-Token={$token}&X-Plex-Container-Start={$offset}&X-Plex-Container-Size={$items_count}";
    error_log("Fetching items from library {$library_key} with offset {$offset}: {$items_url}");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $items_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20, // Reduced timeout for pagination requests
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("cURL error when fetching items from library {$library_key}: " . curl_error($ch));
        curl_close($ch);
        return [];
    }

    curl_close($ch);

    if (!$response) {
        error_log("Empty response when fetching items from library {$library_key}");
        return [];
    }

    $library_items = [];

    // Try to parse JSON
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parsing error in library {$library_key}: " . json_last_error_msg());
        $library_items = parseLibraryItemsXML($response);
    } else {
        // Parse JSON items
        if (isset($data['MediaContainer']['Metadata'])) {
            foreach ($data['MediaContainer']['Metadata'] as $item) {
                // Skip music and live items
                if (
                    (isset($item['type']) && $item['type'] == 'track') ||
                    (isset($item['live']) && $item['live'] == 1)
                ) {
                    continue;
                }

                // ENHANCED: Skip items without proper thumbnails
                if (empty($item['thumb']) && empty($item['art'])) {
                    continue;
                }

                $newItem = [
                    'title' => $item['title'] ?? '',
                    'type' => $item['type'] ?? '',
                    'thumb' => $item['thumb'] ?? '',
                    'art' => $item['art'] ?? '',
                    'year' => $item['year'] ?? '',
                    'ratingKey' => $item['ratingKey'] ?? '', // ENHANCED: For deduplication
                    'addedAt' => $item['addedAt'] ?? '',
                    'duration' => $item['duration'] ?? 0
                ];

                $library_items[] = $newItem;
            }
        }

        // ENHANCED: Also check for Directory items (TV shows)
        if (isset($data['MediaContainer']['Directory'])) {
            foreach ($data['MediaContainer']['Directory'] as $item) {
                // Skip items without proper thumbnails
                if (empty($item['thumb']) && empty($item['art'])) {
                    continue;
                }

                $newItem = [
                    'title' => $item['title'] ?? '',
                    'type' => $item['type'] ?? '',
                    'thumb' => $item['thumb'] ?? '',
                    'art' => $item['art'] ?? '',
                    'year' => $item['year'] ?? '',
                    'ratingKey' => $item['ratingKey'] ?? '',
                    'addedAt' => $item['addedAt'] ?? ''
                ];

                $library_items[] = $newItem;
            }
        }
    }

    error_log("Fetched " . count($library_items) . " items from library {$library_key} at offset {$offset}");
    return $library_items;
}

// ENHANCED: Fallback XML parsing for libraries with batch support
function getRandomLibraryItemsXML($response, $server_url, $token, $count, $batch = 1, $seed = null, $session = null)
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);

    if ($xml === false) {
        $errors = libxml_get_errors();
        foreach ($errors as $error) {
            error_log("XML parsing error in libraries: {$error->message}");
        }
        libxml_clear_errors();
        return [];
    }

    $library_keys = [];
    if ($xml && isset($xml->Directory)) {
        foreach ($xml->Directory as $directory) {
            if ((string) $directory['type'] == 'movie' || (string) $directory['type'] == 'show') {
                $library_keys[] = (string) $directory['key'];
            }
        }
    }

    // ENHANCED: Use the same improved logic as JSON version
    shuffle($library_keys);
    $all_items = [];
    $target_items = $count * 3;
    $items_per_library = ceil($target_items / max(1, count($library_keys)));

    foreach ($library_keys as $key) {
        $offsets = [
            mt_rand(0, 100),
            mt_rand(100, 300),
            mt_rand(300, 600)
        ];

        $library_items = [];
        foreach ($offsets as $offset) {
            $batch_size = ceil($items_per_library / count($offsets));
            $library_batch = fetchItemsFromLibrary($key, $server_url, $token, $batch_size, $offset);
            $library_items = array_merge($library_items, $library_batch);

            if (count($library_items) >= $items_per_library) {
                break;
            }
        }

        $all_items = array_merge($all_items, $library_items);

        if (count($all_items) >= $target_items) {
            break;
        }
    }

    // Deduplication and shuffling
    $unique_items = [];
    $seen_keys = [];

    foreach ($all_items as $item) {
        $key = $item['ratingKey'] ?? ($item['title'] . '_' . $item['year']);
        if (!in_array($key, $seen_keys)) {
            $unique_items[] = $item;
            $seen_keys[] = $key;
        }
    }

    for ($i = 0; $i < 5; $i++) {
        shuffle($unique_items);
    }

    return array_slice($unique_items, 0, $count);
}

// Parse XML formatted library items (fallback method)
function parseLibraryItemsXML($response)
{
    $items = [];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);

    if ($xml === false) {
        $errors = libxml_get_errors();
        foreach ($errors as $error) {
            error_log("XML parsing error in library items: {$error->message}");
        }
        libxml_clear_errors();
        return $items;
    }

    if ($xml && isset($xml->Video)) {
        foreach ($xml->Video as $video) {
            if (
                (isset($video['type']) && (string) $video['type'] == 'track') ||
                (isset($video['live']) && (string) $video['live'] == '1')
            ) {
                continue;
            }

            // ENHANCED: Skip items without thumbnails
            if (empty((string) $video['thumb']) && empty((string) $video['art'])) {
                continue;
            }

            $item = [
                'title' => (string) $video['title'],
                'type' => (string) $video['type'],
                'thumb' => (string) $video['thumb'],
                'art' => (string) $video['art'],
                'year' => (string) $video['year'],
                'ratingKey' => (string) $video['ratingKey'],
                'addedAt' => (string) $video['addedAt'],
                'duration' => (int) $video['duration']
            ];

            $items[] = $item;
        }
    } elseif ($xml && isset($xml->Directory)) {
        foreach ($xml->Directory as $directory) {
            if (isset($directory['type']) && (string) $directory['type'] == 'artist') {
                continue;
            }

            // ENHANCED: Skip items without thumbnails
            if (empty((string) $directory['thumb']) && empty((string) $directory['art'])) {
                continue;
            }

            $item = [
                'title' => (string) $directory['title'],
                'type' => (string) $directory['type'],
                'thumb' => (string) $directory['thumb'],
                'art' => (string) $directory['art'],
                'year' => (string) $directory['year'],
                'ratingKey' => (string) $directory['ratingKey'],
                'addedAt' => (string) $directory['addedAt']
            ];

            $items[] = $item;
        }
    }

    return $items;
}

// ENHANCED: Get parameters with better defaults
$batch = isset($_GET['batch']) ? intval($_GET['batch']) : time(); // Use timestamp if no batch
$count = isset($_GET['count']) ? min(intval($_GET['count']), 50) : 20; // Limit max count
$seed = isset($_GET['seed']) ? intval($_GET['seed']) : null;
$session = isset($_GET['session']) ? $_GET['session'] : null;
$check_timestamp = isset($_GET['check']) ? $_GET['check'] : null;

error_log("Request params: batch={$batch}, count={$count}, seed={$seed}, session={$session}");

// Get active streams
$active_streams = getActiveStreams($plex_url, $plex_token);

// ENHANCED: Get random items with improved variety
$random_items = [];
if (empty($active_streams) || $check_timestamp) {
    // ENHANCED: Always fetch some random items for variety, even during stream checks
    $target_count = empty($active_streams) ? $count : min($count, 10);
    $random_items = getRandomLibraryItems($plex_url, $plex_token, $target_count, $batch, $seed, $session);
}

// ENHANCED: Improved response with more metadata
$response = [
    'active_streams' => $active_streams,
    'random_items' => $random_items,
    'batch' => $batch,
    'timestamp' => time(),
    'has_streams' => !empty($active_streams),
    'random_count' => count($random_items),
    'stream_count' => count($active_streams),
    'session' => $session
];

error_log("Response: " . count($active_streams) . " streams, " . count($random_items) . " random items");

echo json_encode($response);
?>