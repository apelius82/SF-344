<?php
// app/api/process_flash_worker.php
declare(strict_types=1);

// This worker is primarily designed to be run from CLI.
// On shared hosting (where shell_exec is disabled) we also allow it to be
// executed inline from save_flash.php by defining SF_ALLOW_WEB_WORKER.
if (php_sapi_name() !== 'cli' && !defined('SF_ALLOW_WEB_WORKER')) {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

set_time_limit(300);

require_once __DIR__ . '/../../config.php';
// Database class is loaded via config.php (assets/lib/Database.php)
require_once __DIR__ . '/../includes/log_app.php';

// =========================================================================
// KAIKKI KUVANKÄSITTELYFUNKTIOT ALKUPERÄISESTÄ SAVE_FLASH.PHP:STÄ TÄSSÄ
// =========================================================================

// BUG FIX 3: Constants for filename generation
if (!defined('SF_ALLOWED_LANGUAGES')) {
    define('SF_ALLOWED_LANGUAGES', ['fi', 'sv', 'en', 'it', 'el']);
}
if (!defined('SF_MAX_SITE_NAME_LENGTH')) {
    define('SF_MAX_SITE_NAME_LENGTH', 30);
}
if (!defined('SF_MAX_TITLE_LENGTH')) {
    define('SF_MAX_TITLE_LENGTH', 50);
}
if (!defined('SF_FILENAME_SANITIZE_PATTERN')) {
    define('SF_FILENAME_SANITIZE_PATTERN', '/[^a-zA-Z0-9\-_]/'); // Keep only alphanumeric, hyphens, underscores
}
if (!defined('SF_DEFAULT_FLASH_TYPE')) {
    define('SF_DEFAULT_FLASH_TYPE', 'yellow');
}

// Content length thresholds for determining if green flash needs two slides
if (!defined('SF_GREEN_MAX_TOTAL_CONTENT_LENGTH')) {
    define('SF_GREEN_MAX_TOTAL_CONTENT_LENGTH', 900);
}
if (!defined('SF_GREEN_MAX_ROOT_CAUSES_LENGTH')) {
    define('SF_GREEN_MAX_ROOT_CAUSES_LENGTH', 500);
}
if (!defined('SF_GREEN_MAX_ACTIONS_LENGTH')) {
    define('SF_GREEN_MAX_ACTIONS_LENGTH', 500);
}
if (!defined('SF_GREEN_MAX_DESCRIPTION_LENGTH')) {
    define('SF_GREEN_MAX_DESCRIPTION_LENGTH', 400);
}
if (!defined('SF_GREEN_MAX_ROOT_CAUSES_ACTIONS_COMBINED_LENGTH')) {
    define('SF_GREEN_MAX_ROOT_CAUSES_ACTIONS_COMBINED_LENGTH', 800);
}

// Line-based calculation constants for better accuracy
if (!defined('SF_GREEN_MAX_COLUMN_LINES')) {
    define('SF_GREEN_MAX_COLUMN_LINES', 14);  // Max lines that fit in a column on single-slide layout
}
if (!defined('SF_GREEN_CHARS_PER_LINE')) {
    define('SF_GREEN_CHARS_PER_LINE', 45);    // Average characters per line
}
if (!defined('SF_FONT_SIZE_OVERRIDE_MIN')) {
    define('SF_FONT_SIZE_OVERRIDE_MIN', 14);
}
if (!defined('SF_FONT_SIZE_OVERRIDE_MAX')) {
    define('SF_FONT_SIZE_OVERRIDE_MAX', 24);
}
if (!defined('SF_FONT_SIZE_REFERENCE')) {
    define('SF_FONT_SIZE_REFERENCE', 20.0);   // Legacy L preset base size for multiplier 1.0
}

/**
 * Estimate the number of lines needed to display text
 * Takes into account line breaks (bullets) and text wrapping
 * @param string $text Text to estimate
 * @param int $charsPerLine Average characters per line (default: 45)
 * @return int Estimated number of lines
 */
if (!function_exists('sf_estimate_lines')) {
    function sf_estimate_lines($text, $charsPerLine = SF_GREEN_CHARS_PER_LINE) {
        if (empty($text)) {
            return 0;
        }
        
        $lines = 0;
        $paragraphs = explode("\n", $text);
        
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            
            // Each paragraph/bullet point is at least 1 line
            // Additional lines based on character count
            $lines += max(1, (int)ceil(mb_strlen($p) / $charsPerLine));
        }
        
        return $lines;
    }
}

