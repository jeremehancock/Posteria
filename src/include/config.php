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

// Helper function to convert string values to booleans
function getBoolEnvWithFallback($key, $default)
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    // Convert string "true"/"false" to actual boolean
    if (is_string($value)) {
        return strtolower($value) === 'true' || $value === '1' || $value === 'yes';
    }
    return (bool) $value;
}

// Define helper functions if they don't exist
if (!function_exists('getEnvWithFallback')) {
    function getEnvWithFallback($key, $default)
    {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

if (!function_exists('getIntEnvWithFallback')) {
    function getIntEnvWithFallback($key, $default)
    {
        $value = getenv($key);
        return $value !== false ? intval($value) : $default;
    }
}

$auth_config = [
    'username' => getEnvWithFallback('AUTH_USERNAME', 'admin'),
    'password' => getEnvWithFallback('AUTH_PASSWORD', 'changeme'),
    'auth_bypass' => getBoolEnvWithFallback('AUTH_BYPASS', false),
    'session_duration' => getIntEnvWithFallback('SESSION_DURATION', 3600) // 1 hour default
];

$plex_config = [
    'server_url' => getEnvWithFallback('PLEX_SERVER_URL', ''),
    'token' => getEnvWithFallback('PLEX_TOKEN', ''),
    'connect_timeout' => getIntEnvWithFallback('PLEX_CONNECT_TIMEOUT', 10),
    'request_timeout' => getIntEnvWithFallback('PLEX_REQUEST_TIMEOUT', 60),
    'import_batch_size' => getIntEnvWithFallback('PLEX_IMPORT_BATCH_SIZE', 25),
    'remove_overlay_label' => getBoolEnvWithFallback('PLEX_REMOVE_OVERLAY_LABEL', false)
];

$auto_import_config = [
    // Whether auto-import is enabled
    'enabled' => getBoolEnvWithFallback('AUTO_IMPORT_ENABLED', true),

    // Schedule interval - supported formats: '24h', '12h', '6h', '1d', '7d', etc.
    // h = hours, d = days, w = weeks, m = minutes
    'schedule' => getEnvWithFallback('AUTO_IMPORT_SCHEDULE', '24h'),

    // What to import
    'import_movies' => getBoolEnvWithFallback('AUTO_IMPORT_MOVIES', true),
    'import_shows' => getBoolEnvWithFallback('AUTO_IMPORT_SHOWS', true),
    'import_seasons' => getBoolEnvWithFallback('AUTO_IMPORT_SEASONS', true),
    'import_collections' => getBoolEnvWithFallback('AUTO_IMPORT_COLLECTIONS', true)

];

$display_config = [
    'ignore_articles_in_sort' => getBoolEnvWithFallback('IGNORE_ARTICLES_IN_SORT', true), // Default to true for better user experience
    'sort_by_date_added' => getBoolEnvWithFallback('SORT_BY_DATE_ADDED', false) // Default to false to maintain backward compatibility
];

?>