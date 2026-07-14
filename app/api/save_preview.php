<?php
// app/api/save_preview.php
// Saves a 1920x1080 JPG preview for displays.
// Expects POST: id (flashId), image (dataURL from html2canvas)

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';   // auth + CSRF
require_once __DIR__ . '/../includes/log_app.php';

header('Content-Type: application/json; charset=utf-8');

// Debug only when explicitly enabled
if (!empty($config['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// Limits (defense-in-depth)
const PREVIEW_TARGET_WIDTH  = 1920;
const PREVIEW_TARGET_HEIGHT = 1080;
const PREVIEW_JPG_QUALITY   = 88;

// Absolute limits to prevent memory abuse
const SF_MAX_DATAURL_BYTES  = 8_000_000;  // ~8MB base64 payload max
const SF_MAX_DECODED_BYTES  = 6_000_000;  // ~6MB raw image bytes max

const UPLOADS_PREVIEWS_DIR  = __DIR__ . '/../../uploads/previews/';

function sf_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$flashId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$dataUrl = (string)($_POST['image'] ?? '');

if ($flashId <= 0 || $dataUrl === '') {
    sf_json(['ok' => false, 'error' => 'Missing parameters'], 400);
}

if (strlen($dataUrl) > SF_MAX_DATAURL_BYTES) {
    sf_json(['ok' => false, 'error' => 'Image payload too large'], 413);
}

// Accept only data:image/png or data:image/jpeg
if (!preg_match('#^data:image/(png|jpeg);base64,#i', $dataUrl, $m)) {
    sf_json(['ok' => false, 'error' => 'Invalid image format'], 400);
}

$base64 = preg_replace('#^data:image/(png|jpeg);base64,#i', '', $dataUrl);
if ($base64 === null) {
    sf_json(['ok' => false, 'error' => 'Invalid image payload'], 400);
}

// Decode with strict mode
$binary = base64_decode($base64, true);
if ($binary === false) {
    sf_json(['ok' => false, 'error' => 'Invalid base64'], 400);
}

if (strlen($binary) > SF_MAX_DECODED_BYTES) {
    sf_json(['ok' => false, 'error' => 'Decoded image too large'], 413);
}

// Ensure preview dir exists
if (!is_dir(UPLOADS_PREVIEWS_DIR)) {
    if (!@mkdir(UPLOADS_PREVIEWS_DIR, 0750, true) && !is_dir(UPLOADS_PREVIEWS_DIR)) {
        sf_json(['ok' => false, 'error' => 'Failed to create directory'], 500);
    }
}

// Load image (prefer Imagick)
$jpegBinary = null;

try {
    if (class_exists('Imagick')) {
        $img = new Imagick();
        $img->readImageBlob($binary);

        // Flatten transparent background on white for JPG
        $img->setImageBackgroundColor('white');
        $img = $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(PREVIEW_JPG_QUALITY);

        // Resize (contain) to target
        $img->thumbnailImage(PREVIEW_TARGET_WIDTH, PREVIEW_TARGET_HEIGHT, true, true);

        // Create exact canvas
        $canvas = new Imagick();
        $canvas->newImage(PREVIEW_TARGET_WIDTH, PREVIEW_TARGET_HEIGHT, 'white', 'jpeg');

        $x = (int)((PREVIEW_TARGET_WIDTH  - $img->getImageWidth())  / 2);
        $y = (int)((PREVIEW_TARGET_HEIGHT - $img->getImageHeight()) / 2);
        $canvas->compositeImage($img, Imagick::COMPOSITE_OVER, $x, $y);

        $jpegBinary = $canvas->getImageBlob();

        $img->clear(); $img->destroy();
        $canvas->clear(); $canvas->destroy();
    } else {
        $src = @imagecreatefromstring($binary);
        if (!$src) {
            sf_json(['ok' => false, 'error' => 'Invalid image data'], 400);
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        if ($srcW < 50 || $srcH < 50) {
            imagedestroy($src);
            sf_json(['ok' => false, 'error' => 'Image too small'], 400);
        }

        $scale = min(PREVIEW_TARGET_WIDTH / $srcW, PREVIEW_TARGET_HEIGHT / $srcH);
        $newW = (int)max(1, floor($srcW * $scale));
        $newH = (int)max(1, floor($srcH * $scale));

        $dst = imagecreatetruecolor(PREVIEW_TARGET_WIDTH, PREVIEW_TARGET_HEIGHT);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, PREVIEW_TARGET_WIDTH, PREVIEW_TARGET_HEIGHT, $white);

        $tmp = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

        $x = (int)((PREVIEW_TARGET_WIDTH - $newW) / 2);
        $y = (int)((PREVIEW_TARGET_HEIGHT - $newH) / 2);
        imagecopy($dst, $tmp, $x, $y, 0, 0, $newW, $newH);

        ob_start();
        imagejpeg($dst, null, PREVIEW_JPG_QUALITY);
        $jpegBinary = ob_get_clean();

        imagedestroy($src);
        imagedestroy($tmp);
        imagedestroy($dst);
    }
} catch (Throwable $e) {
    sf_app_log('save_preview error: ' . $e->getMessage(), LOG_LEVEL_WARNING, ['flash_id' => $flashId]);
    sf_json(['ok' => false, 'error' => 'Preview render failed'], 500);
}

if (!$jpegBinary) {
    sf_json(['ok' => false, 'error' => 'Preview render failed'], 500);
}

// Save file
$filename = 'preview_' . $flashId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.jpg';
$path = UPLOADS_PREVIEWS_DIR . $filename;

if (@file_put_contents($path, $jpegBinary, LOCK_EX) === false) {
    sf_json(['ok' => false, 'error' => 'Failed to save preview'], 500);
}
@chmod($path, 0640);

// Return URL (relative)
$base = rtrim((string)($config['base_url'] ?? ''), '/');
$publicUrl = $base . '/uploads/previews/' . $filename;

sf_json(['ok' => true, 'url' => $publicUrl]);