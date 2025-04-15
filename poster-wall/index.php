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
// Replace the getActiveStreams function in the first file (paste.txt) with this updated version
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

// Add these helper functions to the file
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

        // If we have enough items, break early
        if (count($items) >= $count * 2) {
            break;
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

        #poster-container {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            height: 90vh;
            z-index: 2;
            transition: width 0.5s ease, height 0.5s ease;
            perspective: 1000px;
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

        // State variables
        let state = {
            items: <?php echo !empty($items_json) ? $items_json : '[]'; ?>,
            isStreaming: <?php echo !empty($active_streams) ? 'true' : 'false'; ?>,
            hasMultipleStreams: <?php echo (count($active_streams) > 1) ? 'true' : 'false'; ?>,
            currentIndex: 0,
            transitioning: false,
            displayTimer: null,
            streamCheckTimer: null,
            streamCheckScheduled: false,
            loadingNewBatch: false,
            pendingBatch: null,
            lastTransitionTime: 0,
            transitionLock: false,
            preloadedImages: {},
            currentTransitionTimeout: null,
            lastStreamCheck: 0, // Timestamp of last stream check
            streamCheckQueue: [], // Queue of stream checks
            previousStreams: [], // Keep track of previous stream sets
            uniqueId: 1  // Used for generating unique IDs for tracking streams
        };

        // URLs
        const urls = {
            proxy: '<?php echo $proxy_url; ?>',
            checkStreams: './check-streams.php'
        };

        // DOM elements
        const background = document.getElementById('background');
        const posterContainer = document.getElementById('poster-container');

        // Debug logger
        function debug(message) {
            if (config.debug) {
                const timestamp = new Date().toISOString().split('T')[1].split('.')[0];
                console.log(`[${timestamp}] ${message}`);
            }
        }

        // Initialize the app
        function initialize() {
            debug('Initializing Poster Wall');

            // Check if we have content to display
            if (!state.items || state.items.length === 0) {
                showError('No content available. Please check your server connection and refresh the page.');
                return;
            }

            // Add unique IDs to each item for better tracking
            state.items = state.items.map(item => {
                return { ...item, _id: state.uniqueId++ };
            });

            // Preload current poster's images
            if (state.items[state.currentIndex]) {
                preloadStreamImages(state.items[state.currentIndex]);
            }

            // Set initial poster
            updateDisplay();

            // Start display timer only if we have multiple items to show 
            // and not showing just a single stream
            if (!state.isStreaming || state.hasMultipleStreams) {
                startDisplayTimer();
            }

            // Start stream checking
            startStreamChecking();

            // Handle window resize
            window.addEventListener('resize', adjustPosterSize);
            adjustPosterSize();
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

        // Helper function to convert Plex URLs to proxy URLs
        function getProxyUrl(plexUrl) {
            if (!plexUrl) return '';
            return `${urls.proxy}?path=${btoa(plexUrl)}`;
        }

        // Start display timer
        function startDisplayTimer() {
            // Clear existing timer
            stopDisplayTimer();

            // Start new timer
            debug('Starting display timer');
            state.displayTimer = setInterval(nextPoster, config.displayDuration);
        }

        // Stop display timer
        function stopDisplayTimer() {
            if (state.displayTimer) {
                debug('Stopping display timer');
                clearInterval(state.displayTimer);
                state.displayTimer = null;
            }
        }

        // Start stream checking
        function startStreamChecking() {
            // Check immediately on start
            scheduleStreamCheck(0);

            // Clear existing timer
            if (state.streamCheckTimer) {
                clearInterval(state.streamCheckTimer);
            }

            // Start new timer
            state.streamCheckTimer = setInterval(() => {
                scheduleStreamCheck();
            }, config.streamCheckInterval);
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

        // Check for streams
        function checkForStreams() {
            const now = Date.now();

            // Ensure we don't check too frequently (debounce)
            if (now - state.lastStreamCheck < config.streamCheckDebounce) {
                debug('Skipping stream check - too soon since last check');
                return;
            }

            state.lastStreamCheck = now;

            debug('Checking for active streams...');

            // Skip if we're in the middle of a transition or lock
            if (state.transitioning || state.transitionLock) {
                debug('Skipping stream check - in transition or locked');
                return;
            }

            fetch(urls.checkStreams)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    // Apply transition lock to ensure nothing interrupts our state changes
                    state.transitionLock = true;

                    try {
                        processStreamCheckResult(data);
                    } finally {
                        // Ensure lock gets removed even if there's an error
                        setTimeout(() => {
                            state.transitionLock = false;
                        }, 500);
                    }
                })
                .catch(error => {
                    console.error('Error checking for streams:', error);
                    state.transitionLock = false;
                });
        }

        // Process the result of a stream check
        function processStreamCheckResult(data) {
            debug('Processing stream check result');

            // Add unique IDs to all items
            let activeStreams = (data.active_streams || []).map(item => {
                return { ...item, _id: state.uniqueId++ };
            });

            let randomItems = (data.random_items || []).map(item => {
                return { ...item, _id: state.uniqueId++ };
            });

            const wasStreaming = state.isStreaming;
            const hadMultipleStreams = state.hasMultipleStreams;

            // Check current state
            const isStreaming = activeStreams.length > 0;
            const hasMultipleStreams = activeStreams.length > 1;

            debug(`Stream check: was=${wasStreaming}(${hadMultipleStreams ? 'multiple' : 'single'}), now=${isStreaming}(${hasMultipleStreams ? 'multiple' : 'single'})`);

            // VERY IMPORTANT: Remember the current item being displayed
            const currentDisplayedItem = state.items[state.currentIndex];
            if (!currentDisplayedItem) {
                debug('No current item found - this should not happen');
            } else {
                debug(`Current displayed item: ${currentDisplayedItem.title}`);
            }

            // Save current streams for tracking stream changes
            if (state.isStreaming) {
                state.previousStreams = [...state.items];
            }

            // Calculate new streaming state

            // Case 1: Switching from no streams to streaming
            if (!wasStreaming && isStreaming) {
                debug(`Switching to streaming mode. Streams: ${activeStreams.length}`);
                handleSwitchToStreaming(currentDisplayedItem, activeStreams, hasMultipleStreams);
            }
            // Case 2: Switching from streaming to no streams
            else if (wasStreaming && !isStreaming) {
                debug('Switching back to random posters');
                handleSwitchToRandom(currentDisplayedItem, randomItems);
            }
            // Case 3: Staying in streaming mode but stream count changed
            else if (wasStreaming && isStreaming) {
                debug(`Stream count: ${activeStreams.length}`);
                handleStreamChanges(currentDisplayedItem, activeStreams, hadMultipleStreams, hasMultipleStreams);
            }
            // Case 4: Store random items for later use
            else if (!state.isStreaming && randomItems.length > 0 && state.pendingBatch === null) {
                // Store random items for next batch
                state.pendingBatch = randomItems;
            }
        }

        // Handle switching from no streams to active streams
        function handleSwitchToStreaming(currentDisplayedItem, activeStreams, hasMultipleStreams) {
            // Get the current random poster for transition
            const currentRandomPoster = currentDisplayedItem;

            // Preload the stream images
            preloadStreamImages(activeStreams[0]);

            // Create transition items array with current random poster and first stream
            const transitionItems = [currentRandomPoster, activeStreams[0]];

            debug(`Transitioning from random "${currentRandomPoster.title}" to stream "${activeStreams[0].title}"`);

            // Create transition with callback
            performTransition(0, 1, transitionItems, () => {
                // After transition completes, update full state
                state.isStreaming = true;
                state.hasMultipleStreams = hasMultipleStreams;
                state.items = activeStreams;
                state.currentIndex = 0;

                // Start or stop the display timer based on number of streams
                if (hasMultipleStreams) {
                    startDisplayTimer();
                } else {
                    stopDisplayTimer();
                }

                debug('Transition to streaming mode complete');
            });
        }

        // Handle switching from streams to random posters
        function handleSwitchToRandom(currentDisplayedItem, randomItems) {
            // Get the current stream for transition
            const currentStream = currentDisplayedItem;

            // Ensure we have random items to transition to
            if (randomItems.length === 0) {
                debug('No random items available for transition');
                state.isStreaming = false;
                state.hasMultipleStreams = false;
                state.items = [];
                state.currentIndex = 0;
                updateDisplay();
                return;
            }

            // Preload the random poster image
            preloadStreamImages(randomItems[0]);

            // Create transition items array with current stream and first random poster
            const transitionItems = [currentStream, randomItems[0]];

            debug(`Transitioning from stream "${currentStream.title}" to random "${randomItems[0].title}"`);

            // Perform transition with callback
            performTransition(0, 1, transitionItems, () => {
                // After transition completes, update full state
                state.isStreaming = false;
                state.hasMultipleStreams = false;
                state.items = randomItems;
                state.currentIndex = 0;

                // Always start transition timer for random posters
                startDisplayTimer();

                debug('Transition to random posters complete');
            });
        }

        // Handle changes to the stream list
        function handleStreamChanges(currentDisplayedItem, activeStreams, hadMultipleStreams, hasMultipleStreams) {
            // Use keys to more accurately detect changes
            const currentStreamsKeys = new Set(state.items.map(getStreamKey));
            const newStreamsKeys = new Set(activeStreams.map(getStreamKey));

            // Find streams that have been removed
            const removedStreams = state.items.filter(stream =>
                !newStreamsKeys.has(getStreamKey(stream))
            );

            // Find streams that have been added
            const addedStreams = activeStreams.filter(stream =>
                !currentStreamsKeys.has(getStreamKey(stream))
            );

            // Only consider it a change if streams were added or removed
            const streamsChanged = removedStreams.length > 0 || addedStreams.length > 0;

            debug(`Stream analysis: removed=${removedStreams.length}, added=${addedStreams.length}, changed=${streamsChanged}`);

            if (streamsChanged) {
                debug('Stream list changed, updating');

                // If changing from multiple to single stream
                if (hadMultipleStreams && !hasMultipleStreams) {
                    debug('Switching from multiple streams to single stream');
                    handleMultipleToSingleStream(currentDisplayedItem, activeStreams);
                }
                // If changing from single to multiple streams
                else if (!hadMultipleStreams && hasMultipleStreams) {
                    debug('Switching from single stream to multiple streams');
                    handleSingleToMultipleStreams(currentDisplayedItem, activeStreams);
                }
                // If staying with multiple streams but the list changed
                else if (hasMultipleStreams) {
                    debug('Updating multiple streams list');
                    handleMultipleStreamsUpdate(currentDisplayedItem, activeStreams, removedStreams);
                }
                // If staying with single stream but the stream changed
                else {
                    debug('Switching to different single stream');
                    handleSingleStreamUpdate(currentDisplayedItem, activeStreams);
                }
            } else {
                // Same streams, just update progress if streaming
                if (state.isStreaming) {
                    debug('Same streams, updating progress');

                    // Update with new progress data while preserving current index
                    const currentStreamKey = getStreamKey(currentDisplayedItem);

                    state.items = activeStreams;

                    // Find the same stream in the updated array
                    const newIndex = activeStreams.findIndex(s =>
                        getStreamKey(s) === currentStreamKey);

                    if (newIndex !== -1) {
                        state.currentIndex = newIndex;
                    }

                    updateStreamInfo(state.items[state.currentIndex]);
                }
            }
        }

        // Handle transition from multiple streams to a single stream
        function handleMultipleToSingleStream(currentDisplayedItem, activeStreams) {
            // First make sure there is at least one stream
            if (activeStreams.length === 0) {
                debug('No streams left, unusual state');
                state.isStreaming = false;
                state.hasMultipleStreams = false;
                // We should have caught this in the main handler
                return;
            }

            // We need to determine the right transition:
            // 1. If current stream is gone, transition to the remaining stream
            // 2. If current stream is the remaining stream, no transition needed

            const currentStreamKey = getStreamKey(currentDisplayedItem);
            const remainingStreamKey = getStreamKey(activeStreams[0]);

            // Check if the current stream is still there
            if (currentStreamKey === remainingStreamKey) {
                debug('Current stream is the remaining stream, no transition needed');
                state.items = activeStreams;
                state.hasMultipleStreams = false;
                state.currentIndex = 0;

                // Stop timer for single stream
                stopDisplayTimer();

                // Just update info
                updateStreamInfo(state.items[0]);
            } else {
                debug('Current stream is gone, transitioning to remaining stream');

                // Get current stream for transition
                const currentStream = currentDisplayedItem;

                // Preload the remaining stream
                preloadStreamImages(activeStreams[0]);

                // Create transition array with current and remaining stream
                const transitionItems = [currentStream, activeStreams[0]];

                debug(`Transitioning from "${currentStream.title}" to remaining stream "${activeStreams[0].title}"`);

                // Perform transition with callback
                performTransition(0, 1, transitionItems, () => {
                    // After transition, update state
                    state.items = activeStreams;
                    state.hasMultipleStreams = false;
                    state.currentIndex = 0;

                    // Stop timer for single stream
                    stopDisplayTimer();

                    debug('Transition to single stream complete');
                });
            }
        }

        // Handle transition from single stream to multiple streams
        function handleSingleToMultipleStreams(currentDisplayedItem, activeStreams) {
            // Ensure we have at least 2 streams
            if (activeStreams.length < 2) {
                debug('Somehow switching to multiple streams but only have one stream');
                state.items = activeStreams;
                state.currentIndex = 0;
                updateDisplay();
                return;
            }

            // Deep clone the current stream
            const currentStream = JSON.parse(JSON.stringify(currentDisplayedItem));

            // Find the first stream in the new list that's DIFFERENT from our current one
            const currentStreamKey = getStreamKey(currentStream);
            let targetStreamIndex = -1;

            for (let i = 0; i < activeStreams.length; i++) {
                // Check if this stream is different from our current one
                if (getStreamKey(activeStreams[i]) !== currentStreamKey) {
                    targetStreamIndex = i;
                    break;
                }
            }

            // If we couldn't find a different stream (unlikely), use the second stream
            if (targetStreamIndex === -1) {
                targetStreamIndex = 1; // Default to second stream
            }

            // Preload the stream images
            preloadStreamImages(currentStream);
            preloadStreamImages(activeStreams[targetStreamIndex]);

            debug(`Will transition from "${currentStream.title}" to "${activeStreams[targetStreamIndex].title}"`);

            // Create a dedicated transition array with exactly 2 items
            const transitionItems = [currentStream, activeStreams[targetStreamIndex]];

            // Perform transition with full callback
            performTransition(0, 1, transitionItems, () => {
                // After transition completes, update full state
                state.items = activeStreams;
                state.hasMultipleStreams = true;
                state.currentIndex = targetStreamIndex; // IMPORTANT: Keep the current index at targetStreamIndex

                // Start the timer for future transitions
                startDisplayTimer();

                debug('Stream transition complete');
            });
        }

        // Handle updates to multiple streams list
        function handleMultipleStreamsUpdate(currentDisplayedItem, activeStreams, removedStreams) {
            const currentStreamKey = getStreamKey(currentDisplayedItem);

            // Check if the current stream was removed
            const currentStreamRemoved = removedStreams.some(s => getStreamKey(s) === currentStreamKey);

            if (currentStreamRemoved) {
                debug('Current stream was removed, transitioning to another stream');

                // Find a suitable stream to transition to
                let targetStreamIndex = 0; // Default to first stream

                // Create a transition array
                const transitionItems = [currentDisplayedItem, activeStreams[targetStreamIndex]];

                // Preload images
                preloadStreamImages(activeStreams[targetStreamIndex]);

                debug(`Transitioning from removed "${currentDisplayedItem.title}" to "${activeStreams[targetStreamIndex].title}"`);

                // Perform transition with callback
                performTransition(0, 1, transitionItems, () => {
                    // After transition, update state
                    state.items = activeStreams;
                    state.hasMultipleStreams = true;
                    state.currentIndex = targetStreamIndex;

                    // Restart timer for multiple streams
                    startDisplayTimer();

                    debug('Transition after stream removal complete');
                });
            } else {
                // Current stream is still in the list
                state.items = activeStreams;

                // Find the current stream in the new list
                const newIndex = activeStreams.findIndex(s => getStreamKey(s) === currentStreamKey);

                if (newIndex !== -1) {
                    state.currentIndex = newIndex;
                } else {
                    // This should not happen given our check above
                    state.currentIndex = 0;
                }

                // Restart timer for multiple streams
                startDisplayTimer();

                // Just update the display
                updateDisplay();
            }
        }

        // Handle update to a single stream
        function handleSingleStreamUpdate(currentDisplayedItem, activeStreams) {
            // Check that we actually have a stream
            if (activeStreams.length === 0) {
                debug('No streams left, unusual state');
                state.isStreaming = false;
                state.hasMultipleStreams = false;
                // We should have caught this in the main handler
                return;
            }

            // Create transition to the new stream
            const transitionItems = [currentDisplayedItem, activeStreams[0]];

            debug(`Transitioning from "${currentDisplayedItem.title}" to new stream "${activeStreams[0].title}"`);

            // Preload the new stream
            preloadStreamImages(activeStreams[0]);

            // Perform transition with callback
            performTransition(0, 1, transitionItems, () => {
                // After transition, update state
                state.items = activeStreams;
                state.hasMultipleStreams = false;
                state.currentIndex = 0;

                debug('Transition to new single stream complete');
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

        // Load more random posters
        function loadMorePosters() {
            debug('Loading more posters...');

            // If we already have a pending batch, use it
            if (state.pendingBatch) {
                processNewBatch(state.pendingBatch);
                state.pendingBatch = null;
                return;
            }

            // Set flag to avoid multiple requests
            state.loadingNewBatch = true;

            fetch(`${urls.checkStreams}?batch=${Math.floor(Math.random() * 1000)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    // Apply unique IDs to items
                    const randomItems = (data.random_items || []).map(item => {
                        return { ...item, _id: state.uniqueId++ };
                    });

                    const activeStreams = (data.active_streams || []).map(item => {
                        return { ...item, _id: state.uniqueId++ };
                    });

                    // If we suddenly have active streams, switch to them
                    if (activeStreams.length > 0 && !state.isStreaming) {
                        state.isStreaming = true;
                        state.hasMultipleStreams = activeStreams.length > 1;
                        state.items = activeStreams;
                        state.currentIndex = 0;

                        if (state.hasMultipleStreams) {
                            startDisplayTimer();
                        } else {
                            stopDisplayTimer();
                        }

                        updateDisplay();
                        state.loadingNewBatch = false;
                        return;
                    }

                    // If we're not streaming, process the new batch
                    if (!state.isStreaming && randomItems.length > 0) {
                        processNewBatch(randomItems);
                    }

                    // Reset loading flag
                    state.loadingNewBatch = false;
                })
                .catch(error => {
                    console.error('Error loading more posters:', error);
                    state.loadingNewBatch = false;
                });
        }

        // Process a new batch of posters with proper transition
        function processNewBatch(newBatch) {
            debug(`Processing new batch of ${newBatch.length} posters`);

            // Save current item for transition
            const currentItem = state.items[state.currentIndex];

            // Create transition items array with just what we need for the transition
            const transitionItems = [
                currentItem,  // Current poster
                newBatch[0]   // First poster in new batch
            ];

            // Preload images
            preloadStreamImages(currentItem);
            preloadStreamImages(newBatch[0]);

            debug(`Transitioning batch: From "${currentItem.title}" to "${newBatch[0].title}"`);

            // Perform transition with callback
            performTransition(0, 1, transitionItems, () => {
                // Update the items array after transition completes
                state.items = newBatch;
                state.currentIndex = 0;

                debug('New batch loaded and transition completed');
            });
        }

        // Move to next poster
        function nextPoster() {
            if (state.transitioning || state.loadingNewBatch || state.transitionLock) {
                debug('Skip nextPoster - transition state prevents it');
                return;
            }

            // Skip transitions if we're showing a single stream
            if (state.isStreaming && !state.hasMultipleStreams) {
                debug('Single stream mode - no transitions');
                return;
            }

            // Check if we need to load more posters
            if (!state.isStreaming && state.currentIndex >= config.preloadThreshold && !state.loadingNewBatch) {
                debug(`At poster ${state.currentIndex} of ${state.items.length}, loading more`);
                loadMorePosters();
                return;
            }

            // Calculate next index
            const nextIndex = (state.currentIndex + 1) % state.items.length;

            debug(`Moving to next poster: ${state.currentIndex} -> ${nextIndex}`);

            // Preload next poster's images
            if (state.items[nextIndex]) {
                preloadStreamImages(state.items[nextIndex]);
            }

            // Transition to next poster
            performTransition(state.currentIndex, nextIndex);

            // Update current index
            state.currentIndex = nextIndex;
        }

        // Perform a transition with safety mechanisms
        function performTransition(fromIndex, toIndex, customItems, callback) {
            // Apply transition lock
            state.transitionLock = true;

            // Perform the actual transition
            transition(fromIndex, toIndex, customItems, (...args) => {
                // Remove transition lock after a delay
                setTimeout(() => {
                    state.transitionLock = false;
                }, 100);

                // Call the original callback if provided
                if (callback) {
                    callback(...args);
                }
            });
        }

        // Update display with current item
        function updateDisplay() {
            if (!state.items || state.items.length === 0 || state.transitioning) {
                debug('Skip updateDisplay - invalid state');
                return;
            }

            // Get current item
            const currentItem = state.items[state.currentIndex];
            if (!currentItem) {
                debug('Skip updateDisplay - no current item');
                return;
            }

            debug(`Updating display to show: ${currentItem.title || 'Unknown'} (ID: ${currentItem._id || 'none'})`);

            // Preload current item's images
            preloadStreamImages(currentItem);

            // Clear existing content
            posterContainer.innerHTML = '';

            // Update background
            const artUrl = currentItem.art || currentItem.thumb ?
                getProxyUrl(currentItem.art || currentItem.thumb) : '';
            background.style.backgroundImage = artUrl ? `url(${artUrl})` : '';

            // Create poster
            const posterUrl = (currentItem.type === 'episode' && currentItem.show_thumb) ?
                getProxyUrl(currentItem.show_thumb) :
                (currentItem.thumb ? getProxyUrl(currentItem.thumb) : '');

            if (posterUrl) {
                const poster = document.createElement('div');
                poster.style.width = '100%';
                poster.style.height = '100%';
                poster.style.backgroundImage = `url(${posterUrl})`;
                poster.style.backgroundSize = 'cover';
                poster.style.backgroundPosition = 'center';
                poster.style.position = 'absolute';
                posterContainer.appendChild(poster);
            }

            // Add streaming elements if in streaming mode
            if (state.isStreaming) {
                // Add streaming badge
                const badge = document.createElement('div');
                badge.className = 'streaming-badge';
                badge.textContent = 'Currently Streaming';
                posterContainer.appendChild(badge);

                // Add stream info
                addStreamInfo(currentItem);
            }
        }

        // Update stream info only (for progress updates without changing the display)
        function updateStreamInfo(stream) {
            if (!stream) return;

            // Find existing stream info element
            const existingInfo = posterContainer.querySelector('.stream-info');
            if (existingInfo) {
                existingInfo.remove();
            }

            // Add updated stream info
            addStreamInfo(stream);
        }

        // Add streaming information
        function addStreamInfo(stream) {
            if (!stream) return;

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

            posterContainer.appendChild(infoDiv);
        }

        // Handle transition between posters
        function transition(fromIndex, toIndex, customItems, callback) {
            if (state.transitioning) {
                debug('Skip transition - already transitioning');
                return;
            }

            state.transitioning = true;
            state.lastTransitionTime = Date.now();

            // Clear any existing transition timeout
            if (state.currentTransitionTimeout) {
                clearTimeout(state.currentTransitionTimeout);
                state.currentTransitionTimeout = null;
            }

            // Use custom items array if provided, otherwise use state.items
            const items = customItems || state.items;

            debug(`Starting transition from ${fromIndex} to ${toIndex} (Custom items: ${customItems ? 'Yes' : 'No'})`);

            // Get items for transition
            const fromItem = items[fromIndex];
            const toItem = items[toIndex];

            if (!fromItem || !toItem) {
                debug('Invalid items for transition');
                state.transitioning = false;
                if (callback) callback();
                updateDisplay();
                return;
            }

            // Get URLs for transition
            const fromPosterUrl = (fromItem.type === 'episode' && fromItem.show_thumb) ?
                getProxyUrl(fromItem.show_thumb) :
                (fromItem.thumb ? getProxyUrl(fromItem.thumb) : '');

            const toPosterUrl = (toItem.type === 'episode' && toItem.show_thumb) ?
                getProxyUrl(toItem.show_thumb) :
                (toItem.thumb ? getProxyUrl(toItem.thumb) : '');

            if (!fromPosterUrl || !toPosterUrl) {
                debug('Missing poster URLs for transition');
                state.transitioning = false;
                if (callback) callback();
                updateDisplay();
                return;
            }

            // Update background for destination item
            const artUrl = toItem.art || toItem.thumb ?
                getProxyUrl(toItem.art || toItem.thumb) : '';
            if (artUrl) {
                background.style.backgroundImage = `url(${artUrl})`;
            }

            // Clear existing content
            posterContainer.innerHTML = '';

            const containerWidth = posterContainer.offsetWidth;
            const containerHeight = posterContainer.offsetHeight;

            const tileWidth = containerWidth / config.tileCols;
            const tileHeight = containerHeight / config.tileRows;

            // Create tile grid for transition
            const tiles = [];
            for (let row = 0; row < config.tileRows; row++) {
                for (let col = 0; col < config.tileCols; col++) {
                    const tile = document.createElement('div');
                    tile.className = 'tile';
                    tile.style.width = tileWidth + 'px';
                    tile.style.height = tileHeight + 'px';
                    tile.style.top = (row * tileHeight) + 'px';
                    tile.style.left = (col * tileWidth) + 'px';

                    // From poster image
                    tile.style.backgroundImage = `url(${fromPosterUrl})`;
                    tile.style.backgroundPosition = `-${col * tileWidth}px -${row * tileHeight}px`;
                    tile.style.backgroundSize = `${containerWidth}px ${containerHeight}px`;

                    // Add back face
                    const tileBack = document.createElement('div');
                    tileBack.className = 'tile-back';
                    tileBack.style.backgroundImage = `url(${toPosterUrl})`;
                    tileBack.style.backgroundPosition = `-${col * tileWidth}px -${row * tileHeight}px`;
                    tileBack.style.backgroundSize = `${containerWidth}px ${containerHeight}px`;

                    tile.appendChild(tileBack);
                    posterContainer.appendChild(tile);
                    tiles.push(tile);
                }
            }

            // Add streaming elements if in streaming mode
            if (state.isStreaming || (customItems && fromItem.user)) {
                // Add streaming badge
                const badge = document.createElement('div');
                badge.className = 'streaming-badge';
                badge.textContent = 'Currently Streaming';
                posterContainer.appendChild(badge);

                // Add stream info
                addStreamInfo(fromItem);
            }

            // Shuffle tiles for random flip effect
            shuffleArray(tiles);

            // Animate tiles
            const totalTiles = tiles.length;
            const maxDelay = config.transitionDuration * 0.95;

            // Track tiles that have been animated
            let animatedTiles = 0;

            // Add event listener to track animation completions
            tiles.forEach((tile, index) => {
                // Create a slight ease-out effect
                const progress = index / totalTiles;
                const easedProgress = progress < 0.8 ?
                    progress :
                    0.8 + (progress - 0.8) * 0.7;

                const delay = easedProgress * maxDelay;

                // Start the tile animation
                setTimeout(() => {
                    tile.style.transform = 'rotateY(180deg)';
                    animatedTiles++;

                    // For additional safety, if all tiles have animated, double-check the callback
                    if (animatedTiles === totalTiles) {
                        // Reset immediately to prevent any stray "transitioning" flags
                        setTimeout(() => {
                            // If we're still transitioning after all tiles have flipped plus a safety margin,
                            // something might be wrong - clean up just in case
                            if (state.transitioning) {
                                debug('Safety check: All tiles animated but still transitioning');
                                finishTransition();
                            }
                        }, 500); // Small safety margin
                    }
                }, delay);
            });

            // Function to cleanly finish a transition
            function finishTransition() {
                debug('Finishing transition...');

                // Reset transitioning flag
                state.transitioning = false;

                // Run callback if provided before updating display
                if (callback) {
                    try {
                        callback();
                    } catch (e) {
                        console.error('Error in transition callback:', e);
                    }
                }

                // Update display with new poster
                updateDisplay();

                // Restart display timer only if needed (multiple streams or not streaming)
                if (!state.isStreaming || (state.isStreaming && state.hasMultipleStreams)) {
                    startDisplayTimer();
                }

                // Clear the transition timeout reference
                state.currentTransitionTimeout = null;
            }

            // Wait for transition to complete
            const transitionTimeout = setTimeout(finishTransition, config.transitionDuration + 200);

            // Keep track of the timeout for cleanup
            state.currentTransitionTimeout = transitionTimeout;
        }

        // Adjust poster size
        function adjustPosterSize() {
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

            // Apply the calculated dimensions
            posterContainer.style.width = posterWidth + 'px';
            posterContainer.style.height = posterHeight + 'px';

            // Update display if not in transition
            if (!state.transitioning) {
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

        // Safety check to make sure transitions don't get stuck
        setInterval(() => {
            // If we've been transitioning for too long, reset the flag
            if (state.transitioning) {
                const currentTime = Date.now();
                const transitionDuration = currentTime - state.lastTransitionTime;

                if (transitionDuration > config.transitionDuration * 2) {
                    debug('Transition seems stuck, resetting transition state');
                    state.transitioning = false;

                    // Also clear any lingering transition timeout
                    if (state.currentTransitionTimeout) {
                        clearTimeout(state.currentTransitionTimeout);
                        state.currentTransitionTimeout = null;
                    }
                }
            }

            // Also reset lock if it's been too long
            if (state.transitionLock) {
                const currentTime = Date.now();
                const transitionDuration = currentTime - state.lastTransitionTime;

                if (transitionDuration > config.transitionDuration * 3) {
                    debug('Transition lock seems stuck, resetting');
                    state.transitionLock = false;
                }
            }
        }, 5000);

        // Clean up when page unloads
        window.addEventListener('beforeunload', () => {
            if (state.displayTimer) {
                clearInterval(state.displayTimer);
            }
            if (state.streamCheckTimer) {
                clearInterval(state.streamCheckTimer);
            }
            if (state.currentTransitionTimeout) {
                clearTimeout(state.currentTransitionTimeout);
            }
        });

        // Initialize when page loads
        window.onload = initialize;
    </script>
</body>

</html>