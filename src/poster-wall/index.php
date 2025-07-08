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

// Helper function to get environment variable with fallback
function getEnvWithFallback($key, $default)
{
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

$config = [
    'siteUrl' => (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/',
];

// Site title configuration
$site_title = getEnvWithFallback('SITE_TITLE', 'Posteria') . ' Poster Wall';

// Function to get active streams
function getActiveStreams($server_url, $token)
{
    $sessions_url = "{$server_url}/status/sessions?X-Plex-Token={$token}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $sessions_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        curl_close($ch);
        return [];
    }

    curl_close($ch);

    if (!$response) {
        return [];
    }

    // Try to parse JSON
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Fallback to XML parsing
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

            // Additional check for LiveTV type
            if (isset($video['type']) && (strtolower($video['type']) == 'livetv' || strtolower($video['type']) == 'live')) {
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

            // Additional check for LiveTV type
            if (isset($video['type']) && (strtolower((string) $video['type']) == 'livetv' || strtolower((string) $video['type']) == 'live')) {
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
                $stream['show_thumb'] = (string) $video['grandparentThumb'];
            }

            $streams[] = $stream;
        }
    }

    return $streams;
}

// FIXED: Improved random library items function with better randomization
function getRandomLibraryItems($server_url, $token, $count = 10, $seed = null)
{
    // Use seed for deterministic randomization or time-based for true randomness
    if ($seed !== null) {
        mt_srand($seed);
    } else {
        mt_srand(time() + mt_rand(1, 10000)); // Add extra randomness
    }

    // Get all libraries
    $libraries_url = "{$server_url}/library/sections?X-Plex-Token={$token}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $libraries_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        curl_close($ch);
        return [];
    }

    curl_close($ch);

    if (!$response) {
        return [];
    }

    // Try to parse the XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);

    if ($xml === false) {
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

    // Shuffle library keys to randomize which libraries we pull from
    shuffle($library_keys);

    // Get items from libraries with better randomization
    $all_items = [];
    foreach ($library_keys as $key) {
        // Add random offset to get different starting points in the library
        $random_offset = mt_rand(0, 500);
        $batch_size = min($count * 3, 100); // Get more items than needed for better selection

        $items_url = "{$server_url}/library/sections/{$key}/all?X-Plex-Token={$token}&X-Plex-Container-Start={$random_offset}&X-Plex-Container-Size={$batch_size}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $items_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            curl_close($ch);
            continue;
        }

        curl_close($ch);

        if (!$response) {
            continue;
        }

        // Try to parse the XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);

        if ($xml === false) {
            libxml_clear_errors();
            continue;
        }

        if ($xml && isset($xml->Video)) {
            foreach ($xml->Video as $video) {
                $item = [
                    'title' => (string) $video['title'],
                    'type' => (string) $video['type'],
                    'thumb' => (string) $video['thumb'],
                    'art' => (string) $video['art'],
                    'year' => (string) $video['year'],
                    'ratingKey' => (string) $video['ratingKey'], // Add unique identifier
                    'addedAt' => (string) $video['addedAt']
                ];

                $all_items[] = $item;
            }
        } elseif ($xml && isset($xml->Directory)) {
            foreach ($xml->Directory as $directory) {
                $item = [
                    'title' => (string) $directory['title'],
                    'type' => (string) $directory['type'],
                    'thumb' => (string) $directory['thumb'],
                    'art' => (string) $directory['art'],
                    'year' => (string) $directory['year'],
                    'ratingKey' => (string) $directory['ratingKey'], // Add unique identifier
                    'addedAt' => (string) $directory['addedAt']
                ];

                $all_items[] = $item;
            }
        }
    }

    // Remove duplicates based on ratingKey
    $unique_items = [];
    $seen_keys = [];
    foreach ($all_items as $item) {
        $key = $item['ratingKey'] ?? $item['title'];
        if (!in_array($key, $seen_keys)) {
            $unique_items[] = $item;
            $seen_keys[] = $key;
        }
    }

    // Multiple randomization passes for better distribution
    for ($i = 0; $i < 3; $i++) {
        shuffle($unique_items);
    }

    // Return the requested count, but ensure variety
    $final_items = array_slice($unique_items, 0, $count);

    // If we don't have enough items, pad with more random selections
    if (count($final_items) < $count && count($unique_items) > count($final_items)) {
        $remaining_needed = $count - count($final_items);
        $remaining_items = array_slice($unique_items, count($final_items));
        shuffle($remaining_items);
        $final_items = array_merge($final_items, array_slice($remaining_items, 0, $remaining_needed));
    }

    return $final_items;
}

