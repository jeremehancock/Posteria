<?php
// Debug script to check EXCLUDED_LIBRARIES configuration
require_once 'include/config.php';

echo "=== EXCLUDED_LIBRARIES Debug ===\n";
echo "Raw env value: " . var_export(getenv('EXCLUDED_LIBRARIES'), true) . "\n";
echo "Parsed array: " . var_export($auto_import_config['excluded_libraries'], true) . "\n";
echo "Count: " . count($auto_import_config['excluded_libraries']) . "\n";

if (!empty($auto_import_config['excluded_libraries'])) {
    echo "\nLibraries that will be excluded:\n";
    foreach ($auto_import_config['excluded_libraries'] as $index => $lib) {
        echo "  [$index] = '" . $lib . "' (length: " . strlen($lib) . ")\n";
    }
}
?>