/**
 * Get font size multiplier based on font_size_override
 * Same multipliers as frontend (preview-server.js)
 * @param mixed $fontSizeOverride Font size override value (numeric, S/M/L/XL, auto, or null)
 * @return float Multiplier to apply to character limits
 */
if (!function_exists('sf_get_font_size_multiplier')) {
    function sf_get_font_size_multiplier($fontSizeOverride): float {
        $legacyMultipliers = [
            'S' => 1.4,   // Small font = 140% of base limit (40% more text fits)
            'M' => 1.2,   // Medium font = 120% of base limit (20% more text fits)
            'L' => 1.0,   // Large font = 100% of base limit
            'XL' => 0.85, // XL font = 85% of base limit (15% less text fits)
        ];

        if (is_string($fontSizeOverride)) {
            $normalized = strtoupper(trim($fontSizeOverride));
            if (isset($legacyMultipliers[$normalized])) {
                return $legacyMultipliers[$normalized];
            }
        }

        if (is_numeric($fontSizeOverride)) {
            $size = max(SF_FONT_SIZE_OVERRIDE_MIN, min(SF_FONT_SIZE_OVERRIDE_MAX, (int) $fontSizeOverride));
            if ($size <= 0) {
                return 1.0;
            }
            return SF_FONT_SIZE_REFERENCE / $size;
        }

        return 1.0; // auto/null
    }
}

/**
 * BUG FIX 3: Sanitize string for use in filename
 * Removes special characters and limits length
 * @param string $text Text to sanitize
 * @param int $maxLength Maximum length
 * @param string $fallback Fallback value if result is empty
 * @return string Sanitized string
 */
if (!function_exists('sf_sanitize_for_filename')) {
    function sf_sanitize_for_filename($text, $maxLength, $fallback) {
        // Remove all characters not matching the safe pattern
        $sanitized = preg_replace(SF_FILENAME_SANITIZE_PATTERN, '', $text);
        $sanitized = substr($sanitized, 0, $maxLength);
        // Use strict check to preserve '0' strings while catching truly empty strings
        return (trim($sanitized) === '') ? $fallback : $sanitized;
    }
}

/**
 * BUG FIX 3: Create descriptive filename in format SF_YYYY_MM_DD_TYPE_Site-Title-LANG.jpg
 * For green type with multiple cards, adds card number suffix: -1, -2
 * @param string $site Site name
 * @param string $title Flash title
 * @param string $lang Language code
 * @param string $type Flash type (yellow/green)
 * @param string $occurredAt Occurred date (Y-m-d H:i:s format)
 * @param int|null $cardNumber Card number for multi-card reports (1 or 2)
 * @return string Sanitized filename
 */
if (!function_exists('sf_generate_preview_filename')) {
    function sf_generate_preview_filename($site, $title, $lang, $type = SF_DEFAULT_FLASH_TYPE, $occurredAt = null, $cardNumber = null) {
        // Get date in YYYY_MM_DD format
        $date = $occurredAt ? date('Y_m_d', strtotime($occurredAt)) : date('Y_m_d');
        
        // Sanitize site name and title using helper function
        $siteSafe = sf_sanitize_for_filename($site, SF_MAX_SITE_NAME_LENGTH, 'Site');
        $titleSafe = sf_sanitize_for_filename($title, SF_MAX_TITLE_LENGTH, 'Flash');
        
        // Ensure language is valid, fallback to FI
        $langSafe = in_array($lang, SF_ALLOWED_LANGUAGES, true) ? strtoupper($lang) : 'FI';
        
        // Ensure type is valid, fallback to YELLOW
        $typeSafe = in_array(strtolower($type), ['yellow', 'green'], true) ? strtoupper($type) : 'YELLOW';
        
        // Build filename: SF_YYYY_MM_DD_TYPE_Site-Title-LANG.jpg
        // For green type with card number, add card suffix after language: -LANG-1.jpg or -LANG-2.jpg
        $cardSuffix = '';
        if ($cardNumber !== null && strtolower($type) === 'green') {
            $cardSuffix = "-{$cardNumber}";
        }
        $filename = "SF_{$date}_{$typeSafe}_{$siteSafe}-{$titleSafe}-{$langSafe}{$cardSuffix}.jpg";
        
        return $filename;
    }
}