// Get active streams
$active_streams = getActiveStreams($plex_url, $plex_token);

// Get random items with better randomization
$random_seed = isset($_GET['seed']) ? intval($_GET['seed']) : null;
$random_count = isset($_GET['count']) ? min(intval($_GET['count']), 50) : 15; // Increased default
$random_items = getRandomLibraryItems($plex_url, $plex_token, $random_count, $random_seed);

// Ensure we have items to display
if (empty($active_streams) && empty($random_items)) {
    $items = [
        [
            'title' => 'No content available',
            'type' => 'placeholder',
            'thumb' => '',
            'art' => '',
        ]
    ];
} else {
    $items = empty($active_streams) ? $random_items : $active_streams;
}

// Convert items to JSON for JavaScript
$items_json = json_encode($items);

$proxy_url = "./proxy.php";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_title); ?></title>
    <meta property="og:title" content="Posteria" />
    <meta property="og:type" content="website" />
    <meta property="og:description" content="Posteria" />
    <meta property="og:url" content="<?php echo htmlspecialchars($config['siteUrl']); ?>" />
    <meta property="og:image"
        content="<?php echo htmlspecialchars($config['siteUrl']); ?>/assets/web-app-manifest-512x512.png" />
    <link rel="icon" type="image/png" href="/assets/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg" />
    <link rel="shortcut icon" href="../assets/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="Posteria" />
    <link rel="manifest" href="../assets/site.webmanifest" />
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Theme Variables */
        :root {
            /* Colors */
            --bg-primary: #1f1f1f;
            --bg-secondary: #282828;
            --bg-tertiary: #333333;
            --text-primary: #ffffff;
            --text-secondary: #999999;
            --accent-primary: #e5a00d;
            --accent-hover: #f5b025;
            --border-color: #3b3b3b;
            --card-bg: #282828;
            --card-hover: #333333;
            --success-color: #2ed573;
            --danger-color: #ff4757;
            --action-bg: rgba(0, 0, 0, 0.85);

            /* Shadows */
            --shadow-sm: 0 4px 6px rgba(0, 0, 0, 0.4);
            --shadow-md: 0 6px 12px rgba(0, 0, 0, 0.5);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background-color: #1f1f1f;
            color: #fff;
            overflow: hidden;
            width: 100vw;
            height: 100vh;
            position: relative;
        }

        #background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            filter: blur(30px);
            opacity: 0.4;
            transition: background-image 1s ease;
            z-index: 1;
        }

        #background-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            filter: blur(30px);
            opacity: 0;
            transition: opacity 1.5s ease;
            z-index: 1;
        }

        #poster-container {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            height: 90vh;
            z-index: 2;
            perspective: 1000px;
            overflow: hidden;
        }

        .poster-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }

        .tile {
            position: absolute;
            background-size: cover;
            transform-style: preserve-3d;
            transition: transform 1s ease;
            backface-visibility: hidden;
        }

        .tile-back {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            transform: rotateY(180deg);
            backface-visibility: hidden;
        }

        .stream-info {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            background: rgba(0, 0, 0, 0.7);
            padding: 15px;
            z-index: 3;
        }

        .stream-title {
            font-size: 1.5em;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stream-details {
            font-size: 1em;
            opacity: 0.8;
        }

        .stream-progress {
            width: 100%;
            height: 4px;
            background: rgba(255, 255, 255, 0.2);
            margin-top: 10px;
        }

        .stream-progress-bar {
            height: 100%;
            background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
        }

        .streaming-badge {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
            color: #000;
            padding: 10px;
            text-align: center;
            font-weight: 700;
            font-size: 1.2em;
            z-index: 3;
        }

        .error-container {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: rgba(0, 0, 0, 0.7);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            max-width: 80%;
        }

        @media (max-width: 768px) {
            .stream-title {
                font-size: 1.2em;
            }

            .stream-details {
                font-size: 0.8em;
            }

            .streaming-badge {
                font-size: 0.9em;
                padding: 5px;
            }

            .stream-info {
                padding: 10px;
            }
        }

        @media (max-width: 480px) {
            .stream-title {
                font-size: 1em;
            }

            .stream-details {
                font-size: 0.7em;
            }

            .streaming-badge {
                font-size: 0.8em;
                padding: 3px;
            }

            .stream-info {
                padding: 5px;
            }

            .stream-progress {
                height: 2px;
                margin-top: 5px;
            }
        }
    </style>
</head>

<body>
    <div id="background"></div>
    <div id="background-overlay"></div>
    <div id="poster-container"></div>

    <script>
        // FIXED: Enhanced configuration with better rotation settings
        const config = {
            tileRows: 12,
            tileCols: 8,
            displayDuration: 8000, // Reduced to 8 seconds for more variety
            transitionDuration: 2500, // Slightly faster transitions
            streamCheckInterval: 15000, // Check streams every 15 seconds
            streamCheckDebounce: 1000,
            batchSize: 15, // Increased batch size
            preloadThreshold: 5, // Load new batch earlier
            refreshThreshold: 20, // Force refresh after 20 displays
            maxBatches: 50, // Maximum number of items to keep in memory
            debug: true
        };

        // FIXED: Simplified state management with better tracking
        let state = {
            items: <?php echo !empty($items_json) ? $items_json : '[]'; ?>,
            currentIndex: 0,
            displayCount: 0, // Track how many posters we've shown
            lastRefreshTime: Date.now(),
            isStreaming: <?php echo !empty($active_streams) ? 'true' : 'false'; ?>,
            hasMultipleStreams: <?php echo (count($active_streams) > 1) ? 'true' : 'false'; ?>,

            // Transition state
            isTransitioning: false,
            transitionStartTime: 0,

            // Resource management
            preloadedImages: new Set(),
            seenItems: new Set(), // Track what we've shown to avoid immediate repeats

            // Timers
            displayTimer: null,
            streamCheckTimer: null,
            refreshTimer: null,

            // Utilities
            uniqueId: Date.now(),
            sessionId: Math.random().toString(36).substr(2, 9) // Unique session ID
        };

        // URLs
        const urls = {
            proxy: '<?php echo $proxy_url; ?>',
            checkStreams: './check-streams.php'
        };

        // DOM elements
        const background = document.getElementById('background');
        const backgroundOverlay = document.getElementById('background-overlay');
        const posterContainer = document.getElementById('poster-container');

        // Debug logger
        function debug(message) {
            if (config.debug) {
                const timestamp = new Date().toISOString().split('T')[1].split('.')[0];
                console.log(`[${timestamp}] ${message}`);
            }
        }

        // FIXED: Simplified transition manager
        const transitionManager = {
            start(fromItem, toItem, onComplete) {
                if (state.isTransitioning) {
                    debug("Transition in progress, skipping");
                    return false;
                }

                const fromPosterUrl = getPosterUrl(fromItem);
                const toPosterUrl = getPosterUrl(toItem);

                if (!fromPosterUrl || !toPosterUrl) {
                    debug("Missing poster URLs");
                    if (onComplete) onComplete(false);
                    return false;
                }

                state.isTransitioning = true;
                state.transitionStartTime = Date.now();

                // Start background transition
                this.transitionBackground(toItem);

                // Create and animate tiles
                setTimeout(() => {
                    this.createAndAnimateTiles(fromPosterUrl, toPosterUrl, () => {
                        state.isTransitioning = false;
                        if (onComplete) onComplete(true);
                        updateDisplay();
                    });
                }, 50);

                return true;
            },

            createAndAnimateTiles(fromUrl, toUrl, onComplete) {
                posterContainer.innerHTML = '';

                const width = posterContainer.offsetWidth;
                const height = posterContainer.offsetHeight;
                const tileWidth = Math.floor(width / config.tileCols);
                const tileHeight = Math.floor(height / config.tileRows);

                const tiles = [];

                // Create tiles
                for (let row = 0; row < config.tileRows; row++) {
                    for (let col = 0; col < config.tileCols; col++) {
                        const tile = this.createTile(row, col, tileWidth, tileHeight, width, height, fromUrl, toUrl);
                        posterContainer.appendChild(tile);
                        tiles.push(tile);
                    }
                }

                // Animate tiles
                this.animateTiles(tiles, onComplete);
            },

            createTile(row, col, tileWidth, tileHeight, totalWidth, totalHeight, fromUrl, toUrl) {
                const isLastCol = col === config.tileCols - 1;
                const isLastRow = row === config.tileRows - 1;
                const actualWidth = isLastCol ? totalWidth - (col * tileWidth) : tileWidth;
                const actualHeight = isLastRow ? totalHeight - (row * tileHeight) : tileHeight;

                const tile = document.createElement('div');
                tile.className = 'tile';
                tile.style.cssText = `
                    width: ${actualWidth}px;
                    height: ${actualHeight}px;
                    top: ${row * tileHeight}px;
                    left: ${col * tileWidth}px;
                    background-image: url(${fromUrl});
                    background-position: -${col * tileWidth}px -${row * tileHeight}px;
                    background-size: ${totalWidth}px ${totalHeight}px;
                `;

                const tileBack = document.createElement('div');
                tileBack.className = 'tile-back';
                tileBack.style.cssText = `
                    background-image: url(${toUrl});
                    background-position: -${col * tileWidth}px -${row * tileHeight}px;
                    background-size: ${totalWidth}px ${totalHeight}px;
                `;

                tile.appendChild(tileBack);
                return tile;
            },

            animateTiles(tiles, onComplete) {
                const shuffledTiles = [...tiles];
                shuffleArray(shuffledTiles);

                let completed = 0;
                const total = tiles.length;

                shuffledTiles.forEach((tile, index) => {
                    const delay = (index / total) * config.transitionDuration * 0.8;

                    setTimeout(() => {
                        tile.style.transform = 'rotateY(180deg)';
                        completed++;

                        if (completed === total) {
                            setTimeout(onComplete, 200);
                        }
                    }, delay);
                });
            },

            transitionBackground(item) {
                const artUrl = item.art || item.thumb ? getProxyUrl(item.art || item.thumb) : '';
                if (artUrl) {
                    backgroundOverlay.style.backgroundImage = `url(${artUrl})`;
                    setTimeout(() => {
                        backgroundOverlay.style.opacity = '0.4';
                        setTimeout(() => {
                            background.style.backgroundImage = `url(${artUrl})`;
                            backgroundOverlay.style.opacity = '0';
                        }, 1500);
                    }, 100);
                }
            }
        };

        // FIXED: Improved timer management
        const timerManager = {
            startDisplayTimer() {
                this.stopDisplayTimer();
                state.displayTimer = setInterval(() => {
                    if (!state.isTransitioning) {
                        nextPoster();
                    }
                }, config.displayDuration);
                debug('Display timer started');
            },

            stopDisplayTimer() {
                if (state.displayTimer) {
                    clearInterval(state.displayTimer);
                    state.displayTimer = null;
                    debug('Display timer stopped');
                }
            },

            startStreamChecking() {
                if (state.streamCheckTimer) {
                    clearInterval(state.streamCheckTimer);
                }

                // Initial check after 5 seconds
                setTimeout(checkForStreams, 5000);

                state.streamCheckTimer = setInterval(checkForStreams, config.streamCheckInterval);
                debug('Stream checking started');
            },

            startRefreshTimer() {
                // Force refresh every 10 minutes to ensure variety
                if (state.refreshTimer) {
                    clearInterval(state.refreshTimer);
                }

                state.refreshTimer = setInterval(() => {
                    if (!state.isStreaming) {
                        debug('Forcing refresh for variety');
                        loadFreshBatch(true);
                    }
                }, 600000); // 10 minutes
            },

            cleanup() {
                this.stopDisplayTimer();
                if (state.streamCheckTimer) {
                    clearInterval(state.streamCheckTimer);
                    state.streamCheckTimer = null;
                }
                if (state.refreshTimer) {
                    clearInterval(state.refreshTimer);
                    state.refreshTimer = null;
                }
            }
        };

        // Helper functions
        function getProxyUrl(plexUrl) {
            return plexUrl ? `${urls.proxy}?path=${btoa(plexUrl)}` : '';
        }

        function getPosterUrl(item) {
            if (!item) return '';
            return (item.type === 'episode' && item.show_thumb) ?
                getProxyUrl(item.show_thumb) : getProxyUrl(item.thumb);
        }

        function getItemKey(item) {
            return item.ratingKey || `${item.title}_${item.year}`;
        }

        function shuffleArray(array) {
            for (let i = array.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [array[i], array[j]] = [array[j], array[i]];
            }
            return array;
        }

        // FIXED: Improved poster rotation with better variety
        function nextPoster() {
            if (state.isTransitioning) return;

            // Check if we need fresh content
            if (!state.isStreaming && shouldLoadFreshBatch()) {
                loadFreshBatch();
                return;
            }

            // For single streams, don't rotate
            if (state.isStreaming && !state.hasMultipleStreams) {
                return;
            }

            // Get next index with variety logic
            const nextIndex = getNextIndex();
            const fromItem = state.items[state.currentIndex];
            const toItem = state.items[nextIndex];

            // Preload next image
            preloadImage(getPosterUrl(toItem));

            // Transition
            transitionManager.start(fromItem, toItem, (success) => {
                if (success) {
                    state.currentIndex = nextIndex;
                    state.displayCount++;

                    // Track what we've seen
                    state.seenItems.add(getItemKey(toItem));

                    debug(`Displayed: ${toItem.title} (${state.displayCount} total)`);
                }
            });
        }

        // FIXED: Smart index selection to avoid repetition
        function getNextIndex() {
            const totalItems = state.items.length;

            if (totalItems <= 1) return 0;

            // For small collections, use simple rotation
            if (totalItems <= 3) {
                return (state.currentIndex + 1) % totalItems;
            }

            // For larger collections, avoid recently seen items
            const recentlySeenCount = Math.min(totalItems - 1, 5);
            const candidates = [];

            for (let i = 0; i < totalItems; i++) {
                if (i === state.currentIndex) continue;

                const item = state.items[i];
                const key = getItemKey(item);

                // Check if this item was recently shown
                const recentlyShown = state.seenItems.has(key) && state.seenItems.size < recentlySeenCount;

                if (!recentlyShown) {
                    candidates.push(i);
                }
            }

            // If no candidates (all recently shown), allow any except current
            if (candidates.length === 0) {
                for (let i = 0; i < totalItems; i++) {
                    if (i !== state.currentIndex) {
                        candidates.push(i);
                    }
                }
                // Clear seen items to start fresh
                state.seenItems.clear();
            }

            // Return random candidate
            return candidates[Math.floor(Math.random() * candidates.length)];
        }

        // FIXED: Better fresh batch loading
        function shouldLoadFreshBatch() {
            return (
                state.displayCount >= config.refreshThreshold ||
                Date.now() - state.lastRefreshTime > 600000 || // 10 minutes
                state.currentIndex >= state.items.length - 2
            );
        }

        function loadFreshBatch(force = false) {
            if (state.isTransitioning && !force) return;

            debug('Loading fresh batch...');

            // Create cache-busting URL
            const timestamp = Date.now();
            const randomSeed = Math.floor(Math.random() * 10000);
            const url = `${urls.checkStreams}?batch=${timestamp}&seed=${randomSeed}&count=${config.batchSize}&session=${state.sessionId}`;

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.random_items && data.random_items.length > 0) {
                        const newItems = data.random_items.map(item => ({
                            ...item,
                            _id: state.uniqueId++
                        }));

                        // Transition to new batch
                        const currentItem = state.items[state.currentIndex];
                        transitionManager.start(currentItem, newItems[0], (success) => {
                            if (success) {
                                state.items = newItems;
                                state.currentIndex = 0;
                                state.displayCount = 0;
                                state.lastRefreshTime = Date.now();
                                state.seenItems.clear();

                                // Preload first few images
                                for (let i = 0; i < Math.min(3, newItems.length); i++) {
                                    preloadImage(getPosterUrl(newItems[i]));
                                }

                                debug(`Loaded ${newItems.length} new items`);
                            }
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading fresh batch:', error);
                });
        }

        // Preload images
        function preloadImage(url) {
            if (url && !state.preloadedImages.has(url)) {
                const img = new Image();
                img.src = url;
                state.preloadedImages.add(url);
            }
        }

        // FIXED: Simplified stream checking
        function checkForStreams() {
            if (state.isTransitioning) return;

            fetch(`${urls.checkStreams}?check=${Date.now()}`)
                .then(response => response.json())
                .then(data => {
                    const activeStreams = data.active_streams || [];
                    const shouldBeStreaming = activeStreams.length > 0;
                    const shouldHaveMultiple = activeStreams.length > 1;

                    if (!state.isStreaming && shouldBeStreaming) {
                        // Switch to streaming
                        debug('Switching to streaming mode');
                        const currentItem = state.items[state.currentIndex];

                        transitionManager.start(currentItem, activeStreams[0], (success) => {
                            if (success) {
                                state.isStreaming = true;
                                state.hasMultipleStreams = shouldHaveMultiple;
                                state.items = activeStreams.map(item => ({ ...item, _id: state.uniqueId++ }));
                                state.currentIndex = 0;

                                if (shouldHaveMultiple) {
                                    timerManager.startDisplayTimer();
                                } else {
                                    timerManager.stopDisplayTimer();
                                }
                            }
                        });
                    } else if (state.isStreaming && !shouldBeStreaming) {
                        // Switch back to random
                        debug('Switching back to random posters');
                        state.isStreaming = false;
                        state.hasMultipleStreams = false;
                        loadFreshBatch(true);
                        timerManager.startDisplayTimer();
                    } else if (state.isStreaming && shouldBeStreaming) {
                        // Update existing streams
                        const updatedStreams = activeStreams.map(item => ({ ...item, _id: state.uniqueId++ }));
                        const currentKey = getItemKey(state.items[state.currentIndex]);
                        const newIndex = updatedStreams.findIndex(item => getItemKey(item) === currentKey);

                        state.items = updatedStreams;
                        state.currentIndex = newIndex >= 0 ? newIndex : 0;
                        state.hasMultipleStreams = shouldHaveMultiple;

                        if (shouldHaveMultiple) {
                            timerManager.startDisplayTimer();
                        } else {
                            timerManager.stopDisplayTimer();
                        }

                        updateDisplay();
                    }
                })
                .catch(error => {
                    console.error('Error checking streams:', error);
                });
        }

        // Update display
        function updateDisplay() {
            if (state.isTransitioning || !state.items.length) return;

            const currentItem = state.items[state.currentIndex];
            if (!currentItem) return;

            posterContainer.innerHTML = '';

            const posterWrapper = document.createElement('div');
            posterWrapper.className = 'poster-wrapper';
            posterContainer.appendChild(posterWrapper);

            // Update background
            const artUrl = currentItem.art || currentItem.thumb ? getProxyUrl(currentItem.art || currentItem.thumb) : '';
            if (artUrl) {
                background.style.backgroundImage = `url(${artUrl})`;
            }

            // Create poster
            const posterUrl = getPosterUrl(currentItem);
            if (posterUrl) {
                const poster = document.createElement('div');
                poster.style.cssText = `
                    width: 100%;
                    height: 100%;
                    background-image: url(${posterUrl});
                    background-size: cover;
                    background-position: center;
                    position: absolute;
                `;
                posterWrapper.appendChild(poster);
            }

            // Add streaming elements if needed
            if (state.isStreaming) {
                const badge = document.createElement('div');
                badge.className = 'streaming-badge';
                badge.textContent = 'Currently Streaming';
                posterWrapper.appendChild(badge);

                addStreamInfo(currentItem, posterWrapper);
            }

            // Preload next few images
            for (let i = 1; i <= 3; i++) {
                const nextIndex = (state.currentIndex + i) % state.items.length;
                if (state.items[nextIndex]) {
                    preloadImage(getPosterUrl(state.items[nextIndex]));
                }
            }
        }

        // Add stream info
        function addStreamInfo(stream, container) {
            const infoDiv = document.createElement('div');
            infoDiv.className = 'stream-info';

            let title = stream.title || 'Unknown';
            let details = '';

            if (stream.type === 'episode') {
                title = stream.show_title || 'Unknown Show';
                details = `S${stream.season || '?'}E${stream.episode || '?'} - ${stream.title || 'Unknown'} • ${stream.user || 'Unknown User'}`;
            } else {
                details = `${stream.year || ''} • ${stream.user || 'Unknown User'}`;
            }

            const progress = stream.duration ? Math.floor((stream.viewOffset / stream.duration) * 100) : 0;

            infoDiv.innerHTML = `
                <div class="stream-title">${title}</div>
                <div class="stream-details">${details}</div>
                <div class="stream-progress">
                    <div class="stream-progress-bar" style="width: ${progress}%;"></div>
                </div>
            `;

            container.appendChild(infoDiv);
        }

        // Adjust poster size
        function adjustPosterSize() {
            if (state.isTransitioning) return;

            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;
            const posterRatio = 2 / 3;

            let posterWidth, posterHeight;

            if (viewportWidth <= 480) {
                posterWidth = viewportWidth * 0.85;
                posterHeight = posterWidth / posterRatio;
                if (posterHeight > viewportHeight * 0.85) {
                    posterHeight = viewportHeight * 0.85;
                    posterWidth = posterHeight * posterRatio;
                }
            } else {
                const maxHeight = viewportHeight * 0.9;
                const maxWidth = viewportWidth * 0.9;

                if (maxWidth / maxHeight < posterRatio) {
                    posterWidth = maxWidth;
                    posterHeight = posterWidth / posterRatio;
                } else {
                    posterHeight = maxHeight;
                    posterWidth = posterHeight * posterRatio;
                }
            }

            posterContainer.style.width = `${Math.floor(posterWidth)}px`;
            posterContainer.style.height = `${Math.floor(posterHeight)}px`;

            if (!state.isTransitioning) {
                updateDisplay();
            }
        }

        // Initialize
        function initialize() {
            debug('Initializing improved Poster Wall');

            timerManager.cleanup();

            if (!state.items || state.items.length === 0) {
                posterContainer.innerHTML = '<div class="error-container"><h2>No content available</h2></div>';
                return;
            }

            // Add unique IDs and preload initial images
            state.items = state.items.map(item => ({ ...item, _id: state.uniqueId++ }));

            for (let i = 0; i < Math.min(3, state.items.length); i++) {
                preloadImage(getPosterUrl(state.items[i]));
            }

            updateDisplay();

            // Start timers
            if (!state.isStreaming || state.hasMultipleStreams) {
                timerManager.startDisplayTimer();
            }

            timerManager.startStreamChecking();
            timerManager.startRefreshTimer();

            // Setup resize handling
            window.addEventListener('resize', adjustPosterSize);
            adjustPosterSize();

            // Setup periodic refresh to prevent stagnation
            setInterval(() => {
                if (!state.isStreaming && state.displayCount > 0 && state.displayCount % 30 === 0) {
                    debug('Periodic refresh triggered');
                    loadFreshBatch(true);
                }
            }, 60000); // Check every minute

            debug('Initialization complete');
        }

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            timerManager.cleanup();
        });

        // Initialize when page loads
        window.onload = initialize;
    </script>
</body>

</html>