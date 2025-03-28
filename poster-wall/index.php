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
        const displayDuration = 30000; // 30 seconds
        const transitionDuration = 3000; // 3 seconds
        const streamCheckInterval = 15000; // Check for streams every 15 seconds

        // Current state
        let currentIndex = 0;
        let transitioning = false;
        let timerInterval;
        let streamCheckTimer;
        let items = initialItems;
        let isStreaming = isInitialStreaming;
        let streamIds = []; // Keep track of current stream IDs
        let randomItems = []; // Store random items for fallback

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

            // Then check periodically
            streamCheckTimer = setInterval(checkForStreams, streamCheckInterval);
        }

        // Function to check for active streams via AJAX
        function checkForStreams() {
            debug('Checking for active streams...');

            fetch(checkStreamsUrl)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    handleStreamUpdate(data);
                })
                .catch(error => {
                    debug(`Error checking streams: ${error.message}`);
                });
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
                }
            }
        }

        // Initialize the display
        function init() {
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

            // Set initial poster
            updateDisplay();

            // Start timer for transitions if we have more than one item and are streaming
            // or if we're not streaming (random posters)
            if ((isStreaming && items.length > 1) || !isStreaming) {
                startTransitionTimer();
            }

            // Start periodic stream checking
            startStreamChecking();

            // Handle window resize
            window.addEventListener('resize', adjustPosterSize);
            adjustPosterSize();
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
            if (!items || items.length === 0 || currentIndex >= items.length) {
                debug('Invalid items or index');
                return;
            }

            const currentItem = items[currentIndex];
            if (!currentItem) {
                debug('Current item is undefined');
                return;
            }

            // Update background
            const artUrl = currentItem.art || currentItem.thumb ?
                getImageUrl(currentItem.art || currentItem.thumb) : '';
            if (artUrl) {
                background.style.backgroundImage = `url(${artUrl})`;
            }

            // Clear existing content
            posterContainer.innerHTML = '';

            // Create single seamless poster
            const posterUrl = currentItem && currentItem.thumb ?
                getImageUrl(currentItem.thumb) : '';

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

            // Add stream info if applicable
            if (isStreaming) {
                addStreamInfo(items[currentIndex]);
            }

            // Create streaming badge if applicable
            if (isStreaming) {
                const badge = document.createElement('div');
                badge.className = 'streaming-badge';
                badge.textContent = 'Currently Streaming';
                posterContainer.appendChild(badge);
            }
        }

        // Add streaming information display
        function addStreamInfo(stream) {
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

        // Start transition timer
        function startTransitionTimer() {
            clearInterval(timerInterval);
            timerInterval = setInterval(transition, displayDuration);
        }

        // Handle transition between posters
        function transition() {
            if (transitioning) return;
            transitioning = true;

            // Prepare next item
            const nextIndex = (currentIndex + 1) % items.length;
            const nextItem = items[nextIndex];
            const nextPosterUrl = nextItem && nextItem.thumb ?
                getImageUrl(nextItem.thumb) : '';

            if (!nextPosterUrl) {
                transitioning = false;
                return;
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

                    // Current poster image
                    const currentPosterUrl = items[currentIndex] && items[currentIndex].thumb ?
                        getImageUrl(items[currentIndex].thumb) : '';

                    tile.style.backgroundImage = `url(${currentPosterUrl})`;
                    tile.style.backgroundPosition = `-${col * tileWidth}px -${row * tileHeight}px`;
                    tile.style.backgroundSize = `${containerWidth}px ${containerHeight}px`;

                    // Add back face (next poster)
                    const tileBack = document.createElement('div');
                    tileBack.className = 'tile-back';
                    tileBack.style.backgroundImage = `url(${nextPosterUrl})`;
                    tileBack.style.backgroundPosition = `-${col * tileWidth}px -${row * tileHeight}px`;
                    tileBack.style.backgroundSize = `${containerWidth}px ${containerHeight}px`;

                    tile.appendChild(tileBack);
                    posterContainer.appendChild(tile);
                    tiles.push(tile);
                }
            }

            // Add streaming info if applicable
            if (isStreaming) {
                addStreamInfo(items[currentIndex]);
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
            setTimeout(() => {
                currentIndex = nextIndex;

                // Clear all tiles
                posterContainer.innerHTML = '';

                // Create single seamless poster
                const poster = document.createElement('div');
                poster.style.width = '100%';
                poster.style.height = '100%';
                poster.style.backgroundImage = `url(${nextPosterUrl})`;
                poster.style.backgroundSize = 'cover';
                poster.style.backgroundPosition = 'center';
                poster.style.position = 'absolute';
                posterContainer.appendChild(poster);

                // Add stream info if applicable
                if (isStreaming) {
                    addStreamInfo(items[currentIndex]);
                }

                // Create streaming badge if applicable
                if (isStreaming) {
                    const badge = document.createElement('div');
                    badge.className = 'streaming-badge';
                    badge.textContent = 'Currently Streaming';
                    posterContainer.appendChild(badge);
                }

                transitioning = false;
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