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

// Simple API fetch handler for the TMDB and Fanart.tv button in Posteria

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

function generateClientInfoHeader()
{
    $payload = [
        'name' => 'Posteria',
        'ts' => round(microtime(true) * 1000),
        'v' => '1.0',
        'platform' => 'php'
    ];

    // Convert to JSON and encode as Base64
    return base64_encode(json_encode($payload));
}

// Function to normalize titles for comparison (strict matching)
function normalizeTitle($title)
{
    // Convert to lowercase
    $title = strtolower($title);

    // Remove common articles 
    $title = preg_replace('/^(the|a|an) /', '', $title);

    // Remove non-alphanumeric characters except spaces
    $title = preg_replace('/[^\p{L}\p{N}\s]/u', '', $title);

    // Replace multiple spaces with a single space
    $title = preg_replace('/\s+/', ' ', $title);

    return trim($title);
}

// MODIFIED: Strict title matching for collections allowing base title or base title + "Collection"
function isCollectionMatch($searchTitle, $resultTitle)
{
    // Normalize both titles
    $normalizedSearch = normalizeTitle(stripYear($searchTitle));
    $normalizedResult = normalizeTitle(stripYear($resultTitle));

    // Strip "Collection" from search term if it exists
    $baseSearchTitle = stripCollectionWord($normalizedSearch);

    // Match only if the result is exactly the base title OR base title + "Collection"
    return (
        $normalizedResult === $baseSearchTitle ||
        $normalizedResult === ($baseSearchTitle . ' collection')
    );
}

// Function to check if titles are an exact match (strict)
function isTitleExactMatch($title1, $title2)
{
    // Normalize both titles
    $normalizedTitle1 = normalizeTitle($title1);
    $normalizedTitle2 = normalizeTitle($title2);

    // Return true only if normalized titles match exactly
    return ($normalizedTitle1 === $normalizedTitle2);
}

// NEW: Function to handle Star Wars movie titles
// IMPROVED: Function to handle Star Wars movie and TV show titles with better edge case handling
function handleStarWarsTitle($title, &$originalTitle = null)
{
    // Store the original title for alternate matching
    $originalTitle = $title;

    // Special case for the Holiday Special - keep exact title
    if (preg_match('/star\s+wars\s+holiday\s+special/i', $title)) {
        return $title; // Return the exact title for precise matching
    }

    // Case 1: Original "Star Wars" (1977) - keep as "Star Wars" or "Star Wars: A New Hope"
    if (
        preg_match('/^star\s+wars$/i', $title) ||
        preg_match('/^star\s+wars:?\s*a\s+new\s+hope$/i', $title) ||
        preg_match('/^star\s+wars:?\s*episode\s+iv$/i', $title) ||
        preg_match('/^star\s+wars:?\s*episode\s+4$/i', $title)
    ) {
        // For the original Star Wars, keep the title as is
        return $title;
    }

    // Case 2: Star Wars spinoffs like "Solo: A Star Wars Story"
    // We'll keep the full title but also remember the short title for flexible matching
    if (preg_match('/^(.*?):?\s*a\s+star\s+wars\s+story$/i', $title, $matches)) {
        if (!empty($matches[1])) {
            // Remember just the character/main title part (e.g., "Solo") for alternate matching
            $originalTitle = trim($matches[1]);
            // But return the full title for primary searching
            return $title;
        }
    }

    // Case 3: Star Wars TV series with specific names
    // For simple one-word shows like "Andor", keep the prefix to avoid confusion
    if (preg_match('/^star\s+wars:?\s*(andor|ahsoka|rebels|resistance|visions)$/i', $title, $matches)) {
        // For these shorter-titled shows, keep "Star Wars" in the name to avoid confusion
        return $title;
    }

    // For longer-named shows, we can use just the specific part
    if (preg_match('/^star\s+wars:?\s*(the\s+clone\s+wars|the\s+mandalorian|the\s+book\s+of\s+boba\s+fett|obi-wan\s+kenobi|the\s+bad\s+batch|the\s+acolyte)/i', $title, $matches)) {
        if (!empty($matches[1])) {
            // Return the specific TV show name without "Star Wars:" prefix
            return trim($matches[1]);
        }
    }

    // Case 4: Star Wars episodes with subtitles (e.g., "Star Wars Episode V - The Empire Strikes Back")
    if (preg_match('/star\s+wars(?:\s+episode\s+(?:[ivx]+|\d+))?(?:\s*[-:]\s*)?(.+)/i', $title, $matches)) {
        // If we have a subtitle after "Star Wars" or "Star Wars Episode X", use that instead
        if (!empty($matches[1])) {
            return trim($matches[1]);
        }
    }

    // If no special case matched or no subtitle found, return the original title
    return $title;
}

