<?php
// app/actions/template_replace.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json; charset=utf-8');

$user = sf_current_user();
if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Ei oikeuksia tähän toimintoon.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrfToken = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Virheellinen turvatunniste.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$templateName = basename((string)($_POST['template_name'] ?? ''));
if ($templateName === '' || !preg_match('/^[A-Za-z0-9._-]+\.jpg$/', $templateName)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Virheellinen pohjatiedoston nimi.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$templateDir = realpath(__DIR__ . '/../../assets/img/templates');
if ($templateDir === false) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Pohjakansiota ei löytynyt.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetPath = $templateDir . DIRECTORY_SEPARATOR . $templateName;
$realTargetPath = realpath($targetPath);
if ($realTargetPath === false || !is_file($realTargetPath)) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'Korvattavaa pohjatiedostoa ei löytynyt.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['template_file']) || !is_array($_FILES['template_file'])) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Ladattava tiedosto puuttuu.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['template_file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Tiedoston lähetys epäonnistui.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpPath = (string)($file['tmp_name'] ?? '');
$originalName = (string)($file['name'] ?? '');

if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Väliaikaista tiedostoa ei löytynyt.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$originalExtension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($originalExtension, ['jpg', 'jpeg'], true)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Pohjan pitää olla JPG-tiedosto.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Tiedoston tarkistus epäonnistui.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$mimeType = (string)finfo_file($finfo, $tmpPath);
finfo_close($finfo);

if (!in_array($mimeType, ['image/jpeg', 'image/pjpeg'], true)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Vain JPG-kuvat ovat sallittuja.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$newImageInfo = @getimagesize($tmpPath);
$oldImageInfo = @getimagesize($realTargetPath);

if ($newImageInfo === false || $oldImageInfo === false) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Kuvatiedoston tietoja ei voitu lukea.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$newWidth = (int)($newImageInfo[0] ?? 0);
$newHeight = (int)($newImageInfo[1] ?? 0);
$oldWidth = (int)($oldImageInfo[0] ?? 0);
$oldHeight = (int)($oldImageInfo[1] ?? 0);

if ($newWidth !== $oldWidth || $newHeight !== $oldHeight) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Korvaavan kuvan koon pitää olla täsmälleen ' . $oldWidth . ' × ' . $oldHeight . ' px.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tempTarget = $templateDir . DIRECTORY_SEPARATOR . '.tmp_' . bin2hex(random_bytes(8)) . '.jpg';

if (!move_uploaded_file($tmpPath, $tempTarget)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Tiedoston siirto epäonnistui.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

@chmod($tempTarget, 0644);

if (!rename($tempTarget, $realTargetPath)) {
    @unlink($tempTarget);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Pohjan korvaaminen epäonnistui.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

sf_audit_log(
    'template_replaced',
    'template',
    0,
    [
        'template_name' => $templateName,
        'original_upload_name' => $originalName,
        'width' => $newWidth,
        'height' => $newHeight,
    ],
    (int)($user['id'] ?? 0)
);

echo json_encode([
    'ok' => true,
    'message' => 'Pohja korvattu onnistuneesti: ' . $templateName
], JSON_UNESCAPED_UNICODE);
exit;