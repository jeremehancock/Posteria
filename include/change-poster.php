<?php
# Posteria: A Media Poster Collection App
# Change Poster Functionality
#
# Developed by Jereme Hancock
# https://github.com/jeremehancock/Posteria
#
# MIT License
#
# Copyright (c) 2024 Jereme Hancock

// Set headers
header('Content-Type: application/json');

// Make sure we catch all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error: [$errno] $errstr in $errfile on line $errline");
    
    // Return true to prevent the standard PHP error handler from running
    return true;
});

// Make sure all exceptions are caught
set_exception_handler(function($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    
    // Send a JSON response with the error
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unhandled exception: ' . $exception->getMessage()]);
    exit;
});

// Prevent direct output of errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Define helper functions
function getEnvWithFallback($key, $default) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

function getIntEnvWithFallback($key, $default) {
    $value = getenv($key);
    return $value !== false ? intval($value) : $default;
}

try {
    // Start session
    if (!session_id()) {
        session_start();
    }

    // Include configuration
    try {
        if (file_exists('../include/config.php')) {
            require_once '../include/config.php';
        } else if (file_exists('../config.php')) {
            require_once '../config.php';
        } else {
            throw new Exception("Config file not found in any of the expected locations");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Config file error: ' . $e->getMessage()]);
        exit;
    }

    // Check authentication
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    // Refresh session time
    $_SESSION['login_time'] = time();

    // Define directories
    $directories = [
        'movies' => '../posters/movies/',
        'tv-shows' => '../posters/tv-shows/',
        'tv-seasons' => '../posters/tv-seasons/',
        'collections' => '../posters/collections/'
    ];

    // Helper function to get allowed extensions
    function getAllowedExtensions() {
        return ['jpg', 'jpeg', 'png', 'webp'];
    }

    // Helper function to check if a filename is valid
    function isValidFilename($filename) {
        // Check for slashes and backslashes
        return strpos($filename, '/') === false && strpos($filename, '\\') === false;
    }

    // Get max file size from config
    $maxFileSize = 5 * 1024 * 1024; // Default 5MB

    // Change Poster functionality
    if (isset($_POST['action']) && $_POST['action'] === 'change_poster') {
        // Check required parameters
        if (!isset($_POST['original_filename'], $_POST['directory'])) {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            exit;
        }
        
        $originalFilename = $_POST['original_filename'];
        $directory = $_POST['directory'];
        $uploadType = isset($_POST['upload_type']) ? $_POST['upload_type'] : 'file';
        
        // Validate directory exists
        if (!isset($directories[$directory])) {
            echo json_encode(['success' => false, 'error' => 'Invalid directory']);
            exit;
        }
        
        // Security check for filename
        if (!isValidFilename($originalFilename)) {
            echo json_encode(['success' => false, 'error' => 'Invalid filename']);
            exit;
        }
        
        // Create full path to the original file
        $originalFilePath = $directories[$directory] . $originalFilename;
        
        // Check if original file exists
        if (!file_exists($originalFilePath)) {
            echo json_encode(['success' => false, 'error' => 'Original file not found']);
            exit;
        }
        
        // Create a backup of the original file
        $backupFilePath = $originalFilePath . '.backup';
        if (!copy($originalFilePath, $backupFilePath)) {
            echo json_encode(['success' => false, 'error' => 'Failed to create backup of original file']);
            exit;
        }
        
        // Get the original file extension
        $originalExt = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $allowedExtensions = getAllowedExtensions();
        
        $replacementSuccessful = false;
        
        // Handle different upload types
        if ($uploadType === 'file') {
            // File upload
            if (!isset($_FILES['new_poster'])) {
                echo json_encode(['success' => false, 'error' => 'No file uploaded']);
                exit;
            }
            
            $file = $_FILES['new_poster'];
            
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
                
                // Cleanup backup
                if (file_exists($backupFilePath)) {
                    unlink($backupFilePath);
                }
                
                echo json_encode(['success' => false, 'error' => $error_message]);
                exit;
            }
            
            // Validate file size
            if ($file['size'] > $maxFileSize) {
                // Cleanup backup
                if (file_exists($backupFilePath)) {
                    unlink($backupFilePath);
                }
                
                echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is ' . ($maxFileSize / 1024 / 1024) . 'MB']);
                exit;
            }
            
            // Validate file type
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions)) {
                // Cleanup backup
                if (file_exists($backupFilePath)) {
                    unlink($backupFilePath);
                }
                
                echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed types: ' . implode(', ', $allowedExtensions)]);
                exit;
            }
            
            // Try to replace the original file
            $replacementSuccessful = move_uploaded_file($file['tmp_name'], $originalFilePath);
            
        } else if ($uploadType === 'url') {
            // URL upload
            if (!isset($_POST['image_url']) || empty($_POST['image_url'])) {
                echo json_encode(['success' => false, 'error' => 'No image URL provided']);
                exit;
            }
            
            $url = $_POST['image_url'];
            
            // Validate URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                // Cleanup backup
                if (file_exists($backupFilePath)) {
                    unlink($backupFilePath);
                }
                
                echo json_encode(['success' => false, 'error' => 'Invalid URL format']);
                exit;
            }
            
            // Get file info from URL
            $urlExt = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            
            // Validate file type from URL
            if (!in_array($urlExt, $allowedExtensions)) {
                // Cleanup backup
                if (file_exists($backupFilePath)) {
                    unlink($backupFilePath);
                }
                
                echo json_encode(['success' => false, 'error' => 'Invalid file type in URL. Allowed types: ' . implode(', ', $allowedExtensions)]);
                exit;
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
                // Cleanup backup
                if (file_exists($backupFilePath)) {
                    unlink($backupFilePath);
                }
                
                echo json_encode(['success' => false, 'error' => 'Download failed: ' . $error]);
                exit;
            }
            
            // Check HTTP response code
            if ($httpCode !== 200) {
                // Cleanup backup
                if (file_exists($backupFilePath)) {
                    unlink($backupFilePath);
                }
                
                echo json_encode(['success' => false, 'error' => 'HTTP error: ' . $httpCode]);
                exit;
            }
            
            // Check file size
            $downloadedSize = strlen($fileContent);
            if ($downloadedSize > $maxFileSize) {
                // Cleanup backup
                if (file_exists($backupFilePath)) {
                    unlink($backupFilePath);
                }
                
                echo json_encode(['success' => false, 'error' => 'File exceeds maximum allowed size of ' . ($maxFileSize / 1024 / 1024) . 'MB']);
                exit;
            }
            
            // Verify the downloaded content is an image
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($fileContent);
            if (!str_starts_with($mimeType, 'image/')) {
                // Cleanup backup
                if (file_exists($backupFilePath)) {
                    unlink($backupFilePath);
                }
                
                echo json_encode(['success' => false, 'error' => 'Downloaded content is not an image']);
                exit;
            }
            
            // Try to save the file
            $replacementSuccessful = file_put_contents($originalFilePath, $fileContent) !== false;
        }
        
        // Process the replacement result
        if ($replacementSuccessful) {
            // Set proper permissions
            chmod($originalFilePath, 0644);
            
            // Delete the backup file
            if (file_exists($backupFilePath)) {
                unlink($backupFilePath);
            }
            
            echo json_encode(['success' => true]);
        } else {
            // If replacement fails, restore from backup
            if (file_exists($backupFilePath)) {
                copy($backupFilePath, $originalFilePath);
                unlink($backupFilePath);
            }
            
            echo json_encode(['success' => false, 'error' => 'Failed to replace poster. Please check directory permissions.']);
        }
        
        exit;
    }

    // Default response if no action matched
    echo json_encode(['success' => false, 'error' => 'Invalid action requested']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