// Strip year from query for better searching
function stripYear($title)
{
    // Remove year in parentheses pattern: "Movie Title (2023)"
    $title = preg_replace('/\s*\(\d{4}\)\s*$/', '', $title);

    // Remove year in parentheses pattern if it's in the middle: "Movie Title (2023) Extra Info"
    $title = preg_replace('/\s*\(\d{4}\)\s*/', ' ', $title);

    // Remove just year pattern if it's at the end: "Movie Title 2023"
    $title = preg_replace('/\s+\d{4}\s*$/', '', $title);

    return trim($title);
}

// Strip the word "Collection" from the search query for collection searches
function stripCollectionWord($title)
{
    // Remove the word "Collection" at the end (with space)
    $title = preg_replace('/\s+Collection$/i', '', $title);

    // Remove " - Collection" at the end
    $title = preg_replace('/\s+-\s+Collection$/i', '', $title);

    // Remove "Collection" as a separate word anywhere in the string
    $title = preg_replace('/\bCollection\b/i', '', $title);

    // Replace multiple spaces with a single space
    $title = preg_replace('/\s+/', ' ', $title);

    return trim($title);
}

// Clean the search query - strip years before sending to API
$cleanQuery = stripYear($query);

// MODIFIED: For Star Wars movies, extract the subtitle but also remember original
$originalStarWarsTitle = null;
$cleanQuery = handleStarWarsTitle($cleanQuery, $originalStarWarsTitle);

// MODIFIED: For Star Wars movies, extract the subtitle for better searching
$cleanQuery = handleStarWarsTitle($cleanQuery);

// For collection searches, also strip the word "Collection"
if ($type === 'collection') {
    $cleanQuery = stripCollectionWord($cleanQuery);
}

// Store the original search title before sending to API
$originalSearchTitle = $query;

$apiUrl = 'https://posteria.app/api/fetch/posters?';
if ($type === 'movie') {
    $apiUrl .= 'movie=' . urlencode($cleanQuery);
} elseif ($type === 'tv') {
    $apiUrl .= 'q=' . urlencode($cleanQuery) . '&type=tv';

    // Add season parameter if provided
    if ($season !== null) {
        $apiUrl .= '&season=' . $season;
    }
} elseif ($type === 'season') {
    // Handle TV season directly
    // Extract show title and season number from the query if in format "Show Name - Season X"
    if (preg_match('/^(.+?)(?:\s*[-:]\s*Season\s*(\d+))?$/i', $cleanQuery, $matches)) {
        $showTitle = trim($matches[1]);
        $seasonNumber = isset($matches[2]) ? intval($matches[2]) : 1; // Default to season 1 if not specified

        // If season was explicitly provided in POST, use that instead
        if ($season !== null) {
            $seasonNumber = $season;
        }

        $apiUrl .= 'q=' . urlencode($showTitle) . '&type=tv&season=' . $seasonNumber;
    } else {
        // If we can't parse the format, just use what we have
        $apiUrl .= 'q=' . urlencode($cleanQuery) . '&type=tv&season=1';
    }
} elseif ($type === 'collection') {
    // Handle collections - note that we've already stripped "Collection" from the query
    $apiUrl .= 'q=' . urlencode($cleanQuery) . '&type=collection';
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid type parameter'
    ]);
    exit;
}

