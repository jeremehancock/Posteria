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
function getEnvWithFallback($key, $default)
{
	$value = getenv($key);
	return $value !== false ? $value : $default;
}

// Helper function to get integer environment variable with fallback
function getIntEnvWithFallback($key, $default)
{
	$value = getenv($key);
	return $value !== false ? intval($value) : $default;
}

// Site title configuration
$site_title = getEnvWithFallback('SITE_TITLE', 'Posteria');

// Include configuration file
require_once './include/config.php';

// Include version checker
require_once './include/version.php';

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

// Get current directory filter from URL parameter
$currentDirectory = isset($_GET['directory']) ? trim($_GET['directory']) : '';
if (!empty($currentDirectory) && !isset($config['directories'][$currentDirectory])) {
	$currentDirectory = '';
}

/**
 * Utility Functions
 */

// Send JSON response and exit
function sendJsonResponse($success, $error = null)
{
	header('Content-Type: application/json');
	echo json_encode([
		'success' => $success,
		'error' => $error
	]);
	exit;
}

// Function to extract library name from filename
function extractLibraryName($filename)
{
	// Look for library name enclosed in double brackets [[Library Name]]
	if (preg_match('/\[\[(.*?)\]\]/', $filename, $matches)) {
		return $matches[1];
	}
	return '';
}

// Function to check if there are multiple libraries for a media type
function hasMultipleLibraries($allImages, $directory)
{
	$libraries = [];

	foreach ($allImages as $image) {
		if ($image['directory'] === $directory) {
			$libraryName = extractLibraryName($image['filename']);
			if (!empty($libraryName) && !in_array($libraryName, $libraries)) {
				$libraries[] = $libraryName;
			}
		}
	}

	return count($libraries) > 1;
}

// Function to generate directory badge with optional library name
function generateDirectoryBadge($image, $allImages)
{
	$directory = $image['directory'];
	$directoryName = formatDirectoryName($directory);

	// Don't show library name for collections
	if ($directory === 'collections') {
		return $directoryName;
	}

	// Check if we should display library name (only if multiple libraries exist)
	$hasMultipleLibraries = hasMultipleLibraries($allImages, $directory);

	if ($hasMultipleLibraries) {
		$libraryName = extractLibraryName($image['filename']);
		if (!empty($libraryName)) {
			return $directoryName . ': ' . $libraryName;
		}
	}

	return $directoryName;
}

// Get image files from directory
function getImageFiles($config, $currentDirectory = '')
{
	global $display_config;
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

	// Updated sorting function for getImageFiles
// Sort files based on configuration
	usort($files, function ($a, $b) use ($display_config) {
		// Check if we should sort by date added
		if (
			(isset($_GET['sort_by_date']) && filter_var($_GET['sort_by_date'], FILTER_VALIDATE_BOOLEAN)) ||
			(!isset($_GET['sort_by_date']) && isset($display_config['sort_by_date_added']) && $display_config['sort_by_date_added'] === true)
		) {
			// Extract the addedAt timestamp from both filenames using regex
			$addedAtA = 0;
			$addedAtB = 0;

			// Match the (A12345678) pattern
			if (preg_match('/\(A(\d+)\)/', $a['filename'], $matchesA)) {
				$addedAtA = (int) $matchesA[1];
			}

			if (preg_match('/\(A(\d+)\)/', $b['filename'], $matchesB)) {
				$addedAtB = (int) $matchesB[1];
			}

			// If both have addedAt timestamps, sort by them (most recent first)
			if ($addedAtA > 0 && $addedAtB > 0) {
				// Return negative value for descending order (newest first)
				return $addedAtB - $addedAtA;
			}

			// If only one has timestamp, put it first
			if ($addedAtA > 0) {
				return -1;
			}

			if ($addedAtB > 0) {
				return 1;
			}

			// If neither has timestamp, fall back to alphabetical sort
		}

		// Default alphabetical sorting (original logic)
		// Get filenames without extension
		$filenameA = pathinfo($a['filename'], PATHINFO_FILENAME);
		$filenameB = pathinfo($b['filename'], PATHINFO_FILENAME);

		// Remove Plex and Orphaned tags for cleaner comparison
		$filenameA = str_replace(['--Plex--', '--Orphaned--'], '', $filenameA);
		$filenameB = str_replace(['--Plex--', '--Orphaned--'], '', $filenameB);

		// Remove library brackets and their contents
		$filenameA = preg_replace('/\[\[[^\]]*\]\]|\[[^\]]*\]/', '', $filenameA);
		$filenameB = preg_replace('/\[\[[^\]]*\]\]|\[[^\]]*\]/', '', $filenameB);

		// Remove added timestamp pattern for sorting
		$filenameA = preg_replace('/\(A\d+\)/', '', $filenameA);
		$filenameB = preg_replace('/\(A\d+\)/', '', $filenameB);

		// Trim extra spaces
		$filenameA = trim($filenameA);
		$filenameB = trim($filenameB);

		// If configured to ignore articles, remove leading "The ", "A ", "An "
		if (isset($display_config['ignore_articles_in_sort']) && $display_config['ignore_articles_in_sort']) {
			$filenameA = preg_replace('/^(The|A|An)\s+/i', '', $filenameA);
			$filenameB = preg_replace('/^(The|A|An)\s+/i', '', $filenameB);
		}

		return strnatcasecmp($filenameA, $filenameB);
	});

	return $files;
}

// Improved fuzzy search implementation that prioritizes relevance
function improvedFuzzySearch($pattern, $str)
{
	// Normalize both strings
	$normalizedPattern = normalizeString(strtolower($pattern));
	$normalizedStr = normalizeString(strtolower($str));
	$originalStr = strtolower($str);
	$originalPattern = strtolower($pattern);

	// 1. Exact match (highest priority)
	if ($normalizedPattern === $normalizedStr || $originalPattern === $originalStr) {
		return true;
	}

	// 2. Direct substring match in original strings (high priority)
	if (stripos($str, $pattern) !== false) {
		return true;
	}

	// 3. Direct substring match in normalized strings (high priority)
	if (stripos($normalizedStr, $normalizedPattern) !== false) {
		return true;
	}

	// 4. Word boundary matches (medium-high priority)
	// Check if the pattern matches at word boundaries
	if (
		matchesWordBoundary($normalizedPattern, $normalizedStr) ||
		matchesWordBoundary($originalPattern, $originalStr)
	) {
		return true;
	}

	// 5. Starts with match (medium priority)
	if (
		stripos($normalizedStr, $normalizedPattern) === 0 ||
		stripos($originalStr, $originalPattern) === 0
	) {
		return true;
	}

	// 6. Very restrictive fuzzy match only for short patterns (low priority)
	// Only do fuzzy matching for patterns 4+ characters to avoid too many false positives
	if (mb_strlen($normalizedPattern) >= 4) {
		return restrictiveFuzzyMatch($normalizedPattern, $normalizedStr);
	}

	return false;
}

// Function to check word boundary matches
function matchesWordBoundary($pattern, $str)
{
	// Split the string into words
	$words = preg_split('/\s+/', $str);

	foreach ($words as $word) {
		// Check if any word starts with the pattern
		if (stripos($word, $pattern) === 0) {
			return true;
		}

		// Check if any word exactly matches the pattern
		if (strtolower($word) === strtolower($pattern)) {
			return true;
		}
	}

	return false;
}

// More restrictive fuzzy matching that requires consecutive character clusters
function restrictiveFuzzyMatch($pattern, $str)
{
	$patternLength = mb_strlen($pattern);
	$strLength = mb_strlen($str);

	if ($patternLength > $strLength) {
		return false;
	}

	// For restrictive fuzzy matching, require at least 70% of characters to match
	// in relatively close proximity
	$requiredMatches = max(3, ceil($patternLength * 0.7));
	$matchedChars = 0;
	$lastMatchPos = -2; // Start at -2 so first match at position 0 works
	$maxGap = 3; // Maximum gap between consecutive matches

	for ($i = 0; $i < $patternLength; $i++) {
		$char = mb_substr($pattern, $i, 1);
		$pos = mb_strpos($str, $char, max(0, $lastMatchPos + 1));

		if ($pos !== false && ($pos - $lastMatchPos) <= $maxGap) {
			$matchedChars++;
			$lastMatchPos = $pos;
		} else {
			// Try to find the character within a reasonable distance
			$searchStart = max(0, $lastMatchPos + 1);
			$searchEnd = min($strLength, $searchStart + $maxGap + 2);
			$found = false;

			for ($j = $searchStart; $j < $searchEnd; $j++) {
				if (mb_substr($str, $j, 1) === $char) {
					$matchedChars++;
					$lastMatchPos = $j;
					$found = true;
					break;
				}
			}

			if (!$found) {
				// Reset if we can't find consecutive matches
				break;
			}
		}
	}

	return $matchedChars >= $requiredMatches;
}

// Keep the existing normalizeString function as it was
function normalizeString($str)
{
	// Check if the Transliterator extension is available
	if (function_exists('transliterator_transliterate')) {
		// Convert accented characters to their ASCII equivalents
		$str = transliterator_transliterate('Any-Latin; Latin-ASCII', $str);
	} else {
		// Fallback for servers without the Transliterator extension
		$replacements = [
			// Common accented characters and their replacements
			'à' => 'a',
			'á' => 'a',
			'â' => 'a',
			'ã' => 'a',
			'ä' => 'a',
			'å' => 'a',
			'æ' => 'ae',
			'ç' => 'c',
			'č' => 'c',
			'è' => 'e',
			'é' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'ì' => 'i',
			'í' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ñ' => 'n',
			'ń' => 'n',
			'ò' => 'o',
			'ó' => 'o',
			'ô' => 'o',
			'õ' => 'o',
			'ö' => 'o',
			'ø' => 'o',
			'ù' => 'u',
			'ú' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'ý' => 'y',
			'ÿ' => 'y',
			'š' => 's',
			'ś' => 's',
			'ß' => 'ss',
			'ž' => 'z',
			'ź' => 'z',
			'đ' => 'd',
			'ł' => 'l',
			'ō' => 'o',
			'ū' => 'u',
			// Uppercase variants
			'À' => 'A',
			'Á' => 'A',
			'Â' => 'A',
			'Ã' => 'A',
			'Ä' => 'A',
			'Å' => 'A',
			'Æ' => 'AE',
			'Ç' => 'C',
			'Č' => 'C',
			'È' => 'E',
			'É' => 'E',
			'Ê' => 'E',
			'Ë' => 'E',
			'Ì' => 'I',
			'Í' => 'I',
			'Î' => 'I',
			'Ï' => 'I',
			'Ñ' => 'N',
			'Ń' => 'N',
			'Ò' => 'O',
			'Ó' => 'O',
			'Ô' => 'O',
			'Õ' => 'O',
			'Ö' => 'O',
			'Ø' => 'O',
			'Ù' => 'U',
			'Ú' => 'U',
			'Û' => 'U',
			'Ü' => 'U',
			'Ý' => 'Y',
			'Ÿ' => 'Y',
			'Š' => 'S',
			'Ś' => 'S',
			'Ž' => 'Z',
			'Ź' => 'Z',
			'Đ' => 'D',
			'Ł' => 'L',
			'Ō' => 'O',
			'Ū' => 'U'
		];

		$str = strtr($str, $replacements);
	}

	// Remove any remaining non-alphanumeric characters except spaces
	$str = preg_replace('/[^\p{L}\p{N}\s]/u', '', $str);

	return $str;
}

// Enhanced filter function with better relevance scoring
function filterImages($images, $searchQuery)
{
	if (empty($searchQuery)) {
		return $images;
	}

	$searchQueryLower = strtolower(trim($searchQuery));
	$results = [];

	// Special case for searching "orphaned"
	$searchingForOrphaned = ($searchQueryLower === 'orphaned');

	foreach ($images as $image) {
		$filename = pathinfo($image['filename'], PATHINFO_FILENAME);
		$fullFilename = strtolower($image['filename']);

		// Check for special search terms first
		if ($searchingForOrphaned) {
			if (strpos($fullFilename, '--plex--') === false) {
				$results[] = ['image' => $image, 'score' => 100];
			}
			continue;
		}

		// Clean filename for searching
		$cleanFilename = $filename;
		$cleanFilename = str_replace(['--Plex--', '--Orphaned--'], '', $cleanFilename);
		$cleanFilename = preg_replace('/\[\[.*?\]\]|\[.*?\]/s', '', $cleanFilename);
		$cleanFilename = preg_replace('/\s*\(A\d{8,12}\)\s*/', ' ', $cleanFilename);
		$cleanFilename = trim($cleanFilename);

		// Calculate relevance score
		$score = calculateRelevanceScore($searchQuery, $cleanFilename, $filename);

		if ($score > 0) {
			$results[] = ['image' => $image, 'score' => $score];
		}
	}

	// Sort by relevance score (highest first)
	usort($results, function ($a, $b) {
		return $b['score'] - $a['score'];
	});

	// Extract just the images from the scored results
	return array_map(function ($result) {
		return $result['image'];
	}, $results);
}

// Calculate relevance score for search results
function calculateRelevanceScore($searchQuery, $cleanFilename, $originalFilename)
{
	$query = strtolower(trim($searchQuery));
	$clean = strtolower($cleanFilename);
	$original = strtolower($originalFilename);

	// Exact match (highest score)
	if ($clean === $query || $original === $query) {
		return 1000;
	}

	// Starts with query (very high score)
	if (stripos($clean, $query) === 0) {
		return 900;
	}

	// Contains query as whole word (high score)
	if (preg_match('/\b' . preg_quote($query, '/') . '\b/i', $clean)) {
		return 800;
	}

	// Contains query as substring (medium-high score)
	if (stripos($clean, $query) !== false) {
		return 700;
	}

	// Word boundary match (medium score)
	if (matchesWordBoundary($query, $clean)) {
		return 600;
	}

	// Check if query matches start of any word (medium-low score)
	$words = preg_split('/\s+/', $clean);
	foreach ($words as $word) {
		if (stripos($word, $query) === 0 && strlen($word) > strlen($query)) {
			return 500;
		}
	}

	// Very restrictive fuzzy match for longer queries only (low score)
	if (strlen($query) >= 4 && restrictiveFuzzyMatch(normalizeString($query), normalizeString($clean))) {
		return 200;
	}

	return 0; // No match
}

// Check if user is logged in
function isLoggedIn()
{
	return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Validate filename for security
function isValidFilename($filename)
{
	// Check for slashes and backslashes
	if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
		return false;
	}
	return true;
}

