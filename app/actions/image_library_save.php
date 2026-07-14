<?php
// app/actions/image_library_save.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

// Vain admin
$user = sf_current_user();
if (!$user || (int) ($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);

    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $isAjax = (strpos($accept, 'application/json') !== false)
        || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Ei oikeuksia'], JSON_UNESCAPED_UNICODE);
    } else {
        echo 'Ei oikeuksia';
    }
    exit;
}

$baseUrl = rtrim((string) ($config['base_url'] ??  ''), '/');

// Tue molempia: sf_action (uusi) ja action (vanha/fallback)
$action = (string) ($_POST['sf_action'] ?? $_POST['action'] ?? '');

$mysqli = sf_db();

// Luo uploads/library-kansio jos ei ole
$uploadDir = __DIR__ . '/../../uploads/library/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

switch ($action) {
    case 'add':
        // Validoi
        $title       = trim((string) ($_POST['title'] ?? ''));
        $category    = trim((string) ($_POST['category'] ?? 'body'));
        $description = trim((string) ($_POST['description'] ?? ''));

        $allowedCategories = ['body', 'warning', 'equipment', 'template'];
        if (!in_array($category, $allowedCategories, true)) {
            $category = 'body';
        }

        if ($title === '') {
            header("Location: {$baseUrl}/index.php?page=settings&tab=image_library&error=missing_title");
            exit;
        }

        // Tarkista tiedosto
        if (!isset($_FILES['image']) || !is_array($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            header("Location: {$baseUrl}/index.php?page=settings&tab=image_library&error=upload_failed");
            exit;
        }

        $file         = $_FILES['image'];
        $originalName = (string) ($file['name'] ?? '');
        $tmpPath      = (string) ($file['tmp_name'] ?? '');

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            header("Location: {$baseUrl}/index.php?page=settings&tab=image_library&error=upload_failed");
            exit;
        }

        // Tarkista tyyppi - EI SVG:tä (XSS-riski)
        $allowedTypes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png'  => ['png'],
            'image/gif'  => ['gif'],
            'image/webp' => ['webp'],
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            header("Location: {$baseUrl}/index.php?page=settings&tab=image_library&error=invalid_type");
            exit;
        }
        $mimeType = (string) finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!array_key_exists($mimeType, $allowedTypes)) {
            header("Location: {$baseUrl}/index.php?page=settings&tab=image_library&error=invalid_type");
            exit;
        }

        // Validoi tiedostopääte MIME-tyyppiä vasten
        $originalExt = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExts = $allowedTypes[$mimeType];

        if ($originalExt === '' || !in_array($originalExt, $allowedExts, true)) {
            header("Location: {$baseUrl}/index.php?page=settings&tab=image_library&error=extension_mismatch");
            exit;
        }

        // Varmista että tiedosto on oikeasti kuva (SVG ei läpäise getimagesizea)
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            header("Location: {$baseUrl}/index.php?page=settings&tab=image_library&error=invalid_image");
            exit;
        }

        // Luo uniikki tiedostonimi käyttäen validoitua päätettä
        $safeExt  = $allowedExts[0]; // ensisijainen (jpg/png/gif/webp)
        $filename = 'lib_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;

        // Siirrä tiedosto
        $destPath = $uploadDir . $filename;
        if (!move_uploaded_file($tmpPath, $destPath)) {
            header("Location: {$baseUrl}/index.php?page=settings&tab=image_library&error=move_failed");
            exit;
        }

        @chmod($destPath, 0644);

        // Tallenna kantaan
        $stmt = $mysqli->prepare("
            INSERT INTO sf_image_library
                (filename, original_name, category, title, description, created_by, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, NOW())
        ");
        if ($stmt) {
            $userId = (int) ($user['id'] ?? 0);
            $stmt->bind_param('sssssi', $filename, $originalName, $category, $title, $description, $userId);
            $stmt->execute();
            $newImageId = $mysqli->insert_id;
            $stmt->close();

            // ========== AUDIT LOG ==========
            sf_audit_log(
                'image_uploaded',
                'image',
                (int)$newImageId,
                [
                    'title' => $title,
                    'category' => $category,
                    'filename' => $filename,
                    'original_name' => $originalName,
                ],
                $userId
            );
            // ================================
        }

        header("Location:  {$baseUrl}/index.php?page=settings&tab=image_library&notice=image_added");
        exit;

    case 'toggle': 
        $id = (int) ($_POST['id'] ?? 0);

        if ($id > 0) {
            $stmt = $mysqli->prepare("UPDATE sf_image_library SET is_active = NOT is_active WHERE id = ? ");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }

            // Hae uusi tila audit-lokia varten
            $newActive = null;
            $imageTitle = null;
            $stmtInfo = $mysqli->prepare("SELECT title, is_active FROM sf_image_library WHERE id = ? LIMIT 1");
            if ($stmtInfo) {
                $stmtInfo->bind_param('i', $id);
                $stmtInfo->execute();
                $resInfo = $stmtInfo->get_result();
                $rowInfo = $resInfo ? $resInfo->fetch_assoc() : null;
                if ($rowInfo) {
                    $newActive = (int)($rowInfo['is_active'] ?? 0);
                    $imageTitle = $rowInfo['title'] ?? null;
                }
                $stmtInfo->close();
            }

            // ========== AUDIT LOG ==========
            sf_audit_log(
                'image_updated',
                'image',
                $id,
                [
                    'title' => $imageTitle,
                    'is_active' => $newActive,
                    'action' => 'toggle',
                ],
                (int)($user['id'] ?? 0)
            );
            // ================================
        }

        header("Location: {$baseUrl}/index.php?page=settings&tab=image_library&notice=image_toggled");
        exit;

    case 'delete':
        $id = (int) ($_POST['id'] ?? 0);

        if ($id > 0) {
            // Hae tiedostonimi ja title audit-lokia varten
            $stmt = $mysqli->prepare("SELECT filename, title FROM sf_image_library WHERE id = ? ");
            $imageTitle = null;
            $filename = null;
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();

                if ($row) {
                    $imageTitle = $row['title'] ??  null;
                    if (! empty($row['filename'])) {
                        // Poista tiedosto turvallisesti
                        $filename   = basename((string) $row['filename']); // estä path traversal
                        $filePath   = $uploadDir . $filename;
                        $realUpload = realpath($uploadDir);
                        $realFile   = realpath($filePath);

                        if ($realUpload && $realFile) {
                            $realUpload = rtrim($realUpload, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                            if (strpos($realFile, $realUpload) === 0) {
                                @unlink($realFile);
                            }
                        }
                    }
                }
            }

            // Poista kannasta (riippumatta siitä löytyikö tiedosto)
            $delStmt = $mysqli->prepare("DELETE FROM sf_image_library WHERE id = ? ");
            if ($delStmt) {
                $delStmt->bind_param('i', $id);
                $delStmt->execute();
                $delStmt->close();
            }

            // ========== AUDIT LOG ==========
            sf_audit_log(
                'image_deleted',
                'image',
                $id,
                [
                    'title' => $imageTitle,
                    'filename' => $filename,
                ],
                (int)($user['id'] ?? 0)
            );
            // ================================
        }

        header("Location: {$baseUrl}/index.php?page=settings&tab=image_library&notice=image_deleted");
        exit;

    default:
        header("Location: {$baseUrl}/index.php?page=settings&tab=image_library");
        exit;
}