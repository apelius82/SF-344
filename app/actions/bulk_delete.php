<?php
/**
 * bulk_delete.php - Poistaa useita Safetyflasheja kerralla (vain ylläpitäjä)
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config.php';

// bulk_delete uses JSON body and validates CSRF token manually.
// Skip protect.php automatic CSRF (which only checks $_POST).
define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';

// Tarkista ylläpitäjäoikeudet
$user = sf_current_user();
if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Ei oikeuksia'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lue JSON-body
$input = json_decode(file_get_contents('php://input'), true);
$ids   = $input['ids'] ?? [];

// Tarkista CSRF-token
$csrfToken = $input['csrf_token'] ?? '';
if (!sf_csrf_validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Virheellinen CSRF-token']);
    exit;
}

if (empty($ids) || !is_array($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ei poistettavia ID:itä'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Suodata vain numerot
$ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);

if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'Virheelliset ID:t']);
    exit;
}

// DB-yhteys
try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Tietokantavirhe'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deleted       = 0;
$errors        = [];
$deletedTitles = [];

// Kansioiden polut
$uploadsImages   = __DIR__ . '/../../uploads/images/';
$uploadsPreviews = __DIR__ . '/../../uploads/previews/';
$uploadsGridBitmaps = __DIR__ . '/../../uploads/grid_bitmaps/';
$uploadsRoot = __DIR__ . '/../../uploads/';

// Ensin:  Etsi kaikki poistetavat ID:t (myös kieliversiot)
$allIdsToDelete = [];
$processedGroups = [];

// Käy läpi käyttäjän valitsemat IDs
foreach ($ids as $selectedId) {
    // Jos tämä ryhmä on jo käsitelty, ohita
    if (in_array($selectedId, $processedGroups, true)) {
        continue;
    }

    try {
        // Hae tämän flashin tiedot (myös translation_group_id)
        $stmt = $pdo->prepare("
            SELECT 
                id,
                translation_group_id,
                title,
                image_main,
                image_2,
                image_3,
                preview_filename,
                preview_filename_2,
                grid_bitmap
            FROM sf_flashes
            WHERE id = ?
        ");
        $stmt->execute([$selectedId]);
        $flash = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$flash) {
            continue;
        }

        // Määritä ryhmä
        $isRoot = empty($flash['translation_group_id']) || 
                  (int)$flash['translation_group_id'] === (int)$flash['id'];
        $groupRootId = $isRoot ? (int)$flash['id'] : (int)($flash['translation_group_id'] ?? $flash['id']);

        // Jos tämä on pääversio, hae kaikki kieliversiot
        if ($isRoot) {
            $selAll = $pdo->prepare("
                SELECT 
                    id,
                    title,
                    image_main,
                    image_2,
                    image_3,
                    preview_filename,
                    preview_filename_2,
                    grid_bitmap
                FROM sf_flashes 
                WHERE translation_group_id = ? OR id = ?
            ");
            $selAll->execute([$groupRootId, $groupRootId]);
            $allVersions = $selAll->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($allVersions as $version) {
                if (! in_array((int)$version['id'], $allIdsToDelete, true)) {
                    $allIdsToDelete[] = $version;
                }
            }
            
            // Merkitse tämä ryhmä käsitellyksi
            $processedGroups[] = $groupRootId;
        } else {
            // Jos vain kieliversio valittiin, poista vain se
            if (! in_array($selectedId, array_column($allIdsToDelete, 'id'), true)) {
                $allIdsToDelete[] = $flash;
            }
            $processedGroups[] = $selectedId;
        }
    } catch (Exception $e) {
        $errors[] = "ID {$selectedId}: " . $e->getMessage();
    }
}

// Nyt poista kaikki kerätyt versiot
foreach ($allIdsToDelete as $flashToDelete) {
    try {
        $flashId = (int)$flashToDelete['id'];
        
        $deletedTitles[] = $flashToDelete['title'] ?? "ID {$flashId}";

        // Poista kuvat
        $imagesToDelete = [
            $flashToDelete['image_main']         ? $uploadsImages   . $flashToDelete['image_main']         : null,
            $flashToDelete['image_2']            ? $uploadsImages   . $flashToDelete['image_2']            : null,
            $flashToDelete['image_3']            ? $uploadsImages   . $flashToDelete['image_3']            : null,
            $flashToDelete['preview_filename']   ? $uploadsPreviews . $flashToDelete['preview_filename']   : null,
            $flashToDelete['preview_filename_2'] ? $uploadsPreviews . $flashToDelete['preview_filename_2'] : null,
        ];

        foreach ($imagesToDelete as $imagePath) {
            if (!empty($imagePath) && is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
        
        // Poista grid_bitmap (tarkista sekä grid_bitmaps-kansio että uploads-juuri)
        if (!empty($flashToDelete['grid_bitmap'])) {
            $gridPath = $uploadsGridBitmaps . $flashToDelete['grid_bitmap'];
            if (is_file($gridPath)) {
                @unlink($gridPath);
            }
            // Tarkista myös uploads-juuri
            $gridPathRoot = $uploadsRoot . $flashToDelete['grid_bitmap'];
            if (is_file($gridPathRoot)) {
                @unlink($gridPathRoot);
            }
        }

        // Poista lokit
        $stmtLog = $pdo->prepare("DELETE FROM safetyflash_logs WHERE flash_id = ?");
        $stmtLog->execute([$flashId]);

        // Poista flash tietokannasta
        $stmtDel = $pdo->prepare("DELETE FROM sf_flashes WHERE id = ?");
        $stmtDel->execute([$flashId]);

        $deleted++;
    } catch (Exception $e) {
        $errors[] = "ID {$flashToDelete['id']}: " . $e->getMessage();
    }
}

// ========== AUDIT LOG ==========
if ($deleted > 0) {
    sf_audit_log(
        'flash_bulk_delete',        // action
        'flash',                    // target type
        null,                       // target id (bulk-operaatio -> ei yksittäistä)
        [
            'deleted_count' => $deleted,
            'deleted_ids'   => $ids,
            'titles'        => array_slice($deletedTitles, 0, 10), // max 10 otsikkoa talteen
        ],
        $user ? (int)$user['id'] : null // user id
    );
}
// ================================

echo json_encode([
    'success' => true,
    'deleted' => $deleted,
    'errors'  => $errors,
]);