// Format directory name for display
function formatDirectoryName($dirKey)
{
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
function generateUniqueFilename($originalName, $directory)
{
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
function isAllowedFileType($filename, $allowedExtensions)
{
	$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
	return in_array($ext, $allowedExtensions);
}

// Generate pagination links
// Generate pagination links
function generatePaginationLinks($currentPage, $totalPages, $searchQuery, $currentDirectory)
{
	$links = '';
	$params = [];
	if (!empty($searchQuery))
		$params['search'] = $searchQuery;
	if (!empty($currentDirectory))
		$params['directory'] = $currentDirectory;
	// Preserve sort_by_date parameter if present
	if (isset($_GET['sort_by_date']))
		$params['sort_by_date'] = $_GET['sort_by_date'];
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

if (isset($auth_config['auth_bypass']) && $auth_config['auth_bypass'] === true) {
	$_SESSION['logged_in'] = true;
	$_SESSION['auth_bypass'] = true;
	$_SESSION['login_time'] = time();
}

// Handle AJAX login
if (
	$_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login' &&
	isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
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


// Handle reset all request
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_all') {
	header('Content-Type: application/json');

	$deleteCount = 0;
	$errors = [];

	// Loop through all directories and delete all files
	foreach ($config['directories'] as $directory) {
		if (is_dir($directory)) {
			$files = glob($directory . '*');
			foreach ($files as $file) {
				if (is_file($file)) {
					if (unlink($file)) {
						$deleteCount++;
					} else {
						$errors[] = 'Failed to delete: ' . basename($file);
					}
				}
			}
		}
	}

	// Return results
	if (!empty($errors)) {
		echo json_encode([
			'success' => false,
			'error' => 'Some files could not be deleted',
			'deleted' => $deleteCount,
			'errors' => $errors
		]);
	} else {
		echo json_encode([
			'success' => true,
			'deleted' => $deleteCount,
			'message' => 'All posters have been deleted successfully'
		]);
	}
	exit;
}

/**
 * File Management Handlers
 */

// Handle delete all orphans request
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_all_orphans') {
	header('Content-Type: application/json');

	// Prepare response
	$response = [
		'success' => false,
		'deleted' => 0,
		'failed' => 0,
		'errors' => []
	];

	// Get all images
	$allImages = getImageFiles($config, '');
	$orphanedImages = [];

	// Filter orphaned images
	foreach ($allImages as $image) {
		if (strpos(strtolower($image['filename']), '--plex--') === false) {
			$orphanedImages[] = $image;
		}
	}

	// Begin deletion
	foreach ($orphanedImages as $image) {
		$filename = $image['filename'];
		$directory = $image['directory'];
		$filepath = $config['directories'][$directory] . $filename;

		// Security check: Ensure the file is within allowed directory
		if (!isValidFilename($filename) || !file_exists($filepath)) {
			$response['failed']++;
			$response['errors'][] = 'Invalid file: ' . $filename;
			continue;
		}

		if (unlink($filepath)) {
			$response['deleted']++;
		} else {
			$response['failed']++;
			$response['errors'][] = 'Failed to delete: ' . $filename;
		}
	}

	// Set success flag if at least one file was deleted
	if ($response['deleted'] > 0) {
		$response['success'] = true;
	}

	echo json_encode($response);
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
			$error_message = match ($file['error']) {
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
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
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
	<meta property="og:image"
		content="<?php echo htmlspecialchars($config['siteUrl']); ?>/assets/web-app-manifest-512x512.png" />
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
			display: flex;
			flex-direction: column;
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
			box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
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

		.orphan-button {
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

		.orphan-button.delete-btn {
			background: #8B0000;
			color: var(--text-primary);
			border: 1px solid #a83232;
			padding: 10px;
			width: 70%;
		}

		.delete {
			background: #8B0000;
			color: var(--text-primary);
			border: 1px solid #a83232;
			padding: 10px;
			border-radius: 6px;
			padding: 10px;
		}

		@media (max-width: 768px) {
			.delete {
				height: 38px;
				padding-top: 8px
			}

			.custom-tooltip {
				display: none !important;
			}
		}

		@media (max-width: 480px) {
			.delete {
				height: 38px;
				padding-top: 8px
			}

			.custom-tooltip {
				display: none !important;
			}
		}

		.orphan-button svg {
			margin-right: 8px;
		}

		.delete:hover {
			transform: translateY(-2px);
			cursor: pointer;
		}

		.orphan-button.delete-btn:hover {
			transform: translateY(-2px);
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
			font-size: 14px;
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
			margin-bottom: 20px;
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
			display: flex;
			flex: 1;
			justify-content: center;
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
			max-width: calc(100% - 20px);
			/* Leave 10px margin on each side */
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
		}

		/* Hover effect to show full text if it's truncated */
		.directory-badge:hover {
			white-space: normal;
			word-break: break-word;
			z-index: 5;
			max-width: 90%;
		}

		/* Delete Orphans Button */
		.delete-orphans-btn {
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
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
		}

		.delete-orphans-btn:hover {
			background: linear-gradient(45deg, #f5b025, #ffa953);
			transform: translateY(-2px);
		}

		.delete-orphans-icon {
			width: 20px;
			height: 20px;
		}

		/* Make orphaned badge more prominent */
		.orphaned-badge {
			position: absolute;
			bottom: 0;
			padding: 4px 8px;
			z-index: 0;
			width: 100%;
			font-size: 1rem;
			background: #8B0000;
			color: var(--text-primary);
			justify-content: center;
		}

		/* Delete progress styles */
		#deleteOrphansProgress {
			margin-top: 20px;
		}

		/* Media query adjustments for the button */
		@media (max-width: 768px) {
			.delete-orphans-btn {
				height: 40px;
			}
		}

		@media (max-width: 480px) {
			.delete-orphans-btn {
				height: 38px;
			}
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
			padding-top: 10px;
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
			padding-right: 20px;
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
			height: 44px;
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
			align-items: end;
		}

		/* Custom File Input */
		.custom-file-input {
			position: relative;
			display: inline-block;
			flex: 1;
			align-self: end;
			margin-bottom: 8px;
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

		.plex-notification.plex-loading {
			background: #000;
			color: #fff;
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
			0% {
				transform: rotate(0deg);
			}

			100% {
				transform: rotate(360deg);
			}
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
			0% {
				transform: rotate(0deg);
			}

			100% {
				transform: rotate(360deg);
			}
		}


		/* ==========================================================================
		   19. Footer
		   ========================================================================== */
		.footer {
			margin-top: 15px;
			padding: 30px 20px;
		}

		.footer-content {
			max-width: 1200px;
			margin: 0 auto;
			display: flex;
			flex-direction: row-reverse;
			justify-content: center;
			align-items: center;
			gap: 10px;
		}

		.copyright {
			color: var(--text-secondary);
			font-size: 14px;
			letter-spacing: 0.02em;
		}

		.footer-links {
			display: flex;
			gap: 24px;
			justify-content: center;
		}

		.footer-link {
			color: var(--text-secondary);
			transition: all 0.3s ease;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 8px;
			border-radius: 50%;
			background: var(--bg-tertiary);
		}

		.footer-link:hover {
			color: var(--accent-primary);
			transform: translateY(-3px);
			box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
		}

		.footer-icon {
			width: 20px;
			height: 20px;
		}

		/* Update badge */
		.update-available {
			color: var(--text-secondary);
			text-decoration: none;
			position: relative;
			padding-right: 5px;
		}

		.update-available:hover {
			color: var(--accent-primary);
		}

		.update-badge {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			background: var(--accent-primary);
			color: var(--bg-primary);
			width: 16px;
			height: 16px;
			font-size: 12px;
			border-radius: 50%;
			margin-left: 5px;
			font-weight: bold;
			animation: pulse 2s infinite;
		}

		@keyframes pulse {
			0% {
				transform: scale(1);
			}

			50% {
				transform: scale(1.1);
			}

			100% {
				transform: scale(1);
			}
		}

		/* ==========================================================================
		   21. Multi Select
		   ========================================================================== */

		/* Enhanced Multi-Select Styles for Library Selection */

		/* Basic styling for the multi-select */
		#plexLibrary {
			padding: 12px 16px;
			background: var(--bg-secondary);
			border: 1px solid var(--border-color);
			border-radius: 6px;
			color: var(--text-primary);
			width: 100%;
			height: auto;
			min-height: 44px;
			max-height: 200px;
			box-sizing: border-box;
			font-family: inherit;
			font-size: 14px;
			transition: all 0.2s ease;
			overflow-y: auto;
			scrollbar-width: thin;
			scrollbar-color: var(--bg-tertiary) var(--bg-secondary);
		}

		/* Custom scrollbar for the multi-select */
		#plexLibrary::-webkit-scrollbar {
			width: 8px;
			height: 8px;
			background-color: var(--bg-secondary);
		}

		#plexLibrary::-webkit-scrollbar-track {
			background: var(--bg-secondary);
			border-radius: 4px;
		}

		#plexLibrary::-webkit-scrollbar-thumb {
			background: var(--bg-tertiary);
			border-radius: 4px;
			border: 2px solid var(--bg-secondary);
			min-height: 30px;
		}

		#plexLibrary::-webkit-scrollbar-thumb:hover {
			background: var(--accent-primary);
		}

		/* Focus state */
		#plexLibrary:focus {
			outline: none;
			border-color: var(--accent-primary);
			box-shadow: 0 0 0 1px rgba(229, 160, 13, 0.2);
			background-color: var(--bg-tertiary);
		}

		/* Hover state */
		#plexLibrary:hover {
			border-color: var(--accent-primary);
			background-color: var(--bg-tertiary);
		}

		/* Option styling */
		#plexLibrary option {
			padding: 10px 12px;
			background-color: var(--bg-secondary);
			color: var(--text-primary);
			border-bottom: 1px solid rgba(59, 59, 59, 0.3);
			cursor: pointer;
			transition: all 0.2s ease;
		}

		/* Last option no border */
		#plexLibrary option:last-child {
			border-bottom: none;
		}

		/* Hover and selected states for options */
		#plexLibrary option:hover,
		#plexLibrary option:focus {
			background-color: var(--bg-tertiary);
		}

		#plexLibrary option:checked {
			background-color: rgba(229, 160, 13, 0.2);
			color: var(--accent-primary);
			font-weight: bold;
		}

		/* Selection instructions */
		.multiselect-instructions {
			margin-top: 6px;
			color: var(--text-secondary);
			font-size: 0.9em;
			line-height: 1.4;
			display: flex;
			align-items: center;
		}

		.multiselect-instructions svg {
			margin-right: 6px;
			flex-shrink: 0;
		}

		/* Container for multiselect */
		.multiselect-container {
			position: relative;
		}

		/* Selection count badge */
		.selection-count {
			position: absolute;
			top: -8px;
			right: -8px;
			background: linear-gradient(45deg, var(--accent-primary), #ff9f43);
			color: #1f1f1f;
			font-weight: bold;
			font-size: 12px;
			border-radius: 50%;
			width: 24px;
			height: 24px;
			display: flex;
			align-items: center;
			justify-content: center;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
			border: 2px solid var(--bg-secondary);
			opacity: 0;
			transform: scale(0.8);
			transition: all 0.3s ease;
		}

		.selection-count.visible {
			opacity: 1;
			transform: scale(1);
		}

		/* Responsive adjustments */
		@media (max-width: 768px) {
			#plexLibrary {
				max-height: 150px;
			}

			.multiselect-instructions {
				font-size: 0.8em;
			}
		}

		@media (max-width: 480px) {
			#plexLibrary {
				max-height: 120px;
				font-size: 13px;
			}

			#plexLibrary option {
				padding: 8px 10px;
			}
		}

		/* ==========================================================================
		   21. Media Queries
		   ========================================================================== */

		/* Hide the Export text on mobile devices */
		@media screen and (max-width: 768px) {
			.export-text {
				display: none;
			}

			header {
				margin-bottom: 0px;
			}


			/* Optionally adjust padding for mobile to make it more of an icon button */
			.upload-trigger-button {
				padding: 8px;
			}
		}

		/* Hide the Export text on mobile devices */
		@media screen and (max-width: 480px) {
			.export-text {
				display: none;
			}

			header {
				margin-bottom: 0px;
			}

			/* Optionally adjust padding for mobile to make it more of an icon button */
			.upload-trigger-button {
				padding: 8px;
			}
		}

		@media (max-width: 480px) {
			.footer {
				padding: 20px 15px;
			}

			.footer-content {
				gap: 15px;
			}

			.footer-links {
				gap: 16px;
			}

			.footer-link {
				width: 36px;
				height: 36px;
			}
		}

		@media (hover: none) {
			.gallery-item:hover {
				transform: none;
				box-shadow: none;
				border: none;
				border-color: unset;
			}

			.gallery-item.touch-active {
				border-color: var(--accent-primary);
				box-shadow: var(--shadow-md);
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
				flex: 1;
				justify-content: space-between;
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
				height: 75px;
				margin-left: -18px;
			}

			.auth-actions {
				gap: 8px;
			}

			.search-input,
			.search-button {
				padding: 12px 16px;
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

			.orphan-button.delete-btn {
				width: 87%;
			}
		}

		@media (max-width: 480px) {
			.orphan-button.delete-btn {
				width: 87%;
			}

			.site-title {
				display: none;
			}

			.filter-button {
				padding: 3px 10px;
			}

			.filter-buttons {
				flex-wrap: nowrap;
				gap: 0;
				flex: 1;
				justify-content: space-between;

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
				height: 75px;
				margin-left: -18px;
			}

			.auth-actions {
				justify-content: center;
				gap: 8px;
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
			.send-to-plex-confirm,
			.import-from-plex-confirm {
				height: 38px;
			}

			.overlay-action-button {
				height: 35px;
				width: 87%;
			}

			.search-button {
				height: 44px;
			}

			.filter-button {
				height: 30px;
			}
		}

		.poster-wall-button {
			background: transparent;
			border: none;
			poster-wall cursor: pointer;
			padding: 8px;
			margin-right: -5px;
			display: flex;
			align-items: center;
			justify-content: center;
			border-radius: 50%;
			transition: all 0.2s ease;
		}

		.poster-wall-button:hover {
			background-color: rgba(229, 160, 13, 0.1);
			transform: scale(1.1);
		}

		.poster-wall-button svg {
			width: 30px;
			height: 30px;
			fill: none;
			stroke: var(--accent-primary);
			filter: drop-shadow(0 1px 3px rgba(0, 0, 0, 0.3));
		}

		@media (max-width: 768px) {
			.poster-wall-button {
				padding: 6px;
			}

			.poster-wall-button svg {
				width: 28px;
				height: 28px;
			}
		}

		@media (max-width: 480px) {
			.poster-wall-button {
				padding: 5px;
			}

			.poster-wall-button svg {
				width: 26px;
				height: 26px;
			}
		}

		.sort-by-date-button {
			background: transparent;
			border: none;
			cursor: pointer;
			padding: 8px;
			margin-right: 8px;
			display: flex;
			align-items: center;
			justify-content: center;
			border-radius: 50%;
			transition: all 0.2s ease;
		}

		.sort-by-date-button:hover {
			background-color: rgba(229, 160, 13, 0.1);
			transform: scale(1.1);
		}

		.sort-by-date-button svg {
			width: 30px;
			height: 30px;
			fill: var(--text-secondary);
			filter: drop-shadow(0 1px 3px rgba(0, 0, 0, 0.3));
		}

		.sort-by-date-button.active svg {
			fill: var(--accent-primary);
		}

		@media (max-width: 768px) {
			.sort-by-date-button {
				padding: 6px;
			}

			.sort-by-date-button svg {
				width: 28px;
				height: 28px;
			}
		}

		@media (max-width: 480px) {
			.sort-by-date-button {
				padding: 5px;
			}

			.sort-by-date-button svg {
				width: 26px;
				height: 26px;
			}
		}

		.support-button {
			background: transparent;
			border: none;
			cursor: pointer;
			padding: 8px;
			margin-right: -6px;
			display: flex;
			align-items: center;
			justify-content: center;
			border-radius: 50%;
			transition: all 0.2s ease;
		}

		.support-button:hover {
			background-color: rgba(231, 76, 60, 0.1);
			transform: scale(1.1);
		}

		.support-button svg {
			width: 30px;
			height: 30px;
			filter: drop-shadow(0 1px 3px rgba(0, 0, 0, 0.3));
		}

		@media (max-width: 768px) {
			.support-button {
				padding: 6px;
			}

			.support-button svg {
				width: 28px;
				height: 28px;
			}
		}

		@media (max-width: 480px) {
			.support-button {
				padding: 5px;
			}

			.support-button svg {
				width: 26px;
				height: 26px;
			}
		}

		/* Support modal specific styles */
		.support-content {
			text-align: center;
		}

		.donate-section {
			margin: 20px 0;
			padding: 20px;
			background: rgba(229, 160, 13, 0.1);
			border-radius: 12px;
			border: 1px solid var(--accent-primary);
		}

		.reset-button {
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
			background: #8B0000;
			color: var(--text-primary);
			border: 1px solid #a83232;
		}

		.reset-button:hover {
			background: #a31c1c;
			transform: translateY(-2px);
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
			border-color: #c73e3e;
		}

		@media (max-width: 768px) {
			.reset-button {
				height: 40px;
				padding: 10px;
			}
		}

		@media (max-width: 480px) {
			.reset-button {
				height: 38px;
				padding: 8px;
			}
		}
	</style>
	<script>
		// This small snippet of CSS will prevent the button flash
		document.write(`
	  <style>
		/* Initially hide the buttons until JavaScript decides what to show */
		.orphan-button.delete-btn:not(.initialized) {
		  display: none !important;
		}
	  </style>
	  `);
	</script>
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

		<!-- Reset Modal -->
		<div id="resetModal" class="modal">
			<div class="modal-content">
				<div class="modal-header">
					<h3>Reset Posteria</h3>
					<button type="button" class="modal-close-btn">×</button>
				</div>
				<div class="modal-body">
					<p>Are you sure you want to delete <strong>ALL</strong> posters? This action cannot be undone.</p>
					<div
						style="margin: 20px 0; padding: 15px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px; text-align: justify;">
						<p style="margin: 0;">⚠️ Warning: This will permanently delete all posters in your Posteria
							collection.</p>
						<p style="margin-top: 10px;">This will not remove any posters from Plex.</p>
					</div>
					<form id="resetForm" method="POST">
						<input type="hidden" name="action" value="reset_all">
						<div class="modal-actions">
							<button type="button" class="modal-button cancel" id="cancelReset">Cancel</button>
							<button type="submit" class="modal-button delete">Reset</button>
						</div>
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

				<p id="changePosterFilename" style="margin: 10px 24px; font-weight: 500; overflow-wrap: break-word;"
					data-filename="" data-dirname=""></p>

				<div class="plex-info"
					style="margin: 0 24px 10px; padding: 10px; background: rgba(46, 213, 115, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px; text-align: center;">
					<span>Poster will be automatically updated in Plex</span>
				</div>

				<div class="upload-tabs">
					<button class="upload-tab-btn active" data-tab="file">Upload from Disk</button>
					<button class="upload-tab-btn" data-tab="url">Upload from URL</button>
				</div>

				<div class="upload-content">
					<!-- Common error message div for both forms -->
					<div class="change-poster-error"
						style="display: none; color: var(--danger-color); background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); padding: 12px; border-radius: 6px; margin-bottom: 16px;">
					</div>

					<form id="fileChangePosterForm" class="upload-form active" method="POST"
						enctype="multipart/form-data">
						<input type="hidden" name="action" value="change_poster">
						<input type="hidden" name="upload_type" value="file">
						<input type="hidden" name="original_filename" id="fileChangePosterOriginalFilename">
						<input type="hidden" name="directory" id="fileChangePosterDirectory">

						<div class="upload-input-group">
							<div class="custom-file-input">
								<input type="file" name="new_poster" id="fileChangePosterInput"
									accept=".jpg,.jpeg,.png,.webp">
								<label for="fileChangePosterInput">
									<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24"
										fill="none" stroke="currentColor" stroke-width="2">
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
							<button type="button" class="modal-button cancel" id="cancelChangePoster">Cancel</button>
							<button type="submit" class="modal-button" disabled
								style="margin-top: 32px;">Change</button>
						</div>
						<div class="upload-help">
							Maximum file size:
							<?php echo htmlspecialchars(round($config['maxFileSize'] / (1024 * 1024), 2) . ' MB'); ?><br>
							Allowed types: jpg, jpeg, png, webp
						</div>
					</form>

					<form id="urlChangePosterForm" class="upload-form" method="POST">
						<input type="hidden" name="action" value="change_poster">
						<input type="hidden" name="upload_type" value="url">
						<input type="hidden" name="original_filename" id="urlChangePosterOriginalFilename">
						<input type="hidden" name="directory" id="urlChangePosterDirectory">

						<div class="upload-input-group">
							<input type="url" name="image_url" class="login-input" placeholder="Enter poster URL..."
								required>
						</div>
						<div class="upload-input-group">
							<button type="button" class="modal-button cancel" id="cancelChangePosterUrl">Cancel</button>
							<button type="submit" class="modal-button" style="margin-top: 32px;">Change</button>
						</div>
						<div class="upload-help">
							Maximum file size:
							<?php echo htmlspecialchars(round($config['maxFileSize'] / (1024 * 1024), 2) . ' MB'); ?><br>
							Allowed types: jpg, jpeg, png, webp
						</div>
					</form>
				</div>
			</div>
		</div>

		<div id="plexConfirmModal" class="modal">
			<div class="modal-content">
				<div class="modal-header">
					<h3>Send to Plex</h3>
					<button type="button" class="modal-close-btn">×</button>
				</div>
				<div class="modal-body">
					<p>Are you sure you want to replace the Plex poster with this one?</p>
					<p id="plexConfirmFilename"
						style="margin-top: 10px; font-weight: 500; overflow-wrap: break-word; display: none;"
						data-filename="" data-dirname=""></p>
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
					<div class="upload-error"
						style="display: none; color: var(--danger-color); background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); padding: 12px; border-radius: 6px; margin-bottom: 16px;">
					</div>

					<form id="fileUploadForm" class="upload-form active" method="POST" enctype="multipart/form-data">
						<input type="hidden" name="action" value="upload">
						<input type="hidden" name="upload_type" value="file">
						<div class="upload-input-group">
							<div class="custom-file-input">
								<input type="file" name="image" id="fileInput"
									accept="<?php echo '.' . implode(',.', $config['allowedExtensions']); ?>">
								<label for="fileInput">
									<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 0 24 24"
										fill="none" stroke="currentColor" stroke-width="2">
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
							<input type="url" name="image_url" class="login-input" placeholder="Enter poster URL..."
								required>
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
								<rect x="0" y="0" width="70" height="100" rx="6" fill="#E5A00D" opacity="0.4" />

								<!-- Middle poster -->
								<rect x="20" y="15" width="70" height="100" rx="6" fill="#E5A00D" opacity="0.7" />

								<!-- Front poster -->
								<rect x="40" y="30" width="70" height="100" rx="6" fill="#E5A00D" />

								<!-- Play button -->
								<circle cx="75" cy="80" r="25" fill="white" />
								<path d="M65 65 L95 80 L65 95 Z" fill="#E5A00D" />
							</g>
						</svg>
						<span class="site-title"><?php echo htmlspecialchars($site_title); ?></span>
					</h1>
				</a>

				<div class="auth-actions">

					<div>
						<button id="showSupportModal" class="support-button tooltip-trigger"
							data-tooltip="Support Development">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#e74c3c" width="24"
								height="24">
								<path
									d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z">
								</path>
							</svg>
						</button>
					</div>
					<div>
						<a href="./poster-wall" class="poster-wall-button tooltip-trigger" data-tooltip="Poster Wall"
							target="_blank">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
								fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
								stroke-linejoin="round">
								<rect x="3" y="3" width="7" height="9"></rect>
								<rect x="14" y="3" width="7" height="5"></rect>
								<rect x="14" y="12" width="7" height="9"></rect>
								<rect x="3" y="16" width="7" height="5"></rect>
							</svg>
						</a>
					</div>
					<div>
						<button id="sortByDateButton" class="sort-by-date-button tooltip-trigger"
							data-tooltip="Toggle Sort by Date Added">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52"
								enable-background="new 0 0 52 52" xml:space="preserve">
								<path
									d="M43.6,6.8h-4V5.2c0-1.8-1.4-3.2-3.2-3.2c-1.8,0-3.2,1.4-3.2,3.2v1.6H18.8V5.2c0-1.8-1.4-3.2-3.2-3.2 s-3.2,1.4-3.2,3.2v1.6h-4c-2.6,0-4.8,2.2-4.8,4.8v1.6c0,0.9,0.7,1.6,1.6,1.6h41.6c0.9,0,1.6-0.7,1.6-1.6v-1.6 C48.4,9,46.2,6.8,43.6,6.8z">
								</path>
								<path
									d="M46.8,19.6H5.2c-0.9,0-1.6,0.7-1.6,1.6v24c0,2.6,2.2,4.8,4.8,4.8h35.2c2.6,0,4.8-2.2,4.8-4.8v-24 C48.4,20.3,47.7,19.6,46.8,19.6z M26,46.7c-6.6,0-11.9-5.4-11.9-11.9c0-6.6,5.4-11.9,11.9-11.9s11.9,5.4,11.9,11.9 C37.9,41.4,32.6,46.7,26,46.7z">
								</path>
								<path
									d="M27.2,34.3v-5.1c0-0.4-0.4-0.8-0.8-0.8h-0.8c-0.4,0-0.8,0.4-0.8,0.8v5.6c0,0.3,0.1,0.6,0.4,0.8l3.8,3.8 c0.3,0.3,0.8,0.3,1.1,0l0.6-0.6c0.3-0.3,0.3-0.8,0-1.1L27.2,34.3z">
								</path>
							</svg>
						</button>
					</div>
					<?php if (isLoggedIn()): ?>
					<?php if ((!empty($plex_config['token']) && !empty($plex_config['server_url']))): ?>
					<div>
						<button class="upload-trigger-button" id="showPlexImportModal" title="Import Posters from Plex">
							<svg class="upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
								stroke="currentColor" stroke-width="2">
								<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
								<polyline points="8 7 3 12 8 17"></polyline>
								<line x1="3" y1="12" x2="15" y2="12"></line>
							</svg>
							<span class="export-text">Import</span>
						</button>
					</div>

					<?php
					// Check if there are any orphaned files
					$hasOrphans = false;
					$allImages = getImageFiles($config, '');
					foreach ($allImages as $image) {
						if (strpos(strtolower($image['filename']), '--plex--') === false) {
							$hasOrphans = true;
							break;
						}
					}

					if ($hasOrphans):
						?>
					<div>
						<button class="delete" id="showDeleteOrphansModal" title="Delete All Orphan Posters">
							<svg height="20px" width="20px" xmlns="http://www.w3.org/2000/svg"
								shape-rendering="geometricPrecision" text-rendering="geometricPrecision"
								image-rendering="optimizeQuality" fill-rule="evenodd" clip-rule="evenodd"
								viewBox="0 0 456 511.82">
								<path fill="#fff"
									d="M48.42 140.13h361.99c17.36 0 29.82 9.78 28.08 28.17l-30.73 317.1c-1.23 13.36-8.99 26.42-25.3 26.42H76.34c-13.63-.73-23.74-9.75-25.09-24.14L20.79 168.99c-1.74-18.38 9.75-28.86 27.63-28.86zM24.49 38.15h136.47V28.1c0-15.94 10.2-28.1 27.02-28.1h81.28c17.3 0 27.65 11.77 27.65 28.01v10.14h138.66c.57 0 1.11.07 1.68.13 10.23.93 18.15 9.02 18.69 19.22.03.79.06 1.39.06 2.17v42.76c0 5.99-4.73 10.89-10.62 11.19-.54 0-1.09.03-1.63.03H11.22c-5.92 0-10.77-4.6-11.19-10.38 0-.72-.03-1.47-.03-2.23v-39.5c0-10.93 4.21-20.71 16.82-23.02 2.53-.45 5.09-.37 7.67-.37zm83.78 208.38c-.51-10.17 8.21-18.83 19.53-19.31 11.31-.49 20.94 7.4 21.45 17.57l8.7 160.62c.51 10.18-8.22 18.84-19.53 19.32-11.32.48-20.94-7.4-21.46-17.57l-8.69-160.63zm201.7-1.74c.51-10.17 10.14-18.06 21.45-17.57 11.32.48 20.04 9.14 19.53 19.31l-8.66 160.63c-.52 10.17-10.14 18.05-21.46 17.57-11.31-.48-20.04-9.14-19.53-19.32l8.67-160.62zm-102.94.87c0-10.23 9.23-18.53 20.58-18.53 11.34 0 20.58 8.3 20.58 18.53v160.63c0 10.23-9.24 18.53-20.58 18.53-11.35 0-20.58-8.3-20.58-18.53V245.66z" />
							</svg>
						</button>
					</div>
					<?php endif; ?>
					<?php endif; ?>

					<?php if (count($allImages) > 0): ?>
					<div>
						<button id="showResetModal" class="reset-button tooltip-trigger" data-tooltip="Reset Posteria">
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
								fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
								stroke-linejoin="round">
								<path d="M3 2v6h6"></path>
								<path d="M3 13a9 9 0 1 0 3-7.7L3 8"></path>
							</svg>
						</button>
					</div>
					<?php endif; ?>
					<?php if (($_SESSION['auth_bypass'] !== true)): ?>
					<a href="?action=logout" class="logout-button" title="Logout">
						<svg class="logout-icon" xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 28 28" fill="none"
							stroke="currentColor" stroke-width="2" style="margin-left: 5px;">
							<!-- Half Circle (using M start, A arc, Z close) -->
							<path d="M7 4 A 8 8 0 0 0 7 20" stroke-linecap="round"></path>
							<polyline points="14 7 19 12 14 17" stroke-linecap="round" stroke-linejoin="round">
							</polyline>
							<line x1="19" y1="12" x2="7" y2="12" stroke-linecap="round"></line>
						</svg>
					</a>
					<?php endif; ?>
				</div>
				<?php else: ?>
				<button id="showLoginModal" class="login-trigger-button">
					<svg class="login-icon" xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 28 28" fill="none"
						stroke="currentColor" stroke-width="2">
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

		<div id="deleteOrphansModal" class="modal">
			<div class="modal-content">
				<div class="modal-header">
					<h3>Delete All Orphan Posters</h3>
					<button type="button" class="modal-close-btn">×</button>
				</div>
				<div class="modal-body">
					<?php
					// Count orphaned files
					$orphanCount = 0;
					$allImages = getImageFiles($config, '');
					foreach ($allImages as $image) {
						if (strpos(strtolower($image['filename']), '--plex--') === false) {
							$orphanCount++;
						}
					}
					?>
					<p>Are you sure you want to delete all orphaned posters? This action cannot be undone.</p>
					<div
						style="margin: 20px 0; padding: 15px; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px; text-align: center;">
						<p style="margin: 0;">This will delete <?php echo $orphanCount; ?> orphaned posters.</p>
					</div>
					<div id="deleteOrphansProgress" style="display: none;">
						<div
							style="height: 8px; background: #333; border-radius: 4px; margin: 16px 0; overflow: hidden;">
							<div id="deleteOrphansProgressBar"
								style="height: 100%; width: 0%; background: linear-gradient(45deg, var(--accent-primary), #ff9f43); transition: width 0.3s;">
							</div>
						</div>
						<div id="deleteOrphansProgressText"
							style="text-align: center; margin-top: 8px; color: var(--text-secondary);">
							Processing 0 of <?php echo $orphanCount; ?> files
						</div>
					</div>
					<form id="deleteOrphansForm" method="POST">
						<input type="hidden" name="action" value="delete_all_orphans">
						<div class="modal-actions">
							<button type="button" class="modal-button cancel" id="cancelDeleteOrphans">Cancel</button>
							<button type="submit" class="modal-button delete">Delete All</button>
						</div>
					</form>
				</div>
			</div>
		</div>

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
							<h4 style="margin-bottom: 12px;">Select Libraries</h4>
							<div class="directory-select" style="margin-bottom: 16px;">
								<select id="plexLibrary" class="login-input" multiple size="5">
									<option value="">Loading libraries...</option>
								</select>
							</div>
							<p style="font-size: 0.9em; color: var(--text-secondary);">Hold Ctrl or Cmd to select
								multiple libraries</p>
						</div>

						<!-- New: Option to import all seasons -->
						<div class="import-step" id="seasonsOptionsStep" style="display: none;">
							<div style="margin-bottom: 16px;">
								<label class="checkbox-container"
									style="display: flex; align-items: center; cursor: pointer; margin-top: 8px;">
									<input type="checkbox" id="importAllSeasons" style="margin-right: 8px;" checked>
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
						<div
							style="margin: 20px 0; padding: 15px; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px; text-align: center;">
							<p style="margin: 0;">This will replace all posters in your selection with the ones from
								Plex.</p>
						</div>
						<div class="import-error"
							style="display: none; color: var(--danger-color); background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); padding: 12px; border-radius: 6px; margin-top: 16px;">
						</div>

						<div class="modal-actions" style="margin-top: 32px; justify-content: end;">
							<button type="button" class="modal-button cancel" id="cancelPlexImportBtn">Cancel</button>
							<button type="button" id="startPlexImport" class="modal-button rename" disabled>
								Start Import
							</button>
						</div>
					</div>

					<!-- Progress Container (hidden by default) -->
					<div id="importProgressContainer" style="display: none; text-align: center;">
						<div>
							<h3 id="importProgressStatus">Importing posters...</h3>
							<div id="importProgressBar"
								style="height: 8px; background: #333; border-radius: 4px; margin: 16px 0; overflow: hidden;">
								<div
									style="height: 100%; width: 0%; background: linear-gradient(45deg, var(--accent-primary), #ff9f43); transition: width 0.3s;">
								</div>
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
						<h3
							style="margin-bottom: 16px; margin-top: 20px; display: flex; justify-content: center; align-items: center;">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"
								fill="none" stroke="var(--success-color)" stroke-width="2" stroke-linecap="round"
								stroke-linejoin="round">
								<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
								<polyline points="22 4 12 14.01 9 11.01"></polyline>
							</svg>
							<span style="margin-left: 10px;">Import Complete</span>
						</h3>

						<div id="importErrors"
							style="display: none; margin-top: 20px; text-align: left; max-height: 150px; overflow-y: auto; padding: 12px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger-color); border-radius: 6px;">
							<h4 style="margin-bottom: 8px; color: var(--danger-color);">Errors:</h4>
							<ul style="margin-left: 20px; color: var(--danger-color);"></ul>
						</div>

						<div class="modal-actions" style="margin-top: 20px; justify-content: end;">
							<button type="button" id="closeImportResults" class="modal-button rename"
								onclick="document.getElementById('plexImportModal').classList.remove('show'); setTimeout(function() { document.getElementById('plexImportModal').style.display = 'none'; window.location.reload(); }, 300);">
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

		<div class="search-container">
			<form class="search-form" method="GET" action="">
				<?php if (!empty($currentDirectory)): ?>
				<input type="hidden" name="directory" value="<?php echo htmlspecialchars($currentDirectory); ?>">
				<?php endif; ?>
				<?php if (isset($_GET['sort_by_date'])): ?>
				<input type="hidden" name="sort_by_date" value="<?php echo htmlspecialchars($_GET['sort_by_date']); ?>">
				<?php endif; ?>
				<input type="text" name="search" class="search-input" placeholder="Search posters..."
					value="<?php echo htmlspecialchars($searchQuery); ?>" autocomplete="off">
				<button type="submit" class="search-button">Search</button>
			</form>
		</div>

		<div class="filter-container">
			<div class="filter-buttons">
				<a href="<?php echo !empty($searchQuery) ? '?search=' . urlencode($searchQuery) : '?'; ?><?php echo isset($_GET['sort_by_date']) ? '&sort_by_date=' . urlencode($_GET['sort_by_date']) : ''; ?>"
					class="filter-button <?php echo empty($currentDirectory) ? 'active' : ''; ?>">All</a>
				<?php foreach ($config['directories'] as $dirKey => $dirPath): ?>
				<a href="?directory=<?php echo urlencode($dirKey); ?><?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo isset($_GET['sort_by_date']) ? '&sort_by_date=' . urlencode($_GET['sort_by_date']) : ''; ?>"
					class="filter-button <?php echo $currentDirectory === $dirKey ? 'active' : ''; ?>">
					<?php echo formatDirectoryName($dirKey); ?>
				</a>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="gallery-stats">
			<?php if (!empty($searchQuery)): ?>
			Showing <?php echo count($filteredImages); ?> of <?php echo count($allImages); ?> images
			<a
				href="?<?php echo !empty($currentDirectory) ? 'directory=' . urlencode($currentDirectory) : ''; ?><?php echo isset($_GET['sort_by_date']) ? (!empty($currentDirectory) ? '&' : '') . 'sort_by_date=' . urlencode($_GET['sort_by_date']) : ''; ?>">Clear
				search</a>
			<?php elseif (!empty($currentDirectory)): ?>
			Showing <?php echo count($filteredImages); ?> of <?php echo count(getImageFiles($config, '')); ?> images in
			<?php echo formatDirectoryName($currentDirectory); ?>
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
					<div class="directory-badge">
						<?php echo generateDirectoryBadge($image, $allImages); ?>
					</div>


					<div class="orphaned-badge"
						data-orphaned="<?php echo (strpos(strtolower($image['filename']), '--plex--') === false) ? 'true' : 'false'; ?>"
						style="display: none;">
						Orphaned
					</div>

					<div class="gallery-image-placeholder">
						<div class="loading-spinner"></div>
					</div>
					<!-- <img src="" alt="<?php echo htmlspecialchars(pathinfo($image['filename'], PATHINFO_FILENAME)); ?>"
						class="gallery-image" loading="lazy"
						data-src="<?php echo htmlspecialchars($image['fullpath']); ?>"> -->
					<?php
					$filename = htmlspecialchars($image['filename']);
					$filepath = htmlspecialchars($image['fullpath']);
					$cacheBuster = '?v=' . time(); // or use uniqid() if preferred
					?>

					<img src="" alt="<?php echo htmlspecialchars(pathinfo($filename, PATHINFO_FILENAME)); ?>"
						class="gallery-image lazy" loading="lazy" data-src="<?php echo $filepath . $cacheBuster; ?>">

					<div class="image-overlay-actions">
						<button class="overlay-action-button copy-url-btn"
							data-url="<?php echo htmlspecialchars($config['siteUrl'] . $image['fullpath']); ?>">
							<svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
								fill="none" stroke="currentColor" stroke-width="2">
								<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
								<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
							</svg>
							Copy URL
						</button>
						<a href="?download=<?php echo urlencode($image['filename']); ?>&dir=<?php echo urlencode($image['directory']); ?>"
							class="overlay-action-button download-btn">
							<svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
								fill="none" stroke="currentColor" stroke-width="2">
								<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
								<polyline points="7 10 12 15 17 10"></polyline>
								<line x1="12" y1="15" x2="12" y2="3"></line>
							</svg>
							Download
						</a>
						<?php if (isLoggedIn()): ?>
						<?php
						// Check if filename contains "Plex" to determine if we should show the Plex-related buttons
						$isPlexFile = strpos(strtolower($image['filename']), '--plex--') !== false;

						// Only show Send to Plex button for Plex files
						if ($isPlexFile):
							?>
						<button class="overlay-action-button send-to-plex-btn"
							data-filename="<?php echo htmlspecialchars($image['filename']); ?>"
							data-dirname="<?php echo htmlspecialchars($image['directory']); ?>">
							<svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
								fill="none" stroke="currentColor" stroke-width="2">
								<path d="M9 3h-4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4"></path>
								<polyline points="16 7 21 12 16 17"></polyline>
								<line x1="21" y1="12" x2="9" y2="12"></line>
							</svg>
							Send to Plex
						</button>

						<!-- Import from Plex button for Plex files -->
						<button class="overlay-action-button import-from-plex-btn"
							data-filename="<?php echo htmlspecialchars($image['filename']); ?>"
							data-dirname="<?php echo htmlspecialchars($image['directory']); ?>">
							<svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
								fill="none" stroke="currentColor" stroke-width="2">
								<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
								<polyline points="8 7 3 12 8 17"></polyline>
								<line x1="3" y1="12" x2="15" y2="12"></line>
							</svg>
							Get from Plex
						</button>
						<?php endif; ?>

						<button class="orphan-button delete-btn"
							data-filename="<?php echo htmlspecialchars($image['filename']); ?>"
							data-dirname="<?php echo htmlspecialchars($image['directory']); ?>"
							data-orphaned="<?php echo (strpos(strtolower($image['filename']), '--plex--') === false) ? 'true' : 'false'; ?>">
							<svg height="16px" width="16px" xmlns="http://www.w3.org/2000/svg"
								shape-rendering="geometricPrecision" text-rendering="geometricPrecision"
								image-rendering="optimizeQuality" fill-rule="evenodd" clip-rule="evenodd"
								viewBox="0 0 456 511.82">
								<path fill="#fff"
									d="M48.42 140.13h361.99c17.36 0 29.82 9.78 28.08 28.17l-30.73 317.1c-1.23 13.36-8.99 26.42-25.3 26.42H76.34c-13.63-.73-23.74-9.75-25.09-24.14L20.79 168.99c-1.74-18.38 9.75-28.86 27.63-28.86zM24.49 38.15h136.47V28.1c0-15.94 10.2-28.1 27.02-28.1h81.28c17.3 0 27.65 11.77 27.65 28.01v10.14h138.66c.57 0 1.11.07 1.68.13 10.23.93 18.15 9.02 18.69 19.22.03.79.06 1.39.06 2.17v42.76c0 5.99-4.73 10.89-10.62 11.19-.54 0-1.09.03-1.63.03H11.22c-5.92 0-10.77-4.6-11.19-10.38 0-.72-.03-1.47-.03-2.23v-39.5c0-10.93 4.21-20.71 16.82-23.02 2.53-.45 5.09-.37 7.67-.37zm83.78 208.38c-.51-10.17 8.21-18.83 19.53-19.31 11.31-.49 20.94 7.4 21.45 17.57l8.7 160.62c.51 10.18-8.22 18.84-19.53 19.32-11.32.48-20.94-7.4-21.46-17.57l-8.69-160.63zm201.7-1.74c.51-10.17 10.14-18.06 21.45-17.57 11.32.48 20.04 9.14 19.53 19.31l-8.66 160.63c-.52 10.17-10.14 18.05-21.46 17.57-11.31-.48-20.04-9.14-19.53-19.32l8.67-160.62zm-102.94.87c0-10.23 9.23-18.53 20.58-18.53 11.34 0 20.58 8.3 20.58 18.53v160.63c0 10.23-9.24 18.53-20.58 18.53-11.35 0-20.58-8.3-20.58-18.53V245.66z" />
							</svg>
							Delete Orphan
						</button>
						<?php endif; ?>
					</div>
				</div>
				<div class="gallery-caption"
					data-full-text="<?php echo htmlspecialchars(pathinfo($image['filename'], PATHINFO_FILENAME)); ?>">
					<?php
					$filename = pathinfo($image['filename'], PATHINFO_FILENAME);
					// Check if this is an orphaned file (doesn't contain Plex tag)
					$isOrphaned = (strpos(strtolower($image['filename']), '--plex--') === false);

					// Create a clean version of the filename (without tags, brackets, timestamps)
					$cleanFilename = str_replace(['--Plex--', '--Orphaned--'], '', $filename);

					// Remove IDs, library names in brackets
					$cleanFilename = preg_replace('/\[\[.*?\]\]|\[.*?\]/s', '', $cleanFilename);

					// Remove ONLY timestamps e.g. (A1742232441) - but keep years like (2025)
					$cleanFilename = preg_replace('/\s*\(A\d{8,12}\)\s*/', ' ', $cleanFilename);

					// Clean up extra spaces and trim
					$cleanFilename = preg_replace('/\s+/', ' ', $cleanFilename);
					$cleanFilename = trim($cleanFilename);

					// Display the clean filename, but append "[Orphaned]" if it's orphaned
					echo htmlspecialchars($cleanFilename) . ($isOrphaned ? ' [Orphaned]' : '');
					?>
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
				<h3>Delete Poster</h3>
				<button type="button" class="modal-close-btn">×</button>
			</div>
			<p>Are you sure you want to delete this poster? This action cannot be undone.</p>
			<div
				style="margin: 20px 0; padding: 15px; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px; text-align: center;">
				<p style="margin: 0;">This is an orphaned poster.</p>
			</div>
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

	<!-- Update Available Modal -->
	<div id="updateModal" class="modal">
		<div class="modal-content" style="max-width: 600px;">
			<div class="modal-header">
				<h3>Update Available</h3>
				<button type="button" class="modal-close-btn">×</button>
			</div>
			<div class="modal-body">
				<div
					style="margin-bottom: 20px; padding: 15px; background: rgba(255, 159, 67, 0.1); border: 1px solid var(--accent-primary); border-radius: 6px;">
					<p style="margin: 0;">A new version of Posteria is available! <span id="updateVersionInfo"></span>
					</p>
				</div>
				<h4>Upgrade Instructions:</h4>
				<ol style="margin-left: 20px; line-height: 1.6;">
					<li>Navigate to your Posteria directory</li>
					<li>Run <code
							style="background: var(--bg-tertiary); padding: 2px 5px; border-radius: 3px;">docker-compose pull</code>
						to download the latest image</li>
					<li>Run <code
							style="background: var(--bg-tertiary); padding: 2px 5px; border-radius: 3px;">docker-compose up -d</code>
						to restart with the new version</li>
				</ol>
				<p>Your poster collection and settings will be preserved during updates as they're stored in the mounted
					volumes.</p>
				<div class="modal-actions">
					<button type="button" class="modal-button cancel" id="closeUpdateModal">Close</button>
					<a href="https://github.com/jeremehancock/Posteria/blob/main/changelog.md" target="_blank"
						class="modal-button" style="text-decoration: none;">
						View Changelog
					</a>
				</div>
			</div>
		</div>
	</div>

	<!-- Support Modal -->
	<div id="supportModal" class="modal">
		<div class="modal-content">
			<div class="modal-header">
				<h3>Support Development</h3>
				<button type="button" class="modal-close-btn">×</button>
			</div>
			<div class="modal-body">
				<div class="support-content">
					<p style="margin-bottom: 20px; text-align: left;">If you find Posteria useful in managing your media
						poster collection, please consider making a donation to support ongoing development and
						maintenance.</p>

					<div class="donate-section">
						<div class="donate-button" style="text-align: center; margin: 15px 0 10px;">
							<a href="https://www.buymeacoffee.com/jeremehancock" target="_blank">
								<img src="https://img.buymeacoffee.com/button-api/?text=Hard drive fund&emoji=💻&slug=jeremehancock&button_colour=f5a623&font_colour=000000&font_family=Bree&outline_colour=000000&coffee_colour=FFDD00"
									alt="Buy Me A Coffee" style="height: 50px; transform: scale(1.2);">
							</a>
						</div>
					</div>

					<div style="text-align: center; margin-top: 20px; color: var(--text-secondary);">
						<p>Thank you for supporting open source software!</p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Footer -->
	<footer class="footer">
		<div class="footer-content">
			<div class="footer-links">
				<a href="https://github.com/jeremehancock/Posteria" target="_blank" class="footer-link"
					title="GitHub Repository">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
						stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="footer-icon">
						<path
							d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22">
						</path>
					</svg>
				</a>
			</div>
			<div class="copyright">
				<script>
					const startYear = 2025;
					const today = new Date();
					const currentYear = today.getFullYear();
					document.write('© ' + (currentYear === startYear ? startYear : startYear + '-' + currentYear));
				</script>
				<a href="https://posteria.app" target="_blank"
					style="color: #ffffff; text-decoration: none;">Posteria</a> MIT License

				<?php
				// Check for updates
				$updateInfo = checkForUpdates();
				if ($updateInfo['updateAvailable']):
					?>
				<span style="margin-left: 10px;">
					<a href="#" id="showUpdateModal" class="update-available" title="Update Available">
						v<?php echo POSTERIA_VERSION; ?>
						<span class="update-badge">!</span>
					</a>
				</span>
				<?php else: ?>
				<span style="margin-left: 10px;">v<?php echo POSTERIA_VERSION; ?></span>
				<?php endif; ?>
			</div>
		</div>
	</footer>

	<script>
		document.addEventListener('DOMContentLoaded', function () {
			// =========== GLOBAL VARIABLES & STATE ===========

			// Check authentication status
			const isLoggedIn = <?php echo isLoggedIn() ? 'true' : 'false'; ?>;

			// State variables used for import functionality
			let plexLibraries = [];
			let plexShows = [];
			let importCancelled = false;

			// Global results accumulators
			let allImportResults = {
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

					if (deleteModal?.classList.contains('show')) hideModal(deleteModal, deleteForm);
					if (plexErrorModal?.classList.contains('show')) hideModal(plexErrorModal);
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
					loginForm.addEventListener('submit', async function (e) {
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
				fileInput.addEventListener('change', function (e) {
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
					document.getElementById('fileUploadForm').addEventListener('submit', async function (e) {
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
					document.getElementById('urlUploadForm').addEventListener('submit', async function (e) {
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
					deleteForm.addEventListener('submit', async function (e) {
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
				fileChangePosterInput.addEventListener('change', function (e) {
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
					tab.addEventListener('click', function () {
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
					fileChangePosterForm.addEventListener('submit', async function (e) {
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
					urlChangePosterForm.addEventListener('submit', async function (e) {
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
					closeChangePosterButton.addEventListener('click', function () {
						hideModal(changePosterModal);
					});
				}

				// Click outside to close
				changePosterModal.addEventListener('click', function (e) {
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
						closeButton.addEventListener('click', function () {
							hideModal(plexImportModal);
							// Force a page refresh to ensure a clean state
							setTimeout(function () {
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
							librarySelect.innerHTML = '';

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
					const selectedLibraries = Array.from(librarySelect.selectedOptions).map(option => option.value);
					const showId = showSelect.value;
					const importAllSeasons = document.getElementById('importAllSeasons')?.checked || false;

					let isValid = false;

					if (!importType || selectedLibraries.length === 0) {
						isValid = false;
					} else if (importType === 'seasons') {
						// If importing all seasons, we just need valid libraries
						// If importing specific show seasons, we need both libraries and show
						isValid = importAllSeasons ? true : (showId ? true : false);
					} else {
						// For all other types, just need valid libraries
						isValid = true;
					}

					startPlexImportButton.disabled = !isValid;
					return isValid;
				}

				// Checkbox handler for "Import all seasons"
				const importAllSeasonsCheckbox = document.getElementById('importAllSeasons');
				if (importAllSeasonsCheckbox) {
					importAllSeasonsCheckbox.addEventListener('change', function () {
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
									showSelectionStep.style.display = 'none';
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
				importTypeSelect.addEventListener('change', function () {
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
								seasonsOptionsStep.style.display = 'none';
								break;
							case 'collections':
								targetDirectorySelect.value = 'collections';
								break;
						}

						// Show file handling step
						fileHandlingStep.style.display = 'none';
					} else {
						// If user clears the selection, reset the form
						librarySelect.innerHTML = '<option value="">Select a content type first...</option>';
						hideErrorInImportOptions();
						fileHandlingStep.style.display = 'none';
					}

					validateImportOptions();
				});

				// Handle "Import all seasons" checkbox change
				document.getElementById('importAllSeasons').addEventListener('change', function () {
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
								showSelectionStep.style.display = 'none';
							}
						}
					}

					validateImportOptions();
				});

				// Handle library selection
				librarySelect.addEventListener('change', function () {
					const selectedLibraryId = this.value;
					const selectedOption = this.options[this.selectedIndex];
					const libraryType = selectedOption ? selectedOption.dataset.type : '';
					const selectedLibraries = Array.from(this.selectedOptions).map(option => option.value);

					hideErrorInImportOptions();

					validateImportOptions();
					// Reset show selection
					showSelectionStep.style.display = 'none';
					showSelect.innerHTML = '<option value="">Loading shows...</option>';

					// Hide error messages
					hideErrorInImportOptions();

					if (selectedLibraryId) {
						// If importing seasons, show the show selection step
						if (importTypeSelect.value === 'seasons') {
							if (libraryType === 'show') {
								showSelectionStep.style.display = 'none';
								loadPlexShows(selectedLibraryId);
							} else {
								showErrorInImportOptions('Please select a TV Show library to import seasons');
							}
						}
					}

					validateImportOptions();
				});

				// Handle show selection
				showSelect.addEventListener('change', function () {
					validateImportOptions();
				});

				// Start the import process
				startPlexImportButton.addEventListener('click', async function () {
					if (!validateImportOptions()) {
						return;
					}

					// Get selected options
					const importType = importTypeSelect.value;
					const selectedLibraries = Array.from(librarySelect.selectedOptions).map(option => option.value);
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

					// Start import process with multiple libraries
					try {
						await importPlexPosters(importType, selectedLibraries, showKey, targetDirectory, overwriteOption, importAllSeasons);
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
				async function importPlexPosters(type, libraryIds, showKey, contentType, overwriteOption, importAllSeasons) {
					// Configure initial request
					const initialParams = {
						'action': 'import_plex_posters',
						'type': type,
						'libraryIds': libraryIds.join(','), // Send as comma-separated string
						'contentType': contentType,
						'overwriteOption': overwriteOption,
						'batchProcessing': 'true',
						'startIndex': 0,
						'libraryIndex': 0 // New parameter to track which library we're processing
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
					let currentLibraryIndex = 0;
					const results = {
						successful: 0,
						skipped: 0,
						unchanged: 0,
						renamed: 0,
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
								'libraryIndex': currentLibraryIndex,
								'totalSuccessful': results.successful,
								'totalSkipped': results.skipped,
								'totalUnchanged': results.unchanged,
								'totalRenamed': results.renamed,
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
										"Importing posters...";

									// Season progress details
									if (data.progress.seasonCount !== undefined) {
										document.getElementById('importProgressDetails').innerHTML =
											`Processing show ${data.progress.processed} of ${data.progress.total} (${data.progress.percentage}%)<br>` +
											`Found ${data.progress.seasonCount} seasons in current show`;
									} else {
										document.getElementById('importProgressDetails').textContent =
											`Processing show ${data.progress.processed} of ${data.progress.total} (${data.progress.percentage}%)`;
									}
								} else if (data.progress.currentLibrary) {
									// Show which library is being processed (for multi-library)
									document.getElementById('importProgressStatus').textContent =
										`Importing posters from library: ${data.progress.currentLibrary}`;

									// Regular batch progress
									const percentage = data.progress.percentage;
									if (importProgressBar) {
										importProgressBar.style.width = `${percentage}%`;
									}

									// Update progress text
									document.getElementById('importProgressDetails').textContent =
										`Library ${data.progress.currentLibraryIndex + 1} of ${data.progress.totalLibraries}: ` +
										`Processing ${data.progress.processed} of ${data.progress.total} items (${percentage}%)`;
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
									results.successful += data.results.successful || 0;
									results.skipped += data.results.skipped || 0;
									results.unchanged += data.results.unchanged || 0;
									results.renamed += data.results.renamed || 0;
									results.failed += data.results.failed || 0;

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

								// Check if complete or need to move to next library
								if (data.progress.moveToNextLibrary) {
									// Update to the next library
									currentLibraryIndex = data.progress.nextLibraryIndex || 0;
									currentIndex = 0; // Reset index for the new library
								} else {
									// Update the current index within this library
									isComplete = data.progress.isComplete;
									currentIndex = data.progress.nextIndex || 0;
								}
							} else {
								// Handle non-batch processing result
								isComplete = true;

								if (data.results) {
									results.successful = data.results.successful || 0;
									results.skipped = data.results.skipped || 0;
									results.unchanged = data.results.unchanged || 0;
									results.renamed = data.results.renamed || 0;
									results.failed = data.results.failed || 0;
									results.errors = data.results.errors || [];
								}

								// Update stats dashboard
								updateStatsDashboard(data.totalStats || results, allSkippedDetails);
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
						const skippedDetailsContent = allImportResults.items.map(function (item, index) {
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
				plexImportModal.addEventListener('click', function (e) {
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
				closeResultsButton?.addEventListener('click', function () {
					// Make sure to hide the modal and reset
					hideModal(plexImportModal);
					// Force a page refresh to ensure a clean state
					setTimeout(function () {
						window.location.reload();
					}, 300);
				});
			}

			const cancelPlexImportBtn = document.getElementById('cancelPlexImportBtn');
			if (cancelPlexImportBtn) {
				cancelPlexImportBtn.addEventListener('click', function () {
					hidePlexModal();
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

						// Properly encode the path with special characters
						const encodedPath = encodeURI(imgPath).replace(/#/g, '%23');
						const newSrc = encodedPath + '?t=' + timestamp;

						// Set the new source
						img.src = '';
						img.setAttribute('data-src', imgPath + '?t=' + timestamp); // Keep original in data-src
						img.src = newSrc; // Use encoded version for src

						// Reset loading state
						img.classList.remove('loaded');
						const placeholder = img.previousElementSibling;
						if (placeholder && placeholder.classList.contains('gallery-image-placeholder')) {
							placeholder.classList.remove('hidden');
						}

						// Set loaded class when the new image loads
						img.onload = function () {
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
				return filename.toLowerCase().includes('--plex--');
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
					closeButton.addEventListener('click', function () {
						hideModal(modal);
					});
				}

				// Cancel button handler
				if (cancelButton) {
					cancelButton.addEventListener('click', function () {
						hideModal(modal);
					});
				}

				// Confirm button handler
				if (confirmButton) {
					confirmButton.addEventListener('click', function () {
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
				modal.addEventListener('click', function (e) {
					if (e.target === modal) {
						hideModal(modal);
					}
				});

				// Escape key to close
				document.addEventListener('keydown', function (e) {
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
							<p>Are you sure you want to replace this poster with the one from Plex?</p>
							<p id="importFromPlexFilename" style="margin-top: 10px; font-weight: 500; overflow-wrap: break-word; display: none;" data-filename="" data-dirname=""></p>
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
					return;
				}

				// Get elements
				const closeButton = modal.querySelector('.modal-close-btn');
				const cancelButton = document.getElementById('cancelImportFromPlex');
				const confirmButton = modal.querySelector('.import-from-plex-confirm');

				// Close button handler
				if (closeButton) {
					closeButton.addEventListener('click', function () {
						hideModal(modal);
					});
				}

				// Cancel button handler
				if (cancelButton) {
					cancelButton.addEventListener('click', function () {
						hideModal(modal);
					});
				}

				// Confirm button handler
				if (confirmButton) {
					confirmButton.addEventListener('click', function () {
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
				modal.addEventListener('click', function (e) {
					if (e.target === modal) {
						hideModal(modal);
					}
				});

				// Escape key to close
				document.addEventListener('keydown', function (e) {
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

						if (data.renamed) {
							// If the file was renamed, we need to update DOM elements and refresh with the new filename
							handleRenamedPoster(filename, data.filename, directory, data.newPath);
						} else {
							// If not renamed, just refresh the image
							refreshImage(filename, directory);
						}
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

			// Function to handle renamed posters
			function handleRenamedPoster(oldFilename, newFilename, directory, newPath) {
				// First find the gallery item that contains this poster
				const galleryItems = document.querySelectorAll('.gallery-item');
				let targetItem = null;

				galleryItems.forEach(item => {
					const buttons = item.querySelectorAll('button[data-filename]');
					buttons.forEach(button => {
						if (button.getAttribute('data-filename') === oldFilename &&
							button.getAttribute('data-dirname') === directory) {
							targetItem = item;
						}
					});
				});

				if (!targetItem) {
					return;
				}

				// Update all buttons' data-filename attributes in this gallery item
				const buttons = targetItem.querySelectorAll('button[data-filename]');
				buttons.forEach(button => {
					button.setAttribute('data-filename', newFilename);
				});

				// Find the image element and update its source
				const imageElement = targetItem.querySelector('.gallery-image');
				if (imageElement) {
					// For a renamed file, we'll use the new path directly instead of trying to modify the old path
					if (newPath) {
						// Properly encode the new path with special characters
						const encodedPath = encodeURI(newPath).replace(/#/g, '%23');

						imageElement.src = '';  // Clear the src first
						imageElement.setAttribute('data-src', newPath);
						imageElement.src = encodedPath;

						// Reset loading state
						imageElement.classList.remove('loaded');
						const placeholder = imageElement.previousElementSibling;
						if (placeholder && placeholder.classList.contains('gallery-image-placeholder')) {
							placeholder.classList.remove('hidden');
						}

						// Set loaded class when the new image loads
						imageElement.onload = function () {
							imageElement.classList.add('loaded');
							if (placeholder) {
								placeholder.classList.add('hidden');
							}
						};
					}
				}

				// Update the copy URL button
				const copyUrlBtn = targetItem.querySelector('.copy-url-btn');
				if (copyUrlBtn) {
					try {
						// Figure out what the new URL should be based on site URL and path structure
						const siteBaseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
						const dirPath = directory === 'movies' ? 'posters/movies/' :
							directory === 'tv-shows' ? 'posters/tv-shows/' :
								directory === 'tv-seasons' ? 'posters/tv-seasons/' :
									'posters/collections/';

						// Construct the complete new URL (using encodeURIComponent to properly handle all special chars)
						const newUrl = siteBaseUrl + dirPath + encodeURIComponent(newFilename);

						// Update the copy URL button's data-url attribute
						copyUrlBtn.setAttribute('data-url', newUrl);

					} catch (e) {
						// Silent error handling
					}
				}

				// Update the download link href if it exists
				const downloadLink = targetItem.querySelector('.download-btn');
				if (downloadLink) {
					const href = downloadLink.getAttribute('href');
					if (href) {
						// Parse the URL query parameters
						try {
							// Remove the leading ? if present
							const queryString = href.startsWith('?') ? href.substring(1) : href;
							const params = new URLSearchParams(queryString);

							// Update the download parameter with the new filename
							if (params.has('download')) {
								params.set('download', newFilename);

								// Set the updated href
								const newHref = '?' + params.toString();
								downloadLink.setAttribute('href', newHref);
							}
						} catch (e) {
							// Silent error handling
						}
					}
				}

				// Update the caption text if needed 
				const captionElement = targetItem.querySelector('.gallery-caption');
				if (captionElement) {
					// Get the current full text
					let fullText = captionElement.getAttribute('data-full-text');
					if (fullText) {
						// Replace the old filename with the new one
						fullText = fullText.replace(oldFilename, newFilename);
						captionElement.setAttribute('data-full-text', fullText);

						// Also update the displayed caption text
						// First remove Plex and Orphaned tags
						let displayText = fullText.replace(/\-\-(Plex|Orphaned)\-\-/g, '');
						// Then remove both single [ID] and double [[Library]] brackets with their contents
						// Replace this line:
						displayText = displayText.replace(/\[\[[^\]]*\]\]|\[[^\]]*\]/g, '');
						// With this:
						displayText = displayText.replace(/\[\[.*?\]\]|\[.*?\]/gs, '');
						// Trim any resulting extra spaces
						displayText = displayText.trim();

						captionElement.textContent = displayText;
					}
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
				// Get the URL and properly encode special characters including #
				const url = this.getAttribute('data-url');

				// Properly encode all special characters in the URL, especially handling # (encodes to %23)
				const encodedUrl = encodeURI(url).replace(/#/g, '%23');

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

				// Add these handlers for the cancel buttons
				const cancelChangePosterBtn = document.getElementById('cancelChangePoster');
				if (cancelChangePosterBtn) {
					cancelChangePosterBtn.addEventListener('click', function () {
						hideModal(changePosterModal);
					});
				}

				const cancelChangePosterUrlBtn = document.getElementById('cancelChangePosterUrl');
				if (cancelChangePosterUrlBtn) {
					cancelChangePosterUrlBtn.addEventListener('click', function () {
						hideModal(changePosterModal);
					});
				}

				// Initialize Plex-specific buttons
				initChangePosters();
				initializeSendToPlexButtons();
				initializeImportFromPlexButtons();
				showOrphanedBadge();
			}

			// Debounce function for resize handling
			function debounce(func, wait) {
				let timeout;
				return function () {
					const context = this, args = arguments;
					clearTimeout(timeout);
					timeout = setTimeout(function () {
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
						caption.style.cursor = 'default';
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
								// Get the original src and properly encode all special characters including #
								const originalSrc = img.dataset.src;
								// Encode the URL, specifically handling the # character correctly
								const encodedSrc = encodeURI(originalSrc).replace(/#/g, '%23');

								img.src = encodedSrc;

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
					button.addEventListener('touchstart', function (e) {
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

					item.addEventListener('touchstart', function (e) {
						if (isScrolling) return;

						if (!this.classList.contains('touch-active')) {
							touchStartY = e.touches[0].clientY;
							touchStartX = e.touches[0].clientX;
							touchTimeStart = Date.now();
						}
					}, { passive: true });

					item.addEventListener('touchend', function (e) {
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
				document.addEventListener('touchstart', function (e) {
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

				const cleanedFilename = this.getAttribute('data-filename')
					.replace(/\.jpg$/i, '')                         // Remove .jpg extension
					.replace(/\-\-Plex\-\-|\-\-Orphaned\-\-/g, '')  // Remove --Plex-- and --Orphaned-- tags
					.replace(/\[\[.*?\]\]|\[.*?\]/gs, '')           // Remove both [[Library]] and [ID] brackets with contents
					.replace(/\s*\(A\d{8,12}\)\s*/g, ' ')           // Remove timestamps (A1742232441)
					.replace(/\s+/g, ' ')                           // Normalize spaces (replace multiple spaces with single space)
					.trim();

				// Update the modal with file info
				document.getElementById('changePosterFilename').textContent = `Changing poster: ${cleanedFilename}`;
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
										<path d="M9 3h-4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4"></path>
										<polyline points="16 7 21 12 16 17"></polyline>
										<line x1="21" y1="12" x2="9" y2="12"></line>
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
				// Remove existing event listeners (this is an approximation, as we can't directly
				// remove anonymous listeners, but this will prevent default which is what we need)
				const oldSearchForm = searchForm.cloneNode(true);
				searchForm.parentNode.replaceChild(oldSearchForm, searchForm);

				// Get the new reference
				const newSearchForm = document.querySelector('.search-form');

				// Add basic event handler just to update filter links before submission
				// but allow the form to submit normally
				newSearchForm.addEventListener('submit', function (e) {
					// Don't prevent default - we want normal form submission

					// But we should update filter hrefs to maintain search parameter when clicked
					const searchValue = this.querySelector('.search-input').value;
					const urlParams = new URLSearchParams(window.location.search);
					const sortByDate = urlParams.get('sort_by_date');
					if (searchValue) {
						// Update All button
						const allFilterButton = document.querySelector('.filter-buttons a:first-child');
						if (allFilterButton) {
							let allUrl = `?search=${encodeURIComponent(searchValue)}`;
							if (sortByDate) {
								allUrl += `&sort_by_date=${sortByDate}`;
							}
							allFilterButton.href = allUrl;
						}

						// Update directory filter buttons
						const directoryButtons = document.querySelectorAll('.filter-buttons a:not(:first-child)');
						directoryButtons.forEach(button => {
							const href = button.getAttribute('href');
							const directoryMatch = href.match(/\?directory=([^&]*)/);
							if (directoryMatch && directoryMatch[1]) {
								let btnUrl = `?directory=${directoryMatch[1]}&search=${encodeURIComponent(searchValue)}`;
								if (sortByDate) {
									btnUrl += `&sort_by_date=${sortByDate}`;
								}
								button.href = btnUrl;
							}
						});
					}

					// Allow normal form submission - this will cause a full page reload
				});
			}

			// Handle browser back/forward
			window.addEventListener('popstate', function () {
				location.reload();
			});

			// =========== CLOSING BUTTON HANDLING ===========

			// For regular close buttons
			const closeButtons = document.querySelectorAll('.plex-import-modal-close, #closeImportModal, .close-button');
			closeButtons.forEach(button => {
				button.addEventListener('click', function () {
					setTimeout(function () {
						window.location.reload();
					}, 300);
				});
			});

			// =========== HANDLE ORPHANS ===========	

			function hideNonOrphanedDeleteButtons() {
				document.querySelectorAll('.delete-btn').forEach(button => {
					const isOrphaned = button.getAttribute('data-orphaned') === 'true';
					// Add initialized class to all buttons
					button.classList.add('initialized');

					if (!isOrphaned) {
						button.style.display = 'none';
					} else {
						// Make sure orphaned buttons are visible
						button.style.display = 'flex';
					}
				});
			}

			function showOrphanedBadge() {
				document.querySelectorAll('.orphaned-badge').forEach(button => {
					const isOrphaned = button.getAttribute('data-orphaned') === 'true';
					if (isOrphaned) {
						button.style.display = 'flex';
					}
				});
			}

			function initDeleteOrphans() {
				// Get elements
				const deleteOrphansButton = document.getElementById('showDeleteOrphansModal');
				const deleteOrphansModal = document.getElementById('deleteOrphansModal');
				const closeDeleteOrphansButton = deleteOrphansModal?.querySelector('.modal-close-btn');
				const cancelDeleteOrphansButton = document.getElementById('cancelDeleteOrphans');
				const deleteOrphansForm = document.getElementById('deleteOrphansForm');

				// Don't initialize if elements aren't found
				if (!deleteOrphansButton || !deleteOrphansModal) return;

				// Show modal handler
				deleteOrphansButton.addEventListener('click', function () {
					showModal(deleteOrphansModal);
				});

				// Close modal handlers
				if (closeDeleteOrphansButton) {
					closeDeleteOrphansButton.addEventListener('click', function () {
						hideModal(deleteOrphansModal);
					});
				}

				if (cancelDeleteOrphansButton) {
					cancelDeleteOrphansButton.addEventListener('click', function () {
						hideModal(deleteOrphansModal);
					});
				}

				// Click outside to close
				deleteOrphansModal.addEventListener('click', function (e) {
					if (e.target === deleteOrphansModal) {
						hideModal(deleteOrphansModal);
					}
				});

				// Handle form submission - simple implementation
				if (deleteOrphansForm) {
					deleteOrphansForm.addEventListener('submit', function (e) {
						e.preventDefault();

						// Show a loading notification
						const notification = showNotification('Deleting orphaned posters...', 'loading');

						// Simple AJAX request
						const formData = new FormData(deleteOrphansForm);

						fetch(window.location.href, {
							method: 'POST',
							body: formData
						})
							.then(response => response.json())
							.then(data => {
								// Remove loading notification
								notification.remove();

								if (data.success) {
									// Show success and reload
									showNotification(`Deleted ${data.deleted} orphaned posters.`, 'success');
									setTimeout(function () {
										window.location.reload();
									}, 1500);

									// Hide modal
									hideModal(deleteOrphansModal);
								} else {
									// Show error
									showNotification(`Error: ${data.error || 'Delete failed'}`, 'error');
								}
							})
							.catch(error => {
								// Remove loading notification
								notification.remove();

								// Show error
								showNotification('Error: Could not complete the deletion.', 'error');
							});
					});
				}
			}

			// =========== VERSION UPDATE NOTIFICATION ===========

			// Initialize update notification
			function initUpdateNotification() {
				const updateLink = document.getElementById('showUpdateModal');
				const updateModal = document.getElementById('updateModal');
				const closeUpdateModalBtn = document.getElementById('closeUpdateModal');
				const closeUpdateBtn = updateModal?.querySelector('.modal-close-btn');

				if (updateLink && updateModal) {
					// Show update modal when clicking the version link
					updateLink.addEventListener('click', function (e) {
						e.preventDefault();

						// Set version info in the modal
						const versionSpan = document.getElementById('updateVersionInfo');
						if (versionSpan) {
							<?php if ($updateInfo['updateAvailable']): ?>
							versionSpan.textContent = "(Current: v<?php echo POSTERIA_VERSION; ?>, Latest: v<?php echo $updateInfo['latestVersion']; ?>)";
							<?php else: ?>
							versionSpan.textContent = "(Current: v<?php echo POSTERIA_VERSION; ?>)";
							<?php endif; ?>
						}

						showModal(updateModal);
					});

					// Close modal with close button
					if (closeUpdateBtn) {
						closeUpdateBtn.addEventListener('click', function () {
							hideModal(updateModal);
						});
					}

					// Close modal with cancel button
					if (closeUpdateModalBtn) {
						closeUpdateModalBtn.addEventListener('click', function () {
							hideModal(updateModal);
						});
					}

					// Close when clicking outside the modal
					updateModal.addEventListener('click', function (e) {
						if (e.target === updateModal) {
							hideModal(updateModal);
						}
					});
				}
			}

			// =========== INITIALIZATION ===========

			// Initial setup
			initializeGalleryFeatures();
			initializeButtons();
			initializeTruncation();
			initPlexConfirmModal();
			createImportFromPlexModal();
			initImportFromPlexFeature();
			hideNonOrphanedDeleteButtons();
			showOrphanedBadge();
			initDeleteOrphans();
			initUpdateNotification();

			// Call truncation after images load and on resize
			document.querySelectorAll('.gallery-image').forEach(img => {
				img.addEventListener('load', initializeTruncation);
			});

			// Debounced resize handler
			window.addEventListener('resize', debounce(initializeTruncation, 250));
		});
	</script>

	<script>
		/**
	 * Enhanced TMDB & Fanart.tv Integration for Posteria
	 * 
	 * This script adds support for fetching posters from TMDB for:
	 * - Movies
	 * - TV Shows
	 * - TV Seasons (with automatic season detection)
	 * - Collections (with multi-poster selection)
	 * 
	 * All media types now support multi-poster selection when multiple options are available.
	 */

		// Detect if device supports touch events (mobile detection)
		let isTouchDevice = false;
		window.addEventListener('touchstart', function onFirstTouch() {
			isTouchDevice = true;
			// Remove event listener once detected
			window.removeEventListener('touchstart', onFirstTouch);
		}, { passive: true });

		document.addEventListener('DOMContentLoaded', function () {
			// Add our CSS styles
			const style = document.createElement('style');
			style.textContent = `
.tmdb-search-btn {
	background: var(--bg-tertiary);
	border: 1px solid var(--border-color);
	color: var(--text-primary);
	cursor: pointer;
	padding: 12px 16px;
	border-radius: 8px;
	transition: all 0.2s;
	font-weight: 500;
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 15px;
	width: auto;
	height: 44px;
}

.tmdb-search-btn:hover {
	background: #333;
	transform: translateY(-2px);
}

.tmdb-search-btn svg {
	width: 22px;
	height: 22px;
}

/* Attribution styling */
.tmdb-attribution {
	margin-top: 10px;
	font-size: 12px;
	color: var(--text-secondary);
}

.tmdb-attribution a {
	color: var(--accent-primary);
	text-decoration: none;
	transition: color 0.2s;
}

.tmdb-attribution a:hover {
	color: var(--accent-hover);
	text-decoration: underline;
}

/* Form layout with flex */
.url-input-container {
	display: flex;
	gap: 20px;
	margin-bottom: 15px;
}

.url-input-container .upload-input-group {
	flex: 1;
}

.url-input-container .login-input {
	width: 100%;
}

.tmdb-poster-preview {
	width: 100px;
	height: 150px; /* Fixed height based on typical poster ratio */
	border: 2px solid #E5A00D;
	border-radius: 8px;
	overflow: hidden;
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
	flex-shrink: 0;
	background-color: var(--bg-secondary);
	display: flex;
	align-items: center;
	justify-content: center;
	margin-bottom: 9px;
	cursor: pointer; /* Show it's clickable */
	position: relative; /* For the zoom icon overlay */
}

.tmdb-poster-preview:after {
	content: '';
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0,0,0,0.6);
	opacity: 0;
	transition: opacity 0.2s;
	display: flex;
	align-items: center;
	justify-content: center;
}

.tmdb-poster-preview:before {
	content: '';
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	z-index: 2;
	opacity: 0;
	transition: all 0.2s;
	background: rgba(229, 160, 13, 0.9);
	width: 40px;
	height: 40px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 2px 8px rgba(0,0,0,0.5);
}

/* Add SVG magnifying glass icon */
.tmdb-poster-preview:after {
	content: '';
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0,0,0,0.6);
	opacity: 0;
	transition: opacity 0.2s;
}

/* Icon inside the circle */
.tmdb-poster-icon {
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	width: 24px;
	height: 24px;
	z-index: 3;
	opacity: 0;
	transition: all 0.2s;
}

.tmdb-poster-preview:hover:after,
.tmdb-poster-preview:hover:before,
.tmdb-poster-preview:hover .tmdb-poster-icon {
	opacity: 1;
}

.tmdb-poster-preview img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
}

.tmdb-loading {
	width: 100px;
	height: 150px; /* Match the poster height */
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	background-color: var(--bg-secondary);
	border-radius: 8px;
	border: 1px solid var(--border-color);
	flex-shrink: 0;
}

.tmdb-spinner {
	width: 40px;
	height: 40px;
	border: 4px solid rgba(255, 255, 255, 0.1);
	border-radius: 50%;
	border-top-color: var(--accent-primary);
	animation: spin 1s infinite linear;
	margin-bottom: 15px;
}

/* Preview Modal */
.image-preview-modal {
	display: none;
	position: fixed;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background: rgba(0, 0, 0, 0.85);
	z-index: 3000; /* Higher than multi-poster-modal */
	opacity: 0;
	transition: opacity 0.3s ease;
	justify-content: center;
	align-items: center;
}

.image-preview-modal.show {
	opacity: 1;
}

.image-preview-content {
	position: relative;
	max-width: 80%;
	max-height: 80%;
	border-radius: 8px;
	overflow: hidden;
	box-shadow: 0 8px 16px rgba(0,0,0,0.5);
}

.image-preview-content img {
	display: block;
	max-width: 100%;
	max-height: 80vh;
	margin: 0 auto;
}

.image-preview-close {
	position: absolute;
	top: 20px;
	right: 20px;
	background: var(--bg-secondary);
	color: var(--text-primary);
	width: 40px;
	height: 40px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	border: none;
	font-size: 24px;
	box-shadow: 0 2px 10px rgba(0,0,0,0.3);
	transition: all 0.2s;
}

.image-preview-close:hover {
	background: var(--bg-tertiary);
	transform: scale(1.1);
}


/* Multi Poster Modal */
.multi-poster-modal {
	display: none;
	position: fixed;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background: rgba(0, 0, 0, 0.85);
	z-index: 2000;
	opacity: 0;
	transition: opacity 0.3s ease;
}

.multi-poster-modal.show {
	opacity: 1;
	display: flex;
	justify-content: center;
	align-items: center;
}

.multi-poster-content {
	background: var(--bg-secondary);
	border-radius: 12px;
	width: 90%;
	max-width: 800px;
	max-height: 90vh;
	display: flex;
	flex-direction: column;
	box-shadow: 0 8px 32px rgba(0,0,0,0.5);
	border: 1px solid var(--border-color);
	overflow: hidden;
}

.multi-poster-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px 24px;
	border-bottom: 1px solid var(--border-color);
}

.multi-poster-header h3 {
	margin: 0;
	color: var(--text-primary);
	font-size: 1.25rem;
}

.multi-poster-close-btn {
	background: none;
	border: none;
	color: var(--text-secondary);
	font-size: 28px;
	cursor: pointer;
	padding: 0;
	line-height: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	transition: all 0.2s;
}

.multi-poster-close-btn:hover {
	color: var(--text-primary);
	transform: scale(1.1);
}

.multi-poster-search-bar {
	padding: 16px 24px;
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 12px;
	border-bottom: 1px solid var(--border-color);
}

#posterSearchInput {
	flex: 1;
	min-width: 200px;
	padding: 10px 16px;
	border-radius: 6px;
	border: 1px solid var(--border-color);
	background: var(--bg-tertiary);
	color: var(--text-primary);
	font-size: 14px;
}

#posterSearchInput:focus {
	outline: none;
	border-color: var(--accent-primary);
}

.poster-count {
	color: var(--text-secondary);
	font-size: 14px;
}

.poster-count span {
	font-weight: bold;
	color: var(--accent-primary);
}

.multi-poster-grid {
	flex: 1;
	overflow-y: auto;
	padding: 24px;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	gap: 20px;
	align-content: start;
	max-height: 60vh;
	scrollbar-width: thin;
	scrollbar-color: var(--bg-tertiary) var(--bg-secondary);
}

.multi-poster-grid::-webkit-scrollbar {
	width: 8px;
}

.multi-poster-grid::-webkit-scrollbar-track {
	background: var(--bg-secondary);
}

.multi-poster-grid::-webkit-scrollbar-thumb {
	background-color: var(--bg-tertiary);
	border-radius: 8px;
	border: 2px solid var(--bg-secondary);
}

.multi-poster-grid::-webkit-scrollbar-thumb:hover {
	background-color: var(--accent-primary);
}

.poster-item {
	position: relative;
	aspect-ratio: 2/3;
	border-radius: 8px;
	cursor: pointer;
	transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
	border: 2px solid transparent;
	background: var(--bg-tertiary);
}

.poster-item:hover {
	transform: translateY(-5px);
	border-color: var(--accent-primary);
	box-shadow: 0 8px 16px rgba(0,0,0,0.3);
}

.poster-item img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	transition: opacity 0.2s;
}

.poster-item.loading img {
	opacity: 0;
}

.poster-loading {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--bg-tertiary);
	z-index: 1;
}

.poster-spinner {
	width: 30px;
	height: 30px;
	border: 3px solid rgba(229, 160, 13, 0.2);
	border-top-color: var(--accent-primary);
	border-radius: 50%;
	animation: spin 1s infinite linear;
}

.no-posters-found {
	grid-column: 1 / -1;
	text-align: center;
	padding: 40px;
	color: var(--text-secondary);
}

/* Error states */
.tmdb-error-container {
	width: 100px;
	height: 150px;
	display: flex;
	align-items: center;
	justify-content: center;
	border: 2px solid #FF4757;
	background-color: rgba(33, 33, 33, 0.95);
	border-radius: 8px;
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
	flex-shrink: 0;
	overflow: hidden;
	position: relative;
	margin-bottom: 9px;
}

/* Remove all hover effects */
.tmdb-error-container::before,
.tmdb-error-container::after,
.tmdb-error-container:hover::before,
.tmdb-error-container:hover::after {
	display: none !important;
}

.error-content {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	text-align: center;
	color: #FF6B81;
	height: 100%;
	width: 100%;
	padding: 8px;
}

.error-text {
	font-weight: bold;
	margin-top: 8px;
	font-size: 14px;
	text-shadow: 0 1px 3px rgba(0,0,0,0.5);
	color: #FF6B81;
}

.error-subtext {
	font-size: 12px;
	margin-top: 4px;
	color: #FFC3C3;
	text-shadow: 0 1px 2px rgba(0,0,0,0.4);
}

/* Specifically ensure no hover states */
.tmdb-error-container .tmdb-poster-icon,
.tmdb-error-container:hover .tmdb-poster-icon {
	display: none !important;
}

/* Poster Overlay Buttons */
.poster-overlay {
	position: absolute;
	bottom: 0;
	left: 0;
	width: 100%;
	padding: 8px;
	background: rgba(0, 0, 0, 0.7);
	display: flex;
	justify-content: space-between;
	opacity: 0;
	transition: opacity 0.3s ease;
	z-index: 2;
}

/* Mobile touch detection class */
.poster-item.touched .poster-overlay {
	opacity: 1;
}

.poster-item:hover .poster-overlay {
	opacity: 1;
}

.poster-btn {
	border: none;
	background: var(--accent-primary);
	color: #000;
	font-weight: 700;
	font-size: 12px;
	padding: 5px 10px;
	border-radius: 4px;
	cursor: pointer;
	transition: all 0.2s;
	flex: 1;
	text-align: center;
	display: flex;
	align-items: center;
	justify-content: center;
}

.poster-btn:first-child {
	margin-right: 4px;
}

.poster-btn:hover {
	background: var(--accent-hover);
	transform: translateY(-2px);
}

.poster-btn svg {
	width: 14px;
	height: 14px;
	margin-right: 4px;
	stroke-width: 2.5;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
	.url-input-container {
		gap: 10px;
	}
	
	.url-input-container .upload-input-group {
		flex: 0 1 auto;
		width: 100%;
	}
	
	.tmdb-poster-preview, .tmdb-loading {
		flex-shrink: 0;
	}
	
	/* Make the input text smaller on mobile */
	.url-input-container .login-input {
		font-size: 14px;
		padding: 10px;
	}
	
	.image-preview-content {
		max-width: 90%;
	}
}

@media (max-width: 640px) {
	.multi-poster-grid {
		grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
		gap: 15px;
		padding: 15px;
	}
	
	.multi-poster-header,
	.multi-poster-search-bar {
		padding: 12px 16px;
	}
	
	.multi-poster-header h3 {
		font-size: 1.125rem;
	}
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}
`;
			document.head.appendChild(style);

			// Handle manual URL input
			function handleManualUrlInput(url) {
				// Get the URL form
				const urlForm = document.getElementById('urlChangePosterForm');
				if (!urlForm) return;

				// Find the container
				const container = urlForm.querySelector('.url-input-container');
				if (!container) return;

				// Don't proceed if URL is empty or very short
				if (!url || url.length < 10) {
					removeExistingPreview();
					return;
				}

				// Special handling for mediux.pro URLs
				if (url.includes('api.mediux.pro')) {
					// If it doesn't end with an image extension, append .jpg
					if (!url.match(/\.(jpg|jpeg|png|webp)($|\?)/i)) {
						url = url + '.jpg';
					}
					showManualUrlPreview(url);
					return;
				}

				// Check if URL has an image extension
				const validExtensions = ['.jpg', '.jpeg', '.png', '.webp'];
				const hasValidExtension = validExtensions.some(ext =>
					url.toLowerCase().endsWith(ext) || url.toLowerCase().includes(ext + '?')
				);

				if (hasValidExtension) {
					showManualUrlPreview(url);
				} else {
					removeExistingPreview();
				}
			}

			window.handleManualUrlInput = handleManualUrlInput;

			// Extract season number from title
			function extractSeasonFromTitle(title) {
				// First check for "Specials" - this has priority
				if (title.toLowerCase().includes('special')) {
					return 0;
				}

				// Look for "Season 0" pattern (special case for season 0)
				let seasonMatch = title.match(/season\s*0+/i);
				if (seasonMatch) {
					return 0;
				}

				// Look for "S00" pattern (special case for season 0)
				seasonMatch = title.match(/\bS0+\b/i);
				if (seasonMatch) {
					return 0;
				}

				// Look for "Season X" pattern
				seasonMatch = title.match(/season\s*(\d+)/i);
				if (seasonMatch && seasonMatch[1]) {
					return parseInt(seasonMatch[1], 10);
				}

				// Look for "S##" pattern
				seasonMatch = title.match(/\bS(\d+)\b/i);
				if (seasonMatch && seasonMatch[1]) {
					return parseInt(seasonMatch[1], 10);
				}

				// Default to season 1 if no season number found
				return 1;
			}

			// Add TMDB search button to the URL form
			function addTMDBButton() {
				// Get the form
				const urlForm = document.getElementById('urlChangePosterForm');
				if (!urlForm || !urlForm.classList.contains('active')) return;

				// Don't add if already exists
				if (urlForm.querySelector('.tmdb-search-btn')) return;

				// Get the URL input group
				const urlInputGroup = urlForm.querySelector('.upload-input-group');
				if (!urlInputGroup) return;

				// Create a flex container for the URL input and poster
				if (!urlForm.querySelector('.url-input-container')) {
					// Wrap the input group in a container
					const container = document.createElement('div');
					container.className = 'url-input-container';

					// Replace the input group with the container
					urlInputGroup.parentNode.insertBefore(container, urlInputGroup);
					container.appendChild(urlInputGroup);

					// Get the URL input element
					const urlInput = urlInputGroup.querySelector('input[name="image_url"]');
					if (urlInput) {
						// Add event listener for manual URL entry with both input and change events
						const checkUrl = function () {
							handleManualUrlInput(this.value);
						};

						urlInput.addEventListener('input', checkUrl);
						urlInput.addEventListener('change', checkUrl);
						urlInput.addEventListener('paste', function (e) {
							// Short delay after paste to ensure value is updated
							setTimeout(() => {
								handleManualUrlInput(this.value);
							}, 100);
						});

						// Check if there's already a value in the input
						if (urlInput.value) {
							setTimeout(() => {
								handleManualUrlInput(urlInput.value);
							}, 300);
						}
					}
				}

				// Get title from filename
				const filenameElement = document.getElementById('changePosterFilename');
				if (!filenameElement) return;

				const filename = filenameElement.textContent;
				let title = filename.replace('Changing poster:', '').trim();
				title = title.replace(/\([^)]*\)/g, '').trim(); // Remove content in parentheses
				title = title.replace(/\[[^\]]*\]/g, '').trim(); // Remove content in brackets

				// Determine if this is a movie, TV show, or season by checking directory
				const directoryInput = document.getElementById('urlChangePosterDirectory');
				let directory = '';

				if (directoryInput) {
					directory = directoryInput.value;
				}

				// Create the search button
				const searchButton = document.createElement('button');
				searchButton.type = 'button'; // Prevent form submission
				searchButton.className = 'tmdb-search-btn';
				searchButton.innerHTML = `
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
		<circle cx="11" cy="11" r="8"></circle>
		<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
	</svg>
	Find Posters
`;

				// Store the season number as data attribute for TV seasons
				let seasonNumber = null;
				if (directory === 'tv-seasons') {
					seasonNumber = extractSeasonFromTitle(title);
					searchButton.setAttribute('data-season', seasonNumber);
				}

				// Create the attribution div
				const attributionDiv = document.createElement('div');
				attributionDiv.className = 'tmdb-attribution';
				attributionDiv.innerHTML = `
	<span>Powered by: 
		<a href="https://www.themoviedb.org/" target="_blank" rel="noopener noreferrer">TMDB</a>,
		<a href="https://www.thetvdb.com/" target="_blank" rel="noopener noreferrer">TVDB</a> &
		<a href="https://fanart.tv/" target="_blank" rel="noopener noreferrer">Fanart.tv</a>
	</span>
`;

				// Add the button after the URL input container
				const container = urlForm.querySelector('.url-input-container');
				container.insertAdjacentElement('afterend', searchButton);

				// Add the attribution div after the search button
				searchButton.insertAdjacentElement('afterend', attributionDiv);

				// Add click handler for search button
				searchButton.addEventListener('click', function () {
					let mediaType = 'movie'; // Default
					let season = null;

					if (directory === 'tv-shows') {
						mediaType = 'tv';
					} else if (directory === 'tv-seasons') {
						mediaType = 'season';
						// Use the season number we determined earlier
						season = parseInt(this.getAttribute('data-season'), 10);
						if (isNaN(season)) {
							season = 1; // Fallback
						}
					} else if (directory === 'collections') {
						mediaType = 'collection';
					}

					performTMDBSearch(title, mediaType, season);
				});
			}

			// Show preview for manually entered URL
			function showManualUrlPreview(url) {
				const urlForm = document.getElementById('urlChangePosterForm');
				if (!urlForm) return;

				const container = urlForm.querySelector('.url-input-container');
				if (!container) return;

				// Remove any existing preview
				removeExistingPreview();

				// Create poster preview
				const preview = document.createElement('div');
				preview.className = 'tmdb-poster-preview';

				// Create image with loading state
				const img = document.createElement('img');
				img.alt = 'Poster preview';

				// Set up loading behavior
				img.style.opacity = '0';
				img.style.transition = 'opacity 0.3s';
				img.onload = () => {
					img.style.opacity = '1';
				};
				img.onerror = () => {
					preview.remove(); // Remove preview if image fails to load
				};

				// Add SVG magnifying glass icon
				const icon = document.createElement('div');
				icon.className = 'tmdb-poster-icon';
				icon.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
	<circle cx="11" cy="11" r="8"></circle>
	<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
</svg>`;

				// Add image and icon to preview
				preview.appendChild(img);
				preview.appendChild(icon);
				container.appendChild(preview);

				// Set the src after appending to DOM
				img.src = url;

				// Add click handler to open larger preview
				preview.addEventListener('click', function () {
					showImagePreviewModal(url);
				});
			}

			// Remove any existing preview
			function removeExistingPreview() {
				const urlForm = document.getElementById('urlChangePosterForm');
				if (!urlForm) return;

				const container = urlForm.querySelector('.url-input-container');
				if (!container) return;

				const existingPreview = container.querySelector('.tmdb-loading, .tmdb-poster-preview');
				if (existingPreview) {
					existingPreview.remove();
				}
			}

			// Create image preview modal
			function createImagePreviewModal() {
				// Check if modal already exists
				if (document.getElementById('imagePreviewModal')) return;

				const modal = document.createElement('div');
				modal.id = 'imagePreviewModal';
				modal.className = 'image-preview-modal';
				modal.innerHTML = `
	<div class="image-preview-content">
		<img id="imagePreviewImg" src="" alt="Preview">
	</div>
	<button class="image-preview-close">×</button>
`;

				document.body.appendChild(modal);

				// Close button handler
				const closeBtn = modal.querySelector('.image-preview-close');
				closeBtn.addEventListener('click', function (e) {
					e.stopPropagation(); // Prevent event bubbling
					hideImagePreviewModal();
				});

				// Click outside to close but only this modal
				modal.addEventListener('click', function (e) {
					if (e.target === modal) {
						e.stopPropagation(); // Prevent event bubbling to other modals
						hideImagePreviewModal();
					}
				});

				// Escape key to close only the top-most modal
				document.addEventListener('keydown', function (e) {
					if (e.key === 'Escape') {
						// Check if image preview is visible
						const imagePreviewModal = document.getElementById('imagePreviewModal');
						if (imagePreviewModal && imagePreviewModal.classList.contains('show')) {
							e.preventDefault(); // Prevent other modals from closing
							hideImagePreviewModal();
						}
					}
				});
			}

			// Show the image preview modal
			function showImagePreviewModal(imageUrl) {
				// Create modal if it doesn't exist
				createImagePreviewModal();

				// Get the modal elements
				const modal = document.getElementById('imagePreviewModal');
				const img = document.getElementById('imagePreviewImg');

				// Set the image source
				img.src = imageUrl;

				// Show the modal
				modal.style.display = 'flex';
				modal.offsetHeight; // Force reflow
				modal.classList.add('show');
			}

			// Hide the image preview modal without affecting the multi-poster modal
			function hideImagePreviewModal() {
				const modal = document.getElementById('imagePreviewModal');
				if (!modal) return;

				// Reset all poster touch states when closing the image preview
				document.querySelectorAll('.poster-item.touched').forEach(item => {
					item.classList.remove('touched');
					const overlay = item.querySelector('.poster-overlay');
					if (overlay) {
						overlay.style.pointerEvents = 'none';
						overlay.querySelectorAll('.poster-btn').forEach(btn => {
							btn.style.pointerEvents = 'none';
						});
					}
				});

				modal.classList.remove('show');
				setTimeout(() => {
					modal.style.display = 'none';
				}, 300);
			}

			// Create multi-poster selection modal
			function createMultiPosterModal() {
				// Check if modal already exists
				if (document.getElementById('multiPosterModal')) {
					return;
				}

				// Create modal HTML
				const modalHTML = `
<div id="multiPosterModal" class="multi-poster-modal">
	<div class="multi-poster-content">
		<div class="multi-poster-header">
			<h3>Select a Poster</h3>
			<button type="button" class="multi-poster-close-btn">×</button>
		</div>
		<div class="multi-poster-search-bar">
			<input type="text" id="posterSearchInput" placeholder="Filter posters..." style="visibility: hidden;">
			<div class="poster-count"><span id="posterCount">0</span> posters found</div>
		</div>
		<div class="multi-poster-grid" id="posterGrid"></div>
	</div>
</div>
`;

				// Add modal to document
				document.body.insertAdjacentHTML('beforeend', modalHTML);

				// Get modal elements
				const modal = document.getElementById('multiPosterModal');
				const closeBtn = modal.querySelector('.multi-poster-close-btn');
				const searchInput = document.getElementById('posterSearchInput');

				// Add event listeners
				closeBtn.addEventListener('click', hideMultiPosterModal);

				// Close when clicking outside the content
				modal.addEventListener('click', (e) => {
					if (e.target === modal) {
						hideMultiPosterModal();
					}
				});

				// Close on escape key
				document.addEventListener('keydown', (e) => {
					if (e.key === 'Escape' && modal.classList.contains('show')) {
						hideMultiPosterModal();
					}
				});

				// Add search functionality
				searchInput.addEventListener('input', filterPosters);
			}

			// Show the multi-poster modal with provided posters
			function showMultiPosterModal(posters, mediaType = 'collection', season = null) {
				createMultiPosterModal(); // Make sure modal exists

				const modal = document.getElementById('multiPosterModal');
				const grid = document.getElementById('posterGrid');
				const posterCount = document.getElementById('posterCount');
				const searchInput = document.getElementById('posterSearchInput');
				const modalTitle = modal.querySelector('.multi-poster-header h3');

				// Update modal title based on media type
				if (modalTitle) {
					if (mediaType === 'movie') {
						modalTitle.textContent = 'Select a Movie Poster';
					} else if (mediaType === 'tv') {
						modalTitle.textContent = 'Select a TV Show Poster';
					} else if (mediaType === 'season') {
						modalTitle.textContent = season === 0 ?
							'Select a Specials Poster' :
							`Select a Season ${season} Poster`;
					} else if (mediaType === 'collection') {
						modalTitle.textContent = 'Select a Collection Poster';
					} else {
						modalTitle.textContent = 'Select a Poster';
					}
				}

				// Reset search input
				searchInput.value = '';

				// Clear existing posters
				grid.innerHTML = '';

				// Update poster count
				posterCount.textContent = posters.length;

				// Remove any existing info messages
				const existingInfo = modal.querySelector('.multi-poster-info');
				if (existingInfo) {
					existingInfo.remove();
				}

				// Prepare a document fragment for better performance
				const fragment = document.createDocumentFragment();

				// Add posters to grid
				if (posters.length === 0) {
					const noPosters = document.createElement('div');
					noPosters.className = 'no-posters-found';
					noPosters.textContent = 'No posters found';
					fragment.appendChild(noPosters);
				} else {
					posters.forEach((poster, index) => {
						// Create the poster element
						const posterElement = document.createElement('div');
						posterElement.className = 'poster-item';
						posterElement.dataset.url = poster.url;
						posterElement.dataset.name = poster.name || '';
						posterElement.dataset.index = index;

						// Add media type and season data attributes (for season badges)
						posterElement.dataset.mediaType = mediaType;
						if (poster.season !== undefined) {
							posterElement.dataset.season = poster.season;
						} else if (season !== null && mediaType === 'season') {
							posterElement.dataset.season = season;
						}

						// Create loading spinner
						const loadingElement = document.createElement('div');
						loadingElement.className = 'poster-loading';
						loadingElement.innerHTML = '<div class="poster-spinner"></div>';
						posterElement.appendChild(loadingElement);

						// Create image element
						const imgElement = document.createElement('img');
						imgElement.alt = poster.name || 'Poster';
						posterElement.appendChild(imgElement);

						// Create the button overlay
						const overlayElement = document.createElement('div');
						overlayElement.className = 'poster-overlay';
						overlayElement.style.pointerEvents = 'none'; // Initially disable pointer events on overlay

						// Create the View button
						const viewButton = document.createElement('button');
						viewButton.className = 'poster-btn';
						viewButton.style.pointerEvents = 'none'; // Initially disable pointer events
						viewButton.innerHTML = `
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
						<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
						<circle cx="12" cy="12" r="3"></circle>
					</svg>
					View
				`;

						// Create the Select button
						const selectButton = document.createElement('button');
						selectButton.className = 'poster-btn';
						selectButton.style.pointerEvents = 'none'; // Initially disable pointer events
						selectButton.innerHTML = `
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
						<polyline points="20 6 9 17 4 12"></polyline>
					</svg>
					Select
				`;

						// Add buttons to overlay in new order - View first, then Select
						overlayElement.appendChild(viewButton);
						overlayElement.appendChild(selectButton);

						// Add overlay to poster element
						posterElement.appendChild(overlayElement);

						// Add hover detection for desktop devices only
						posterElement.addEventListener('mouseenter', function () {
							// Only enable hover behavior on non-touch devices
							if (!isTouchDevice) {
								const overlay = this.querySelector('.poster-overlay');
								if (overlay) {
									overlay.style.pointerEvents = 'auto';
									overlay.querySelectorAll('.poster-btn').forEach(btn => {
										btn.style.pointerEvents = 'auto';
									});
								}
							}
						});

						// Reset on mouse leave for desktop devices
						posterElement.addEventListener('mouseleave', function () {
							// Only for desktop devices and if not in touched state
							if (!isTouchDevice && !this.classList.contains('touched')) {
								const overlay = this.querySelector('.poster-overlay');
								if (overlay) {
									overlay.style.pointerEvents = 'none';
									overlay.querySelectorAll('.poster-btn').forEach(btn => {
										btn.style.pointerEvents = 'none';
									});
								}
							}
						});

						// Add touch interaction handling
						posterElement.addEventListener('click', function (e) {
							// First tap anywhere on poster should just show the overlay
							if (!this.classList.contains('touched')) {
								e.preventDefault();
								e.stopPropagation();

								// Remove 'touched' class from all other posters
								document.querySelectorAll('.poster-item.touched').forEach(item => {
									if (item !== this) {
										item.classList.remove('touched');
										// Disable pointer events on all other overlays and their buttons
										const otherOverlay = item.querySelector('.poster-overlay');
										if (otherOverlay) {
											otherOverlay.style.pointerEvents = 'none';
											otherOverlay.querySelectorAll('.poster-btn').forEach(btn => {
												btn.style.pointerEvents = 'none';
											});
										}
									}
								});

								// Add 'touched' class to this poster
								this.classList.add('touched');

								// Enable pointer events on this overlay and its buttons after a short delay
								setTimeout(() => {
									const overlay = this.querySelector('.poster-overlay');
									if (overlay) {
										overlay.style.pointerEvents = 'auto';
										overlay.querySelectorAll('.poster-btn').forEach(btn => {
											btn.style.pointerEvents = 'auto';
										});
									}
								}, 50);

								return false;
							}
						});

						// Add click events for the buttons
						selectButton.addEventListener('click', function (e) {
							e.stopPropagation(); // Prevent the poster click event

							// Immediately hide the modal
							modal.classList.remove('show');
							modal.style.display = 'none';

							// Get the form elements and update immediately
							const urlForm = document.getElementById('urlChangePosterForm');
							if (urlForm) {
								const urlInput = urlForm.querySelector('input[name="image_url"]');
								if (urlInput) {
									// Get the poster URL from the dataset
									const posterUrl = posterElement.dataset.url;

									// Update the input value
									urlInput.value = posterUrl;

									// Trigger input event for any listeners
									const inputEvent = new Event('input', { bubbles: true });
									urlInput.dispatchEvent(inputEvent);

									// Make sure the submit button is enabled
									const submitButton = urlForm.querySelector('button[type="submit"]');
									if (submitButton) {
										submitButton.disabled = false;
									}

									// Find the container for preview
									const container = urlForm.querySelector('.url-input-container');
									if (container) {
										// Use the existing function for preview
										handleManualUrlInput(posterUrl);
									}
								}
							}
						});

						viewButton.addEventListener('click', function (e) {
							e.stopPropagation(); // Prevent the poster click event

							// Force remove touched state from the parent poster before opening preview
							const posterItem = this.closest('.poster-item');
							if (posterItem) {
								posterItem.classList.remove('touched');

								// Disable pointer events for buttons and overlay to force reset for next tap
								const overlay = posterItem.querySelector('.poster-overlay');
								if (overlay) {
									overlay.style.pointerEvents = 'none';
									overlay.querySelectorAll('.poster-btn').forEach(btn => {
										btn.style.pointerEvents = 'none';
									});
								}
							}

							// Show the image in full screen without closing the multi-poster modal
							showImagePreviewModal(posterElement.dataset.url);
						});

						// Set the image source last to ensure the loading spinner shows first
						imgElement.onload = () => {
							// Remove loading spinner once image has loaded
							loadingElement.style.display = 'none';
						};

						imgElement.onerror = () => {
							// Show error state if image fails to load
							loadingElement.innerHTML = `
				<div style="display: flex; height: 100%; align-items: center; justify-content: center; 
							color: var(--text-secondary); flex-direction: column; text-align: center; padding: 10px;">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" 
						 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="10"></circle>
						<line x1="14.31" y1="8" x2="20.05" y2="17.94"></line>
						<line x1="9.69" y1="8" x2="21.17" y2="8"></line>
						<line x1="7.38" y1="12" x2="13.12" y2="2.06"></line>
						<line x1="9.69" y1="16" x2="3.95" y2="6.06"></line>
						<line x1="14.31" y1="16" x2="2.83" y2="16"></line>
						<line x1="16.62" y1="12" x2="10.88" y2="21.94"></line>
					</svg>
					<small style="margin-top: 8px;">Failed to load</small>
				</div>
			`;
						};

						// Set image source after setting up handlers
						imgElement.src = poster.url;

						fragment.appendChild(posterElement);
					});
				}

				// Add all posters to the grid at once for better performance
				grid.appendChild(fragment);

				// Add click handler to clear touch state when clicking outside posters
				document.addEventListener('click', function (e) {
					// If clicking outside a poster item
					if (!e.target.closest('.poster-item')) {
						// Remove touched class from all posters
						document.querySelectorAll('.poster-item.touched').forEach(item => {
							item.classList.remove('touched');
						});
					}
				}, { capture: true });

				// Show modal
				modal.style.display = 'flex';
				// Force a reflow
				modal.offsetHeight;
				modal.classList.add('show');
			}

			// Hide the multi-poster modal
			function hideMultiPosterModal() {
				const modal = document.getElementById('multiPosterModal');
				if (modal) {
					// Remove touched state from all posters before hiding and disable pointer events
					document.querySelectorAll('.poster-item.touched').forEach(item => {
						item.classList.remove('touched');
						const overlay = item.querySelector('.poster-overlay');
						if (overlay) {
							overlay.style.pointerEvents = 'none';
							overlay.querySelectorAll('.poster-btn').forEach(btn => {
								btn.style.pointerEvents = 'none';
							});
						}
					});

					modal.classList.remove('show');
					setTimeout(() => {
						modal.style.display = 'none';
					}, 300); // Match transition duration
				}
			}

			// Filter posters based on search input
			function filterPosters() {
				const searchInput = document.getElementById('posterSearchInput');
				const searchTerm = searchInput.value.toLowerCase().trim();
				const posterItems = document.querySelectorAll('.poster-item');
				const posterCount = document.getElementById('posterCount');

				let visibleCount = 0;

				posterItems.forEach(item => {
					const posterName = item.dataset.name.toLowerCase();
					if (posterName.includes(searchTerm)) {
						item.style.display = '';
						visibleCount++;
					} else {
						item.style.display = 'none';
					}
				});

				// Update the visible count
				posterCount.textContent = visibleCount;
			}

			// Perform TMDB search
			function performTMDBSearch(title, mediaType, season = null) {
				// Get the URL form
				const urlForm = document.getElementById('urlChangePosterForm');
				if (!urlForm) {
					return;
				}

				// Get input
				const urlInput = urlForm.querySelector('input[name="image_url"]');
				if (!urlInput) {
					return;
				}

				// Find the container
				const container = urlForm.querySelector('.url-input-container');
				if (!container) {
					return;
				}

				// Remove any existing preview
				removeExistingPreview();

				// Also remove any existing error containers
				const existingError = container.querySelector('.tmdb-error-container');
				if (existingError) {
					existingError.remove();
				}

				// Show loading indicator
				const loading = document.createElement('div');
				loading.className = 'tmdb-loading';
				loading.innerHTML = `
		<div class="tmdb-spinner"></div>
		<div>Searching...</div>
	`;

				container.appendChild(loading);

				// For TV seasons, try to extract just the show name without season info
				let searchTitle = title;
				if (mediaType === 'season') {
					// Remove "Season X", "SXX", and "Specials" from the title
					searchTitle = title.replace(/\s*[-:]\s*Season\s*\d+/i, '')
						.replace(/\s*S\d+\b/i, '')
						.replace(/\s*[-:]\s*Specials/i, '')
						.replace(/\s+Specials$/i, '')
						.replace(/\s+Special$/i, '')
						.trim();
				}

				// Create form data for request
				const formData = new FormData();
				formData.append('query', searchTitle);
				formData.append('type', mediaType);

				// Add season if searching for TV season
				if (season !== null && (mediaType === 'tv' || mediaType === 'season')) {
					formData.append('season', season);
				}

				// Make the API request
				fetch('./include/fetch-posters.php', {
					method: 'POST',
					body: formData
				})
					.then(response => response.json())
					.then(data => {
						// Remove loading indicator
						loading.remove();

						if (data.success) {
							// Check if we have multiple posters for ANY media type
							if (data.hasMultiplePosters && Array.isArray(data.allPosters) && data.allPosters.length > 1) {
								// Show multi-poster selection modal
								showMultiPosterModal(data.allPosters, mediaType, season);
							} else if (data.posterUrl) {
								// Single poster handling (same as before, but without season badge)
								// Fill in the URL input
								urlInput.value = data.posterUrl;

								// Trigger input event to enable the submit button if needed
								const inputEvent = new Event('input', { bubbles: true });
								urlInput.dispatchEvent(inputEvent);

								// Create poster preview (without season badge)
								showManualUrlPreview(data.posterUrl);

								// Highlight the URL input but don't focus it (prevents keyboard on mobile)
								urlInput.style.borderColor = 'var(--accent-primary)';

								// On desktop only, focus the input (won't trigger keyboard on mobile)
								if (window.innerWidth > 768) {
									urlInput.focus();
								}

								// Flash effect to draw attention to the URL being populated
								urlInput.style.backgroundColor = 'rgba(229, 160, 13, 0.2)';
								setTimeout(() => {
									urlInput.style.backgroundColor = '';
								}, 1000);

								// Make sure the submit button is enabled
								const submitButton = urlForm.querySelector('button[type="submit"]');
								if (submitButton) {
									submitButton.disabled = false;
								}
							} else {
								// No poster URL available (shouldn't happen if data.success is true)
								showErrorMessage(container, mediaType);
							}
						} else {
							// Show error message with enhanced styling
							showErrorMessage(container, mediaType);
						}
					})
					.catch(error => {
						// Remove loading indicator
						loading.remove();

						// Show connection error message
						showConnectionErrorMessage(container);
					});
			}

			// Function to show error message with enhanced styling
			function showErrorMessage(container, mediaType) {
				// First, remove any existing error containers
				const existingError = container.querySelector('.tmdb-error-container');
				if (existingError) {
					existingError.remove();
				}

				// Create a new error message container
				const errorMsg = document.createElement('div');
				errorMsg.className = 'tmdb-error-container';

				// Add the error icon SVG with improved styling
				errorMsg.innerHTML = `
		<div class="error-content">
			<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="12" cy="12" r="10"></circle>
				<line x1="12" y1="8" x2="12" y2="12"></line>
				<line x1="12" y1="16" x2="12.01" y2="16"></line>
			</svg>
			<div class="error-text">No poster found</div>
			<div class="error-subtext">${mediaType === 'collection' ? 'Collection' :
						mediaType === 'season' ? 'Season' :
							mediaType === 'tv' ? 'TV Show' : 'Movie'} not found</div>
		</div>
	`;

				container.appendChild(errorMsg);
			}

			// Function to show connection error message
			function showConnectionErrorMessage(container) {
				// First, remove any existing error containers
				const existingError = container.querySelector('.tmdb-error-container');
				if (existingError) {
					existingError.remove();
				}

				const errorMsg = document.createElement('div');
				errorMsg.className = 'tmdb-error-container';
				errorMsg.innerHTML = `
		<div class="error-content">
			<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
				<polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon>
				<line x1="12" y1="8" x2="12" y2="12"></line>
				<line x1="12" y1="16" x2="12.01" y2="16"></line>
			</svg>
			<div class="error-text">Search failed</div>
			<div class="error-subtext">Connection error</div>
		</div>
	`;
				container.appendChild(errorMsg);
			}

			// Clean up TMDB elements
			function cleanupTMDB() {
				// Remove search button
				const searchButton = document.querySelector('.tmdb-search-btn');
				if (searchButton) {
					searchButton.remove();
				}

				// Remove attribution div
				const attributionDiv = document.querySelector('.tmdb-attribution');
				if (attributionDiv) {
					attributionDiv.remove();
				}

				// Unwrap the URL input group
				const container = document.querySelector('.url-input-container');
				if (container) {
					const inputGroup = container.querySelector('.upload-input-group');
					if (inputGroup && container.parentNode) {
						container.parentNode.insertBefore(inputGroup, container);
						container.remove();
					}
				}

				// Clear the URL input field
				const urlInput = document.querySelector('#urlChangePosterForm input[name="image_url"]');
				if (urlInput) {
					urlInput.value = '';
					urlInput.style.borderColor = ''; // Reset border color
					urlInput.style.backgroundColor = ''; // Reset background color
				}
			}

			// Listen for tab changes
			document.addEventListener('click', function (event) {
				const clickedTab = event.target.closest('.upload-tab-btn');
				if (clickedTab) {
					// Clean up
					cleanupTMDB();

					// Add button if URL tab
					if (clickedTab.getAttribute('data-tab') === 'url') {
						setTimeout(addTMDBButton, 100);
					}
				}
			});

			// Listen for modal close
			document.addEventListener('click', function (event) {
				if (event.target.closest('.modal-close-btn')) {
					cleanupTMDB();
				}
			});

			// Watch for modal visibility changes
			const observer = new MutationObserver((mutations) => {
				mutations.forEach(mutation => {
					if (mutation.type === 'attributes' &&
						mutation.attributeName === 'style' &&
						mutation.target.id === 'changePosterModal') {

						if (mutation.target.style.display === 'none') {
							cleanupTMDB();

							// Also hide image preview if it's open
							hideImagePreviewModal();
						}
					}
				});
			});

			// Listen for modal opening
			document.addEventListener('click', function (event) {
				if (event.target.closest('.change-poster-btn')) {
					// Clean up
					cleanupTMDB();

					// Wait for modal to open
					setTimeout(() => {
						// Observe modal
						const modal = document.getElementById('changePosterModal');
						if (modal && !observer._isObserving) {
							observer.observe(modal, {
								attributes: true,
								attributeFilter: ['style']
							});
							observer._isObserving = true;
						}

						// Check if URL tab is active
						const urlTab = document.querySelector('.upload-tab-btn[data-tab="url"]');
						if (urlTab && urlTab.classList.contains('active')) {
							addTMDBButton();
						}
					}, 300);
				}
			});

			// Initialize
			function initialize() {
				// Create the image preview modal
				createImagePreviewModal();

				// Check if URL tab is active
				const urlTab = document.querySelector('.upload-tab-btn[data-tab="url"]');
				if (urlTab && urlTab.classList.contains('active')) {
					addTMDBButton();
				}

				// Observe modal
				const modal = document.getElementById('changePosterModal');
				if (modal && !observer._isObserving) {
					observer.observe(modal, {
						attributes: true,
						attributeFilter: ['style']
					});
					observer._isObserving = true;
				}

				// Add global click handler to remove touched state when clicking outside
				document.addEventListener('click', function (e) {
					// Don't process if clicking inside a poster item
					if (e.target.closest('.poster-item')) {
						return;
					}

					// Remove touched class from all posters and disable pointer events on overlays
					document.querySelectorAll('.poster-item.touched').forEach(item => {
						item.classList.remove('touched');
						const overlay = item.querySelector('.poster-overlay');
						if (overlay) {
							overlay.style.pointerEvents = 'none';
							overlay.querySelectorAll('.poster-btn').forEach(btn => {
								btn.style.pointerEvents = 'none';
							});
						}
					});
				});
			}

			// Initialize on DOM ready
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initialize);
			} else {
				initialize();
			}
		});
	</script>

	<script>
		// Add file preview functionality to match URL preview exactly
		document.addEventListener('DOMContentLoaded', function () {
			// Get the file input element and its parent container
			const fileInput = document.getElementById('fileChangePosterInput');
			if (!fileInput) return;

			const inputGroup = fileInput.closest('.upload-input-group');
			if (!inputGroup) return;

			// Style the input group as flex container to place preview alongside input
			inputGroup.style.display = 'flex';
			inputGroup.style.gap = '20px';
			inputGroup.style.alignItems = 'start';

			// Create the preview container with same styling as URL preview
			const previewContainer = document.createElement('div');
			previewContainer.className = 'tmdb-poster-preview';
			previewContainer.id = 'file-poster-preview';
			previewContainer.style.width = '100px';
			previewContainer.style.height = '150px';
			previewContainer.style.borderRadius = '8px';
			previewContainer.style.overflow = 'hidden';
			previewContainer.style.boxShadow = '0 4px 8px rgba(0, 0, 0, 0.2)';
			previewContainer.style.flexShrink = '0';
			previewContainer.style.backgroundColor = 'var(--bg-secondary)';
			previewContainer.style.display = 'none';
			previewContainer.style.cursor = 'pointer';
			previewContainer.style.position = 'relative';
			previewContainer.style.border = '2px solid #E5A00D';
			previewContainer.style.marginBottom = '9px';

			// Create the magnifying icon overlay (copied from URL preview)
			const overlayIcon = document.createElement('div');
			overlayIcon.className = 'tmdb-poster-icon';
			overlayIcon.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
			<circle cx="11" cy="11" r="8"></circle>
			<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
		</svg>`;
			overlayIcon.style.position = 'absolute';
			overlayIcon.style.top = '50%';
			overlayIcon.style.left = '50%';
			overlayIcon.style.transform = 'translate(-50%, -50%)';
			overlayIcon.style.width = '24px';
			overlayIcon.style.height = '24px';
			overlayIcon.style.zIndex = '3';
			overlayIcon.style.opacity = '0';
			overlayIcon.style.transition = 'all 0.2s';

			// Create the preview image element
			const previewImg = document.createElement('img');
			previewImg.style.width = '100%';
			previewImg.style.height = '100%';
			previewImg.style.objectFit = 'cover';
			previewImg.style.display = 'block';

			// Add hover effect (same as URL preview)
			previewContainer.addEventListener('mouseenter', function () {
				overlayIcon.style.opacity = '1';
				this.style.setProperty('--overlay-opacity', '1');
			});

			previewContainer.addEventListener('mouseleave', function () {
				overlayIcon.style.opacity = '0';
				this.style.setProperty('--overlay-opacity', '0');
			});

			// Add the hover overlay effect with pseudo-elements
			const style = document.createElement('style');
			style.textContent = `
			#file-poster-preview::before {
				content: '';
				position: absolute;
				top: 50%;
				left: 50%;
				transform: translate(-50%, -50%);
				z-index: 2;
				opacity: 0;
				opacity: var(--overlay-opacity, 0);
				transition: all 0.2s;
				background: rgba(229, 160, 13, 0.9);
				width: 40px;
				height: 40px;
				border-radius: 50%;
				display: flex;
				align-items: center;
				justify-content: center;
				box-shadow: 0 2px 8px rgba(0,0,0,0.5);
			}
			
			#file-poster-preview::after {
				content: '';
				position: absolute;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background: rgba(0,0,0,0.6);
				opacity: 0;
				opacity: var(--overlay-opacity, 0);
				transition: opacity 0.2s;
			}
		`;
			document.head.appendChild(style);

			// Add elements to the DOM
			previewContainer.appendChild(previewImg);
			previewContainer.appendChild(overlayIcon);
			inputGroup.appendChild(previewContainer);

			// Create the fullscreen preview modal (if it doesn't exist yet)
			let imagePreviewModal = document.getElementById('imagePreviewModal');

			if (!imagePreviewModal) {
				imagePreviewModal = document.createElement('div');
				imagePreviewModal.id = 'imagePreviewModal';
				imagePreviewModal.className = 'image-preview-modal';
				imagePreviewModal.innerHTML = `
				<div class="image-preview-content">
					<img id="imagePreviewImg" src="" alt="Preview">
				</div>
				<button class="image-preview-close">×</button>
			`;
				document.body.appendChild(imagePreviewModal);

				// Style the modal to match the URL preview modal
				imagePreviewModal.style.display = 'none';
				imagePreviewModal.style.position = 'fixed';
				imagePreviewModal.style.top = '0';
				imagePreviewModal.style.left = '0';
				imagePreviewModal.style.width = '100%';
				imagePreviewModal.style.height = '100%';
				imagePreviewModal.style.background = 'rgba(0, 0, 0, 0.85)';
				imagePreviewModal.style.zIndex = '1100';
				imagePreviewModal.style.opacity = '0';
				imagePreviewModal.style.transition = 'opacity 0.3s ease';
				imagePreviewModal.style.justifyContent = 'center';
				imagePreviewModal.style.alignItems = 'center';

				// Style the modal content
				const modalContent = imagePreviewModal.querySelector('.image-preview-content');
				modalContent.style.position = 'relative';
				modalContent.style.maxWidth = '80%';
				modalContent.style.maxHeight = '80%';
				modalContent.style.borderRadius = '8px';
				modalContent.style.overflow = 'hidden';
				modalContent.style.boxShadow = '0 8px 16px rgba(0,0,0,0.5)';

				// Style the image
				const modalImg = imagePreviewModal.querySelector('#imagePreviewImg');
				modalImg.style.display = 'block';
				modalImg.style.maxWidth = '100%';
				modalImg.style.maxHeight = '80vh';
				modalImg.style.margin = '0 auto';

				// Style the close button
				const closeBtn = imagePreviewModal.querySelector('.image-preview-close');
				closeBtn.style.position = 'absolute';
				closeBtn.style.top = '20px';
				closeBtn.style.right = '20px';
				closeBtn.style.background = 'var(--bg-secondary)';
				closeBtn.style.color = 'var(--text-primary)';
				closeBtn.style.width = '40px';
				closeBtn.style.height = '40px';
				closeBtn.style.borderRadius = '50%';
				closeBtn.style.display = 'flex';
				closeBtn.style.alignItems = 'center';
				closeBtn.style.justifyContent = 'center';
				closeBtn.style.cursor = 'pointer';
				closeBtn.style.border = 'none';
				closeBtn.style.fontSize = '24px';
				closeBtn.style.boxShadow = '0 2px 10px rgba(0,0,0,0.3)';
				closeBtn.style.transition = 'all 0.2s';

				// Add hover effect to close button
				closeBtn.addEventListener('mouseover', function () {
					this.style.background = 'var(--bg-tertiary)';
					this.style.transform = 'scale(1.1)';
				});

				closeBtn.addEventListener('mouseout', function () {
					this.style.background = 'var(--bg-secondary)';
					this.style.transform = 'scale(1)';
				});

				// Close modal on button click
				closeBtn.addEventListener('click', function () {
					hideImagePreviewModal();
				});

				// Close modal on outside click
				imagePreviewModal.addEventListener('click', function (e) {
					if (e.target === imagePreviewModal) {
						hideImagePreviewModal();
					}
				});

				// Close modal on escape key
				document.addEventListener('keydown', function (e) {
					if (e.key === 'Escape' && imagePreviewModal.classList.contains('show')) {
						hideImagePreviewModal();
					}
				});
			}

			// Show image preview modal function
			function showImagePreviewModal(imageUrl) {
				const modalImg = document.getElementById('imagePreviewImg');
				modalImg.src = imageUrl;

				imagePreviewModal.style.display = 'flex';
				// Force reflow
				imagePreviewModal.offsetHeight;
				imagePreviewModal.classList.add('show');
				imagePreviewModal.style.opacity = '1';
			}

			// Hide image preview modal function
			function hideImagePreviewModal() {
				imagePreviewModal.classList.remove('show');
				imagePreviewModal.style.opacity = '0';
				setTimeout(() => {
					imagePreviewModal.style.display = 'none';
				}, 300);
			}

			// Add click handler to preview container
			previewContainer.addEventListener('click', function () {
				showImagePreviewModal(previewImg.src);
			});

			// Listen for file selection changes
			fileInput.addEventListener('change', function () {
				if (this.files && this.files[0]) {
					const file = this.files[0];

					// Validate it's an image
					if (!file.type.match('image.*')) {
						previewContainer.style.display = 'none';
						return;
					}

					// Read the file and update preview
					const reader = new FileReader();
					reader.onload = function (e) {
						previewImg.src = e.target.result;
						previewContainer.style.display = 'flex';
					};

					reader.readAsDataURL(file);

					// Important: Keep the file name element empty
					const fileNameElement = this.parentElement.querySelector('.file-name');
					if (fileNameElement) {
						fileNameElement.textContent = '';
					}
				} else {
					// No file selected, hide preview
					previewContainer.style.display = 'none';
				}
			});

			// Clear preview and file selection when switching tabs
			const changePosterTabs = document.querySelectorAll('.upload-tab-btn');
			if (changePosterTabs) {
				changePosterTabs.forEach(tab => {
					tab.addEventListener('click', function () {
						if (this.getAttribute('data-tab') !== 'file') {
							// We're switching away from file tab, clear everything
							resetFileInput();
						} else if (fileInput.files && fileInput.files[0]) {
							previewContainer.style.display = 'flex';
						}
					});
				});
			}

			// Function to reset file input and preview
			function resetFileInput() {
				// Hide preview
				previewContainer.style.display = 'none';

				// Reset file input value
				fileInput.value = '';

				// Reset file name display
				const fileNameElement = fileInput.parentElement.querySelector('.file-name');
				if (fileNameElement) {
					fileNameElement.textContent = '';
				}

				// Reset submit button state
				const submitButton = document.querySelector('#fileChangePosterForm button[type="submit"]');
				if (submitButton) {
					submitButton.disabled = true;
				}
			}

			// Handle modal close to reset everything
			const changePosterModal = document.getElementById('changePosterModal');
			if (changePosterModal) {
				// Handle close button click
				const closeButton = changePosterModal.querySelector('.modal-close-btn');
				if (closeButton) {
					closeButton.addEventListener('click', resetFileInput);
				}

				// Add a direct click handler to the modal background
				// This ensures it runs even if other handlers stop propagation
				changePosterModal.addEventListener('mousedown', function (e) {
					// Only trigger on direct clicks to the modal backdrop
					if (e.target === this) {
						resetFileInput();
					}
				}, true); // Use capture phase to ensure this runs first

				// Handle ESC key
				document.addEventListener('keydown', function (e) {
					if (e.key === 'Escape' &&
						changePosterModal.style.display !== 'none' &&
						getComputedStyle(changePosterModal).display !== 'none') {
						resetFileInput();
					}
				});

				// Also watch for modal being hidden via class changes
				const observer = new MutationObserver(function (mutations) {
					mutations.forEach(function (mutation) {
						if (mutation.attributeName === 'class' ||
							mutation.attributeName === 'style') {
							// Check if the modal was visible and is now hidden
							const wasVisible = mutation.oldValue &&
								(mutation.oldValue.includes('show') ||
									!mutation.oldValue.includes('display: none'));
							const isHidden = !changePosterModal.classList.contains('show') ||
								changePosterModal.style.display === 'none';

							if (wasVisible && isHidden) {
								resetFileInput();
							}
						}
					});
				});

				observer.observe(changePosterModal, {
					attributes: true,
					attributeFilter: ['class', 'style'],
					attributeOldValue: true
				});
			}
		});
	</script>

	<!-- Add support button to header -->
	<script>
		document.addEventListener('DOMContentLoaded', function () {


			// Add event listeners for the modal
			const supportButton = document.getElementById('showSupportModal');
			const supportModal = document.getElementById('supportModal');
			const closeSupportButton = supportModal?.querySelector('.modal-close-btn');

			if (supportButton && supportModal) {
				// Show modal function
				function showSupportModal() {
					supportModal.style.display = 'block';
					supportModal.offsetHeight; // Force reflow
					supportModal.classList.add('show');
				}

				// Hide modal function
				function hideSupportModal() {
					supportModal.classList.remove('show');
					setTimeout(() => {
						supportModal.style.display = 'none';
					}, 300);
				}

				// Event listeners
				supportButton.addEventListener('click', showSupportModal);

				if (closeSupportButton) {
					closeSupportButton.addEventListener('click', hideSupportModal);
				}

				// Close when clicking outside the modal
				supportModal.addEventListener('click', function (e) {
					if (e.target === supportModal) {
						hideSupportModal();
					}
				});

				// Close on escape key
				document.addEventListener('keydown', function (e) {
					if (e.key === 'Escape' && supportModal.classList.contains('show')) {
						hideSupportModal();
					}
				});
			}
		});
	</script>

	<script>
		/**
		 * Custom Tooltip Implementation for Posteria
		 * 
		 * This script adds a custom tooltip system to replace native title attributes
		 * with more attractive, customizable tooltips.
		 */

		document.addEventListener('DOMContentLoaded', function () {
			// Create tooltip element
			const tooltip = document.createElement('div');
			tooltip.className = 'custom-tooltip';
			tooltip.style.display = 'none';
			document.body.appendChild(tooltip);

			// Add CSS for tooltips
			const tooltipStyle = document.createElement('style');
			tooltipStyle.textContent = `
		.custom-tooltip {
			position: fixed;
			background: var(--bg-tertiary);
			color: var(--text-primary);
			padding: 8px 12px;
			border-radius: 8px;
			font-size: 14px;
			max-width: 300px;
			box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
			z-index: 1000;
			pointer-events: none;
			transition: opacity 0.2s;
			opacity: 0;
			border: 1px solid var(--border-color);
			word-wrap: break-word;
			line-height: 1.4;
			text-align: center;
		}
		
		/* Arrow indicator for the tooltip */
		.custom-tooltip:after {
			content: '';
			position: absolute;
			width: 10px;
			height: 10px;
			background: var(--bg-tertiary);
		}
		
		/* Bottom arrow (when tooltip is above the element) */
		.custom-tooltip.tooltip-top:after {
			bottom: -5px;
			left: 50%;
			transform: translateX(-50%) rotate(45deg);
			border-right: 1px solid var(--border-color);
			border-bottom: 1px solid var(--border-color);
		}
		
		/* Top arrow (when tooltip is below the element) */
		.custom-tooltip.tooltip-bottom:after {
			top: -5px;
			left: 50%;
			transform: translateX(-50%) rotate(45deg);
			border-left: 1px solid var(--border-color);
			border-top: 1px solid var(--border-color);
		}
		
		/* Left arrow (when tooltip is to the right of the element) */
		.custom-tooltip.tooltip-left:after {
			top: 50%;
			left: -5px;
			transform: translateY(-50%) rotate(45deg);
			border-left: 1px solid var(--border-color);
			border-bottom: 1px solid var(--border-color);
		}
		
		/* Right arrow (when tooltip is to the left of the element) */
		.custom-tooltip.tooltip-right:after {
			top: 50%;
			right: -5px;
			transform: translateY(-50%) rotate(45deg);
			border-right: 1px solid var(--border-color);
			border-top: 1px solid var(--border-color);
		}
		
		.custom-tooltip.visible {
			opacity: 1;
		}
		
		/* Add tooltip trigger class */
		.tooltip-trigger {
			cursor: pointer;
			position: relative;
		}
	`;
			document.head.appendChild(tooltipStyle);

			// Function to position the tooltip
			function positionTooltip(target, tooltip) {
				const rect = target.getBoundingClientRect();
				const tooltipRect = tooltip.getBoundingClientRect();

				// Get viewport dimensions
				const viewportWidth = window.innerWidth;
				const viewportHeight = window.innerHeight;

				// Calculate center positions
				const centerX = rect.left + rect.width / 2;
				const centerY = rect.top + rect.height / 2;

				// Calculate available space in each direction
				const spaceAbove = rect.top;
				const spaceBelow = viewportHeight - rect.bottom;
				const spaceLeft = rect.left;
				const spaceRight = viewportWidth - rect.right;

				// Determine the preferred position (top, right, bottom, left)
				// Start by removing all position classes
				tooltip.classList.remove('tooltip-top', 'tooltip-bottom', 'tooltip-left', 'tooltip-right');

				let top, left;

				// First try vertical positioning (above or below) as it's usually more natural
				if (spaceBelow >= tooltipRect.height + 10 || (spaceBelow > spaceAbove && spaceAbove < tooltipRect.height + 10)) {
					// Position below the element
					top = rect.bottom + 10;
					left = centerX - (tooltipRect.width / 2);
					tooltip.classList.add('tooltip-bottom');
				} else if (spaceAbove >= tooltipRect.height + 10) {
					// Position above the element
					top = rect.top - tooltipRect.height - 10;
					left = centerX - (tooltipRect.width / 2);
					tooltip.classList.add('tooltip-top');
				}
				// If vertical positioning doesn't work well, try horizontal
				else if (spaceRight >= tooltipRect.width + 10 || (spaceRight > spaceLeft && spaceLeft < tooltipRect.width + 10)) {
					// Position to the right
					top = centerY - (tooltipRect.height / 2);
					left = rect.right + 10;
					tooltip.classList.add('tooltip-left');
				} else if (spaceLeft >= tooltipRect.width + 10) {
					// Position to the left
					top = centerY - (tooltipRect.height / 2);
					left = rect.left - tooltipRect.width - 10;
					tooltip.classList.add('tooltip-right');
				} else {
					// Default to below if no good option (this is a fallback)
					top = rect.bottom + 10;
					left = centerX - (tooltipRect.width / 2);
					tooltip.classList.add('tooltip-bottom');
				}

				// Ensure tooltip stays within viewport horizontally
				if (left < 10) {
					left = 10;
				} else if (left + tooltipRect.width > viewportWidth - 10) {
					left = viewportWidth - tooltipRect.width - 10;
				}

				// Ensure tooltip stays within viewport vertically
				if (top < 10) {
					top = 10;
				} else if (top + tooltipRect.height > viewportHeight - 10) {
					top = viewportHeight - tooltipRect.height - 10;
				}

				// Apply position
				tooltip.style.top = `${top}px`;
				tooltip.style.left = `${left}px`;
			}

			// Initialize tooltips
			function initTooltips() {
				// Find all elements with title attributes
				const elementsWithTitle = document.querySelectorAll('[title]');
				elementsWithTitle.forEach(element => {
					// Store title text and remove the attribute
					const titleText = element.getAttribute('title');
					element.removeAttribute('title');

					// Add data attribute instead
					element.setAttribute('data-tooltip', titleText);

					// Add tooltip trigger class
					element.classList.add('tooltip-trigger');
				});

				// Add event delegation for showing tooltips
				document.addEventListener('mouseover', function (e) {
					const target = e.target.closest('.tooltip-trigger');
					if (target && target.getAttribute('data-tooltip')) {
						tooltip.textContent = target.getAttribute('data-tooltip');
						tooltip.style.display = 'block';

						// Force reflow for animation
						tooltip.offsetHeight;

						// Position the tooltip
						positionTooltip(target, tooltip);

						// Show tooltip with animation
						tooltip.classList.add('visible');
					}
				});

				document.addEventListener('mouseout', function (e) {
					if (e.target.closest('.tooltip-trigger')) {
						tooltip.classList.remove('visible');
						setTimeout(() => {
							if (!tooltip.classList.contains('visible')) {
								tooltip.style.display = 'none';
							}
						}, 200); // Match transition duration
					}
				});

				// Special handling for touch devices
				let touchTimer;
				document.addEventListener('touchstart', function (e) {
					const target = e.target.closest('.tooltip-trigger');
					if (target && target.getAttribute('data-tooltip')) {
						touchTimer = setTimeout(() => {
							tooltip.textContent = target.getAttribute('data-tooltip');
							tooltip.style.display = 'block';

							// Force reflow for animation
							tooltip.offsetHeight;

							// Position the tooltip
							positionTooltip(target, tooltip);

							// Show tooltip with animation
							tooltip.classList.add('visible');

							// Hide after a delay
							setTimeout(() => {
								tooltip.classList.remove('visible');
								setTimeout(() => {
									tooltip.style.display = 'none';
								}, 200);
							}, 3000);
						}, 500); // Show after 500ms press
					}
				});

				document.addEventListener('touchend', function () {
					clearTimeout(touchTimer);
				});

				document.addEventListener('touchmove', function () {
					clearTimeout(touchTimer);
				});

				// Handle window resize - reposition tooltips if visible
				window.addEventListener('resize', function () {
					if (tooltip.classList.contains('visible')) {
						const currentTarget = document.querySelector('.tooltip-trigger:hover');
						if (currentTarget) {
							positionTooltip(currentTarget, tooltip);
						}
					}
				});

				// Apply tooltips to dynamically loaded content
				// This handles gallery items loaded via AJAX
				const observer = new MutationObserver(function (mutations) {
					mutations.forEach(function (mutation) {
						if (mutation.type === 'childList') {
							mutation.addedNodes.forEach(function (node) {
								if (node.nodeType === 1) { // Element node
									const newElements = node.querySelectorAll('[title]');
									if (newElements.length > 0) {
										newElements.forEach(element => {
											const titleText = element.getAttribute('title');
											element.removeAttribute('title');
											element.setAttribute('data-tooltip', titleText);
											element.classList.add('tooltip-trigger');
										});
									}

									// Also check if the node itself has a title
									if (node.hasAttribute && node.hasAttribute('title')) {
										const titleText = node.getAttribute('title');
										node.removeAttribute('title');
										node.setAttribute('data-tooltip', titleText);
										node.classList.add('tooltip-trigger');
									}
								}
							});
						}
					});
				});

				observer.observe(document.body, {
					childList: true,
					subtree: true
				});
			}

			// Initialize tooltips after everything is loaded
			initTooltips();
		});

		// Call initTooltips after AJAX updates
		document.addEventListener('ajaxComplete', function () {
			// If your app has a custom event for AJAX completion, use that instead
			setTimeout(function () {
				const elementsWithTitle = document.querySelectorAll('[title]');
				elementsWithTitle.forEach(element => {
					const titleText = element.getAttribute('title');
					element.removeAttribute('title');
					element.setAttribute('data-tooltip', titleText);
					element.classList.add('tooltip-trigger');
				});
			}, 500);
		});
	</script>

	<script>
		document.addEventListener('DOMContentLoaded', function () {

			// Get the modal elements
			const resetModal = document.getElementById('resetModal');
			const showResetButton = document.getElementById('showResetModal');
			const closeResetButton = resetModal?.querySelector('.modal-close-btn');
			const cancelResetButton = document.getElementById('cancelReset');
			const resetForm = document.getElementById('resetForm');

			// Modal functions
			function showResetModal() {
				resetModal.style.display = 'block';
				resetModal.offsetHeight; // Force reflow
				resetModal.classList.add('show');
			}

			function hideResetModal() {
				resetModal.classList.remove('show');
				setTimeout(() => {
					resetModal.style.display = 'none';
				}, 300);
			}

			// Add event listeners
			if (showResetButton && resetModal) {
				showResetButton.addEventListener('click', showResetModal);
			}

			if (closeResetButton) {
				closeResetButton.addEventListener('click', hideResetModal);
			}

			if (cancelResetButton) {
				cancelResetButton.addEventListener('click', hideResetModal);
			}

			// Close modal when clicking outside
			if (resetModal) {
				resetModal.addEventListener('click', (e) => {
					if (e.target === resetModal) {
						hideResetModal();
					}
				});
			}

			// Handle form submission
			if (resetForm) {
				resetForm.addEventListener('submit', async function (e) {
					e.preventDefault();

					// Show a loading notification
					const notification = showNotification('Resetting Posteria...', 'loading');

					try {
						const formData = new FormData(resetForm);

						// Send to the main PHP file instead of a separate file
						const response = await fetch(window.location.href, {
							method: 'POST',
							body: formData
						});

						const data = await response.json();

						if (data.success) {
							// Show success notification
							notification.remove();
							showNotification('Posteria has been reset successfully!', 'success');

							// Hide modal
							hideResetModal();

							// Reload page after a brief delay
							setTimeout(() => {
								window.location.reload();
							}, 1500);
						} else {
							// Show error notification
							notification.remove();
							showNotification('Error: ' + (data.error || 'Reset failed'), 'error');
						}
					} catch (error) {
						// Show error notification
						notification.remove();
						showNotification('Error: Unable to reset Posteria', 'error');
					}
				});
			}

			// Helper function to show notifications
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
		});
	</script>

	<script>
		document.addEventListener('DOMContentLoaded', function () {
			// First check if it's a touch device - we'll only apply this solution for touch devices
			const isTouchDevice = ('ontouchstart' in window) ||
				(navigator.maxTouchPoints > 0) ||
				(navigator.msMaxTouchPoints > 0);

			// Exit early if this is not a touch device (no need to set up mobile tooltip)
			if (!isTouchDevice) {
				return;
			}

			// Create a mobile tooltip element for captions
			const mobileCaptionTooltip = document.createElement('div');
			mobileCaptionTooltip.className = 'mobile-caption-tooltip';
			mobileCaptionTooltip.style.display = 'none';
			document.body.appendChild(mobileCaptionTooltip);

			// Add CSS for the mobile caption tooltip
			const mobileCaptionStyle = document.createElement('style');
			mobileCaptionStyle.textContent = `
		.mobile-caption-tooltip {
			position: absolute;
			background: var(--bg-tertiary);
			color: var(--text-primary);
			padding: 10px 14px;
			border-radius: 6px;
			font-size: 14px;
			box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
			z-index: 1050;
			text-align: center;
			border: 1px solid var(--border-color);
			opacity: 0;
			transition: opacity 0.2s;
			word-break: break-word;
			line-height: 1.4;
			transform: translateX(-50%);
			left: 50%;
			top: 100%;
			margin-top: 5px; /* Reduced space between caption and tooltip */
			width: calc(100% - 4px); /* Make it width of poster minus a small margin */
		}
		
		/* Arrow for the tooltip */
		.mobile-caption-tooltip:after {
			content: '';
			position: absolute;
			bottom: 100%;
			left: 50%;
			margin-left: -5px;
			width: 0;
			height: 0;
			border-left: 5px solid transparent;
			border-right: 5px solid transparent;
			border-bottom: 5px solid var(--bg-tertiary);
		}
		
		/* Border arrow for the tooltip */
		.mobile-caption-tooltip:before {
			content: '';
			position: absolute;
			bottom: 100%;
			left: 50%;
			margin-left: -6px;
			width: 0;
			height: 0;
			border-left: 6px solid transparent;
			border-right: 6px solid transparent;
			border-bottom: 6px solid var(--border-color);
		}
		
		.mobile-caption-tooltip.visible {
			opacity: 1;
		}
		
		/* Add a class to indicate truncated captions */
		.gallery-caption.is-truncated {
			position: relative;
		}
		
		/* Only style truncated captions on mobile devices */
		@media (hover: none) {
			.gallery-caption.is-truncated:active {
				background-color: rgba(255, 255, 255, 0.1);
				border-radius: 4px;
			}
		}
	`;
			document.head.appendChild(mobileCaptionStyle);

			// Track whether tooltip is showing or timer is active
			let tooltipActive = false;
			let tooltipTimer = null;
			let currentlyTruncatedElement = null;

			// Track touch interactions for better gesture detection
			let touchStartX = 0;
			let touchStartY = 0;
			let touchMoved = false;
			const touchThreshold = 10; // Pixels of movement to consider a scroll vs. tap

			// Function to show the mobile caption tooltip
			function showMobileCaptionTooltip(captionElement) {
				if (!captionElement || !captionElement.classList.contains('is-truncated')) {
					return;
				}

				const fullText = captionElement.getAttribute('data-full-text');
				if (!fullText) {
					return;
				}

				// Store current element
				currentlyTruncatedElement = captionElement;

				// Clean up the text to display
				let textToDisplay = fullText;
				// Remove Plex and Orphaned tags
				textToDisplay = textToDisplay.replace(/\-\-(Plex|Orphaned)\-\-/g, '');
				// Remove both single [ID] and double [[Library]] brackets with their contents
				textToDisplay = textToDisplay.replace(/\[\[.*?\]\]|\[.*?\]/gs, '');
				// Remove timestamp patterns
				textToDisplay = textToDisplay.replace(/\s*\(A\d{8,12}\)\s*/g, '')
				// Trim any resulting extra spaces
				textToDisplay = textToDisplay.trim();

				// Set tooltip content
				mobileCaptionTooltip.textContent = textToDisplay;

				// Find the poster image container (parent of the caption)
				const galleryItem = captionElement.closest('.gallery-item');

				if (!galleryItem) {
					hideMobileCaptionTooltip();
					return;
				}

				const imageContainer = galleryItem.querySelector('.gallery-image-container');

				if (!imageContainer) {
					hideMobileCaptionTooltip();
					return;
				}

				// Position the tooltip directly under the caption
				const captionRect = captionElement.getBoundingClientRect();

				// Adjust the tooltip to match the poster width 
				// (technically the caption width, which is the same as the poster)
				mobileCaptionTooltip.style.width = captionRect.width + 'px';

				// Position it relative to the caption
				mobileCaptionTooltip.style.display = 'block';

				// Append to the document body
				document.body.appendChild(mobileCaptionTooltip);

				// Get the position of the caption relative to the page
				const captionPosition = {
					top: captionRect.top + window.scrollY,
					left: captionRect.left + window.scrollX,
					width: captionRect.width,
					height: captionRect.height
				};

				// Position the tooltip
				mobileCaptionTooltip.style.position = 'absolute';
				mobileCaptionTooltip.style.top = (captionPosition.top + captionPosition.height + 5) + 'px';
				mobileCaptionTooltip.style.left = (captionPosition.left + captionPosition.width / 2) + 'px';

				// Force reflow
				mobileCaptionTooltip.offsetHeight;

				// Add visible class for animation
				mobileCaptionTooltip.classList.add('visible');

				// Set timeout to hide tooltip after 3 seconds
				if (tooltipTimer) {
					clearTimeout(tooltipTimer);
				}

				tooltipActive = true;
				tooltipTimer = setTimeout(() => {
					hideMobileCaptionTooltip();
				}, 3000);
			}

			// Function to hide the mobile caption tooltip
			function hideMobileCaptionTooltip() {
				mobileCaptionTooltip.classList.remove('visible');
				tooltipActive = false;
				currentlyTruncatedElement = null;

				// Wait for animation to complete before hiding
				setTimeout(() => {
					if (!tooltipActive) {
						mobileCaptionTooltip.style.display = 'none';
					}
				}, 300);
			}

			// Function to determine if text is tru			ncated
			function isTextTruncated(element) {
				// Check if the element has overflow or has ellipsis
				if (!element) return false;

				// First check for the most common case - an ellipsis at the end
				if (element.textContent.trim().endsWith('...')) {
					return true;
				}

				// Check if the displayed text is shorter than the full text
				const fullText = element.getAttribute('data-full-text');
				if (!fullText) return false;

				// Clean up the full text for comparison (same as in the showTooltip function)
				let cleanFullText = fullText;
				// Remove Plex and Orphaned tags
				cleanFullText = cleanFullText.replace(/\-\-(Plex|Orphaned)\-\-/g, '');
				// Remove both single [ID] and double [[Library]] brackets with their contents
				cleanFullText = cleanFullText.replace(/\[\[[^\]]*\]\]|\[[^\]]*\]/g, '');
				// Remove timestamp patterns
				cleanFullText = cleanFullText.replace(/\s*\(A\d{8,12}\)\s*/g, '')
				// Remove [Orphaned] text that might be added at the end
				cleanFullText = cleanFullText.replace(/\s*\[Orphaned\]$/, '');
				// Trim any resulting extra spaces
				cleanFullText = cleanFullText.trim();

				// Check if the displayed text is shorter than the cleaned full text
				// (accounting for added [Orphaned] text that might be in displayed text)
				const displayText = element.textContent.trim().replace(/\s*\[Orphaned\]$/, '');

				// If the display text is significantly shorter than the full text, it's truncated
				return displayText.length < cleanFullText.length - 3; // Allow for small differences
			}

			// Function to mark truncated captions
			function markTruncatedCaptions() {
				const captions = document.querySelectorAll('.gallery-caption');

				captions.forEach(caption => {
					if (isTextTruncated(caption)) {
						caption.classList.add('is-truncated');
					} else {
						caption.classList.remove('is-truncated');
					}
				});
			}

			// Handle tooltips immediately on touchstart but track for scrolling
			document.addEventListener('touchstart', function (e) {
				// Store the touch start position for later movement calculation
				touchStartX = e.touches[0].clientX;
				touchStartY = e.touches[0].clientY;
				touchMoved = false;

				// Check if we're touching a truncated caption
				const caption = e.target.closest('.gallery-caption.is-truncated');

				// If we touched a truncated caption, show tooltip immediately
				if (caption && caption !== currentlyTruncatedElement) {
					// Only show the tooltip if the caption is actually truncated
					if (isTextTruncated(caption)) {
						// Show the tooltip right away on first touch
						showMobileCaptionTooltip(caption);
					}
				}

				// DO NOT call preventDefault() here - that would block scrolling
			}, { passive: true }); // Keep passive: true to improve scrolling performance

			// Track touchmove to detect scrolling intention
			document.addEventListener('touchmove', function (e) {
				if (!e.touches[0]) return;

				const touchX = e.touches[0].clientX;
				const touchY = e.touches[0].clientY;

				// Calculate distance moved
				const distX = Math.abs(touchX - touchStartX);
				const distY = Math.abs(touchY - touchStartY);

				// If moved more than threshold, mark as a scroll gesture
				if (distX > touchThreshold || distY > touchThreshold) {
					touchMoved = true;

					// If tooltip is showing, hide it during scroll
					if (tooltipActive) {
						hideMobileCaptionTooltip();
					}
				}
			}, { passive: true });

			// Use touchend to handle caption taps for anywhere else
			document.addEventListener('touchend', function (e) {
				// Skip if this was a scroll gesture
				if (touchMoved) return;

				// Check if we tapped somewhere other than a caption or tooltip
				if (!e.target.closest('.gallery-caption.is-truncated') &&
					!e.target.closest('.mobile-caption-tooltip')) {
					// If tapped anywhere else, hide tooltip
					hideMobileCaptionTooltip();
				}
			}, { passive: true });

			// Hide tooltip when scrolling
			let scrollTimer = null;
			window.addEventListener('scroll', function () {
				if (tooltipActive) {
					hideMobileCaptionTooltip();
				}

				// Also refresh the truncated caption markers when scrolling stops
				clearTimeout(scrollTimer);
				scrollTimer = setTimeout(() => {
					markTruncatedCaptions();
				}, 200);
			}, { passive: true });

			// Mark truncated captions on load
			markTruncatedCaptions();

			// Run markTruncatedCaptions after images load, which might change layout
			document.querySelectorAll('.gallery-image').forEach(img => {
				img.addEventListener('load', function () {
					setTimeout(markTruncatedCaptions, 100);
				});
			});

			// Re-check on window resize
			let resizeTimer = null;
			window.addEventListener('resize', function () {
				clearTimeout(resizeTimer);
				resizeTimer = setTimeout(() => {
					markTruncatedCaptions();
				}, 200);
			});

			// Observer for dynamic content changes
			const observer = new MutationObserver(function (mutations) {
				let shouldMarkCaptions = false;

				mutations.forEach(function (mutation) {
					if (mutation.type === 'childList' ||
						(mutation.type === 'attributes' &&
							mutation.attributeName === 'class' &&
							mutation.target.classList.contains('gallery-caption'))) {
						shouldMarkCaptions = true;
					}
				});

				if (shouldMarkCaptions) {
					setTimeout(markTruncatedCaptions, 100);
				}
			});

			observer.observe(document.body, {
				childList: true,
				subtree: true,
				attributes: true,
				attributeFilter: ['class']
			});
		});
	</script>

	<script>
		document.addEventListener("DOMContentLoaded", function () {
			const lazyImages = document.querySelectorAll("img.lazy");

			const lazyLoad = function () {
				lazyImages.forEach(img => {
					if (img.dataset.src && img.getBoundingClientRect().top < window.innerHeight + 200) {
						img.src = encodeURI(img.dataset.src).replace(/#/g, '%23');
						img.classList.remove("lazy");
					}
				});
			};

			window.addEventListener("scroll", lazyLoad);
			window.addEventListener("resize", lazyLoad);
			window.addEventListener("orientationchange", lazyLoad);

			// Optional: initial load
			lazyLoad();
		});

	</script>
	<script>
		document.addEventListener('DOMContentLoaded', function () {

			// Check current sorting state
			const urlParams = new URLSearchParams(window.location.search);

			const phpSortByDateAdded = <?= isset($display_config['sort_by_date_added']) && $display_config['sort_by_date_added'] === true ? 'true' : 'false' ?>;

			let isSortByDate = false;

			if (urlParams.has('sort_by_date')) {
				isSortByDate = urlParams.get('sort_by_date') === 'true';
			} else {
				isSortByDate = phpSortByDateAdded;
			};

			// Add active class if sorting by date
			if (isSortByDate) {
				sortByDateButton.classList.add('active');
			};

			sortByDateButton.addEventListener('click', function () {
				const url = new URL(window.location.href);
				const params = new URLSearchParams(url.search);

				// Toggle the parameter to the opposite of current state
				params.set('sort_by_date', (!isSortByDate).toString());

				// Update URL and reload page
				url.search = params.toString();
				window.location.href = url.toString();
			});
		});
	</script>

	<script>
		document.addEventListener('DOMContentLoaded', function () {
			// Create the full-screen modal for viewing posters if it doesn't exist yet
			function createFullScreenModal() {
				if (document.getElementById('fullScreenModal')) return;

				const modal = document.createElement('div');
				modal.id = 'fullScreenModal';
				modal.className = 'fullscreen-modal';
				modal.innerHTML = `
			<div class="fullscreen-content">
				<img id="fullScreenImage" src="" alt="Full Screen Poster">
			</div>
			<button class="fullscreen-close">×</button>
		`;

				// Add CSS for the full-screen modal to match the URL preview style
				const style = document.createElement('style');
				style.textContent = `
			.fullscreen-modal {
				display: none;
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				background: rgba(0, 0, 0, 0.85);
				z-index: 2000;
				opacity: 0;
				transition: opacity 0.3s ease;
				justify-content: center;
				align-items: center;
			}
			
			.fullscreen-modal.show {
				opacity: 1;
			}
			
			.fullscreen-content {
				position: relative;
				max-width: 80%;
				max-height: 80%;
				border-radius: 8px;
				overflow: hidden;
				box-shadow: 0 8px 16px rgba(0, 0, 0, 0.5);
			}
			
			#fullScreenImage {
				display: block;
				max-width: 100%;
				max-height: 80vh;
				margin: 0 auto;
			}
			
			.fullscreen-close {
				position: absolute;
				top: 20px;
				right: 20px;
				background: var(--bg-secondary);
				color: var(--text-primary);
				width: 40px;
				height: 40px;
				border-radius: 50%;
				display: flex;
				align-items: center;
				justify-content: center;
				cursor: pointer;
				border: none;
				font-size: 24px;
				box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
				transition: all 0.2s;
				z-index: 2001;
			}
			
			.fullscreen-close:hover {
				background: var(--bg-tertiary);
				transform: scale(1.1);
			}
			
			/* Animation for loading image */
			@keyframes spin {
				0% { transform: rotate(0deg); }
				100% { transform: rotate(360deg); }
			}
			
			/* Loading spinner */
			.fullscreen-loading {
				position: absolute;
				top: 50%;
				left: 50%;
				transform: translate(-50%, -50%);
				width: 50px;
				height: 50px;
				border: 4px solid rgba(255, 255, 255, 0.1);
				border-radius: 50%;
				border-top-color: var(--accent-primary);
				animation: spin 1s infinite linear;
			}
			
			/* Responsive adjustments */
			@media (max-width: 768px) {
				.fullscreen-content {
					max-width: 90%;
				}
				
				.fullscreen-close {
					top: 15px;
					right: 15px;
					width: 36px;
					height: 36px;
					font-size: 22px;
				}
			}
		`;

				document.head.appendChild(style);
				document.body.appendChild(modal);

				// Add event listeners for the modal
				const closeBtn = modal.querySelector('.fullscreen-close');
				closeBtn.addEventListener('click', hideFullScreenModal);

				// Close on background click
				modal.addEventListener('click', function (e) {
					if (e.target === modal) {
						hideFullScreenModal();
					}
				});

				// Close on ESC key press
				document.addEventListener('keydown', function (e) {
					if (e.key === 'Escape' && modal.classList.contains('show')) {
						hideFullScreenModal();
					}
				});
			}

			// Function to show the full-screen modal
			function showFullScreenModal(imageSrc) {
				const modal = document.getElementById('fullScreenModal');
				const img = document.getElementById('fullScreenImage');

				if (!modal || !img) {
					createFullScreenModal();
				}

				// Create loading indicator
				let loadingSpinner = modal.querySelector('.fullscreen-loading');
				if (!loadingSpinner) {
					loadingSpinner = document.createElement('div');
					loadingSpinner.className = 'fullscreen-loading';
					modal.querySelector('.fullscreen-content').appendChild(loadingSpinner);
				}

				// Make loading spinner visible
				loadingSpinner.style.display = 'block';

				// Hide the image until it's loaded
				img.style.opacity = '0';

				// Set the image source
				img.src = imageSrc;

				// When image is loaded, show it and hide the spinner
				img.onload = function () {
					img.style.opacity = '1';
					loadingSpinner.style.display = 'none';
				};

				// Display the modal
				modal.style.display = 'flex';
				modal.offsetHeight; // Force reflow
				modal.classList.add('show');
			}

			// Function to hide the full-screen modal
			function hideFullScreenModal() {
				const modal = document.getElementById('fullScreenModal');
				if (!modal) return;

				modal.classList.remove('show');
				setTimeout(() => {
					modal.style.display = 'none';
				}, 300); // Match transition duration
			}

			// Add the full-screen button to all gallery items
			function addFullScreenButtons() {
				// Create the full-screen modal
				createFullScreenModal();

				// Find all gallery items
				const galleryItems = document.querySelectorAll('.gallery-item');

				galleryItems.forEach(item => {
					const overlayActions = item.querySelector('.image-overlay-actions');
					const imageElement = item.querySelector('.gallery-image');

					if (!overlayActions || !imageElement) return;

					// Check if button already exists to avoid duplicates
					if (overlayActions.querySelector('.fullscreen-view-btn')) return;

					// Get the image source
					const imageSrc = imageElement.getAttribute('data-src');
					if (!imageSrc) return;

					// Create the full-screen button
					const fullScreenBtn = document.createElement('button');
					fullScreenBtn.className = 'overlay-action-button fullscreen-view-btn';
					fullScreenBtn.innerHTML = `
				<svg class="image-action-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path>
				</svg>
				Full Screen
			`;

					// Add click event to open full-screen view
					fullScreenBtn.addEventListener('click', function (e) {
						e.preventDefault();
						e.stopPropagation();

						// Clean the URL to ensure proper loading
						const cleanImageUrl = imageSrc.split('?')[0] + '?v=' + new Date().getTime();
						showFullScreenModal(cleanImageUrl);
					});

					// Insert the button ABOVE the "Copy URL" button
					const copyUrlBtn = overlayActions.querySelector('.copy-url-btn');
					if (copyUrlBtn) {
						copyUrlBtn.parentNode.insertBefore(fullScreenBtn, copyUrlBtn);
					} else {
						// Fallback: add to the beginning
						overlayActions.prepend(fullScreenBtn);
					}
				});
			}

			// Initialize
			addFullScreenButtons();

			// Re-add buttons after AJAX updates or dynamic content changes
			// Set up a mutation observer to detect when new gallery items are added
			const observer = new MutationObserver(function (mutations) {
				let shouldAddButtons = false;

				mutations.forEach(function (mutation) {
					if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
						for (let i = 0; i < mutation.addedNodes.length; i++) {
							const node = mutation.addedNodes[i];
							// Check if it's a gallery item or contains gallery items
							if (node.classList && node.classList.contains('gallery-item') ||
								(node.querySelectorAll && node.querySelectorAll('.gallery-item').length > 0)) {
								shouldAddButtons = true;
								break;
							}
						}
					}
				});

				if (shouldAddButtons) {
					setTimeout(addFullScreenButtons, 100);
				}
			});

			// Start observing the document body
			observer.observe(document.body, {
				childList: true,
				subtree: true
			});

			// Also call when images finish loading to ensure all buttons are added
			window.addEventListener('load', function () {
				setTimeout(addFullScreenButtons, 500);
			});
		});
	</script>
</body>

</html>