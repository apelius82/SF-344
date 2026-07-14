<?php
/**
 * Image Helper Functions
 * 
 * Provides helper functions for image processing, specifically for thumbnail generation.
 * Used by the additional images feature to create thumbnail versions of uploaded images.
 * 
 * @package SafetyFlash
 * @subpackage Utilities
 */

declare(strict_types=1);

require_once __DIR__ . '/image_utils.php';

/**
 * Generate a thumbnail version of an image
 * 
 * Creates a smaller version of the image with a "thumb_" prefix.
 * Uses the existing sf_resize_image function for consistent image processing.
 * 
 * @param string $sourcePath Full path to the source image
 * @param int $maxWidth Maximum width for thumbnail (default: 300)
 * @param int $maxHeight Maximum height for thumbnail (default: 300)
 * @param int $quality JPEG/WEBP quality 0-100 (default: 75)
 * @return string|false Returns the thumbnail filename on success, false on failure
 */
function sf_generate_thumbnail(
    string $sourcePath,
    int $maxWidth = 300,
    int $maxHeight = 300,
    int $quality = 75
): string|false {
    if (!file_exists($sourcePath)) {
        error_log('sf_generate_thumbnail: Source file does not exist: ' . $sourcePath);
        return false;
    }
    
    // Create thumbnail filename with thumb_ prefix
    $dir = dirname($sourcePath);
    $filename = basename($sourcePath);
    $thumbFilename = 'thumb_' . $filename;
    $thumbPath = $dir . '/' . $thumbFilename;
    
    // Generate thumbnail using existing resize function
    $success = sf_resize_image($sourcePath, $thumbPath, $maxWidth, $maxHeight, $quality);
    
    if (!$success) {
        error_log('sf_generate_thumbnail: Failed to generate thumbnail for: ' . $sourcePath);
        return false;
    }
    
    return $thumbFilename;
}

/**
 * Delete an image and its thumbnail if it exists
 * 
 * @param string $imagePath Full path to the image file
 * @return bool True if at least the main image was deleted, false otherwise
 */
function sf_delete_image_with_thumbnail(string $imagePath): bool {
    $deleted = false;
    
    // Delete main image
    if (file_exists($imagePath) && is_file($imagePath)) {
        $deleted = @unlink($imagePath);
        if (!$deleted) {
            error_log('sf_delete_image_with_thumbnail: Failed to delete main image: ' . $imagePath);
        }
    }
    
    // Delete thumbnail if it exists
    $dir = dirname($imagePath);
    $filename = basename($imagePath);
    $thumbPath = $dir . '/thumb_' . $filename;
    
    if (file_exists($thumbPath) && is_file($thumbPath)) {
        $thumbDeleted = @unlink($thumbPath);
        if (!$thumbDeleted) {
            error_log('sf_delete_image_with_thumbnail: Failed to delete thumbnail: ' . $thumbPath);
        }
    }
    
    return $deleted;
}

/**
 * Save grid bitmap base64 data URL to file
 * 
 * Converts base64 data URLs from frontend JavaScript canvas.toDataURL() into
 * actual image files stored in uploads/grids/ directory. Maintains backward
 * compatibility with existing filenames.
 * 
 * @param string $gridBitmap Base64 data URL or existing filename
 * @param int $flashId Flash ID for unique filename
 * @return string Filename (if saved) or original value (if already filename/empty)
 */
function sf_save_grid_bitmap_to_file(string $gridBitmap, int $flashId): string
{
    $gridBitmap = trim($gridBitmap);
    
    // If empty or already a filename (not base64), return as-is
    if ($gridBitmap === '' || strpos($gridBitmap, 'data:image/') !== 0) {
        return $gridBitmap;
    }
    
    // Create uploads/grids directory if needed
    $gridsDir = __DIR__ . '/../../uploads/grids/';
    if (!is_dir($gridsDir)) {
        if (!mkdir($gridsDir, 0755, true)) {
            error_log("sf_save_grid_bitmap_to_file: Failed to create directory: {$gridsDir}");
            // Return empty string to avoid storing base64 in database
            return '';
        }
    }
    
    try {
        // Parse data URL: data:image/png;base64,XXXXX
        $parts = explode(',', $gridBitmap, 2);
        if (count($parts) !== 2) {
            error_log("sf_save_grid_bitmap_to_file: Invalid data URL format");
            return '';
        }
        
        // Decode base64
        $imageData = base64_decode($parts[1]);
        if ($imageData === false) {
            error_log("sf_save_grid_bitmap_to_file: Base64 decode failed");
            return '';
        }
        
        // Determine extension from MIME type with whitelist validation
        $ext = 'png'; // default
        if (preg_match('/data:image\/(\w+);/', $parts[0], $matches)) {
            $ext = strtolower($matches[1]);
            
            // Normalize jpeg to jpg for consistency before whitelist check
            if ($ext === 'jpeg') $ext = 'jpg';
            
            // Whitelist of allowed image extensions
            $allowedExtensions = ['png', 'jpg', 'gif', 'webp'];
            if (!in_array($ext, $allowedExtensions, true)) {
                error_log("sf_save_grid_bitmap_to_file: Unsupported image extension: {$ext}");
                return '';
            }
        }
        
        // Generate unique filename
        $filename = 'grid_' . $flashId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $filepath = $gridsDir . $filename;
        
        // Write file
        if (file_put_contents($filepath, $imageData) === false) {
            error_log("sf_save_grid_bitmap_to_file: Failed to write file: {$filepath}");
            return '';
        }
        
        if (!chmod($filepath, 0644)) {
            error_log("sf_save_grid_bitmap_to_file: Warning - failed to set permissions on: {$filepath}");
        }
        
        error_log("sf_save_grid_bitmap_to_file: Saved grid bitmap for flash {$flashId}: {$filename}");
        
        return $filename;
        
    } catch (Throwable $e) {
        error_log("sf_save_grid_bitmap_to_file: Error: " . $e->getMessage());
        return '';
    }
}