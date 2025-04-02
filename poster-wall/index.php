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
                $stream['show_thumb'] = (string) $video['grandparentThumb']; // Add the show poster
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
    error_log("Fetching libraries from: {$libraries_url}");

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
        error_log("cURL error when fetching libraries: " . curl_error($ch));
        curl_close($ch);
        return [];
    }

    curl_close($ch);

    if (!$response) {
        error_log("Empty response when fetching libraries");
        return [];
    }

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
            if ((string) $directory['type'] == 'movie' || (string) $directory['type'] == 'show') {
                $library_keys[] = (string) $directory['key'];
            }
        }
    }

    // Get random items from each library
    $items = [];
    foreach ($library_keys as $key) {
        $items_url = "{$server_url}/library/sections/{$key}/all?X-Plex-Token={$token}";
        error_log("Fetching items from library {$key}: {$items_url}");

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
            error_log("cURL error when fetching items from library {$key}: " . curl_error($ch));
            curl_close($ch);
            continue; // Skip to next library
        }

        curl_close($ch);

        if (!$response) {
            error_log("Empty response when fetching items from library {$key}");
            continue; // Skip to next library
        }

        // Try to parse the XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);

        if ($xml === false) {
            $errors = libxml_get_errors();
            foreach ($errors as $error) {
                error_log("XML parsing error in library {$key}: {$error->message}");
            }
            libxml_clear_errors();
            continue; // Skip to next library
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
error_log("Active streams count: " . count($active_streams));

// If no active streams, get random items
$items = [];
if (empty($active_streams)) {
    $items = getRandomLibraryItems($plex_url, $plex_token, 20);
    error_log("Random items count: " . count($items));
} else {
    $items = $active_streams;
}

// Ensure we have at least one valid item to display
if (empty($items)) {
    // Provide a fallback item if no items were found
    $items = [
        [
            'title' => 'No content available',
            'type' => 'placeholder',
            'thumb' => '',
            'art' => '',
        ]
    ];
    error_log("Using fallback item. No content found from Plex server.");
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
        /* ==========================================================================
           1. Theme Variables
           ========================================================================== */
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
        // Initial items from PHP
        const initialItems = <?php echo !empty($items_json) ? $items_json : '[]'; ?>;
        const isInitialStreaming = <?php echo !empty($active_streams) ? 'true' : 'false'; ?>;
        const proxyUrl = '<?php echo $proxy_url; ?>';
        const checkStreamsUrl = './check-streams.php';

        // DOM elements
        const background = document.getElementById('background');
        const posterContainer = document.getElementById('poster-container');

        // Configuration
        const tileRows = 12;
        const tileCols = 8;
        const displayDuration = 10000; // 10 seconds between stream transitions (reduced for testing)
        const transitionDuration = 3000; // 3 seconds for the transition animation
        const streamCheckInterval = 10000; // Check for streams every 10 seconds

        // Add this to the JavaScript configuration section, after the other configuration variables
        const randomRefreshInterval = 300000; // 5 minutes (in milliseconds) to refresh random posters
        let randomRefreshTimer = null; // Timer for refreshing random posters

        // Current state
        let currentIndex = 0;
        let transitioning = false;
        let timerInterval = null;
        let streamCheckTimer = null;
        let lastTransitionTime = 0;
        let items = initialItems;
        let isStreaming = isInitialStreaming;
        let streamIds = []; // Keep track of current stream IDs
        let randomItems = []; // Store random items for fallback
        let forceTransition = false; // Flag to force transition even when updating display
        let multipleStreams = isInitialStreaming && initialItems.length > 1; // Flag to track multiple streams
        let currentTransitionTimeout = null; // Track the current transition timeout

        // Debug helper
        function debug(message) {
            console.log(`[Debug] ${message}`);
        }

        // Function to start random poster refresh timer
        function startRandomRefreshTimer() {
            debug('Starting random poster refresh timer');
            // Clear any existing timer first
            stopRandomRefreshTimer();

            // Set new timer
            randomRefreshTimer = setInterval(refreshRandomPosters, randomRefreshInterval);
        }

        // Function to stop random poster refresh timer
        function stopRandomRefreshTimer() {
            if (randomRefreshTimer) {
                debug('Stopping random poster refresh timer');
                clearInterval(randomRefreshTimer);
                randomRefreshTimer = null;
            }
        }

        // Function to refresh random posters
        function refreshRandomPosters() {
            // Only refresh if we're not streaming and not in transition
            if (isStreaming || transitioning) {
                debug('Not refreshing random posters: streaming or in transition');
                return;
            }

            debug('Refreshing random posters');

            fetch(checkStreamsUrl)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    debug(`Got fresh random posters at timestamp ${data.timestamp || 'unknown'}`);

                    // Check if we're still in non-streaming mode before applying changes
                    if (!isStreaming && !transitioning) {
                        const activeStreams = data.active_streams || [];

                        // If there are suddenly active streams, handle stream detection
                        if (activeStreams.length > 0) {
                            debug('Active streams detected during random refresh, handling stream detection');
                            handleFirstStreamDetection(data);
                            return;
                        }

                        // Get new random items
                        if (data.random_items && data.random_items.length > 0) {
                            debug(`Received ${data.random_items.length} new random posters`);

                            // Remember current index position as a percentage
                            const currentPosition = currentIndex / Math.max(items.length, 1);

                            // Update random items
                            randomItems = data.random_items;
                            items = randomItems;

                            // Calculate new index to maintain approximate position
                            currentIndex = Math.min(Math.floor(currentPosition * items.length), items.length - 1);

                            // Force a transition to a new poster
                            forceTransition = true;
                            transition();
                        }
                    }
                })
                .catch(error => {
                    debug(`Error refreshing random posters: ${error.message}`);
                });
        }

        // Helper function to convert Plex URLs to our proxy handler
        function getImageUrl(plexUrl) {
            if (!plexUrl) return '';
            return `${proxyUrl}?path=${btoa(plexUrl)}`;
        }

        // Function to periodically check for active streams
        function startStreamChecking() {
            debug('Starting periodic stream checking');
            // Check immediately on start
            checkForStreams();

            // Clear any existing timer first
            if (streamCheckTimer) {
                clearInterval(streamCheckTimer);
                streamCheckTimer = null;
            }

            // Then check periodically
            streamCheckTimer = setInterval(checkForStreams, streamCheckInterval);
        }

        // Variable to track if we're in initial stream detection mode
        let streamDetectionLock = false;

        // Function to check for active streams via AJAX
        function checkForStreams() {
            debug('Checking for active streams...');

            // Check if we're currently locked from checking streams
            if (streamDetectionLock) {
                debug('LOCKED: Stream checking currently locked, skipping');
                return;
            }

            // Check if we're in a transition
            if (transitioning) {
                debug('Note: Still transitioning during stream check');
            }

            // For debugging: log current state
            debug(`Current state before check: isStreaming=${isStreaming}, multipleStreams=${multipleStreams}, currentIndex=${currentIndex}, timerRunning=${timerInterval !== null}`);
            if (items && items.length > 0 && currentIndex < items.length) {
                debug(`Current item: ${items[currentIndex].title || 'Unknown'}`);
            }

            fetch(checkStreamsUrl)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    debug(`Got stream data at timestamp ${data.timestamp || 'unknown'}`);
                    debug(`Found ${data.active_streams ? data.active_streams.length : 0} active streams`);

                    const activeStreams = data.active_streams || [];
                    const wasStreaming = isStreaming;
                    const newStreaming = activeStreams.length > 0;

                    // Process stream data regardless of transition state
                    if (!wasStreaming && newStreaming) {
                        // CRITICAL: We're changing from no streams to having streams
                        debug('DETECTED FIRST STREAM - PROCESSING IMMEDIATELY');

                        // Lock stream checking for a period to prevent conflicts
                        streamDetectionLock = true;

                        // Handle the initial stream detection
                        handleFirstStreamDetection(data);

                        // Unlock stream checking after a delay
                        setTimeout(() => {
                            streamDetectionLock = false;
                            debug('STREAM DETECTION UNLOCKED');
                        }, 10000); // 10 second lock
                    } else {
                        // Normal update for existing state
                        handleStreamUpdate(data);
                    }
                })
                .catch(error => {
                    debug(`Error checking streams: ${error.message}`);
                });
        }

        // Special handler for first stream detection
        function handleFirstStreamDetection(data) {
            debug('HANDLING FIRST STREAM DETECTION - COMPLETE RESET');

            // Cancel any ongoing transition
            if (currentTransitionTimeout) {
                debug('Cancelling any existing transition timeout');
                clearTimeout(currentTransitionTimeout);
                currentTransitionTimeout = null;
            }

            // Force transitioning to false immediately
            transitioning = false;

            const activeStreams = data.active_streams || [];

            // Store random items for later
            if (data.random_items && data.random_items.length > 0) {
                randomItems = data.random_items;
            }

            // Reset all state variables
            isStreaming = true;
            multipleStreams = activeStreams.length > 1;
            streamIds = activeStreams.map(stream => `${stream.title}-${stream.user}-${stream.viewOffset}`);

            // Update items array with active streams
            items = activeStreams;

            // Stop all timers
            stopTransitionTimer();

            // Clear display completely
            posterContainer.innerHTML = '';
            background.style.backgroundImage = '';

            // Always start with the first stream
            currentIndex = 0;

            // Force direct display update (no transitions)
            debug(`NOW SHOWING FIRST STREAM: ${items[0] ? items[0].title : 'Unknown'}`);

            // Ensure transitioning is false before updating display
            transitioning = false;

            // Update the display immediately
            updateDisplay();

            // If multiple streams, start transitions
            if (multipleStreams) {
                debug('Multiple streams detected, starting transition timer');
                startTransitionTimer();
            }
        }

        // Function to handle stream updates
        function handleStreamUpdate(data) {
            const activeStreams = data.active_streams || [];

            debug(`Received ${activeStreams.length} active streams`);

            // Check if we got some random items to store
            if (data.random_items && data.random_items.length > 0) {
                randomItems = data.random_items;
                debug(`Stored ${randomItems.length} random items for fallback`);
            }

            // Get IDs for current streams (using title and user as unique identifier)
            // We exclude viewOffset since that changes constantly and isn't a "real" state change
            const newStreamIds = activeStreams.map(stream =>
                `${stream.title}-${stream.user}`);

            // Generate sets for easier comparison
            const currentStreamSet = new Set(streamIds.map(id => id.split('-').slice(0, 2).join('-')));
            const newStreamSet = new Set(newStreamIds);

            // Check if the actual streams have changed (not just their progress)
            const streamsChanged = (
                activeStreams.length !== streamIds.length ||
                newStreamSet.size !== currentStreamSet.size ||
                ![...newStreamSet].every(id => currentStreamSet.has(id))
            );

            debug(`Stream comparison: current=${[...currentStreamSet]}, new=${[...newStreamSet]}, changed=${streamsChanged}`);

            if (streamsChanged) {
                debug('Stream state has changed, updating display');

                // Only cancel ongoing transitions if we're not in the middle of a tile flip
                // This prevents stream checks from interrupting transitions in progress
                if (currentTransitionTimeout && !transitioning) {
                    debug('Cancelling scheduled transition timeout due to stream state change');
                    clearTimeout(currentTransitionTimeout);
                    currentTransitionTimeout = null;
                } else if (transitioning) {
                    debug('NOT cancelling active transition - letting it complete first');
                    // Instead of interrupting, we'll just update after the transition finishes
                    return;
                }

                // Reset transitioning flag
                transitioning = false;

                // Save new stream IDs
                streamIds = newStreamIds;

                // Update streaming state
                const wasStreaming = isStreaming;
                const wasMultipleStreams = multipleStreams;
                isStreaming = activeStreams.length > 0;
                multipleStreams = activeStreams.length > 1;

                debug(`Stream state update: wasStreaming=${wasStreaming}, isStreaming=${isStreaming}, wasMultipleStreams=${wasMultipleStreams}, multipleStreams=${multipleStreams}`);

                // Handle transition between states
                if (!wasStreaming && isStreaming) {
                    // Transition from random posters to streaming
                    debug('Transitioning from random posters to streaming');
                    items = activeStreams;
                    currentIndex = 0;
                    stopTransitionTimer();
                    stopRandomRefreshTimer(); // Stop random refresh timer
                    updateDisplay();

                    // If multiple streams, start transitions between them
                    if (multipleStreams) {
                        debug('Multiple initial streams detected, starting transition timer');
                        startTransitionTimer();
                    }
                } else if (wasStreaming && !isStreaming) {
                    // Transition from streaming to random posters
                    debug('Transitioning from streaming to random posters');
                    items = randomItems.length > 0 ? randomItems : [];
                    currentIndex = 0;
                    updateDisplay();
                    startTransitionTimer();
                    startRandomRefreshTimer(); // Start random refresh timer
                } else if (isStreaming) {
                    // Update the current streams
                    debug('Updating active streams');
                    const currentStream = items[currentIndex];
                    items = activeStreams;

                    // Try to keep the same stream selected if it's still active
                    if (currentStream) {
                        const sameStreamIndex = activeStreams.findIndex(stream =>
                            stream.title === currentStream.title &&
                            stream.user === currentStream.user);

                        if (sameStreamIndex !== -1) {
                            currentIndex = sameStreamIndex;
                        } else {
                            currentIndex = 0;
                        }
                    }

                    updateDisplay();

                    // Key fix: If we switched from one stream to multiple streams,
                    // make sure we start the timer to rotate between them
                    if (!wasMultipleStreams && multipleStreams) {
                        debug('IMPORTANT: Changed from one stream to multiple streams, starting transition timer');
                        stopTransitionTimer();
                        startTransitionTimer();
                    } else if (multipleStreams) {
                        // Just restart the timer to be safe
                        debug('Multiple streams still active, ensuring transition timer is running');
                        stopTransitionTimer();
                        startTransitionTimer();
                    } else {
                        // Single stream, make sure timer is stopped
                        debug('Single stream only, stopping transition timer');
                        stopTransitionTimer();
                    }
                }
            } else {
                // Same streams, but update view offsets/progress
                if (isStreaming && activeStreams.length > 0) {
                    debug('Same streams, updating progress');
                    // Update items with new progress data but maintain current index
                    const currentStream = items[currentIndex];
                    items = activeStreams;

                    // Find the same stream in the new data
                    if (currentStream) {
                        const sameStreamIndex = activeStreams.findIndex(stream =>
                            stream.title === currentStream.title &&
                            stream.user === currentStream.user);

                        if (sameStreamIndex !== -1) {
                            currentIndex = sameStreamIndex;
                        }
                    }

                    // Just update the stream info without full transition
                    updateStreamInfo(items[currentIndex]);

                    // Update multipleStreams flag to ensure it's correct
                    const wasMultipleStreams = multipleStreams;
                    multipleStreams = activeStreams.length > 1;

                    // Log state for debugging
                    debug(`Progress update: wasMultipleStreams=${wasMultipleStreams}, multipleStreams=${multipleStreams}`);

                    // CRITICAL: Make sure transition timer is running if we have multiple streams
                    if (multipleStreams) {
                        // Check if timer is already running
                        if (!timerInterval) {
                            debug('Multiple streams detected but timer not running - starting transition timer');
                            startTransitionTimer();
                        } else {
                            debug('Multiple streams, timer already running');
                        }
                    } else {
                        // Single stream, make sure timer is stopped
                        if (timerInterval) {
                            debug('Only one stream active now, stopping transition timer');
                            stopTransitionTimer();
                        }
                    }
                }
            }
        }

        // Initialize the display
        function init() {
            // Set up a global safety check to ensure the transitioning flag doesn't get stuck
            setInterval(() => {
                if (transitioning) {
                    const now = Date.now();
                    const timeSinceLastTransition = now - lastTransitionTime;

                    // If it's been more than double the transition duration since last transition started,
                    // something is wrong and we should reset the flag
                    if (timeSinceLastTransition > transitionDuration * 2) {
                        debug('GLOBAL SAFETY: Transitioning flag stuck for too long, resetting');
                        transitioning = false;

                        // Also clear any transition timeout that might be lingering
                        if (currentTransitionTimeout) {
                            clearTimeout(currentTransitionTimeout);
                            currentTransitionTimeout = null;
                        }
                    }
                }
            }, 5000); // Check every 5 seconds

            // Check if we have items to display
            if (!items || items.length === 0) {
                debug('No items to display');
                // Create error message display
                posterContainer.innerHTML = `
            <div class="error-container">
                <h2>No Content Available</h2>
                <p>Unable to retrieve content from your Plex server.</p>
                <p>Please check your server connection and refresh the page.</p>
                <button onclick="location.reload()" style="margin-top: 20px; padding: 10px 20px; background: #cc7b19; border: none; color: white; border-radius: 5px; cursor: pointer;">
                    Retry
                </button>
            </div>
        `;
                return;
            }

            debug(`Initialized with ${items.length} items`);
            if (items[0]) {
                debug(`First item: ${items[0].title} (${items[0].type})`);
            }

            // Check for multiple streams on init
            if (isStreaming && items.length > 1) {
                debug("MULTIPLE STREAMS DETECTED ON INITIALIZATION");
                multipleStreams = true;
            }

            // Set initial poster
            updateDisplay();

            // Start timer for transitions if we have more than one item
            if ((isStreaming && items.length > 1) || !isStreaming) {
                debug("Starting transition timer on init");
                startTransitionTimer();

                // Also start random refresh timer if we're not streaming
                if (!isStreaming) {
                    startRandomRefreshTimer();
                }
            }

            // Start periodic stream checking
            startStreamChecking();

            // Handle window resize
            window.addEventListener('resize', adjustPosterSize);
            adjustPosterSize();

            // Extra check after initialization to make sure timer is running if needed
            setTimeout(() => {
                if (multipleStreams && !timerInterval) {
                    debug("CRITICAL: Post-init check found multiple streams but no timer running!");
                    startTransitionTimer();
                }

                // Also check random refresh timer
                if (!isStreaming && !randomRefreshTimer) {
                    debug("CRITICAL: Post-init check found no random refresh timer in random mode!");
                    startRandomRefreshTimer();
                }
            }, 5000);
        }

        // Stop transition timer
        function stopTransitionTimer() {
            if (timerInterval) {
                debug("Stopping transition timer");
                clearInterval(timerInterval);
                timerInterval = null;
            }
        }

        // Adjust poster size to maintain aspect ratio
        function adjustPosterSize() {
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;

            // Standard movie poster ratio is 2:3 (width:height)
            const posterRatio = 2 / 3; // width/height

            let posterWidth, posterHeight;

            // For very small screens (mobile)
            if (viewportWidth <= 480) {
                // Use a larger percentage of the viewport, but maintain ratio
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
            if (!transitioning) {
                updateDisplay();
            }
        }

        // Add streaming information display
        function updateStreamInfo(stream) {
            // Find existing stream info and remove it
            const existingInfo = posterContainer.querySelector('.stream-info');
            if (existingInfo) {
                existingInfo.remove();
            }

            if (!stream) return;

            const infoDiv = document.createElement('div');
            infoDiv.className = 'stream-info';

            let title = stream.title;
            let details = '';

            if (stream.type === 'episode') {
                title = `${stream.show_title}`;
                details = `S${stream.season}E${stream.episode} - ${stream.title} • ${stream.user}`;
            } else {
                details = `${stream.year} • ${stream.user}`;
            }

            // Create progress bar
            const progress = Math.floor((stream.viewOffset / stream.duration) * 100);

            infoDiv.innerHTML = `
                <div class="stream-title">${title}</div>
                <div class="stream-details">${details}</div>
                <div class="stream-progress">
                    <div class="stream-progress-bar" style="width: ${progress}%;"></div>
                </div>
            `;

            posterContainer.appendChild(infoDiv);
        }

        // Update the display with the current item
        function updateDisplay() {
            // First, verify we have valid items and index
            if (!items || items.length === 0) {
                debug('No items to display');
                posterContainer.innerHTML = '';
                return;
            }

            // Make sure current index is valid
            if (currentIndex < 0 || currentIndex >= items.length) {
                debug(`Invalid currentIndex: ${currentIndex}, resetting to 0`);
                currentIndex = 0;
            }

            const currentItem = items[currentIndex];
            if (!currentItem) {
                debug('Current item is undefined, cannot update display');
                return;
            }

            // If we're already transitioning or we want to use tile effect, use transition instead
            if (transitioning || forceTransition) {
                // If we forced a transition, reset the flag
                if (forceTransition) {
                    forceTransition = false;
                    doTileTransition(currentIndex, currentIndex);
                }
                return;
            }

            debug(`Updating display to show item: ${currentIndex} - ${currentItem.title || 'Unknown'}`);

            // IMPORTANT - Clear existing content completely first
            posterContainer.innerHTML = '';

            // Update background
            const artUrl = currentItem.art || currentItem.thumb ?
                getImageUrl(currentItem.art || currentItem.thumb) : '';
            if (artUrl) {
                background.style.backgroundImage = `url(${artUrl})`;
            } else {
                background.style.backgroundImage = '';
            }

            // Create single seamless poster
            const posterUrl = (currentItem.type === 'episode' && currentItem.show_thumb) ?
                getImageUrl(currentItem.show_thumb) :
                (currentItem && currentItem.thumb ? getImageUrl(currentItem.thumb) : '');

            if (posterUrl) {
                debug(`Setting poster image to: ${posterUrl}`);
                const poster = document.createElement('div');
                poster.style.width = '100%';
                poster.style.height = '100%';
                poster.style.backgroundImage = `url(${posterUrl})`;
                poster.style.backgroundSize = 'cover';
                poster.style.backgroundPosition = 'center';
                poster.style.position = 'absolute';
                posterContainer.appendChild(poster);
            } else {
                debug('No poster URL available');
            }

            // Only add streaming elements if we're actually in streaming mode
            if (isStreaming) {
                // Create streaming badge
                debug('Adding streaming badge to index ' + currentIndex);
                const badge = document.createElement('div');
                badge.className = 'streaming-badge';
                badge.textContent = 'Currently Streaming';
                posterContainer.appendChild(badge);

                // Add stream info 
                if (items[currentIndex]) {
                    debug('Adding stream info for ' + (items[currentIndex].title || 'Unknown'));
                    addStreamInfo(items[currentIndex]);
                }
            } else {
                debug('Not in streaming mode, skipping streaming elements');
            }
        }

        // Add streaming information display
        function addStreamInfo(stream) {
            // Check if stream exists before proceeding
            if (!stream) {
                debug("ERROR: Called addStreamInfo with undefined stream");
                return;
            }

            const infoDiv = document.createElement('div');
            infoDiv.className = 'stream-info';

            let title = stream.title || 'Unknown Title';
            let details = '';

            if (stream.type === 'episode') {
                title = `${stream.show_title || 'Unknown Show'}`;
                details = `S${stream.season || '?'}E${stream.episode || '?'} - ${stream.title || 'Unknown'} • ${stream.user || 'Unknown User'}`;
            } else {
                details = `${stream.year || ''} • ${stream.user || 'Unknown User'}`;
            }

            // Create progress bar (with safety checks)
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

        // Start transition timer
        function startTransitionTimer() {
            clearInterval(timerInterval);
            debug(`Starting transition timer - will rotate every ${displayDuration / 1000} seconds`);
            timerInterval = setInterval(transition, displayDuration);
        }

        // Handle transition between posters
        function transition() {
            // Update the last transition time
            lastTransitionTime = Date.now();

            // Don't transition if we're already in the middle of one
            if (transitioning) {
                debug('Already transitioning, skipping this transition request');
                return;
            }

            // Only proceed if we have items to transition between
            if (!items || items.length <= 1) {
                debug('Not enough items to transition between');
                return;
            }

            // Ensure current index is valid
            if (currentIndex < 0 || currentIndex >= items.length) {
                debug(`Invalid current index: ${currentIndex}, resetting to 0`);
                currentIndex = 0;
            }

            // Prepare next item
            const nextIndex = (currentIndex + 1) % items.length;
            debug(`TRANSITION: from item ${currentIndex} to item ${nextIndex} of ${items.length} items`);

            if (items[currentIndex] && items[nextIndex]) {
                debug(`Transitioning from "${items[currentIndex].title || 'Unknown'}" to "${items[nextIndex].title || 'Unknown'}"`);
            }

            // Use our tile transition helper function
            doTileTransition(currentIndex, nextIndex);

            // Reset the transition timer to ensure continual cycling
            if (multipleStreams) {
                debug('Resetting transition timer after transition');
                startTransitionTimer();
            }
        }

        function doTileTransition(fromIndex, toIndex) {
            if (transitioning) {
                debug('Already transitioning, cannot start another transition');
                return;
            }

            debug(`Starting tile transition from index ${fromIndex} to index ${toIndex}`);
            transitioning = true;

            // Record the time we started transitioning
            lastTransitionTime = Date.now();

            // Check for valid indices
            if (fromIndex < 0 || fromIndex >= items.length || toIndex < 0 || toIndex >= items.length) {
                debug(`Invalid indices for transition: ${fromIndex} to ${toIndex} (valid range: 0-${items.length - 1})`);
                transitioning = false;
                return;
            }

            // Get items for transition
            const fromItem = items[fromIndex];
            const toItem = items[toIndex];

            if (!fromItem || !toItem) {
                debug('Missing item for transition, using fallback display');
                transitioning = false;
                currentIndex = toIndex; // Still update the index
                updateDisplay();
                return;
            }

            // Get URLs for the transition
            const fromPosterUrl = (fromItem.type === 'episode' && fromItem.show_thumb) ?
                getImageUrl(fromItem.show_thumb) :
                (fromItem && fromItem.thumb ? getImageUrl(fromItem.thumb) : '');
            const toPosterUrl = (toItem.type === 'episode' && toItem.show_thumb) ?
                getImageUrl(toItem.show_thumb) :
                (toItem && toItem.thumb ? getImageUrl(toItem.thumb) : '');

            if (!toPosterUrl || !fromPosterUrl) {
                debug('Missing poster URL for transition, using fallback display');
                transitioning = false;
                currentIndex = toIndex; // Still update the index
                updateDisplay();
                return;
            }

            debug(`Transitioning from poster ${fromPosterUrl} to ${toPosterUrl}`);

            // Update background for the destination item
            const artUrl = toItem.art || toItem.thumb ?
                getImageUrl(toItem.art || toItem.thumb) : '';
            if (artUrl) {
                background.style.backgroundImage = `url(${artUrl})`;
            }

            // Clear existing content and create tiles for transition effect
            posterContainer.innerHTML = '';

            const containerWidth = posterContainer.offsetWidth;
            const containerHeight = posterContainer.offsetHeight;

            const tileWidth = containerWidth / tileCols;
            const tileHeight = containerHeight / tileRows;

            // Create tile grid just for the transition effect
            const tiles = [];
            for (let row = 0; row < tileRows; row++) {
                for (let col = 0; col < tileCols; col++) {
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

                    // Add back face (to poster)
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

            // Add streaming info if applicable
            if (isStreaming) {
                addStreamInfo(fromItem);
            }

            // Create streaming badge if applicable
            if (isStreaming) {
                const badge = document.createElement('div');
                badge.className = 'streaming-badge';
                badge.textContent = 'Currently Streaming';
                posterContainer.appendChild(badge);
            }

            // Shuffle tiles for random flip effect and animate
            shuffleArray(tiles);

            // FIX: Calculate the delay differently to ensure smoother distribution
            // Use a curve that slows down slightly toward the end rather than linear distribution
            const totalTiles = tiles.length;
            const maxDelay = transitionDuration * 0.95; // Reserve a small buffer at the end

            tiles.forEach((tile, index) => {
                // Use a non-linear scaling to make the last tiles flip more smoothly
                // This creates a slight ease-out effect for the final tiles
                const progress = index / totalTiles;
                const easedProgress = progress < 0.8 ?
                    progress :
                    0.8 + (progress - 0.8) * 0.7; // Slow down the last 20% of tiles

                const delay = easedProgress * maxDelay;

                setTimeout(() => {
                    tile.style.transform = 'rotateY(180deg)';
                }, delay);
            });

            // Clear any existing transition timeout
            if (currentTransitionTimeout) {
                clearTimeout(currentTransitionTimeout);
            }

            // After transition completes, display single seamless poster
            currentTransitionTimeout = setTimeout(() => {
                debug(`Transition animation complete, updating display to show item ${toIndex}`);
                currentIndex = toIndex;

                // Clear all tiles
                posterContainer.innerHTML = '';

                // Create single seamless poster
                const poster = document.createElement('div');
                poster.style.width = '100%';
                poster.style.height = '100%';
                poster.style.backgroundImage = `url(${toPosterUrl})`;
                poster.style.backgroundSize = 'cover';
                poster.style.backgroundPosition = 'center';
                poster.style.position = 'absolute';
                posterContainer.appendChild(poster);

                // First add streaming badge if applicable
                if (isStreaming) {
                    debug('Adding streaming badge after transition');
                    const badge = document.createElement('div');
                    badge.className = 'streaming-badge';
                    badge.textContent = 'Currently Streaming';
                    posterContainer.appendChild(badge);
                }

                // Then add stream info if applicable
                if (isStreaming && items[currentIndex]) {
                    debug('Adding stream info for ' + (items[currentIndex].title || 'Unknown'));
                    addStreamInfo(items[currentIndex]);
                }

                // IMPORTANT: Reset transitioning flag
                debug('Resetting transitioning flag to false');
                transitioning = false;

                // Clear the transition timeout reference
                currentTransitionTimeout = null;

                // After a transition completes, check if we had a pending stream state change
                // that was waiting for this transition to complete
                setTimeout(() => {
                    if (!streamDetectionLock) {
                        debug('Checking streams after transition completed');
                        checkForStreams();
                    }
                }, 500); // Small delay to ensure display is updated first

                // CRUCIAL: If we have multiple streams, make sure the timer is running
                if (multipleStreams && !timerInterval) {
                    debug("CRITICAL: Transition completed but timer not running with multiple streams!");
                    startTransitionTimer();
                }
            }, transitionDuration + 100);
        }

        // Utility function to shuffle array
        function shuffleArray(array) {
            for (let i = array.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [array[i], array[j]] = [array[j], array[i]];
            }
            return array;
        }

        // Clean up when page unloads
        window.addEventListener('beforeunload', () => {
            clearInterval(timerInterval);
            clearInterval(streamCheckTimer);
            clearInterval(randomRefreshTimer); // Add this line
            if (currentTransitionTimeout) {
                clearTimeout(currentTransitionTimeout);
            }
        });

        // Initialize when page loads
        window.onload = init;
    </script>
</body>

</html>