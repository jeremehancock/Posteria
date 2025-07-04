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
                'user' => isset($video['User']['title']) ? $video['User']['title'] : 'Unknown User'
            ];

            // Add TV show specific information
            if ($stream['type'] == 'episode') {
                $stream['show_title'] = $video['grandparentTitle'] ?? '';
                $stream['season'] = $video['parentIndex'] ?? '';
                $stream['episode'] = $video['index'] ?? '';
                $stream['show_thumb'] = $video['grandparentThumb'] ?? ''; // Add this line
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
                'user' => (string) $video->User['title']
            ];

            // Add TV show specific information
            if ($stream['type'] == 'episode') {
                $stream['show_title'] = (string) $video['grandparentTitle'];
                $stream['season'] = (string) $video['parentIndex'];
                $stream['episode'] = (string) $video['index'];
                $stream['show_thumb'] = (string) $video['grandparentThumb']; // Add this line
            }

            $streams[] = $stream;
        }
    }

    return $streams;
}

// Function to get random library items - MODIFIED to fetch more varied items
function getRandomLibraryItems($server_url, $token, $count = 20)
{
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
        CURLOPT_HTTPHEADER => ['Accept: application/json'] // Request JSON response
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

        // Fallback to XML parsing
        return getRandomLibraryItemsXML($response, $server_url, $token, $count);
    }

    // Get library keys from JSON
    $library_keys = [];
    if (isset($data['MediaContainer']['Directory'])) {
        foreach ($data['MediaContainer']['Directory'] as $directory) {
            // Only include movie and TV show libraries, exclude music
            if ($directory['type'] == 'movie' || $directory['type'] == 'show') {
                $library_keys[] = $directory['key'];
            }
        }
    }

    // Fetch items from each library with a better distribution
    $all_items = [];
    $items_per_library = ceil($count * 2 / max(1, count($library_keys))); // Fetch more than we need

    foreach ($library_keys as $key) {
        $library_items = fetchItemsFromLibrary($key, $server_url, $token, $items_per_library);
        $all_items = array_merge($all_items, $library_items);

        error_log("Fetched " . count($library_items) . " items from library {$key}");
    }

    // Shuffle and limit the items
    shuffle($all_items);
    return array_slice($all_items, 0, $count);
}

// Helper function to fetch items from a single library
function fetchItemsFromLibrary($library_key, $server_url, $token, $items_count)
{
    $items_url = "{$server_url}/library/sections/{$library_key}/all?X-Plex-Token={$token}";
    error_log("Fetching items from library {$library_key}: {$items_url}");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $items_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'] // Request JSON response
    ]);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("cURL error when fetching items from library {$library_key}: " . curl_error($ch));
        curl_close($ch);
        return []; // Return empty array on error
    }

    curl_close($ch);

    if (!$response) {
        error_log("Empty response when fetching items from library {$library_key}");
        return []; // Return empty array if no response
    }

    // Parse the response and extract items
    $library_items = [];

    // Try to parse JSON
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parsing error in library {$library_key}: " . json_last_error_msg());

        // Try parsing as XML
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

                $newItem = [
                    'title' => $item['title'] ?? '',
                    'type' => $item['type'] ?? '',
                    'thumb' => $item['thumb'] ?? '',
                    'art' => $item['art'] ?? '',
                    'year' => $item['year'] ?? ''
                ];

                $library_items[] = $newItem;
            }
        }
    }

    // If we have many items, take a diverse sample
    if (count($library_items) > $items_count * 2) {
        error_log("Taking diverse sample from " . count($library_items) . " items in library {$library_key}");

        // Get a diverse selection by taking items at regular intervals
        $sampled_items = [];
        $step = count($library_items) / $items_count;

        for ($i = 0; $i < $items_count; $i++) {
            $index = min(floor($i * $step), count($library_items) - 1);
            $sampled_items[] = $library_items[$index];
        }

        // Also add some random items for variety
        $random_count = min(5, ceil($items_count / 4));
        for ($i = 0; $i < $random_count; $i++) {
            $random_index = mt_rand(0, count($library_items) - 1);
            $sampled_items[] = $library_items[$random_index];
        }

        return $sampled_items;
    }

    return $library_items;
}

// Fallback XML parsing for libraries
function getRandomLibraryItemsXML($response, $server_url, $token, $count)
{
    // Try to parse the XML
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
            // Only include movie and TV show libraries, exclude music
            if ((string) $directory['type'] == 'movie' || (string) $directory['type'] == 'show') {
                $library_keys[] = (string) $directory['key'];
            }
        }
    }

    // Fetch items from each library
    $all_items = [];
    $items_per_library = ceil($count * 2 / max(1, count($library_keys))); // Fetch more than we need

    foreach ($library_keys as $key) {
        $library_items = fetchItemsFromLibrary($key, $server_url, $token, $items_per_library);
        $all_items = array_merge($all_items, $library_items);
    }

    // Shuffle and limit the items
    shuffle($all_items);
    return array_slice($all_items, 0, $count);
}

// Parse XML formatted library items (fallback method)
function parseLibraryItemsXML($response)
{
    $items = [];

    // Try to parse the XML
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
            // Skip music and live items
            if (
                (isset($video['type']) && (string) $video['type'] == 'track') ||
                (isset($video['live']) && (string) $video['live'] == '1')
            ) {
                continue;
            }

            $item = [
                'title' => (string) $video['title'],
                'type' => (string) $video['type'],
                'thumb' => (string) $video['thumb'],
                'art' => (string) $video['art'],
                'year' => (string) $video['year']
            ];

            $items[] = $item;
        }
    } elseif ($xml && isset($xml->Directory)) {
        foreach ($xml->Directory as $directory) {
            // Skip music libraries
            if (isset($directory['type']) && (string) $directory['type'] == 'artist') {
                continue;
            }

            $item = [
                'title' => (string) $directory['title'],
                'type' => (string) $directory['type'],
                'thumb' => (string) $directory['thumb'],
                'art' => (string) $directory['art'],
                'year' => (string) $directory['year']
            ];

            $items[] = $item;
        }
    }

    return $items;
}

// Get active streams
$active_streams = getActiveStreams($plex_url, $plex_token);

// Get batch parameter for poster variety
$batch = isset($_GET['batch']) ? intval($_GET['batch']) : 1;

// Get a higher count for random items to allow for more variety
$random_items_count = 40; // Get more items than we'll return

// Get random items if needed
$random_items = [];
if (empty($active_streams)) {
    $random_items = getRandomLibraryItems($plex_url, $plex_token, $random_items_count);
}

// Use the batch number to provide variety in which random items we return
if (!empty($random_items) && count($random_items) > 20) {
    // Seed random generator with batch to get consistent but different results per batch
    srand($batch * 13); // Multiply by a prime number for better distribution
    shuffle($random_items);

    // Take the first 20 items after shuffling
    $random_items = array_slice($random_items, 0, 20);

    error_log("Returning 20 random items for batch {$batch}");
}

// Add a timestamp to help with debugging
$response = [
    'active_streams' => $active_streams,
    'random_items' => $random_items,
    'batch' => $batch,
    'timestamp' => time()
];

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>