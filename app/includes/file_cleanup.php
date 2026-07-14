<?php
// app/includes/file_cleanup.php
declare(strict_types=1);

/**
 * File cleanup utilities for SafetyFlash images
 * Handles deletion of uploaded images, previews, and other files
 */

/**
 * Delete all files associated with a SafetyFlash
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId SafetyFlash ID
 * @return void
 */
function sf_cleanup_flash_files(PDO $pdo, int $flashId): void
{
    // Get flash data
    $stmt = $pdo->prepare("SELECT image_main, image_2, image_3, preview_filename, preview_filename_2, grid_bitmap FROM sf_flashes WHERE id = ?");
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$flash) return;
    
    $baseDir = __DIR__ . '/../../uploads/';
    
    // Delete uploaded images
    $images = ['image_main', 'image_2', 'image_3'];
    foreach ($images as $field) {
        if (!empty($flash[$field])) {
            $path = $baseDir . 'images/' . $flash[$field];
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }
    
    // Delete preview images
    $previews = ['preview_filename', 'preview_filename_2'];
    foreach ($previews as $field) {
        if (!empty($flash[$field])) {
            $path = $baseDir . 'previews/' . $flash[$field];
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }
    
    // Delete grid bitmap
    if (!empty($flash['grid_bitmap'])) {
        // Try grid_bitmaps subdirectory first
        $path = $baseDir . 'grid_bitmaps/' . $flash['grid_bitmap'];
        if (file_exists($path)) {
            @unlink($path);
        }
        // Also check uploads root directory
        $path = $baseDir . $flash['grid_bitmap'];
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}

/**
 * Cleanup original images after publishing (keep only preview)
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId SafetyFlash ID
 * @return void
 */
function sf_cleanup_after_publish(PDO $pdo, int $flashId): void
{
    $stmt = $pdo->prepare("SELECT image_main, image_2, image_3 FROM sf_flashes WHERE id = ?");
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$flash) return;
    
    $imagesDir = __DIR__ . '/../../uploads/images/';
    
    $images = ['image_main', 'image_2', 'image_3'];
    foreach ($images as $field) {
        if (!empty($flash[$field])) {
            $path = $imagesDir . $flash[$field];
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }
    
    // Clear image references in database (keep preview)
    $stmt = $pdo->prepare("UPDATE sf_flashes SET image_main = NULL, image_2 = NULL, image_3 = NULL WHERE id = ?");
    $stmt->execute([$flashId]);
}