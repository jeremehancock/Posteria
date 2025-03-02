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
 
// Error reporting and session management
error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();

/**
 * Helper Functions
 */

// Helper function to get environment variable with fallback
function getEnvWithFallback($key, $default) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

// Helper function to get integer environment variable with fallback
function getIntEnvWithFallback($key, $default) {
    $value = getenv($key);
    return $value !== false ? intval($value) : $default;
}

// Site title configuration
$site_title = getEnvWithFallback('SITE_TITLE', 'Posteria');

// Include configuration file
require_once './include/config.php';

/**
 * Application Configuration
 */
$config = [
    'directories' => [
        'movies' => 'posters/movies/',
        'tv-shows' => 'posters/tv-shows/',
        'tv-seasons' => 'posters/tv-seasons/',
        'collections' => 'posters/collections/'
    ],
    'imagesPerPage' => getIntEnvWithFallback('IMAGES_PER_PAGE', 24),
    'allowedExtensions' => ['jpg', 'jpeg', 'png', 'webp'],
    'siteUrl' => (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/',
    'maxFileSize' => getIntEnvWithFallback('MAX_FILE_SIZE', 5 * 1024 * 1024) // 5MB default
];

$loginError = '';

// Get current directory filter from URL parameter
$currentDirectory = isset($_GET['directory']) ? trim($_GET['directory']) : '';
if (!empty($currentDirectory) && !isset($config['directories'][$currentDirectory])) {
    $currentDirectory = '';
}

/**
 * Utility Functions
 */

// Send JSON response and exit
function sendJsonResponse($success, $error = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'error' => $error
    ]);
    exit;
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

// Fuzzy search implementation
function fuzzySearch($pattern, $str) {
    $pattern = strtolower($pattern);
    $str = strtolower($str);
    $patternLength = strlen($pattern);
    $strLength = strlen($str);
    
    if ($patternLength > $strLength) {
        return false;
    }
    
    if ($patternLength === $strLength) {
        return $pattern === $str;
    }
    
    $previousIndex = -1;
    for ($i = 0; $i < $patternLength; $i++) {
        $currentChar = $pattern[$i];
        $index = strpos($str, $currentChar, $previousIndex + 1);
        
        if ($index === false) {
            return false;
        }
        
        $previousIndex = $index;
    }
    
    return true;
}

// Filter images based on search query
function filterImages($images, $searchQuery) {
    if (empty($searchQuery)) {
        return $images;
    }
    
    $filteredImages = [];
    foreach ($images as $image) {
        $filename = pathinfo($image['filename'], PATHINFO_FILENAME);
        if (fuzzySearch($searchQuery, $filename)) {
            $filteredImages[] = $image;
        }
    }
    
    return $filteredImages;
}

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

// Format directory name for display
function formatDirectoryName($dirKey) {
    $text = str_replace('-', ' ', $dirKey);
    
    // Handle TV-related text
    if (stripos($text, 'tv') === 0) {
        $text = 'TV ' . ucwords(substr($text, 3));
    } else {
        $text = ucwords($text);
    }
    
    return $text;
}

// Generate unique filename to avoid overwrites
function generateUniqueFilename($originalName, $directory) {
    $info = pathinfo($originalName);
    $ext = strtolower($info['extension']);
    $filename = $info['filename'];
    
    $newFilename = $filename;
    $counter = 1;
    
    while (file_exists($directory . $newFilename . '.' . $ext)) {
        $newFilename = $filename . '_' . $counter;
        $counter++;
    }
    
    return $newFilename . '.' . $ext;
}

// Check if file has allowed extension
function isAllowedFileType($filename, $allowedExtensions) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowedExtensions);
}

// Generate pagination links
function generatePaginationLinks($currentPage, $totalPages, $searchQuery, $currentDirectory) {
    $links = '';
    $params = [];
    if (!empty($searchQuery)) $params['search'] = $searchQuery;
    if (!empty($currentDirectory)) $params['directory'] = $currentDirectory;
    $queryString = http_build_query($params);
    $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
    
    // Previous page link
    if ($currentPage > 1) {
        $links .= "<a href=\"" . $baseUrl . "page=" . ($currentPage - 1) . "\" class=\"pagination-link\">&laquo;</a> ";
    } else {
        $links .= "<span class=\"pagination-link disabled\">&laquo;</span> ";
    }
    
    // Page number links
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if ($startPage > 1) {
        $links .= "<a href=\"" . $baseUrl . "page=1\" class=\"pagination-link\">1</a> ";
        if ($startPage > 2) {
            $links .= "<span class=\"pagination-ellipsis\">...</span> ";
        }
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        if ($i == $currentPage) {
            $links .= "<span class=\"pagination-link current\">{$i}</span> ";
        } else {
            $links .= "<a href=\"" . $baseUrl . "page={$i}\" class=\"pagination-link\">{$i}</a> ";
        }
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $links .= "<span class=\"pagination-ellipsis\">...</span> ";
        }
        $links .= "<a href=\"" . $baseUrl . "page={$totalPages}\" class=\"pagination-link\">{$totalPages}</a> ";
    }
    
    // Next page link
    if ($currentPage < $totalPages) {
        $links .= "<a href=\"" . $baseUrl . "page=" . ($currentPage + 1) . "\" class=\"pagination-link\">&raquo;</a>";
    } else {
        $links .= "<span class=\"pagination-link disabled\">&raquo;</span>";
    }
    
    return $links;
}

/**
 * Authentication Handlers
 */

// Handle AJAX login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login' && 
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    if ($_POST['username'] === $auth_config['username'] && $_POST['password'] === $auth_config['password']) {
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid username or password']);
    }
    exit;
}

// Regular form login (fallback)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if ($_POST['username'] === $auth_config['username'] && $_POST['password'] === $auth_config['password']) {
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Check session expiration
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $auth_config['session_duration']) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * File Management Handlers
 */

// Handle file move
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'move') {
    header('Content-Type: application/json');
    
    $filename = isset($_POST['filename']) ? $_POST['filename'] : '';
    $sourceDirectory = isset($_POST['source_directory']) ? $_POST['source_directory'] : '';
    $targetDirectory = isset($_POST['target_directory']) ? $_POST['target_directory'] : '';
    
    if (empty($filename) || empty($sourceDirectory) || empty($targetDirectory) || 
        !isset($config['directories'][$sourceDirectory]) || !isset($config['directories'][$targetDirectory])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    
    $sourcePath = $config['directories'][$sourceDirectory] . $filename;
    $targetPath = $config['directories'][$targetDirectory] . $filename;
    
    // Security checks
    if (!isValidFilename($filename) || !file_exists($sourcePath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file']);
        exit;
    }
    
    // Check if a file with the same name exists in target directory
    if (file_exists($targetPath)) {
        echo json_encode(['success' => false, 'error' => 'A file with this name already exists in the target directory']);
        exit;
    }
    
    exit;
}

// Handle file upload
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    header('Content-Type: application/json');
    
    // Handle file upload from disk
    if ($_POST['upload_type'] === 'file' && isset($_FILES['image'])) {
        $file = $_FILES['image'];
        $directory = isset($_POST['directory']) ? $_POST['directory'] : 'movies';
        
        // Validate directory exists
        if (!isset($config['directories'][$directory])) {
            sendJsonResponse(false, 'Invalid directory');
        }
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = match($file['error']) {
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
                default => 'Unknown upload error'
            };
            sendJsonResponse(false, $error_message);
        }
        
        // Validate file size
        if ($file['size'] > $config['maxFileSize']) {
            sendJsonResponse(false, 'File too large. Maximum size is ' . ($config['maxFileSize'] / 1024 / 1024) . 'MB');
        }
        
        // Validate file type
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $config['allowedExtensions'])) {
            sendJsonResponse(false, 'Invalid file type. Allowed types: ' . implode(', ', $config['allowedExtensions']));
        }
        
        // Generate unique filename
        $filename = generateUniqueFilename($file['name'], $config['directories'][$directory]);
        $filepath = $config['directories'][$directory] . $filename;
        
        // Ensure directory exists and is writable
        $targetDir = dirname($filepath);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                sendJsonResponse(false, 'Failed to create directory structure');
            }
        }
        
        if (!is_writable($targetDir)) {
            sendJsonResponse(false, 'Directory is not writable');
        }
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Set proper permissions
            chmod($filepath, 0644);
            sendJsonResponse(true);
        } else {
            sendJsonResponse(false, 'Failed to save file. Please check directory permissions.');
        }
    }
    
    // Handle URL upload
    if ($_POST['upload_type'] === 'url' && isset($_POST['image_url'])) {
        $url = $_POST['image_url'];
        $directory = isset($_POST['directory']) ? $_POST['directory'] : 'movies';
        
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            sendJsonResponse(false, 'Invalid URL format');
        }
        
        // Validate directory exists
        if (!isset($config['directories'][$directory])) {
            sendJsonResponse(false, 'Invalid directory');
        }
        
        // Get file info and decode URL-encoded spaces in the basename
        $fileInfo = pathinfo(urldecode($url));
        $decodedBasename = $fileInfo['basename'];
        $ext = strtolower($fileInfo['extension']);
        
        // Validate file type
        if (!in_array($ext, $config['allowedExtensions'])) {
            sendJsonResponse(false, 'Invalid file type. Allowed types: ' . implode(', ', $config['allowedExtensions']));
        }
        
        // Generate unique filename using the decoded basename
        $filename = generateUniqueFilename($decodedBasename, $config['directories'][$directory]);
        $filepath = $config['directories'][$directory] . $filename;
        
        // Ensure directory exists and is writable
        $targetDir = dirname($filepath);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                sendJsonResponse(false, 'Failed to create directory structure');
            }
        }
        
        if (!is_writable($targetDir)) {
            sendJsonResponse(false, 'Directory is not writable');
        }
        
        // Initialize curl
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            CURLOPT_TIMEOUT => 30
        ]);
        
        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);

        // Check for curl errors
        if ($fileContent === false) {
            sendJsonResponse(false, 'Download failed: ' . $error);
        }

        // Check HTTP response code
        if ($httpCode !== 200) {
            sendJsonResponse(false, 'HTTP error: ' . $httpCode);
        }

        // Check file size
        $downloadedSize = strlen($fileContent);
        if ($downloadedSize > $config['maxFileSize']) {
            sendJsonResponse(false, 'File exceeds maximum allowed size of ' . ($config['maxFileSize'] / 1024 / 1024) . 'MB');
        }

        // Verify the downloaded content is an image
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($fileContent);
        if (!str_starts_with($mimeType, 'image/')) {
            sendJsonResponse(false, 'Downloaded content is not an image');
        }

        // Save the file
        if (file_put_contents($filepath, $fileContent)) {
            chmod($filepath, 0644);
            sendJsonResponse(true);
        } else {
            sendJsonResponse(false, 'Failed to save file');
        }
    }
}

// Handle file delete
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');
    
    $filename = isset($_POST['filename']) ? $_POST['filename'] : '';
    $directory = isset($_POST['directory']) ? $_POST['directory'] : '';
    
    if (empty($filename) || empty($directory) || !isset($config['directories'][$directory])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    
    $filepath = $config['directories'][$directory] . $filename;
    
    // Security check: Ensure the file is within allowed directory
    if (!isValidFilename($filename) || !file_exists($filepath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file']);
        exit;
    }
    
    if (unlink($filepath)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete file']);
    }
    exit;
}

// Handle download request
if (isset($_GET['download']) && !empty($_GET['download'])) {
    $requestedFile = $_GET['download'];
    $directory = isset($_GET['dir']) ? $_GET['dir'] : '';
    
    if (!empty($directory) && isset($config['directories'][$directory])) {
        $filePath = $config['directories'][$directory] . $requestedFile;
        
        // Validate the file exists and is allowed
        $extension = strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION));
        if (file_exists($filePath) && in_array($extension, $config['allowedExtensions'])) {
            // Set headers for download
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($requestedFile) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            
            // Output file and exit
            readfile($filePath);
            exit;
        }
    }
}

/**
 * Image and Pagination Processing
 */

// Get all image files
$allImages = getImageFiles($config, $currentDirectory);

// Get search query
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

// Filter images based on search query
$filteredImages = filterImages($allImages, $searchQuery);

// Calculate pagination
$totalImages = count($filteredImages);
$totalPages = ceil($totalImages / $config['imagesPerPage']);
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1
$page = min($page, max(1, $totalPages)); // Ensure page doesn't exceed total pages

