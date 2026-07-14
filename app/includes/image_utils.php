<?php
/**
 * Image Processing Utilities
 * 
 * Provides centralized image resizing and compression functionality
 * to optimize uploaded images for web display and storage efficiency.
 * 
 * @package SafetyFlash
 * @subpackage Utilities
 */

declare(strict_types=1);

/**
 * Resize and compress an image while maintaining aspect ratio
 * 
 * This function uses PHP GD library to:
 * - Load images in JPEG, PNG, WEBP, and GIF formats
 * - Respect EXIF orientation data (auto-rotate)
 * - Resize to fit within specified dimensions while maintaining aspect ratio
 * - Compress with specified quality setting
 * 
 * @param string $source Path to source image file
 * @param string $destination Path where processed image will be saved
 * @param int $maxWidth Maximum width in pixels (default: 1920)
 * @param int $maxHeight Maximum height in pixels (default: 1920)
 * @param int $quality JPEG/WEBP quality 0-100 (default: 80)
 * @return bool True on success, false on failure
 */
function sf_resize_image(
    string $source,
    string $destination,
    int $maxWidth = 1920,
    int $maxHeight = 1920,
    int $quality = 80
): bool {
    // Validate that GD extension is loaded
    if (!extension_loaded('gd')) {
        error_log('sf_resize_image: GD extension not loaded');
        return false;
    }

    // Validate source file exists
    if (!file_exists($source)) {
        error_log("sf_resize_image: Source file does not exist: $source");
        return false;
    }

    // Detect MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        error_log('sf_resize_image: Failed to initialize finfo');
        return false;
    }

    $mimeType = finfo_file($finfo, $source);
    finfo_close($finfo);

    if ($mimeType === false) {
        error_log("sf_resize_image: Failed to detect MIME type for: $source");
        return false;
    }

    // Load image based on type
    $sourceImage = null;
    switch ($mimeType) {
        case 'image/jpeg':
            $sourceImage = @imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $sourceImage = @imagecreatefrompng($source);
            break;
        case 'image/gif':
            $sourceImage = @imagecreatefromgif($source);
            break;
        case 'image/webp':
            $sourceImage = @imagecreatefromwebp($source);
            break;
        default:
            error_log("sf_resize_image: Unsupported MIME type: $mimeType");
            return false;
    }

    if ($sourceImage === false || $sourceImage === null) {
        error_log("sf_resize_image: Failed to load image from: $source");
        return false;
    }

    // Get original dimensions
    $origWidth = imagesx($sourceImage);
    $origHeight = imagesy($sourceImage);

    if ($origWidth === false || $origHeight === false) {
        imagedestroy($sourceImage);
        error_log("sf_resize_image: Failed to get image dimensions");
        return false;
    }

    // Handle EXIF orientation for JPEG images
    if ($mimeType === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($source);
        if ($exif !== false && isset($exif['Orientation'])) {
            $orientation = (int)$exif['Orientation'];
            
            // Rotate image based on orientation
            $rotated = null;
            switch ($orientation) {
                case 3:
                    $rotated = imagerotate($sourceImage, 180, 0);
                    break;
                case 6:
                    $rotated = imagerotate($sourceImage, -90, 0);
                    if ($rotated !== false) {
                        // Swap dimensions after rotation
                        $temp = $origWidth;
                        $origWidth = $origHeight;
                        $origHeight = $temp;
                    }
                    break;
                case 8:
                    $rotated = imagerotate($sourceImage, 90, 0);
                    if ($rotated !== false) {
                        // Swap dimensions after rotation
                        $temp = $origWidth;
                        $origWidth = $origHeight;
                        $origHeight = $temp;
                    }
                    break;
            }
            
            // Only update source image if rotation succeeded
            if ($rotated !== false && $rotated !== null) {
                imagedestroy($sourceImage);
                $sourceImage = $rotated;
            }
        }
    }

    // Calculate new dimensions while maintaining aspect ratio
    $newWidth = $origWidth;
    $newHeight = $origHeight;

    if ($origWidth > $maxWidth || $origHeight > $maxHeight) {
        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
        $newWidth = (int)round($origWidth * $ratio);
        $newHeight = (int)round($origHeight * $ratio);
    }

    // Create new image
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    
    if ($newImage === false) {
        imagedestroy($sourceImage);
        error_log("sf_resize_image: Failed to create new image");
        return false;
    }

    // Preserve transparency for PNG and GIF
    if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        
        $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
        }
    }

    // Resample image
    $result = imagecopyresampled(
        $newImage,
        $sourceImage,
        0, 0, 0, 0,
        $newWidth,
        $newHeight,
        $origWidth,
        $origHeight
    );

    if ($result === false) {
        imagedestroy($sourceImage);
        imagedestroy($newImage);
        error_log("sf_resize_image: Failed to resample image");
        return false;
    }

    // Save image based on target extension or original type
    $ext = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
    $success = false;

    // Determine output format from extension, fall back to original MIME type
    $outputMimeType = match($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => $mimeType
    };

    switch ($outputMimeType) {
        case 'image/jpeg':
            $success = imagejpeg($newImage, $destination, $quality);
            break;
        case 'image/png':
            // PNG compression level: 0 (no compression) to 9 (max compression)
            // Convert quality (0-100) to compression level (9-0)
            // Clamp quality to 0-100 range and result to 0-9 range
            $clampedQuality = max(0, min(100, $quality));
            $pngCompression = (int)round((100 - $clampedQuality) * 9 / 100);
            $pngCompression = max(0, min(9, $pngCompression));
            $success = imagepng($newImage, $destination, $pngCompression);
            break;
        case 'image/gif':
            $success = imagegif($newImage, $destination);
            break;
        case 'image/webp':
            $success = imagewebp($newImage, $destination, $quality);
            break;
        default:
            error_log("sf_resize_image: Unsupported output format: $outputMimeType");
            break;
    }

    // Clean up
    imagedestroy($sourceImage);
    imagedestroy($newImage);

    if (!$success) {
        error_log("sf_resize_image: Failed to save processed image to: $destination");
        return false;
    }

    return true;
}