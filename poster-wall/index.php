<?php
// Include configuration
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
    <title>Plex Poster Display</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
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
            background: #cc7b19;
        }

        .streaming-badge {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            background: rgba(204, 123, 25, 0.9);
            color: white;
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

        // Debug helper
        function debug(message) {
            console.log(`[Debug] ${message}`);
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

            // Get IDs for current streams (using viewOffset as unique identifier)
            const newStreamIds = activeStreams.map(stream =>
                `${stream.title}-${stream.user}-${stream.viewOffset}`);

            // Check if the stream state has changed
            const streamsChanged = (
                activeStreams.length !== streamIds.length ||
                !newStreamIds.every(id => streamIds.includes(id))
            );

            if (streamsChanged) {
                debug('Stream state has changed, updating display');

                // Save new stream IDs
                streamIds = newStreamIds;

                // Update streaming state
                const wasStreaming = isStreaming;
                isStreaming = activeStreams.length > 0;

                // Handle transition between states
                if (!wasStreaming && isStreaming) {
                    // Transition from random posters to streaming
                    debug('Transitioning from random posters to streaming');
                    items = activeStreams;
                    currentIndex = 0;
                    stopTransitionTimer();
                    updateDisplay();

                    // If multiple streams, start transitions between them
                    if (activeStreams.length > 1) {
                        startTransitionTimer();
                    }
                } else if (wasStreaming && !isStreaming) {
                    // Transition from streaming to random posters
                    debug('Transitioning from streaming to random posters');
                    items = randomItems.length > 0 ? randomItems : [];
                    currentIndex = 0;
                    updateDisplay();
                    startTransitionTimer();
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

                    // Manage transition timer based on number of streams
                    stopTransitionTimer();
                    if (activeStreams.length > 1) {
                        startTransitionTimer();
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

                    // CRITICAL: Make sure transition timer is running if we have multiple streams
                    if (activeStreams.length > 1) {
                        // Check if timer is already running
                        if (!timerInterval) {
                            debug('Multiple streams detected but timer not running - starting transition timer');
                            startTransitionTimer();
                        } else {
                            debug('Multiple streams, timer already running');
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

                    // If it's been more than 10 seconds since last transition started,
                    // something is wrong and we should reset the flag
                    if (timeSinceLastTransition > 10000) {
                        debug('GLOBAL SAFETY: Transitioning flag stuck for too long, resetting');
                        transitioning = false;
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
            }, 5000);
        }

        // Stop transition timer
        function stopTransitionTimer() {
            clearInterval(timerInterval);
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
            const posterUrl = currentItem && currentItem.thumb ?
                getImageUrl(currentItem.thumb) : '';

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

        // Helper function to perform tile transition between any two items
        function doTileTransition(fromIndex, toIndex) {
            if (transitioning) {
                debug('Already transitioning, cannot start another transition');
                return;
            }

            debug(`Starting tile transition from index ${fromIndex} to index ${toIndex}`);
            transitioning = true;

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
            const fromPosterUrl = fromItem && fromItem.thumb ?
                getImageUrl(fromItem.thumb) : '';
            const toPosterUrl = toItem && toItem.thumb ?
                getImageUrl(toItem.thumb) : '';

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
            let delay = 0;
            const delayIncrement = transitionDuration / tiles.length;

            tiles.forEach(tile => {
                setTimeout(() => {
                    tile.style.transform = 'rotateY(180deg)';
                }, delay);
                delay += delayIncrement;
            });

            // After transition completes, display single seamless poster
            const completionTimeout = setTimeout(() => {
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

                // Clear the safety timeout - only if it exists in this scope
                try {
                    if (typeof safetyTimeout !== 'undefined') {
                        clearTimeout(safetyTimeout);
                    }
                } catch (e) {
                    debug('Note: Could not clear safety timeout: ' + e.message);
                }

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
        });

        // Initialize when page loads
        window.onload = init;
    </script>
</body>

</html>