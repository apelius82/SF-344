<?php
// app/actions/delete.php
declare(strict_types=1);


require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/statuses.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

$base = rtrim($config['base_url'] ?? '', '/');

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    header("Location: {$base}/index.php?page=view&id=" . ($id ? (int)$id : ''));
    exit;
}

try {
    $id = sf_validate_id();
    if ($id <= 0) {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => 'Virheellinen ID.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        header("Location: {$base}/index.php?page=list&notice=error");
        exit;
    }

    $pdo = sf_get_pdo();

    // Fetch the row
    $stmt = $pdo->prepare("
        SELECT 
            id,
            translation_group_id,
            image_main,
            image_2,
            image_3,
            preview_filename,
            grid_bitmap,
            state,
            title
        FROM sf_flashes 
        WHERE id = :id 
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        if ($isAjax) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => 'Tiedotetta ei löytynyt.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        header("Location: {$base}/index.php?page=list&notice=notfound");
        exit;
    }

    $flashTitle  = $row['title'] ?? '';
    $isRoot      = empty($row['translation_group_id']) || (int)$row['translation_group_id'] === (int)$row['id'];
    $groupRootId = $isRoot ? (int)$row['id'] : (int)($row['translation_group_id'] ?? $row['id']);

        // Determine which rows to delete
    if ($isRoot) {
$sel = $pdo->prepare("
    SELECT id, image_main, image_2, image_3, preview_filename, preview_filename_2, grid_bitmap
    FROM sf_flashes 
    WHERE translation_group_id = :gid1 OR id = :gid2
");
        $sel->execute([':gid1' => $groupRootId, ':gid2' => $groupRootId]);
        $toDelete = $sel->fetchAll();
    } else {
        $toDelete = [
            [
                'id'                 => $row['id'],
                'image_main'         => $row['image_main'] ?? null,
                'image_2'            => $row['image_2'] ??  null,
                'image_3'            => $row['image_3'] ?? null,
                'preview_filename'   => $row['preview_filename'] ?? null,
                'preview_filename_2' => $row['preview_filename_2'] ?? null,
                'grid_bitmap'        => $row['grid_bitmap'] ?? null,
            ]
        ];
    }

    if (empty($toDelete)) {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        if ($isAjax) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => 'Tiedotetta ei löytynyt.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        header("Location: {$base}/index.php?page=list&notice=notfound");
        exit;
    }

    // Begin transaction
    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    $ids = array_map(fn($r) => (int)$r['id'], $toDelete);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Delete related background jobs before the flash records (avoids FK conflict
    // if the ON DELETE CASCADE migration has already been applied)
    $jobDel = $pdo->prepare("DELETE FROM sf_jobs WHERE flash_id IN ($placeholders)");
    $jobDel->execute($ids);

    $del = $pdo->prepare("DELETE FROM sf_flashes WHERE id IN ($placeholders)");
    $del->execute($ids);

    // Commit DB changes
    if ($startedTx) {
        $pdo->commit();
    }

    // Remove files from disk
    $imgDirRel  = __DIR__ . '/../../uploads/images/';
    $prevDirRel = __DIR__ . '/../../uploads/previews/';
    $imgDirAlt  = __DIR__ . '/../../img/';
    $gridDirRel = __DIR__ . '/../../uploads/grid_bitmaps/';

    // Collect all unique filenames to check in batches
    $imageFilenames = [];
    $previewFilenames = [];
    $gridFilenames = [];
    
    foreach ($toDelete as $r) {
        foreach (['image_main', 'image_2', 'image_3'] as $k) {
            $fn = $r[$k] ?? null;
            if ($fn) {
                $imageFilenames[$fn] = true;
            }
        }
        
        $preview = $r['preview_filename'] ?? null;
        if ($preview) {
            $previewFilenames[$preview] = true;
        }
        
        $preview2 = $r['preview_filename_2'] ?? null;
        if ($preview2) {
            $previewFilenames[$preview2] = true;
        }

        $grid = $r['grid_bitmap'] ?? null;
        if ($grid) {
            $gridFilenames[$grid] = true;
        }
    }
    
    // Check which image files are safe to delete (not used by other flashes)
    $safeToDeleteImages = [];
    foreach (array_keys($imageFilenames) as $fn) {
        $checkSql = "SELECT COUNT(*) FROM sf_flashes WHERE (image_main = ? OR image_2 = ? OR image_3 = ?) AND id NOT IN ($placeholders)";
        $checkParams = array_merge([$fn, $fn, $fn], $ids);
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute($checkParams);
        
        if ((int)$checkStmt->fetchColumn() === 0) {
            $safeToDeleteImages[$fn] = true;
        }
    }
    
    // Check which preview files are safe to delete (not used by other flashes)
    $safeToDeletePreviews = [];
    foreach (array_keys($previewFilenames) as $fn) {
        $checkSql = "SELECT COUNT(*) FROM sf_flashes WHERE (preview_filename = ? OR preview_filename_2 = ?) AND id NOT IN ($placeholders)";
        $checkParams = array_merge([$fn, $fn], $ids);
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute($checkParams);
        
        if ((int)$checkStmt->fetchColumn() === 0) {
            $safeToDeletePreviews[$fn] = true;
        }
    }

    // Check which grid_bitmap files are safe to delete (not used by other flashes)
    $safeToDeleteGrids = [];
    foreach (array_keys($gridFilenames) as $fn) {
        $checkSql = "SELECT COUNT(*) FROM sf_flashes WHERE grid_bitmap = ? AND id NOT IN ($placeholders)";
        $checkParams = array_merge([$fn], $ids);
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute($checkParams);

        if ((int)$checkStmt->fetchColumn() === 0) {
            $safeToDeleteGrids[$fn] = true;
        }
    }
    
    // Delete files that are safe to delete
    foreach ($toDelete as $r) {
        foreach (['image_main', 'image_2', 'image_3'] as $k) {
            $fn = $r[$k] ?? null;
            if ($fn && isset($safeToDeleteImages[$fn])) {
                // Safe to delete - no other flash uses this file
                $p1 = $imgDirRel . $fn;
                $p2 = $imgDirAlt . $fn;
                if (is_file($p1)) {
                    @unlink($p1);
                }
                if (is_file($p2)) {
                    @unlink($p2);
                }
                // Mark as deleted so we don't try again
                unset($safeToDeleteImages[$fn]);
            }
        }
        
        $preview = $r['preview_filename'] ?? null;
        if ($preview && isset($safeToDeletePreviews[$preview])) {
            // Safe to delete - no other flash uses this file
            $p1 = $prevDirRel . $preview;
            $p2 = $imgDirAlt . $preview;
            if (is_file($p1)) {
                @unlink($p1);
            }
            if (is_file($p2)) {
                @unlink($p2);
            }
            unset($safeToDeletePreviews[$preview]);
        }
        
        $preview2 = $r['preview_filename_2'] ?? null;
        if ($preview2 && isset($safeToDeletePreviews[$preview2])) {
            // Safe to delete - no other flash uses this file
            $p1 = $prevDirRel . $preview2;
            $p2 = $imgDirAlt . $preview2;
            if (is_file($p1)) {
                @unlink($p1);
            }
            if (is_file($p2)) {
                @unlink($p2);
            }
            unset($safeToDeletePreviews[$preview2]);
        }

        $grid = $r['grid_bitmap'] ?? null;
        if ($grid && isset($safeToDeleteGrids[$grid])) {
            // Safe to delete - no other flash uses this file
            $p1 = $gridDirRel . $grid;
            $p2 = __DIR__ . '/../../uploads/' . $grid;
            if (is_file($p1)) {
                @unlink($p1);
            }
            if (is_file($p2)) {
                @unlink($p2);
            }
            unset($safeToDeleteGrids[$grid]);
        }
    }

    // Log deletion event to safetyflash_logs
    $logFlashId = $groupRootId;
    $userId     = $_SESSION['user_id'] ?? null;
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
// Tallennetaan avaimella
$desc = $isRoot
    ? "log_group_deleted|id:{$logFlashId}"
    : "log_version_deleted|id:{$id}";

    // LISÄTTY: Poista lokit kaikille poistetuille versiosta
    $logIds = implode(',', array_fill(0, count($ids), '?'));
    $stmtDelLogs = $pdo->prepare("DELETE FROM safetyflash_logs WHERE flash_id IN ($logIds)");
    $stmtDelLogs->execute($ids);

    // Kirjataan tapahtuma vain jos $logFlashId:tä ei poistettu juuri nyt
    // eikä se ole orvon kieliversion puuttuva juuri-flash.
    if (function_exists('sf_log_event') && !in_array($logFlashId, $ids, true)) {
        $checkExists = $pdo->prepare("SELECT 1 FROM sf_flashes WHERE id = ? LIMIT 1");
        $checkExists->execute([$logFlashId]);
        if ($checkExists->fetchColumn() !== false) {
            try {
                sf_log_event($logFlashId, 'deleted', $desc);
            } catch (Throwable $logErr) {
                error_log('delete.php: sf_log_event failed for flash_id ' . $logFlashId . ': ' . $logErr->getMessage());
            }
        }
    }

    // ========== AUDIT LOG ==========
    $user = sf_current_user();

    sf_audit_log(
        'flash_delete',                 // action
        'flash',                        // target type
        (int)$id,                       // target id (yksittäinen flash; ryhmästä kertoo is_group)
        [
            'title'         => $flashTitle,
            'is_group'      => $isRoot,
            'deleted_count' => count($toDelete),
        ],                              // details
        $user ? (int)$user['id'] : null // user id
    );
    // ================================

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'message' => sf_term('flash_deleted_success', $currentUiLang)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header("Location: {$base}/index.php?page=list&notice=deleted");
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('delete.php ERROR: ' . $e->getMessage());
    
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Virhe poistettaessa tiedotetta.',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    header("Location: {$base}/index.php?page=view&id=" . (int)($id ?? 0) . "&notice=error");
    exit;
}