<?php
// Include configuration
require_once '../include/config.php';

// Get Plex token from config
$plex_token = $plex_config['token'];

// Function to fetch an image from Plex
function fetchPlexImage($url, $token)
{
    if (empty($url)) {
        return false;
    }

    // Check if it's a relative URL and add base URL if needed
    if (strpos($url, 'http') !== 0) {
        // Get Plex server URL from config
        global $plex_config;
        $plex_url = $plex_config['server_url'];
        $full_url = $plex_url . $url;
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

// Main proxy logic
if (isset($_GET['path'])) {
    $image_path = base64_decode($_GET['path']);
    $image = fetchPlexImage($image_path, $plex_token);

    if ($image) {
        // Set caching headers
        header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
        header('Content-Type: ' . $image['content_type']);
        echo $image['data'];
        exit;
    } else {
        header('HTTP/1.1 404 Not Found');
        exit;
    }
} else {
    header('HTTP/1.1 400 Bad Request');
    exit;
}
?>