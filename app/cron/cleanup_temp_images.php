<?php
// app/cron/cleanup_temp_images.php
// Ajetaan cronilla: 0 * * * * php /path/to/cleanup_temp_images.php
// Poistaa vanhat temp-kuvat (yli 24h)

declare(strict_types=1);

// Configuration
const MAX_AGE_HOURS = 24;

$tempDir = __DIR__ . '/../../uploads/temp/';
$maxAge = MAX_AGE_HOURS * 60 * 60; // Convert hours to seconds

if (!is_dir($tempDir)) {
    echo "Temp directory not found: {$tempDir}\n";
    exit(1);
}

$deleted = 0;
$errors = 0;
$skipped = 0;

foreach (glob($tempDir . 'temp_*') as $file) {
    if (!is_file($file)) {
        continue;
    }
    
    $fileTime = filemtime($file);
    if ($fileTime === false) {
        $skipped++;
        error_log("cleanup_temp_images: Unable to get modification time for: " . basename($file));
        continue;
    }
    
    if ($fileTime < time() - $maxAge) {
        if (@unlink($file)) {
            $deleted++;
            echo "Deleted: " . basename($file) . "\n";
        } else {
            $errors++;
            error_log("cleanup_temp_images: Failed to delete: " . basename($file));
            echo "Failed to delete: " . basename($file) . "\n";
        }
    }
}

echo "\nCleanup complete: {$deleted} files deleted, {$errors} errors, {$skipped} skipped\n";
exit(0);