if (!function_exists('sf_save_dataurl_preview_v2')) {
    function sf_save_dataurl_preview_v2($dataurl, $uploadDir, $prefix = 'preview', $flashData = null) {
        if (empty($dataurl) || strpos($dataurl, 'data:image') !== 0) {
            sf_app_log('sf_save_dataurl_preview_v2: Invalid dataurl', 'ERROR');
            return false;
        }
        $parts = explode(',', $dataurl);
        if (count($parts) !== 2) {
            sf_app_log('sf_save_dataurl_preview_v2: Could not parse dataurl', 'ERROR');
            return false;
        }
        $imageData = base64_decode($parts[1], true);
        if ($imageData === false) {
            sf_app_log('sf_save_dataurl_preview_v2: base64_decode failed', 'ERROR');
            return false;
        }
        
        // BUG FIX 3: Use descriptive filename if flash data provided
        if ($flashData && isset($flashData['site']) && isset($flashData['title']) && isset($flashData['lang'])) {
            // Determine card number from prefix for green type with two cards
            $cardNumber = null;
            $type = $flashData['type'] ?? SF_DEFAULT_FLASH_TYPE;
            if (strtolower($type) === 'green') {
                // For green type, use card number to differentiate filenames
                $cardNumber = ($prefix === 'preview2') ? 2 : 1;
            }
            
            $filename = sf_generate_preview_filename(
                $flashData['site'],
                $flashData['title'],
                $flashData['lang'],
                $type,
                $flashData['occurred_at'] ?? null,
                $cardNumber
            );
        } else {
            // Fallback to old format if data not available
            $filename = $prefix . '_' . time() . '_' . uniqid() . '.jpg';
        }
        
        $targetPath = $uploadDir . $filename;
        $saved = false;
        try {
            if (extension_loaded('imagick')) {
                $im = new Imagick();
                $im->readImageBlob($imageData);
                if ($im->getImageAlphaChannel()) {
                    $im->setImageBackgroundColor('white');
                    $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                }
                $im->cropThumbnailImage(1920, 1080);
                $im->setImageFormat('jpeg');
                $im->setImageCompression(Imagick::COMPRESSION_JPEG);
                $im->setImageCompressionQuality(88);
                $im->writeImage($targetPath);
                $saved = true;
            } else if (extension_loaded('gd')) {
                $image = @imagecreatefromstring($imageData);
                if ($image !== false) {
                    $src_width = imagesx($image);
                    $src_height = imagesy($image);
                    $dst_width = 1920; $dst_height = 1080;
                    $dst = imagecreatetruecolor($dst_width, $dst_height);
                    $white = imagecolorallocate($dst, 255, 255, 255);
                    imagefill($dst, 0, 0, $white);
                    
                    // Issue 2: Calculate scale to fit (not stretch) - aspect-aware resizing
                    $scaleX = $dst_width / $src_width;
                    $scaleY = $dst_height / $src_height;
                    $scale = min($scaleX, $scaleY);
                    $newWidth = (int) round($src_width * $scale);
                    $newHeight = (int) round($src_height * $scale);
                    $offsetX = (int) (($dst_width - $newWidth) / 2);
                    $offsetY = (int) (($dst_height - $newHeight) / 2);
                    
                    imagecopyresampled($dst, $image, $offsetX, $offsetY, 0, 0, $newWidth, $newHeight, $src_width, $src_height);
                    imagejpeg($dst, $targetPath, 88);
                    imagedestroy($image); imagedestroy($dst);
                    $saved = true;
                }
            }
        } catch (Exception $e) {
            sf_app_log('sf_save_dataurl_preview_v2: Exception: ' . $e->getMessage(), 'ERROR');
        }
        return $saved ? $filename : false;
    }
}

