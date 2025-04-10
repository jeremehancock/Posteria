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
            batchSize: 10, // Number of posters to load at once
            preloadThreshold: 8 // Load new posters when reaching this index
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
            loadingNewBatch: false,
            pendingBatch: null
        };

        // URLs
        const urls = {
            proxy: '<?php echo $proxy_url; ?>',
            checkStreams: './check-streams.php'
        };

        // DOM elements
        const background = document.getElementById('background');
        const posterContainer = document.getElementById('poster-container');

        // Initialize the app
        function initialize() {
            console.log('Initializing Poster Wall');

            // Check if we have content to display
            if (!state.items || state.items.length === 0) {
                showError('No content available. Please check your server connection and refresh the page.');
                return;
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
            if (state.displayTimer) {
                clearInterval(state.displayTimer);
            }

            // Start new timer
            state.displayTimer = setInterval(nextPoster, config.displayDuration);
        }

        // Stop display timer
        function stopDisplayTimer() {
            if (state.displayTimer) {
                clearInterval(state.displayTimer);
                state.displayTimer = null;
            }
        }

        // Start stream checking
        function startStreamChecking() {
            // Check immediately on start
            checkForStreams();

            // Clear existing timer
            if (state.streamCheckTimer) {
                clearInterval(state.streamCheckTimer);
            }

            // Start new timer
            state.streamCheckTimer = setInterval(checkForStreams, config.streamCheckInterval);
        }

        // Check for streams
        function checkForStreams() {
            console.log('Checking for active streams...');

            // Skip if we're in the middle of a transition
            if (state.transitioning) {
                console.log('Skipping stream check during transition');
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
                    const activeStreams = data.active_streams || [];
                    const randomItems = data.random_items || [];

                    const wasStreaming = state.isStreaming;
                    const hadMultipleStreams = state.hasMultipleStreams;

                    // Check current state
                    const isStreaming = activeStreams.length > 0;
                    const hasMultipleStreams = activeStreams.length > 1;

                    // Handle transitions between different states

                    // Case 1: Switching from no streams to streaming
                    if (!wasStreaming && isStreaming) {
                        console.log(`Switching to streaming mode. Streams: ${activeStreams.length}`);

                        // Update state
                        state.isStreaming = true;
                        state.hasMultipleStreams = hasMultipleStreams;
                        state.items = activeStreams;

                        // For a single stream, just display it - no transition timer
                        if (!hasMultipleStreams) {
                            state.currentIndex = 0;
                            stopDisplayTimer();
                            updateDisplay();
                        } else {
                            // For multiple streams, transition between them
                            state.currentIndex = 0;
                            startDisplayTimer();
                            updateDisplay();
                        }
                    }
                    // Case 2: Switching from streaming to no streams
                    else if (wasStreaming && !isStreaming) {
                        console.log('Switching back to random posters');

                        // Update state
                        state.isStreaming = false;
                        state.hasMultipleStreams = false;
                        state.items = randomItems.length > 0 ? randomItems : [];
                        state.currentIndex = 0;

                        // Always transition with random posters
                        startDisplayTimer();
                        updateDisplay();
                    }
                    // Case 3: Staying in streaming mode but stream count changed
                    else if (wasStreaming && isStreaming) {
                        console.log(`Stream count: ${activeStreams.length}`);

                        // Check if stream list has changed
                        const streamsChanged = JSON.stringify(state.items) !== JSON.stringify(activeStreams);

                        if (streamsChanged) {
                            console.log('Stream list changed, updating');
                            state.items = activeStreams;

                            // If changing from multiple to single stream
                            if (hadMultipleStreams && !hasMultipleStreams) {
                                console.log('Switching from multiple streams to single stream');
                                state.hasMultipleStreams = false;
                                stopDisplayTimer();
                                state.currentIndex = 0;
                                updateDisplay();
                            }
                            // If changing from single to multiple streams
                            else if (!hadMultipleStreams && hasMultipleStreams) {
                                console.log('Switching from single stream to multiple streams');
                                state.hasMultipleStreams = true;
                                state.currentIndex = 0;
                                startDisplayTimer();
                                updateDisplay();
                            }
                            // If staying with multiple streams but the list changed
                            else if (hasMultipleStreams) {
                                state.hasMultipleStreams = true;
                                // Keep current index if valid, otherwise reset
                                if (state.currentIndex >= activeStreams.length) {
                                    state.currentIndex = 0;
                                }
                                updateDisplay();
                                startDisplayTimer(); // Restart timer to ensure transitions
                            }
                            // If staying with single stream but the stream changed
                            else {
                                state.currentIndex = 0;
                                updateDisplay();
                            }
                        } else {
                            // Same streams, just update progress if streaming
                            if (isStreaming) {
                                state.items = activeStreams; // Update with new progress data
                                updateStreamInfo(state.items[state.currentIndex]);
                            }
                        }
                    }
                    // Case 4: Store random items for later use
                    else if (!state.isStreaming && randomItems.length > 0 && state.pendingBatch === null) {
                        // Store random items for next batch
                        state.pendingBatch = randomItems;
                    }
                })
                .catch(error => {
                    console.error('Error checking for streams:', error);
                });
        }

        // Load more random posters
        function loadMorePosters() {
            console.log('Loading more posters...');

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
                    const randomItems = data.random_items || [];
                    const activeStreams = data.active_streams || [];

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
            console.log(`Processing new batch of ${newBatch.length} posters`);

            // Save current item for transition
            const currentItem = state.items[state.currentIndex];

            // Create transition items array with just what we need for the transition
            const transitionItems = [currentItem, newBatch[0]];

            // Update the items array
            state.items = newBatch;

            // Trigger the transition
            transition(0, 1, transitionItems);

            // Reset the index to start from the beginning of the new batch
            state.currentIndex = 0;
        }

        // Move to next poster
        function nextPoster() {
            if (state.transitioning || state.loadingNewBatch) {
                return;
            }

            // Skip transitions if we're showing a single stream
            if (state.isStreaming && !state.hasMultipleStreams) {
                console.log('Single stream mode - no transitions');
                return;
            }

            // Check if we need to load more posters
            if (!state.isStreaming && state.currentIndex >= config.preloadThreshold && !state.loadingNewBatch) {
                loadMorePosters();
                return;
            }

            // Calculate next index
            const nextIndex = (state.currentIndex + 1) % state.items.length;

            // Transition to next poster
            transition(state.currentIndex, nextIndex);

            // Update current index
            state.currentIndex = nextIndex;
        }

        // Update display with current item
        function updateDisplay() {
            if (!state.items || state.items.length === 0 || state.transitioning) {
                return;
            }

            // Get current item
            const currentItem = state.items[state.currentIndex];
            if (!currentItem) {
                return;
            }

            console.log(`Updating display to show: ${currentItem.title || 'Unknown'}`);

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
        function transition(fromIndex, toIndex, customItems) {
            if (state.transitioning) {
                return;
            }

            state.transitioning = true;

            // Use custom items array if provided, otherwise use state.items
            const items = customItems || state.items;

            console.log(`Transitioning from ${fromIndex} to ${toIndex} (Custom items: ${customItems ? 'Yes' : 'No'})`);

            // Get items for transition
            const fromItem = items[fromIndex];
            const toItem = items[toIndex];

            if (!fromItem || !toItem) {
                state.transitioning = false;
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
                state.transitioning = false;
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
            if (state.isStreaming) {
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

            tiles.forEach((tile, index) => {
                // Create a slight ease-out effect
                const progress = index / totalTiles;
                const easedProgress = progress < 0.8 ?
                    progress :
                    0.8 + (progress - 0.8) * 0.7;

                const delay = easedProgress * maxDelay;

                setTimeout(() => {
                    tile.style.transform = 'rotateY(180deg)';
                }, delay);
            });

            // After transition completes, display full poster
            setTimeout(() => {
                // Reset transitioning flag
                state.transitioning = false;

                // Update display with new poster
                updateDisplay();

                // Restart display timer only if needed (multiple streams or not streaming)
                if (!state.isStreaming || (state.isStreaming && state.hasMultipleStreams)) {
                    startDisplayTimer();
                }
            }, config.transitionDuration + 100);
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

        // Clean up when page unloads
        window.addEventListener('beforeunload', () => {
            if (state.displayTimer) {
                clearInterval(state.displayTimer);
            }
            if (state.streamCheckTimer) {
                clearInterval(state.streamCheckTimer);
            }
        });

        // Initialize when page loads
        window.onload = initialize;
    </script>
</body>

</html>