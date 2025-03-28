<?php
/**
 * Plex API Proxy
 * 
 * This script acts as a proxy between the client and the Plex server
 * to handle cross-origin requests and SSL certificate issues.
 */

// Get the URL from the query parameter
$url = isset($_GET['url']) ? $_GET['url'] : null;

// Validate URL
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Invalid URL parameter']);
    exit;
}

// Initialize cURL session
$ch = curl_init();

// Set cURL options
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,  // Ignore SSL certificate issues
    CURLOPT_SSL_VERIFYHOST => false,  // Ignore SSL certificate issues
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HEADER => false,
]);

// Get content type from Plex URL for images
if (strpos($url, 'thumb') !== false || strpos($url, 'art') !== false) {
    curl_setopt($ch, CURLOPT_HEADER, true);
}

// Execute cURL request
$response = curl_exec($ch);

// Check for errors
if (curl_errno($ch)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Curl error: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

// For image responses, parse headers and set appropriate content type
if (strpos($url, 'thumb') !== false || strpos($url, 'art') !== false) {
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);

    // Extract content type
    if (preg_match('/Content-Type: (.*?)(\r\n|\r|\n)/i', $header, $matches)) {
        $content_type = trim($matches[1]);
        header('Content-Type: ' . $content_type);
    } else {
        // Default to image/jpeg if no content type is found
        header('Content-Type: image/jpeg');
    }

    echo $body;
} else {
    // For API responses, pass through the content type
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    header('Content-Type: ' . $content_type);
    echo $response;
}

// Close cURL session
curl_close($ch);