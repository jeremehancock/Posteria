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

// Simple API fetch handler for the TMDB tab in Posteria

// Set content type to JSON
header('Content-Type: application/json');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Check for required POST parameters
if (empty($_POST['query']) || empty($_POST['type'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameters'
    ]);
    exit;
}

// Get the query and type from POST
$query = trim($_POST['query']);
$type = trim($_POST['type']);
$apiKey = 'medulla'; // API key for TMDB

// Build the API URL based on type
$apiUrl = 'https://jereme.dev/test/?';
if ($type === 'movie') {
    $apiUrl .= 'movie=' . urlencode($query) . '&key=' . $apiKey;
} elseif ($type === 'tv') {
    $apiUrl .= 'q=' . urlencode($query) . '&type=tv&key=' . $apiKey;
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid type parameter'
    ]);
    exit;
}

// Make the API request
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT => 'Posteria/1.0',
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Handle request errors
if ($response === false) {
    echo json_encode([
        'success' => false,
        'error' => 'API request failed: ' . $error
    ]);
    exit;
}

// Check HTTP status code
if ($httpCode !== 200) {
    echo json_encode([
        'success' => false,
        'error' => 'API returned error code: ' . $httpCode
    ]);
    exit;
}

// Parse the JSON response
$data = json_decode($response, true);

// Validate the response
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON response: ' . json_last_error_msg()
    ]);
    exit;
}

// Check if the API returned an error
if (isset($data['success']) && $data['success'] === false) {
    echo json_encode([
        'success' => false,
        'error' => $data['error'] ?? 'Unknown API error'
    ]);
    exit;
}

// Check if we have results
if (empty($data['results']) || !is_array($data['results'])) {
    echo json_encode([
        'success' => false,
        'error' => 'No results found'
    ]);
    exit;
}

// Return the first result
$result = $data['results'][0];

// Extract the title
$title = '';
if ($type === 'movie') {
    $title = $result['title'] ?? 'Unknown Movie';
    if (!empty($result['release_date'])) {
        $year = substr($result['release_date'], 0, 4);
        $title .= " ($year)";
    }
} else {
    $title = $result['title'] ?? 'Unknown TV Show';
    if (!empty($result['first_air_date'])) {
        $year = substr($result['first_air_date'], 0, 4);
        $title .= " ($year)";
    }
}

// Extract the poster URL (get the largest available)
$posterUrl = null;
if (!empty($result['poster']) && is_array($result['poster'])) {
    $posterUrl = $result['poster']['original'] ?? $result['poster']['large'] ?? $result['poster']['medium'] ?? $result['poster']['small'] ?? null;
}

// If no poster found, return error
if (empty($posterUrl)) {
    echo json_encode([
        'success' => false,
        'error' => 'No poster found for this title'
    ]);
    exit;
}

// Return success with the poster URL and title
echo json_encode([
    'success' => true,
    'posterUrl' => $posterUrl,
    'title' => $title,
    'mediaType' => $type
]);

?>