// Get images for current page
$startIndex = ($page - 1) * $config['imagesPerPage'];
$pageImages = array_slice($filteredImages, $startIndex, $config['imagesPerPage']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_title); ?></title>
    <meta property="og:title" content="Posteria" />
    <meta property="og:type" content="website" />
    <meta property="og:description" content="Posteria" />
    <meta property="og:url" content="<?php echo htmlspecialchars($config['siteUrl']); ?>" />
    <meta property="og:image" content="<?php echo htmlspecialchars($config['siteUrl']); ?>/assets/web-app-manifest-512x512.png" />
    <link rel="icon" type="image/png" href="/assets/favicon-96x96.png" sizes="96x96" />
	<link rel="icon" type="image/svg+xml" href="./assets/favicon.svg" />
	<link rel="shortcut icon" href="./assets/favicon.ico" />
	<link rel="apple-touch-icon" sizes="180x180" href="./assets/apple-touch-icon.png" />
	<meta name="apple-mobile-web-app-title" content="Posteria" />
	<link rel="manifest" href="./assets/site.webmanifest" />
    <style>
		/* ==========================================================================
		   1. Theme Variables
		   ========================================================================== */
		:root {
			/* Colors */
			--bg-primary: #1f1f1f;
			--bg-secondary: #282828;
			--bg-tertiary: #333333;
			--text-primary: #ffffff;
			--text-secondary: #999999;
			--accent-primary: #e5a00d;
			--accent-hover: #f5b025;
			--border-color: #3b3b3b;
			--card-bg: #282828;
			--card-hover: #333333;
			--success-color: #2ed573;
			--danger-color: #ff4757;
			--action-bg: rgba(0, 0, 0, 0.85);
			
			/* Shadows */
			--shadow-sm: 0 4px 6px rgba(0, 0, 0, 0.4);
			--shadow-md: 0 6px 12px rgba(0, 0, 0, 0.5);
		}

		/* ==========================================================================
		   2. Base & Reset Styles
		   ========================================================================== */
		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}

		html {
			scrollbar-gutter: stable;
			overflow-y: scroll;
			scrollbar-color: var(--bg-tertiary) var(--bg-primary);
			scrollbar-width: thin;
		}

		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			line-height: 1.6;
			color: var(--text-primary);
			background-color: var(--bg-primary);
			padding: 20px;
			background-image: linear-gradient(to bottom, #1a1a1a, #1f1f1f);
			min-height: 100vh;
		}

		/* ==========================================================================
		   3. Layout & Container
		   ========================================================================== */
		.container {
			max-width: 1200px;
			margin: 0 auto;
			padding-bottom: 10px;
			height: 100%;
		}

		/* ==========================================================================
		   4. Typography
		   ========================================================================== */
		h1 {
			font-weight: 600;
			letter-spacing: -0.025em;
			color: var(--text-primary);
			font-size: 3rem;
			margin-bottom: 15px;
		}

		/* ==========================================================================
		   5. Custom Scrollbar
		   ========================================================================== */
		::-webkit-scrollbar {
			width: 12px;
			height: 12px;
			background-color: var(--bg-primary);
		}

		::-webkit-scrollbar-track {
			background: var(--bg-primary);
			border-radius: 8px;
			border: 2px solid var(--bg-secondary);
		}

		::-webkit-scrollbar-thumb {
			background: var(--bg-tertiary);
			border-radius: 8px;
			border: 3px solid var(--bg-primary);
			min-height: 40px;
		}

		::-webkit-scrollbar-thumb:hover {
			background: var(--accent-primary);
			border-width: 2px;
		}

		::-webkit-scrollbar-corner {
			background: var(--bg-primary);
		}

		/* ==========================================================================
		   6. Buttons
		   ========================================================================== */
		/* General Button Styles */
		.login-trigger-button,
		.upload-trigger-button,
		.logout-button {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 12px;
			border-radius: 8px;
			cursor: pointer;
			font-weight: 600;
			transition: all 0.2s;
			text-decoration: none;
			border: none;
			height: 44px;
			box-sizing: border-box;
		}

		.login-trigger-button,
		.upload-trigger-button {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
		}

		.login-trigger-button:hover,
		.upload-trigger-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-2px);
		}

		.logout-button {
			background: var(--bg-tertiary);
			color: var(--text-secondary);
			border: 1px solid var(--border-color);
		}

		.logout-button:hover {
			background: #3d3d3d;
			color: var(--text-primary);
			border-color: var(--text-secondary);
			transform: translateY(-2px);
		}

		/* Filter Buttons */
		.filter-button {
			padding: 8px 16px;
			border: none;
			border-radius: 6px;
			background: transparent;
			color: var(--text-primary);
			cursor: pointer;
			transition: all 0.2s;
			font-weight: 500;
			text-decoration: none;
			height: 36px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			box-sizing: border-box;
		}

		.filter-button:hover {
			background: var(--bg-tertiary);
		}

		.filter-button.active {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			font-weight: 600;
		}

		/* Overlay Action Buttons */
		.overlay-action-button {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			padding: 10px 16px;
			border-radius: 6px;
			cursor: pointer;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 14px;
			font-weight: 600;
			transition: all 0.2s;
			box-shadow: 0 2px 5px rgba(0,0,0,0.2);
			text-decoration: none;
			width: 70%;
			margin: 0 auto 8px auto;
			border: none;
			height: 44px;
			box-sizing: border-box;
		}

		.overlay-action-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-2px);
		}

		/* Modal Buttons */
		.modal-button {
			padding: 12px 24px;
			border-radius: 6px;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.2s ease;
			font-size: 14px;
			border: none;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			height: 44px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			box-sizing: border-box;
		}

		.modal-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-2px);
		}

		.modal-button.cancel {
			background: var(--bg-secondary);
			color: var(--text-primary);
			border: 1px solid var(--border-color);
		}

		.modal-button.cancel:hover {
			background: var(--bg-tertiary);
			border-color: var(--accent-primary);
			transform: translateY(-1px);
		}

		.modal-button.delete,
		.delete-btn {
			background: #8B0000;
			color: var(--text-primary);
			border: 1px solid #a83232;
		}

		.modal-button.delete:hover,
		.delete-btn:hover {
			background: #a31c1c;
			transform: translateY(-1px);
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
			border-color: #c73e3e;
		}

		/* Login Button */
		.login-button {
			width: 100%;
			padding: 12px 20px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border: none;
			border-radius: 6px;
			cursor: pointer;
			font-weight: 600;
			transition: all 0.2s ease;
			margin-top: 8px;
			height: 44px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			box-sizing: border-box;
		}

		.login-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-1px);
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
		}

		/* Upload Button */
		.upload-button {
			padding: 10px 20px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border: none;
			border-radius: 6px;
			cursor: pointer;
			transition: all 0.2s ease;
			white-space: nowrap;
			min-width: 120px;
			font-weight: 600;
			margin-top: 20px;
			height: 44px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			box-sizing: border-box;
		}

		.upload-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-1px);
		}

		/* Icon Styles */
		.login-icon,
		.upload-icon,
		.logout-icon,
		.image-action-icon {
			width: 20px;
			height: 20px;
		}

		.image-action-icon {
			margin-right: 8px;
		}

		/* Plex Buttons */
		.send-to-plex-confirm,
		.import-from-plex-confirm {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			height: 44px;
			box-sizing: border-box;
		}

		.send-to-plex-confirm:hover,
		.import-from-plex-confirm:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-2px);
		}

		/* Search button */
		.search-button {
			padding: 14px 24px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border: none;
			cursor: pointer;
			font-size: 16px;
			font-weight: 600;
			height: 52px;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			box-sizing: border-box;
		}

		.search-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
		}

		/* Custom File Input */
		.custom-file-input label {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 12px 16px;
			background: var(--bg-secondary);
			border: 1px solid var(--border-color);
			border-radius: 6px;
			color: var(--text-primary);
			font-weight: 500;
			cursor: pointer;
			transition: all 0.2s ease;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			height: 44px;
			box-sizing: border-box;
		}

		.custom-file-input label:hover {
			background: var(--bg-tertiary);
			border-color: var(--accent-primary);
		}

		/* Pagination links */
		.pagination-link {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 10px 16px;
			background-color: var(--bg-secondary);
			border: 1px solid var(--border-color);
			border-radius: 6px;
			color: var(--text-primary);
			text-decoration: none;
			font-weight: 500;
			min-width: 40px;
			transition: all 0.2s;
			height: 44px;
			box-sizing: border-box;
		}

		.pagination-link.current {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border-color: transparent;
			font-weight: 600;
		}

		.pagination-link:hover:not(.current):not(.disabled) {
			background-color: var(--bg-tertiary);
			border-color: var(--accent-primary);
			transform: translateY(-1px);
		}

		.pagination-link.disabled {
			color: var(--text-secondary);
			cursor: not-allowed;
			opacity: 0.5;
		}

		/* ==========================================================================
		   7. Header
		   ========================================================================== */
		header {
			text-align: center;
			margin-bottom: 40px;
		}

		.header-content {
			display: flex;
			justify-content: space-between;
			align-items: center;
		}

		.site-name {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			-webkit-background-clip: text;
			-webkit-text-fill-color: transparent;
			display: flex;
			align-items: center;
		}

		.site-name svg {
			flex-shrink: 0;
			height: 80px;
		}

		.auth-actions {
			display: flex;
			gap: 12px;
			align-items: center;
		}

		/* ==========================================================================
		   8. Search
		   ========================================================================== */
		.search-container {
			margin-bottom: 40px;
			text-align: center;
			position: relative;
		}

		.search-form {
			display: inline-flex;
			width: 100%;
			max-width: 500px;
			border-radius: 8px;
			overflow: hidden;
			box-shadow: var(--shadow-md);
			border: 1px solid var(--border-color);
		}

		.search-input {
			flex-grow: 1;
			padding: 14px 20px;
			background-color: var(--bg-secondary);
			color: var(--text-primary);
			font-size: 16px;
			border: none;
		}

		.search-input:focus {
			outline: none;
			background-color: var(--bg-tertiary);
		}

		.search-button {
			padding: 14px 24px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border: none;
			cursor: pointer;
			font-size: 16px;
			font-weight: 600;
		}

		.search-button:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
		}

		/* ==========================================================================
		   9. Filter & Gallery Stats
		   ========================================================================== */
		.filter-container {
			margin: 20px 0 30px;
			text-align: center;
		}

		.filter-buttons {
			display: inline-flex;
			gap: 10px;
			background: var(--bg-secondary);
			padding: 5px;
			border-radius: 8px;
			border: 1px solid var(--border-color);
		}

		.gallery-stats {
			text-align: center;
			margin-bottom: 30px;
			color: var(--text-secondary);
			font-size: 14px;
		}

		.gallery-stats a {
			color: var(--accent-primary);
			text-decoration: none;
			font-weight: 500;
			margin-left: 8px;
		}

		.gallery-stats a:hover {
			color: var(--accent-hover);
			text-decoration: underline;
		}

		/* ==========================================================================
		   10. Gallery Grid
		   ========================================================================== */
		.gallery {
			display: grid;
			grid-template-columns: repeat(4, minmax(200px, 1fr));
			gap: 25px;
			margin-bottom: 40px;
			width: 100%;
		}

		.gallery-item {
			background: var(--card-bg);
			border-radius: 12px;
			overflow: hidden;
			box-shadow: var(--shadow-sm);
			transition: all 0.3s ease;
			position: relative;
			border: 1px solid var(--border-color);
		}

		.gallery-item:hover {
			transform: translateY(-5px);
			box-shadow: var(--shadow-md);
			border-color: var(--accent-primary);
		}

		/* Gallery Image Styles */
		.gallery-image-container {
			position: relative;
			overflow: hidden;
			border-radius: 12px 12px 0 0;
			display: flex;
			align-items: center;
			justify-content: center;
			aspect-ratio: 2/3;
			width: 100%;
			background: var(--bg-tertiary);
		}

		.gallery-image {
			width: 100%;
			height: 100%;
			object-fit: cover;
			display: block;
			transition: transform 0.5s ease, opacity 0.3s ease;
			opacity: 0;
			background: var(--bg-tertiary);
			max-height: 100%;
			max-width: 100%;
		}

		.gallery-image.loaded {
			opacity: 1;
		}

		.gallery-image-container:hover .gallery-image {
			transform: scale(1.05);
		}

		.gallery-image-placeholder {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: var(--bg-tertiary);
			display: flex;
			align-items: center;
			justify-content: center;
			transition: opacity 0.3s ease;
		}

		.gallery-image-placeholder.hidden {
			opacity: 0;
		}

		/* Image Caption */
		.gallery-caption {
			padding: 16px;
			text-align: center;
			word-break: break-word;
			font-weight: 500;
			color: var(--text-primary);
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			position: relative;
		}

		/* Directory Badge */
		.directory-badge {
			position: absolute;
			top: 10px;
			left: 10px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			padding: 4px 8px;
			border-radius: 4px;
			font-size: 12px;
			font-weight: 600;
			opacity: 0.9;
			z-index: 0;
		}

		/* Image Actions */
		.image-overlay-actions {
			position: absolute;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			display: flex;
			justify-content: center;
			align-items: center;
			background: var(--action-bg);
			opacity: 0;
			transition: opacity 0.3s ease;
			flex-direction: column;
		}

		/* Desktop hover behavior */
		@media (hover: hover) {
			.gallery-item:hover .image-overlay-actions {
				opacity: 1;
			}
		}

		/* Mobile touch behavior */
		.gallery-item.touch-active .image-overlay-actions {
			opacity: 1;
		}

		/* No Results */
		.no-results {
			text-align: center;
			padding: 40px 20px;
			background: var(--bg-secondary);
			border-radius: 12px;
			margin: 20px 0;
			width: 100%;
			border: 1px solid var(--border-color);
		}

		.no-results h2 {
			color: var(--text-primary);
			font-size: 1.5rem;
			margin-bottom: 16px;
			font-weight: 600;
		}

		.no-results p {
			color: var(--text-secondary);
			margin-bottom: 12px;
		}

		.no-results a {
			color: var(--accent-primary);
			text-decoration: none;
			font-weight: 500;
		}

		.no-results a:hover {
			color: var(--accent-hover);
			text-decoration: underline;
		}

		/* ==========================================================================
		   11. Modal Components
		   ========================================================================== */
		.modal {
			display: none;
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.7);
			z-index: 1000;
			opacity: 0;
			transition: opacity 0.3s ease;
		}

		.modal.show {
			opacity: 1;
		}

		.modal-content {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%) scale(0.9);
			background: var(--bg-secondary);
			padding: 24px;
			border-radius: 12px;
			width: 90%;
			max-width: 400px;
			transition: transform 0.3s ease;
			border: 1px solid var(--border-color);
			box-shadow: var(--shadow-md);
		}

		.modal.show .modal-content {
			transform: translate(-50%, -50%) scale(1);
		}

		.modal-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 20px 0;
			padding-top: 0px;
		}

		.modal-header h3 {
			margin: 0;
			font-size: 1.25rem;
			color: var(--text-primary);
		}

		.modal-close-btn {
			background: none;
			border: none;
			color: var(--text-secondary);
			font-size: 24px;
			cursor: pointer;
			padding: 0;
			line-height: 1;
		}

		.modal-close-btn:hover {
			color: var(--text-primary);
		}

		/* Modal Action Buttons */
		.modal-actions {
			display: flex;
			gap: 12px;
			justify-content: flex-end;
			margin-top: 20px;
		}

		/* Upload Modal */
		.upload-modal .modal-content {
			max-width: 600px;
			padding: 0;
		}

		.upload-modal .modal-header {
			padding: 20px 24px;
		}

		.upload-tabs {
			display: flex;
			gap: 10px;
			padding: 20px 24px 0;
			margin-bottom: 0;
		}

		.upload-content {
			padding: 20px 24px;
		}

		.upload-tab-btn {
			padding: 10px 20px;
			background: var(--bg-secondary);
			border: 1px solid var(--border-color);
			color: var(--text-primary);
			border-radius: 6px;
			cursor: pointer;
			transition: all 0.3s ease;
		}

		.upload-tab-btn.active {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border-color: transparent;
			font-weight: 600;
		}

		/* Jellyfin Import Modal */
		#jellyfinImportModal .modal-content,
		#jellyfinErrorModal .modal-content {
			max-width: 600px;
		}

		/* ==========================================================================
		   12. Form Components
		   ========================================================================== */
		/* Login Form Styles */
		.login-container {
			max-width: 400px;
			margin: 40px auto;
			padding: 24px;
			background: var(--card-bg);
			border-radius: 12px;
			box-shadow: var(--shadow-md);
		}

		.login-form {
			display: flex;
			flex-direction: column;
			gap: 16px;
		}

		.login-input {
			padding: 12px 16px;
			background: var(--bg-secondary);
			border: 1px solid var(--border-color);
			border-radius: 6px;
			color: var(--text-primary);
			width: 100%;
		}

		/* Style for select elements using login-input class */
		select.login-input {
			padding-right: 36px;
			appearance: none;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23999999' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
			background-repeat: no-repeat;
			background-position: right 8px center;
			background-size: 16px;
			cursor: pointer;
		}

		/* Upload Form */
		.upload-form {
			display: none;
			margin-bottom: 0;
		}

		.upload-form.active {
			display: block;
		}

		.upload-input-group {
			display: flex;
			gap: 10px;
			margin-bottom: 10px;
			justify-content: right;
		}

		/* Custom File Input */
		.custom-file-input {
			position: relative;
			display: inline-block;
			flex: 1;
		}

		.custom-file-input input[type="file"] {
			position: absolute;
			left: -9999px;
			opacity: 0;
			width: 0.1px;
			height: 0.1px;
		}

		.custom-file-input label {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 12px 16px;
			background: var(--bg-secondary);
			border: 1px solid var(--border-color);
			border-radius: 6px;
			color: var(--text-primary);
			font-weight: 500;
			cursor: pointer;
			transition: all 0.2s ease;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			height: 41px;
		}

		.custom-file-input label:hover {
			background: var(--bg-tertiary);
			border-color: var(--accent-primary);
		}

		.file-name {
			margin-left: 8px;
			font-weight: normal;
			color: var(--text-secondary);
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		/* Upload Messages */
		.upload-message {
			margin: 20px 24px 0;
			padding: 12px 16px;
			border-radius: 6px;
			font-weight: 500;
		}

		.upload-message.success {
			background: rgba(46, 213, 115, 0.1);
			border: 1px solid var(--success-color);
			color: var(--success-color);
		}

		.upload-message.error {
			background: rgba(239, 68, 68, 0.1);
			border: 1px solid var(--danger-color);
			color: var(--danger-color);
		}

		/* ==========================================================================
		   13. Notifications
		   ========================================================================== */
		.copy-notification {
			position: fixed;
			bottom: 25px;
			right: 25px;
			background: linear-gradient(45deg, #2ed573, #7bed9f);
			color: #1f1f1f;
			padding: 12px 24px;
			border-radius: 8px;
			box-shadow: var(--shadow-md);
			display: none;
			z-index: 1000;
			font-weight: 600;
			opacity: 0;
			transform: translateY(20px);
			transition: opacity 0.3s ease, transform 0.3s ease;
		}

		.copy-notification.show {
			opacity: 1;
			transform: translateY(0);
		}

		/* Plex Notification */
		.plex-notification {
			position: fixed;
			bottom: 25px;
			right: 25px;
			padding: 0;
			border-radius: 8px;
			box-shadow: var(--shadow-md);
			z-index: 1000;
			font-weight: 600;
			opacity: 0;
			transform: translateY(20px);
			transition: opacity 0.3s ease, transform 0.3s ease;
		}

		.plex-notification.show {
			opacity: 1;
			transform: translateY(0);
		}

		.plex-notification-content {
			display: flex;
			align-items: center;
			padding: 12px 24px;
		}

		.plex-notification.plex-success {
			background: linear-gradient(45deg, #2ed573, #7bed9f);
			color: #1e1e1e;
		}

		.plex-notification.plex-success svg {
			margin-right: 10px;
		}

		.plex-notification.plex-error {
			background: linear-gradient(45deg, #ff4757, #ff6b81);
			color: #ffffff;
		}

		.plex-notification.plex-error svg {
			margin-right: 10px;
		}

		.plex-notification.plex-sending {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1e1e1e;
		}

		/* ==========================================================================
		   14. Pagination
		   ========================================================================== */
		.pagination {
			text-align: center;
			margin: 40px 0 20px;
			display: flex;
			justify-content: center;
			flex-wrap: wrap;
			gap: 8px;
		}

		.pagination-link {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 10px 16px;
			background-color: var(--bg-secondary);
			border: 1px solid var(--border-color);
			border-radius: 6px;
			color: var(--text-primary);
			text-decoration: none;
			font-weight: 500;
			min-width: 40px;
			transition: all 0.2s;
		}

		.pagination-link.current {
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			border-color: transparent;
			font-weight: 600;
		}

		.pagination-link:hover:not(.current):not(.disabled) {
			background-color: var(--bg-tertiary);
			border-color: var(--accent-primary);
			transform: translateY(-1px);
		}

		.pagination-link.disabled {
			color: var(--text-secondary);
			cursor: not-allowed;
			opacity: 0.5;
		}

		.pagination-ellipsis {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 8px;
			color: var(--text-secondary);
		}

		/* ==========================================================================
		   15. Dropdown
		   ========================================================================== */
		.dropdown {
			position: relative;
			display: inline-block;
		}

		.dropdown-toggle {
			cursor: pointer;
			display: inline-flex;
			align-items: center;
		}

		.dropdown-content {
			display: none;
			position: absolute;
			right: 0;
			background-color: var(--bg-secondary);
			width: max-content;
			box-shadow: var(--shadow-md);
			z-index: 100;
			border-radius: 8px;
			border: 1px solid var(--border-color);
			overflow: hidden;
		}

		.dropdown-content a {
			color: var(--text-primary);
			padding: 12px 16px;
			text-decoration: none;
			display: flex;
			align-items: center;
			gap: 10px;
			transition: all 0.2s;
			justify-content: center;
		}

		.dropdown-content a:hover {
			background-color: var(--bg-tertiary);
			color: var(--accent-primary);
		}

		.dropdown:hover .dropdown-content {
			display: block;
		}

		/* ==========================================================================
		   16. Tooltips
		   ========================================================================== */
		.gallery-caption.has-tooltip {
			cursor: help;
		}

		.gallery-caption.has-tooltip::after {
			content: attr(data-tooltip);
			visibility: hidden;
			opacity: 0;
			position: absolute;
			bottom: 125%;
			left: 50%;
			transform: translateX(-50%);
			background: var(--bg-tertiary);
			color: var(--text-primary);
			padding: 8px 12px;
			border-radius: 6px;
			font-size: 14px;
			white-space: nowrap;
			z-index: 1000;
			box-shadow: var(--shadow-md);
			border: 1px solid var(--border-color);
			transition: opacity 0.2s ease-in-out;
			pointer-events: none;
		}

		.gallery-caption.has-tooltip::before {
			content: '';
			visibility: hidden;
			opacity: 0;
			position: absolute;
			bottom: 125%;
			left: 50%;
			transform: translateX(-50%);
			border: 6px solid transparent;
			border-top-color: var(--bg-tertiary);
			z-index: 1000;
			transition: opacity 0.2s ease-in-out;
			pointer-events: none;
		}

		.gallery-caption.has-tooltip:hover::after,
		.gallery-caption.has-tooltip:hover::before {
			visibility: visible;
			opacity: 1;
		}

		/* ==========================================================================
		   17. Animations
		   ========================================================================== */
		/* Loading Spinner */
		.loading-spinner {
			width: 40px;
			height: 40px;
			border-radius: 50%;
			border: 4px solid var(--text-secondary);
			border-top-color: var(--accent-primary);
			animation: spin 1s infinite linear;
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		/* Plex Spinner */
		.plex-spinner {
			width: 20px;
			height: 20px;
			border-radius: 50%;
			border: 3px solid rgba(255, 255, 255, 0.3);
			border-top-color: #ffffff;
			animation: plex-spin 1s infinite linear;
			margin-right: 10px;
		}

		@keyframes plex-spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		/* ==========================================================================
		   18. Plex Integration
		   ========================================================================== */
		/* Handled in other sections for better organization */

		/* ==========================================================================
		   19. Media Queries
		   ========================================================================== */
		@media (hover: none) {
			.gallery-item:hover {
				transform: none;
				box-shadow: none;
				border: none;
				border-color: unset;
			}
			
			.gallery-image-container:hover .gallery-image {
				transform: none;
			}
			
			.overlay-action-button:hover {
				background: linear-gradient(45deg, #f5b025, #ffa953);
				transform: none;
			}
		}

		@media (max-width: 1024px) {
			.gallery {
				grid-template-columns: repeat(3, 1fr);
				gap: 20px;
			}
		}

		@media (max-width: 768px) {
			.site-title {
				display: none;
			}

			.filter-button {
				padding: 3px 10px;
			}

			.filter-buttons {
				font-size: .8rem;
				flex-wrap: nowrap;
				gap: 0;
			}
			
			body {
				padding: 15px;
			}
			
			.gallery {
				grid-template-columns: repeat(2, 1fr);
				gap: 15px;
			}
			
			h1 {
				font-size: 2rem;
			}
			
			.site-name svg {
				height: 50px;
			}
			
			.search-input, 
			.search-button {
				padding: 12px 16px;
			}

			.filter-buttons {
				flex-wrap: wrap;
				justify-content: center;
			}
			
			.modal-content {
				width: 95%;
				padding: 20px;
			}
			
			.overlay-action-button {
				width: 85%;
				font-size: 13px;
			}
			
			.login-trigger-button,
			.upload-trigger-button,
			.logout-button,
			.modal-button,
			.login-button,
			.upload-button,
			.custom-file-input label,
			.pagination-link,
			.overlay-action-button,
			.send-to-plex-confirm,
			.import-from-plex-confirm {
				height: 40px;
			}
			
			.search-button {
				height: 48px;
			}
			
			.filter-button {
				height: 32px;
			}
		}

		@media (max-width: 480px) {
			.site-title {
				display: none;
			}

			.filter-button {
				padding: 3px 10px;
			}

			.filter-buttons {
				font-size: .76rem;
				flex-wrap: nowrap;
				gap: 0;
			}

			.gallery {
				grid-template-columns: repeat(2, 1fr);
			}
			
			.gallery-caption {
				font-size: .9rem;
			}
			
			h1 {
				font-size: 1.75rem;
			}
			
			.site-name svg {
				height: 50px;
			}

			.header-content {
				gap: 15px;
			}

			.auth-actions {
				justify-content: center;
			}
			
			.modal-content {
				width: 98%;
				padding: 16px;
			}
			
			.gallery-caption.has-tooltip::after {
				width: max-content;
				max-width: 200px;
				white-space: normal;
				text-align: center;
			}
			
			.pagination-link {
				padding: 8px 12px;
				min-width: 36px;
				font-size: 14px;
			}

			.login-trigger-button,
			.upload-trigger-button,
			.logout-button,
			.modal-button,
			.login-button,
			.upload-button,
			.custom-file-input label,
			.pagination-link,
			.overlay-action-button,
			.send-to-plex-confirm,
			.import-from-plex-confirm {
				height: 38px;
			}
			
			.search-button {
				height: 44px;
			}
			
			.filter-button {
				height: 30px;
			}
		}
    </style>
</head>

<body>
    <div class="container">
    
	<!-- Login Modal -->
        <div id="loginModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Login</h3>
                    <button type="button" class="modal-close-btn">×</button>
                </div>
                <div class="modal-body">
                    <div class="login-error"></div>
                    <form class="login-form">
                        <input type="hidden" name="action" value="login">
                        <input type="text" name="username" placeholder="Username" required class="login-input">
                        <input type="password" name="password" placeholder="Password" required class="login-input">
                        <button type="submit" class="login-button">Login</button>
                    </form>
                </div>
            </div>
        </div>
  
  <!-- Change Poster Modal -->
<div id="changePosterModal" class="modal upload-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Change Poster</h3>
            <button type="button" class="modal-close-btn">×</button>
        </div>
        
        <p id="changePosterFilename" style="margin: 10px 24px; font-weight: 500; overflow-wrap: break-word;" data-filename="" data-dirname=""></p>
        
        <div class="plex-info" style="margin: 0 24px 10px; padding: 10px; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px;">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12" y2="8"></line>
            </svg>
            <span>Updated poster will be automatically synced with Plex after upload</span>
        </div>
        
        <div class="upload-tabs">
            <button class="upload-tab-btn active" data-tab="file">Upload from Disk</button>
            <button class="upload-tab-btn" data-tab="url">Upload from URL</button>
        </div>
        
        <div class="upload-content">
            <!-- Common error message div for both forms -->
            <div class="change-poster-error" style="display: none; color: var(--danger-color); background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); padding: 12px; border-radius: 6px; margin-bottom: 16px;"></div>

            <form id="fileChangePosterForm" class="upload-form active" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="change_poster">
                <input type="hidden" name="upload_type" value="file">
                <input type="hidden" name="original_filename" id="fileChangePosterOriginalFilename">
                <input type="hidden" name="directory" id="fileChangePosterDirectory">
                
                <div class="upload-input-group">
                    <div class="custom-file-input">
                        <input type="file" name="new_poster" id="fileChangePosterInput" accept=".jpg,.jpeg,.png,.webp">
                        <label for="fileChangePosterInput">
                            <svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="17 8 12 3 7 8"></polyline>
                                <line x1="12" y1="3" x2="12" y2="15"></line>
                            </svg>
                            Choose Poster
                            <span class="file-name"></span>
                        </label>
                    </div>
                </div>
                <div class="upload-input-group">
                    <button type="submit" class="modal-button" disabled style="margin-top: 32px;">Change</button>
                </div>
                <div class="upload-help">
                    Maximum file size: 5MB<br>
                    Allowed types: jpg, jpeg, png, webp
                </div>
            </form>

            <form id="urlChangePosterForm" class="upload-form" method="POST">
                <input type="hidden" name="action" value="change_poster">
                <input type="hidden" name="upload_type" value="url">
                <input type="hidden" name="original_filename" id="urlChangePosterOriginalFilename">
                <input type="hidden" name="directory" id="urlChangePosterDirectory">
                
                <div class="upload-input-group">
                    <input type="url" name="image_url" class="login-input" placeholder="Enter poster URL..." required>
                </div>
                <div class="upload-input-group">
                    <button type="submit" class="modal-button" style="margin-top: 32px;">Change</button>
                </div>
                <div class="upload-help">
                    Maximum file size: 5MB<br>
                    Allowed types: jpg, jpeg, png, webp
                </div>
            </form>
        </div>
    </div>
</div>   
		<!-- Add this HTML before the closing </body> tag -->
<div id="plexConfirmModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Send to Plex</h3>
            <button type="button" class="modal-close-btn">×</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to send this poster to Plex?</p>
            <p id="plexConfirmFilename" style="margin-top: 10px; font-weight: 500; overflow-wrap: break-word;" data-filename="" data-dirname=""></p>
        </div>
        <div class="modal-actions">
            <button type="button" class="modal-button cancel" id="cancelPlexSend">Cancel</button>
            <button type="button" class="modal-button send-to-plex-confirm">Send</button>
        </div>
    </div>
</div>

		<!-- Upload Modal -->
		<div id="uploadModal" class="modal upload-modal">
			<div class="modal-content">
				<div class="modal-header">
				    <h3>Upload Poster</h3>
				    <button type="button" class="modal-close-btn">×</button>
				</div>
				
				<div class="upload-tabs">
				    <button class="upload-tab-btn active" data-tab="file">Upload from Disk</button>
				    <button class="upload-tab-btn" data-tab="url">Upload from URL</button>
				</div>
				
				<div class="upload-content">
				    <!-- Common error message div for both forms -->
				    <div class="upload-error" style="display: none; color: var(--danger-color); background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); padding: 12px; border-radius: 6px; margin-bottom: 16px;"></div>

				    <form id="fileUploadForm" class="upload-form active" method="POST" enctype="multipart/form-data">
				        <input type="hidden" name="action" value="upload">
				        <input type="hidden" name="upload_type" value="file">
				        <div class="upload-input-group">
				            <div class="custom-file-input">
				                <input type="file" name="image" id="fileInput" accept="<?php echo '.'.implode(',.', $config['allowedExtensions']); ?>">
				                <label for="fileInput">
				                    <svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
				                        <polyline points="17 8 12 3 7 8"></polyline>
				                        <line x1="12" y1="3" x2="12" y2="15"></line>
				                    </svg>
				                    Choose Poster
				                    <span class="file-name"></span>
				                </label>
				            </div>
				        </div>
				        <div class="directory-select" style="margin: 12px 0;">
				            <select name="directory" class="login-input">
				                <?php foreach ($config['directories'] as $dirKey => $dirPath): ?>
				                    <option value="<?php echo htmlspecialchars($dirKey); ?>">
				                        <?php echo formatDirectoryName($dirKey); ?>
				                    </option>
				                <?php endforeach; ?>
				            </select>
				        </div>
				        <div class="upload-input-group">
				            <button type="submit" class="upload-button">Upload</button>
				        </div>
				        <div class="upload-help">
				            Maximum file size: <?php echo $config['maxFileSize'] / 1024 / 1024; ?>MB<br>
				            Allowed types: <?php echo implode(', ', $config['allowedExtensions']); ?>
				        </div>
				    </form>

				    <form id="urlUploadForm" class="upload-form" method="POST">
				        <input type="hidden" name="action" value="upload">
				        <input type="hidden" name="upload_type" value="url">
				        <div class="upload-input-group">
				            <input type="url" name="image_url" class="login-input" placeholder="Enter poster URL..." required>
				        </div>
				        <div class="directory-select" style="margin: 12px 0;">
				            <select name="directory" class="login-input">
				                <?php foreach ($config['directories'] as $dirKey => $dirPath): ?>
				                    <option value="<?php echo htmlspecialchars($dirKey); ?>">
				                        <?php echo formatDirectoryName($dirKey); ?>
				                    </option>
				                <?php endforeach; ?>
				            </select>
				        </div>
				        <div class="upload-input-group">
				            <button type="submit" class="upload-button">Upload</button>
				        </div>
				        <div class="upload-help">
				            Maximum file size: <?php echo $config['maxFileSize'] / 1024 / 1024; ?>MB<br>
				            Allowed types: <?php echo implode(', ', $config['allowedExtensions']); ?>
				        </div>
				    </form>
				</div>
			</div>
		</div>
		
		<header>
			<div class="header-content">
				<a href="./" style="text-decoration: none;">
					<h1 class="site-name">
						<svg xmlns="http://www.w3.org/2000/svg" height="80" viewBox="0 0 200 200">
						  <!-- Main poster group -->
						  <g transform="translate(40, 35)">
							<!-- Back poster -->
							<rect x="0" y="0" width="70" height="100" rx="6" fill="#E5A00D" opacity="0.4"/>
							
							<!-- Middle poster -->
							<rect x="20" y="15" width="70" height="100" rx="6" fill="#E5A00D" opacity="0.7"/>
							
							<!-- Front poster -->
							<rect x="40" y="30" width="70" height="100" rx="6" fill="#E5A00D"/>
							
							<!-- Play button -->
							<circle cx="75" cy="80" r="25" fill="white"/>
							<path d="M65 65 L95 80 L65 95 Z" fill="#E5A00D"/>
						  </g>
						</svg>
						<span class="site-title"><?php echo htmlspecialchars($site_title); ?></span>
					</h1>
				</a>
<?php if (isLoggedIn()): ?>
    <div class="auth-actions">
        <?php if ((!empty($plex_config['token']) && !empty($plex_config['server_url'])) || 
                  (!empty($jellyfin_config['api_key']) && !empty($jellyfin_config['server_url']))): ?>
        <div class="dropdown">
            <button class="upload-trigger-button dropdown-toggle">
                <svg class="upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                    <polyline points="8 7 3 12 8 17"></polyline>
                    <line x1="3" y1="12" x2="15" y2="12"></line>
                </svg>
                Import
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12" style="margin-left: 5px;">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>
            <div class="dropdown-content">
                <?php if (!empty($plex_config['token']) && !empty($plex_config['server_url'])): ?>
                <a href="#" id="showPlexImportModal">
                	From Plex
                </a>
                <?php endif; ?>
                <?php if (!empty($jellyfin_config['api_key']) && !empty($jellyfin_config['server_url'])): ?>
                <a href="#" id="showJellyfinImportModal">
					From Jellyfin
                </a>
                <?php endif; ?>
            </div>
        </div>
		<div class="dropdown">
			<button class="upload-trigger-button dropdown-toggle">
				<svg class="upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				    <path d="M9 3h-4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4"></path>
				    <polyline points="16 7 21 12 16 17"></polyline>
				    <line x1="21" y1="12" x2="9" y2="12"></line>
				</svg>
				Export
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12" style="margin-left: 5px;">
				    <polyline points="6 9 12 15 18 9"></polyline>
				</svg>
			</button>
			<div class="dropdown-content">
				<?php if (!empty($plex_config['token']) && !empty($plex_config['server_url'])): ?>
				<a href="#" id="showPlexExportModal">
				    To Plex
				</a>
				<?php endif; ?>
				<?php if (!empty($jellyfin_config['api_key']) && !empty($jellyfin_config['server_url'])): ?>
				<a href="#" id="showJellyfinExportModal">
				    To Jellyfin
				</a>
				<?php endif; ?>
			</div>
		</div>
        <?php endif; ?>
        <a href="?action=logout" class="logout-button" title="Logout">
            <svg class="logout-icon" xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 28 28" fill="none" stroke="currentColor" stroke-width="2" style="margin-left: 5px;">
              <!-- Half Circle (using M start, A arc, Z close) -->
              <path d="M7 4 A 8 8 0 0 0 7 20" stroke-linecap="round"></path>
              <polyline points="14 7 19 12 14 17" stroke-linecap="round" stroke-linejoin="round"></polyline>
              <line x1="19" y1="12" x2="7" y2="12" stroke-linecap="round"></line>
            </svg>
        </a>
    </div>
<?php else: ?>
    <button id="showLoginModal" class="login-trigger-button">
        <svg class="login-icon" xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 28 28" fill="none" stroke="currentColor" stroke-width="2">
          <!-- Half Circle (using M start, A arc, Z close) -->
          <path d="M17 4 A 8 8 0 0 1 17 20" stroke-linecap="round"></path>
          <polyline points="10 7 5 12 10 17" stroke-linecap="round" stroke-linejoin="round"></polyline>
          <line x1="5" y1="12" x2="17" y2="12" stroke-linecap="round"></line>
        </svg>
        Login
    </button>
<?php endif; ?>
			</div>
		</header>
		
	<!-- Plex Import Modal -->
	<div id="plexImportModal" class="modal">
		<div class="modal-content" style="max-width: 600px;">
		    <div class="modal-header">
		        <h3>Import Posters from Plex</h3>
		        <button type="button" class="modal-close-btn">×</button>
		    </div>

		    <div class="plex-import-content">
		        <div id="plexConnectionStatus" style="margin-bottom: 20px; display: none;"></div>
		        
		        <!-- Plex Import Options -->
		        <div id="plexImportOptions">
		            <!-- Step 1: Media Type -->
		            <div class="import-step" id="importTypeStep">
		                <h4 style="margin-bottom: 12px;">What would you like to import?</h4>
		                <div class="directory-select" style="margin-bottom: 16px;">
		                    <select id="plexImportType" class="login-input">
		                        <option value="">Select content type...</option>
		                        <option value="movies">Movies</option>
		                        <option value="shows">TV Shows</option>
		                        <option value="seasons">TV Seasons</option>
		                        <option value="collections">Collections</option>
		                    </select>
		                </div>
		            </div>
		            
		            <!-- Step 2: Library Selection -->
		            <div class="import-step" id="librarySelectionStep" style="display: none;">
		                <h4 style="margin-bottom: 12px;">Select Library</h4>
		                <div class="directory-select" style="margin-bottom: 16px;">
		                    <select id="plexLibrary" class="login-input">
		                        <option value="">Loading libraries...</option>
		                    </select>
		                </div>
		            </div>
		            
		            <!-- New: Option to import all seasons -->
		            <div class="import-step" id="seasonsOptionsStep" style="display: none;">
		                <div style="margin-bottom: 16px;">
		                    <label class="checkbox-container" style="display: flex; align-items: center; cursor: pointer; margin-top: 8px;">
		                        <input type="checkbox" id="importAllSeasons" style="margin-right: 8px;">
		                        <span>Import all seasons from all shows (may take longer)</span>
		                    </label>
		                </div>
		            </div>
		            
		            <!-- Step 2b: Show Selection (only for seasons) -->
		            <div class="import-step" id="showSelectionStep" style="display: none;">
		                <h4 style="margin-bottom: 12px;">Select TV Show</h4>
		                <div class="directory-select" style="margin-bottom: 16px;">
		                    <select id="plexShow" class="login-input">
		                        <option value="">Loading shows...</option>
		                    </select>
		                </div>
		            </div>
		            
		            <!-- Step 3: Target Directory -->
		            <div class="import-step" id="targetDirectoryStep" style="display: none;">
		                <h4 style="margin-bottom: 12px;">Save to Directory</h4>
		                <div class="directory-select" style="margin-bottom: 16px;">
		                    <select id="targetDirectory" class="login-input">
		                        <option value="movies">Movies</option>
		                        <option value="tv-shows">TV Shows</option>
		                        <option value="tv-seasons">TV Seasons</option>
		                        <option value="collections">Collections</option>
		                    </select>
		                </div>
		            </div>
		            
		            <!-- Step 4: File Handling -->
		            <div class="import-step" id="fileHandlingStep" style="display: none;">
		                <h4 style="margin-bottom: 12px;">If file already exists</h4>
		                <div class="directory-select" style="margin-bottom: 16px;">
		                    <select id="fileHandling" class="login-input">
		                        <option value="overwrite">Update if Changed</option>
		                        <option value="copy">Create copy</option>
		                        <option value="skip">Skip</option>
		                    </select>
		                </div>
		            </div>
		            
		            <div class="import-error" style="display: none; color: var(--danger-color); background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); padding: 12px; border-radius: 6px; margin-top: 16px;"></div>
		            
		            <div class="modal-actions" style="margin-top: 32px; justify-content: end;">
		                <button type="button" id="startPlexImport" class="modal-button rename" disabled>
		                    Start Import
		                </button>
		            </div>
		        </div>
		        
		        <!-- Progress Container (hidden by default) -->
		        <div id="importProgressContainer" style="display: none; text-align: center;">              
		            <div>
		                <h3 id="importProgressStatus">Importing posters...</h3>
		                <div id="importProgressBar" style="height: 8px; background: #333; border-radius: 4px; margin: 16px 0; overflow: hidden;">
		                    <div style="height: 100%; width: 0%; background: linear-gradient(45deg, var(--accent-primary), #ff9f43); transition: width 0.3s;"></div>
		                </div>
		                <div id="importProgressDetails" style="margin-top: 10px; color: var(--text-secondary);">
		                    Processing 0 of 0 items (0%)
		                </div>
		                <p style="margin-top: 20px; color: var(--text-secondary); font-style: italic;">
		                    This process can take a while with large libraries. 
		                </p>
		                <p style="margin-top: 10px; color: var(--text-secondary); font-style: italic;">
		                    Please don't refresh or close the window until import is complete.
		                </p>
		            </div>
		        </div>
		        
		        <!-- Results Container (hidden by default) -->
		        <div id="importResultsContainer" style="display: none; text-align: center;">
		            <h3 style="margin-bottom: 16px; margin-top: 20px; display: flex; justify-content: center; align-items: center;">                    
		                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="var(--success-color)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
		                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
		                <polyline points="22 4 12 14.01 9 11.01"></polyline>
		                </svg>
		                <span style="margin-left: 10px;">Import Complete</span>
		            </h3>
		            
		            <div id="importErrors" style="display: none; margin-top: 20px; text-align: left; max-height: 150px; overflow-y: auto; padding: 12px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px;">
		                <h4 style="margin-bottom: 8px; color: var(--danger-color);">Errors:</h4>
		                <ul style="margin-left: 20px; color: var(--danger-color);"></ul>
		            </div>
		            
		            <div class="modal-actions" style="margin-top: 20px; justify-content: end;">
					<button type="button" id="closeImportResults" class="modal-button rename" onclick="document.getElementById('plexImportModal').classList.remove('show'); setTimeout(function() { document.getElementById('plexImportModal').style.display = 'none'; window.location.reload(); }, 300);">
						Close
					</button>
		            </div>
		        </div>
		    </div>
		</div>
	</div>

	<!-- Error Modal for Plex Import -->
	<div id="plexErrorModal" class="modal">
		<div class="modal-content">
		    <div class="modal-header">
		        <h3>Import Error</h3>
		        <button type="button" class="modal-close-btn">×</button>
		    </div>
		    <div class="modal-body">
		        <p id="plexErrorMessage" style="margin-bottom: 20px; color: var(--danger-color);"></p>
		        <div class="modal-actions">
		            <button type="button" class="modal-button cancel plexErrorClose">Close</button>
		        </div>
		    </div>
		</div>
	</div>

	<!-- Jellyfin Import Modal -->
	<div id="jellyfinImportModal" class="modal">
		<div class="modal-content" style="max-width: 600px;">
			<div class="modal-header">
			    <h3>Import Posters from Jellyfin</h3>
			    <button type="button" class="modal-close-btn">×</button>
			</div>

			<div class="jellyfin-import-content">
			    <div id="jellyfinConnectionStatus" style="margin-bottom: 20px; display: none;"></div>
			    
			    <!-- Jellyfin Import Options -->
			    <div id="jellyfinImportOptions">
			        <!-- Step 1: Media Type -->
			        <div class="import-step" id="jellyfinImportTypeStep">
			            <h4 style="margin-bottom: 12px;">What would you like to import?</h4>
			            <div class="directory-select" style="margin-bottom: 16px;">
			                <select id="jellyfinImportType" class="login-input">
			                    <option value="">Select content type...</option>
			                    <option value="movies">Movies</option>
			                    <option value="shows">TV Shows</option>
			                    <option value="seasons">TV Seasons</option>
			                    <option value="collections">Collections</option>
			                </select>
			            </div>
			        </div>
			        
			        <!-- Step 2: Library Selection -->
			        <div class="import-step" id="jellyfinLibrarySelectionStep" style="display: none;">
			            <h4 style="margin-bottom: 12px;">Select Library</h4>
			            <div class="directory-select" style="margin-bottom: 16px;">
			                <select id="jellyfinLibrary" class="login-input">
			                    <option value="">Loading libraries...</option>
			                </select>
			            </div>
			        </div>
			        
			        <!-- Option to import all seasons -->
			        <div class="import-step" id="jellyfinSeasonsOptionsStep" style="display: none;">
			            <div style="margin-bottom: 16px;">
			                <label class="checkbox-container" style="display: flex; align-items: center; cursor: pointer; margin-top: 8px;">
			                    <input type="checkbox" id="jellyfinImportAllSeasons" style="margin-right: 8px;">
			                    <span>Import all seasons from all shows (may take longer)</span>
			                </label>
			            </div>
			        </div>
			        
			        <!-- Step 2b: Show Selection (only for seasons) -->
			        <div class="import-step" id="jellyfinShowSelectionStep" style="display: none;">
			            <h4 style="margin-bottom: 12px;">Select TV Show</h4>
			            <div class="directory-select" style="margin-bottom: 16px;">
			                <select id="jellyfinShow" class="login-input">
			                    <option value="">Loading shows...</option>
			                </select>
			            </div>
			        </div>
			        
			        <!-- Step 3: Target Directory -->
			        <div class="import-step" id="jellyfinTargetDirectoryStep" style="display: none;">
			            <h4 style="margin-bottom: 12px;">Save to Directory</h4>
			            <div class="directory-select" style="margin-bottom: 16px;">
			                <select id="jellyfinTargetDirectory" class="login-input">
			                    <option value="movies">Movies</option>
			                    <option value="tv-shows">TV Shows</option>
			                    <option value="tv-seasons">TV Seasons</option>
			                    <option value="collections">Collections</option>
			                </select>
			            </div>
			        </div>
			        
			        <!-- Step 4: File Handling -->
			        <div class="import-step" id="jellyfinFileHandlingStep" style="display: none;">
			            <h4 style="margin-bottom: 12px;">If file already exists</h4>
			            <div class="directory-select" style="margin-bottom: 16px;">
			                <select id="jellyfinFileHandling" class="login-input">
			                    <option value="overwrite">Update if Changed</option>
			                    <option value="copy">Create copy</option>
			                    <option value="skip">Skip</option>
			                </select>
			            </div>
			        </div>
			        
			        <div class="jellyfin-import-error" style="display: none; color: var(--danger-color); background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); padding: 12px; border-radius: 6px; margin-top: 16px;"></div>
			        
			        <div class="modal-actions" style="margin-top: 32px; justify-content: end;">
			            <button type="button" id="startJellyfinImport" class="modal-button rename" disabled>
			                Start Import
			            </button>
			        </div>
			    </div>
			    
			    <!-- Progress Container (hidden by default) -->
			    <div id="jellyfinImportProgressContainer" style="display: none; text-align: center;">              
			        <div>
			            <h3 id="jellyfinImportProgressStatus">Importing posters...</h3>
			            <div id="jellyfinImportProgressBar" style="height: 8px; background: #333; border-radius: 4px; margin: 16px 0; overflow: hidden;">
			                <div style="height: 100%; width: 0%; background: linear-gradient(45deg, var(--accent-primary), #ff9f43); transition: width 0.3s;"></div>
			            </div>
			            <div id="jellyfinImportProgressDetails" style="margin-top: 10px; color: var(--text-secondary);">
			                Processing 0 of 0 items (0%)
			            </div>
			            <p style="margin-top: 20px; color: var(--text-secondary); font-style: italic;">
			                This process can take a while with large libraries. 
			            </p>
			            <p style="margin-top: 10px; color: var(--text-secondary); font-style: italic;">
			                Please don't refresh or close the window until import is complete.
			            </p>
			        </div>
			    </div>
			    
			    <!-- Results Container (hidden by default) -->
			    <div id="jellyfinImportResultsContainer" style="display: none; text-align: center;">
			        <h3 style="margin-bottom: 16px; margin-top: 20px; display: flex; justify-content: center; align-items: center;">                    
			            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="var(--success-color)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
			            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
			            <polyline points="22 4 12 14.01 9 11.01"></polyline>
			            </svg>
			            <span style="margin-left: 10px;">Import Complete</span>
			        </h3>
			        
			        <div id="jellyfinImportErrors" style="display: none; margin-top: 20px; text-align: left; max-height: 150px; overflow-y: auto; padding: 12px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px;">
			            <h4 style="margin-bottom: 8px; color: var(--danger-color);">Errors:</h4>
			            <ul style="margin-left: 20px; color: var(--danger-color);"></ul>
			        </div>
			        
			        <div class="modal-actions" style="margin-top: 20px; justify-content: end;">
			            <button type="button" id="closeJellyfinImportResults" class="modal-button rename" onclick="document.getElementById('jellyfinImportModal').classList.remove('show'); setTimeout(function() { document.getElementById('jellyfinImportModal').style.display = 'none'; window.location.reload(); }, 300);">
			                Close
			            </button>
			        </div>
			    </div>
			</div>
		</div>
	</div>

	<!-- Error Modal for Jellyfin Import -->
	<div id="jellyfinErrorModal" class="modal">
		<div class="modal-content">
			<div class="modal-header">
			    <h3>Import Error</h3>
			    <button type="button" class="modal-close-btn">×</button>
			</div>
			<div class="modal-body">
			    <p id="jellyfinErrorMessage" style="margin-bottom: 20px; color: var(--danger-color);"></p>
			    <div class="modal-actions">
			        <button type="button" class="modal-button cancel jellyfinErrorClose">Close</button>
			    </div>
			</div>
		</div>
	</div>
	
<!-- Plex Export Modal -->
<div id="plexExportModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Export Posters to Plex</h3>
            <button type="button" class="modal-close-btn">×</button>
        </div>

        <div class="plex-export-content">
            <div id="plexExportConnectionStatus" style="margin-bottom: 20px; display: none;"></div>
            
            <!-- Plex Export Options -->
            <div id="plexExportOptions">
                <!-- Step 1: Media Type -->
                <div class="export-step" id="exportTypeStep">
                    <h4 style="margin-bottom: 12px;">What would you like to export?</h4>
                    <div class="directory-select" style="margin-bottom: 16px;">
                        <select id="plexExportType" class="login-input">
                            <option value="">Select content type...</option>
                            <option value="movies">Movies</option>
                            <option value="shows">TV Shows</option>
                            <option value="seasons">TV Seasons</option>
                            <option value="collections">Collections</option>
                        </select>
                    </div>
                </div>
                
                <!-- Explanation text -->
                <div style="margin: 20px 0; padding: 15px; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px;">
                    <p style="margin: 0;">This will export all your Plex posters of the selected type to your Plex server. Only posters with "Plex" in the filename will be processed.</p>
                </div>
                
                <div class="export-error" style="display: none; color: var(--danger-color); background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); padding: 12px; border-radius: 6px; margin-top: 16px;"></div>
                
                <div class="modal-actions" style="margin-top: 32px; justify-content: end;">
                    <button type="button" id="startPlexExport" class="modal-button rename" disabled>
                        Start Export
                    </button>
                </div>
            </div>
            
            <!-- Progress Container (hidden by default) -->
            <div id="exportProgressContainer" style="display: none; text-align: center;">              
                <div>
                    <h3 id="exportProgressStatus">Exporting posters...</h3>
                    <div id="exportProgressBar" style="height: 8px; background: #333; border-radius: 4px; margin: 16px 0; overflow: hidden;">
                        <div style="height: 100%; width: 0%; background: linear-gradient(45deg, var(--accent-primary), #ff9f43); transition: width 0.3s;"></div>
                    </div>
                    <div id="exportProgressDetails" style="margin-top: 10px; color: var(--text-secondary);">
                        Processing 0 of 0 items (0%)
                    </div>
                    <p style="margin-top: 20px; color: var(--text-secondary); font-style: italic;">
                        This process can take a while with large libraries. 
                    </p>
                    <p style="margin-top: 10px; color: var(--text-secondary); font-style: italic;">
                        Please don't refresh or close the window until export is complete.
                    </p>
                </div>
            </div>
            
            <!-- Results Container (hidden by default) -->
            <div id="exportResultsContainer" style="display: none; text-align: center;">
                <h3 style="margin-bottom: 16px; margin-top: 20px; display: flex; justify-content: center; align-items: center;">                    
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="var(--success-color)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    <span style="margin-left: 10px;">Export Complete</span>
                </h3>
                
                <div id="exportErrors" style="display: none; margin-top: 20px; text-align: left; max-height: 150px; overflow-y: auto; padding: 12px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px;">
                    <h4 style="margin-bottom: 8px; color: var(--danger-color);">Errors:</h4>
                    <ul style="margin-left: 20px; color: var(--danger-color);"></ul>
                </div>
                
                <div class="modal-actions" style="margin-top: 20px; justify-content: end;">
                    <button type="button" id="closeExportResults" class="modal-button rename" onclick="document.getElementById('plexExportModal').classList.remove('show'); setTimeout(function() { document.getElementById('plexExportModal').style.display = 'none'; window.location.reload(); }, 300);">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Error Modal for Plex Export -->
<div id="plexExportErrorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Export Error</h3>
            <button type="button" class="modal-close-btn">×</button>
        </div>
        <div class="modal-body">
            <p id="plexExportErrorMessage" style="margin-bottom: 20px; color: var(--danger-color);"></p>
            <div class="modal-actions">
                <button type="button" class="modal-button cancel plexExportErrorClose">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Jellyfin Export Modal (placeholder) -->
<div id="jellyfinExportModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Export to Jellyfin</h3>
            <button type="button" class="modal-close-btn">×</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 20px;">This feature is not yet implemented. It will be available in a future update.</p>
            <div class="modal-actions">
                <button type="button" class="modal-button cancel" onclick="document.getElementById('jellyfinExportModal').classList.remove('show'); setTimeout(function() { document.getElementById('jellyfinExportModal').style.display = 'none'; }, 300);">Close</button>
            </div>
        </div>
    </div>
</div>
		    
		<div class="search-container">
		    <form class="search-form" method="GET" action="">
		        <?php if (!empty($currentDirectory)): ?>
		            <input type="hidden" name="directory" value="<?php echo htmlspecialchars($currentDirectory); ?>">
		        <?php endif; ?>
		        <input type="text" name="search" class="search-input" placeholder="Search posters..." value="<?php echo htmlspecialchars($searchQuery); ?>" autocomplete="off">
		        <button type="submit" class="search-button">Search</button>
		    </form>
		</div>

		<div class="filter-container">
		    <div class="filter-buttons">
		        <a href="?" class="filter-button <?php echo empty($currentDirectory) ? 'active' : ''; ?>">All</a>
		        <?php foreach ($config['directories'] as $dirKey => $dirPath): ?>
					<a href="?directory=<?php echo urlencode($dirKey); ?>" class="filter-button <?php echo $currentDirectory === $dirKey ? 'active' : ''; ?>">
						<?php echo formatDirectoryName($dirKey); ?>
					</a>
		        <?php endforeach; ?>
		    </div>
		</div>

		<div class="gallery-stats">
		    <?php if (!empty($searchQuery)): ?>
		        Showing <?php echo count($filteredImages); ?> of <?php echo count($allImages); ?> images
		        <a href="?<?php echo !empty($currentDirectory) ? 'directory=' . urlencode($currentDirectory) : ''; ?>">Clear search</a>
		    <?php else: ?>
		        Total images: <?php echo count($allImages); ?>
		    <?php endif; ?>
		</div>
		
			<?php if (empty($pageImages)): ?>
				<div class="no-results">
					<h2>No posters found</h2>
					<?php if (!empty($searchQuery)): ?>
						<p>No posters match your search query "<?php echo htmlspecialchars($searchQuery); ?>".</p>
					<?php else: ?>
						<p>No posters match your filter type.</p>
					<?php endif; ?>
				</div>
			<?php else: ?>
				<div class="gallery">
				    <?php foreach ($pageImages as $image): ?>
				        <div class="gallery-item">
				            <div class="gallery-image-container">
				                <?php if (empty($currentDirectory)): ?>
									<div class="directory-badge">
										<?php echo formatDirectoryName($image['directory']); ?>
									</div>
				                <?php endif; ?>
				                <div class="gallery-image-placeholder">
				                    <div class="loading-spinner"></div>
				                </div>
				                <img 
				                    src="" 
				                    alt="<?php echo htmlspecialchars(pathinfo($image['filename'], PATHINFO_FILENAME)); ?>"
				                    class="gallery-image"
				                    loading="lazy"
				                    data-src="<?php echo htmlspecialchars($image['fullpath']); ?>"
				                >
				                <div class="image-overlay-actions">
				                    <button class="overlay-action-button copy-url-btn" data-url="<?php echo htmlspecialchars($config['siteUrl'] . $image['fullpath']); ?>">
				                        <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
				                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
				                        </svg>
				                        Copy URL
				                    </button>
				                    <a href="?download=<?php echo urlencode($image['filename']); ?>&dir=<?php echo urlencode($image['directory']); ?>" class="overlay-action-button download-btn">
				                        <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
				                            <polyline points="7 10 12 15 17 10"></polyline>
				                            <line x1="12" y1="15" x2="12" y2="3"></line>
				                        </svg>
				                        Download
				                    </a>
<?php if (isLoggedIn()): ?>
    <?php 
    // Check if filename contains "Plex" to determine if we should show the Plex-related buttons
    $isPlexFile = strpos(strtolower($image['filename']), 'plex') !== false;
    
    // Only show Send to Plex button for Plex files
    if ($isPlexFile): 
    ?>
    <button class="overlay-action-button send-to-plex-btn" 
            data-filename="<?php echo htmlspecialchars($image['filename']); ?>"
            data-dirname="<?php echo htmlspecialchars($image['directory']); ?>">
            <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="17 8 12 3 7 8"></polyline>
                <line x1="12" y1="3" x2="12" y2="15"></line>
            </svg>
        Send to Plex
    </button>
    
    <!-- Import from Plex button for Plex files -->
    <button class="overlay-action-button import-from-plex-btn" 
            data-filename="<?php echo htmlspecialchars($image['filename']); ?>"
            data-dirname="<?php echo htmlspecialchars($image['directory']); ?>">
                <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                    <polyline points="8 7 3 12 8 17"></polyline>
                    <line x1="3" y1="12" x2="15" y2="12"></line>
                </svg>
        Get from Plex
    </button>
    <?php endif; ?>
    
    <button class="overlay-action-button delete-btn" 
            data-filename="<?php echo htmlspecialchars($image['filename']); ?>"
            data-dirname="<?php echo htmlspecialchars($image['directory']); ?>">
        <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
        </svg>
        Delete
    </button>
<?php endif; ?>
				                </div>
				            </div>
							<div class="gallery-caption" data-full-text="<?php echo htmlspecialchars(pathinfo($image['filename'], PATHINFO_FILENAME)); ?>">
								<?php echo htmlspecialchars(pathinfo($image['filename'], PATHINFO_FILENAME)); ?>
							</div>
				     	</div>
				    <?php endforeach; ?>
				</div>
				    
				<?php if (!empty($pageImages) && $totalPages > 1): ?>
				        <div class="pagination">
				            <?php echo generatePaginationLinks($page, $totalPages, $searchQuery, $currentDirectory); ?>
				        </div>
				<?php endif; ?>
		<?php endif; ?>
		    
				<div id="copyNotification" class="copy-notification">URL copied to clipboard!</div>
			</div>
		<!-- Delete Modal -->
		<div id="deleteModal" class="modal">
		    <div class="modal-content">
		        <div class="modal-header">
		            <h3>Confirm Deletion</h3>
		            <button type="button" class="modal-close-btn">×</button>
		        </div>
		        <p>Are you sure you want to delete this poster? This action cannot be undone.</p>
		        <form id="deleteForm" method="POST">
		            <input type="hidden" name="action" value="delete">
		            <input type="hidden" name="filename" id="deleteFilename">
		            <input type="hidden" name="directory" id="deleteDirectory">
		            <div class="modal-actions">
		                <button type="button" class="modal-button cancel" id="cancelDelete">Cancel</button>
		                <button type="submit" class="modal-button delete">Delete</button>
		            </div>
		        </form>
		    </div>
		</div>
	</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // =========== GLOBAL VARIABLES & STATE ===========
    
    // Check authentication status
    const isLoggedIn = <?php echo isLoggedIn() ? 'true' : 'false'; ?>;
    
    // State variables used for import functionality
    let plexLibraries = [];
    let plexShows = [];
    let jellyfinLibraries = [];
    let jellyfinShows = [];
    let importCancelled = false;
    
    // Global results accumulators
    let allImportResults = {
        successful: 0,
        skipped: 0,
        failed: 0,
        errors: [],
        items: []
    };
    
    let allJellyfinImportResults = {
        successful: 0,
        skipped: 0,
        failed: 0,
        errors: [],
        items: []
    };
    
    // =========== ELEMENT REFERENCES ===========
    
    // Modal elements
    const loginModal = document.getElementById('loginModal');
    const uploadModal = document.getElementById('uploadModal');
    const deleteModal = document.getElementById('deleteModal');
    const plexImportModal = document.getElementById('plexImportModal');
    const plexErrorModal = document.getElementById('plexErrorModal');
    const changePosterModal = document.getElementById('changePosterModal');
    
    // Button elements
    const showLoginButton = document.getElementById('showLoginModal');
    const showUploadButton = document.getElementById('showUploadModal');
    const showPlexImportButton = document.getElementById('showPlexImportModal');
    
    // Close buttons
    const closeLoginButton = loginModal?.querySelector('.modal-close-btn');
    const closeUploadButton = uploadModal?.querySelector('.modal-close-btn');
    const closeDeleteButton = deleteModal?.querySelector('.modal-close-btn');
    const closePlexImportButton = plexImportModal?.querySelector('.modal-close-btn');
    const closeErrorModalButton = plexErrorModal?.querySelector('.modal-close-btn');
    const closeChangePosterButton = changePosterModal?.querySelector('.modal-close-btn');
    
    // Form elements
    const loginForm = document.querySelector('.login-form');
    const deleteForm = document.getElementById('deleteForm');
    const fileChangePosterForm = document.getElementById('fileChangePosterForm');
    const urlChangePosterForm = document.getElementById('urlChangePosterForm');
    
    // Input elements
    const deleteFilenameInput = document.getElementById('deleteFilename');
    const deleteDirectoryInput = document.getElementById('deleteDirectory');
    const oldFilenameInput = document.getElementById('oldFilename');
    const newFilenameInput = document.getElementById('newFilename');
    const fileChangePosterInput = document.getElementById('fileChangePosterInput');
    
    // Error elements
    const loginError = document.querySelector('.login-error');
    const changeError = document.querySelector('.change-poster-error');
    
    // Notification elements
    const copyNotification = document.getElementById('copyNotification');
    
    // Search elements
    const searchForm = document.querySelector('.search-form');
    const searchInput = document.querySelector('.search-input');
    
    // =========== GENERIC MODAL FUNCTIONS ===========
    
    function showModal(modal) {
        if (modal) {
            modal.style.display = 'block';
            modal.offsetHeight; // Force reflow
            setTimeout(() => {
                modal.classList.add('show');
            }, 10);
        }
    }

    function hideModal(modal, form = null) {
        if (modal) {
            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
                if (form) form.reset();
            }, 300);
        }
    }
    
    // Handle escape key for modals
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (loginModal?.classList.contains('show')) hideModal(loginModal, loginForm);
            if (uploadModal?.classList.contains('show')) hideModal(uploadModal);
            
            // Don't close import modals if import is in progress
            if (plexImportModal?.classList.contains('show') && 
                document.getElementById('importProgressContainer')?.style.display !== 'block') {
                hideModal(plexImportModal);
            }
            
            if (jellyfinImportModal?.classList.contains('show') && 
                document.getElementById('jellyfinImportProgressContainer')?.style.display !== 'block') {
                hideModal(jellyfinImportModal);
            }
            
            if (deleteModal?.classList.contains('show')) hideModal(deleteModal, deleteForm);
            if (plexErrorModal?.classList.contains('show')) hideModal(plexErrorModal);
            if (jellyfinErrorModal?.classList.contains('show')) hideModal(jellyfinErrorModal);
            if (changePosterModal?.classList.contains('show')) hideModal(changePosterModal);
        }
    });
    
    // =========== NOTIFICATION SYSTEM ===========
    
    // Generic notification function
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `plex-notification plex-${type}`;
        
        let iconHtml = '';
        if (type === 'success') {
            iconHtml = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>`;
        } else if (type === 'error') {
            iconHtml = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>`;
        } else if (type === 'loading') {
            iconHtml = `<div class="plex-spinner"></div>`;
        }
        
        notification.innerHTML = `
            <div class="plex-notification-content">
                ${iconHtml}
                <span>${message}</span>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Force reflow to trigger animation
        notification.offsetHeight;
        notification.classList.add('show');
        
        // Auto-remove after 3 seconds for success/error notifications
        if (type !== 'loading') {
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    notification.remove();
                }, 300); // Match transition duration
            }, 3000);
        }
        
        return notification;
    }
    
    // Show copy notification
    function showCopyNotification() {
        copyNotification.style.display = 'block';
        copyNotification.classList.add('show');
        
        setTimeout(() => {
            copyNotification.classList.remove('show');
            setTimeout(() => {
                copyNotification.style.display = 'none';
            }, 300);
        }, 2000);
    }
    
    // =========== LOGIN MODAL ===========
    
    if (showLoginButton && loginModal) {
        function showLoginModal() {
            showModal(loginModal);
            if (loginError) loginError.style.display = 'none';
            
            // Focus on username input
            setTimeout(() => {
                const usernameInput = loginModal.querySelector('input[name="username"]');
                if (usernameInput) usernameInput.focus();
            }, 100); // Short delay to ensure modal is fully visible
        }

        function hideLoginModal() {
            hideModal(loginModal, loginForm);
            if (loginError) loginError.style.display = 'none';
        }

        showLoginButton.addEventListener('click', showLoginModal);
        closeLoginButton?.addEventListener('click', hideLoginModal);
        
        loginModal.addEventListener('click', (e) => {
            if (e.target === loginModal) hideLoginModal();
        });
        
        // Login form submission handling
        if (loginForm) {
            loginForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(loginForm);
                
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        window.location.reload();
                    } else {
                        loginError.textContent = data.error;
                        loginError.style.display = 'block';
                    }
                } catch (error) {
                    loginError.textContent = 'An error occurred during login';
                    loginError.style.display = 'block';
                }
            });
        }
    }
    
    // =========== UPLOAD MODAL ===========
    
    // Upload error handling
    const uploadError = document.querySelector('.upload-error');
    
    function showUploadError(message) {
        if (uploadError) {
            uploadError.textContent = message;
            uploadError.style.display = 'block';
        }
    }
    
    function hideUploadError() {
        if (uploadError) {
            uploadError.style.display = 'none';
            uploadError.textContent = '';
        }
    }
    
    // File input handling
    const fileInput = document.getElementById('fileInput');
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || 'No file chosen';
            const fileNameElement = this.parentElement.querySelector('.file-name');
            if (fileNameElement) {
                fileNameElement.textContent = fileName;
            }
        });
    }
    
    if (showUploadButton && uploadModal && isLoggedIn) {
        function showUploadModal() {
            showModal(uploadModal);
        }

        function hideUploadModal() {
            hideModal(uploadModal);
        }

        showUploadButton.addEventListener('click', showUploadModal);
        closeUploadButton?.addEventListener('click', hideUploadModal);
        
        uploadModal.addEventListener('click', (e) => {
            if (e.target === uploadModal) hideUploadModal();
        });
        
        // Upload tabs functionality
        const uploadTabs = document.querySelectorAll('.upload-tab-btn');
        const uploadForms = document.querySelectorAll('.upload-form');
        
        uploadTabs.forEach(button => {
            button.addEventListener('click', () => {
                const tabName = button.getAttribute('data-tab');
                
                // Update active tab button
                uploadTabs.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
                
                // Show active form
                uploadForms.forEach(form => {
                    if (form.id === tabName + 'UploadForm') {
                        form.classList.add('active');
                    } else {
                        form.classList.remove('active');
                    }
                });
                
                // Clear any errors when switching tabs
                hideUploadError();
            });
        });
        
        // Clear error when switching tabs in upload modal
        document.querySelectorAll('.upload-tab-btn').forEach(button => {
            button.addEventListener('click', () => {
                hideUploadError();
            });
        });
        
        // Update file upload form handler
        if (document.getElementById('fileUploadForm')) {
            document.getElementById('fileUploadForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                hideUploadError();
                
                const formData = new FormData(this);
                const fileInput = this.querySelector('input[type="file"]');
                
                // Validate file type
                if (fileInput.files.length > 0) {
                    const file = fileInput.files[0];
                    const ext = file.name.split('.').pop().toLowerCase();
                    const allowedExtensions = <?php echo json_encode($config['allowedExtensions']); ?>;
                    
                    if (!allowedExtensions.includes(ext)) {
                        showUploadError('Invalid file type. Allowed types: ' + allowedExtensions.join(', '));
                        return;
                    }
                }
                
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Only hide modal and reload on success
                        hideModal(uploadModal);
                        window.location.reload();
                    } else {
                        // Keep modal open and show error
                        showUploadError(data.error || 'Upload failed');
                        
                        // Reset file input on failure while keeping modal open
                        fileInput.value = '';
                        const fileNameElement = fileInput.parentElement.querySelector('.file-name');
                        if (fileNameElement) {
                            fileNameElement.textContent = '';
                        }
                    }
                } catch (error) {
                    showUploadError('An error occurred during upload');
                    
                    // Reset file input on error while keeping modal open
                    fileInput.value = '';
                    const fileNameElement = fileInput.parentElement.querySelector('.file-name');
                    if (fileNameElement) {
                        fileNameElement.textContent = '';
                    }
                }
            });
        }

        // Update URL upload form handler
        if (document.getElementById('urlUploadForm')) {
            document.getElementById('urlUploadForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                hideUploadError();
                
                const formData = new FormData(this);
                const imageUrl = formData.get('image_url');
                const urlInput = this.querySelector('input[name="image_url"]');
                
                // Basic URL validation
                try {
                    const url = new URL(imageUrl);
                    const ext = url.pathname.split('.').pop().toLowerCase();
                    const allowedExtensions = <?php echo json_encode($config['allowedExtensions']); ?>;
                    
                    if (!allowedExtensions.includes(ext)) {
                        showUploadError('Invalid file type. Allowed types: ' + allowedExtensions.join(', '));
                        return;
                    }
                } catch (error) {
                    showUploadError('Invalid URL format');
                    return;
                }
                
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Only hide modal and reload on success
                        hideModal(uploadModal);
                        window.location.reload();
                    } else {
                        // Keep modal open and show error
                        showUploadError(data.error || 'Upload failed');
                        
                        // Clear URL input on failure while keeping modal open
                        urlInput.value = '';
                    }
                } catch (error) {
                    showUploadError('An error occurred during upload');
                    
                    // Clear URL input on error while keeping modal open
                    urlInput.value = '';
                }
            });
        }
    }
    
    // =========== DELETE MODAL ===========
    
    if (deleteModal) {
        // Fix close button
        const cancelDeleteBtn = document.getElementById('cancelDelete');
        if (cancelDeleteBtn) {
            cancelDeleteBtn.addEventListener('click', () => {
                hideModal(deleteModal, deleteForm);
            });
        }

        // Close when clicking outside the modal
        deleteModal.addEventListener('click', (e) => {
            if (e.target === deleteModal) {
                hideModal(deleteModal, deleteForm);
            }
        });
        
        // Fix close button
        if (closeDeleteButton) {
            closeDeleteButton.addEventListener('click', () => {
                hideModal(deleteModal, deleteForm);
            });
        }
        
        // Handle form submission
        if (deleteForm) {
            deleteForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(deleteForm);
                
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        hideModal(deleteModal);
                        window.location.reload();
                    } else {
                        alert(data.error || 'Failed to delete file');
                    }
                } catch (error) {
                    alert('An error occurred while deleting the file');
                }
            });
        }
    }
    
    // =========== CHANGE POSTER MODAL ===========

    // Change Poster error handling functions
    function showChangeError(message) {
        if (changeError) {
            changeError.textContent = message;
            changeError.style.display = 'block';
        }
    }
    
    function hideChangeError() {
        if (changeError) {
            changeError.textContent = '';
            changeError.style.display = 'none';
        }
    }
    
    // File input change handler
    if (fileChangePosterInput) {
        fileChangePosterInput.addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || '';
            const fileNameElement = this.parentElement.querySelector('.file-name');
            if (fileNameElement) {
                fileNameElement.textContent = fileName;
            }
            
            // Enable/disable submit button based on file selection
            const submitButton = fileChangePosterForm.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = !fileName;
            }
        });
    }
    
    // Tab functionality for the Change Poster modal
    if (changePosterModal) {
        const changePosterTabs = changePosterModal.querySelectorAll('.upload-tab-btn');
        const changePosterForms = changePosterModal.querySelectorAll('.upload-form');
        
        changePosterTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const tabName = this.getAttribute('data-tab');
                
                // Update active tab
                changePosterTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Show active form
                changePosterForms.forEach(form => {
                    if (form.id === tabName + 'ChangePosterForm') {
                        form.classList.add('active');
                    } else {
                        form.classList.remove('active');
                    }
                });
                
                // Clear any error messages
                hideChangeError();
            });
        });
        
        // Form submission handler - File upload
		if (fileChangePosterForm) {
			fileChangePosterForm.addEventListener('submit', async function(e) {
				e.preventDefault();
				
				// Show a loading notification
				const notification = showNotification('Replacing poster...', 'loading');
				
				try {
				    const formData = new FormData(this);
				    
				    const response = await fetch('./include/change-poster.php', {
				        method: 'POST',
				        body: formData
				    });
				    
				    const data = await response.json();
				    
				    if (data.success) {
				        // Remove the loading notification first
				        notification.remove();
				        
				        // Hide the modal
				        hideModal(changePosterModal);
				        
				        // Determine the message based on Plex update status
				        let message = 'Poster successfully replaced!';
				        if (data.plexUpdated) {
				            message = 'Poster replaced and updated in Plex!';
				        }
				        
				        // Show success notification
				        showNotification(message, 'success');
				        
				        // Refresh the image to show the updated version
				        const filename = formData.get('original_filename');
				        const directory = formData.get('directory');
				        refreshImage(filename, directory);
				    } else {
				        // Show error in modal
				        showChangeError(data.error || 'Failed to change poster');
				        notification.remove();
				    }
				} catch (error) {
				    // Show error in modal
				    showChangeError('Error replacing poster: ' + error.message);
				    notification.remove();
				}
			});
		}
        
        // Form submission handler - URL upload
		if (urlChangePosterForm) {
			urlChangePosterForm.addEventListener('submit', async function(e) {
				e.preventDefault();
				
				// Show a loading notification
				const notification = showNotification('Replacing poster from URL...', 'loading');
				
				try {
				    const formData = new FormData(this);
				    
				    const response = await fetch('./include/change-poster.php', {
				        method: 'POST',
				        body: formData
				    });
				    
				    const data = await response.json();
				    
				    if (data.success) {
				        // Remove the loading notification first
				        notification.remove();
				        
				        // Hide the modal
				        hideModal(changePosterModal);
				        
				        // Determine the message based on Plex update status
				        let message = 'Poster successfully replaced!';
				        if (data.plexUpdated) {
				            message = 'Poster replaced and updated in Plex!';
				        }
				        
				        // Show success notification
				        showNotification(message, 'success');
				        
				        // Refresh the image to show the updated version
				        const filename = formData.get('original_filename');
				        const directory = formData.get('directory');
				        refreshImage(filename, directory);
				    } else {
				        // Show error in modal
				        showChangeError(data.error || 'Failed to change poster from URL');
				        notification.remove();
				    }
				} catch (error) {
				    // Show error in modal
				    showChangeError('Error replacing poster: ' + error.message);
				    notification.remove();
				}
			});
		}
        
        // Modal close handlers
        if (closeChangePosterButton) {
            closeChangePosterButton.addEventListener('click', function() {
                hideModal(changePosterModal);
            });
        }
        
        // Click outside to close
        changePosterModal.addEventListener('click', function(e) {
            if (e.target === changePosterModal) {
                hideModal(changePosterModal);
            }
        });
    }
    
    // =========== PLEX IMPORT MODAL ===========
    
    // Only initialize if user is logged in and elements exist
    if (isLoggedIn && showPlexImportButton && plexImportModal) {
        // Plex-specific elements
        const startPlexImportButton = document.getElementById('startPlexImport');
        const importTypeSelect = document.getElementById('plexImportType');
        const librarySelect = document.getElementById('plexLibrary');
        const showSelect = document.getElementById('plexShow');
        const targetDirectorySelect = document.getElementById('targetDirectory');
        const fileHandlingSelect = document.getElementById('fileHandling');
        
        // Step containers
        const importTypeStep = document.getElementById('importTypeStep');
        const librarySelectionStep = document.getElementById('librarySelectionStep');
        const showSelectionStep = document.getElementById('showSelectionStep');
        const seasonsOptionsStep = document.getElementById('seasonsOptionsStep');
        const targetDirectoryStep = document.getElementById('targetDirectoryStep');
        const fileHandlingStep = document.getElementById('fileHandlingStep');
        
        // Progress and results elements
        const importProgressContainer = document.getElementById('importProgressContainer');
        const importProgressBar = document.getElementById('importProgressBar')?.querySelector('div');
        const importProgressDetails = document.getElementById('importProgressDetails');
        const importResultsContainer = document.getElementById('importResultsContainer');
        const importOptionsContainer = document.getElementById('plexImportOptions');
        
        // Error handling
        const importErrorContainer = document.querySelector('.import-error');
        const plexErrorMessage = document.getElementById('plexErrorMessage');
        const plexErrorCloseButtons = document.querySelectorAll('.plexErrorClose');
        
        // Results elements
        const closeResultsButton = document.getElementById('closeImportResults');
        const importErrors = document.getElementById('importErrors');
        
        // Connection status
        const connectionStatus = document.getElementById('plexConnectionStatus');
        
        // Show/hide Plex Import Modal functions
        function showPlexModal() {
            showModal(plexImportModal);
            
            // Reset the form to a clean state
            resetPlexImport();
            
            // Just test the connection
            testPlexConnection();
        }

        function hidePlexModal() {
            hideModal(plexImportModal);
            resetPlexImport();
        }
        
        function showErrorModal(message) {
            plexErrorMessage.textContent = message;
            showModal(plexErrorModal);
        }
        
        function hideErrorModal() {
            hideModal(plexErrorModal);
        }
        
        // Reset the import form
        function resetPlexImport() {
            // Show the close button again when results are shown
            const closeButton = plexImportModal.querySelector('.modal-close-btn');
            if (closeButton) {
                closeButton.style.display = 'block';
                
                // Ensure close button has proper event handler
                closeButton.addEventListener('click', function() {
                    hideModal(plexImportModal);
                    // Force a page refresh to ensure a clean state
                    setTimeout(function() {
                        window.location.reload();
                    }, 300);
                });
            }
                
            // Reset form selections
            importTypeSelect.value = '';
            librarySelect.innerHTML = '<option value="">Select a content type first...</option>';
            showSelect.innerHTML = '<option value="">Select a library first...</option>';
            targetDirectorySelect.value = 'movies';
            fileHandlingSelect.value = 'overwrite';
            
            // Hide all steps except type
            importTypeStep.style.display = 'block';
            librarySelectionStep.style.display = 'none';
            showSelectionStep.style.display = 'none';
            seasonsOptionsStep.style.display = 'none';
            targetDirectoryStep.style.display = 'none';
            fileHandlingStep.style.display = 'none';
            
            // Reset containers
            importProgressContainer.style.display = 'none';
            importResultsContainer.style.display = 'none';
            importOptionsContainer.style.display = 'block';
            
            // Hide error container instead of just clearing it
            importErrorContainer.style.display = 'none';
            importErrorContainer.textContent = '';
            
            // Disable start button
            startPlexImportButton.disabled = true;
            
            // Reset progress
            if (importProgressBar) {
                importProgressBar.style.width = '0%';
            }
            importProgressDetails.textContent = 'Processing 0 of 0 items (0%)';
            
            // Reset import cancelled flag
            importCancelled = false;
        }
        
        // Test Plex connection and display status
        async function testPlexConnection() {
            connectionStatus.style.display = 'block';
            connectionStatus.innerHTML = `
                <div style="padding: 10px; border-radius: 4px; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary);">
                    <span style="display: inline-block; margin-right: 8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </span>
                    Testing connection to Plex server...
                </div>
            `;
            
            try {
                const response = await fetch('./include/plex-import.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'test_plex_connection'
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    connectionStatus.innerHTML = `
                        <div style="padding: 10px; border-radius: 4px; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--success-color);">
                            <span style="display: inline-block; margin-right: 8px; color: var(--success-color);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                </svg>
                            </span>
                            Connected to Plex server
                        </div>
                    `;
                    
                    // Load libraries if connection successful
                    loadPlexLibraries();
                } else {
                    connectionStatus.innerHTML = `
                        <div style="padding: 10px; border-radius: 4px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color);">
                            <span style="display: inline-block; margin-right: 8px; color: var(--danger-color);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="8" x2="12" y2="12"></line>
                                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                </svg>
                            </span>
                            Failed to connect to Plex server: ${data.error}
                        </div>
                    `;
                    
                    // Disable the form if connection failed
                    startPlexImportButton.disabled = true;
                }
            } catch (error) {
                connectionStatus.innerHTML = `
                    <div style="padding: 10px; border-radius: 4px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color);">
                        <span style="display: inline-block; margin-right: 8px; color: var(--danger-color);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        </span>
                        Error connecting to Plex server: ${error.message}
                    </div>
                `;
                
                // Disable the form if connection error
                startPlexImportButton.disabled = true;
            }
        }
        
        // Load Plex libraries based on import type
        async function loadPlexLibraries() {
            // Get the currently selected import type
            const importType = importTypeSelect.value;
            
            // If no import type is selected, don't try to load libraries
            if (!importType) {
                librarySelect.innerHTML = '<option value="">Select a content type first...</option>';
                return;
            }
            
            librarySelect.innerHTML = '<option value="">Loading libraries...</option>';
            
            try {
                const response = await fetch('./include/plex-import.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'get_plex_libraries'
                    })
                });
                
                const data = await response.json();
                
                if (data.success && data.data.length > 0) {
                    plexLibraries = data.data;
                    librarySelect.innerHTML = '<option value="">Select library...</option>';
                    
                    let matchingLibrariesCount = 0;
                    
                    plexLibraries.forEach(library => {
                        // Filter libraries based on import type
                        const showLibrary = (
                            // For movies, only show movie libraries
                            (importType === 'movies' && library.type === 'movie') ||
                            // For shows or seasons, only show TV show libraries
                            ((importType === 'shows' || importType === 'seasons') && library.type === 'show') ||
                            // For collections, show both
                            (importType === 'collections')
                        );
                        
                        if (showLibrary) {
                            matchingLibrariesCount++;
                            const option = document.createElement('option');
                            option.value = library.id;
                            option.dataset.type = library.type;
                            option.textContent = `${library.title} (${library.type === 'movie' ? 'Movies' : 'TV Shows'})`;
                            librarySelect.appendChild(option);
                        }
                    });
                    
                    // If no libraries match the filter
                    if (matchingLibrariesCount === 0) {
                        librarySelect.innerHTML = '<option value="">No matching libraries found</option>';
                        showErrorInImportOptions('No libraries of the required type were found');
                    }
                } else {
                    librarySelect.innerHTML = '<option value="">No libraries found</option>';
                    showErrorInImportOptions(data.error || 'No libraries found on Plex server');
                }
            } catch (error) {
                librarySelect.innerHTML = '<option value="">Error loading libraries</option>';
                showErrorInImportOptions('Error loading Plex libraries: ' + error.message);
            }
        }
        
        // Load shows for a specific library (for TV season selection)
        async function loadPlexShows(libraryId) {
            showSelect.innerHTML = '<option value="">Loading shows...</option>';
            
            try {
                const response = await fetch('./include/plex-import.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'get_plex_shows_for_seasons',
                        'libraryId': libraryId
                    })
                });
                
                const data = await response.json();
                
                if (data.success && data.data.length > 0) {
                    plexShows = data.data;
                    showSelect.innerHTML = '<option value="">Select show...</option>';
                    
                    plexShows.forEach(show => {
                        const option = document.createElement('option');
                        option.value = show.ratingKey;
                        option.textContent = show.title + (show.year ? ` (${show.year})` : '');
                        showSelect.appendChild(option);
                    });
                } else {
                    showSelect.innerHTML = '<option value="">No shows found</option>';
                    showErrorInImportOptions(data.error || 'No shows found in the selected library');
                }
            } catch (error) {
                showSelect.innerHTML = '<option value="">Error loading shows</option>';
                showErrorInImportOptions('Error loading shows: ' + error.message);
            }
        }
        
        // Display error in import options container
        function showErrorInImportOptions(message) {
            // Only show errors if we have an actual message and if the user has started making selections
            if (message && importTypeSelect.value) {
                importErrorContainer.textContent = message;
                importErrorContainer.style.display = 'block';
            }
        }

        function hideErrorInImportOptions() {
            importErrorContainer.style.display = 'none';
            importErrorContainer.textContent = '';
        }

        // Helper function to check if the user has started making selections
        function hasUserStartedSelections() {
            return importTypeSelect.value !== '';
        }
        
        // Validate import options and enable/disable start button
        function validateImportOptions() {
            const importType = importTypeSelect.value;
            const libraryId = librarySelect.value;
            const showId = showSelect.value;
            const importAllSeasons = document.getElementById('importAllSeasons')?.checked || false;
            
            let isValid = false;
            
            if (!importType || !libraryId) {
                isValid = false;
            } else if (importType === 'seasons') {
                // If importing all seasons, we just need a valid library
                // If importing specific show seasons, we need both library and show
                isValid = importAllSeasons ? true : (showId ? true : false);
            } else {
                // For all other types, just need a valid library ID
                isValid = true;
            }
            
            startPlexImportButton.disabled = !isValid;
            return isValid;
        }
        
        // Checkbox handler for "Import all seasons"
        const importAllSeasonsCheckbox = document.getElementById('importAllSeasons');
        if (importAllSeasonsCheckbox) {
            importAllSeasonsCheckbox.addEventListener('change', function() {
                const showSelectionStep = document.getElementById('showSelectionStep');
                
                if (this.checked) {
                    // Hide show selection when "Import all seasons" is checked
                    showSelectionStep.style.display = 'none';
                } else {
                    // Show the show selection step if we have a library selected
                    const libraryId = librarySelect.value;
                    if (libraryId) {
                        const selectedOption = librarySelect.options[librarySelect.selectedIndex];
                        const libraryType = selectedOption ? selectedOption.dataset.type : '';
                        
                        if (libraryType === 'show') {
                            showSelectionStep.style.display = 'block';
                            // Load shows for the library if they're not already loaded
                            if (showSelect.options.length <= 1) {
                                loadPlexShows(libraryId);
                            }
                        }
                    }
                }
                
                validateImportOptions();
            });
        }
        
        // Handle import type selection
        importTypeSelect.addEventListener('change', function() {
            const selectedType = this.value;
            
            // Reset other steps
            librarySelectionStep.style.display = 'none';
            seasonsOptionsStep.style.display = 'none';
            showSelectionStep.style.display = 'none';
            targetDirectoryStep.style.display = 'none';
            fileHandlingStep.style.display = 'none';
            
            // Reset selects with appropriate default messages
            librarySelect.innerHTML = '<option value="">Loading libraries...</option>';
            showSelect.innerHTML = '<option value="">Select a library first...</option>';
            
            // Hide any previous error messages
            hideErrorInImportOptions();
            
            if (selectedType) {
                // Show library selection step
                librarySelectionStep.style.display = 'block';
                
                // Now it's appropriate to load libraries since user has selected a type
                loadPlexLibraries();
                
                // Pre-select target directory based on import type
                switch (selectedType) {
                    case 'movies':
                        targetDirectorySelect.value = 'movies';
                        break;
                    case 'shows':
                        targetDirectorySelect.value = 'tv-shows';
                        break;
                    case 'seasons':
                        targetDirectorySelect.value = 'tv-seasons';
                        // Show seasons options step
                        seasonsOptionsStep.style.display = 'block';
                        break;
                    case 'collections':
                        targetDirectorySelect.value = 'collections';
                        break;
                }
                
                // Show file handling step
                fileHandlingStep.style.display = 'block';
            } else {
                // If user clears the selection, reset the form
                librarySelect.innerHTML = '<option value="">Select a content type first...</option>';
                hideErrorInImportOptions();
                fileHandlingStep.style.display = 'none';
            }
            
            validateImportOptions();
        });
        
        // Handle "Import all seasons" checkbox change
        document.getElementById('importAllSeasons').addEventListener('change', function() {
            const showSelectionStep = document.getElementById('showSelectionStep');
            
            if (this.checked) {
                // Hide show selection when "Import all seasons" is checked
                showSelectionStep.style.display = 'none';
            } else {
                // Show the show selection step if we have a library selected
                const libraryId = librarySelect.value;
                if (libraryId) {
                    const selectedOption = librarySelect.options[librarySelect.selectedIndex];
                    const libraryType = selectedOption ? selectedOption.dataset.type : '';
                    
                    if (libraryType === 'show') {
                        showSelectionStep.style.display = 'block';
                    }
                }
            }
            
            validateImportOptions();
        });
        
        // Handle library selection
        librarySelect.addEventListener('change', function() {
            const selectedLibraryId = this.value;
            const selectedOption = this.options[this.selectedIndex];
            const libraryType = selectedOption ? selectedOption.dataset.type : '';
            
            // Reset show selection
            showSelectionStep.style.display = 'none';
            showSelect.innerHTML = '<option value="">Loading shows...</option>';
            
            // Hide error messages
            hideErrorInImportOptions();
            
            if (selectedLibraryId) {
                // If importing seasons, show the show selection step
                if (importTypeSelect.value === 'seasons') {
                    if (libraryType === 'show') {
                        showSelectionStep.style.display = 'block';
                        loadPlexShows(selectedLibraryId);
                    } else {
                        showErrorInImportOptions('Please select a TV Show library to import seasons');
                    }
                }
            }
            
            validateImportOptions();
        });
        
        // Handle show selection
        showSelect.addEventListener('change', function() {
            validateImportOptions();
        });
        
        // Start the import process
        startPlexImportButton.addEventListener('click', async function() {
            if (!validateImportOptions()) {
                return;
            }
            
            // Get selected options
            const importType = importTypeSelect.value;
            const libraryId = librarySelect.value;
            const importAllSeasons = document.getElementById('importAllSeasons')?.checked || false;
            
            // Only get showKey if we're not importing all seasons
            const showKey = (importType === 'seasons' && !importAllSeasons) ? showSelect.value : null;
            
            const targetDirectory = targetDirectorySelect.value;
            const overwriteOption = fileHandlingSelect.value;
            
            // Hide the close button when starting the import process
            const closeButton = plexImportModal.querySelector('.modal-close-btn');
            if (closeButton) {
                closeButton.style.display = 'none';
            }
            
            // Show progress container, hide options
            importOptionsContainer.style.display = 'none';
            importProgressContainer.style.display = 'block';
            
            // Start import process
            try {
                await importPlexPosters(importType, libraryId, showKey, targetDirectory, overwriteOption, importAllSeasons);
            } catch (error) {
                // Show the close button again on error
                if (closeButton) {
                    closeButton.style.display = 'block';
                }
                
                // Hide the progress container
                importProgressContainer.style.display = 'none';
                importOptionsContainer.style.display = 'block';
                
                // Show error
                showErrorInImportOptions('Import failed: ' + error.message);
            }
        });
        
        // Import posters from Plex
        async function importPlexPosters(type, libraryId, showKey, contentType, overwriteOption, importAllSeasons) {
            // Configure initial request
            const initialParams = {
                'action': 'import_plex_posters',
                'type': type,
                'libraryId': libraryId,
                'contentType': contentType,
                'overwriteOption': overwriteOption,
                'batchProcessing': 'true',
                'startIndex': 0
            };
            
            // Add showKey for seasons import (when not importing all)
            if (type === 'seasons' && !importAllSeasons && showKey) {
                initialParams.showKey = showKey;
            }
            
            // Add importAllSeasons parameter if true
            if (type === 'seasons' && importAllSeasons) {
                initialParams.importAllSeasons = 'true';
            }
            
            let isComplete = false;
            let currentIndex = 0;
            const results = {
                successful: 0,
                skipped: 0,
                failed: 0,
                errors: []
            };
            
            // Stats dashboard in the modal
            const statsDashboard = `
            <div id="importStatsDashboard" style="margin-top: 20px; display: flex; justify-content: space-between; text-align: center; gap: 10px;">
                <div style="flex: 1; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--success-color); border-radius: 6px; padding: 12px;">
                    <div style="font-size: 24px; font-weight: bold; color: var(--success-color);" id="statsSuccessful">0</div>
                    <div style="color: var(--text-primary);">Successful</div>
                </div>
                <div style="flex: 1; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px; padding: 12px;">
                    <div style="font-size: 24px; font-weight: bold; color: var(--accent-primary);" id="statsSkipped">0</div>
                    <div style="color: var(--text-primary);">Skipped</div>
                </div>
                <div style="flex: 1; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px; padding: 12px;">
                    <div style="font-size: 24px; font-weight: bold; color: var(--danger-color);" id="statsFailed">0</div>
                    <div style="color: var(--text-primary);">Failed</div>
                </div>
            </div>
            `;
            
            // Add stats dashboard to progress container
            if (!document.getElementById('importStatsDashboard')) {
                document.getElementById('importProgressDetails').insertAdjacentHTML('afterend', statsDashboard);
            }
            
            // Update progress message for all seasons import
            if (type === 'seasons' && importAllSeasons) {
                document.getElementById('importProgressStatus').textContent = 'Importing season posters from all shows...';
            }
            
            const allSkippedDetails = []; // Array to store results
            
            // While not complete and not cancelled
            while (!isComplete && !importCancelled) {
                try {
                    const formData = new FormData();
                    
                    // Add all parameters
                    for (const [key, value] of Object.entries({
                        ...initialParams,
                        'startIndex': currentIndex,
                        'totalSuccessful': results.successful,
                        'totalSkipped': results.skipped,
                        'totalFailed': results.failed
                    })) {
                        formData.append(key, value);
                    }
                    
                    const response = await fetch('./include/plex-import.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (!data.success) {
                        throw new Error(data.error || 'Unknown error during import');
                    }
                    
                    // Update progress
                    if (data.batchComplete) {
                        // For "Import all seasons", show which show is being processed
                        if (type === 'seasons' && importAllSeasons && data.progress.currentShow) {
                            document.getElementById('importProgressStatus').textContent = 
                                `Importing seasons from: ${data.progress.currentShow}`;
                            
                            // Season progress details
                            if (data.progress.seasonCount !== undefined) {
                                document.getElementById('importProgressDetails').innerHTML = 
                                    `Processing show ${data.progress.processed} of ${data.progress.total} (${data.progress.percentage}%)<br>` +
                                    `Found ${data.progress.seasonCount} seasons in current show`;
                            } else {
                                document.getElementById('importProgressDetails').textContent = 
                                    `Processing show ${data.progress.processed} of ${data.progress.total} (${data.progress.percentage}%)`;
                            }
                        } else {
                            // Regular batch progress
                            const percentage = data.progress.percentage;
                            if (importProgressBar) {
                                importProgressBar.style.width = `${percentage}%`;
                            }
                            
                            // Update progress text
                            document.getElementById('importProgressDetails').textContent = 
                                `Processing ${data.progress.processed} of ${data.progress.total} items (${percentage}%)`;
                        }
                        
                        // Update progress bar for all cases
                        const percentage = data.progress.percentage;
                        if (importProgressBar) {
                            importProgressBar.style.width = `${percentage}%`;
                        }
                        
                        // Track results
                        if (data.results) {
                            results.successful += data.results.successful;
                            results.skipped += data.results.skipped;
                            results.failed += data.results.failed;
                            
                            // Concat any errors
                            if (data.results.errors && data.results.errors.length) {
                                results.errors = [...results.errors, ...data.results.errors];
                            }
                        }
                        
                        if (data.results && data.results.skippedDetails) {
                            allSkippedDetails.push(...data.results.skippedDetails); // Spread to merge arrays
                        }
                        
                        // Update stats dashboard with the latest totals
                        updateStatsDashboard(results, allSkippedDetails);
                        
                        // Check if complete
                        isComplete = data.progress.isComplete;
                        currentIndex = data.progress.nextIndex || 0;
                    } else {
                        // Handle non-batch processing result
                        isComplete = true;
                        
                        if (data.results) {
                            results.successful = data.results.successful;
                            results.skipped = data.results.skipped;
                            results.failed = data.results.failed;
                            results.errors = data.results.errors || [];
                        }
                        
                        // Update stats dashboard
                        updateStatsDashboard(data.totalStats, allSkippedDetails || results, allSkippedDetails);
                    }
                    
                    // If complete, show results
                    if (isComplete) {
                        // Update status text for results
                        document.getElementById('importProgressStatus').textContent = 'Import complete!';
                        
                        // Small delay before showing the results screen
                        setTimeout(() => {
                            showImportResults(results, allSkippedDetails);
                        }, 500);
                    }
                } catch (error) {
                    // Stop processing and show error
                    throw error;
                }
            }
            
            return results;
        }
        
        // Update the stats dashboard with the current totals
        function updateStatsDashboard(stats) {
            document.getElementById('statsSuccessful').textContent = stats.successful;
            document.getElementById('statsSkipped').textContent = stats.skipped;
            document.getElementById('statsFailed').textContent = stats.failed;
        }
        
        // Show import results
        function showImportResults(results, skipped) {
            // Accumulate results from all batches
            if (results) {
                // Update counts
                allImportResults.successful += results.successful || 0;
                allImportResults.skipped += results.skipped || 0;
                allImportResults.failed += results.failed || 0;

                // Accumulate errors
                if (results.errors && results.errors.length > 0) {
                    allImportResults.errors = allImportResults.errors.concat(results.errors);
                }
            }

            // Accumulate items from this batch
            if (skipped && skipped.length > 0) {
                allImportResults.items = allImportResults.items.concat(skipped);
            }

            // Hide progress container
            importProgressContainer.style.display = 'none';
            
            // Prepare details HTML if accumulated results exist
            let skippedDetailsHtml = '';
            if (allImportResults.items.length > 0) {
                const skippedDetailsContent = allImportResults.items.map(function(item, index) {
                    // Truncate long filenames, keeping the collection name more visible
                    const truncateFilename = (filename) => {
                        const match = filename.match(/(.+) \[([a-f0-9]+)\]/);
                        if (match) {
                            const [, collectionName, hash] = match;
                            return `${collectionName} [${hash.substring(0, 10)}...]`;
                        }
                        return filename.length > 50 
                            ? filename.substring(0, 47) + '...' 
                            : filename;
                    };

                    return `
                        <div style="
                            margin-bottom: 12px; 
                            padding: 12px; 
                            background-color: ${index % 2 === 0 ? 'var(--background-secondary)' : 'var(--background-tertiary)'};
                            border-radius: 6px;
                            display: grid;
                            gap: 10px;
                            align-items: start;
                            border: 1px solid var(--border-color);
                        ">
                            <div>
                                <div style="
                                    font-weight: bold;
                                    color: var(--text-primary);
                                    margin-bottom: 4px;
                                ">File</div>
                                <div style="
                                    color: var(--text-secondary);
                                    word-break: break-all;
                                    font-size: 0.9em;
                                " title="${item.file}">
                                    ${truncateFilename(item.file)}
                                </div>
                                
                                <div style="
                                    font-weight: bold;
                                    color: var(--text-primary);
                                    margin-top: 8px;
                                    margin-bottom: 4px;
                                ">Reason</div>
                                <div style="
                                    color: var(--text-secondary);
                                    font-size: 0.9em;
                                ">
                                    ${item.reason}
                                </div>
                                
                                <div style="
                                    font-weight: bold;
                                    color: var(--text-primary);
                                    margin-top: 8px;
                                    margin-bottom: 4px;
                                ">Message</div>
                                <div style="
                                    color: var(--text-secondary);
                                    font-size: 0.9em;
                                ">
                                    ${item.message}
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');

                skippedDetailsHtml = `
                <div style="margin-top: 15px; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden;">
                    <div 
                        style="
                            background-color: var(--background-secondary); 
                            padding: 12px 15px; 
                            cursor: pointer; 
                            display: flex; 
                            justify-content: space-between; 
                            align-items: center;
                            border-bottom: 1px solid var(--border-color);
                        " 
                        onclick="
                            var detailsSection = this.nextElementSibling;
                            detailsSection.style.display = detailsSection.style.display === 'none' ? 'block' : 'none';
                            this.querySelector('.toggle-icon').textContent = 
                                detailsSection.style.display === 'none' ? '▼' : '▲';
                        "
                    >
                        <strong style="color: var(--text-primary);">Skipped Details</strong> 
                        <span style="color: var(--text-secondary);">
                            <span class="toggle-icon">▼</span> 
                            ${allImportResults.items.length} items
                        </span>
                    </div>
                    <div style="
                        display: none; 
                        max-height: 300px; 
                        text-align: left;
                        overflow-y: auto; 
                        padding: 15px; 
                        background-color: var(--background-primary);
                    ">
                        ${skippedDetailsContent}
                    </div>
                </div>`;
            }
            
            // Enhance results summary with stats
            const resultsSummary = `
            <div style="margin-bottom: 20px; text-align: center;">
                <div style="display: flex; justify-content: space-between; gap: 15px; margin-bottom: 20px;">
                    <div style="flex: 1; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--success-color); border-radius: 6px; padding: 15px;">
                        <div style="font-size: 28px; font-weight: bold; color: var(--success-color);">${allImportResults.successful}</div>
                        <div style="color: var(--text-primary);">Successful</div>
                    </div>
                    <div style="flex: 1; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px; padding: 15px;">
                        <div style="font-size: 28px; font-weight: bold; color: var(--accent-primary);">${allImportResults.skipped}</div>
                        <div style="color: var(--text-primary);">Skipped</div>
                    </div>
                    <div style="flex: 1; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px; padding: 15px;">
                        <div style="font-size: 28px; font-weight: bold; color: var(--danger-color);">${allImportResults.failed}</div>
                        <div style="color: var(--text-primary);">Failed</div>
                    </div>
                </div>
                <div style="color: var(--text-secondary);">
                    Total processed: ${allImportResults.successful + allImportResults.skipped + allImportResults.failed}
                </div>
                ${skippedDetailsHtml}
            </div>
            `;
            
            // Add the results summary to the results container
            const importResultsContainer = document.getElementById('importResultsContainer');
            const existingContent = importResultsContainer.innerHTML;
            importResultsContainer.innerHTML = existingContent.replace(
                '<h3 style="margin-bottom: 16px; margin-top: 20px; display: flex; justify-content: center; align-items: center;">',
                resultsSummary + '<h3 style="margin-bottom: 16px; margin-top: 20px; display: flex; justify-content: center; align-items: center;">'
            );
            
            // Show results container
            importResultsContainer.style.display = 'block';
            
            // Show errors if any
            const importErrors = document.getElementById('importErrors');
            if (allImportResults.errors.length > 0) {
                const errorList = importErrors.querySelector('ul');
                errorList.innerHTML = '';
                
                allImportResults.errors.forEach(error => {
                    const li = document.createElement('li');
                    li.textContent = error;
                    errorList.appendChild(li);
                });
                
                importErrors.style.display = 'block';
            } else {
                importErrors.style.display = 'none';
            }
            
            // Show the close button again when results are shown
            const closeButton = plexImportModal.querySelector('.modal-close-btn');
            if (closeButton) {
                closeButton.style.display = 'block';
            }
        }
        
        // Event handlers
        showPlexImportButton.addEventListener('click', showPlexModal);
        closePlexImportButton?.addEventListener('click', hidePlexModal);
        
        // Don't close when clicking outside the modal during import
        plexImportModal.addEventListener('click', function(e) {
            if (e.target === plexImportModal) {
                // Check if import is in progress
                if (importProgressContainer.style.display === 'block') {
                    // Don't close if import is in progress
                    return;
                }
                hidePlexModal();
            }
        });
        
        // Close error modal event handlers
        closeErrorModalButton?.addEventListener('click', hideErrorModal);
        
        plexErrorCloseButtons.forEach(button => {
            button.addEventListener('click', hideErrorModal);
        });
        
        // Close results and prepare for a new import
        closeResultsButton?.addEventListener('click', function() {
            // Make sure to hide the modal and reset
            hideModal(plexImportModal);
            // Force a page refresh to ensure a clean state
            setTimeout(function() {
                window.location.reload();
            }, 300);
        });
    }
    
    // =========== JELLYFIN IMPORT MODAL ===========
    
    // Check if Jellyfin Import Modal exists
    const jellyfinImportModal = document.getElementById('jellyfinImportModal');
    const showJellyfinImportButton = document.getElementById('showJellyfinImportModal');

    if (jellyfinImportModal && showJellyfinImportButton && isLoggedIn) {
        // Modal elements
        const closeJellyfinImportButton = jellyfinImportModal.querySelector('.modal-close-btn');
        const jellyfinErrorModal = document.getElementById('jellyfinErrorModal');
        const closeJellyfinErrorButton = jellyfinErrorModal?.querySelector('.modal-close-btn');
        const jellyfinErrorCloseButtons = document.querySelectorAll('.jellyfinErrorClose');
        
        // Form elements
        const jellyfinImportType = document.getElementById('jellyfinImportType');
        const jellyfinLibrary = document.getElementById('jellyfinLibrary');
        const jellyfinShow = document.getElementById('jellyfinShow');
        const jellyfinTargetDirectory = document.getElementById('jellyfinTargetDirectory');
        const jellyfinFileHandling = document.getElementById('jellyfinFileHandling');
        const startJellyfinImportButton = document.getElementById('startJellyfinImport');
        
        // Step containers
        const jellyfinImportTypeStep = document.getElementById('jellyfinImportTypeStep');
        const jellyfinLibrarySelectionStep = document.getElementById('jellyfinLibrarySelectionStep');
        const jellyfinShowSelectionStep = document.getElementById('jellyfinShowSelectionStep');
        const jellyfinSeasonsOptionsStep = document.getElementById('jellyfinSeasonsOptionsStep');
        const jellyfinTargetDirectoryStep = document.getElementById('jellyfinTargetDirectoryStep');
        const jellyfinFileHandlingStep = document.getElementById('jellyfinFileHandlingStep');
        
        // Progress and results elements
        const jellyfinImportProgressContainer = document.getElementById('jellyfinImportProgressContainer');
        const jellyfinImportProgressBar = document.getElementById('jellyfinImportProgressBar')?.querySelector('div');
        const jellyfinImportProgressDetails = document.getElementById('jellyfinImportProgressDetails');
        const jellyfinImportResultsContainer = document.getElementById('jellyfinImportResultsContainer');
        const jellyfinImportOptionsContainer = document.getElementById('jellyfinImportOptions');
        
        // Error handling
        const jellyfinImportErrorContainer = document.querySelector('.jellyfin-import-error');
        const jellyfinErrorMessage = document.getElementById('jellyfinErrorMessage');
        
        // Results elements
        const jellyfinImportErrors = document.getElementById('jellyfinImportErrors');
        const closeJellyfinResultsButton = document.getElementById('closeJellyfinImportResults');
        
        // Connection status
        const connectionStatus = document.getElementById('jellyfinConnectionStatus');
        
        // Show/hide Jellyfin Import Modal functions
        function showJellyfinModal() {
            showModal(jellyfinImportModal);
            
            // Reset the form to a clean state
            resetJellyfinImport();
            
            // Test the connection
            testJellyfinConnection();
        }

        function hideJellyfinModal() {
            hideModal(jellyfinImportModal);
            resetJellyfinImport();
        }
        
        function showJellyfinErrorModal(message) {
            jellyfinErrorMessage.textContent = message;
            showModal(jellyfinErrorModal);
        }
        
        function hideJellyfinErrorModal() {
            hideModal(jellyfinErrorModal);
        }
        
        // Reset the import form
        function resetJellyfinImport() {
            // Show the close button again when results are shown
            const closeButton = jellyfinImportModal.querySelector('.modal-close-btn');
            if (closeButton) {
                closeButton.style.display = 'block';
                
                // Ensure close button has proper event handler
                closeButton.addEventListener('click', function() {
                    hideModal(jellyfinImportModal);
                    // Force a page refresh to ensure a clean state
                    setTimeout(function() {
                        window.location.reload();
                    }, 300);
                });
            }
                
            // Reset form selections
            jellyfinImportType.value = '';
            jellyfinLibrary.innerHTML = '<option value="">Select a content type first...</option>';
            jellyfinShow.innerHTML = '<option value="">Select a library first...</option>';
            jellyfinTargetDirectory.value = 'movies';
            jellyfinFileHandling.value = 'overwrite';
            
            // Hide all steps except type
            jellyfinImportTypeStep.style.display = 'block';
            jellyfinLibrarySelectionStep.style.display = 'none';
            jellyfinShowSelectionStep.style.display = 'none';
            jellyfinSeasonsOptionsStep.style.display = 'none';
            jellyfinTargetDirectoryStep.style.display = 'none';
            jellyfinFileHandlingStep.style.display = 'none';
            
            // Reset containers
            jellyfinImportProgressContainer.style.display = 'none';
            jellyfinImportResultsContainer.style.display = 'none';
            jellyfinImportOptionsContainer.style.display = 'block';
            
            // Hide error container
            jellyfinImportErrorContainer.style.display = 'none';
            jellyfinImportErrorContainer.textContent = '';
            
            // Disable start button
            startJellyfinImportButton.disabled = true;
            
            // Reset progress
            if (jellyfinImportProgressBar) {
                jellyfinImportProgressBar.style.width = '0%';
            }
            jellyfinImportProgressDetails.textContent = 'Processing 0 of 0 items (0%)';
            
            // Reset import cancelled flag
            importCancelled = false;
        }
        
        // Test Jellyfin connection and display status
        async function testJellyfinConnection() {
            connectionStatus.style.display = 'block';
            connectionStatus.innerHTML = `
                <div style="padding: 10px; border-radius: 4px; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary);">
                    <span style="display: inline-block; margin-right: 8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </span>
                    Testing connection to Jellyfin server...
                </div>
            `;
            
            try {
                const response = await fetch('./include/jellyfin-import.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'test_jellyfin_connection'
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    connectionStatus.innerHTML = `
                        <div style="padding: 10px; border-radius: 4px; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--success-color);">
                            <span style="display: inline-block; margin-right: 8px; color: var(--success-color);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                </svg>
                            </span>
                            Connected to Jellyfin server
                        </div>
                    `;
                    
                    // Load libraries if connection successful
                    loadJellyfinLibraries();
                } else {
                    connectionStatus.innerHTML = `
                        <div style="padding: 10px; border-radius: 4px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color);">
                            <span style="display: inline-block; margin-right: 8px; color: var(--danger-color);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="8" x2="12" y2="12"></line>
                                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                </svg>
                            </span>
                            Failed to connect to Jellyfin server: ${data.error}
                        </div>
                    `;
                    
                    // Disable the form if connection failed
                    startJellyfinImportButton.disabled = true;
                }
            } catch (error) {
                connectionStatus.innerHTML = `
                    <div style="padding: 10px; border-radius: 4px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color);">
                        <span style="display: inline-block; margin-right: 8px; color: var(--danger-color);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        </span>
                        Error connecting to Jellyfin server: ${error.message}
                    </div>
                `;
                
                // Disable the form if connection error
                startJellyfinImportButton.disabled = true;
            }
        }
        
        // Load Jellyfin libraries based on import type
        async function loadJellyfinLibraries() {
            // Get the currently selected import type
            const importType = jellyfinImportType.value;
            
            // If no import type is selected, don't try to load libraries
            if (!importType) {
                jellyfinLibrary.innerHTML = '<option value="">Select a content type first...</option>';
                return;
            }
            
            jellyfinLibrary.innerHTML = '<option value="">Loading libraries...</option>';
            
            try {
                const response = await fetch('./include/jellyfin-import.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'get_jellyfin_libraries'
                    })
                });
                
                const data = await response.json();
                
                if (data.success && data.data.length > 0) {
                    jellyfinLibraries = data.data;
                    jellyfinLibrary.innerHTML = '<option value="">Select library...</option>';
                    
                    let matchingLibrariesCount = 0;
                    
                    jellyfinLibraries.forEach(library => {
                        // Filter libraries based on import type
                        const showLibrary = (
                            // For movies, only show movie libraries
                            (importType === 'movies' && library.type === 'movie') ||
                            // For shows or seasons, only show TV show libraries
                            ((importType === 'shows' || importType === 'seasons') && library.type === 'show') ||
                            // For collections, show both
                            (importType === 'collections')
                        );
                        
                        if (showLibrary) {
                            matchingLibrariesCount++;
                            const option = document.createElement('option');
                            option.value = library.id;
                            option.dataset.type = library.type;
                            option.textContent = `${library.title} (${library.type === 'movie' ? 'Movies' : 'TV Shows'})`;
                            jellyfinLibrary.appendChild(option);
                        }
                    });
                    
                    // If no libraries match the filter
                    if (matchingLibrariesCount === 0) {
                        jellyfinLibrary.innerHTML = '<option value="">No matching libraries found</option>';
                        showErrorInJellyfinImportOptions('No libraries of the required type were found');
                    }
                } else {
                    jellyfinLibrary.innerHTML = '<option value="">No libraries found</option>';
                    showErrorInJellyfinImportOptions(data.error || 'No libraries found on Jellyfin server');
                }
            } catch (error) {
                jellyfinLibrary.innerHTML = '<option value="">Error loading libraries</option>';
                showErrorInJellyfinImportOptions('Error loading Jellyfin libraries: ' + error.message);
            }
        }
        
        // Load shows for a specific library (for TV season selection)
        async function loadJellyfinShows(libraryId) {
            jellyfinShow.innerHTML = '<option value="">Loading shows...</option>';
            
            try {
                const response = await fetch('./include/jellyfin-import.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'get_jellyfin_shows_for_seasons',
                        'libraryId': libraryId
                    })
                });
                
                const data = await response.json();
                
                if (data.success && data.data.length > 0) {
                    jellyfinShows = data.data;
                    jellyfinShow.innerHTML = '<option value="">Select show...</option>';
                    
                    jellyfinShows.forEach(show => {
                        const option = document.createElement('option');
                        option.value = show.id;
                        option.textContent = show.title + (show.year ? ` (${show.year})` : '');
                        jellyfinShow.appendChild(option);
                    });
                } else {
                    jellyfinShow.innerHTML = '<option value="">No shows found</option>';
                    showErrorInJellyfinImportOptions(data.error || 'No shows found in the selected library');
                }
            } catch (error) {
                jellyfinShow.innerHTML = '<option value="">Error loading shows</option>';
                showErrorInJellyfinImportOptions('Error loading shows: ' + error.message);
            }
        }
        
        // Display error in import options container
        function showErrorInJellyfinImportOptions(message) {
            // Only show errors if we have an actual message and if the user has started making selections
            if (message && jellyfinImportType.value) {
                jellyfinImportErrorContainer.textContent = message;
                jellyfinImportErrorContainer.style.display = 'block';
            }
        }

        function hideErrorInJellyfinImportOptions() {
            jellyfinImportErrorContainer.style.display = 'none';
            jellyfinImportErrorContainer.textContent = '';
        }
        
        // Validate import options and enable/disable start button
        function validateJellyfinImportOptions() {
            const importType = jellyfinImportType.value;
            const libraryId = jellyfinLibrary.value;
            const showId = jellyfinShow.value;
            const importAllSeasons = document.getElementById('jellyfinImportAllSeasons')?.checked || false;
            
            let isValid = false;
            
            if (!importType || !libraryId) {
                isValid = false;
            } else if (importType === 'seasons') {
                // If importing all seasons, we just need a valid library
                // If importing specific show seasons, we need both library and show
                isValid = importAllSeasons ? true : (showId ? true : false);
            } else {
                // For all other types, just need a valid library ID
                isValid = true;
            }
            
            startJellyfinImportButton.disabled = !isValid;
            return isValid;
        }
        
        // Checkbox handler for "Import all seasons"
        const jellyfinImportAllSeasonsCheckbox = document.getElementById('jellyfinImportAllSeasons');
        if (jellyfinImportAllSeasonsCheckbox) {
            jellyfinImportAllSeasonsCheckbox.addEventListener('change', function() {
                const showSelectionStep = document.getElementById('jellyfinShowSelectionStep');
                
                if (this.checked) {
                    // Hide show selection when "Import all seasons" is checked
                    showSelectionStep.style.display = 'none';
                } else {
                    // Show the show selection step if we have a library selected
                    const libraryId = jellyfinLibrary.value;
                    if (libraryId) {
                        const selectedOption = jellyfinLibrary.options[jellyfinLibrary.selectedIndex];
                        const libraryType = selectedOption ? selectedOption.dataset.type : '';
                        
                        if (libraryType === 'show') {
                            showSelectionStep.style.display = 'block';
                            // Load shows for the library if they're not already loaded
                            if (jellyfinShow.options.length <= 1) {
                                loadJellyfinShows(libraryId);
                            }
                        }
                    }
                }
                
                validateJellyfinImportOptions();
            });
        }
        
        // Handle import type selection
        jellyfinImportType.addEventListener('change', function() {
            const selectedType = this.value;
            
            // Reset other steps
            jellyfinLibrarySelectionStep.style.display = 'none';
            jellyfinSeasonsOptionsStep.style.display = 'none';
            jellyfinShowSelectionStep.style.display = 'none';
            jellyfinTargetDirectoryStep.style.display = 'none';
            jellyfinFileHandlingStep.style.display = 'none';
            
            // Reset selects with appropriate default messages
            jellyfinLibrary.innerHTML = '<option value="">Loading libraries...</option>';
            jellyfinShow.innerHTML = '<option value="">Select a library first...</option>';
            
            // Hide any previous error messages
            hideErrorInJellyfinImportOptions();
            
            if (selectedType) {
                // Show library selection step
                jellyfinLibrarySelectionStep.style.display = 'block';
                
                // Now it's appropriate to load libraries since user has selected a type
                loadJellyfinLibraries();
                
                // Pre-select target directory based on import type
                switch (selectedType) {
                    case 'movies':
                        jellyfinTargetDirectory.value = 'movies';
                        break;
                    case 'shows':
                        jellyfinTargetDirectory.value = 'tv-shows';
                        break;
                    case 'seasons':
                        jellyfinTargetDirectory.value = 'tv-seasons';
                        // Show seasons options step
                        jellyfinSeasonsOptionsStep.style.display = 'block';
                        break;
                    case 'collections':
                        jellyfinTargetDirectory.value = 'collections';
                        break;
                }
                
                // Show file handling step
                jellyfinFileHandlingStep.style.display = 'block';
            } else {
                // If user clears the selection, reset the form
                jellyfinLibrary.innerHTML = '<option value="">Select a content type first...</option>';
                hideErrorInJellyfinImportOptions();
                jellyfinFileHandlingStep.style.display = 'none';
            }
            
            validateJellyfinImportOptions();
        });
        
        // Handle library selection
        jellyfinLibrary.addEventListener('change', function() {
            const selectedLibraryId = this.value;
            const selectedOption = this.options[this.selectedIndex];
            const libraryType = selectedOption ? selectedOption.dataset.type : '';
            
            // Reset show selection
            jellyfinShowSelectionStep.style.display = 'none';
            jellyfinShow.innerHTML = '<option value="">Loading shows...</option>';
            
            // Hide error messages
            hideErrorInJellyfinImportOptions();
            
            if (selectedLibraryId) {
                // If importing seasons, show the show selection step
                if (jellyfinImportType.value === 'seasons') {
                    // Check if "Import all seasons" is checked
                    const importAllSeasons = document.getElementById('jellyfinImportAllSeasons').checked;
                    
                    // Only show the show selection step if not importing all seasons
                    if (!importAllSeasons) {
                        if (libraryType === 'show') {
                            jellyfinShowSelectionStep.style.display = 'block';
                            loadJellyfinShows(selectedLibraryId);
                        } else {
                            showErrorInJellyfinImportOptions('Please select a TV Show library to import seasons');
                        }
                    }
                }
                
                // Show target directory step
                jellyfinTargetDirectoryStep.style.display = 'none';
            }
            
            validateJellyfinImportOptions();
        });
        
        // Handle show selection
        jellyfinShow.addEventListener('change', function() {
            validateJellyfinImportOptions();
        });
        
        // Start the Jellyfin import process
        startJellyfinImportButton.addEventListener('click', async function() {
            if (!validateJellyfinImportOptions()) {
                return;
            }
            
            // Get selected options
            const importType = jellyfinImportType.value;
            const libraryId = jellyfinLibrary.value;
            const importAllSeasons = document.getElementById('jellyfinImportAllSeasons')?.checked || false;
            
            // Only get showKey if we're not importing all seasons
            const showKey = (importType === 'seasons' && !importAllSeasons) ? jellyfinShow.value : null;
            
            const targetDirectory = jellyfinTargetDirectory.value;
            const overwriteOption = jellyfinFileHandling.value;
            
            // Hide the close button when starting the import process
            const closeButton = jellyfinImportModal.querySelector('.modal-close-btn');
            if (closeButton) {
                closeButton.style.display = 'none';
            }
            
            // Show progress container, hide options
            jellyfinImportOptionsContainer.style.display = 'none';
            jellyfinImportProgressContainer.style.display = 'block';
            
            // Start import process
            try {
                await importJellyfinPosters(importType, libraryId, showKey, targetDirectory, overwriteOption, importAllSeasons);
            } catch (error) {
                // Show the close button again on error
                if (closeButton) {
                    closeButton.style.display = 'block';
                }
                
                // Hide the progress container
                jellyfinImportProgressContainer.style.display = 'none';
                jellyfinImportOptionsContainer.style.display = 'block';
                
                // Show error
                showErrorInJellyfinImportOptions('Import failed: ' + error.message);
            }
        });
        
        // Import posters from Jellyfin
        async function importJellyfinPosters(type, libraryId, showKey, contentType, overwriteOption, importAllSeasons) {
            // Configure initial request
            const initialParams = {
                'action': 'import_jellyfin_posters',
                'type': type,
                'libraryId': libraryId,
                'contentType': contentType,
                'overwriteOption': overwriteOption,
                'batchProcessing': 'true',
                'startIndex': 0
            };
            
            // Add showKey for seasons import (when not importing all)
            if (type === 'seasons' && !importAllSeasons) {
                if (!showKey) {
                    throw new Error('Show ID is required for single-show seasons import');
                }
                initialParams.showKey = showKey;
            }
            
            // Add importAllSeasons parameter if true
            if (type === 'seasons' && importAllSeasons) {
                initialParams.importAllSeasons = 'true';
            }
            
            let isComplete = false;
            let currentIndex = 0;
            const results = {
                successful: 0,
                skipped: 0,
                failed: 0,
                errors: []
            };
            
            // Stats dashboard in the modal
            const statsDashboard = `
            <div id="jellyfinImportStatsDashboard" style="margin-top: 20px; display: flex; justify-content: space-between; text-align: center; gap: 10px;">
                <div style="flex: 1; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--success-color); border-radius: 6px; padding: 12px;">
                    <div style="font-size: 24px; font-weight: bold; color: var(--success-color);" id="jellyfinStatsSuccessful">0</div>
                    <div style="color: var(--text-primary);">Successful</div>
                </div>
                <div style="flex: 1; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px; padding: 12px;">
                    <div style="font-size: 24px; font-weight: bold; color: var(--accent-primary);" id="jellyfinStatsSkipped">0</div>
                    <div style="color: var(--text-primary);">Skipped</div>
                </div>
                <div style="flex: 1; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px; padding: 12px;">
                    <div style="font-size: 24px; font-weight: bold; color: var(--danger-color);" id="jellyfinStatsFailed">0</div>
                    <div style="color: var(--text-primary);">Failed</div>
                </div>
            </div>
            `;
            
            // Add stats dashboard to progress container
            if (!document.getElementById('jellyfinImportStatsDashboard')) {
                document.getElementById('jellyfinImportProgressDetails').insertAdjacentHTML('afterend', statsDashboard);
            }
            
            // Update progress message for all seasons import
            if (type === 'seasons' && importAllSeasons) {
                document.getElementById('jellyfinImportProgressStatus').textContent = 'Importing season posters from all shows...';
            }
            
            const allSkippedDetails = []; // Array to store results

            // While not complete and not cancelled
            while (!isComplete && !importCancelled) {
                try {
                    const formData = new FormData();
                    
                    // Add all parameters
                    for (const [key, value] of Object.entries({
                        ...initialParams,
                        'startIndex': currentIndex,
                        'totalSuccessful': results.successful,
                        'totalSkipped': results.skipped,
                        'totalFailed': results.failed
                    })) {
                        formData.append(key, value);
                    }
                    
                    const response = await fetch('./include/jellyfin-import.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const responseText = await response.text();
                    
                    let data;
                    try {
                        data = JSON.parse(responseText);
                    } catch (parseError) {
                        throw new Error(`Failed to parse JSON response: ${responseText.substring(0, 100)}...`);
                    }
                    
                    if (!data.success) {
                        throw new Error(data.error || 'Unknown error during import');
                    }
                    
                    if (data.results && data.results.skippedDetails) {
                        allSkippedDetails.push(...data.results.skippedDetails); // Spread to merge arrays
                    }
                    
                    // Update progress
                    if (data.batchComplete) {
                        
                        // For "Import all seasons", show which show is being processed
                        if (type === 'seasons' && importAllSeasons && data.progress.currentShow) {
                            document.getElementById('jellyfinImportProgressStatus').textContent = 
                                `Importing seasons from: ${data.progress.currentShow}`;
                            
                            // Season progress details
                            if (data.progress.seasonCount !== undefined) {
                                document.getElementById('jellyfinImportProgressDetails').innerHTML = 
                                    `Processing show ${data.progress.processed} of ${data.progress.total} (${data.progress.percentage}%)<br>` +
                                    `Found ${data.progress.seasonCount} seasons in current show`;
                            } else {
                                document.getElementById('jellyfinImportProgressDetails').textContent = 
                                    `Processing show ${data.progress.processed} of ${data.progress.total} (${data.progress.percentage}%)`;
                            }
                        } else {
                            // Regular batch progress
                            const percentage = data.progress.percentage;
                            if (jellyfinImportProgressBar) {
                                jellyfinImportProgressBar.style.width = `${percentage}%`;
                            } else {
                                const progressBarElement = document.querySelector('#jellyfinImportProgressBar > div');
                                if (progressBarElement) {
                                    progressBarElement.style.width = `${percentage}%`;
                                }
                            }
                            
                            // Update progress text
                            document.getElementById('jellyfinImportProgressDetails').textContent = 
                                `Processing ${data.progress.processed} of ${data.progress.total} items (${percentage}%)`;
                        }
                        
                        // Update progress bar for all cases
                        const percentage = data.progress.percentage;
                        if (jellyfinImportProgressBar) {
                            jellyfinImportProgressBar.style.width = `${percentage}%`;
                        }
                        
                        // Track results
                        if (data.results) {
                            results.successful += data.results.successful;
                            results.skipped += data.results.skipped;
                            results.failed += data.results.failed;
                            
                            // Concat any errors
                            if (data.results.errors && data.results.errors.length) {
                                results.errors = [...results.errors, ...data.results.errors];
                            }
                        }
                        
                        // Update stats dashboard with the latest totals
                        updateJellyfinStatsDashboard(results, allSkippedDetails);
                        
                        // Check if complete
                        isComplete = data.progress.isComplete;
                        currentIndex = data.progress.nextIndex || 0;
                        
                        // Force a small delay between requests to prevent overwhelming the server
                        if (!isComplete) {
                            await new Promise(resolve => setTimeout(resolve, 100));
                        }
                    } else {
                        // Handle non-batch processing result
                        isComplete = true;
                        
                        if (data.results) {
                            results.successful = data.results.successful;
                            results.skipped = data.results.skipped;
                            results.failed = data.results.failed;
                            results.errors = data.results.errors || [];
                        }
                        
                        // Update stats dashboard
                        updateJellyfinStatsDashboard(data.totalStats, allSkippedDetails || results,  allSkippedDetails);
                    }
                    
                    // If complete, show results
                    if (isComplete) {
                        // Update status text for results
                        document.getElementById('jellyfinImportProgressStatus').textContent = 'Import complete!';
                        
                        // Small delay before showing the results screen
                        setTimeout(() => {
                            showJellyfinImportResults(results, allSkippedDetails);
                        }, 500);
                    }
                } catch (error) {
                    throw error;
                }
            }
            
            return results;
        }
        
        // Update the stats dashboard with the current totals
        function updateJellyfinStatsDashboard(stats) {
            // Make sure the elements exist before trying to update them
            const successfulElement = document.getElementById('jellyfinStatsSuccessful');
            const skippedElement = document.getElementById('jellyfinStatsSkipped');
            const failedElement = document.getElementById('jellyfinStatsFailed');
            
            if (successfulElement) {
                successfulElement.textContent = stats.successful || 0;
            }
            
            if (skippedElement) {
                skippedElement.textContent = stats.skipped || 0;
            }
            
            if (failedElement) {
                failedElement.textContent = stats.failed || 0;
            }
        }
        
        // Show import results
        function showJellyfinImportResults(results, skipped) {
            // Accumulate results from all batches
            if (results) {
                // Update counts
                allJellyfinImportResults.successful += results.successful || 0;
                allJellyfinImportResults.skipped += results.skipped || 0;
                allJellyfinImportResults.failed += results.failed || 0;

                // Accumulate errors
                if (results.errors && results.errors.length > 0) {
                    allJellyfinImportResults.errors = allJellyfinImportResults.errors.concat(results.errors);
                }
            }

            // Accumulate items from this batch
            if (skipped && skipped.length > 0) {
                allJellyfinImportResults.items = allJellyfinImportResults.items.concat(skipped);
            }

            // Hide progress container
            jellyfinImportProgressContainer.style.display = 'none';
            
            // Prepare details HTML if accumulated results exist
            let skippedDetailsHtml = '';
            if (allJellyfinImportResults.items.length > 0) {
                const skippedDetailsContent = allJellyfinImportResults.items.map(function(item, index) {
                    // Truncate long filenames, keeping the collection name more visible
                    const truncateFilename = (filename) => {
                        const match = filename.match(/(.+) \[([a-f0-9]+)\]/);
                        if (match) {
                            const [, collectionName, hash] = match;
                            return `${collectionName} [${hash.substring(0, 10)}...]`;
                        }
                        return filename.length > 50 
                            ? filename.substring(0, 47) + '...' 
                            : filename;
                    };

                    return `
                        <div style="
                            margin-bottom: 12px; 
                            padding: 12px; 
                            background-color: ${index % 2 === 0 ? 'var(--background-secondary)' : 'var(--background-tertiary)'};
                            border-radius: 6px;
                            display: grid;
                            gap: 10px;
                            align-items: start;
                            border: 1px solid var(--border-color);
                        ">
                            <div>
                                <div style="
                                    font-weight: bold;
                                    color: var(--text-primary);
                                    margin-bottom: 4px;
                                ">File</div>
                                <div style="
                                    color: var(--text-secondary);
                                    word-break: break-all;
                                    font-size: 0.9em;
                                " title="${item.file}">
                                    ${truncateFilename(item.file)}
                                </div>
                                
                                <div style="
                                    font-weight: bold;
                                    color: var(--text-primary);
                                    margin-top: 8px;
                                    margin-bottom: 4px;
                                ">Reason</div>
                                <div style="
                                    color: var(--text-secondary);
                                    font-size: 0.9em;
                                ">
                                    ${item.reason}
                                </div>
                                
                                <div style="
                                    font-weight: bold;
                                    color: var(--text-primary);
                                    margin-top: 8px;
                                    margin-bottom: 4px;
                                ">Message</div>
                                <div style="
                                    color: var(--text-secondary);
                                    font-size: 0.9em;
                                ">
                                    ${item.message}
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');

                skippedDetailsHtml = `
                <div style="margin-top: 15px; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden;">
                    <div 
                        style="
                            background-color: var(--background-secondary); 
                            padding: 12px 15px; 
                            cursor: pointer; 
                            display: flex; 
                            justify-content: space-between; 
                            align-items: center;
                            border-bottom: 1px solid var(--border-color);
                        " 
                        onclick="
                            var detailsSection = this.nextElementSibling;
                            detailsSection.style.display = detailsSection.style.display === 'none' ? 'block' : 'none';
                            this.querySelector('.toggle-icon').textContent = 
                                detailsSection.style.display === 'none' ? '▼' : '▲';
                        "
                    >
                        <strong style="color: var(--text-primary);">Skipped Details</strong> 
                        <span style="color: var(--text-secondary);">
                            <span class="toggle-icon">▼</span> 
                            ${allJellyfinImportResults.items.length} items
                        </span>
                    </div>
                    <div style="
                        display: none; 
                        max-height: 300px; 
                        text-align: left;
                        overflow-y: auto; 
                        padding: 15px; 
                        background-color: var(--background-primary);
                    ">
                        ${skippedDetailsContent}
                    </div>
                </div>`;
            }
            
            // Enhance results summary with stats
            const resultsSummary = `
            <div style="margin-bottom: 20px; text-align: center;">
                <div style="display: flex; justify-content: space-between; gap: 15px; margin-bottom: 20px;">
                    <div style="flex: 1; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--success-color); border-radius: 6px; padding: 15px;">
                        <div style="font-size: 28px; font-weight: bold; color: var(--success-color);">${allJellyfinImportResults.successful}</div>
                        <div style="color: var(--text-primary);">Successful</div>
                    </div>
                    <div style="flex: 1; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px; padding: 15px;">
                        <div style="font-size: 28px; font-weight: bold; color: var(--accent-primary);">${allJellyfinImportResults.skipped}</div>
                        <div style="color: var(--text-primary);">Skipped</div>
                    </div>
                    <div style="flex: 1; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px; padding: 15px;">
                        <div style="font-size: 28px; font-weight: bold; color: var(--danger-color);">${allJellyfinImportResults.failed}</div>
                        <div style="color: var(--text-primary);">Failed</div>
                    </div>
                </div>
                <div style="color: var(--text-secondary);">
                    Total processed: ${allJellyfinImportResults.successful + allJellyfinImportResults.skipped + allJellyfinImportResults.failed}
                </div>
                ${skippedDetailsHtml}
            </div>
            `;
            
            // Add the results summary to the results container
            const jellyfinImportResultsContainer = document.getElementById('jellyfinImportResultsContainer');
            const existingContent = jellyfinImportResultsContainer.innerHTML;
            jellyfinImportResultsContainer.innerHTML = existingContent.replace(
                '<h3 style="margin-bottom: 16px; margin-top: 20px; display: flex; justify-content: center; align-items: center;">',
                resultsSummary + '<h3 style="margin-bottom: 16px; margin-top: 20px; display: flex; justify-content: center; align-items: center;">'
            );
            
            // Show results container
            jellyfinImportResultsContainer.style.display = 'block';
            
            // Show errors if any
            const jellyfinImportErrors = document.getElementById('jellyfinImportErrors');
            if (allJellyfinImportResults.errors.length > 0) {
                const errorList = jellyfinImportErrors.querySelector('ul');
                errorList.innerHTML = '';
                
                allJellyfinImportResults.errors.forEach(function(error) {
                    const li = document.createElement('li');
                    li.textContent = error;
                    errorList.appendChild(li);
                });
                
                jellyfinImportErrors.style.display = 'block';
            } else {
                jellyfinImportErrors.style.display = 'none';
            }
            
            // ENSURE the close button is visible
            const closeButton = jellyfinImportModal.querySelector('.modal-close-btn');
            if (closeButton) {
                closeButton.style.display = 'block';
            }
            
            // Also ensure the close results button is properly set up
            const closeResultsButton = document.getElementById('closeJellyfinImportResults');
            if (closeResultsButton) {
                closeResultsButton.style.display = 'block';
                // Remove any existing event listeners to prevent multiple attachments
                closeResultsButton.removeEventListener('click', closeImportResultsHandler);
                closeResultsButton.addEventListener('click', closeImportResultsHandler);
            }
        }

        // Separate event handler function to avoid multiple listener attachments
        function closeImportResultsHandler() {
            // Reset the global results object when closing
            allJellyfinImportResults = {
                successful: 0,
                skipped: 0,
                failed: 0,
                errors: [],
                items: []
            };
            
            jellyfinImportModal.classList.remove('show');
            setTimeout(function() {
                jellyfinImportModal.style.display = 'none';
                // Force a page refresh to ensure a clean state
                window.location.reload();
            }, 300);
        }
        
        // Event handlers
        showJellyfinImportButton.addEventListener('click', showJellyfinModal);
        closeJellyfinImportButton?.addEventListener('click', hideJellyfinModal);
        
        // Don't close when clicking outside the modal during import
        jellyfinImportModal.addEventListener('click', function(e) {
            if (e.target === jellyfinImportModal) {
                // Check if import is in progress
                if (jellyfinImportProgressContainer.style.display === 'block') {
                    // Don't close if import is in progress
                    return;
                }
                hideJellyfinModal();
            }
        });
        
        // Close error modal event handlers
        closeJellyfinErrorButton?.addEventListener('click', hideJellyfinErrorModal);
        
        jellyfinErrorCloseButtons.forEach(button => {
            button.addEventListener('click', hideJellyfinErrorModal);
        });
        
        // Close results and prepare for a new import
        closeJellyfinResultsButton?.addEventListener('click', function() {
            // Make sure to hide the modal and reset
            hideModal(jellyfinImportModal);
            // Force a page refresh to ensure a clean state
            setTimeout(function() {
                window.location.reload();
            }, 300);
        });
    }
    
    // =========== PLEX INTEGRATION UTILITIES ===========
    
    // Function to refresh image after updates
    function refreshImage(filename, directory) {
        const images = document.querySelectorAll('.gallery-image');
        images.forEach(img => {
            const imgPath = img.getAttribute('data-src');
            if (imgPath && imgPath.includes(filename)) {
                // Add a timestamp to force a cache refresh
                const timestamp = new Date().getTime();
                const newSrc = imgPath + '?t=' + timestamp;
                
                // Set the new source
                img.src = '';
                img.setAttribute('data-src', newSrc);
                img.src = newSrc;
                
                // Reset loading state
                img.classList.remove('loaded');
                const placeholder = img.previousElementSibling;
                if (placeholder && placeholder.classList.contains('gallery-image-placeholder')) {
                    placeholder.classList.remove('hidden');
                }
                
                // Set loaded class when the new image loads
                img.onload = function() {
                    img.classList.add('loaded');
                    if (placeholder) {
                        placeholder.classList.add('hidden');
                    }
                };
            }
        });
    }
    
    // Function to check if a filename has "Plex" in it
    function isPlexFile(filename) {
        return filename.toLowerCase().includes('plex');
    }
    
    // =========== SEND TO PLEX FUNCTIONALITY ===========
    
    // Function to send image to Plex
    async function sendToPlex(filename, directory) {
        // Show a loading notification
        const notification = showSendingNotification();
        
        try {
            const formData = new FormData();
            formData.append('action', 'send_to_plex');
            formData.append('filename', filename);
            formData.append('directory', directory);
            
            const response = await fetch('./include/send-to-plex.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Show success notification
                showPlexSuccessNotification();
            } else {
                // Show error notification
                showPlexErrorNotification(data.error || 'Failed to send poster to Plex');
            }
        } catch (error) {
            // Show error notification
            showPlexErrorNotification('Error sending poster to Plex: ' + error.message);
        } finally {
            // Hide the sending notification
            notification.remove();
        }
    }
    
    // Function to show sending notification
    function showSendingNotification() {
        const notification = document.createElement('div');
        notification.className = 'plex-notification plex-sending';
        notification.innerHTML = `
            <div class="plex-notification-content">
                <div class="plex-spinner"></div>
                <span>Sending to Plex...</span>
            </div>
        `;
        document.body.appendChild(notification);
        
        // Force reflow to trigger animation
        notification.offsetHeight;
        notification.classList.add('show');
        
        return notification;
    }
    
    // Function to show success notification for Plex send
    function showPlexSuccessNotification() {
        const notification = document.createElement('div');
        notification.className = 'plex-notification plex-success';
        notification.innerHTML = `
            <div class="plex-notification-content">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <span>Sent to Plex successfully!</span>
            </div>
        `;
        document.body.appendChild(notification);
        
        // Force reflow to trigger animation
        notification.offsetHeight;
        notification.classList.add('show');
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                notification.remove();
            }, 300); // Match transition duration
        }, 3000);
    }
    
    // Function to show error notification for Plex
    function showPlexErrorNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'plex-notification plex-error';
        notification.innerHTML = `
            <div class="plex-notification-content">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <span>${message}</span>
            </div>
        `;
        document.body.appendChild(notification);
        
        // Force reflow to trigger animation
        notification.offsetHeight;
        notification.classList.add('show');
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                notification.remove();
            }, 300); // Match transition duration
        }, 5000);
    }
    
    // Initialize Plex Confirm Modal
    function initPlexConfirmModal() {
        const modal = document.getElementById('plexConfirmModal');
        if (!modal) {
            return;
        }
        
        // Get elements
        const closeButton = modal.querySelector('.modal-close-btn');
        const cancelButton = document.getElementById('cancelPlexSend');
        const confirmButton = modal.querySelector('.send-to-plex-confirm');
        
        // Close button handler
        if (closeButton) {
            closeButton.addEventListener('click', function() {
                hideModal(modal);
            });
        }
        
        // Cancel button handler
        if (cancelButton) {
            cancelButton.addEventListener('click', function() {
                hideModal(modal);
            });
        }
        
        // Confirm button handler
        if (confirmButton) {
            confirmButton.addEventListener('click', function() {
                const filenameElement = document.getElementById('plexConfirmFilename');
                const filename = filenameElement.getAttribute('data-filename');
                const directory = filenameElement.getAttribute('data-dirname');
                
                // Hide the modal
                hideModal(modal);
                
                // Send the poster to Plex
                sendToPlex(filename, directory);
            });
        }
        
        // Click outside to close
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                hideModal(modal);
            }
        });
        
        // Escape key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('show')) {
                hideModal(modal);
            }
        });
    }
    
    // Send to Plex handler function
    function sendToPlexHandler(e) {
        e.preventDefault();
        const filename = this.getAttribute('data-filename');
        const directory = this.getAttribute('data-dirname');
        
        // Store the data in the modal
        const modal = document.getElementById('plexConfirmModal');
        const filenameElement = document.getElementById('plexConfirmFilename');
        
        filenameElement.textContent = filename;
        filenameElement.setAttribute('data-filename', filename);
        filenameElement.setAttribute('data-dirname', directory);
        
        // Show the modal
        showModal(modal);
    }
    
	// =========== PLEX EXPORT FUNCTIONALITY ===========

	// Check if Export buttons exist
	const showPlexExportButton = document.getElementById('showPlexExportModal');
	const showJellyfinExportButton = document.getElementById('showJellyfinExportModal');

	if (showPlexExportButton && isLoggedIn) {
		// Plex Export Modal elements
		const plexExportModal = document.getElementById('plexExportModal');
		const closePlexExportButton = plexExportModal?.querySelector('.modal-close-btn');
		const plexExportErrorModal = document.getElementById('plexExportErrorModal');
		const closePlexExportErrorButton = plexExportErrorModal?.querySelector('.modal-close-btn');
		const plexExportErrorCloseButtons = document.querySelectorAll('.plexExportErrorClose');
		
		// Form elements
		const plexExportType = document.getElementById('plexExportType');
		const startPlexExportButton = document.getElementById('startPlexExport');
		
		// Progress and results elements
		const exportProgressContainer = document.getElementById('exportProgressContainer');
		const exportProgressBar = document.getElementById('exportProgressBar')?.querySelector('div');
		const exportProgressDetails = document.getElementById('exportProgressDetails');
		const exportResultsContainer = document.getElementById('exportResultsContainer');
		const exportOptionsContainer = document.getElementById('plexExportOptions');
		
		// Error handling
		const exportErrorContainer = document.querySelector('.export-error');
		const plexExportErrorMessage = document.getElementById('plexExportErrorMessage');
		
		// Results elements
		const exportErrors = document.getElementById('exportErrors');
		const closeExportResults = document.getElementById('closeExportResults');
		
		// Connection status
		const connectionStatus = document.getElementById('plexExportConnectionStatus');
		
		// Global results accumulator
		let allExportResults = {
		    successful: 0,
		    skipped: 0,
		    failed: 0,
		    errors: [],
		    items: []
		};
		
		let exportCancelled = false;
		
		// Show/hide Plex Export Modal functions
		function showPlexExportModal(e) {
		    // Prevent default behavior and stop propagation
		    if (e) {
		        e.preventDefault();
		        e.stopPropagation();
		    }
		    
		    if (!plexExportModal) {
		        console.error("Plex Export Modal not found");
		        return;
		    }
		    
		    // First make sure modal is displayed
		    plexExportModal.style.display = 'block';
		    
		    // Force reflow before adding the show class
		    plexExportModal.offsetHeight;
		    
		    // Add the show class after a short delay
		    setTimeout(() => {
		        plexExportModal.classList.add('show');
		        
		        // Reset the form to a clean state
		        resetPlexExport();
		        
		        // Test connection
		        testPlexExportConnection();
		    }, 50);
		}

		function hidePlexExportModal() {
		    if (!plexExportModal) {
		        return;
		    }
		    
		    plexExportModal.classList.remove('show');
		    setTimeout(() => {
		        plexExportModal.style.display = 'none';
		        resetPlexExport();
		    }, 300);
		}
		
		function showExportErrorModal(message) {
		    if (!plexExportErrorModal) {
		        return;
		    }
		    
		    plexExportErrorMessage.textContent = message;
		    plexExportErrorModal.style.display = 'block';
		    plexExportErrorModal.offsetHeight; // Force reflow
		    setTimeout(() => {
		        plexExportErrorModal.classList.add('show');
		    }, 10);
		}
		
		function hideExportErrorModal() {
		    if (!plexExportErrorModal) {
		        return;
		    }
		    
		    plexExportErrorModal.classList.remove('show');
		    setTimeout(() => {
		        plexExportErrorModal.style.display = 'none';
		    }, 300);
		}
		
		// Reset the export form
		function resetPlexExport() {
		    // Show the close button
		    const closeButton = plexExportModal.querySelector('.modal-close-btn');
		    if (closeButton) {
		        closeButton.style.display = 'block';
		        
		        // Remove existing event listeners by cloning the button
		        const newCloseButton = closeButton.cloneNode(true);
		        closeButton.parentNode.replaceChild(newCloseButton, closeButton);
		        
		        // Add new event handler
		        newCloseButton.addEventListener('click', function() {
		            hidePlexExportModal();
		        });
		    }
		    
		    // Reset form selections
		    if (plexExportType) {
		        plexExportType.value = '';
		    }
		    
		    // Reset containers
		    if (exportProgressContainer) {
		        exportProgressContainer.style.display = 'none';
		    }
		    if (exportResultsContainer) {
		        exportResultsContainer.style.display = 'none';
		    }
		    if (exportOptionsContainer) {
		        exportOptionsContainer.style.display = 'block';
		    }
		    
		    // Hide error container
		    if (exportErrorContainer) {
		        exportErrorContainer.style.display = 'none';
		        exportErrorContainer.textContent = '';
		    }
		    
		    // Disable start button
		    if (startPlexExportButton) {
		        startPlexExportButton.disabled = true;
		    }
		    
		    // Reset progress
		    if (exportProgressBar) {
		        exportProgressBar.style.width = '0%';
		    }
		    if (exportProgressDetails) {
		        exportProgressDetails.textContent = 'Processing 0 of 0 items (0%)';
		    }
		    
		    // Reset export cancelled flag
		    exportCancelled = false;
		    
		    // Reset export results
		    allExportResults = {
		        successful: 0,
		        skipped: 0,
		        failed: 0,
		        errors: [],
		        items: []
		    };
		}
		
		// Test Plex connection
		function testPlexExportConnection() {
		    if (!connectionStatus) {
		        return;
		    }
		    
		    connectionStatus.style.display = 'block';
		    connectionStatus.innerHTML = `
		        <div style="padding: 10px; border-radius: 4px; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary);">
		            <span style="display: inline-block; margin-right: 8px;">
		                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
		                    <circle cx="12" cy="12" r="10"></circle>
		                    <polyline points="12 6 12 12 16 14"></polyline>
		                </svg>
		            </span>
		            Testing connection to Plex server...
		        </div>
		    `;
		    
		    fetch('./include/plex-export.php', {
		        method: 'POST',
		        headers: {
		            'Content-Type': 'application/x-www-form-urlencoded',
		        },
		        body: new URLSearchParams({
		            'action': 'test_plex_connection'
		        })
		    })
		    .then(response => response.json())
		    .then(data => {
		        if (data.success) {
		            connectionStatus.innerHTML = `
		                <div style="padding: 10px; border-radius: 4px; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--success-color);">
		                    <span style="display: inline-block; margin-right: 8px; color: var(--success-color);">
		                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
		                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
		                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
		                        </svg>
		                    </span>
		                    Connected to Plex server
		                </div>
		            `;
		        } else {
		            connectionStatus.innerHTML = `
		                <div style="padding: 10px; border-radius: 4px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color);">
		                    <span style="display: inline-block; margin-right: 8px; color: var(--danger-color);">
		                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
		                            <circle cx="12" cy="12" r="10"></circle>
		                            <line x1="12" y1="8" x2="12" y2="12"></line>
		                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
		                        </svg>
		                    </span>
		                    Failed to connect to Plex server: ${data.error}
		                </div>
		            `;
		            
		            // Disable the form if connection failed
		            if (startPlexExportButton) {
		                startPlexExportButton.disabled = true;
		            }
		        }
		    })
		    .catch(error => {
		        connectionStatus.innerHTML = `
		            <div style="padding: 10px; border-radius: 4px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color);">
		                <span style="display: inline-block; margin-right: 8px; color: var(--danger-color);">
		                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
		                        <circle cx="12" cy="12" r="10"></circle>
		                        <line x1="12" y1="8" x2="12" y2="12"></line>
		                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
		                    </svg>
		                </span>
		                Error connecting to Plex server: ${error.message}
		            </div>
		        `;
		        
		        // Disable the form if connection error
		        if (startPlexExportButton) {
		            startPlexExportButton.disabled = true;
		        }
		    });
		}
		
		// Display error in export options container
		function showErrorInExportOptions(message) {
		    if (message && plexExportType && plexExportType.value && exportErrorContainer) {
		        exportErrorContainer.textContent = message;
		        exportErrorContainer.style.display = 'block';
		    }
		}

		function hideErrorInExportOptions() {
		    if (exportErrorContainer) {
		        exportErrorContainer.style.display = 'none';
		        exportErrorContainer.textContent = '';
		    }
		}
		
		// Validate export options
		function validateExportOptions() {
		    if (!plexExportType || !startPlexExportButton) {
		        return false;
		    }
		    
		    const exportType = plexExportType.value;
		    let isValid = exportType !== '';
		    startPlexExportButton.disabled = !isValid;
		    return isValid;
		}
		
		// Handle export type selection
		if (plexExportType) {
		    plexExportType.addEventListener('change', function() {
		        // Hide any previous error messages
		        hideErrorInExportOptions();
		        
		        // Validate options
		        validateExportOptions();
		    });
		}
		
		// Start the export process
		if (startPlexExportButton) {
		    startPlexExportButton.addEventListener('click', async function() {
		        if (!validateExportOptions()) {
		            return;
		        }
		        
		        // Get selected options
		        const exportType = plexExportType.value;
		        
		        // Hide the close button
		        const closeButton = plexExportModal.querySelector('.modal-close-btn');
		        if (closeButton) {
		            closeButton.style.display = 'none';
		        }
		        
		        // Show progress container, hide options
		        if (exportOptionsContainer) {
		            exportOptionsContainer.style.display = 'none';
		        }
		        if (exportProgressContainer) {
		            exportProgressContainer.style.display = 'block';
		        }
		        
		        // Start export process
		        try {
		            await exportPlexPosters(exportType);
		        } catch (error) {
		            // Show the close button again on error
		            if (closeButton) {
		                closeButton.style.display = 'block';
		            }
		            
		            // Hide the progress container
		            if (exportProgressContainer) {
		                exportProgressContainer.style.display = 'none';
		            }
		            if (exportOptionsContainer) {
		                exportOptionsContainer.style.display = 'block';
		            }
		            
		            // Show error
		            showErrorInExportOptions('Export failed: ' + error.message);
		        }
		    });
		}
		
		// Export posters to Plex
		async function exportPlexPosters(type) {
		    // Configure initial request
		    const initialParams = {
		        'action': 'export_plex_posters',
		        'type': type,
		        'batchProcessing': 'true',
		        'startIndex': 0
		    };
		    
		    let isComplete = false;
		    let currentIndex = 0;
		    const results = {
		        successful: 0,
		        skipped: 0,
		        failed: 0,
		        errors: []
		    };
		    
		    // Stats dashboard in the modal
		    const statsDashboard = `
		    <div id="exportStatsDashboard" style="margin-top: 20px; display: flex; justify-content: space-between; text-align: center; gap: 10px;">
		        <div style="flex: 1; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--success-color); border-radius: 6px; padding: 12px;">
		            <div style="font-size: 24px; font-weight: bold; color: var(--success-color);" id="statsSuccessful">0</div>
		            <div style="color: var(--text-primary);">Successful</div>
		        </div>
		        <div style="flex: 1; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px; padding: 12px;">
		            <div style="font-size: 24px; font-weight: bold; color: var(--accent-primary);" id="statsSkipped">0</div>
		            <div style="color: var(--text-primary);">Skipped</div>
		        </div>
		        <div style="flex: 1; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px; padding: 12px;">
		            <div style="font-size: 24px; font-weight: bold; color: var(--danger-color);" id="statsFailed">0</div>
		            <div style="color: var(--text-primary);">Failed</div>
		        </div>
		    </div>
		    `;
		    
		    // Add stats dashboard to progress container
		    if (exportProgressDetails && !document.getElementById('exportStatsDashboard')) {
		        exportProgressDetails.insertAdjacentHTML('afterend', statsDashboard);
		    }
		    
		    // While not complete and not cancelled
		    while (!isComplete && !exportCancelled) {
		        try {
		            const formData = new FormData();
		            
		            // Add all parameters
		            for (const [key, value] of Object.entries({
		                ...initialParams,
		                'startIndex': currentIndex,
		                'totalSuccessful': results.successful,
		                'totalSkipped': results.skipped,
		                'totalFailed': results.failed
		            })) {
		                formData.append(key, value);
		            }
		            
		            const response = await fetch('./include/plex-export.php', {
		                method: 'POST',
		                body: formData
		            });
		            
		            const data = await response.json();
		            
		            if (!data.success) {
		                throw new Error(data.error || 'Unknown error during export');
		            }
		            
		            // Update progress
		            if (data.batchComplete) {
		                // Regular batch progress
		                const percentage = data.progress.percentage;
		                if (exportProgressBar) {
		                    exportProgressBar.style.width = `${percentage}%`;
		                }
		                
		                // Update progress text
		                if (exportProgressDetails) {
		                    exportProgressDetails.textContent = 
		                        `Processing ${data.progress.processed} of ${data.progress.total} items (${percentage}%)`;
		                }
		                
		                // Track results
		                if (data.results) {
		                    results.successful += data.results.successful;
		                    results.skipped += data.results.skipped;
		                    results.failed += data.results.failed;
		                    
		                    // Concat any errors
		                    if (data.results.errors && data.results.errors.length) {
		                        results.errors = [...results.errors, ...data.results.errors];
		                    }
		                }
		                
		                // Update stats dashboard with the latest totals
		                updateExportStatsDashboard(results);
		                
		                // Check if complete
		                isComplete = data.progress.isComplete;
		                currentIndex = data.progress.nextIndex || 0;
		            } else {
		                // Handle non-batch processing result
		                isComplete = true;
		                
		                if (data.results) {
		                    results.successful = data.results.successful;
		                    results.skipped = data.results.skipped;
		                    results.failed = data.results.failed;
		                    results.errors = data.results.errors || [];
		                }
		                
		                // Update stats dashboard
		                updateExportStatsDashboard(results);
		            }
		            
		            // If complete, show results
		            if (isComplete) {
		                // Update status text for results
		                const statusElement = document.getElementById('exportProgressStatus');
		                if (statusElement) {
		                    statusElement.textContent = 'Export complete!';
		                }
		                
		                // Small delay before showing the results screen
		                setTimeout(() => {
		                    showExportResults(results);
		                }, 500);
		            }
		        } catch (error) {
		            // Stop processing and show error
		            throw error;
		        }
		    }
		    
		    return results;
		}
		
		// Update the stats dashboard with the current totals
		function updateExportStatsDashboard(stats) {
		    const successfulElement = document.getElementById('statsSuccessful');
		    const skippedElement = document.getElementById('statsSkipped');
		    const failedElement = document.getElementById('statsFailed');
		    
		    if (successfulElement) {
		        successfulElement.textContent = stats.successful;
		    }
		    if (skippedElement) {
		        skippedElement.textContent = stats.skipped;
		    }
		    if (failedElement) {
		        failedElement.textContent = stats.failed;
		    }
		}
		
		// Show export results
		function showExportResults(results) {
		    // Accumulate results from all batches
		    if (results) {
		        // Update counts
		        allExportResults.successful += results.successful || 0;
		        allExportResults.skipped += results.skipped || 0;
		        allExportResults.failed += results.failed || 0;

		        // Accumulate errors
		        if (results.errors && results.errors.length > 0) {
		            allExportResults.errors = allExportResults.errors.concat(results.errors);
		        }
		    }

		    // Hide progress container
		    if (exportProgressContainer) {
		        exportProgressContainer.style.display = 'none';
		    }
		    
		    // Enhance results summary with stats
		    const resultsSummary = `
		    <div style="margin-bottom: 20px; text-align: center;">
		        <div style="display: flex; justify-content: space-between; gap: 15px; margin-bottom: 20px;">
		            <div style="flex: 1; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--success-color); border-radius: 6px; padding: 15px;">
		                <div style="font-size: 28px; font-weight: bold; color: var(--success-color);">${allExportResults.successful}</div>
		                <div style="color: var(--text-primary);">Successful</div>
		            </div>
		            <div style="flex: 1; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px; padding: 15px;">
		                <div style="font-size: 28px; font-weight: bold; color: var(--accent-primary);">${allExportResults.skipped}</div>
		                <div style="color: var(--text-primary);">Skipped</div>
		            </div>
		            <div style="flex: 1; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px; padding: 15px;">
		                <div style="font-size: 28px; font-weight: bold; color: var(--danger-color);">${allExportResults.failed}</div>
		                <div style="color: var(--text-primary);">Failed</div>
		            </div>
		        </div>
		        <div style="color: var(--text-secondary);">
		            Total processed: ${allExportResults.successful + allExportResults.skipped + allExportResults.failed}
		        </div>
		    </div>
		    `;
		    
		    // Add the results summary to the results container
		    const exportResultsContainer = document.getElementById('exportResultsContainer');
		    if (exportResultsContainer) {
		        const existingContent = exportResultsContainer.innerHTML;
		        exportResultsContainer.innerHTML = existingContent.replace(
		            '<h3 style="margin-bottom: 16px; margin-top: 20px; display: flex; justify-content: center; align-items: center;">',
		            resultsSummary + '<h3 style="margin-bottom: 16px; margin-top: 20px; display: flex; justify-content: center; align-items: center;">'
		        );
		        
		        // Show results container
		        exportResultsContainer.style.display = 'block';
		    }
		    
		    // Show errors if any
		    const exportErrors = document.getElementById('exportErrors');
		    if (exportErrors) {
		        if (allExportResults.errors.length > 0) {
		            const errorList = exportErrors.querySelector('ul');
		            if (errorList) {
		                errorList.innerHTML = '';
		                
		                allExportResults.errors.forEach(error => {
		                    const li = document.createElement('li');
		                    li.textContent = error;
		                    errorList.appendChild(li);
		                });
		            }
		            
		            exportErrors.style.display = 'block';
		        } else {
		            exportErrors.style.display = 'none';
		        }
		    }
		    
		    // Show the close button again when results are shown
		    const closeButton = plexExportModal.querySelector('.modal-close-btn');
		    if (closeButton) {
		        closeButton.style.display = 'block';
		    }
		}
		
		// Event handlers - use explicit event binding method to avoid issues
		if (showPlexExportButton) {
		    // Remove any existing listeners (if any)
		    showPlexExportButton.onclick = null;
		    
		    // Add the click handler
		    showPlexExportButton.onclick = showPlexExportModal;
		}
		
		if (closePlexExportButton) {
		    closePlexExportButton.onclick = hidePlexExportModal;
		}
		
		// Don't close when clicking outside the modal during export
		if (plexExportModal) {
		    plexExportModal.addEventListener('click', function(e) {
		        if (e.target === plexExportModal) {
		            // Check if export is in progress
		            if (exportProgressContainer && exportProgressContainer.style.display === 'block') {
		                // Don't close if export is in progress
		                return;
		            }
		            hidePlexExportModal();
		        }
		    });
		}
		
		// Close error modal event handlers
		if (closePlexExportErrorButton) {
		    closePlexExportErrorButton.onclick = hideExportErrorModal;
		}
		
		if (plexExportErrorCloseButtons) {
		    plexExportErrorCloseButtons.forEach(button => {
		        button.onclick = hideExportErrorModal;
		    });
		}
		
		// Close results and prepare for a new export
		if (closeExportResults) {
		    closeExportResults.addEventListener('click', function() {
		        // Make sure to hide the modal and reset
		        if (plexExportModal) {
		            plexExportModal.classList.remove('show');
		            setTimeout(function() {
		                plexExportModal.style.display = 'none';
		                // Force a page refresh to ensure a clean state
		                window.location.reload();
		            }, 300);
		        }
		    });
		}
	}

	// Init Jellyfin Export (placeholder functionality for now)
	if (showJellyfinExportButton && isLoggedIn) {
		const jellyfinExportModal = document.getElementById('jellyfinExportModal');
		const closeJellyfinExportButton = jellyfinExportModal?.querySelector('.modal-close-btn');
		
		function showJellyfinExportModal(e) {
		    if (e) {
		        e.preventDefault();
		        e.stopPropagation();
		    }
		    
		    if (!jellyfinExportModal) return;
		    
		    jellyfinExportModal.style.display = 'block';
		    jellyfinExportModal.offsetHeight; // Force reflow
		    setTimeout(() => {
		        jellyfinExportModal.classList.add('show');
		    }, 10);
		}
		
		function hideJellyfinExportModal() {
		    if (!jellyfinExportModal) return;
		    
		    jellyfinExportModal.classList.remove('show');
		    setTimeout(() => {
		        jellyfinExportModal.style.display = 'none';
		    }, 300);
		}
		
		if (showJellyfinExportButton) {
		    showJellyfinExportButton.onclick = showJellyfinExportModal;
		}
		
		if (closeJellyfinExportButton) {
		    closeJellyfinExportButton.onclick = hideJellyfinExportModal;
		}
		
		if (jellyfinExportModal) {
		    jellyfinExportModal.addEventListener('click', function(e) {
		        if (e.target === jellyfinExportModal) {
		            hideJellyfinExportModal();
		        }
		    });
		}
	}
    
    // =========== IMPORT FROM PLEX FUNCTIONALITY ===========
    
    // Create Import from Plex Modal if it doesn't exist
    function createImportFromPlexModal() {
        if (!document.getElementById('importFromPlexModal')) {
            const modalHTML = `
            <div id="importFromPlexModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Get from Plex</h3>
                        <button type="button" class="modal-close-btn">×</button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to import this poster from Plex?</p>
                        <p id="importFromPlexFilename" style="margin-top: 10px; font-weight: 500; overflow-wrap: break-word;" data-filename="" data-dirname=""></p>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="modal-button cancel" id="cancelImportFromPlex">Cancel</button>
                        <button type="button" class="modal-button import-from-plex-confirm">Get</button>
                    </div>
                </div>
            </div>
            `;
            
            // Append the modal HTML to the body
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }
    }
    
    // Initialize Import from Plex feature
    function initImportFromPlexFeature() {
        const modal = document.getElementById('importFromPlexModal');
        if (!modal) {
            console.error("Import from Plex modal not found");
            return;
        }
        
        // Get elements
        const closeButton = modal.querySelector('.modal-close-btn');
        const cancelButton = document.getElementById('cancelImportFromPlex');
        const confirmButton = modal.querySelector('.import-from-plex-confirm');
        
        // Close button handler
        if (closeButton) {
            closeButton.addEventListener('click', function() {
                hideModal(modal);
            });
        }
        
        // Cancel button handler
        if (cancelButton) {
            cancelButton.addEventListener('click', function() {
                hideModal(modal);
            });
        }
        
        // Confirm button handler
        if (confirmButton) {
            confirmButton.addEventListener('click', function() {
                const filenameElement = document.getElementById('importFromPlexFilename');
                const filename = filenameElement.getAttribute('data-filename');
                const directory = filenameElement.getAttribute('data-dirname');
                
                // Hide the modal
                hideModal(modal);
                
                // Import the poster from Plex
                importFromPlex(filename, directory);
            });
        }
        
        // Click outside to close
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                hideModal(modal);
            }
        });
        
        // Escape key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('show')) {
                hideModal(modal);
            }
        });
    }
    
    // Import from Plex handler function
    function importFromPlexHandler(e) {
        e.preventDefault();
        const filename = this.getAttribute('data-filename');
        const directory = this.getAttribute('data-dirname');
        
        // Store the data in the modal
        const modal = document.getElementById('importFromPlexModal');
        const filenameElement = document.getElementById('importFromPlexFilename');
        
        filenameElement.textContent = filename;
        filenameElement.setAttribute('data-filename', filename);
        filenameElement.setAttribute('data-dirname', directory);
        
        // Show the modal
        showModal(modal);
    }
    
    // Function to send the import request to the server
    async function importFromPlex(filename, directory) {
        // Show a loading notification
        const notification = showImportingNotification();
        
        try {
            const formData = new FormData();
            formData.append('action', 'import_from_plex');
            formData.append('filename', filename);
            formData.append('directory', directory);
            
            const response = await fetch('./include/get-from-plex.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Show success notification
                showImportSuccessNotification();
                
                // Refresh the image to show the updated version
                refreshImage(filename, directory);
            } else {
                // Show error notification
                showImportErrorNotification(data.error || 'Failed to import poster from Plex');
            }
        } catch (error) {
            // Show error notification
            showImportErrorNotification('Error importing poster from Plex: ' + error.message);
        } finally {
            // Hide the sending notification
            notification.remove();
        }
    }
    
    // Function to show importing notification
    function showImportingNotification() {
        const notification = document.createElement('div');
        notification.className = 'plex-notification plex-sending';
        notification.innerHTML = `
            <div class="plex-notification-content">
                <div class="plex-spinner"></div>
                <span>Importing from Plex...</span>
            </div>
        `;
        document.body.appendChild(notification);
        
        // Force reflow to trigger animation
        notification.offsetHeight;
        notification.classList.add('show');
        
        return notification;
    }
    
    // Function to show success notification for imports
    function showImportSuccessNotification() {
        const notification = document.createElement('div');
        notification.className = 'plex-notification plex-success';
        notification.innerHTML = `
            <div class="plex-notification-content">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <span>Imported from Plex successfully!</span>
            </div>
        `;
        document.body.appendChild(notification);
        
        // Force reflow to trigger animation
        notification.offsetHeight;
        notification.classList.add('show');
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                notification.remove();
            }, 300); // Match transition duration
        }, 3000);
    }
    
    // Function to show error notification for imports
    function showImportErrorNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'plex-notification plex-error';
        notification.innerHTML = `
            <div class="plex-notification-content">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <span>${message}</span>
            </div>
        `;
        document.body.appendChild(notification);
        
        // Force reflow to trigger animation
        notification.offsetHeight;
        notification.classList.add('show');
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                notification.remove();
            }, 300); // Match transition duration
        }, 5000);
    }
    
    // =========== GALLERY FUNCTIONALITY ===========
    
    // Event Handler Functions
    function deleteHandler(e) {
        e.preventDefault();
        const filename = this.getAttribute('data-filename');
        const dirname = this.getAttribute('data-dirname');
        deleteFilenameInput.value = filename;
        deleteDirectoryInput.value = dirname;
        showModal(deleteModal);
    }

    function copyUrlHandler() {
        // Get the URL and encode spaces
        const url = this.getAttribute('data-url');
        const encodedUrl = url.replace(/ /g, '%20');
        
        try {
            navigator.clipboard.writeText(encodedUrl).then(() => {
                showCopyNotification();
            });
        } catch (err) {
            // Fallback for browsers that don't support clipboard API
            const textarea = document.createElement('textarea');
            textarea.value = encodedUrl;
            textarea.style.position = 'fixed';
            document.body.appendChild(textarea);
            textarea.select();
            
            try {
                document.execCommand('copy');
                showCopyNotification();
            } catch (e) {
                alert('Copy failed. Please select and copy the URL manually.');
            }
            
            document.body.removeChild(textarea);
        }
    }
    
    // Initialize all button handlers
    function initializeButtons() {
        // Copy buttons
        document.querySelectorAll('.copy-url-btn').forEach(button => {
            button.removeEventListener('click', copyUrlHandler);
            button.addEventListener('click', copyUrlHandler);
        });

        // Delete buttons
        document.querySelectorAll('.delete-btn').forEach(button => {
            button.removeEventListener('click', deleteHandler);
            button.addEventListener('click', deleteHandler);
        });
        
        // Send to Plex buttons
        document.querySelectorAll('.send-to-plex-btn').forEach(button => {
            button.removeEventListener('click', sendToPlexHandler);
            button.addEventListener('click', sendToPlexHandler);
        });
        
        // Import from Plex buttons
        document.querySelectorAll('.import-from-plex-btn').forEach(button => {
            button.removeEventListener('click', importFromPlexHandler);
            button.addEventListener('click', importFromPlexHandler);
        });
        
        // Initialize Plex-specific buttons
        initChangePosters();
        initializeSendToPlexButtons();
        initializeImportFromPlexButtons();
    }
    
    // Debounce function for resize handling
    function debounce(func, wait) {
        let timeout;
        return function() {
            const context = this, args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    }
    
    // Initialize caption truncation
    function initializeTruncation() {
        const captions = document.querySelectorAll('.gallery-caption');
        
        captions.forEach(caption => {
            const text = caption.textContent.trim();
            caption.textContent = text;
            
            const containerWidth = caption.clientWidth;
            
            const measurer = document.createElement('div');
            measurer.style.visibility = 'hidden';
            measurer.style.position = 'absolute';
            measurer.style.whiteSpace = 'nowrap';
            measurer.style.fontSize = window.getComputedStyle(caption).fontSize;
            measurer.style.fontFamily = window.getComputedStyle(caption).fontFamily;
            measurer.style.fontWeight = window.getComputedStyle(caption).fontWeight;
            measurer.style.padding = window.getComputedStyle(caption).padding;
            measurer.textContent = text;
            document.body.appendChild(measurer);

            const textWidth = measurer.offsetWidth;
            document.body.removeChild(measurer);

            if (textWidth > containerWidth * 0.9) {
                let truncated = text;
                let currentWidth = textWidth;
                
                let start = 0;
                let end = text.length;
                
                while (start < end) {
                    const mid = Math.floor((start + end + 1) / 2);
                    const testText = text.slice(0, mid) + '...';
                    measurer.textContent = testText;
                    document.body.appendChild(measurer);
                    currentWidth = measurer.offsetWidth;
                    document.body.removeChild(measurer);
                    
                    if (currentWidth <= containerWidth * 0.9) {
                        start = mid;
                    } else {
                        end = mid - 1;
                    }
                }
                
                truncated = text.slice(0, start) + '...';
                caption.textContent = truncated;
                caption.title = text; // Use native title for tooltip
                caption.style.cursor = 'help';
            }
        });
    }
    
    // Initialize gallery features (lazy loading, touch interactions)
    function initializeGalleryFeatures() {
        // Initialize lazy loading
        const observerOptions = {
            root: null,
            rootMargin: '50px',
            threshold: 0.1
        };
        
        const handleIntersection = (entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    const placeholder = img.previousElementSibling;
                    
                    if (!img.classList.contains('loaded')) {
                        img.src = img.dataset.src;
                        
                        img.onload = () => {
                            img.classList.add('loaded');
                            placeholder.classList.add('hidden');
                        };
                        
                        img.onerror = () => {
                            placeholder.innerHTML = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
                        };
                    }
                    
                    observer.unobserve(img);
                }
            });
        };
        
        const observer = new IntersectionObserver(handleIntersection, observerOptions);
        
        document.querySelectorAll('.gallery-image').forEach(img => {
            observer.observe(img);
        });

        // Handle touch interactions for gallery items
        let isScrolling = false;
        let scrollTimeout;
        let overlayActivationTime = 0;
        const OVERLAY_ACTIVATION_DELAY = 100; // Time in ms to wait before allowing button interactions
        
        // Add intercepting event listener for buttons
        document.querySelectorAll('.overlay-action-button').forEach(button => {
            button.addEventListener('touchstart', function(e) {
                // If overlay was just activated, prevent button interaction
                if (Date.now() - overlayActivationTime < OVERLAY_ACTIVATION_DELAY) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }, { capture: true }); // Use capture phase to intercept before regular handlers
        });

        // Handle touch interactions for gallery items
        document.querySelectorAll('.gallery-item').forEach(item => {
            let touchStartY = 0;
            let touchStartX = 0;
            let touchTimeStart = 0;

            item.addEventListener('touchstart', function(e) {
                if (isScrolling) return;
                
                if (!this.classList.contains('touch-active')) {
                    touchStartY = e.touches[0].clientY;
                    touchStartX = e.touches[0].clientX;
                    touchTimeStart = Date.now();
                }
            }, { passive: true });

            item.addEventListener('touchend', function(e) {
                if (isScrolling) return;
                
                // Handle initial tap to show overlay
                if (!this.classList.contains('touch-active')) {
                    const touchEndY = e.changedTouches[0].clientY;
                    const touchEndX = e.changedTouches[0].clientX;
                    const touchTime = Date.now() - touchTimeStart;
                    
                    const dy = Math.abs(touchEndY - touchStartY);
                    const dx = Math.abs(touchEndX - touchStartX);
                    
                    if (touchTime < 300 && dy < 10 && dx < 10) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // Remove active class from all other items
                        document.querySelectorAll('.gallery-item').forEach(otherItem => {
                            if (otherItem !== this) {
                                otherItem.classList.remove('touch-active');
                            }
                        });
                        
                        // Show this overlay and record the time
                        this.classList.add('touch-active');
                        overlayActivationTime = Date.now();
                    }
                }
            });
        });

        // Close active overlay when touching outside
        document.addEventListener('touchstart', function(e) {
            if (!isScrolling && 
                !e.target.closest('.gallery-item') && 
                !e.target.closest('.overlay-action-button')) {
                document.querySelectorAll('.gallery-item').forEach(item => {
                    item.classList.remove('touch-active');
                });
            }
        }, { passive: true });
        
        // Function to handle scroll events
        function handleScroll() {
            isScrolling = true;
            
            // Hide all overlay actions while scrolling
            document.querySelectorAll('.gallery-item').forEach(item => {
                item.classList.remove('touch-active');
            });
            
            // Clear the existing timeout
            clearTimeout(scrollTimeout);
            
            // Set a new timeout to mark scrolling as finished
            scrollTimeout = setTimeout(() => {
                isScrolling = false;
            }, 100);
        }

        // Add scroll event listener
        window.addEventListener('scroll', handleScroll, { passive: true });
    }
    
    // =========== CHANGE POSTER FUNCTIONALITY ===========
    
    // Initialize Change Poster buttons on all Plex posters
    function initChangePosters() {
        document.querySelectorAll('.gallery-item').forEach(item => {
            const filenameElement = item.querySelector('.gallery-caption');
            const overlayActions = item.querySelector('.image-overlay-actions');
            
            if (filenameElement && overlayActions) {
                const filename = filenameElement.getAttribute('data-full-text');
                
                // Only show the Change Poster button for files with "Plex" in the name
                if (isPlexFile(filename)) {
                    // Check if button already exists to avoid duplicates
                    if (!overlayActions.querySelector('.change-poster-btn')) {
                        // Get the delete button as reference for positioning
                        const deleteButton = overlayActions.querySelector('.delete-btn');
                        
                        if (deleteButton) {
                            const directoryValue = deleteButton.getAttribute('data-dirname');
                            const filenameValue = deleteButton.getAttribute('data-filename');
                            
                            const changePosterButton = document.createElement('button');
                            changePosterButton.className = 'overlay-action-button change-poster-btn';
                            changePosterButton.setAttribute('data-filename', filenameValue);
                            changePosterButton.setAttribute('data-dirname', directoryValue);
                            changePosterButton.innerHTML = `
                                <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                Change Poster
                            `;
                            
                            // Insert before Delete button
                            deleteButton.parentNode.insertBefore(changePosterButton, deleteButton);
                            
                            // Add event listener
                            changePosterButton.addEventListener('click', changePosterHandler);
                        }
                    }
                }
            }
        });
    }
    
    // Change Poster click handler
    function changePosterHandler(e) {
        e.preventDefault();
        const filename = this.getAttribute('data-filename');
        const dirname = this.getAttribute('data-dirname');
        
        // Update the modal with file info
        document.getElementById('changePosterFilename').textContent = `Changing poster: ${filename}`;
        document.getElementById('fileChangePosterOriginalFilename').value = filename;
        document.getElementById('fileChangePosterDirectory').value = dirname;
        document.getElementById('urlChangePosterOriginalFilename').value = filename;
        document.getElementById('urlChangePosterDirectory').value = dirname;
        
        // Reset file input
        fileChangePosterInput.value = '';
        const fileNameElement = fileChangePosterInput.parentElement.querySelector('.file-name');
        if (fileNameElement) {
            fileNameElement.textContent = '';
        }
        
        // Reset URL input
        const urlInput = urlChangePosterForm.querySelector('input[name="image_url"]');
        if (urlInput) {
            urlInput.value = '';
        }
        
        // Disable submit button until a file is selected
        const fileSubmitButton = fileChangePosterForm.querySelector('button[type="submit"]');
        if (fileSubmitButton) {
            fileSubmitButton.disabled = true;
        }
        
        // Hide any previous error messages
        hideChangeError();
        
        // Reset to file tab
        const fileTabs = changePosterModal.querySelectorAll('.upload-tab-btn');
        fileTabs.forEach(tab => {
            if (tab.getAttribute('data-tab') === 'file') {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });
        
        // Show file form, hide URL form
        fileChangePosterForm.classList.add('active');
        urlChangePosterForm.classList.remove('active');
        
        // Show the modal
        showModal(changePosterModal);
    }
    
    // =========== PLEX BUTTON INITIALIZATION ===========
    
    // Initialize Send to Plex buttons
    function initializeSendToPlexButtons() {
        // Add Send to Plex button to appropriate gallery items
        document.querySelectorAll('.gallery-item').forEach(item => {
            const filenameElement = item.querySelector('.gallery-caption');
            const overlayActions = item.querySelector('.image-overlay-actions');
            
            if (filenameElement && overlayActions) {
                const filename = filenameElement.getAttribute('data-full-text');
                
                // Only show the Send to Plex button for files with "Plex" in the name
                if (isPlexFile(filename)) {
                    // Check if button already exists to avoid duplicates
                    if (!overlayActions.querySelector('.send-to-plex-btn')) {
                        // Create button before the existing Delete button
                        const deleteButton = overlayActions.querySelector('.delete-btn');
                        
                        if (deleteButton) {
                            const directoryValue = deleteButton.getAttribute('data-dirname');
                            const filenameValue = deleteButton.getAttribute('data-filename');
                            
                            const sendToPlexButton = document.createElement('button');
                            sendToPlexButton.className = 'overlay-action-button send-to-plex-btn';
                            sendToPlexButton.setAttribute('data-filename', filenameValue);
                            sendToPlexButton.setAttribute('data-dirname', directoryValue);
                            sendToPlexButton.innerHTML = `
                                <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
                                    <polyline points="16 6 12 2 8 6"></polyline>
                                    <line x1="12" y1="2" x2="12" y2="15"></line>
                                </svg>
                                Send to Plex
                            `;
                            
                            // Insert before Delete button
                            deleteButton.parentNode.insertBefore(sendToPlexButton, deleteButton);
                            
                            // Add event listener
                            sendToPlexButton.addEventListener('click', sendToPlexHandler);
                        }
                    }
                }
            }
        });
    }
    
    // Initialize Import from Plex buttons
    function initializeImportFromPlexButtons() {
        // Add Import from Plex button to appropriate gallery items
        document.querySelectorAll('.gallery-item').forEach(item => {
            const filenameElement = item.querySelector('.gallery-caption');
            const overlayActions = item.querySelector('.image-overlay-actions');
            
            if (filenameElement && overlayActions) {
                const filename = filenameElement.getAttribute('data-full-text');
                
                // Only show the Import from Plex button for files with "Plex" in the name
                if (isPlexFile(filename)) {
                    // Check if button already exists to avoid duplicates
                    if (!overlayActions.querySelector('.import-from-plex-btn')) {
                        // Get the move button as reference
                        const moveButton = overlayActions.querySelector('.move-btn');
                        
                        if (moveButton) {
                            const directoryValue = moveButton.getAttribute('data-dirname');
                            const filenameValue = moveButton.getAttribute('data-filename');
                            
                            const importFromPlexButton = document.createElement('button');
                            importFromPlexButton.className = 'overlay-action-button import-from-plex-btn';
                            importFromPlexButton.setAttribute('data-filename', filenameValue);
                            importFromPlexButton.setAttribute('data-dirname', directoryValue);
                            importFromPlexButton.innerHTML = `
                                <svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
                                    <polyline points="10 17 15 12 10 7"></polyline>
                                    <line x1="15" y1="12" x2="3" y2="12"></line>
                                </svg>
                                Get from Plex
                            `;
                            
                            // Add event listener
                            importFromPlexButton.addEventListener('click', importFromPlexHandler);
                        }
                    }
                }
            }
        });
    }
    
    // =========== SEARCH FUNCTIONALITY ===========
    
    // Set autocomplete off to prevent browser behavior
    if (searchInput) {
        searchInput.setAttribute('autocomplete', 'off');
    }

    // Handle form submission (search button click or Enter key)
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const searchValue = searchForm.querySelector('.search-input').value;
            const currentUrl = new URL(window.location.href);
            
            if (searchValue) {
                currentUrl.searchParams.set('search', searchValue);
            } else {
                currentUrl.searchParams.delete('search');
            }
            
            // Maintain directory filter if exists
            const currentDirectory = currentUrl.searchParams.get('directory');
            if (currentDirectory) {
                currentUrl.searchParams.set('directory', currentDirectory);
            }
            
            // Update URL
            window.history.pushState({}, '', currentUrl.toString());
            
            // Perform search
            fetch(currentUrl.toString())
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const newDoc = parser.parseFromString(html, 'text/html');
                    
                    // Update stats
                    document.querySelector('.gallery-stats').innerHTML = 
                        newDoc.querySelector('.gallery-stats').innerHTML;
                    
                    // Update pagination
                    const paginationContainer = document.querySelector('.pagination');
                    const newPagination = newDoc.querySelector('.pagination');
                    
                    if (paginationContainer) {
                        if (newPagination) {
                            paginationContainer.style.display = 'flex';
                            paginationContainer.innerHTML = newPagination.innerHTML;
                        } else {
                            paginationContainer.style.display = 'none';
                        }
                    }
                    
                    // Get the gallery container
                    const galleryContainer = document.querySelector('.gallery');
                    
                    // Check if there are results
                    const newGallery = newDoc.querySelector('.gallery');
                    const noResults = newDoc.querySelector('.no-results');
                    
                    if (newGallery) {
                        // Show gallery with results
                        if (galleryContainer) {
                            galleryContainer.style.display = 'grid';
                            galleryContainer.innerHTML = newGallery.innerHTML;
                        }
                        // Remove any existing no-results message
                        const existingNoResults = document.querySelector('.no-results');
                        if (existingNoResults) {
                            existingNoResults.remove();
                        }
                    } else if (noResults) {
                        // Hide gallery
                        if (galleryContainer) {
                            galleryContainer.style.display = 'none';
                        }
                        // Remove any existing no-results message
                        const existingNoResults = document.querySelector('.no-results');
                        if (existingNoResults) {
                            existingNoResults.remove();
                        }
                        // Insert new no-results message after gallery stats
                        const galleryStats = document.querySelector('.gallery-stats');
                        galleryStats.insertAdjacentHTML('afterend', noResults.outerHTML);
                    }
                    
                    // Blur the search input to hide keyboard on mobile
                    if (searchInput) {
                        searchInput.blur();
                    }
                    
                    // Reinitialize observers and buttons
                    initializeGalleryFeatures();
                    initializeButtons();
                });
        });
    }
    
    // =========== DROPDOWN & ADDITIONAL EVENT LISTENERS ===========
    
    // Fix for Plex Import modal
    const plexImportButton = document.getElementById('showPlexImportModal');
    if (plexImportButton) {
        plexImportButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const plexModal = document.getElementById('plexImportModal');
            if (plexModal) {
                plexModal.style.display = 'block';
                setTimeout(() => {
                    plexModal.classList.add('show');
                }, 10);
            }
        });
    }
    
    // Fix for Jellyfin Import modal
    const jellyfinImportButton = document.getElementById('showJellyfinImportModal');
    if (jellyfinImportButton) {
        jellyfinImportButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const jellyfinModal = document.getElementById('jellyfinImportModal');
            if (jellyfinModal) {
                jellyfinModal.style.display = 'block';
                setTimeout(() => {
                    jellyfinModal.classList.add('show');
                }, 10);
            }
        });
    }
    
    // Prevent dropdown from closing when clicking inside it
    const dropdownContents = document.querySelectorAll('.dropdown-content');
    dropdownContents.forEach(content => {
        content.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
    
    // Handle browser back/forward
    window.addEventListener('popstate', function() {
        location.reload();
    });
    
    // =========== CLOSING BUTTON HANDLING ===========
    
    // For regular close buttons
    const closeButtons = document.querySelectorAll('.plex-import-modal-close, #closeImportModal, .close-button');
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            setTimeout(function() {
                window.location.reload();
            }, 300);
        });
    });
    
    // =========== INITIALIZATION ===========
    
    // Initial setup
    initializeGalleryFeatures();
    initializeButtons();
    initializeTruncation();
    initPlexConfirmModal();
    createImportFromPlexModal();
    initImportFromPlexFeature();
    
    // Call truncation after images load and on resize
    document.querySelectorAll('.gallery-image').forEach(img => {
        img.addEventListener('load', initializeTruncation);
    });
    
    // Debounced resize handler
    window.addEventListener('resize', debounce(initializeTruncation, 250));
});
</script>
</body>
</html>
