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

// Version Checker for Posteria

// Set the current version here (to be updated with each release)

define('POSTERIA_VERSION', '1.4.8');

/**
 * Checks if an update is available by comparing version strings
 * 
 * @return array Contains 'updateAvailable' (bool) and 'latestVersion' (string)
 */
function checkForUpdates()
{
    $result = [
        'updateAvailable' => false,
        'latestVersion' => POSTERIA_VERSION
    ];

    // Simple version comparison function
    function compareVersions($version1, $version2)
    {
        $v1 = explode('.', $version1);
        $v2 = explode('.', $version2);

        // Ensure both arrays have 3 elements
        $v1 = array_pad($v1, 3, 0);
        $v2 = array_pad($v2, 3, 0);

        // Compare major, minor, patch versions
        for ($i = 0; $i < 3; $i++) {
            if ((int) $v1[$i] != (int) $v2[$i]) {
                return ((int) $v1[$i] < (int) $v2[$i]) ? -1 : 1;
            }
        }

        return 0; // Versions are equal
    }

    // Safe URL fetch with timeout and error handling
    try {
        // Try to fetch the latest version from GitHub safely
        $versionUrl = 'https://raw.githubusercontent.com/jeremehancock/Posteria/refs/heads/main/version';

        // Use cURL if available (more reliable)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $versionUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $latestVersion = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Only use result if HTTP 200 OK
            if ($httpCode !== 200) {
                $latestVersion = false;
            }
        } else {
            // Fallback to file_get_contents
            $context = @stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);

            $latestVersion = @file_get_contents($versionUrl, false, $context);
        }

        // Process result if fetch was successful
        if ($latestVersion !== false) {
            $latestVersion = trim($latestVersion);

            // Validate version string format (x.y.z)
            if (preg_match('/^\d+\.\d+\.\d+$/', $latestVersion)) {
                if (compareVersions(POSTERIA_VERSION, $latestVersion) < 0) {
                    $result['updateAvailable'] = true;
                    $result['latestVersion'] = $latestVersion;
                }
            }
        }
    } catch (Exception $e) {
        // Silently fail and use default values
    }

    return $result;
}
?>