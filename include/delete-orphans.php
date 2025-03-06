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

session_start();

// Include configuration
require_once '../include/config.php';

/**
 * Application Configuration
 */
$config = [
    'directories' => [
        'movies' => '../posters/movies/',
        'tv-shows' => '../posters/tv-shows/',
        'tv-seasons' => '../posters/tv-seasons/',
        'collections' => '../posters/collections/'
    ],
    'allowedExtensions' => ['jpg', 'jpeg', 'png', 'webp']
];

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Validate filename for security
function isValidFilename($filename) {
    // Check for slashes and backslashes
    if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
        return false;
    }
    return true;
}

// Get image files from directory
function getImageFiles($config, $currentDirectory = '') {
    $files = [];
    
    if (empty($currentDirectory)) {
        // Get files from all directories
        foreach ($config['directories'] as $dirKey => $dirPath) {
            if (is_dir($dirPath)) {
                if ($handle = opendir($dirPath)) {
                    while (($file = readdir($handle)) !== false) {
                        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        if (in_array($extension, $config['allowedExtensions'])) {
                            $files[] = [
                                'filename' => $file,
                                'directory' => $dirKey,
                                'fullpath' => $dirPath . $file
                            ];
                        }
                    }
                    closedir($handle);
                }
            }
        }
    } else {
        // Get files from specific directory
        $dirPath = $config['directories'][$currentDirectory];
        if (is_dir($dirPath)) {
            if ($handle = opendir($dirPath)) {
                while (($file = readdir($handle)) !== false) {
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($extension, $config['allowedExtensions'])) {
                        $files[] = [
                            'filename' => $file,
                            'directory' => $currentDirectory,
                            'fullpath' => $dirPath . $file
                        ];
                    }
                }
                closedir($handle);
            }
        }
    }
    
    // Sort files alphabetically
    usort($files, function($a, $b) {
        return strnatcasecmp($a['filename'], $b['filename']);
    });
    
    return $files;
}

// Security check - only allow logged in users
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized'
    ]);
    exit;
}

// Get all orphaned files
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_orphaned_files') {
    header('Content-Type: application/json');
    
    // Get all images
    $allImages = getImageFiles($config, '');
    $orphanedImages = [];
    
    // Filter orphaned images
    foreach ($allImages as $image) {
        if (strpos(strtolower($image['filename']), '**plex**') === false) {
            $orphanedImages[] = [
                'filename' => $image['filename'],
                'directory' => $image['directory']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'files' => $orphanedImages,
        'count' => count($orphanedImages)
    ]);
    exit;
}

// Delete a single orphaned file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_orphaned_file') {
    header('Content-Type: application/json');
    
    $filename = isset($_POST['filename']) ? $_POST['filename'] : '';
    $directory = isset($_POST['directory']) ? $_POST['directory'] : '';
    
    if (empty($filename) || empty($directory) || !isset($config['directories'][$directory])) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid request'
        ]);
        exit;
    }
    
    $filepath = $config['directories'][$directory] . $filename;
    
    // Security check: Ensure the file is within allowed directory and is orphaned
    if (!isValidFilename($filename) || !file_exists($filepath) || strpos(strtolower($filename), '**plex**') !== false) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid file'
        ]);
        exit;
    }
    
    if (unlink($filepath)) {
        echo json_encode([
            'success' => true
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to delete file'
        ]);
    }
    exit;
}

// Return error for any other requests
header('Content-Type: application/json');
echo json_encode([
    'success' => false,
    'error' => 'Invalid action'
]);
exit;
