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
$season = isset($_POST['season']) ? intval($_POST['season']) : null;

function generateClientInfoHeader() {
    $payload = [
        'name' => 'Posteria',         
        'ts' => round(microtime(true) * 1000), 
        'v' => '1.0',                 
        'platform' => 'php'           
    ];
    
    // Convert to JSON and encode as Base64
    return base64_encode(json_encode($payload));
}

$apiUrl = 'https://posteria.app/api/tmdb/fetch/?';
if ($type === 'movie') {
    $apiUrl .= 'movie=' . urlencode($query);
} elseif ($type === 'tv') {
    $apiUrl .= 'q=' . urlencode($query) . '&type=tv';
    
    // Add season parameter if provided
    if ($season !== null) {
        $apiUrl .= '&season=' . $season;
    }
} elseif ($type === 'season') {
    // Handle TV season directly
    // Extract show title and season number from the query if in format "Show Name - Season X"
    if (preg_match('/^(.+?)(?:\s*[-:]\s*Season\s*(\d+))?$/i', $query, $matches)) {
        $showTitle = trim($matches[1]);
        $seasonNumber = isset($matches[2]) ? intval($matches[2]) : 1; // Default to season 1 if not specified
        
        // If season was explicitly provided in POST, use that instead
        if ($season !== null) {
            $seasonNumber = $season;
        }
        
        $apiUrl .= 'q=' . urlencode($showTitle) . '&type=tv&season=' . $seasonNumber;
    } else {
        // If we can't parse the format, just use what we have
        $apiUrl .= 'q=' . urlencode($query) . '&type=tv&season=1';
    }
} elseif ($type === 'collection') {
    // Handle collections
    $apiUrl .= 'q=' . urlencode($query) . '&type=collection';
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid type parameter'
    ]);
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT => 'Posteria/1.0',
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Client-Info: ' . generateClientInfoHeader()
    ]
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

if ($httpCode !== 200) {
    echo json_encode([
        'success' => false,
        'error' => 'API returned error code: ' . $httpCode
    ]);
    exit;
}

$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON response: ' . json_last_error_msg()
    ]);
    exit;
}

if (isset($data['success']) && $data['success'] === false) {
    echo json_encode([
        'success' => false,
        'error' => $data['error'] ?? 'Unknown API error'
    ]);
    exit;
}

if (empty($data['results']) || !is_array($data['results'])) {
    echo json_encode([
        'success' => false,
        'error' => 'No results found'
    ]);
    exit;
}

$result = $data['results'][0];

$title = '';
$posterUrl = null;
$seasonNumber = null;

if ($type === 'movie') {
    // Movie handling
    $title = $result['title'] ?? 'Unknown Movie';
    if (!empty($result['release_date'])) {
        $year = substr($result['release_date'], 0, 4);
        $title .= " ($year)";
    }
    
    // Get movie poster
    if (!empty($result['poster']) && is_array($result['poster'])) {
        $posterUrl = $result['poster']['original'] ?? $result['poster']['large'] ?? $result['poster']['medium'] ?? $result['poster']['small'] ?? null;
    }
} elseif ($type === 'tv') {
    // TV show handling
    $title = $result['title'] ?? 'Unknown TV Show';
    if (!empty($result['first_air_date'])) {
        $year = substr($result['first_air_date'], 0, 4);
        $title .= " ($year)";
    }
    
    // Get TV show poster
    if (!empty($result['poster']) && is_array($result['poster'])) {
        $posterUrl = $result['poster']['original'] ?? $result['poster']['large'] ?? $result['poster']['medium'] ?? $result['poster']['small'] ?? null;
    }
} elseif ($type === 'season') {
    // Season handling
    $title = $result['title'] ?? 'Unknown TV Show';
    if (!empty($result['first_air_date'])) {
        $year = substr($result['first_air_date'], 0, 4);
        $title .= " ($year)";
    }
    
    // Check if there's a season object with poster
    if (!empty($result['season']) && is_array($result['season'])) {
        $seasonNumber = $result['season']['season_number'] ?? null;
        $seasonName = $result['season']['name'] ?? "Season $seasonNumber";
        
        // Add season information to the title
        $title .= " - $seasonName";
        
        // Get season poster from the season object
        if (!empty($result['season']['poster']) && is_array($result['season']['poster'])) {
            $posterUrl = $result['season']['poster']['original'] ?? 
                         $result['season']['poster']['large'] ?? 
                         $result['season']['poster']['medium'] ?? 
                         $result['season']['poster']['small'] ?? null;
        }
    } else {
        // Fallback to show poster if season poster not available
        if (!empty($result['poster']) && is_array($result['poster'])) {
            $posterUrl = $result['poster']['original'] ?? $result['poster']['large'] ?? $result['poster']['medium'] ?? $result['poster']['small'] ?? null;
        }
    }
} elseif ($type === 'collection') {
    // Collection handling
    $title = $result['name'] ?? 'Unknown Collection';
    
    // Get collection poster
    if (!empty($result['poster']) && is_array($result['poster'])) {
        $posterUrl = $result['poster']['original'] ?? $result['poster']['large'] ?? $result['poster']['medium'] ?? $result['poster']['small'] ?? null;
    }
}

if (empty($posterUrl)) {
    echo json_encode([
        'success' => false,
        'error' => 'No poster found for this title'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'posterUrl' => $posterUrl,
    'title' => $title,
    'mediaType' => $type,
    'seasonNumber' => $seasonNumber,
    'requested_season' => $season
]);

?>
