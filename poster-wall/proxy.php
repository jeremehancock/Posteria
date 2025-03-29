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

// Get Plex token from config
$plex_token = $plex_config['token'];
$plex_url = $plex_config['server_url'];

// Function to fetch an image from Plex
function fetchPlexImage($url, $token, $base_url)
{
    if (empty($url)) {
        error_log("Proxy: Empty URL provided");
        return false;
    }

    // Check if it's a relative URL and add base URL if needed
    if (strpos($url, 'http') !== 0) {
        $full_url = $base_url . $url;
    } else {
        $full_url = $url;
    }

    // Ensure the URL has a token
    if (strpos($full_url, 'X-Plex-Token=') === false) {
        $full_url .= (strpos($full_url, '?') === false ? '?' : '&') . 'X-Plex-Token=' . $token;
    }

    error_log("Proxy: Fetching image from: {$full_url}");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $full_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        error_log("Proxy: cURL error when fetching image: " . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    if (!$response || $http_code >= 400) {
        error_log("Proxy: Error response when fetching image, HTTP code: " . $http_code);
        return false;
    }

    return [
        'data' => $response,
        'content_type' => $content_type
    ];
}

// Display a fallback image if no path is provided
if (!isset($_GET['path']) || empty($_GET['path'])) {
    // Serve a placeholder image instead of an error
    header('Content-Type: image/png');
    // Simple 1x1 transparent PNG
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}

// Main proxy logic
try {
    $image_path = base64_decode($_GET['path']);
    if ($image_path === false) {
        error_log("Proxy: Failed to decode base64 path: " . $_GET['path']);
        throw new Exception("Invalid path encoding");
    }

    $image = fetchPlexImage($image_path, $plex_token, $plex_url);

    if ($image) {
        // Set caching headers
        header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
        header('Content-Type: ' . $image['content_type']);
        echo $image['data'];
        exit;
    } else {
        throw new Exception("Failed to fetch image");
    }
} catch (Exception $e) {
    error_log("Proxy error: " . $e->getMessage());
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: image/png');
    // Simple 1x1 transparent PNG
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}
?>