// MODIFIED: Add the original query as a parameter to help with result filtering later
$apiUrl .= '&original_query=' . urlencode($originalSearchTitle);

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

// Extract the search query for title filtering
$searchQuery = preg_replace('/\s*\([^)]*\)\s*/', '', $query); // Remove content in parentheses
$searchQuery = preg_replace('/\s*-\s*Season\s*\d+\s*/', '', $searchQuery); // Remove "- Season X" if present
$searchQuery = stripYear($searchQuery); // Strip years from search query
$searchQuery = trim($searchQuery);

// NEW: For Star Wars titles, we need both the original and the subtitle for filtering
$isStarWarsTitle = preg_match('/star\s+wars/i', $searchQuery);
$starWarsSubtitle = null;
if ($isStarWarsTitle) {
    $starWarsSubtitle = handleStarWarsTitle($searchQuery);
}

// For collection searches, also strip the word "Collection" from the comparison query
if ($type === 'collection') {
    $searchQuery = stripCollectionWord($searchQuery);
}

// Get the first result as the primary result 
$result = isset($data['results'][0]) ? $data['results'][0] : null;

$title = '';
$posterUrl = null;
$seasonNumber = null;
$allPosters = [];

// Process all results to get multiple posters for any type
$allResults = $data['results'];

// Extract appropriate information based on media type
if ($type === 'movie') {
    // Get base movie title without year for matching
    $baseTitle = $searchQuery;
    $matchedResults = [];

    // For movie processing:
    if ($isStarWarsTitle) {
        // Special case for the Holiday Special - exact match only
        if (preg_match('/star\s+wars\s+holiday\s+special/i', $searchQuery)) {
            foreach ($allResults as $movieResult) {
                $movieTitle = $movieResult['title'] ?? 'Unknown Movie';
                $movieCompareTitle = $movieTitle; // Store the plain title for comparison

                // Strip year from the movie title for comparison
                $movieCompareTitle = stripYear($movieCompareTitle);

                // Only match if it's explicitly the Holiday Special
                if (preg_match('/star\s+wars\s+holiday\s+special/i', $movieCompareTitle)) {
                    $matchedResults[] = $movieResult;
                }
            }
        }
        // For the original Star Wars (1977), look for exact matches only
        else if (preg_match('/^star\s+wars$/i', $searchQuery)) {
            foreach ($allResults as $movieResult) {
                $movieTitle = $movieResult['title'] ?? 'Unknown Movie';
                $movieCompareTitle = $movieTitle; // Store the plain title for comparison

                // Strip year from the movie title for comparison
                $movieCompareTitle = stripYear($movieCompareTitle);

                // Look for EXACT matches to "Star Wars" only, not containing matches
                if (
                    preg_match('/^star\s+wars$/i', $movieCompareTitle) ||
                    preg_match('/^star\s+wars:?\s*a\s+new\s+hope$/i', $movieCompareTitle) ||
                    preg_match('/^star\s+wars:?\s*episode\s+iv$/i', $movieCompareTitle) ||
                    preg_match('/^star\s+wars:?\s*episode\s+4$/i', $movieCompareTitle)
                ) {
                    $matchedResults[] = $movieResult;
                }
            }
        }
        // For spin-offs like "Solo: A Star Wars Story", need more flexible matching
        else if (preg_match('/a\s+star\s+wars\s+story$/i', $searchQuery) && $originalStarWarsTitle) {
            foreach ($allResults as $movieResult) {
                $movieTitle = $movieResult['title'] ?? 'Unknown Movie';
                $movieCompareTitle = $movieTitle; // Store the plain title for comparison

                // Strip year from the movie title for comparison
                $movieCompareTitle = stripYear($movieCompareTitle);

                // Try to match the FULL title first (e.g., "Solo: A Star Wars Story")
                if (stripos($movieCompareTitle, $searchQuery) !== false) {
                    $matchedResults[] = $movieResult;
                }
                // Also try matching movies with just the main part (e.g., "Solo")
                else if (stripos($movieCompareTitle, $originalStarWarsTitle) !== false) {
                    $matchedResults[] = $movieResult;
                }
            }
        }
        // For other Star Wars movies (episodes with subtitles), match on the subtitle
        else {
            foreach ($allResults as $movieResult) {
                $movieTitle = $movieResult['title'] ?? 'Unknown Movie';
                $movieCompareTitle = $movieTitle; // Store the plain title for comparison

                // Strip year from the movie title for comparison
                $movieCompareTitle = stripYear($movieCompareTitle);

                // Try matching the cleaned query (subtitle or full title)
                if (stripos($movieCompareTitle, $cleanQuery) !== false) {
                    $matchedResults[] = $movieResult;
                }
                // Also try the original search query if it's different
                else if ($searchQuery !== $cleanQuery && stripos($movieCompareTitle, $searchQuery) !== false) {
                    $matchedResults[] = $movieResult;
                }
            }
        }
    }

    // If we didn't find any matches with the full title and this is a Star Wars movie,
    // try matching with just the subtitle (e.g., "The Empire Strikes Back")
    if ($isStarWarsTitle && empty($matchedResults) && $starWarsSubtitle) {
        foreach ($allResults as $movieResult) {
            $movieTitle = $movieResult['title'] ?? 'Unknown Movie';
            $movieCompareTitle = $movieTitle; // Store the plain title for comparison

            // Strip year from the movie title for comparison
            $movieCompareTitle = stripYear($movieCompareTitle);

            // Check for subtitle match (exact or contained within)
            if (stripos($movieCompareTitle, $starWarsSubtitle) !== false) {
                $matchedResults[] = $movieResult;
            }
        }
    }

    // If still no matches or not a Star Wars movie, use the original exact matching logic
    if (empty($matchedResults)) {
        foreach ($allResults as $movieResult) {
            $movieTitle = $movieResult['title'] ?? 'Unknown Movie';
            $movieCompareTitle = $movieTitle; // Store the plain title for comparison

            // Strip year from the movie title for comparison
            $movieCompareTitle = stripYear($movieCompareTitle);

            // Filter out results that don't match the search query exactly for movies
            if (!isTitleExactMatch($baseTitle, $movieCompareTitle)) {
                continue; // Skip this result if titles don't match
            }

            $matchedResults[] = $movieResult;
        }
    }

    // If we found matches, use them; otherwise fall back to all results
    $resultsToProcess = !empty($matchedResults) ? $matchedResults : $allResults;

    // Process the selected results
    foreach ($resultsToProcess as $movieResult) {
        $movieTitle = $movieResult['title'] ?? 'Unknown Movie';

        // Add year if available (for display only)
        if (!empty($movieResult['release_date'])) {
            $year = substr($movieResult['release_date'], 0, 4);
            $movieTitle .= " ($year)";
        }

        // Get movie poster
        if (!empty($movieResult['poster']) && is_array($movieResult['poster'])) {
            $moviePosterUrl = $movieResult['poster']['original'] ??
                $movieResult['poster']['large'] ??
                $movieResult['poster']['medium'] ??
                $movieResult['poster']['small'] ?? null;

            if ($moviePosterUrl) {
                $allPosters[] = [
                    'url' => $moviePosterUrl,
                    'name' => $movieTitle
                ];
            }
        }
    }

    // Set the primary result info (first result, if no filtered results)
    // This is for backward compatibility
    if (!empty($matchedResults)) {
        $result = $matchedResults[0];
    }

    if ($result) {
        $title = $result['title'] ?? 'Unknown Movie';
        if (!empty($result['release_date'])) {
            $year = substr($result['release_date'], 0, 4);
            $title .= " ($year)";
        }

        // Get primary poster URL
        if (!empty($result['poster']) && is_array($result['poster'])) {
            $posterUrl = $result['poster']['original'] ??
                $result['poster']['large'] ??
                $result['poster']['medium'] ??
                $result['poster']['small'] ?? null;
        }
    }
} elseif ($type === 'tv') {
    // Get base TV show title without year for matching
    $baseTitle = $searchQuery;
    $matchedResults = [];

    // For TV show processing:
    if ($isStarWarsTitle) {
        // Special case for the Holiday Special - exact match only
        if (preg_match('/star\s+wars\s+holiday\s+special/i', $searchQuery)) {
            foreach ($allResults as $tvResult) {
                $tvTitle = $tvResult['name'] ?? $tvResult['title'] ?? 'Unknown TV Show';
                $tvCompareTitle = $tvTitle; // Store the plain title for comparison

                // Strip year from the TV title for comparison
                $tvCompareTitle = stripYear($tvCompareTitle);

                // Only match if it's explicitly the Holiday Special
                if (preg_match('/star\s+wars\s+holiday\s+special/i', $tvCompareTitle)) {
                    $matchedResults[] = $tvResult;
                }
            }
        } else {
            foreach ($allResults as $tvResult) {
                $tvTitle = $tvResult['name'] ?? $tvResult['title'] ?? 'Unknown TV Show';
                $tvCompareTitle = $tvTitle; // Store the plain title for comparison

                // Strip year from the TV title for comparison
                $tvCompareTitle = stripYear($tvCompareTitle);

                // Try to match the cleaned query first (may be full title or shortened version)
                if (stripos($tvCompareTitle, $cleanQuery) !== false) {
                    $matchedResults[] = $tvResult;
                }
                // For cases like Andor, also try matching with the "Star Wars" prefix
                else if (
                    stripos($tvCompareTitle, "Star Wars " . $cleanQuery) !== false ||
                    stripos($tvCompareTitle, "Star Wars: " . $cleanQuery) !== false
                ) {
                    $matchedResults[] = $tvResult;
                }
                // Also try the original search query if different
                else if ($searchQuery !== $cleanQuery && stripos($tvCompareTitle, $searchQuery) !== false) {
                    $matchedResults[] = $tvResult;
                }
            }
        }
    }

    // If no Star Wars matches or not a Star Wars title, use standard exact matching
    if (empty($matchedResults)) {
        foreach ($allResults as $tvResult) {
            $tvTitle = $tvResult['name'] ?? $tvResult['title'] ?? 'Unknown TV Show';
            $tvCompareTitle = $tvTitle; // Store the plain title for comparison

            // Strip year from the TV title for comparison
            $tvCompareTitle = stripYear($tvCompareTitle);

            // Filter out results that don't match the search query exactly for TV shows
            if (!isTitleExactMatch($baseTitle, $tvCompareTitle)) {
                continue; // Skip this result if titles don't match
            }

            $matchedResults[] = $tvResult;
        }
    }

    // Process each TV show result to build posters array
    $resultsToProcess = !empty($matchedResults) ? $matchedResults : $allResults;

    foreach ($resultsToProcess as $tvResult) {
        $tvTitle = $tvResult['name'] ?? $tvResult['title'] ?? 'Unknown TV Show';

        // Add year if available (for display only)
        if (!empty($tvResult['first_air_date'])) {
            $year = substr($tvResult['first_air_date'], 0, 4);
            $tvTitle .= " ($year)";
        }

        // Get TV show poster
        if (!empty($tvResult['poster']) && is_array($tvResult['poster'])) {
            $tvPosterUrl = $tvResult['poster']['original'] ??
                $tvResult['poster']['large'] ??
                $tvResult['poster']['medium'] ??
                $tvResult['poster']['small'] ?? null;

            if ($tvPosterUrl) {
                $allPosters[] = [
                    'url' => $tvPosterUrl,
                    'name' => $tvTitle
                ];
            }
        }
    }

    // Set the primary result info (first result, if no filtered results)
    // This is for backward compatibility
    if (!empty($matchedResults)) {
        $result = $matchedResults[0];
    }

    if ($result) {
        $title = $result['name'] ?? $result['title'] ?? 'Unknown TV Show';
        if (!empty($result['first_air_date'])) {
            $year = substr($result['first_air_date'], 0, 4);
            $title .= " ($year)";
        }

        // Get primary poster URL
        if (!empty($result['poster']) && is_array($result['poster'])) {
            $posterUrl = $result['poster']['original'] ??
                $result['poster']['large'] ??
                $result['poster']['medium'] ??
                $result['poster']['small'] ?? null;
        }
    }
} elseif ($type === 'season') {
    // For TV seasons, extract just the show title without season info for comparison
    $searchShowTitle = preg_replace('/\s*-\s*Season\s*\d+\s*/', '', $searchQuery);
    $searchShowTitle = stripYear($searchShowTitle);
    $searchShowTitle = trim($searchShowTitle);

    // The requested season number from parameters
    $requestedSeasonNumber = $season ?? 1;

    // Extract primary season info from first result
    if ($result) {
        $title = $result['name'] ?? $result['title'] ?? 'Unknown TV Show';
        if (!empty($result['first_air_date'])) {
            $year = substr($result['first_air_date'], 0, 4);
            $title .= " ($year)";
        }
    }

    // Process each show to get its season
    foreach ($allResults as $showResult) {
        $showTitle = $showResult['name'] ?? $showResult['title'] ?? 'Unknown TV Show';
        $showCompareTitle = $showTitle; // Store the plain title for comparison

        // Strip year from the show title for comparison
        $showCompareTitle = stripYear($showCompareTitle);

        // Filter out results that don't match the search query exactly for TV seasons
        if (!isTitleExactMatch($searchShowTitle, $showCompareTitle)) {
            continue; // Skip this result if titles don't match exactly
        }

        // Add year if available (for display only)
        if (!empty($showResult['first_air_date'])) {
            $year = substr($showResult['first_air_date'], 0, 4);
            $showTitle .= " ($year)";
        }

        // Check the source of the result for attribution in the response
        $source = $showResult['source'] ?? '';

        // Check if there's a season object with poster
        if (!empty($showResult['season']) && is_array($showResult['season'])) {
            $showSeasonNumber = $showResult['season']['season_number'] ?? $requestedSeasonNumber;

            // Only include if this is the season we're looking for
            if ($showSeasonNumber === $requestedSeasonNumber) {
                $seasonName = $showResult['season']['name'] ?? ($showSeasonNumber === 0 ? "Specials" : "Season $showSeasonNumber");

                // Add season information to the title
                $seasonTitle = "$showTitle - $seasonName";

                // Get season poster from the season object
                if (!empty($showResult['season']['poster']) && is_array($showResult['season']['poster'])) {
                    $seasonPosterUrl = $showResult['season']['poster']['original'] ??
                        $showResult['season']['poster']['large'] ??
                        $showResult['season']['poster']['medium'] ??
                        $showResult['season']['poster']['small'] ?? null;

                    $posterSource = $showResult['season']['poster_source'] ?? $source ?? '';

                    if ($seasonPosterUrl) {
                        $allPosters[] = [
                            'url' => $seasonPosterUrl,
                            'name' => $seasonTitle,
                            'season' => $showSeasonNumber,
                            'source' => $posterSource,
                            'isSeasonPoster' => true
                        ];
                    }
                }
            }
        }

        // Process TheTVDB season posters (which might have a different structure)
        if ($source === 'thetvdb' || strpos(($showResult['poster']['original'] ?? ''), 'thetvdb.com') !== false) {
            $posterUrl = $showResult['poster']['original'] ??
                $showResult['poster']['large'] ??
                $showResult['poster']['medium'] ??
                $showResult['poster']['small'] ?? null;

            // Check if URL contains indicators that it's a season poster
            // TheTVDB season posters often have URLs with "seasons/<show_id>-<season_number>" format
            if (
                $posterUrl && (
                    strpos($posterUrl, 'seasons/') !== false ||
                    strpos($posterUrl, '-' . $requestedSeasonNumber . '.') !== false ||
                    strpos($posterUrl, '/season/') !== false ||
                    strpos($posterUrl, '/seasons/') !== false
                )
            ) {
                $seasonName = $requestedSeasonNumber === 0 ? "Specials" : "Season $requestedSeasonNumber";
                $seasonTitle = "$showTitle - $seasonName (TVDB)";

                $allPosters[] = [
                    'url' => $posterUrl,
                    'name' => $seasonTitle,
                    'season' => $requestedSeasonNumber,
                    'source' => 'thetvdb',
                    'isSeasonPoster' => true
                ];
            }
        }

        // Process Fanart.tv season posters (if they're structured differently)
        if ($source === 'fanart.tv' || strpos(($showResult['poster']['original'] ?? ''), 'fanart.tv') !== false) {
            // Only include Fanart.tv season posters that are explicitly marked as season posters
            // Look for season identifiers in URL
            $posterUrl = $showResult['poster']['original'] ??
                $showResult['poster']['large'] ??
                $showResult['poster']['medium'] ??
                $showResult['poster']['small'] ?? null;

            // Check if this is actually a season poster from Fanart.tv (they follow specific naming patterns)
            if ($posterUrl && strpos($posterUrl, 'seasonposter') !== false) {
                $seasonName = $requestedSeasonNumber === 0 ? "Specials" : "Season $requestedSeasonNumber";
                $seasonTitle = "$showTitle - $seasonName (Fanart)";

                $allPosters[] = [
                    'url' => $posterUrl,
                    'name' => $seasonTitle,
                    'season' => $requestedSeasonNumber,
                    'source' => 'fanart.tv',
                    'isSeasonPoster' => true
                ];
            }
        }

        // Only add fallback general show poster if we don't have any season-specific posters
        if (count($allPosters) === 0 && !empty($showResult['poster']) && is_array($showResult['poster'])) {
            $showPosterUrl = $showResult['poster']['original'] ??
                $showResult['poster']['large'] ??
                $showResult['poster']['medium'] ??
                $showResult['poster']['small'] ?? null;

            if ($showPosterUrl) {
                $seasonName = $requestedSeasonNumber === 0 ? "Specials" : "Season $requestedSeasonNumber";

                $allPosters[] = [
                    'url' => $showPosterUrl,
                    'name' => "$showTitle - $seasonName (Show Poster)",
                    'season' => $requestedSeasonNumber,
                    'isFallback' => true,
                    'source' => $source,
                    'isSeasonPoster' => false
                ];
            }
        }
    }

    // If we found no posters at all, include a fallback from the primary result
    if (empty($allPosters) && $result) {
        if (!empty($result['poster']) && is_array($result['poster'])) {
            $posterUrl = $result['poster']['original'] ??
                $result['poster']['large'] ??
                $result['poster']['medium'] ??
                $result['poster']['small'] ?? null;

            $seasonName = $requestedSeasonNumber === 0 ? "Specials" : "Season $requestedSeasonNumber";
            $fallbackTitle = ($result['name'] ?? $result['title'] ?? 'Unknown TV Show') . " - $seasonName (Fallback)";

            if ($posterUrl) {
                $allPosters[] = [
                    'url' => $posterUrl,
                    'name' => $fallbackTitle,
                    'season' => $requestedSeasonNumber,
                    'isFallback' => true,
                    'isSeasonPoster' => false
                ];
            }
        }
    }

    // Handle primary result poster for backward compatibility
    if ($result) {
        // Check if there's a season object with poster for the primary result
        if (!empty($result['season']) && is_array($result['season'])) {
            $seasonNumber = $result['season']['season_number'] ?? $requestedSeasonNumber;
            $seasonName = $result['season']['name'] ?? ($seasonNumber === 0 ? "Specials" : "Season $seasonNumber");

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
                $posterUrl = $result['poster']['original'] ??
                    $result['poster']['large'] ??
                    $result['poster']['medium'] ??
                    $result['poster']['small'] ?? null;
            }

            // Add fallback season name
            $seasonName = $requestedSeasonNumber === 0 ? "Specials" : "Season $requestedSeasonNumber";
            $title .= " - $seasonName";
        }
    }
} elseif ($type === 'collection') {
    // Get the base search term (without "Collection")
    $baseSearchTerm = $searchQuery;

    // Collection-specific matching
    $strictMatches = []; // Store strictly matching collections

    // Process each collection result
    foreach ($allResults as $index => $collectionResult) {
        // Get collection title
        $collectionTitle = $collectionResult['title'] ?? $collectionResult['name'] ?? 'Unknown Collection';

        // Check if this collection title is an exact match for our search
        if (isCollectionMatch($originalSearchTitle, $collectionTitle)) {
            // Get collection poster
            if (!empty($collectionResult['poster']) && is_array($collectionResult['poster'])) {
                $collectionPosterUrl = $collectionResult['poster']['original'] ??
                    $collectionResult['poster']['large'] ??
                    $collectionResult['poster']['medium'] ??
                    $collectionResult['poster']['small'] ?? null;

                if ($collectionPosterUrl) {
                    $posterEntry = [
                        'url' => $collectionPosterUrl,
                        'name' => $collectionTitle,
                        'isExactMatch' => true
                    ];

                    $strictMatches[] = $posterEntry;
                    $allPosters[] = $posterEntry;

                    // If this is the first result, make it the primary result for backward compatibility
                    if ($index === 0) {
                        $result = $collectionResult;
                    }
                }
            }
        }
    }

    // Set primary result to first strict match if available
    if (!empty($strictMatches)) {
        $primaryMatch = $strictMatches[0];
        $title = $primaryMatch['name'];
        $posterUrl = $primaryMatch['url'];
    }
    // Otherwise fallback to the first result (backward compatibility)
    else if ($result) {
        $title = $result['name'] ?? $result['title'] ?? 'Unknown Collection';

        // Get collection poster
        if (!empty($result['poster']) && is_array($result['poster'])) {
            $posterUrl = $result['poster']['original'] ??
                $result['poster']['large'] ??
                $result['poster']['medium'] ??
                $result['poster']['small'] ?? null;
        }
    }

    // If no exact matches were found at all, clear all posters
    if (empty($strictMatches)) {
        $allPosters = [];
        $posterUrl = null;
    }
}

// Check if we have any posters
if (empty($posterUrl) && empty($allPosters)) {
    echo json_encode([
        'success' => false,
        'error' => 'No poster found for this title'
    ]);
    exit;
}

// NEW: Add debug information about the Star Wars title handling
$debugInfo = [];
if (preg_match('/star\s+wars/i', $originalSearchTitle)) {
    $debugInfo = [
        'originalTitle' => $originalSearchTitle,
        'cleanedQuery' => $cleanQuery,
        'isStarWarsTitle' => $isStarWarsTitle,
        'starWarsSubtitle' => $starWarsSubtitle,
        'resultsCount' => count($allResults),
        'matchedCount' => isset($matchedResults) ? count($matchedResults) : 0
    ];
}

// Return both single poster and all posters for multi-selection
echo json_encode([
    'success' => true,
    'posterUrl' => $posterUrl,
    'title' => $title,
    'mediaType' => $type,
    'seasonNumber' => $seasonNumber,
    'requested_season' => $season,
    'allPosters' => $allPosters,
    'hasMultiplePosters' => count($allPosters) > 1,
    'originalQuery' => $query,
    'cleanedQuery' => $cleanQuery,
    'debug' => $debugInfo
]);
?>