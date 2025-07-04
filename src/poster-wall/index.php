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
        CURLOPT_HTTPHEADER => ['Accept: application/json'] // Request JSON response
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

// Function to get random library items
function getRandomLibraryItems($server_url, $token, $count = 10)
{
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

    // Get items from libraries
    $items = [];
    foreach ($library_keys as $key) {
        $items_url = "{$server_url}/library/sections/{$key}/all?X-Plex-Token={$token}";

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
                    'year' => (string) $video['year']
                ];

                $items[] = $item;
            }
        } elseif ($xml && isset($xml->Directory)) {
            foreach ($xml->Directory as $directory) {
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
    }

    // Shuffle and limit the items
    shuffle($items);
    return array_slice($items, 0, $count);
}

// Get active streams
$active_streams = getActiveStreams($plex_url, $plex_token);

// Get random items
$random_items = getRandomLibraryItems($plex_url, $plex_token, 10);

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
        // Configuration
        const config = {
            tileRows: 12,
            tileCols: 8,
            displayDuration: 10000, // 10 seconds between transitions
            transitionDuration: 3000, // 3 seconds for transition animation
            streamCheckInterval: 10000, // Check for streams every 10 seconds
            streamCheckDebounce: 1000, // Debounce stream checks by 1 second
            batchSize: 10, // Number of posters to load at once
            preloadThreshold: 8, // Load new posters when reaching this index
            debug: true // Enable debug logs
        };

        // 1. Use a more explicit state machine approach
        const TransitionState = {
            IDLE: 'idle',               // Normal display, no transition
            PREPARING: 'preparing',     // Getting ready for transition
            ANIMATING: 'animating',     // Visual transition is occurring
            FINALIZING: 'finalizing'    // Transition visuals complete, updating state
        };

        // 2. Simplified state object with clearer separation of concerns
        let state = {
            // Content data
            items: <?php echo !empty($items_json) ? $items_json : '[]'; ?>,
            currentIndex: 0,
            isStreaming: <?php echo !empty($active_streams) ? 'true' : 'false'; ?>,
            hasMultipleStreams: <?php echo (count($active_streams) > 1) ? 'true' : 'false'; ?>,

            // Transition state
            transitionState: TransitionState.IDLE,
            transitionStartTime: 0,
            transitionTarget: null,     // Target state after transition

            // Resource management
            preloadedImages: {},
            nextBatch: null,

            // Timers (references only)
            displayTimer: null,
            streamCheckTimer: null,
            transitionTimeout: null,

            // Utilities
            uniqueId: 1,
            lockedDimensions: null, // Store locked dimensions during transitions
            lastStreamCheck: 0, // Timestamp of last stream check
            streamCheckScheduled: false, // Flag for scheduled stream checks
            previousStreams: [] // Keep track of previous stream sets
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

        // 3. Centralized transition manager
        const transitionManager = {
            // Start a transition with proper state handling
            start(fromItem, toItem, onComplete) {
                // Don't start a new transition if one is in progress
                if (state.transitionState !== TransitionState.IDLE) {
                    debug("Transition already in progress, ignoring new request");
                    return false;
                }

                // Get poster URLs for transition
                const fromPosterUrl = getPosterUrl(fromItem);
                const toPosterUrl = getPosterUrl(toItem);

                if (!fromPosterUrl || !toPosterUrl) {
                    debug("Missing poster URLs for transition");
                    if (onComplete) onComplete(false);
                    return false;
                }

                // Update transition state
                state.transitionState = TransitionState.PREPARING;
                state.transitionStartTime = Date.now();
                state.transitionTarget = {
                    fromItem,
                    toItem,
                    onComplete
                };

                // Lock dimensions during transition
                this.lockDimensions();

                // Start background transition
                this.transitionBackground(toItem);

                // Create tile grid
                setTimeout(() => {
                    this.createTiles(fromPosterUrl, toPosterUrl);

                    // Move to animation state
                    state.transitionState = TransitionState.ANIMATING;

                    // Set safety timeout
                    this.setTimeoutWithCleanup(this.completeTransition.bind(this), config.transitionDuration + 500);
                }, 50);

                return true;
            },

            // Create visual tiles for the transition
            createTiles(fromPosterUrl, toPosterUrl) {
                // Clear existing content
                posterContainer.innerHTML = '';

                const width = posterContainer.offsetWidth;
                const height = posterContainer.offsetHeight;

                // Use precise calculations with Math.floor to avoid rounding issues
                const tileWidth = Math.floor(width / config.tileCols);
                const tileHeight = Math.floor(height / config.tileRows);

                // Create and animate tiles
                const tiles = [];

                for (let row = 0; row < config.tileRows; row++) {
                    for (let col = 0; col < config.tileCols; col++) {
                        // Handle last row/column dimensions
                        const isLastCol = col === config.tileCols - 1;
                        const isLastRow = row === config.tileRows - 1;

                        const tileWidthActual = isLastCol ? width - (col * tileWidth) : tileWidth;
                        const tileHeightActual = isLastRow ? height - (row * tileHeight) : tileHeight;

                        // Create tile with consistent styling
                        const tile = document.createElement('div');
                        tile.className = 'tile';
                        tile.style.width = `${tileWidthActual}px`;
                        tile.style.height = `${tileHeightActual}px`;
                        tile.style.top = `${row * tileHeight}px`;
                        tile.style.left = `${col * tileWidth}px`;

                        // Front face (current poster)
                        tile.style.backgroundImage = `url(${fromPosterUrl})`;
                        tile.style.backgroundPosition = `-${col * tileWidth}px -${row * tileHeight}px`;
                        tile.style.backgroundSize = `${width}px ${height}px`;

                        // Back face (target poster)
                        const tileBack = document.createElement('div');
                        tileBack.className = 'tile-back';
                        tileBack.style.backgroundImage = `url(${toPosterUrl})`;
                        tileBack.style.backgroundPosition = `-${col * tileWidth}px -${row * tileHeight}px`;
                        tileBack.style.backgroundSize = `${width}px ${height}px`;

                        tile.appendChild(tileBack);
                        posterContainer.appendChild(tile);
                        tiles.push(tile);
                    }
                }

                // Add stream info if needed
                if (state.transitionTarget &&
                    (state.isStreaming || state.transitionTarget.fromItem.user)) {
                    this.addStreamingElements(state.transitionTarget.fromItem);
                }

                // Shuffle and animate tiles
                this.animateTiles(tiles);
            },

            // Add streaming badge and info during transition
            addStreamingElements(item) {
                // Add streaming badge
                const badge = document.createElement('div');
                badge.className = 'streaming-badge';
                badge.textContent = 'Currently Streaming';
                badge.style.position = 'absolute';
                badge.style.zIndex = '10';
                posterContainer.appendChild(badge);

                // Add stream info container
                const infoContainer = document.createElement('div');
                infoContainer.style.position = 'absolute';
                infoContainer.style.bottom = '0';
                infoContainer.style.left = '0';
                infoContainer.style.width = '100%';
                infoContainer.style.zIndex = '10';
                posterContainer.appendChild(infoContainer);

                // Add stream info
                addStreamInfo(item, infoContainer);
            },

            // Animate tiles with proper cleanup
            animateTiles(tiles) {
                // Randomize animation order
                shuffleArray(tiles);

                const totalTiles = tiles.length;
                const maxDelay = config.transitionDuration * 0.95;
                let animatedTiles = 0;

                // Animate each tile with an eased delay
                tiles.forEach((tile, index) => {
                    // Create slight ease-out effect
                    const progress = index / totalTiles;
                    const easedProgress = progress < 0.8 ?
                        progress : 0.8 + (progress - 0.8) * 0.7;

                    const delay = easedProgress * maxDelay;

                    // Start animation after delay
                    setTimeout(() => {
                        tile.style.transform = 'rotateY(180deg)';
                        animatedTiles++;

                        // Check if all tiles have animated
                        if (animatedTiles === totalTiles) {
                            // Move to finalizing state
                            state.transitionState = TransitionState.FINALIZING;

                            // Allow a small delay for all visual effects to complete
                            setTimeout(() => {
                                this.completeTransition();
                            }, 200);
                        }
                    }, delay);
                });
            },

            // Transition background image
            transitionBackground(toItem) {
                const artUrl = toItem.art || toItem.thumb ?
                    getProxyUrl(toItem.art || toItem.thumb) : '';

                if (artUrl) {
                    // Set overlay to the new background
                    backgroundOverlay.style.backgroundImage = `url(${artUrl})`;

                    // Fade in overlay
                    setTimeout(() => {
                        backgroundOverlay.style.opacity = '0.4';
                    }, 100);

                    // Set main background after animation completes
                    setTimeout(() => {
                        background.style.backgroundImage = `url(${artUrl})`;
                        backgroundOverlay.style.opacity = '0';
                    }, config.transitionDuration + 500);
                }
            },

            // Complete transition and clean up
            completeTransition() {
                // Only complete once
                if (state.transitionState === TransitionState.IDLE) {
                    return;
                }

                // Get target info
                const target = state.transitionTarget;

                // Clean up all timers
                this.clearAllTransitionTimers();

                // Unlock dimensions
                this.unlockDimensions();

                // Reset state to idle
                state.transitionState = TransitionState.IDLE;
                state.transitionStartTime = 0;

                // Run completion callback if available
                if (target && target.onComplete) {
                    try {
                        target.onComplete(true);
                    } catch (e) {
                        console.error('Error in transition callback:', e);
                    }
                }

                // Reset transition target
                state.transitionTarget = null;

                // Update display
                updateDisplay();
            },

            // Lock dimensions during transition
            lockDimensions() {
                const width = posterContainer.offsetWidth;
                const height = posterContainer.offsetHeight;

                state.lockedDimensions = {
                    width: posterContainer.style.width,
                    height: posterContainer.style.height
                };

                posterContainer.style.width = `${width}px`;
                posterContainer.style.height = `${height}px`;
            },

            // Unlock dimensions after transition
            unlockDimensions() {
                if (state.lockedDimensions) {
                    posterContainer.style.width = state.lockedDimensions.width;
                    posterContainer.style.height = state.lockedDimensions.height;
                    state.lockedDimensions = null;
                }
            },

            // Set a timeout that won't be left dangling
            setTimeoutWithCleanup(callback, delay) {
                // Clear existing timeout
                if (state.transitionTimeout) {
                    clearTimeout(state.transitionTimeout);
                }

                // Set new timeout
                state.transitionTimeout = setTimeout(() => {
                    state.transitionTimeout = null;
                    callback();
                }, delay);

                return state.transitionTimeout;
            },

            // Clean up all transition timers
            clearAllTransitionTimers() {
                if (state.transitionTimeout) {
                    clearTimeout(state.transitionTimeout);
                    state.transitionTimeout = null;
                }
            },

            // Safety check - force complete if stuck
            checkForStuckTransition() {
                if (state.transitionState !== TransitionState.IDLE) {
                    const currentTime = Date.now();
                    const duration = currentTime - state.transitionStartTime;

                    // If transition has been going for too long, force completion
                    if (duration > config.transitionDuration * 2) {
                        debug("Transition appears stuck, forcing completion");
                        this.completeTransition();
                        return true;
                    }
                }

                return false;
            }
        };

        // 1. Revise the timer manager to ensure stream checks happen reliably
        const timerManager = {
            // Start display timer with proper cleanup
            startDisplayTimer() {
                this.stopDisplayTimer();

                state.displayTimer = setInterval(() => {
                    // Only proceed if no transition is happening
                    if (state.transitionState === TransitionState.IDLE) {
                        nextPoster();
                    }
                }, config.displayDuration);

                debug('Display timer started');
            },

            // Stop display timer
            stopDisplayTimer() {
                if (state.displayTimer) {
                    clearInterval(state.displayTimer);
                    state.displayTimer = null;
                    debug('Display timer stopped');
                }
            },

            // Start stream checking with proper cleanup - FIXED
            startStreamChecking() {
                // Clear existing timer first
                if (state.streamCheckTimer) {
                    clearInterval(state.streamCheckTimer);
                    state.streamCheckTimer = null;
                }

                // Run an initial check immediately
                checkForStreams();

                // Set up regular interval for checking
                state.streamCheckTimer = setInterval(checkForStreams, config.streamCheckInterval);

                debug('Stream checking started');
            },

            // Clean up all timers
            cleanupAllTimers() {
                this.stopDisplayTimer();

                if (state.streamCheckTimer) {
                    clearInterval(state.streamCheckTimer);
                    state.streamCheckTimer = null;
                }

                if (state.transitionTimeout) {
                    clearTimeout(state.transitionTimeout);
                    state.transitionTimeout = null;
                }
            }
        };

        // Helper function to convert Plex URLs to proxy URLs
        function getProxyUrl(plexUrl) {
            if (!plexUrl) return '';
            return `${urls.proxy}?path=${btoa(plexUrl)}`;
        }

        // Helper to get the poster URL for an item
        function getPosterUrl(item) {
            if (!item) return '';

            return (item.type === 'episode' && item.show_thumb) ?
                getProxyUrl(item.show_thumb) :
                (item.thumb ? getProxyUrl(item.thumb) : '');
        }

        // Schedule a stream check with debouncing
        function scheduleStreamCheck(delay = config.streamCheckDebounce) {
            // Don't schedule if we already have a scheduled check
            if (state.streamCheckScheduled) {
                return;
            }

            state.streamCheckScheduled = true;

            setTimeout(() => {
                checkForStreams();
                state.streamCheckScheduled = false;
            }, delay);
        }

        // Generate a unique key for a stream
        function getStreamKey(stream) {
            return `${stream.title}_${stream.user}`;
        }

        // 2. Simplified stream check function - FIXED to be more direct
        function checkForStreams() {
            debug('Checking for active streams...');

            // Skip check if in the middle of a transition
            if (state.transitionState !== TransitionState.IDLE) {
                debug('Skipping stream check - in transition');
                return;
            }

            // Make direct API call to check streams endpoint
            fetch(urls.checkStreams)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    debug(`Stream check response: Active streams: ${(data.active_streams || []).length}, Random items: ${(data.random_items || []).length}`);

                    // Process stream data 
                    if (data && (data.active_streams || data.random_items)) {
                        handleStreamCheck(data);
                    }
                })
                .catch(error => {
                    console.error('Error checking for streams:', error);
                });
        }

        function handleStreamCheck(data) {
            // Add unique IDs to all items
            const activeStreams = (data.active_streams || []).map(item => ({
                ...item,
                _id: state.uniqueId++
            }));

            const randomItems = (data.random_items || []).map(item => ({
                ...item,
                _id: state.uniqueId++
            }));

            // Determine if we should be streaming
            const shouldBeStreaming = activeStreams.length > 0;
            const shouldHaveMultipleStreams = activeStreams.length > 1;

            // Current state and item
            const isStreaming = state.isStreaming;
            const currentItem = state.items[state.currentIndex];

            debug(`Stream check result: Should be streaming: ${shouldBeStreaming}, Current streaming: ${isStreaming}`);

            // Handle state transitions
            if (!isStreaming && shouldBeStreaming) {
                // Switch from random items to streaming
                debug('Switching to streaming mode');

                // Preload images
                if (activeStreams[0]) {
                    preloadStreamImages(activeStreams[0]);
                }

                // Transition to streaming
                transitionManager.start(currentItem, activeStreams[0], success => {
                    if (success) {
                        // Update state after transition
                        state.isStreaming = true;
                        state.hasMultipleStreams = shouldHaveMultipleStreams;
                        state.items = activeStreams;
                        state.currentIndex = 0;

                        // Control display timer based on number of streams
                        if (shouldHaveMultipleStreams) {
                            timerManager.startDisplayTimer();
                        } else {
                            timerManager.stopDisplayTimer();
                        }

                        debug('Now in streaming mode');
                    }
                });
            }
            else if (isStreaming && !shouldBeStreaming) {
                // Switch from streaming to random items
                debug('Switching back to random posters');

                if (randomItems.length === 0) {
                    debug('No random items available');
                    return;
                }

                // Preload images
                preloadStreamImages(randomItems[0]);

                // Transition to random items
                transitionManager.start(currentItem, randomItems[0], success => {
                    if (success) {
                        // Update state after transition
                        state.isStreaming = false;
                        state.hasMultipleStreams = false;
                        state.items = randomItems;
                        state.currentIndex = 0;

                        // Always start display timer for random posters
                        timerManager.startDisplayTimer();

                        debug('Now showing random posters');
                    }
                });
            }
            else if (isStreaming && shouldBeStreaming) {
                // Update active streams or handle streams that were added/removed
                updateActiveStreams(currentItem, activeStreams, shouldHaveMultipleStreams);
            }
            else if (!isStreaming && randomItems.length > 0) {
                // Store random items for next batch
                state.nextBatch = randomItems;
            }
        }

        function updateActiveStreams(currentItem, newStreams, hasMultipleStreams) {
            // If we have no current item, use the first new stream
            if (!currentItem && newStreams.length > 0) {
                state.items = newStreams;
                state.currentIndex = 0;
                state.hasMultipleStreams = hasMultipleStreams;
                updateDisplay();
                return;
            }

            // Check if current stream still exists
            const currentKey = currentItem ? getStreamKey(currentItem) : null;
            const stillExists = currentKey ?
                newStreams.some(s => getStreamKey(s) === currentKey) : false;

            if (stillExists) {
                // Current stream still exists, just update the streams array
                const newIndex = newStreams.findIndex(s => getStreamKey(s) === currentKey);

                state.items = newStreams;
                state.currentIndex = newIndex !== -1 ? newIndex : 0;
                state.hasMultipleStreams = hasMultipleStreams;

                // Update timer based on stream count
                if (hasMultipleStreams) {
                    timerManager.startDisplayTimer();
                } else {
                    timerManager.stopDisplayTimer();
                }

                // Update display to refresh progress
                updateStreamInfo(state.items[state.currentIndex]);
                debug('Updated existing stream info');
            }
            else {
                // Current stream was removed, transition to a new one
                debug('Current stream was removed, transitioning to new stream');

                transitionManager.start(currentItem, newStreams[0], success => {
                    if (success) {
                        state.items = newStreams;
                        state.currentIndex = 0;
                        state.hasMultipleStreams = hasMultipleStreams;

                        // Update timer based on stream count
                        if (hasMultipleStreams) {
                            timerManager.startDisplayTimer();
                        } else {
                            timerManager.stopDisplayTimer();
                        }

                        debug('Transitioned to new stream');
                    }
                });
            }
        }

        // Process stream data with simplified state management
        function processStreamData(data) {
            // Add unique IDs to all items
            const activeStreams = (data.active_streams || []).map(item => ({
                ...item,
                _id: state.uniqueId++
            }));

            const randomItems = (data.random_items || []).map(item => ({
                ...item,
                _id: state.uniqueId++
            }));

            // Capture current state
            const wasStreaming = state.isStreaming;
            const hadMultipleStreams = state.hasMultipleStreams;
            const currentItem = state.items[state.currentIndex];

            // Calculate new state
            const isStreaming = activeStreams.length > 0;
            const hasMultipleStreams = activeStreams.length > 1;

            debug(`Stream check: was=${wasStreaming}(${hadMultipleStreams ? 'multiple' : 'single'}), now=${isStreaming}(${hasMultipleStreams ? 'multiple' : 'single'})`);

            // VERY IMPORTANT: Remember the current item being displayed
            debug(`Current displayed item: ${currentItem ? currentItem.title : 'none'}`);

            // Save current streams for tracking
            if (state.isStreaming) {
                state.previousStreams = [...state.items];
            }

            // Determine transition needed
            if (!wasStreaming && isStreaming) {
                // Transition from random to streaming
                debug(`Switching to streaming mode. Streams: ${activeStreams.length}`);

                // Preload the stream images
                preloadStreamImages(activeStreams[0]);

                // Start transition to first stream
                transitionManager.start(currentItem, activeStreams[0], success => {
                    if (success) {
                        // Update state after transition
                        state.isStreaming = true;
                        state.hasMultipleStreams = hasMultipleStreams;
                        state.items = activeStreams;
                        state.currentIndex = 0;

                        // Manage timer based on stream count
                        if (hasMultipleStreams) {
                            timerManager.startDisplayTimer();
                        } else {
                            timerManager.stopDisplayTimer();
                        }

                        debug('Transition to streaming mode complete');
                    }
                });
            }
            else if (wasStreaming && !isStreaming) {
                // Transition from streaming to random
                debug('Switching back to random posters');

                // Ensure we have random items
                if (randomItems.length === 0) {
                    debug('No random items available');
                    return;
                }

                // Preload random poster
                preloadStreamImages(randomItems[0]);

                // Start transition
                transitionManager.start(currentItem, randomItems[0], success => {
                    if (success) {
                        // Update state after transition
                        state.isStreaming = false;
                        state.hasMultipleStreams = false;
                        state.items = randomItems;
                        state.currentIndex = 0;

                        // Always start timer for random posters
                        timerManager.startDisplayTimer();

                        debug('Transition to random posters complete');
                    }
                });
            }
            else if (wasStreaming && isStreaming) {
                // Handle stream changes
                // Use keys to detect changes
                const currentKeys = new Set(state.items.map(getStreamKey));
                const newKeys = new Set(activeStreams.map(getStreamKey));

                // Find streams that have been added or removed
                const removedStreams = state.items.filter(s => !newKeys.has(getStreamKey(s)));
                const addedStreams = activeStreams.filter(s => !currentKeys.has(getStreamKey(s)));

                const streamsChanged = removedStreams.length > 0 || addedStreams.length > 0;
                debug(`Stream analysis: removed=${removedStreams.length}, added=${addedStreams.length}, changed=${streamsChanged}`);

                if (!streamsChanged) {
                    // Just update progress data
                    debug('Same streams, updating progress');
                    state.items = activeStreams;

                    // Find the current stream in the updated array
                    const currentKey = currentItem ? getStreamKey(currentItem) : null;
                    const newIndex = currentKey ? activeStreams.findIndex(s => getStreamKey(s) === currentKey) : -1;

                    if (newIndex !== -1) {
                        state.currentIndex = newIndex;
                    }

                    updateStreamInfo(state.items[state.currentIndex]);
                    return;
                }

                // Handle different transition scenarios based on multiple/single streams
                if (hadMultipleStreams && !hasMultipleStreams) {
                    debug('Switching from multiple streams to single stream');

                    if (activeStreams.length === 0) return;

                    const currentKey = currentItem ? getStreamKey(currentItem) : null;
                    const newStream = activeStreams[0];
                    const newKey = getStreamKey(newStream);

                    // Check if current stream is the remaining one
                    if (currentKey === newKey) {
                        // No transition needed
                        state.items = activeStreams;
                        state.hasMultipleStreams = false;
                        state.currentIndex = 0;
                        timerManager.stopDisplayTimer();
                        updateStreamInfo(newStream);
                        debug('Current stream is the remaining stream, no transition needed');
                    } else {
                        // Transition to the remaining stream
                        transitionManager.start(currentItem, newStream, success => {
                            if (success) {
                                state.items = activeStreams;
                                state.hasMultipleStreams = false;
                                state.currentIndex = 0;
                                timerManager.stopDisplayTimer();
                                debug('Transition to single stream complete');
                            }
                        });
                    }
                }
                else if (!hadMultipleStreams && hasMultipleStreams) {
                    debug('Switching from single stream to multiple streams');

                    if (activeStreams.length < 2) return;

                    // Find a different stream to transition to
                    const currentKey = currentItem ? getStreamKey(currentItem) : null;
                    const targetIndex = currentKey ?
                        activeStreams.findIndex(s => getStreamKey(s) !== currentKey) : 0;
                    const targetStream = activeStreams[targetIndex !== -1 ? targetIndex : 1];

                    // Transition to the new stream
                    transitionManager.start(currentItem, targetStream, success => {
                        if (success) {
                            state.items = activeStreams;
                            state.hasMultipleStreams = true;
                            state.currentIndex = targetIndex !== -1 ? targetIndex : 1;
                            timerManager.startDisplayTimer();
                            debug('Transition to multiple streams complete');
                        }
                    });
                }
                else if (hasMultipleStreams) {
                    debug('Updating multiple streams list');

                    const currentKey = currentItem ? getStreamKey(currentItem) : null;
                    const currentRemoved = currentKey ?
                        removedStreams.some(s => getStreamKey(s) === currentKey) : false;

                    if (currentRemoved) {
                        // Transition to first available stream
                        transitionManager.start(currentItem, activeStreams[0], success => {
                            if (success) {
                                state.items = activeStreams;
                                state.hasMultipleStreams = true;
                                state.currentIndex = 0;
                                timerManager.startDisplayTimer();
                                debug('Transition after stream removal complete');
                            }
                        });
                    } else {
                        // Find current stream in new list
                        const newIndex = currentKey ?
                            activeStreams.findIndex(s => getStreamKey(s) === currentKey) : 0;

                        state.items = activeStreams;
                        state.currentIndex = newIndex !== -1 ? newIndex : 0;
                        timerManager.startDisplayTimer();
                        updateDisplay();
                        debug('Updated multiple streams without transition');
                    }
                }
                else {
                    debug('Switching to different single stream');

                    if (activeStreams.length === 0) return;

                    // Transition to the new stream
                    transitionManager.start(currentItem, activeStreams[0], success => {
                        if (success) {
                            state.items = activeStreams;
                            state.hasMultipleStreams = false;
                            state.currentIndex = 0;
                            debug('Transition to new single stream complete');
                        }
                    });
                }
            }
            else if (!wasStreaming && randomItems.length > 0) {
                // Store random items for next batch
                state.nextBatch = randomItems;
                debug('Stored random items for next batch');
            }
        }

        // Handle transition from random to streaming
        function transitionToStreaming(currentItem, activeStreams, hasMultipleStreams) {
            debug(`Switching to streaming mode. Streams: ${activeStreams.length}`);

            // Preload the stream images
            preloadStreamImages(activeStreams[0]);

            // Start transition to first stream
            transitionManager.start(currentItem, activeStreams[0], success => {
                if (success) {
                    // Update state after transition
                    state.isStreaming = true;
                    state.hasMultipleStreams = hasMultipleStreams;
                    state.items = activeStreams;
                    state.currentIndex = 0;

                    // Manage timer based on stream count
                    if (hasMultipleStreams) {
                        timerManager.startDisplayTimer();
                    } else {
                        timerManager.stopDisplayTimer();
                    }
                }
            });
        }

        // Handle transition from streaming to random
        function transitionToRandom(currentItem, randomItems) {
            debug('Switching back to random posters');

            // Ensure we have random items
            if (randomItems.length === 0) {
                debug('No random items available');
                return;
            }

            // Preload random poster
            preloadStreamImages(randomItems[0]);

            // Start transition
            transitionManager.start(currentItem, randomItems[0], success => {
                if (success) {
                    // Update state after transition
                    state.isStreaming = false;
                    state.hasMultipleStreams = false;
                    state.items = randomItems;
                    state.currentIndex = 0;

                    // Always start timer for random posters
                    timerManager.startDisplayTimer();
                }
            });
        }

        // Handle updates to the stream list
        function updateStreams(currentItem, activeStreams, hasMultipleStreams) {
            // Use keys to detect changes
            const currentKey = getStreamKey(currentItem);
            const currentKeys = new Set(state.items.map(getStreamKey));
            const newKeys = new Set(activeStreams.map(getStreamKey));

            // Find streams that have been added or removed
            const removedStreams = state.items.filter(s => !newKeys.has(getStreamKey(s)));
            const addedStreams = activeStreams.filter(s => !currentKeys.has(getStreamKey(s)));

            const streamsChanged = removedStreams.length > 0 || addedStreams.length > 0;
            debug(`Stream analysis: removed=${removedStreams.length}, added=${addedStreams.length}, changed=${streamsChanged}`);

            if (!streamsChanged) {
                // Just update progress data
                state.items = activeStreams;

                // Find the current stream in the updated array
                const newIndex = activeStreams.findIndex(s => getStreamKey(s) === currentKey);
                if (newIndex !== -1) {
                    state.currentIndex = newIndex;
                }

                updateStreamInfo(state.items[state.currentIndex]);
                return;
            }

            // Handle different transition scenarios
            if (hadMultipleStreams && !hasMultipleStreams) {
                handleMultipleToSingleStream(currentItem, activeStreams);
            }
            else if (!hadMultipleStreams && hasMultipleStreams) {
                handleSingleToMultipleStreams(currentItem, activeStreams);
            }
            else if (hasMultipleStreams) {
                handleMultipleStreamsUpdate(currentItem, activeStreams, removedStreams);
            }
            else {
                handleSingleStreamUpdate(currentItem, activeStreams);
            }
        }

        // Handle transition from multiple streams to a single stream
        function handleMultipleToSingleStream(currentItem, activeStreams) {
            debug('Switching from multiple streams to single stream');

            if (activeStreams.length === 0) return;

            const currentKey = getStreamKey(currentItem);
            const newStream = activeStreams[0];
            const newKey = getStreamKey(newStream);

            // Check if current stream is the remaining one
            if (currentKey === newKey) {
                // No transition needed
                state.items = activeStreams;
                state.hasMultipleStreams = false;
                state.currentIndex = 0;
                timerManager.stopDisplayTimer();
                updateStreamInfo(newStream);
            } else {
                // Transition to the remaining stream
                transitionManager.start(currentItem, newStream, success => {
                    if (success) {
                        state.items = activeStreams;
                        state.hasMultipleStreams = false;
                        state.currentIndex = 0;
                        timerManager.stopDisplayTimer();
                    }
                });
            }
        }

        // Handle transition from single stream to multiple streams
        function handleSingleToMultipleStreams(currentItem, activeStreams) {
            debug('Switching from single stream to multiple streams');

            if (activeStreams.length < 2) return;

            // Find a different stream to transition to
            const currentKey = getStreamKey(currentItem);
            const targetIndex = activeStreams.findIndex(s => getStreamKey(s) !== currentKey);
            const targetStream = activeStreams[targetIndex !== -1 ? targetIndex : 1];

            // Transition to the new stream
            transitionManager.start(currentItem, targetStream, success => {
                if (success) {
                    state.items = activeStreams;
                    state.hasMultipleStreams = true;
                    state.currentIndex = targetIndex !== -1 ? targetIndex : 1;
                    timerManager.startDisplayTimer();
                }
            });
        }

        // Handle updates to multiple streams list
        function handleMultipleStreamsUpdate(currentItem, activeStreams, removedStreams) {
            debug('Updating multiple streams list');

            const currentKey = getStreamKey(currentItem);
            const currentRemoved = removedStreams.some(s => getStreamKey(s) === currentKey);

            if (currentRemoved) {
                // Transition to first available stream
                transitionManager.start(currentItem, activeStreams[0], success => {
                    if (success) {
                        state.items = activeStreams;
                        state.hasMultipleStreams = true;
                        state.currentIndex = 0;
                        timerManager.startDisplayTimer();
                    }
                });
            } else {
                // Find current stream in new list
                const newIndex = activeStreams.findIndex(s => getStreamKey(s) === currentKey);

                state.items = activeStreams;
                state.currentIndex = newIndex !== -1 ? newIndex : 0;
                timerManager.startDisplayTimer();
                updateDisplay();
            }
        }

        // Handle update to a single stream
        function handleSingleStreamUpdate(currentItem, activeStreams) {
            debug('Switching to different single stream');

            if (activeStreams.length === 0) return;

            // Transition to the new stream
            transitionManager.start(currentItem, activeStreams[0], success => {
                if (success) {
                    state.items = activeStreams;
                    state.hasMultipleStreams = false;
                    state.currentIndex = 0;
                }
            });
        }

        // Preload stream images
        function preloadStreamImages(stream) {
            if (!stream) return;

            // Get poster and art URLs
            const posterUrl = (stream.type === 'episode' && stream.show_thumb) ?
                getProxyUrl(stream.show_thumb) :
                (stream.thumb ? getProxyUrl(stream.thumb) : '');

            const artUrl = stream.art || stream.thumb ?
                getProxyUrl(stream.art || stream.thumb) : '';

            // Preload the images if they're not already cached
            if (posterUrl && !state.preloadedImages[posterUrl]) {
                const img = new Image();
                img.src = posterUrl;
                state.preloadedImages[posterUrl] = true;
                debug(`Preloading poster: ${posterUrl}`);
            }

            if (artUrl && !state.preloadedImages[artUrl]) {
                const img = new Image();
                img.src = artUrl;
                state.preloadedImages[artUrl] = true;
                debug(`Preloading art: ${artUrl}`);
            }
        }

        // 5. Simplified poster navigation
        function nextPoster() {
            // Skip if transitioning
            if (state.transitionState !== TransitionState.IDLE) {
                return;
            }

            // Skip transitions for single stream
            if (state.isStreaming && !state.hasMultipleStreams) {
                return;
            }

            // Check if we need to load more posters
            if (!state.isStreaming && state.currentIndex >= config.preloadThreshold && state.nextBatch) {
                loadNextBatch();
                return;
            }

            // Calculate next index
            const nextIndex = (state.currentIndex + 1) % state.items.length;

            // Get items for transition
            const fromItem = state.items[state.currentIndex];
            const toItem = state.items[nextIndex];

            // Preload next poster
            preloadStreamImages(toItem);

            // Start transition
            transitionManager.start(fromItem, toItem, (success) => {
                if (success) {
                    // Update current index after successful transition
                    state.currentIndex = nextIndex;
                }
            });
        }

        // Load the next batch of random posters
        function loadNextBatch() {
            if (!state.nextBatch || state.nextBatch.length === 0) {
                // If no pending batch, request new data
                fetch(`${urls.checkStreams}?batch=${Math.floor(Math.random() * 1000)}&count=30`)
                    .then(response => response.json())
                    .then(data => {
                        const randomItems = (data.random_items || []).map(item => ({
                            ...item,
                            _id: state.uniqueId++
                        }));

                        if (randomItems.length > 0) {
                            processNextBatch(randomItems);
                        }
                    })
                    .catch(error => {
                        console.error('Error loading posters:', error);
                    });
            } else {
                // Use the pending batch
                processNextBatch(state.nextBatch);
                state.nextBatch = null;
            }
        }

        // Process a new batch of posters
        function processNextBatch(newBatch) {
            const currentItem = state.items[state.currentIndex];

            // Transition to first poster in new batch
            transitionManager.start(currentItem, newBatch[0], success => {
                if (success) {
                    state.items = newBatch;
                    state.currentIndex = 0;
                }
            });
        }

        // Update display with current item
        function updateDisplay() {
            if (!state.items || state.items.length === 0 || state.transitionState !== TransitionState.IDLE) {
                debug('Skip updateDisplay - invalid state');
                return;
            }

            // Get current item
            const currentItem = state.items[state.currentIndex];
            if (!currentItem) {
                debug('Skip updateDisplay - no current item');
                return;
            }

            debug(`Updating display: ${currentItem.title || 'Unknown'} (ID: ${currentItem._id || 'none'})`);

            // Preload current item's images
            preloadStreamImages(currentItem);

            // Clear existing content
            posterContainer.innerHTML = '';

            // Create a fixed-size wrapper
            const posterWrapper = document.createElement('div');
            posterWrapper.className = 'poster-wrapper';
            posterWrapper.style.width = '100%';
            posterWrapper.style.height = '100%';
            posterWrapper.style.position = 'relative';
            posterWrapper.style.overflow = 'hidden';
            posterContainer.appendChild(posterWrapper);

            // Update background
            const artUrl = currentItem.art || currentItem.thumb ?
                getProxyUrl(currentItem.art || currentItem.thumb) : '';
            background.style.backgroundImage = artUrl ? `url(${artUrl})` : '';

            // Create poster
            const posterUrl = getPosterUrl(currentItem);

            if (posterUrl) {
                const poster = document.createElement('div');
                poster.style.width = '100%';
                poster.style.height = '100%';
                poster.style.backgroundImage = `url(${posterUrl})`;
                poster.style.backgroundSize = 'cover';
                poster.style.backgroundPosition = 'center';
                poster.style.position = 'absolute';
                poster.style.top = '0';
                poster.style.left = '0';
                poster.style.overflow = 'hidden';
                posterWrapper.appendChild(poster);
            }

            // Add streaming elements if in streaming mode
            if (state.isStreaming) {
                // Add streaming badge
                const badge = document.createElement('div');
                badge.className = 'streaming-badge';
                badge.textContent = 'Currently Streaming';
                posterWrapper.appendChild(badge);

                // Add stream info
                addStreamInfo(currentItem, posterWrapper);
            }
        }

        // Update stream info only (for progress updates)
        function updateStreamInfo(stream) {
            if (!stream) return;

            // Find existing wrapper
            const posterWrapper = posterContainer.querySelector('.poster-wrapper');
            if (!posterWrapper) return;

            // Find existing stream info element
            const existingInfo = posterWrapper.querySelector('.stream-info');
            if (existingInfo) {
                existingInfo.remove();
            }

            // Add updated stream info
            addStreamInfo(stream, posterWrapper);
        }

        // Add streaming information
        function addStreamInfo(stream, container) {
            if (!stream || !container) return;

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

            // Create progress bar
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

        // Show error message
        function showError(message) {
            posterContainer.innerHTML = `
                <div class="error-container">
                    <h2>Error</h2>
                    <p>${message}</p>
                    <button onclick="location.reload()" style="margin-top: 20px; padding: 10px 20px; background: #cc7b19; border: none; color: white; border-radius: 5px; cursor: pointer;">
                        Retry
                    </button>
                </div>
            `;
        }

        // Adjust poster size
        function adjustPosterSize() {
            // Skip adjustment during transitions
            if (state.transitionState !== TransitionState.IDLE) {
                return;
            }

            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;

            // Standard movie poster ratio is 2:3 (width:height)
            const posterRatio = 2 / 3; // width/height

            let posterWidth, posterHeight;

            // For very small screens (mobile)
            if (viewportWidth <= 480) {
                posterWidth = viewportWidth * 0.85;
                posterHeight = posterWidth / posterRatio;

                // If poster height is too tall, scale it down
                if (posterHeight > viewportHeight * 0.85) {
                    posterHeight = viewportHeight * 0.85;
                    posterWidth = posterHeight * posterRatio;
                }
            } else {
                // For larger screens
                const maxHeight = viewportHeight * 0.9;
                const maxWidth = viewportWidth * 0.9;

                // Calculate dimensions based on screen constraints
                if (maxWidth / maxHeight < posterRatio) {
                    // Width constrained
                    posterWidth = maxWidth;
                    posterHeight = posterWidth / posterRatio;
                } else {
                    // Height constrained
                    posterHeight = maxHeight;
                    posterWidth = posterHeight * posterRatio;
                }
            }

            // Apply the calculated dimensions with fixed pixel values
            posterContainer.style.width = `${Math.floor(posterWidth)}px`;
            posterContainer.style.height = `${Math.floor(posterHeight)}px`;

            // Update display if not in transition
            if (state.transitionState === TransitionState.IDLE) {
                updateDisplay();
            }
        }

        // Utility function to shuffle array
        function shuffleArray(array) {
            for (let i = array.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [array[i], array[j]] = [array[j], array[i]];
            }
            return array;
        }

        // 7. Create a periodic state maintenance checker for safety
        function setupSafetyChecks() {
            // Run every 5 seconds
            setInterval(() => {
                // Check if transition is stuck
                const wasStuck = transitionManager.checkForStuckTransition();

                // If we found and fixed a stuck transition, update display
                if (wasStuck) {
                    updateDisplay();
                }
            }, 5000);
        }

        function initialize() {
            debug('Initializing Poster Wall');

            // Clean up any existing timers
            timerManager.cleanupAllTimers();

            // Process initial data
            if (!state.items || state.items.length === 0) {
                showError('No content available. Please check your server connection and refresh the page.');
                return;
            }

            // Add unique IDs to items
            state.items = state.items.map(item => ({
                ...item,
                _id: state.uniqueId++
            }));

            // Preload initial content
            if (state.items[state.currentIndex]) {
                preloadStreamImages(state.items[state.currentIndex]);
            }

            // Set initial display
            updateDisplay();

            // Start appropriate timers
            if (!state.isStreaming || state.hasMultipleStreams) {
                timerManager.startDisplayTimer();
            }

            // Start stream checking - CRITICAL FOR STREAM DETECTION
            timerManager.startStreamChecking();

            // Set up window resize handler
            window.addEventListener('resize', adjustPosterSize);
            adjustPosterSize();

            // Set up resize observer
            if (window.ResizeObserver) {
                const resizeObserver = new ResizeObserver(entries => {
                    for (let entry of entries) {
                        if (entry.target === posterContainer && state.transitionState === TransitionState.IDLE) {
                            adjustPosterSize();
                        }
                    }
                });

                resizeObserver.observe(posterContainer);
            }

            // Set up safety checks
            setupSafetyChecks();

            debug('Initialization complete');
        }

        // Clean up on page unload
        window.addEventListener('beforeunload', () => {
            if (timerManager) timerManager.cleanupAllTimers();
        });

        // Initialize when page loads
        window.onload = initialize;
    </script>
</body>

</html>