if (!function_exists('sf_safe_filename')) {
    function sf_safe_filename(string $name): string {
        $name = preg_replace('/[^\w\-. ]+/u', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = trim($name, '._');
        if ($name === '') $name = bin2hex(random_bytes(4));
        return mb_substr($name, 0, 200);
    }
}

if (!function_exists('sf_unique_filename')) {
    function sf_unique_filename(string $dir, string $basename, string $ext): string {
        $i = 0;
        do {
            $suffix = $i === 0 ? '' : "-$i";
            $name = $basename . $suffix . '.' . $ext;
            $i++;
        } while (file_exists($dir . $name) && $i < 1000);
        return $name;
    }
}

if (!function_exists('sf_compress_image')) {
    function sf_compress_image(string $source, string $dest, string $mime): bool {
        $maxWidth = 1920; $maxHeight = 1920; $jpegQuality = 85;
        switch ($mime) {
            case 'image/jpeg': $srcImage = @imagecreatefromjpeg($source); break;
            case 'image/png': $srcImage = @imagecreatefrompng($source); break;
            case 'image/webp': $srcImage = @imagecreatefromwebp($source); break;
            default: return false;
        }
        if (!$srcImage) return false;
        $origWidth = imagesx($srcImage); $origHeight = imagesy($srcImage);
        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight, 1.0);
        $newWidth = (int) round($origWidth * $ratio); $newHeight = (int) round($origHeight * $ratio);
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        if (!$newImage) { imagedestroy($srcImage); return false; }
        $white = imagecolorallocate($newImage, 255, 255, 255);
        imagefill($newImage, 0, 0, $white);
        $resized = imagecopyresampled($newImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        if (!$resized) { imagedestroy($srcImage); imagedestroy($newImage); return false; }
        $saved = imagejpeg($newImage, $dest, $jpegQuality);
        imagedestroy($srcImage); imagedestroy($newImage);
        return $saved;
    }
}

if (!function_exists('sf_handle_uploaded_image')) {
    function sf_handle_uploaded_image(array $file, ?string $destDir = null): ?string {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
        $destDir = $destDir ?: __DIR__ . '/../../uploads/images/';
        $tmp = $file['tmp_name'];
        $maxUploadSize = 20 * 1024 * 1024;
        if (filesize($tmp) > $maxUploadSize) return null;
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
        $origName = basename($file['name'] ?? ('img_' . time()));
        $base = sf_safe_filename(pathinfo($origName, PATHINFO_FILENAME));
        $filename = sf_unique_filename($destDir, $base, 'jpg');
        $dest = $destDir . $filename;
        if (sf_compress_image($tmp, $dest, $mime)) {
            @chmod($dest, 0644);
            return $filename;
        }
        return null;
    }
}

// =========================================================================
// TYÖNTEKIJÄN PÄÄLOGIIKKA
// =========================================================================

$flash_id = 0;
if (php_sapi_name() === 'cli') {
    $flash_id = (int)($argv[1] ?? 0);
} else {
    $flash_id = (int)($_GET['flash_id'] ?? 0);
}
if ($flash_id <= 0) {
    error_log("Worker: Invalid flash_id provided.");
    exit(1);
}

sf_app_log("Worker starting for flash_id: $flash_id", 'INFO');

// Defensiivinen: hae tuore yhteys ja varmista että se toimii.
$pdo = null;
$lastConnError = null;
for ($attempt = 1; $attempt <= 2; $attempt++) {
    try {
        if ($attempt > 1 && method_exists(Database::class, 'reconnect')) {
            $pdo = Database::reconnect();
        } else {
            $pdo = Database::getInstance();
        }
        $pdo->query('SELECT 1'); // probe – throws if connection is dead
        break;
    } catch (Throwable $e) {
        $lastConnError = $e;
        sf_app_log("Worker DB probe failed on attempt {$attempt}: " . $e->getMessage(), 'WARN');
        if ($attempt < 2) {
            usleep(150000); // 150 ms before retry
        }
    }
}
if ($pdo === null) {
    error_log("Worker: Could not establish DB connection: " . ($lastConnError ? $lastConnError->getMessage() : 'unknown'));
    exit(1);
}

try {
    $pdo->prepare("UPDATE sf_flashes SET processing_status = 'in_progress' WHERE id = ?")->execute([$flash_id]);

    $temp_data_dir = __DIR__ . '/../../uploads/processes/';

    // Read job data from sf_jobs table instead of a .jobdata file
    $stmtJob = $pdo->prepare("SELECT id, job_data FROM sf_jobs WHERE flash_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmtJob->execute([$flash_id]);
    $jobRow = $stmtJob->fetch(PDO::FETCH_ASSOC);

    if (!$jobRow) {
        throw new Exception("No pending job record found in sf_jobs for flash_id: $flash_id");
    }

    $sfJobId = (int)$jobRow['id'];

    // Mark the job as in_progress
    $pdo->prepare("UPDATE sf_jobs SET status = 'in_progress', updated_at = NOW() WHERE id = ?")
        ->execute([$sfJobId]);

    $job_data = json_decode((string)$jobRow['job_data'], true);
    if ($job_data === null) {
        throw new Exception("Failed to decode job_data from sf_jobs row id: $sfJobId");
    }
    $post = $job_data['post'];
    $uploadedFiles = $job_data['files'];

    // Always read grid_bitmap from the database as the authoritative source.
    // By the time the worker runs, any temp->permanent file move has already
    // been completed and the DB holds the correct permanent filename.
    // This is a safety net for all save paths (create, edit, investigation update).
    $gridBitmapStmt = $pdo->prepare("SELECT grid_bitmap FROM sf_flashes WHERE id = ?");
    $gridBitmapStmt->execute([$flash_id]);
    $gridBitmapRow = $gridBitmapStmt->fetch(PDO::FETCH_ASSOC);
    if ($gridBitmapRow && !empty($gridBitmapRow['grid_bitmap'])) {
        $post['grid_bitmap'] = $gridBitmapRow['grid_bitmap'];
    }

    $update_fields = [];

    // Get type early since it's needed for both previews
    $type = trim((string) ($post['type'] ?? SF_DEFAULT_FLASH_TYPE));

    // =========================================================================
    // SNAPSHOT: Save/update version history for published flashes
    // =========================================================================

    // Get current flash data to check state
    $stmtFlashData = $pdo->prepare("
        SELECT id, state, type, lang, preview_filename, translation_group_id 
        FROM sf_flashes 
        WHERE id = ? 
        LIMIT 1
    ");
    $stmtFlashData->execute([$flash_id]);
    $currentFlash = $stmtFlashData->fetch(PDO::FETCH_ASSOC);

    // If flash is published, save snapshot before generating new preview
    if ($currentFlash && $currentFlash['state'] === 'published') {
        $previewFilename = $currentFlash['preview_filename'] ?? null;
        
        if ($previewFilename) {
            $baseDir = dirname(__DIR__, 2);
            $previewPath = $baseDir . '/uploads/previews/' . $previewFilename;
            
            if (file_exists($previewPath)) {
                // Determine version type based on flash type
                $flashType = $currentFlash['type'] ?? 'yellow';
                $versionType = match($flashType) {
                    'red' => 'ensitiedote',
                    'yellow' => 'vaaratilanne',
                    'green' => 'tutkintatiedote',
                    default => 'vaaratilanne',
                };
                
                // Use translation_group_id if available, otherwise flash_id
                $groupId = $currentFlash['translation_group_id'] ?: $flash_id;
                
                // Check if snapshot already exists for this version type and lang
                $snapshotLang = $currentFlash['lang'] ?? 'fi';
                $stmtExisting = $pdo->prepare("
                    SELECT id, image_path FROM sf_flash_snapshots 
                    WHERE flash_id = ? AND version_type = ? AND lang = ?
                    LIMIT 1
                ");
                $stmtExisting->execute([$groupId, $versionType, $snapshotLang]);
                $existingSnapshot = $stmtExisting->fetch(PDO::FETCH_ASSOC);
                
                // Get user_id from job data if available
                $userId = $post['user_id'] ?? null;
                
                if ($existingSnapshot) {
                    // Update existing snapshot - copy current preview to snapshot location
                    $snapshotFullPath = $baseDir . $existingSnapshot['image_path'];
                    if (copy($previewPath, $snapshotFullPath)) {
                        $stmtUpdate = $pdo->prepare("
                            UPDATE sf_flash_snapshots 
                            SET published_at = NOW(), published_by = ?
                            WHERE id = ?
                        ");
                        $stmtUpdate->execute([$userId, $existingSnapshot['id']]);
                        sf_app_log("[Worker] Updated existing snapshot for flash {$groupId}, type: {$versionType}");
                    } else {
                        sf_app_log("[Worker] Failed to copy preview to snapshot for flash {$groupId}", LOG_LEVEL_ERROR);
                    }
                } else {
                    // Create new snapshot
                    $snapshotDir = $baseDir . '/storage/snapshots/' . $groupId;
                    if (!is_dir($snapshotDir)) {
                        if (!mkdir($snapshotDir, 0755, true)) {
                            sf_app_log("[Worker] Failed to create snapshot directory for flash {$groupId}", LOG_LEVEL_ERROR);
                        }
                    }
                    
                    if (is_dir($snapshotDir)) {
                        $timestamp = date('Y-m-d_His');
                        $snapshotFilename = $versionType . '_' . $timestamp . '.jpg';
                        $snapshotPath = $snapshotDir . '/' . $snapshotFilename;
                        
                        if (copy($previewPath, $snapshotPath)) {
                            $relativeImagePath = '/storage/snapshots/' . $groupId . '/' . $snapshotFilename;
                            
                            // Count existing snapshots for version number
                            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM sf_flash_snapshots WHERE flash_id = ?");
                            $stmtCount->execute([$groupId]);
                            $versionNumber = (int)$stmtCount->fetchColumn() + 1;
                            
                            $stmtSnapshot = $pdo->prepare("
                                INSERT INTO sf_flash_snapshots 
                                (flash_id, version_type, lang, version_number, image_path, published_at, published_by)
                                VALUES (?, ?, ?, ?, ?, NOW(), ?)
                            ");
                            $stmtSnapshot->execute([$groupId, $versionType, $snapshotLang, $versionNumber, $relativeImagePath, $userId]);
                            sf_app_log("[Worker] Created new snapshot for flash {$groupId}, type: {$versionType}, version: {$versionNumber}");
                        } else {
                            sf_app_log("[Worker] Failed to create snapshot for flash {$groupId}", LOG_LEVEL_ERROR);
                        }
                    }
                }
            } else {
                sf_app_log("[Worker] Preview file not found for snapshot: {$previewPath}", LOG_LEVEL_ERROR);
            }
        }
    }
    // =========================================================================

    // =========================================================================
    // GENERATE PREVIEW IMAGES USING SERVER-SIDE PREVIEWRENDERER
    // =========================================================================

    // BUG FIX 3: Prepare flash data for descriptive filename generation
    $flashDataForFilename = [
        'site' => trim((string) ($post['site'] ?? $post['worksite'] ?? '')),
        'title' => trim((string) ($post['title'] ?? $post['short_text'] ?? '')),
        'lang' => trim((string) ($post['lang'] ?? 'fi')),
        'type' => $type,
        'occurred_at' => trim((string) ($post['occurred_at'] ?? $post['event_date'] ?? ''))
    ];

    // Prepare flash data for PreviewRenderer
    $rendererData = [
        'type' => $type,
        'lang' => trim((string) ($post['lang'] ?? 'fi')),
        'short_text' => trim((string) ($post['short_text'] ?? $post['title_short'] ?? '')),
        'description' => trim((string) ($post['description'] ?? '')),
        'site' => trim((string) ($post['site'] ?? $post['worksite'] ?? '')),
        'site_detail' => trim((string) ($post['site_detail'] ?? '')),
        'occurred_at' => trim((string) ($post['occurred_at'] ?? $post['event_date'] ?? '')),
        'root_causes' => trim((string) ($post['root_causes'] ?? '')),
        'actions' => trim((string) ($post['actions'] ?? '')),
        'grid_bitmap' => trim((string) ($post['grid_bitmap'] ?? '')),
        'font_size_override' => !empty($post['font_size_override']) ? trim((string) $post['font_size_override']) : null,
        'layout_mode' => !empty($post['layout_mode']) ? trim((string) $post['layout_mode']) : 'auto',
    ];

    try {
        require_once __DIR__ . '/../services/PreviewRenderer.php';
        $renderer = new PreviewRenderer();
        
        // Determine if green type needs two slides using the exact same renderer logic
        $needsTwoSlides = false;
        if ($type === 'green') {
            $needsTwoSlides = $renderer->needsSecondCard($rendererData);
        }
        
        // Generate Card 1 (or single card for yellow/red)
        if ($type === 'green') {
            $rendererData['card_number'] = $needsTwoSlides ? '1' : 'single';
        } else {
            // For yellow/red types, use null to avoid card suffix in filename
            $rendererData['card_number'] = null;
        }
        
        $imageBase64 = $renderer->render($rendererData, 'final');
        
        if ($imageBase64) {
            // Save the image
            $previewsDir = __DIR__ . '/../../uploads/previews/';
            if (!is_dir($previewsDir)) {
                @mkdir($previewsDir, 0755, true);
            }
            
            $filename = sf_generate_preview_filename(
                $flashDataForFilename['site'],
                $flashDataForFilename['title'],
                $flashDataForFilename['lang'],
                $type,
                $flashDataForFilename['occurred_at'],
                ($type === 'green' && $needsTwoSlides) ? 1 : null
            );
            
            $imageData = base64_decode($imageBase64);
            if ($imageData === false) {
                sf_app_log("[Worker] Failed to decode base64 image data for card 1", LOG_LEVEL_ERROR);
            } elseif (!file_put_contents($previewsDir . $filename, $imageData)) {
                sf_app_log("[Worker] Failed to write preview file: {$filename}", LOG_LEVEL_ERROR);
            } else {
                $update_fields['preview_filename'] = $filename;
                sf_app_log("[Worker] Generated preview_filename: {$filename}", LOG_LEVEL_DEBUG);
            }
        }
        
        // Generate Card 2 for green type with two slides
        if ($type === 'green' && $needsTwoSlides) {
            $rendererData['card_number'] = '2';
            $card2ImageBase64 = $renderer->render($rendererData, 'final');
            
            if ($card2ImageBase64) {
                $filename2 = sf_generate_preview_filename(
                    $flashDataForFilename['site'],
                    $flashDataForFilename['title'],
                    $flashDataForFilename['lang'],
                    $type,
                    $flashDataForFilename['occurred_at'],
                    2
                );
                
                $card2ImageData = base64_decode($card2ImageBase64);
                if ($card2ImageData === false) {
                    sf_app_log("[Worker] Failed to decode base64 image data for card 2", LOG_LEVEL_ERROR);
                } elseif (!file_put_contents($previewsDir . $filename2, $card2ImageData)) {
                    sf_app_log("[Worker] Failed to write preview file: {$filename2}", LOG_LEVEL_ERROR);
                } else {
                    $update_fields['preview_filename_2'] = $filename2;
                    sf_app_log("[Worker] Generated preview_filename_2: {$filename2}", LOG_LEVEL_DEBUG);
                }
            }
        } elseif ($type === 'green' && !$needsTwoSlides) {
            // Content fits on single card - clear any old second card reference
            $update_fields['preview_filename_2'] = null;
        }
        
    } catch (Throwable $e) {
        sf_app_log("[Worker] PreviewRenderer error: " . $e->getMessage(), LOG_LEVEL_ERROR);
        // Continue without preview - don't fail the entire save
    }

    // Käsittele ladatut kuvat
    if (!defined('UPLOADS_IMAGES_DIR')) {
        define('UPLOADS_IMAGES_DIR', __DIR__ . '/../../uploads/images/');
    }
    if (!is_dir(UPLOADS_IMAGES_DIR)) @mkdir(UPLOADS_IMAGES_DIR, 0755, true);

    foreach (['image1' => 'image_main', 'image2' => 'image_2', 'image3' => 'image_3'] as $field => $dbcol) {
        if (!empty($uploadedFiles[$field]) && $uploadedFiles[$field]['error'] === UPLOAD_ERR_OK) {
            $saved_image = sf_handle_uploaded_image($uploadedFiles[$field], UPLOADS_IMAGES_DIR);
            if ($saved_image) {
                $update_fields[$dbcol] = $saved_image;
            }
        }
    }
    
    // Päivitä tietokanta
    if (!empty($update_fields)) {

        // Jos preview-kuvia syntyi, merkitään preview valmiiksi
        if (!empty($update_fields['preview_filename']) || !empty($update_fields['preview_filename_2'])) {
            $update_fields['preview_status'] = 'completed';
        }

        $set_parts = [];
        foreach (array_keys($update_fields) as $key) {
            $set_parts[] = "`$key` = :$key";
        }

        $adminImportEnabled = (($post['admin_import_enabled'] ?? '') === '1');
        $adminCreatedAtMode = trim((string)($post['admin_created_at_mode'] ?? 'now'));

        if ($adminImportEnabled && $adminCreatedAtMode !== 'now') {
            $sql = "UPDATE sf_flashes SET " . implode(', ', $set_parts)
                 . ", processing_status = 'completed', is_processing = 0, updated_at = created_at WHERE id = :id";
        } else {
            $sql = "UPDATE sf_flashes SET " . implode(', ', $set_parts)
                 . ", processing_status = 'completed', is_processing = 0, updated_at = NOW() WHERE id = :id";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($update_fields + ['id' => $flash_id]);

    } else {
        // Jos mitään kuvia ei ollut, merkitään silti valmiiksi + preview epäonnistui
        $adminImportEnabled = (($post['admin_import_enabled'] ?? '') === '1');
        $adminCreatedAtMode = trim((string)($post['admin_created_at_mode'] ?? 'now'));

        if ($adminImportEnabled && $adminCreatedAtMode !== 'now') {
            $pdo->prepare("UPDATE sf_flashes SET processing_status = 'completed', is_processing = 0, preview_status = 'failed', updated_at = created_at WHERE id = ?")
                ->execute([$flash_id]);
        } else {
            $pdo->prepare("UPDATE sf_flashes SET processing_status = 'completed', is_processing = 0, preview_status = 'failed', updated_at = NOW() WHERE id = ?")
                ->execute([$flash_id]);
        }
    }

    // Mark job as completed in sf_jobs and clean up uploaded temp files
    if (isset($sfJobId)) {
        $pdo->prepare("UPDATE sf_jobs SET status = 'completed', updated_at = NOW() WHERE id = ?")
            ->execute([$sfJobId]);
    }
    foreach ($uploadedFiles as $file_info) {
        if (isset($file_info['tmp_name'])) {
            @unlink($file_info['tmp_name']);
        }
    }
    
    sf_app_log("Worker successfully processed flash_id: $flash_id", 'INFO');

} catch (Throwable $e) {
    $isGoneAway = Database::isGoneAwayError($e);

    sf_app_log("Worker FAILED for flash_id: $flash_id. Error: " . $e->getMessage() . "\n" . $e->getTraceAsString(), 'ERROR');

    // Attempt to update error status, reconnecting first if needed
    try {
        if ($isGoneAway && method_exists(Database::class, 'reconnect')) {
            sf_app_log("Worker attempting reconnect after gone-away error for flash_id: $flash_id", 'WARN');
            $pdo = Database::reconnect();
        }
        if (isset($pdo)) {
            $pdo->prepare("UPDATE sf_flashes SET processing_status = 'error', is_processing = 0 WHERE id = ?")->execute([$flash_id]);
            if (isset($sfJobId)) {
                $pdo->prepare("UPDATE sf_jobs SET status = 'failed', updated_at = NOW() WHERE id = ?")
                    ->execute([$sfJobId]);
            }
        }
    } catch (Throwable $logErr) {
        error_log("Worker failure logging also failed: " . $logErr->getMessage());
    }
}