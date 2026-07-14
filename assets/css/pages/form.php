<?php
// assets/pages/form.php
// THE COMPLETE, UNTRUNCATED, AND CORRECTED FILE
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';
require_once __DIR__ . '/../../app/includes/statuses.php';

$base = rtrim($config['base_url'] ?? '/', '/');

$uiLang = $_SESSION['ui_lang'] ?? 'fi';
$termsData = sf_get_terms_config();
$configLanguages = $termsData['languages'] ?? ['fi'];

if (!in_array($uiLang, $configLanguages, true)) {
    $uiLang = 'fi';
}

// --- Työmaat kannasta (sf_worksites) ---
$worksites = [];

try {
    $worksites = Database::fetchAll(
        "SELECT id, name FROM sf_worksites WHERE is_active = 1 AND show_in_worksite_lists = 1 ORDER BY name ASC"
    );
} catch (Throwable $e) {
    error_log('form.php worksites error: ' . $e->getMessage());
    $worksites = [];
}

// --- Tutkintatiedotteen pohjana olevat julkaistut ensitiedotteet / vaaratilanteet ---
// Haetaan kaikki julkaistut kieliversiot, mutta option value pidetään aina pääversion ID:nä.
// Näin tutkintatiedote päivittää oikean alkuperäisen SafetyFlashin.
// Käyttöliittymä voi tämän jälkeen näyttää valitun kielen mukaisen version ja tarvittaessa alkuperäisen version varalla.
$relatedOptions = [];

try {
    $relatedOptions = Database::fetchAll("
        SELECT
            root.id AS id,
            root.id AS root_id,
            variant.id AS variant_id,
            root.type,
            variant.lang,
            variant.title,
            variant.title_short,
            variant.summary,
            variant.site,
            variant.site_detail,
            variant.description,
            variant.occurred_at,
            variant.image_main,
            variant.image_2,
            variant.image_3,
            variant.annotations_data,
            variant.image1_transform,
            variant.image2_transform,
            variant.image3_transform,
            variant.grid_layout,
            variant.grid_bitmap,
            CASE WHEN variant.id = root.id THEN 1 ELSE 0 END AS is_original
        FROM sf_flashes root
        INNER JOIN sf_flashes variant
            ON variant.id = root.id
            OR variant.translation_group_id = root.id
        WHERE root.state = 'published'
          AND root.type IN ('red', 'yellow')
          AND (root.translation_group_id IS NULL OR root.translation_group_id = root.id)
          AND variant.state = 'published'
          AND variant.type = root.type
        ORDER BY root.occurred_at DESC, is_original DESC, variant.lang ASC
    ");
} catch (Throwable $e) {
    error_log('form.php load related flashes error: ' . $e->getMessage());
}

// --- Load flash for editing if id is provided ---
$editing = false;
$flash   = [];
$editId  = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($editId > 0) {
    try {
        $flash = Database::fetchOne(
            "SELECT * FROM sf_flashes WHERE id = :id LIMIT 1",
            [':id' => $editId]
        );
        if ($flash) {
            $editing = true;
        } else {
            $flash = [];
        }
    } catch (Throwable $e) {
        error_log('form.php load flash error: ' . $e->getMessage());
    }
}

// --- Detect if editing a translation child ---
// A translation child is identified when editing and translation_group_id is set and != id
$isTranslationChild = false;
$sourceFlash = null;

if ($editing && !empty($flash['translation_group_id']) && (int)$flash['translation_group_id'] !== (int)$flash['id']) {
    $isTranslationChild = true;
    
    // Load the source flash (original or parent translation)
    $sourceFlashId = (int)$flash['translation_group_id'];
    try {
        $sourceFlash = Database::fetchOne(
            "SELECT * FROM sf_flashes WHERE id = :id LIMIT 1",
            [':id' => $sourceFlashId]
        );
    } catch (Throwable $e) {
        error_log('form.php load source flash error: ' . $e->getMessage());
    }
}

// Early assignment of $flashLang and $state_val needed for bundle group calculations below.
// These are refined later (language validation against config) but the raw values are
// required here so that $bundleAllMembers and $isInBundle are computed correctly.
$flashLang = $flash['lang'] ?? 'fi';
$state_val  = $flash['state'] ?? '';

// --- Bundle group info ---
// Fetch all other members of the same translation group (for bundle workflow)
$bundleGroupMembers = [];
$bundleGroupId = null;

if ($editing && $editId > 0) {
    $bgRaw = !empty($flash['translation_group_id'])
        ? (int)$flash['translation_group_id']
        : (int)$editId;
    $bundleGroupId = $bgRaw;
    try {
        $pdo = Database::getInstance();
        $bgStmt = $pdo->prepare("
            SELECT id, lang, state, title_short
            FROM sf_flashes
            WHERE (id = :gid OR translation_group_id = :gid2)
              AND id != :current_id
            ORDER BY id ASC
        ");
        $bgStmt->execute([':gid' => $bgRaw, ':gid2' => $bgRaw, ':current_id' => $editId]);
        $bundleGroupMembers = $bgStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('form.php bundle group query error: ' . $e->getMessage());
    }
}

// Build full list: current flash + siblings
$bundleAllMembers = [];
if ($editing && $editId > 0) {
    $bundleAllMembers = array_merge(
        [['id' => $editId, 'lang' => $flashLang, 'state' => $state_val, 'title_short' => $flash['title_short'] ?? '']],
        $bundleGroupMembers
    );
}
$bundleCount = count($bundleAllMembers);

// Languages already used in the group (used to filter available choices)
$bundleUsedLangs = [$flashLang];
foreach ($bundleGroupMembers as $bm) {
    if (!empty($bm['lang']) && !in_array($bm['lang'], $bundleUsedLangs, true)) {
        $bundleUsedLangs[] = $bm['lang'];
    }
}

// Is this flash part of a multi-language bundle in a submittable state?
$isInBundle = $bundleCount > 1
    && in_array($state_val, ['draft', 'request_info', ''], true);

// Check editing lock when loading form for editing
$editingWarning = null;
if ($editing && $editId > 0) {
    $currentUserId = $_SESSION['user_id'] ?? 0;
    
    try {
        $pdo = Database::getInstance();
        $lockStmt = $pdo->prepare("
            SELECT f.editing_user_id, f.editing_started_at,
                   u.first_name, u.last_name
            FROM sf_flashes f
            LEFT JOIN sf_users u ON f.editing_user_id = u.id
            WHERE f.id = ?
        ");
        $lockStmt->execute([$editId]);
        $lockRow = $lockStmt->fetch();
        
        if ($lockRow && $lockRow['editing_user_id'] && 
            (int)$lockRow['editing_user_id'] !== (int)$currentUserId &&
            $lockRow['editing_started_at']) {
            
            $startedTime = strtotime($lockRow['editing_started_at']);
            if ($startedTime === false) {
                // Invalid datetime, skip lock check
                error_log('form.php: Invalid editing_started_at datetime');
            } else {
                $isExpired = (time() - $startedTime) > (15 * 60); // 15 min expiry
                
                if (!$isExpired) {
                    $editorName = trim($lockRow['first_name'] . ' ' . $lockRow['last_name']);
                    $minutesAgo = round((time() - $startedTime) / 60);
                    $editingWarning = [
                        'editor_name' => $editorName,
                        'minutes_ago' => $minutesAgo
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('form.php editing lock check error: ' . $e->getMessage());
    }
}

// Check if user has unfinished drafts
$userDrafts = [];
$currentUser = sf_current_user();
$isAdminUser = $currentUser && (int)($currentUser['role_id'] ?? 0) === 1;

if ($currentUser && !$editing) {
    try {
        $pdo = Database::getInstance();
        $draftStmt = $pdo->prepare("
            SELECT id, flash_type, form_data, updated_at 
            FROM sf_drafts 
            WHERE user_id = ? 
            ORDER BY updated_at DESC
        ");
        $draftStmt->execute([(int)$currentUser['id']]);
        $userDrafts = $draftStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('form.php drafts check error: ' . $e->getMessage());
    }
}
$hasDrafts = !empty($userDrafts);

$flashLang = $flash['lang'] ?? 'fi';

if (!in_array($flashLang, $configLanguages, true)) {
    $flashLang = 'fi';
}
if (!in_array($uiLang, $configLanguages, true)) {
    $uiLang = 'fi';
}

$term = function (string $key) use ($termsData, $uiLang): string {
    $t = $termsData['terms'][$key][$uiLang] ?? $termsData['terms'][$key]['fi'] ?? $key;
    return (string) $t;
};

$sfI18n = [
    'IMAGE_EDIT_MAIN' => $term('IMAGE_EDIT_MAIN'),
    'IMAGE_EDIT_EXTRA_PREFIX' => $term('IMAGE_EDIT_EXTRA_PREFIX'),
    'IMAGE_SAVED' => $term('IMAGE_SAVED'),
    'LABEL_PROMPT' => $term('LABEL_PROMPT'),

    'GRID_LAYOUT_1'  => $term('grid_layout_1'),
    'GRID_LAYOUT_2A' => $term('grid_layout_2a'),
    'GRID_LAYOUT_2B' => $term('grid_layout_2b'),
    'GRID_LAYOUT_3A' => $term('grid_layout_3a'),
    'GRID_LAYOUT_3B' => $term('grid_layout_3b'),
    'GRID_LAYOUT_3C' => $term('grid_layout_3c'),

    'GRID_HELP' => $term('grid_help'),
    'GRID_SELECTED' => $term('GRID_SELECTED'),
    'GRID_SELECT_HINT' => $term('GRID_SELECT_HINT'),
    'EDITOR_HELP_PLACE' => $term('img_edit_help'),

    'processing_flash' => $term('processing_flash'),
];
// --- Esitäytettävät arvot ---
$title            = $flash['title'] ?? '';
$title_short      = $flash['title_short'] ?? ($flash['summary'] ?? '');
$short_text       = $title_short;
$summary          = $flash['summary'] ?? '';
$description      = $flash['description'] ?? '';
$root_causes      = $flash['root_causes'] ?? '';
$actions          = $flash['actions'] ?? '';
$worksite_val     = $flash['site'] ?? '';
$site_detail_val  = $flash['site_detail'] ?? '';
$event_date_val   = !empty($flash['occurred_at']) ? date('Y-m-d\TH:i', strtotime($flash['occurred_at'])) : '';
$type_val         = $flash['type'] ?? '';
$state_val        = $flash['state'] ?? '';
$preview_filename = $flash['preview_filename'] ?? '';
$image_main       = $flash['image_main'] ?? '';

// Mahdolliset transform-arvot (JSON) kolmelle kuvalle
$image1_transform = $flash['image1_transform'] ?? '';
$image2_transform = $flash['image2_transform'] ?? '';
$image3_transform = $flash['image3_transform'] ?? '';

// initial step param (optional)
$initialStep = isset($_GET['step']) ? (int) $_GET['step'] : 1;

// Load existing body-part selections when editing a red-, yellow-, or green-type incident
$existing_body_parts = [];
if ($editing && $editId > 0 && in_array($flash['type'] ?? '', ['red', 'yellow', 'green'], true)) {
    try {
        $existing_body_parts = Database::fetchAll(
            "SELECT bp.svg_id
             FROM incident_body_part ibp
             JOIN body_parts bp ON bp.id = ibp.body_part_id
             WHERE ibp.incident_id = :id
             ORDER BY bp.sort_order",
            ['id' => $editId]
        );
        $existing_body_parts = array_column($existing_body_parts, 'svg_id');
    } catch (Throwable $e) {
        error_log('form.php load body parts error: ' . $e->getMessage());
        $existing_body_parts = [];
    }
}

// Show "saved" toast when returning after draft save
$showSavedNotice = isset($_GET['saved']) && $_GET['saved'] === '1';

// Kuvapolku muokkaustilassa (tarkistaa myös kuvapankki-hakemiston)
$getImageUrl = function ($filename) use ($base) {
    $filename = is_string($filename) ? basename($filename) : '';
    if (empty($filename)) {
        return "{$base}/assets/img/camera-placeholder.png";
    }
    $path = "uploads/images/{$filename}";
    if (file_exists(__DIR__ . "/../../{$path}")) {
        return "{$base}/{$path}";
    }
    $libraryPath = "uploads/library/{$filename}";
    if (file_exists(__DIR__ . "/../../{$libraryPath}")) {
        return "{$base}/{$libraryPath}";
    }
    $oldPath = "img/{$filename}";
    if (file_exists(__DIR__ . "/../../{$oldPath}")) {
        return "{$base}/{$oldPath}";
    }
    return "{$base}/assets/img/camera-placeholder.png";
};

// Hae kuvapankin kuvien ID:t muokkaustilaa varten (UI-valinnan palautus)
$libraryImageIds = [1 => 0, 2 => 0, 3 => 0];
if ($editing) {
    $slotFilenames = [
        1 => $flash['image_main'] ?? '',
        2 => $flash['image_2'] ?? '',
        3 => $flash['image_3'] ?? '',
    ];
    foreach ($slotFilenames as $slot => $filename) {
        if ($filename !== '' && strpos($filename, 'lib_') === 0) {
            try {
                $libRow = Database::fetchOne(
                    "SELECT id FROM sf_image_library WHERE filename = :filename LIMIT 1",
                    [':filename' => $filename]
                );
                if ($libRow) {
                    $libraryImageIds[$slot] = (int)$libRow['id'];
                }
            } catch (Throwable $e) {
                error_log('form.php library image lookup error: ' . $e->getMessage());
            }
        }
    }
}
?>
<?php if ($hasDrafts && !$editing): ?>
<div id="sfDraftRecoveryOverlay" class="sf-draft-overlay">
    <div class="sf-draft-modal">
        <h2><?= htmlspecialchars(sf_term('draft_recovery_title', $uiLang)) ?></h2>
        <p><?= htmlspecialchars(sf_term('draft_recovery_message', $uiLang)) ?></p>
        
        <div class="sf-draft-list">
            <?php foreach ($userDrafts as $draft): 
                $draftData = json_decode($draft['form_data'], true);
                $draftType = $draft['flash_type'] ?? 'unknown';
                // Normalize: remove possible 'type_' prefix
                $draftType = preg_replace('/^type_/', '', $draftType);
                $draftDate = date('d.m.Y H:i', strtotime($draft['updated_at']));
            ?>
            <div class="sf-draft-item" data-draft-id="<?= (int)$draft['id'] ?>">
                <div class="sf-draft-info">
                    <span class="sf-draft-type sf-type-<?= in_array($draftType, ['red', 'yellow', 'green'], true) ? htmlspecialchars($draftType) : 'unknown' ?>">
<?php 
$typeLabels = [
    'red' => sf_term('first_release', $uiLang) ?: 'Ensitiedote',
    'yellow' => sf_term('dangerous_situation', $uiLang) ?: 'Vaaratilanne', 
    'green' => sf_term('investigation_report', $uiLang) ?: 'Tutkintatiedote',
];
$typeLabel = $typeLabels[$draftType] ?? ucfirst($draftType);
?>
<?= htmlspecialchars($typeLabel) ?>
                    </span>
                    <span class="sf-draft-date"><?= htmlspecialchars($draftDate) ?></span>
                </div>
                <div class="sf-draft-actions">
                    <button type="button" class="sf-btn sf-btn-primary sf-draft-continue" 
                            data-draft-id="<?= (int)$draft['id'] ?>">
                        <?= htmlspecialchars(sf_term('draft_continue', $uiLang)) ?>
                    </button>
                    <button type="button" class="sf-btn sf-btn-secondary sf-draft-discard"
                            data-draft-id="<?= (int)$draft['id'] ?>">
                        <?= htmlspecialchars(sf_term('draft_discard', $uiLang)) ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="sf-draft-new">
            <button type="button" class="sf-btn sf-btn-outline" id="sfDraftStartNew">
                <?= htmlspecialchars(sf_term('draft_start_new', $uiLang)) ?>
            </button>
        </div>
    </div>
</div>
<script>
window.SF_USER_DRAFTS = <?= json_encode($userDrafts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php endif; ?>

<?php if ($editing && $editId > 0): ?>
<script>
window.SF_FLASH_ID = <?= (int)$editId ?>;
</script>
<?php endif; ?>

<?php if ($editingWarning): ?>
<div id="editingWarningBanner" class="sf-editing-warning">
    <div class="sf-editing-warning-content">
        <img src="<?= $base ?>/assets/img/icons/warning.svg" alt="Warning" class="sf-editing-warning-icon" style="width: 24px; height: 24px; color: #ff9800;">
        <span class="sf-editing-warning-text">
            <?= htmlspecialchars($editingWarning['editor_name']) ?> 
            <?= htmlspecialchars(sf_term('editing_this_flash', $uiLang) ?? 'muokkaa tätä tiedotetta') ?>
            (<?= htmlspecialchars(sf_term('started', $uiLang) ?? 'aloitettu') ?> 
            <?= (int)$editingWarning['minutes_ago'] ?> min <?= htmlspecialchars(sf_term('ago', $uiLang) ?? 'sitten') ?>)
        </span>
        <div class="sf-editing-warning-actions">
            <button type="button" class="sf-btn sf-btn-warning" onclick="continueEditing()">
                <?= htmlspecialchars(sf_term('continue_anyway', $uiLang) ?? 'Jatka silti') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-secondary" onclick="cancelEditing()">
                <?= htmlspecialchars(sf_term('btn_cancel', $uiLang) ?? 'Peruuta') ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isTranslationChild): ?>
<div class="sf-translation-mode-banner" style="background-color: #e3f2fd; border-left: 4px solid #2196f3; padding: 16px; margin-bottom: 20px; border-radius: 4px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <img src="<?= $base ?>/assets/img/icons/info.svg" alt="Information" style="width: 24px; height: 24px; flex-shrink: 0;">
        <div>
            <strong style="color: #1976d2; font-size: 16px;">
                <?= htmlspecialchars(sf_term('translation_mode_title', $uiLang) ?? 'Muokkaat tämän SafetyFlashin kieliversiota', ENT_QUOTES, 'UTF-8') ?>
            </strong>
            <p style="margin: 4px 0 0 0; color: #424242; font-size: 14px;">
                <?php 
                $supportedLangs = [
                    'fi' => 'Suomi',
                    'sv' => 'Ruotsi', 
                    'en' => 'Englanti',
                    'it' => 'Italia',
                    'el' => 'Kreikka',
                ];
                $langLabel = $supportedLangs[$flashLang] ?? strtoupper($flashLang);
                
                // Display type with emoji
                $typeEmojis = [
                    'red' => '🔴',
                    'yellow' => '🟡',
                    'green' => '🟢'
                ];
                $typeLabels = [
                    'red' => 'Ensitiedote',
                    'yellow' => 'Vaaratilanne',
                    'green' => 'Tutkintatiedote'
                ];
                
                $typeEmoji = $typeEmojis[$sourceFlash['type'] ?? ''] ?? '';
                $typeLabel = $typeLabels[$sourceFlash['type'] ?? ''] ?? ($sourceFlash['type'] ?? '');
                
                echo htmlspecialchars(sf_term('translation_mode_message', $uiLang) ?? 'Luot kieliversiota kielelle', ENT_QUOTES, 'UTF-8');
                ?>: <strong><?= htmlspecialchars($langLabel) ?></strong>
                <?php if ($sourceFlash): ?>
                    <br>
                    <span style="color: #666; font-size: 13px;">
                        <?= htmlspecialchars(sf_term('source_flash_from_label', $uiLang) ?: 'Tiedotteesta', ENT_QUOTES, 'UTF-8') ?>: <strong>"<?= htmlspecialchars($sourceFlash['title'] ?? '') ?>"</strong>
                        (ID #<?= (int)$sourceFlash['id'] ?>, <?= $typeEmoji ?> <?= htmlspecialchars($typeLabel) ?>, <?= htmlspecialchars($sourceFlash['site'] ?? '') ?>)
                    </span>
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<form
  id="sf-form"
  method="post"
  action="<?php echo $base; ?>/app/api/save_flash.php"
  class="sf-form"
  enctype="multipart/form-data"
  novalidate
>
  <?= sf_csrf_field() ?>
  <?php if ($editing): ?>
    <input type="hidden" name="id" value="<?= (int) $editId ?>">
  <?php endif; ?>
  <?php if ($isTranslationChild): ?>
    <input type="hidden" name="is_translation_child" value="1">
    <input type="hidden" name="lang" value="<?= htmlspecialchars($flash['lang'] ?? 'fi', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="worksite" value="<?= htmlspecialchars($worksite_val, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <input type="hidden" id="initialStep" value="<?= (int) $initialStep ?>">
  
  <!-- State field for request_info resubmission detection -->
  <?php if ($editing && $state_val === 'request_info'): ?>
  <input type="hidden" name="state" value="request_info">
  <?php endif; ?>
  
  <!-- Related flash ID tutkintatiedotteelle (päivittää alkuperäisen) -->
  <input type="hidden" id="sf-related-flash-id" value="<?= htmlspecialchars($flash['related_flash_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

  <!-- Modern Navigable Progress Bar (NO wrapper div) -->
  <nav class="sf-form-progress" aria-label="<?= htmlspecialchars(sf_term('form_progress_label', $uiLang) ?: 'Lomakkeen vaiheet', ENT_QUOTES, 'UTF-8') ?>">
      <div class="sf-form-progress__track" role="progressbar" aria-valuenow="<?= (int) $initialStep ?>" aria-valuemin="1" aria-valuemax="6">
        <div class="sf-form-progress__fill" id="sfProgressFill"></div>
      </div>
      <div class="sf-form-progress__steps">
        <button type="button" class="sf-form-progress__step" data-step="1" title="<?= htmlspecialchars(sf_term('step1_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step1_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
          <span class="sf-form-progress__number" aria-hidden="true" data-step-num="1"></span>
          <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step1_short', $uiLang) ?: 'Tyyppi', ENT_QUOTES, 'UTF-8') ?></span>
        </button>
        <button type="button" class="sf-form-progress__step" data-step="2" title="<?= htmlspecialchars(sf_term('step2_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step2_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
          <span class="sf-form-progress__number" aria-hidden="true" data-step-num="2"></span>
          <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step2_short', $uiLang) ?: 'Konteksti', ENT_QUOTES, 'UTF-8') ?></span>
        </button>
        <button type="button" class="sf-form-progress__step" data-step="3" title="<?= htmlspecialchars(sf_term('step3_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step3_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
          <span class="sf-form-progress__number" aria-hidden="true" data-step-num="3"></span>
          <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step3_short', $uiLang) ?: 'Sisältö', ENT_QUOTES, 'UTF-8') ?></span>
        </button>
        <button type="button" class="sf-form-progress__step" data-step="4" title="<?= htmlspecialchars(sf_term('step4_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step4_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
          <span class="sf-form-progress__number" aria-hidden="true" data-step-num="4"></span>
          <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step4_short', $uiLang) ?: 'Kuvat', ENT_QUOTES, 'UTF-8') ?></span>
        </button>
        <button type="button" class="sf-form-progress__step" data-step="5" title="<?= htmlspecialchars(sf_term('step5_heading', $uiLang) ?: 'Asettelu', ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step5_heading', $uiLang) ?: 'Asettelu', ENT_QUOTES, 'UTF-8') ?>">
          <span class="sf-form-progress__number" aria-hidden="true" data-step-num="5"></span>
          <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step5_short', $uiLang) ?: 'Asettelu', ENT_QUOTES, 'UTF-8') ?></span>
        </button>
        <button type="button" class="sf-form-progress__step" data-step="6" title="<?= htmlspecialchars(sf_term('step6_heading', $uiLang) ?: 'Esikatselu', ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step6_heading', $uiLang) ?: 'Esikatselu', ENT_QUOTES, 'UTF-8') ?>">
          <span class="sf-form-progress__number" aria-hidden="true" data-step-num="6"></span>
          <span class="sf-form-progress__label">
            <?php if ($isTranslationChild): ?>
              <?= htmlspecialchars(sf_term('preview_label', $uiLang) ?: 'Esikatselu', ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
              <?= htmlspecialchars(sf_term('step6_short', $uiLang) ?: 'Lähetä', ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
          </span>
        </button>
      </div>
      <!-- Close (X) button -->
      <button type="button" id="sfFormCloseBtn" class="sf-form-progress__close"
              aria-label="<?= htmlspecialchars(sf_term('btn_close_form', $uiLang) ?: 'Sulje lomake', ENT_QUOTES, 'UTF-8') ?>">
          <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <line x1="4.5" y1="4.5" x2="13.5" y2="13.5"/>
              <line x1="13.5" y1="4.5" x2="4.5" y2="13.5"/>
          </svg>
      </button>
    </nav>

  <!-- VAIHE 1: tyyppivalinta ja kieli (TYPE FIRST) -->
  <div class="sf-step-content active" data-step="1">
    <h2><?= htmlspecialchars(sf_term('step1_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
    
    <!-- Tyyppivalinta (NOW FIRST) -->
    <h3><?= htmlspecialchars(sf_term('type_selection_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>

    <div class="sf-type-selection" role="radiogroup" aria-label="Valitse tiedotteen tyyppi">

      <!-- RED -->
      <label class="sf-type-box" data-type="red">
        <input type="radio" name="type" value="red" <?= $type_val === 'red' ? 'checked' : '' ?>>
        <div class="sf-type-box-content">
          <img src="<?= $base ?>/assets/img/icons/type-red.svg" alt="" class="sf-type-icon" aria-hidden="true">
          <div class="sf-type-text">
            <h4 class="sf-type-title">
              <?= htmlspecialchars(sf_term('first_release', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h4>
            <p><?= htmlspecialchars(sf_term('type_red_desc', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>
        <img src="<?= $base ?>/assets/img/icons/checkmark_icon.png" alt="Valittu" class="sf-type-checkmark">
      </label>

      <!-- YELLOW -->
      <label class="sf-type-box" data-type="yellow">
        <input type="radio" name="type" value="yellow" <?= $type_val === 'yellow' ? 'checked' : '' ?>>
        <div class="sf-type-box-content">
          <img src="<?= $base ?>/assets/img/icons/type-yellow.svg" alt="" class="sf-type-icon" aria-hidden="true">
          <div class="sf-type-text">
            <h4 class="sf-type-title">
              <?= htmlspecialchars(sf_term('dangerous_situation', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h4>
            <p><?= htmlspecialchars(sf_term('type_yellow_desc', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>
        <img src="<?= $base ?>/assets/img/icons/checkmark_icon.png" alt="Valittu" class="sf-type-checkmark">
      </label>

      <!-- GREEN -->
      <label class="sf-type-box" data-type="green">
        <input type="radio" name="type" value="green" <?= $type_val === 'green' ? 'checked' : '' ?>>
        <div class="sf-type-box-content">
          <img src="<?= $base ?>/assets/img/icons/type-green.svg" alt="" class="sf-type-icon" aria-hidden="true">
          <div class="sf-type-text">
            <h4 class="sf-type-title">
              <?= htmlspecialchars(sf_term('investigation_report', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h4>
            <p><?= htmlspecialchars(sf_term('type_green_desc', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>
        <img src="<?= $base ?>/assets/img/icons/checkmark_icon.png" alt="Valittu" class="sf-type-checkmark">
      </label>

    </div>

    <hr class="sf-divider" id="sf-lang-divider">

    <!-- Kielivalinta (NOW SECOND, after type) -->
    <div class="sf-lang-selection" id="sf-lang-selection">
      <label class="sf-label"><?= htmlspecialchars(sf_term('lang_selection_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
      <div class="sf-lang-options">
<?php
        $langOptions = [
            'fi' => ['label' => 'Suomi',    'flag' => 'finnish-flag.svg'],
            'sv' => ['label' => 'Svenska',  'flag' => 'swedish-flag.svg'],
            'en' => ['label' => 'English',  'flag' => 'english-flag.png'],
            'it' => ['label' => 'Italiano', 'flag' => 'italy-flag.svg'],
            'el' => ['label' => 'Ελληνικά', 'flag' => 'greek-flag.svg'],
        ];
        $selectedLang = $editing ? ($flash['lang'] ?? '') : '';
        foreach ($langOptions as $langCode => $langData):
        ?>
          <label class="sf-lang-box" data-lang="<?php echo $langCode; ?>">
            <input type="radio" name="lang" value="<?php echo $langCode; ?>" <?php echo $selectedLang === $langCode ? 'checked' : ''; ?> <?= $isTranslationChild ? 'disabled' : '' ?>>
            <div class="sf-lang-box-content">
              <img src="<?php echo $base; ?>/assets/img/<?php echo $langData['flag']; ?>" alt="<?php echo $langData['label']; ?>" class="sf-lang-flag">
              <span class="sf-lang-label"><?php echo htmlspecialchars($langData['label']); ?></span>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="sf-help-text"><?= htmlspecialchars(sf_term('lang_selection_help', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <!-- Vaihe 1 napit (alhaalla) -->
<div class="sf-step-actions sf-step-actions-bottom">
  <button
    type="button"
    id="sfNext"
    class="sf-btn sf-btn-primary sf-next-btn disabled"
    disabled
    aria-disabled="true"
  >
    <?= htmlspecialchars(sf_term('btn_next', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
  </button>
</div>
  </div>

  <!-- VAIHE 2: konteksti -->
  <div class="sf-step-content" data-step="2">
<h2><?= htmlspecialchars(sf_term('step2_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>

<div id="sf-step2-incident" class="sf-step2-section">
  <div class="sf-investigation-source-card">
    <div class="sf-investigation-source-header">
      <h3 class="sf-investigation-source-title">
        <?= htmlspecialchars(sf_term('investigation_source_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </h3>
      <p class="sf-investigation-source-help">
        <?= htmlspecialchars(sf_term('investigation_source_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </p>
    </div>

    <div class="sf-investigation-source-tabs" role="radiogroup" aria-label="<?= htmlspecialchars(sf_term('investigation_source_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
      <label class="sf-investigation-source-tab is-selected" id="sf-investigation-source-base-tab" for="sf-investigation-source-base">
        <input type="radio" id="sf-investigation-source-base" name="investigation_source_mode" value="base" checked>
        <span class="sf-investigation-source-tab-dot"></span>
        <span class="sf-investigation-source-tab-text">
          <span class="sf-investigation-source-tab-title"><?= htmlspecialchars(sf_term('investigation_source_base_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
          <span class="sf-investigation-source-tab-description"><?= htmlspecialchars(sf_term('investigation_source_base_help', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </span>
      </label>

      <label class="sf-investigation-source-tab" id="sf-investigation-source-standalone-tab" for="sf-standalone-investigation">
        <input type="checkbox" id="sf-standalone-investigation" name="standalone_investigation" value="1">
        <span class="sf-investigation-source-tab-dot"></span>
        <span class="sf-investigation-source-tab-text">
          <span class="sf-investigation-source-tab-title"><?= htmlspecialchars(sf_term('standalone_investigation_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
          <span class="sf-investigation-source-tab-description"><?= htmlspecialchars(sf_term('standalone_investigation_help', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </span>
      </label>
    </div>

    <div class="sf-investigation-source-body">
      <div class="sf-field sf-related-flash-field" id="sf-related-flash-field">
        <label for="sf-related-flash" class="sf-label">
          <?= htmlspecialchars(sf_term('related_flash_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </label>
        <select
          name="related_flash_id"
          id="sf-related-flash"
          class="sf-select sf-related-native-select"
          data-placeholder="<?= htmlspecialchars(sf_term('related_flash_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
          data-search-placeholder="<?= htmlspecialchars(sf_term('related_flash_search_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
          data-fallback-badge="<?= htmlspecialchars(sf_term('related_flash_fallback_badge', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
          data-empty-text="<?= htmlspecialchars(sf_term('related_flash_empty_text', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
          data-flag-base-url="<?= htmlspecialchars($base . '/assets/img/flags', ENT_QUOTES, 'UTF-8') ?>"
        >
          <option value="">
            <?= htmlspecialchars(sf_term('related_flash_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>
          </option>
          <?php
              $langFlagFiles = [
                  'fi' => 'finnish-flag.svg',
                  'sv' => 'swedish-flag.svg',
                  'en' => 'english-flag.png',
                  'it' => 'italy-flag.svg',
                  'el' => 'greek-flag.svg',
              ];

              foreach ($relatedOptions as $opt):
              $optDate = !empty($opt['occurred_at'])
                  ? date('d.m.Y', strtotime($opt['occurred_at']))
                  : '–';

              $optSite  = $opt['site'] ?? '–';
              $optTitle = $opt['title'] ?? $opt['title_short'] ?? '–';
              $colorDot = ($opt['type'] === 'red') ? '🔴' : '🟡';

              $optLang = $opt['lang'] ?? 'fi';
              $langFlagFile = $langFlagFiles[$optLang] ?? 'finnish-flag.svg';

              $optLabel = "{$colorDot} {$optDate} – {$optSite} – {$optTitle}";
          ?>
            <option
              value="<?= (int) $opt['root_id'] ?>"
              data-root-id="<?= (int) $opt['root_id'] ?>"
              data-variant-id="<?= (int) $opt['variant_id'] ?>"
              data-lang="<?= htmlspecialchars($opt['lang'] ?? 'fi', ENT_QUOTES, 'UTF-8') ?>"
              data-lang-flag="<?= htmlspecialchars($langFlagFile, ENT_QUOTES, 'UTF-8') ?>"
              data-type="<?= htmlspecialchars($opt['type'] ?? 'yellow', ENT_QUOTES, 'UTF-8') ?>"
              data-is-original="<?= ((int)($opt['is_original'] ?? 0) === 1) ? '1' : '0' ?>"
              data-site="<?= htmlspecialchars($opt['site'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-site-detail="<?= htmlspecialchars($opt['site_detail'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-date="<?= htmlspecialchars($opt['occurred_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-title="<?= htmlspecialchars($opt['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-title-short="<?= htmlspecialchars($opt['title_short'] ??  '', ENT_QUOTES, 'UTF-8') ?>"
              data-description="<?= htmlspecialchars($opt['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-image-main="<?= htmlspecialchars($opt['image_main'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-image-2="<?= htmlspecialchars($opt['image_2'] ??  '', ENT_QUOTES, 'UTF-8') ?>"
              data-image-3="<?= htmlspecialchars($opt['image_3'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-annotations-data="<?= htmlspecialchars($opt['annotations_data'] ?? '{}', ENT_QUOTES, 'UTF-8') ?>"
              data-image1-transform="<?= htmlspecialchars($opt['image1_transform'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-image2-transform="<?= htmlspecialchars($opt['image2_transform'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-image3-transform="<?= htmlspecialchars($opt['image3_transform'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-grid-layout="<?= htmlspecialchars($opt['grid_layout'] ?? 'grid-1', ENT_QUOTES, 'UTF-8') ?>"
              data-grid-bitmap="<?= htmlspecialchars($opt['grid_bitmap'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              <?= (isset($flash['related_flash_id']) && (int) $flash['related_flash_id'] === (int) $opt['root_id']) ? 'selected' :  '' ?>
            >
              <?= htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="sf-help-text" id="sf-related-flash-help">
          <?= htmlspecialchars(sf_term('related_flash_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>

      <div class="sf-standalone-original-type-wrapper" id="sf-standalone-original-type-wrapper" aria-hidden="true">
        <div class="sf-standalone-original-type-inner">
          <p class="sf-standalone-original-type-label">
            <?= htmlspecialchars(sf_term('standalone_original_type_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
          </p>
          <p class="sf-standalone-original-type-help">
            <?= htmlspecialchars(sf_term('standalone_original_type_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
          </p>
          <div class="sf-original-type-pills" role="radiogroup" aria-label="<?= htmlspecialchars(sf_term('standalone_original_type_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
            <?php
            $standaloneTypeMap = [
                'red'    => 'first_release',
                'yellow' => 'dangerous_situation',
            ];
            foreach ($standaloneTypeMap as $typeCode => $termKey):
            ?>
<label class="sf-original-type-pill sf-original-type-pill--<?= $typeCode ?>">
  <input
    type="radio"
    name="standalone_original_type"
    value="<?= htmlspecialchars($typeCode, ENT_QUOTES, 'UTF-8') ?>"
    required
  >
  <span class="sf-original-type-pill-dot" aria-hidden="true"></span>
  <span class="sf-original-type-pill-label"><?= htmlspecialchars(sf_term($termKey, $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
</label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

    <!-- Alkuperäisen tiedotteen kompakti näkymä (näkyy kun related flash valittu) -->
    <div id="sf-original-flash-preview" class="sf-original-flash-compact hidden">
      <img src="<?= $base ?>/assets/img/icon-yellow.png" alt="" class="sf-original-icon" id="sf-original-icon">
      <div class="sf-original-info">
        <span class="sf-original-title" id="sf-original-title">--</span>
        <span class="sf-original-meta">
          <span id="sf-original-site">--</span>
          <span id="sf-original-date">--</span>
        </span>
      </div>
    </div>

    <!-- Tutkintatiedotteen osio (ei tarvitse erillistä info-tekstiä) -->
    <div id="sf-step2-investigation-worksite" class="sf-step2-section"></div>

<!-- Työmaa ja päivämäärä - käytetään KAIKILLE tyypeille (red, yellow, green) -->
<!-- For green type (investigation), hidden by default until user selects base flash or standalone -->
<!-- In edit mode, always show the fields -->
<div id="sf-step2-worksite" class="sf-step2-section sf-investigation-fields<?= ($type_val === 'green' && !$editing) ? ' hidden' : '' ?>">
  <div class="sf-field-row">
    <div class="sf-field sf-worksite-field">
<label for="sf-worksite" class="sf-label">
  <?= htmlspecialchars(sf_term('site_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
</label>

      <select
        name="worksite"
        id="sf-worksite"
        class="sf-select sf-worksite-native"
        <?= $isTranslationChild ? 'disabled' : '' ?>
      >
        <option value="">
          <?= htmlspecialchars(sf_term('worksite_select_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>

        <?php
        $currentWorksiteExistsInList = false;
        foreach ($worksites as $site) {
            if ((string)$worksite_val === (string)$site['name']) {
                $currentWorksiteExistsInList = true;
                break;
            }
        }
        ?>

        <?php if ($isTranslationChild && $worksite_val !== '' && !$currentWorksiteExistsInList): ?>
          <option value="<?= htmlspecialchars($worksite_val, ENT_QUOTES, 'UTF-8') ?>" selected>
            <?= htmlspecialchars($worksite_val, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endif; ?>

        <?php foreach ($worksites as $site): ?>
          <option
            value="<?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?>"
            <?= $worksite_val === $site['name'] ? 'selected' : '' ?>
          >
            <?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <?php if ($isTranslationChild): ?>
        <div
          class="sf-worksite-trigger sf-worksite-trigger-static"
          aria-disabled="true"
        >
          <span
            class="sf-worksite-trigger-text<?= $worksite_val !== '' ? ' has-value' : '' ?>"
          >
            <?= htmlspecialchars($worksite_val !== '' ? $worksite_val : sf_term('worksite_select_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>
      <?php else: ?>
        <div
          id="sf-worksite-picker"
          class="sf-worksite-picker"
          data-disabled="0"
        >
          <button
            type="button"
            id="sf-worksite-trigger"
            class="sf-worksite-trigger"
            aria-expanded="false"
            aria-controls="sf-worksite-chip-panel"
          >
            <span
              id="sf-worksite-trigger-text"
              class="sf-worksite-trigger-text<?= $worksite_val !== '' ? ' has-value' : '' ?>"
              data-placeholder="<?= htmlspecialchars(sf_term('worksite_select_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
            >
              <?= htmlspecialchars($worksite_val !== '' ? $worksite_val : sf_term('worksite_select_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </span>

            <svg class="sf-worksite-trigger-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
              <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>

          <div
            id="sf-worksite-chip-panel"
            class="sf-worksite-chip-panel"
          >
            <div class="sf-search-input-wrap sf-worksite-search-wrap">
              <svg class="sf-search-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M21 21L16.65 16.65M19 11C19 15.4183 15.4183 19 11 19C6.58172 19 3 15.4183 3 11C3 6.58172 6.58172 3 11 3C15.4183 3 19 6.58172 19 11Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>

              <input
                type="text"
                id="sf-worksite-search"
                class="sf-search-input sf-worksite-search"
                placeholder="<?= htmlspecialchars(sf_term('comms_search_worksites', $uiLang) ?: 'Hae työmaata...', ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="off"
              >

              <button
                type="button"
                id="sfClearWorksiteSearch"
                class="sf-clear-search sf-worksite-clear"
                hidden
                aria-label="<?= htmlspecialchars(sf_term('btn_clear', $uiLang) ?: 'Tyhjennä', ENT_QUOTES, 'UTF-8') ?>"
              >×</button>
            </div>

            <div
              id="sf-worksite-chip-list"
              class="sf-worksite-chip-list"
              role="listbox"
              aria-label="<?= htmlspecialchars(sf_term('site_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
            >
              <?php foreach ($worksites as $site): ?>
                <?php
                  $siteName = (string) $site['name'];
                  $isSelected = $worksite_val === $siteName;
                ?>
                <button
                  type="button"
                  class="sf-worksite-chip-option<?= $isSelected ? ' is-selected' : '' ?>"
                  data-value="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>"
                  data-search="<?= htmlspecialchars(mb_strtolower($siteName, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>"
                  aria-pressed="<?= $isSelected ? 'true' : 'false' ?>"
                >
                  <span class="sf-worksite-chip-dot" aria-hidden="true"></span>
                  <span class="sf-worksite-chip-text"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></span>
                </button>
              <?php endforeach; ?>
            </div>

            <div
              id="sf-worksite-chip-empty"
              class="sf-worksite-chip-empty"
              hidden
            >
              <?= htmlspecialchars(sf_term('comms_no_worksites', $uiLang) ?: 'Ei työmaita', ENT_QUOTES, 'UTF-8') ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
          </div>

    <div class="sf-field sf-site-detail-field">
      <label for="sf-site-detail" class="sf-label">
        <?= htmlspecialchars(sf_term('site_detail_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </label>

      <div class="sf-site-detail-suggest">
<div class="sf-site-detail-input-wrap">
  <input
    type="text"
    name="site_detail"
    id="sf-site-detail"
    class="sf-input sf-site-detail-input"
    placeholder="<?= htmlspecialchars(sf_term('site_detail_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
    value="<?= htmlspecialchars($site_detail_val, ENT_QUOTES, 'UTF-8') ?>"
    autocomplete="off"
    aria-autocomplete="list"
    aria-expanded="false"
    aria-controls="sf-site-detail-suggestions"
  >

  <button
    type="button"
    id="sf-site-detail-suggestions-toggle"
    class="sf-site-detail-suggestions-toggle"
    aria-label="<?= htmlspecialchars(sf_term('site_detail_suggestions_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
  >
    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/chevron-down.svg" alt="">
  </button>
</div>

<div
  id="sf-site-detail-suggestions"
  class="sf-site-detail-suggestions"
  data-title="<?= htmlspecialchars(sf_term('site_detail_suggestions_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
  hidden
></div>
      </div>

      <div
        id="sf-site-detail-suggestions-empty"
        class="sf-site-detail-suggestions-empty"
        hidden
      >
        Ei aiempia tarkkoja sijainteja tältä työmaalta.
      </div>
    </div>
  </div>

  <div class="sf-field-row">
    <div class="sf-field">
      <label for="sf-date" class="sf-label">
        <?= htmlspecialchars(sf_term('when_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </label>
      <input
        type="datetime-local"
        name="event_date"
        id="sf-date"
        class="sf-input"
        required
        max="<?= date('Y-m-d\TH:i') ?>"
        step=""
        value="<?= htmlspecialchars($event_date_val, ENT_QUOTES, 'UTF-8') ?>"
        <?= $isTranslationChild ? 'readonly' : '' ?>
      >
    </div>
  </div>

  <p class="sf-help-text">
    <?= htmlspecialchars(sf_term('step2_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
  </p>
</div>

    <!-- Vaihe 2 napit -->
    <div class="sf-step-actions sf-step-actions-bottom">
<button type="button" id="sfPrev" class="sf-btn sf-btn-secondary sf-prev-btn">
  <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
</button>
<button type="button" class="sf-btn sf-btn-primary sf-next-btn">
  <?= htmlspecialchars(sf_term('btn_next', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
</button>
    </div>
  </div>

  <!-- VAIHE 3: itse sisältö -->
  <div class="sf-step-content" data-step="3">
    <h2><?= htmlspecialchars(sf_term('step3_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>

    <div class="sf-field">
      <label for="sf-title" class="sf-label">
        <?= htmlspecialchars(sf_term('title_internal_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </label>
      <input
        type="text"
        name="title"
        id="sf-title"
        class="sf-input"
        required
        placeholder="<?= htmlspecialchars(sf_term('title_internal_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
      >
    </div>

    <div class="sf-field">
      <label for="sf-short-text" class="sf-label">
        <?= htmlspecialchars(sf_term('short_title_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </label>
      <textarea
        name="short_text"
        id="sf-short-text"
        class="sf-textarea"
        rows="2"
        required
        maxlength="85"
        placeholder="<?= htmlspecialchars(sf_term('short_text_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
      ><?= htmlspecialchars($short_text, ENT_QUOTES, 'UTF-8') ?></textarea>
      <p class="sf-char-count"><span id="sf-short-text-count">0</span>/85</p>
    </div>

    <div class="sf-field">
      <label for="sf-description" class="sf-label">
        <?= htmlspecialchars(sf_term('description_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </label>
      <textarea
        name="description"
        id="sf-description"
        class="sf-textarea"
        rows="8"
        required
        placeholder="<?= htmlspecialchars(sf_term('description_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
      ><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
      <p class="sf-char-count"><span id="sf-description-count">0</span>/950</p>
    </div>

    <!-- Loukkaantuneet kehonosat — kaikille tiedotetyypeille (red, yellow, green) -->
    <div id="sf-injury-section" class="hidden">
      <div class="sf-injury-btn-row">
<button type="button" id="sfBodyMapOpenBtn" class="sf-btn-body-map" data-modal-open="#sfBodyMapModal">
  <span
      class="sf-btn-icon sf-btn-icon-mask"
      aria-hidden="true"
      style="--icon-url:url('<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/injury_icon.svg');">
  </span>

  <?= htmlspecialchars(sf_term('body_map_open_btn', $uiLang), ENT_QUOTES, 'UTF-8') ?>
</button>
      </div>
      <p class="sf-help-text"><?= htmlspecialchars(sf_term('body_map_instruction', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
      <!-- Valitut kehonosat näytetään tageina -->
      <div id="sfInjuryTags" class="sf-injury-tags"></div>
      <!-- Piilotettu select — lähetetään lomakkeen mukana -->
      <select id="sfInjuredPartsHidden" name="injured_parts[]" multiple class="sf-form-hidden"><?php
        foreach ($existing_body_parts as $svgId): ?><option value="<?= htmlspecialchars($svgId, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($svgId, ENT_QUOTES, 'UTF-8') ?></option><?php
        endforeach;
      ?></select>
    </div>

    <div id="sf-investigation-extra" class="sf-step3-investigation hidden">
      <div class="sf-field">
        <label for="sf-root-causes" class="sf-label">
          <?= htmlspecialchars(sf_term('root_cause_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </label>
        <textarea
          name="root_causes"
          id="sf-root-causes"
          class="sf-textarea"
          rows="4"
          maxlength="800"
          data-markdown-toolbar="1"
          data-markdown-toolbar-label="<?= htmlspecialchars(sf_term('markdown_toolbar_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
          data-markdown-bullet-label="<?= htmlspecialchars(sf_term('markdown_bullet_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
          data-markdown-remove-bullet-label="<?= htmlspecialchars(sf_term('markdown_remove_bullet_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
          placeholder="<?= htmlspecialchars(sf_term('root_causes_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        ><?= htmlspecialchars($root_causes, ENT_QUOTES, 'UTF-8') ?></textarea>
        <p class="sf-help-text">
          <?= htmlspecialchars(sf_term('root_causes_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>

      <div class="sf-field">
        <label for="sf-actions" class="sf-label">
          <?= htmlspecialchars(sf_term('actions_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </label>
        <textarea
          name="actions"
          id="sf-actions"
          class="sf-textarea"
          rows="4"
          maxlength="800"
data-markdown-toolbar="1"
data-markdown-toolbar-label="<?= htmlspecialchars(sf_term('markdown_toolbar_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
data-markdown-bullet-label="<?= htmlspecialchars(sf_term('markdown_bullet_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
data-markdown-remove-bullet-label="<?= htmlspecialchars(sf_term('markdown_remove_bullet_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
          placeholder="<?= htmlspecialchars(sf_term('actions_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        ><?= htmlspecialchars($actions, ENT_QUOTES, 'UTF-8') ?></textarea>
        <p class="sf-help-text">
          <?= htmlspecialchars(sf_term('actions_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>
    </div>

<div
    class="sf-two-slides-notice"
    id="sfTwoSlidesNotice"
    style="display: none;"
    data-title-attention="<?= htmlspecialchars(sf_term('two_slides_notice_title_attention', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
    data-title-warning="<?= htmlspecialchars(sf_term('two_slides_notice_title_warning', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
    data-text-auto="<?= htmlspecialchars(sf_term('two_slides_notice_text_auto', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
    data-text-force-double="<?= htmlspecialchars(sf_term('two_slides_notice_text_force_double', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
    data-text-force-single-fallback="<?= htmlspecialchars(sf_term('two_slides_notice_text_force_single_fallback', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
    data-text-single-success="<?= htmlspecialchars(sf_term('two_slides_notice_text_single_success', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
>
    <img src="<?= $base ?>/assets/img/icons/info.svg" alt="Information" class="sf-notice-icon" style="width: 20px; height: 20px;">
    <div class="sf-notice-text">
        <strong id="sfTwoSlidesNoticeTitle"><?= htmlspecialchars(sf_term('two_slides_notice_title_attention', $uiLang), ENT_QUOTES, 'UTF-8') ?></strong>
        <span id="sfTwoSlidesNoticeText"><?= htmlspecialchars(sf_term('two_slides_notice_text_auto', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
    </div>
</div>

    <!-- Vaihe 3 napit -->
    <div class="sf-step-actions sf-step-actions-bottom">
      <button type="button" id="sfPrev2" class="sf-btn sf-btn-secondary sf-prev-btn">
        <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
      </button>
      <button type="button" id="sfNext2" class="sf-btn sf-btn-primary sf-next-btn">
        <?= htmlspecialchars(sf_term('btn_next', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
      </button>
    </div>
  </div>

  <!-- VAIHE 4: Kuvat -->
  <div class="sf-step-content" data-step="4">
    <h2><?= htmlspecialchars(sf_term('step4_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>

    <p class="sf-help-text">
      <?= htmlspecialchars(sf_term('step4_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
    </p>


    <div class="sf-image-upload-grid">
      <!-- Pääkuva -->
      <div class="sf-image-upload-card" data-slot="1">
        <label class="sf-image-upload-label">
          <?= htmlspecialchars(sf_term('img_main_label', $uiLang) ?? 'Kuva 1', ENT_QUOTES, 'UTF-8') ?> *
        </label>
        <div class="sf-image-upload-area">
          <div class="sf-image-preview" id="sfImagePreview1">
            <img
              src="<?= $getImageUrl($flash['image_main'] ?? null) ?>"
              alt="Kuva 1"
              id="sfImageThumb1"
              data-placeholder="<?= $base ?>/assets/img/camera-placeholder.png"
            >

            <span
              class="sf-image-edited-badge hidden"
              id="sfImageEditedBadge1"
            >
              <?= htmlspecialchars(sf_term('edited', $uiLang) ?? 'Muokattu', ENT_QUOTES, 'UTF-8') ?>
            </span>

            <button
              type="button"
              class="sf-image-remove-btn <?= empty($flash['image_main']) ? 'hidden' : '' ?>"
              data-slot="1"
              title="<?= htmlspecialchars(sf_term('btn_remove_image', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
            >
              <svg width="16" height="16" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12"/>
              </svg>
            </button>
          </div>
<div class="sf-image-upload-actions">
            <label class="sf-image-upload-btn">
              <input type="file" name="image1" accept="image/*" id="sf-image1" class="sf-image-input">
              <img src="<?= $base ?>/assets/img/icons/upload.svg" alt="" class="sf-btn-icon">
                           <span><?= htmlspecialchars(sf_term('btn_upload', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </label>

            <label class="sf-image-upload-btn sf-image-camera-btn">
              <input type="file" name="image1_camera" accept="image/*" capture="environment" class="sf-image-input">
              <img src="<?= $base ?>/assets/img/icons/camera.svg" alt="" class="sf-btn-icon">
                           <span><?= htmlspecialchars(sf_term('btn_take_photo', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </label>

            <button type="button" class="sf-image-library-btn" data-slot="1">
              <img src="<?= $base ?>/assets/img/icons/image.svg" alt="" class="sf-btn-icon">
              <span><?= htmlspecialchars(sf_term('image_library_btn', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>

            <button type="button" class="sf-image-main-switch-btn" data-main-image-switch-slot="1" aria-label="<?= htmlspecialchars(sf_term('btn_set_as_main_image', $uiLang) ?: 'Aseta pääkuvaksi', ENT_QUOTES, 'UTF-8') ?>">
              <span><?= htmlspecialchars(sf_term('image_main_badge', $uiLang) ?: 'Pääkuva', ENT_QUOTES, 'UTF-8') ?></span>
            </button>
          </div>
        </div>
      </div>

      <!-- Lisäkuva 1 -->
      <div class="sf-image-upload-card" data-slot="2">
        <label class="sf-image-upload-label">
          <?= htmlspecialchars(sf_term('img_2_label', $uiLang) ?? 'Kuva 2', ENT_QUOTES, 'UTF-8') ?>
        </label>
        <div class="sf-image-upload-area">
          <div class="sf-image-preview" id="sfImagePreview2">
            <img
              src="<?= $getImageUrl($flash['image_2'] ?? null) ?>"
              alt="Kuva 2"
              id="sfImageThumb2"
              data-placeholder="<?= $base ?>/assets/img/camera-placeholder.png"
            >

            <span
              class="sf-image-edited-badge hidden"
              id="sfImageEditedBadge2"
            >
              <?= htmlspecialchars(sf_term('edited', $uiLang) ?? 'Muokattu', ENT_QUOTES, 'UTF-8') ?>
            </span>

            <button
              type="button"
              class="sf-image-remove-btn <?= empty($flash['image_2']) ? 'hidden' : '' ?>"
              data-slot="2"
              title="Poista kuva"
            >
              <svg width="16" height="16" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12"/>
              </svg>
            </button>
          </div>
<div class="sf-image-upload-actions">
            <label class="sf-image-upload-btn">
              <input type="file" name="image2" accept="image/*" id="sf-image2" class="sf-image-input">
              <img src="<?= $base ?>/assets/img/icons/upload.svg" alt="" class="sf-btn-icon">
                            <span><?= htmlspecialchars(sf_term('btn_upload', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </label>

            <label class="sf-image-upload-btn sf-image-camera-btn">
              <input type="file" name="image2_camera" accept="image/*" capture="environment" class="sf-image-input">
              <img src="<?= $base ?>/assets/img/icons/camera.svg" alt="" class="sf-btn-icon">
                            <span><?= htmlspecialchars(sf_term('btn_take_photo', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </label>

            <button type="button" class="sf-image-library-btn" data-slot="2">
              <img src="<?= $base ?>/assets/img/icons/image.svg" alt="" class="sf-btn-icon">
              <span><?= htmlspecialchars(sf_term('image_library_btn', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>

<button type="button" class="sf-image-main-switch-btn" data-main-image-switch-slot="2" aria-label="<?= htmlspecialchars(sf_term('btn_set_as_main_image', $uiLang) ?: 'Aseta pääkuvaksi', ENT_QUOTES, 'UTF-8') ?>">
  <span><?= htmlspecialchars(sf_term('image_main_badge', $uiLang) ?: 'Pääkuva', ENT_QUOTES, 'UTF-8') ?></span>
</button>
          </div>
        </div>
      </div>

      <!-- Lisäkuva 2 -->
      <div class="sf-image-upload-card" data-slot="3">
        <label class="sf-image-upload-label">
          <?= htmlspecialchars(sf_term('img_3_label', $uiLang) ?? 'Kuva 3', ENT_QUOTES, 'UTF-8') ?>
        </label>
        <div class="sf-image-upload-area">
          <div class="sf-image-preview" id="sfImagePreview3">
            <img
              src="<?= $getImageUrl($flash['image_3'] ?? null) ?>"
              alt="Kuva 3"
              id="sfImageThumb3"
              data-placeholder="<?= $base ?>/assets/img/camera-placeholder.png"
            >

            <span
              class="sf-image-edited-badge hidden"
              id="sfImageEditedBadge3"
            >
              <?= htmlspecialchars(sf_term('edited', $uiLang) ?? 'Muokattu', ENT_QUOTES, 'UTF-8') ?>
            </span>

            <button
              type="button"
              class="sf-image-remove-btn <?= empty($flash['image_3']) ? 'hidden' : '' ?>"
              data-slot="3"
              title="Poista kuva"
            >
              <svg width="16" height="16" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12"/>
              </svg>
            </button>
          </div>
<div class="sf-image-upload-actions">
            <label class="sf-image-upload-btn">
              <input type="file" name="image3" accept="image/*" id="sf-image3" class="sf-image-input">
              <img src="<?= $base ?>/assets/img/icons/upload.svg" alt="" class="sf-btn-icon">
                            <span><?= htmlspecialchars(sf_term('btn_upload', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </label>

            <label class="sf-image-upload-btn sf-image-camera-btn">
              <input type="file" name="image3_camera" accept="image/*" capture="environment" class="sf-image-input">
              <img src="<?= $base ?>/assets/img/icons/camera.svg" alt="" class="sf-btn-icon">
                            <span><?= htmlspecialchars(sf_term('btn_take_photo', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </label>

            <button type="button" class="sf-image-library-btn" data-slot="3">
              <img src="<?= $base ?>/assets/img/icons/image.svg" alt="" class="sf-btn-icon">
              <span><?= htmlspecialchars(sf_term('image_library_btn', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>

<button type="button" class="sf-image-main-switch-btn" data-main-image-switch-slot="3" aria-label="<?= htmlspecialchars(sf_term('btn_set_as_main_image', $uiLang) ?: 'Aseta pääkuvaksi', ENT_QUOTES, 'UTF-8') ?>">
  <span><?= htmlspecialchars(sf_term('image_main_badge', $uiLang) ?: 'Pääkuva', ENT_QUOTES, 'UTF-8') ?></span>
</button>
          </div>
        </div>
      </div>
    </div>

    <!-- EXTRA IMAGES SECTION -->
    <div class="extra-images-section" id="extra-images-container">
      <div class="extra-images-header">
        <h3><?= htmlspecialchars(sf_term('extra_images_title', $uiLang) ?: 'Lisäkuvat', ENT_QUOTES, 'UTF-8') ?></h3>
        <span class="extra-images-count" id="extra-images-count">0/20</span>
      </div>
      <p class="extra-images-description">
        <?= htmlspecialchars(sf_term('extra_images_description', $uiLang) ?: 'Lisää tähän muita kuvia, jotka eivät näy PDF-tiedotteessa mutta ovat saatavilla tiedotteen katselunäkymässä.', ENT_QUOTES, 'UTF-8') ?>
      </p>
      <div class="extra-images-upload">
        <button type="button" id="extra-image-upload-btn" class="sf-btn sf-btn-primary" aria-label="<?= htmlspecialchars(sf_term('extra_images_add_button', $uiLang) ?: 'Lisää kuvia', ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars(sf_term('extra_images_add_button', $uiLang) ?: 'Lisää kuvia', ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button type="button" id="extra-image-camera-btn" class="sf-btn sf-btn-secondary extra-image-camera-trigger" aria-label="<?= htmlspecialchars(sf_term('upload_camera_btn', $uiLang) ?: 'Ota kuva', ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars(sf_term('upload_camera_btn', $uiLang) ?: 'Ota kuva', ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button type="button" id="extra-video-upload-btn" class="sf-btn sf-btn-secondary" aria-label="<?= htmlspecialchars(sf_term('add_video_btn', $uiLang) ?: 'Lisää video', ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars(sf_term('add_video_btn', $uiLang) ?: 'Lisää video', ENT_QUOTES, 'UTF-8') ?>
        </button>
        <input type="file" id="extra-image-input" accept="image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif" multiple style="display: none;">
        <input type="file" id="extra-image-camera-input" accept="image/*" capture="environment" multiple style="display: none;">
        <input type="file" id="extra-video-input" accept=".mp4,.webm,.ogv,.ogg,.mov,.avi,.mkv" style="display: none;">
      </div>
      <div class="extra-images-progress" id="extra-images-progress" aria-live="polite" aria-atomic="true">
        <div class="extra-images-progress-bar">
          <div class="extra-images-progress-fill" id="extra-images-progress-fill"></div>
        </div>
        <div class="extra-images-progress-text" id="extra-images-progress-text"></div>
      </div>
      <div class="extra-images-grid" id="extra-images-grid">
        <!-- Media items will be added here by JavaScript -->
      </div>
      <div class="extra-images-drop-overlay" id="extra-images-drop-overlay">
        <?= htmlspecialchars(sf_term('extra_img_drop_here', $uiLang) ?: 'Pudota kuvat tähän', ENT_QUOTES, 'UTF-8') ?>
      </div>
    </div>

<div class="sf-step-actions sf-step-actions-bottom">
<button type="button" class="sf-btn sf-btn-secondary sf-prev-btn">
          <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
        </button>

        <button type="button" class="sf-btn sf-btn-primary sf-next-btn">
          <?= htmlspecialchars(sf_term('btn_next', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
        </button>
      </div>
  </div>

<!-- VAIHE 5: Grid-asettelu -->
<div class="sf-step-content" data-step="5">
  <div class="sf-grid-step-header">
    <h2><?= htmlspecialchars(sf_term('grid_heading', $uiLang) ?? 'Kuvien asettelu', ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="sf-help-text sf-grid-step-help">
      <?= htmlspecialchars(sf_term('grid_help', $uiLang) ?? 'Valitse asettelu. Tämän jälkeen järjestelmä generoi lopullisen kuva-alueen.', ENT_QUOTES, 'UTF-8') ?>
    </p>
  </div>

  <div class="sf-grid-step-panel">
    <div class="sf-grid-step-panel-header">
      <div class="sf-grid-step-panel-title-wrap">
        <span class="sf-grid-step-eyebrow">Layout</span>
        <h3 class="sf-grid-step-panel-title">
          <?= htmlspecialchars(sf_term('layout_label', $uiLang) ?? 'Kuvien asettelu', ENT_QUOTES, 'UTF-8') ?>
        </h3>
      </div>
      <p class="sf-grid-step-panel-note">
        <?= htmlspecialchars(sf_term('grid_help', $uiLang) ?? 'Valitse asettelu. Tämän jälkeen järjestelmä generoi lopullisen kuva-alueen.', ENT_QUOTES, 'UTF-8') ?>
      </p>
    </div>

    <!-- GRID-VALINTAKORTIT (JS täyttää sisällön) -->
    <div class="sf-grid-options" id="sfGridPicker"></div>
  </div>

  <div class="sf-step-actions sf-step-actions-bottom">
    <button type="button" class="sf-btn sf-btn-secondary sf-prev-btn">
      <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
    </button>
    <button type="button" class="sf-btn sf-btn-primary sf-next-btn">
      <?= htmlspecialchars(sf_term('btn_next', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
    </button>
  </div>
</div>

  <!-- Piilotetut transform-kentät (ennen vaihetta 5, lomakkeen sisällä) -->
  <input
    type="hidden"
    id="sf-image1-transform"
    name="image1_transform"
    value="<?= htmlspecialchars($image1_transform, ENT_QUOTES, 'UTF-8') ?>"
  >
  <input
    type="hidden"
    id="sf-image2-transform"
    name="image2_transform"
    value="<?= htmlspecialchars($image2_transform, ENT_QUOTES, 'UTF-8') ?>"
  >
  <input
    type="hidden"
    id="sf-image3-transform"
    name="image3_transform"
    value="<?= htmlspecialchars($image3_transform, ENT_QUOTES, 'UTF-8') ?>"
  >

  <!-- Piilotetut existing image -kentät (tiedostonimet jotka ovat jo tietokannassa) -->
  <input
    type="hidden"
    id="sf-existing-image-1"
    name="existing_image_1"
    value="<?= htmlspecialchars($flash['image_main'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
  >
  <input
    type="hidden"
    id="sf-existing-image-2"
    name="existing_image_2"
    value="<?= htmlspecialchars($flash['image_2'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
  >
  <input
    type="hidden"
    id="sf-existing-image-3"
    name="existing_image_3"
    value="<?= htmlspecialchars($flash['image_3'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
  >

  <!-- Piilotetut kuvapankki-kentät: täytetään JS:ssä kun kuva valitaan pankista,
       muokkaustilassa esitäytetään jos kuva on peräisin kuvapankista -->
  <input
    type="hidden"
    id="sfLibraryImage1"
    name="library_image_1"
    value="<?= ($editing && strpos($flash['image_main'] ?? '', 'lib_') === 0) ? htmlspecialchars($flash['image_main'], ENT_QUOTES, 'UTF-8') : '' ?>"
  >
  <input
    type="hidden"
    id="sfLibraryImage2"
    name="library_image_2"
    value="<?= ($editing && strpos($flash['image_2'] ?? '', 'lib_') === 0) ? htmlspecialchars($flash['image_2'], ENT_QUOTES, 'UTF-8') : '' ?>"
  >
  <input
    type="hidden"
    id="sfLibraryImage3"
    name="library_image_3"
    value="<?= ($editing && strpos($flash['image_3'] ?? '', 'lib_') === 0) ? htmlspecialchars($flash['image_3'], ENT_QUOTES, 'UTF-8') : '' ?>"
  >

  <!-- Piilotetut editoidut kuvat (dataURL) - täytetään kuvaeditorissa -->
  <input
    type="hidden"
    id="sf-image1-edited-data"
    name="image1_edited_data"
    value="<?= htmlspecialchars($flash['image1_edited_data'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
  >
  <input
    type="hidden"
    id="sf-image2-edited-data"
    name="image2_edited_data"
    value="<?= htmlspecialchars($flash['image2_edited_data'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
  >
  <input
    type="hidden"
    id="sf-image3-edited-data"
    name="image3_edited_data"
    value="<?= htmlspecialchars($flash['image3_edited_data'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
  >

  <input
    type="hidden"
    id="sf-edit-annotations-data"
    name="annotations_data"
    value="<?= htmlspecialchars($flash['annotations_data'] ?? '{}', ENT_QUOTES, 'UTF-8') ?>"
  >
  <input type="hidden" id="sf-grid-layout" name="grid_layout" value="<?= htmlspecialchars($flash['grid_layout'] ?? 'grid-1', ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" id="sf-grid-bitmap" name="grid_bitmap" value="<?= htmlspecialchars($flash['grid_bitmap'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  <!-- main_image_slot: always "1" because save_flash.php physically swaps the image slots so
       image_main always holds whichever image was selected as main. The UI updates this via JS
       (main-image-switcher.js) when the user clicks a "Pääkuva" button on a different slot. -->
  <input type="hidden" id="sf-main-image-slot" name="main_image_slot" value="1">
  <input
    type="hidden"
    id="sf-annotations-data"
    value="[]"
  >

<!-- VAIHE 6: Esikatselu -->
  <div class="sf-step-content" data-step="6">
    <div class="sf-preview-section">

  <?php
    $lblRefresh = [
      'fi' => 'Päivitä esikatselu',
      'sv' => 'Uppdatera förhandsvisning',
      'en' => 'Refresh preview',
      'it' => 'Aggiorna anteprima',
      'el' => 'Ανανέωση προεπισκόπησης',
    ][$uiLang] ?? 'Refresh preview';

    $lblRefreshing = [
      'fi' => 'Päivitetään…',
      'sv' => 'Uppdaterar…',
      'en' => 'Refreshing…',
      'it' => 'Aggiornamento…',
      'el' => 'Ανανέωση…',
    ][$uiLang] ?? 'Refreshing…';

    // Capture the supervisor section for the preview controls right column.
    // Show for all flashes (including translation children) when in a submittable state.
    ob_start();
    if (
        (!$editing || $state_val === 'draft' || $state_val === 'request_info' || $state_val === '') &&
        !($isTranslationChild && isset($sourceFlash['state']) && in_array($sourceFlash['state'], ['to_comms', 'published'], true))
    ):
  ?>
    <div class="sf-supervisor-section" id="sfSupervisorApprovalSection" style="display: none;">
      <h3 class="sf-supervisor-title"><?= htmlspecialchars(sf_term('select_inspector_title', $uiLang) ?: 'Valitse tarkistaja', ENT_QUOTES, 'UTF-8') ?></h3>
      
      <!-- Worksite Supervisors Section -->
      <div class="sf-supervisor-worksite">
        <p class="sf-supervisor-worksite-label">
          <?= htmlspecialchars(sf_term('worksite_supervisors_label_prefix', $uiLang) ?: 'Työmaan', ENT_QUOTES, 'UTF-8') ?>
          "<span id="sfSelectedWorksiteName">-</span>" 
          <?= htmlspecialchars(sf_term('worksite_supervisors_label_suffix', $uiLang) ?: 'vastuuhenkilöt:', ENT_QUOTES, 'UTF-8') ?>
        </p>
        
        <div class="sf-supervisor-chips" id="sfWorksiteSupervisors">
          <!-- JavaScript will populate this automatically -->
          <div class="sf-supervisor-chips-loading">
            <span class="sf-spinner-small"></span>
            <?= htmlspecialchars(sf_term('loading_text', $uiLang) ?: 'Ladataan...', ENT_QUOTES, 'UTF-8') ?>
          </div>
        </div>
        
        <p class="sf-supervisor-empty" id="sfNoSupervisors" style="display: none;">
          <?= htmlspecialchars(sf_term('no_supervisors_for_worksite', $uiLang) ?: 'Tälle työmaalle ei ole määritetty vastuuhenkilöitä.', ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>
      
      <!-- Search Section for Other Worksites -->
      <div class="sf-supervisor-search">
        <p class="sf-supervisor-search-label"><?= htmlspecialchars(sf_term('search_other_worksites_label', $uiLang) ?: 'Hae muilta työmailta:', ENT_QUOTES, 'UTF-8') ?></p>
        <div class="sf-search-input-wrap">
          <svg class="sf-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"></circle>
            <path d="m21 21-4.35-4.35"></path>
          </svg>
          <input type="text" 
                 id="sfSupervisorSearch" 
                 class="sf-search-input"
                 placeholder="<?= htmlspecialchars(sf_term('search_name_or_worksite_placeholder', $uiLang) ?: 'Hae nimellä tai työmaalla...', ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" id="sfClearSearch" class="sf-clear-search" style="display: none;">×</button>
        </div>
        
        <div class="sf-supervisor-search-results" id="sfSearchResults" style="display: none;">
          <!-- Search results will be populated here -->
        </div>
      </div>
      
      <!-- Selected Counter -->
      <div class="sf-supervisor-counter">
        <span id="sfSelectedCount">0</span> <?= htmlspecialchars(sf_term('selected_label', $uiLang) ?: 'valittu', ENT_QUOTES, 'UTF-8') ?>
      </div>
      
      <!-- Hidden input for selected IDs -->
      <input type="hidden" name="approver_ids" id="approverIds" value="">
      <input type="hidden" name="selected_approvers" id="selectedApprovers" value="">
    </div>
  <?php
    endif;
    $sfPreviewControlsSlot = ob_get_clean();
  ?>

  <?php require __DIR__ . '/../partials/preview_server.php'; ?>
</div>
    
<script>
(function () {
  function initSFServerPreview() {
    const container = document.getElementById('sfServerPreviewWrapper');
    const form = document.getElementById('sf-form');
    if (!container || !form) return;

    // Estä tupla-init (PJAX voi ajaa tämän useasti)
    if (window.sfServerPreview && window.sfServerPreview.__sf_inited) return;

    import('<?= $base ?>/assets/js/modules/preview-server.js').then(module => {
      const preview = new module.ServerPreview({
        endpoint: '<?= $base ?>/app/api/preview.php',
        container,
        form,
        debounce: 500
      });
      preview.init();
      preview.__sf_inited = true;
      window.sfServerPreview = preview;
    });
  }

  // Normaali lataus
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSFServerPreview);
  } else {
    initSFServerPreview();
  }

  // PJAX-lataus (teillä pjax.js dispatchaa tämän)
  document.addEventListener('sf:page:loaded', initSFServerPreview);
})();
</script>

  <?php
  // Language labels with flag emojis – used in both translation-child and normal-mode footers.
  $sfLangLabels = ['fi' => '🇫🇮 FI', 'sv' => '🇸🇪 SV', 'en' => '🇬🇧 EN', 'it' => '🇮🇹 IT', 'el' => '🇬🇷 EL'];
  $bundleMemberLabel = '';
  if ($isInBundle) {
      $bundleMemberLabelParts = array_map(
          function($m) use ($sfLangLabels) { return $sfLangLabels[$m['lang']] ?? strtoupper((string)$m['lang']); },
          $bundleAllMembers
      );
      $bundleMemberLabel = implode(', ', $bundleMemberLabelParts);
  }
  ?>
  <?php
  // Detect if the parent flash is already in an advanced state (to_comms or published).
  // In that case, translation children do not need to go through the review flow again.
  $parentIsAdvancedState = $isTranslationChild
      && isset($sourceFlash['state'])
      && in_array($sourceFlash['state'], ['to_comms', 'published'], true);
  ?>
  <?php if ($isTranslationChild): ?>
    <!-- Translation child mode footer -->
    <div class="sf-step6-footer">
      <?php
      // Määritä näytettävä painike tilan mukaan (sama logiikka kuin pääkieliversion footer)
      // - draft ja request_info: näytä "Tallenna luonnos" + "Lisää kieliversio" + "Lähetä tarkistettavaksi"
      // - muut tilat (pending_supervisor, pending_review, reviewed, to_comms, published): näytä vain "Tallenna"
      $showSendToReview = ! $editing
          || $state_val === 'draft'
          || $state_val === 'request_info'
          || $state_val === '';
      $actionUrl = $base . '/app/api/save_flash.php';
      // Show the bundle info bar unless we are in the "only Tallenna" single-button mode.
      $showBundleInfoBar = $isInBundle && (!$editing || $showSendToReview || $parentIsAdvancedState);
      ?>
      <?php if ($showBundleInfoBar): ?>
        <!-- Bundle info bar above the button row -->
        <div class="sf-bundle-info-bar">
          <span class="sf-bundle-info-label">
            <?= htmlspecialchars(sf_term('bundle_members_label', $uiLang) ?? 'Nipussa:', ENT_QUOTES, 'UTF-8') ?>
            <strong><?= htmlspecialchars($bundleMemberLabel) ?></strong>
          </span>
        </div>
      <?php endif; ?>
      <div class="sf-step6-footer-actions">
        <button type="button" class="sf-btn sf-btn-secondary sf-prev-btn">
          <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <div class="sf-step6-footer-right">
          <?php if ($parentIsAdvancedState): ?>
            <!-- Emoversio on viestinnällä/julkaistu – ei tarvita tarkistuskierrosta.
                 Näytä: Tallenna luonnos + Lisää kieliversio + Tallenna (ei Lähetä tarkistettavaksi) -->
            <button
              type="submit"
              name="submission_type"
              value="draft"
              id="sfSaveDraft"
              class="sf-btn sf-btn-secondary"
            >
              <?= htmlspecialchars(sf_term('btn_save_draft', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button
              type="button"
              id="sfAddLanguageVersion"
              class="sf-btn sf-btn-outline"
              title="<?= htmlspecialchars(sf_term('btn_add_language_version_title', $uiLang) ?? 'Tallenna ensin luonnoksena, luo sitten uusi kieliversio', ENT_QUOTES, 'UTF-8') ?>"
            >
              ➕ <?= htmlspecialchars(sf_term('btn_add_language_version', $uiLang) ?? 'Lisää kieliversio', ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button
              type="button"
              id="sfSaveInline"
              class="sf-btn sf-btn-primary"
              data-action-url="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>"
              data-flash-id="<?= (int)$editId ?>"
            >
              <?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
            </button>
          <?php elseif ($editing && ! $showSendToReview): ?>
            <!-- Muokkaus tilassa joka EI ole draft/request_info – näytä luonnos + kieliversio + tallenna (ei lähetä tarkistettavaksi) -->
            <button
              type="submit"
              name="submission_type"
              value="draft"
              id="sfSaveDraft"
              class="sf-btn sf-btn-secondary"
            >
              <?= htmlspecialchars(sf_term('btn_save_draft', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button
              type="button"
              id="sfAddLanguageVersion"
              class="sf-btn sf-btn-outline"
              title="<?= htmlspecialchars(sf_term('btn_add_language_version_title', $uiLang) ?? 'Tallenna ensin luonnoksena, luo sitten uusi kieliversio', ENT_QUOTES, 'UTF-8') ?>"
            >
              ➕ <?= htmlspecialchars(sf_term('btn_add_language_version', $uiLang) ?? 'Lisää kieliversio', ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button
              type="button"
              id="sfSaveInline"
              class="sf-btn sf-btn-primary"
              data-action-url="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>"
              data-flash-id="<?= (int)$editId ?>"
            >
              <?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
            </button>
          <?php else: ?>
            <!-- Uusi tai draft/request_info - näytä kaikki painikkeet -->
            <?php if ($isInBundle): ?>
              <!-- Bundle mode: save draft + add language + send-all -->
              <button
                type="submit"
                name="submission_type"
                value="draft"
                id="sfSaveDraft"
                class="sf-btn sf-btn-secondary"
              >
                <?= htmlspecialchars(sf_term('btn_save_draft', $uiLang), ENT_QUOTES, 'UTF-8') ?>
              </button>
              <button
                type="button"
                id="sfAddLanguageVersion"
                class="sf-btn sf-btn-outline"
                title="<?= htmlspecialchars(sf_term('btn_add_language_version_title', $uiLang) ?? 'Tallenna ensin luonnoksena, luo sitten uusi kieliversio', ENT_QUOTES, 'UTF-8') ?>"
              >
                ➕ <?= htmlspecialchars(sf_term('btn_add_language_version', $uiLang) ?? 'Lisää kieliversio', ENT_QUOTES, 'UTF-8') ?>
              </button>
              <button
                type="submit"
                name="submission_type"
                value="review"
                id="sfSubmitReview"
                class="sf-btn sf-btn-primary"
              >
                <?php
                $sendAllLabel = sprintf(
                    sf_term('btn_send_bundle_review', $uiLang) ?? 'Lähetä kaikki (%d) tarkistettavaksi',
                    $bundleCount
                );
                echo htmlspecialchars($sendAllLabel, ENT_QUOTES, 'UTF-8');
                ?>
              </button>
            <?php else: ?>
              <!-- Single translation child (not in bundle): save as draft + add language + send for review -->
              <button
                type="submit"
                name="submission_type"
                value="draft"
                id="sfSaveDraft"
                class="sf-btn sf-btn-secondary"
              >
                <?= htmlspecialchars(sf_term('btn_save_draft', $uiLang), ENT_QUOTES, 'UTF-8') ?>
              </button>
              <button
                type="button"
                id="sfAddLanguageVersion"
                class="sf-btn sf-btn-outline"
                title="<?= htmlspecialchars(sf_term('btn_add_language_version_title', $uiLang) ?? 'Tallenna ensin luonnoksena, luo sitten uusi kieliversio', ENT_QUOTES, 'UTF-8') ?>"
              >
                ➕ <?= htmlspecialchars(sf_term('btn_add_language_version', $uiLang) ?? 'Lisää kieliversio', ENT_QUOTES, 'UTF-8') ?>
              </button>
              <button
                type="submit"
                name="submission_type"
                value="review"
                id="sfSubmitReview"
                class="sf-btn sf-btn-primary"
              >
                <?= htmlspecialchars(sf_term('btn_send_review', $uiLang) ?? 'Lähetä tarkistettavaksi', ENT_QUOTES, 'UTF-8') ?>
              </button>
            <?php endif; // $isInBundle ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php else: ?>
    <!-- Normal mode: Full workflow with supervisor selection and review/draft buttons -->
    <!-- Note: Supervisor section is rendered in the preview controls column (right column) -->

    <!-- Vaihe 6 alatunniste: Edellinen vasemmalla, Tallenna/Lähetä oikealla -->
    <div class="sf-step6-footer">
      <?php 
      // Määritä näytettävä painike tilan mukaan
      // - draft ja request_info: näytä "Tallenna luonnos" + "Lähetä tarkistettavaksi"
      // - muut tilat (pending_supervisor, pending_review, reviewed, to_comms, published): näytä vain "Tallenna"
      $showSendToReview = ! $editing 
          || $state_val === 'draft' 
          || $state_val === 'request_info'
          || $state_val === '';
      
      // All updates now go through save_flash.php (uses FlashSaveService)
      $actionUrl = $base . '/app/api/save_flash.php';
      ?>
      <?php if (! ($editing && ! $showSendToReview) && $isInBundle): ?>
        <!-- Bundle info bar above the button row -->
        <div class="sf-bundle-info-bar">
          <span class="sf-bundle-info-label">
            <?= htmlspecialchars(sf_term('bundle_members_label', $uiLang) ?? 'Nipussa:', ENT_QUOTES, 'UTF-8') ?>
            <strong><?= htmlspecialchars($bundleMemberLabel) ?></strong>
          </span>
        </div>
      <?php endif; ?>
      <div class="sf-step6-footer-actions">
        <button type="button" class="sf-btn sf-btn-secondary sf-prev-btn">
          <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <div class="sf-step6-footer-right">
          <?php if ($editing && ! $showSendToReview): ?>
            <!-- Muokkaus tilassa joka EI ole draft/request_info – näytä luonnos + kieliversio + tallenna (ei lähetä tarkistettavaksi) -->
            <button
              type="submit"
              name="submission_type"
              value="draft"
              id="sfSaveDraft"
              class="sf-btn sf-btn-secondary"
            >
              <?= htmlspecialchars(sf_term('btn_save_draft', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button
              type="button"
              id="sfAddLanguageVersion"
              class="sf-btn sf-btn-outline"
              title="<?= htmlspecialchars(sf_term('btn_add_language_version_title', $uiLang) ?? 'Tallenna ensin luonnoksena, luo sitten uusi kieliversio', ENT_QUOTES, 'UTF-8') ?>"
            >
              ➕ <?= htmlspecialchars(sf_term('btn_add_language_version', $uiLang) ?? 'Lisää kieliversio', ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button
              type="button"
              id="sfSaveInline"
              class="sf-btn sf-btn-primary"
              data-action-url="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>"
              data-flash-id="<?= (int)$editId ?>"
            >
              <?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
            </button>
          <?php else: ?>
            <!-- Uusi tai draft/request_info - näytä kaikki painikkeet -->
            <?php if ($isInBundle): ?>
              <!-- Bundle mode: save draft + add language + send-all -->
              <button
                type="submit"
                name="submission_type"
                value="draft"
                id="sfSaveDraft"
                class="sf-btn sf-btn-secondary"
              >
                <?= htmlspecialchars(sf_term('btn_save_draft', $uiLang), ENT_QUOTES, 'UTF-8') ?>
              </button>
              <button
                type="button"
                id="sfAddLanguageVersion"
                class="sf-btn sf-btn-outline"
                title="<?= htmlspecialchars(sf_term('btn_add_language_version_title', $uiLang) ?? 'Tallenna ensin luonnoksena, luo sitten uusi kieliversio', ENT_QUOTES, 'UTF-8') ?>"
              >
                ➕ <?= htmlspecialchars(sf_term('btn_add_language_version', $uiLang) ?? 'Lisää kieliversio', ENT_QUOTES, 'UTF-8') ?>
              </button>
              <button
                type="submit"
                name="submission_type"
                value="review"
                id="sfSubmitReview"
                class="sf-btn sf-btn-primary"
              >
                <?php
                $sendAllLabel = sprintf(
                    sf_term('btn_send_bundle_review', $uiLang) ?? 'Lähetä kaikki (%d) tarkistettavaksi',
                    $bundleCount
                );
                echo htmlspecialchars($sendAllLabel, ENT_QUOTES, 'UTF-8');
                ?>
              </button>
            <?php else: ?>
              <button
                type="submit"
                name="submission_type"
                value="draft"
                id="sfSaveDraft"
                class="sf-btn sf-btn-secondary"
              >
                <?= htmlspecialchars(sf_term('btn_save_draft', $uiLang), ENT_QUOTES, 'UTF-8') ?>
              </button>
              <button
                type="button"
                id="sfAddLanguageVersion"
                class="sf-btn sf-btn-outline"
                title="<?= htmlspecialchars(sf_term('btn_add_language_version_title', $uiLang) ?? 'Tallenna ensin luonnoksena, luo sitten uusi kieliversio', ENT_QUOTES, 'UTF-8') ?>"
              >
                ➕ <?= htmlspecialchars(sf_term('btn_add_language_version', $uiLang) ?? 'Lisää kieliversio', ENT_QUOTES, 'UTF-8') ?>
              </button>
              <button
                type="submit"
                name="submission_type"
                value="review"
                id="sfSubmitReview"
                class="sf-btn sf-btn-primary"
              >
                <?= htmlspecialchars(sf_term('btn_send_review', $uiLang), ENT_QUOTES, 'UTF-8') ?>
              </button>
            <?php endif; // $isInBundle ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; // $isTranslationChild ?>
  </div>
  <!-- Lopullinen preview-kuva base64:na -->
  <input type="hidden" name="preview_image_data" id="sf-preview-image-data" value="">
  <input type="hidden" name="preview_image_data_2" id="sf-preview-image-data-2" value="">

  <div id="sfTextModal" class="sf-modal hidden">
  <div class="sf-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="sfTextModalTitle">
    <div class="sf-modal-header">
      <h3 id="sfTextModalTitle"><?= htmlspecialchars(sf_term('anno_text', $uiLang) ?? 'Teksti', ENT_QUOTES, 'UTF-8') ?></h3>
      <button type="button" class="sf-modal-close" data-modal-close>×</button>
    </div>

    <div class="sf-modal-body">
      <label class="sf-label" for="sfTextModalInput">
        <?= htmlspecialchars(sf_term('LABEL_PROMPT', $uiLang) ?? 'Kirjoita merkintä:', ENT_QUOTES, 'UTF-8') ?>
      </label>
<textarea
  id="sfTextModalInput"
  class="sf-textarea"
  rows="5"
  placeholder="<?= htmlspecialchars(sf_term('anno_text_placeholder', $uiLang) ?? 'Kirjoita teksti… (Enter = uusi rivi)', ENT_QUOTES, 'UTF-8') ?>"
></textarea>
    </div>

    <div class="sf-modal-footer">
      <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
      </button>
      <button type="button" id="sfTextModalSave" class="sf-btn sf-btn-primary">
        <?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
      </button>
    </div>
  </div>
</div>

<!-- KUVAEDITORI MODAL (ei osa steppejä) -->
<div id="sfEditStep" class="hidden sf-edit-modal" aria-hidden="true">
  <div class="sf-edit-modal-card sf-edit-compact">
    
    <!-- Header:  otsikko + close -->
    <div class="sf-edit-modal-header-compact">
      <div class="sf-edit-header-left">
        <h2 data-sf-edit-title><?= htmlspecialchars(sf_term('img_edit_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
      </div>
      <div class="sf-edit-header-actions">
        <button type="button" id="sf-edit-crop-info-btn" class="sf-edit-close-compact" aria-label="<?= htmlspecialchars(sf_term('crop_guide_label', $uiLang) ?? 'Rajausopas', ENT_QUOTES, 'UTF-8') ?>">
          <img src="<?= $base ?>/assets/img/icons/info.svg" alt="">
        </button>
        <button type="button" id="sf-edit-close" class="sf-edit-close-compact" aria-label="<?= htmlspecialchars(sf_term('btn_close', $uiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 6L6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Body: canvas + sivupaneeli samalla rivillä -->
    <div class="sf-edit-modal-body-compact">
      
      <!-- Vasen:  Canvas -->
      <div class="sf-edit-canvas-area">
        <div class="sf-edit-crop-guide" id="sfCropGuide">
          <svg class="sf-edit-crop-guide-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 16v-4M12 8h.01"/>
          </svg>
          <div class="sf-edit-crop-guide-text">
            <strong><?= htmlspecialchars(sf_term('crop_guide_label', $uiLang) ?? 'Rajausopas', ENT_QUOTES, 'UTF-8') ?>:</strong>
            <span class="sf-crop-guide-main"><?= htmlspecialchars(sf_term('crop_guide_text', $uiLang) ?? 'Katkoviiva (1:1) näyttää neliökuvissa näkyvän alueen. Tummennettu reuna-alue näkyy 16:9-vaaka-asettelussa.', ENT_QUOTES, 'UTF-8') ?></span>
            <details class="sf-crop-guide-details">
              <summary><?= htmlspecialchars(sf_term('crop_guide_show_more', $uiLang) ?? 'Näytä lisäohjeet', ENT_QUOTES, 'UTF-8') ?></summary>
              <ul>
                <?php
                  $detailItems = explode('|', sf_term('crop_guide_details', $uiLang) ?? 'Yksi kuva → asemoi kohde 1:1-neliön sisälle|Useampi kuva → kuvat näkyvät 16:9-alueella|Merkintöjä voi lisätä myös tummennetulle alueelle|Palauta-painike asemoi kuvan automaattisesti reunasta reunaan|Tallenna, tarkista esikatselusta ja palaa tarvittaessa säätämään');
                  foreach ($detailItems as $item): ?>
                    <li><?= htmlspecialchars(trim($item), ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
              </ul>
            </details>
          </div>
          <button type="button" class="sf-edit-crop-guide-close" onclick="this.parentElement.classList.add('hidden')" aria-label="<?= htmlspecialchars(sf_term('btn_close', $uiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">×</button>
        </div>
        <div id="sf-edit-img-canvas-wrap" class="sf-edit-canvas-wrap">
          <canvas id="sf-edit-img-canvas" width="1920" height="1080" class="sf-edit-canvas"></canvas>
        </div>
        
        <!-- Zoom/pan kontrollit canvasin alla -->
        <div class="sf-edit-canvas-controls">
          <div class="sf-edit-control-group">
            <button type="button" id="sf-edit-img-zoom-out" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_zoom_out', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
            <button type="button" id="sf-edit-img-zoom-in" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_zoom_in', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
            <span class="sf-edit-ctrl-divider"></span>
            <button type="button" id="sf-edit-img-move-left" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_move_left', $uiLang), ENT_QUOTES, 'UTF-8') ?>">←</button>
            <button type="button" id="sf-edit-img-move-up" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_move_up', $uiLang), ENT_QUOTES, 'UTF-8') ?>">↑</button>
            <button type="button" id="sf-edit-img-move-down" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_move_down', $uiLang), ENT_QUOTES, 'UTF-8') ?>">↓</button>
            <button type="button" id="sf-edit-img-move-right" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_move_right', $uiLang), ENT_QUOTES, 'UTF-8') ?>">→</button>
            <span class="sf-edit-ctrl-divider"></span>
            <button type="button" id="sf-edit-img-rotate-left" class="sf-edit-ctrl-btn" title="Kierrä 90° vasemmalle">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12a9 9 0 1 0 3-6.7"></path>
                <polyline points="3 3 3 9 9 9"></polyline>
              </svg>
            </button>
            <button type="button" id="sf-edit-img-rotate-right" class="sf-edit-ctrl-btn" title="Kierrä 90° oikealle">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 12a9 9 0 1 1-3-6.7"></path>
                <polyline points="21 3 21 9 15 9"></polyline>
              </svg>
            </button>
            <span class="sf-edit-ctrl-divider"></span>
            <button type="button" id="sf-edit-img-reset" class="sf-edit-ctrl-btn sf-edit-ctrl-text" title="<?= htmlspecialchars(sf_term('edit_reset', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars(sf_term('btn_reset', $uiLang) ?? 'Reset', ENT_QUOTES, 'UTF-8') ?>
            </button>
          </div>
        </div>
      </div>

      <!-- Oikea: Merkinnät paneeli -->
      <div class="sf-edit-sidebar">
        <div class="sf-edit-sidebar-section">
          <h3 class="sf-edit-sidebar-title"><?= htmlspecialchars(sf_term('anno_title', $uiLang) ?? 'Merkinnät', ENT_QUOTES, 'UTF-8') ?></h3>
          <p class="sf-edit-sidebar-hint">
            <?= htmlspecialchars(sf_term('anno_help_short', $uiLang) ?? 'Valitse ikoni ja klikkaa kuvaa', ENT_QUOTES, 'UTF-8') ?>
          </p>
          
          <!-- Ikonivalitsin -->
          <div class="sf-edit-anno-grid">
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="arrow" title="<?= htmlspecialchars(sf_term('anno_arrow', $uiLang) ?? 'Nuoli', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/arrow-red.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="circle" title="<?= htmlspecialchars(sf_term('anno_circle', $uiLang) ?? 'Ympyrä', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/circle-red.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="crash" title="<?= htmlspecialchars(sf_term('anno_crash', $uiLang) ?? 'Törmäys', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/crash.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="warning" title="<?= htmlspecialchars(sf_term('anno_warning', $uiLang) ?? 'Varoitus', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/warning.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="injury" title="<?= htmlspecialchars(sf_term('anno_injury', $uiLang) ?? 'Vamma', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/injury.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="cross" title="<?= htmlspecialchars(sf_term('anno_cross', $uiLang) ?? 'Risti', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/cross-red.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="blur" title="<?= htmlspecialchars(sf_term('anno_blur', $uiLang) ?? 'Sumennus (Kasvot / Kilvet)', ENT_QUOTES, 'UTF-8') ?>">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
                <line x1="2" y1="2" x2="22" y2="22"/>
              </svg>
            </button>
          </div>
          
          <!-- Valitun merkinnän kontrollit -->
          <div class="sf-edit-selected-controls" id="sfEditSelectedControls">
            <div class="sf-edit-selected-row">
              <button type="button" id="sf-edit-anno-rotate" class="sf-edit-sel-btn" disabled title="<?= htmlspecialchars(sf_term('anno_rotate_45', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
              </button>
              <button type="button" id="sf-edit-anno-size-down" class="sf-edit-sel-btn" disabled title="<?= htmlspecialchars(sf_term('anno_size_down', $uiLang), ENT_QUOTES, 'UTF-8') ?>">−</button>
              <button type="button" id="sf-edit-anno-size-up" class="sf-edit-sel-btn" disabled title="<?= htmlspecialchars(sf_term('anno_size_up', $uiLang), ENT_QUOTES, 'UTF-8') ?>">+</button>
              <button type="button" id="sf-edit-anno-delete" class="sf-edit-sel-btn sf-edit-sel-danger" disabled title="<?= htmlspecialchars(sf_term('anno_delete_selected', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
              </button>
            </div>
          </div>
          
          <!-- Teksti-nappi -->
          <button type="button" id="sf-edit-img-add-label" class="sf-edit-text-btn" disabled>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg>
            <?= htmlspecialchars(sf_term('anno_text', $uiLang) ?? 'Lisää teksti', ENT_QUOTES, 'UTF-8') ?>
          </button>
        </div>
      </div>
    </div>

    <!-- Footer: Tallenna -->
    <div class="sf-edit-modal-footer-compact">
      <button type="button" id="sf-edit-img-save" class="sf-btn sf-btn-primary">
        <?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8'); ?>
      </button>
    </div>

  </div>
</div>
<script>
window.SF_I18N = <?= json_encode(array_merge($sfI18n, [
    'supervisor_chip_select' => sf_term('supervisor_chip_select', $uiLang),
    'supervisor_chip_selected' => sf_term('supervisor_chip_selected', $uiLang),
    'loading_text' => sf_term('loading_text', $uiLang),
    'select_worksite_first' => sf_term('select_worksite_first', $uiLang),
    'no_supervisors_for_worksite' => sf_term('no_supervisors_for_worksite', $uiLang),
    'error_loading_supervisors' => sf_term('error_loading_supervisors', $uiLang),
    'no_search_results' => sf_term('no_search_results', $uiLang),
    'global_worksite_label' => sf_term('worksite_global', $uiLang),
    'no_name_label' => sf_term('no_name_label', $uiLang),
    'validation_fill_missing' => sf_term('validation_fill_missing', $uiLang),
    'validation_select_language' => sf_term('validation_select_language', $uiLang),
    'validation_select_type' => sf_term('validation_select_type', $uiLang),
    'validation_select_worksite' => sf_term('validation_select_worksite', $uiLang),
    'validation_enter_event_time' => sf_term('validation_enter_event_time', $uiLang),
    'validation_time_not_future' => sf_term('validation_time_not_future', $uiLang),
    'validation_select_base_or_standalone' => sf_term('validation_select_base_or_standalone', $uiLang),
    'validation_enter_title' => sf_term('validation_enter_title', $uiLang),
    'validation_enter_short_desc' => sf_term('validation_enter_short_desc', $uiLang),
    'validation_short_desc_too_long' => sf_term('validation_short_desc_too_long', $uiLang),
    'validation_enter_description' => sf_term('validation_enter_description', $uiLang),
    'validation_desc_too_long' => sf_term('validation_desc_too_long', $uiLang),
    'validation_root_causes_too_long' => sf_term('validation_root_causes_too_long', $uiLang),
    'validation_actions_too_long' => sf_term('validation_actions_too_long', $uiLang),
    'validation_image_required' => sf_term('validation_image_required', $uiLang),
    'validation_fill_missing' => sf_term('validation_fill_missing', $uiLang),
    'validation_select_language' => sf_term('validation_select_language', $uiLang),
    'validation_select_type' => sf_term('validation_select_type', $uiLang),
    'validation_select_worksite' => sf_term('validation_select_worksite', $uiLang),
    'validation_enter_event_time' => sf_term('validation_enter_event_time', $uiLang),
    'validation_time_not_future' => sf_term('validation_time_not_future', $uiLang),
    'validation_select_base_or_standalone' => sf_term('validation_select_base_or_standalone', $uiLang),
    'validation_enter_title' => sf_term('validation_enter_title', $uiLang),
    'validation_enter_short_desc' => sf_term('validation_enter_short_desc', $uiLang),
    'validation_short_desc_too_long' => sf_term('validation_short_desc_too_long', $uiLang),
    'validation_enter_description' => sf_term('validation_enter_description', $uiLang),
    'validation_desc_too_long' => sf_term('validation_desc_too_long', $uiLang),
    'validation_root_causes_too_long' => sf_term('validation_root_causes_too_long', $uiLang),
    'validation_actions_too_long' => sf_term('validation_actions_too_long', $uiLang),
    'validation_image_required' => sf_term('validation_image_required', $uiLang),
    'validation_select_at_least_one_site_manager' => sf_term('validation_select_at_least_one_site_manager', $uiLang),
    'validation_title_required' => sf_term('validation_title_required', $uiLang),
	'first_release' => sf_term('first_release', $uiLang),
	'dangerous_situation' => sf_term('dangerous_situation', $uiLang),
	'related_flash_placeholder' => sf_term('related_flash_placeholder', $uiLang),
	'related_flash_search_placeholder' => sf_term('related_flash_search_placeholder', $uiLang),
	'related_flash_empty_text' => sf_term('related_flash_empty_text', $uiLang),
	'related_flash_fallback_badge' => sf_term('related_flash_fallback_badge', $uiLang),
	'edit_image' => sf_term('edit_image', $uiLang),
	'saving_flash' => sf_term('saving_flash', $uiLang),
    'generating_preview' => sf_term('generating_preview', $uiLang),
    'btn_cancel' => sf_term('btn_cancel', $uiLang),
    'btn_save' => sf_term('btn_save', $uiLang),
    'btn_upload' => sf_term('btn_upload', $uiLang),
    'btn_edit' => sf_term('btn_edit', $uiLang),
    'btn_take_photo' => sf_term('btn_take_photo', $uiLang),
    'error_prefix' => sf_term('error_prefix', $uiLang),
    'please_wait' => sf_term('please_wait', $uiLang),
    'sending_for_review' => sf_term('sending_for_review', $uiLang),
    'processing_continues' => sf_term('processing_continues', $uiLang),
    'data_received_processing' => sf_term('data_received_processing', $uiLang),
    'saving_draft' => sf_term('saving_draft', $uiLang),
    'draft_saved' => sf_term('draft_saved', $uiLang),
    'save_failed' => sf_term('save_failed', $uiLang),
    'saving_changes' => sf_term('saving_changes', $uiLang),
    'changes_saved' => sf_term('changes_saved', $uiLang),
    'translation_saved' => sf_term('translation_saved', $uiLang),
    'redirecting' => sf_term('redirecting', $uiLang),
    'bundle_language_version_error' => sf_term('bundle_language_version_error', $uiLang),
    'bundle_language_version_saving' => sf_term('bundle_language_version_saving', $uiLang),
]), JSON_UNESCAPED_UNICODE) ?>;
window.SF_BASE_URL = "<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>";
window.SF_LIBRARY_SELECTIONS = <?= json_encode($libraryImageIds, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= sf_asset_url('assets/js/SFEditImage.js', $base) ?>"></script>
<script src="<?= sf_asset_url('assets/js/sf-image-edit-flow.js', $base) ?>"></script>
<script src="<?= sf_asset_url('assets/js/sf-grid-step.js', $base) ?>"></script>

<?php if (($editing && !$showSendToReview) || $parentIsAdvancedState): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const saveBtn = document.getElementById('sfSaveInline');
    const form = document.getElementById('sf-form');
    
    if (!saveBtn || !form) return;

    saveBtn.addEventListener('click', async function(e) {
        e.preventDefault();
        
        console.log('[Inline Save] Using window.sfFormSubmit');
        
        // Temporarily swap form action to inline edit endpoint
        const originalAction = form.action;
        const inlineActionUrl = saveBtn.dataset.actionUrl;
        
        if (!inlineActionUrl) {
            console.error('[Inline Save] Missing data-action-url on save button');
            alert('<?= htmlspecialchars(sf_term('error_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>');
            return;
        }
        
        try {
            // Set form action to inline edit endpoint
            form.action = inlineActionUrl;
            
            // CRITICAL: Sync annotations from editor to hidden input before submission
            // The image editor stores annotations in memory but doesn't auto-save to form input
            if (window.SFImageEditor && typeof window.SFImageEditor.getAllAnnotations === 'function') {
                const allAnnotations = window.SFImageEditor.getAllAnnotations();
                const annotationsInput = document.getElementById('sf-edit-annotations-data');
                if (annotationsInput) {
                    annotationsInput.value = JSON.stringify(allAnnotations || {});
                    console.log('[Inline Save] Synced annotations to form:', allAnnotations);
                    
                    // CRITICAL: Also convert to preview format for immediate preview capture
                    // Convert from {"image1": [...], "image2": [...]} to [{...ann, frameId: "sfPreviewImageFrame1"}, ...]
                    const previewAnnotations = [];
                    [1, 2, 3].forEach(slot => {
                        const key = `image${slot}`;
                        const slotAnnotations = allAnnotations[key];
                        if (Array.isArray(slotAnnotations) && slotAnnotations.length > 0) {
                            slotAnnotations.forEach(ann => {
                                if (ann) {
                                    previewAnnotations.push({
                                        ...ann,
                                        frameId: `sfPreviewImageFrame${slot}`, // Always use slot-based frameId for consistency
                                        slot: slot
                                    });
                                }
                            });
                        }
                    });
                    
                    const previewInput = document.getElementById('sf-annotations-data');
                    if (previewInput) {
                        previewInput.value = JSON.stringify(previewAnnotations);
                        console.log('[Inline Save] Converted annotations to preview format:', previewAnnotations);
                        
                        // Re-initialize annotations with delay to ensure DOM is ready (same as bootstrap.js)
                        if (window.Annotations && typeof window.Annotations.init === 'function') {
                            setTimeout(() => {
                                window.Annotations.init();
                            }, 100);
                        }
                    }
                }
            }
            
            // Tyhjennä preview-kentät - palvelin generoi kuvan
            const p1 = document.querySelector('input[name="preview_image_data"]');
            const p2 = document.querySelector('input[name="preview_image_data_2"]');
            if (p1) p1.value = '';
            if (p2) p2.value = '';
            
            // Käytä sfFormSubmit jos saatavilla
            if (typeof window.sfFormSubmit === 'function') {
                await window.sfFormSubmit(form, false, true);
            } else {
                console.error('[Inline Save] submit.js not loaded');
                throw new Error('Submit function not available');
            }
        } catch (err) {
            console.error('[Inline Save] Error:', err);
            alert('<?= htmlspecialchars(sf_term('error_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>: ' + err.message);
        } finally {
            // Always restore original form action
            form.action = originalAction;
        }
    });
});
</script>
<?php endif; ?>

<script>
// Välitä PHP:stä JavaScriptille olemassa olevat kuvat
window.SF_EXISTING_IMAGES = {
    slot1: {
        filename: <?= json_encode($flash['image_main'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
        url: <?= json_encode($getImageUrl($flash['image_main'] ??  null), JSON_UNESCAPED_UNICODE) ?>,
        transform: <?= json_encode($image1_transform, JSON_UNESCAPED_UNICODE) ?>
    },
    slot2: {
        filename: <?= json_encode($flash['image_2'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
        url: <?= json_encode($getImageUrl($flash['image_2'] ?? null), JSON_UNESCAPED_UNICODE) ?>,
        transform:  <?= json_encode($image2_transform, JSON_UNESCAPED_UNICODE) ?>
    },
    slot3: {
        filename: <?= json_encode($flash['image_3'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
        url: <?= json_encode($getImageUrl($flash['image_3'] ?? null), JSON_UNESCAPED_UNICODE) ?>,
        transform: <?= json_encode($image3_transform, JSON_UNESCAPED_UNICODE) ?>
    },
    annotations: <?= json_encode($flash['annotations_data'] ?? '{}', JSON_UNESCAPED_UNICODE) ?>,
    gridLayout: <?= json_encode($flash['grid_layout'] ?? 'grid-1', JSON_UNESCAPED_UNICODE) ?>,
    gridBitmap: <?= json_encode($flash['grid_bitmap'] ?? '', JSON_UNESCAPED_UNICODE) ?>
};

// Lataa kuvat kun sivu on valmis
document.addEventListener('DOMContentLoaded', function() {
    if (window.SF_EXISTING_IMAGES) {
        ['slot1', 'slot2', 'slot3'].forEach(function(slot) {
            const slotNum = slot.replace('slot', '');
            const data = window.SF_EXISTING_IMAGES[slot];
            
            if (data.filename) {
                const thumb = document.getElementById('sfImageThumb' + slotNum);
                const removeBtn = document.querySelector(`[data-slot="${slotNum}"].sf-image-remove-btn`);
                const editBtn = document.querySelector(`[data-slot="${slotNum}"].sf-image-edit-inline-btn`);
                
                if (thumb) {
                    thumb.src = data.url;
                    thumb.dataset.filename = data.filename;
                }
                
                if (removeBtn) {
                    removeBtn.classList.remove('hidden');
                }
                
                if (editBtn) {
                    editBtn.classList.remove('hidden');
                    editBtn.disabled = false;
                }
                
                console.log(`[Form] Loaded existing image for ${slot}: `, data.filename);
            }
        });
    }
});
</script>

</form>

<!-- VAHVISTUSMODAL - Lomakkeen ulkopuolella jotta JS löytää sen -->
<div
  class="sf-modal hidden"
  id="sfConfirmModal"
  role="dialog"
  aria-modal="true"
  aria-labelledby="sfConfirmModalTitle"
>
  <div class="sf-modal-content">
    <h2 id="sfConfirmModalTitle">
      <?= htmlspecialchars(sf_term('confirm_submit_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
    </h2>
    <p><?= htmlspecialchars(sf_term('confirm_submit_text', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="sf-help-text">
      <?= htmlspecialchars(sf_term('confirm_submit_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
    </p>
    <div class="sf-modal-actions">
      <button
        type="button"
        class="sf-btn sf-btn-secondary"
        data-modal-close="sfConfirmModal"
      >
        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </button>
      <button type="button" class="sf-btn sf-btn-primary" id="sfConfirmSubmit">
        <?= htmlspecialchars(sf_term('btn_confirm_yes', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </button>
    </div>
  </div>
</div>

<!-- Confirmation Modal for Supervisor Selection -->
<div id="sfSubmitConfirmModal" class="sf-modal hidden">
    <div class="sf-modal-overlay" onclick="window.sfCloseSubmitModal()"></div>
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3><?= htmlspecialchars(sf_term('submit_confirm_modal_title', $uiLang) ?? 'Vahvista lähetys', ENT_QUOTES, 'UTF-8') ?></h3>
            <button type="button" class="sf-modal-close" onclick="window.sfCloseSubmitModal()">×</button>
        </div>
        
        <div class="sf-modal-body">
            <?php if ($isInBundle || $isTranslationChild): ?>
            <!-- Bundle / translation mode: show all language versions being sent -->
            <div class="sf-bundle-submit-summary" style="margin: 0 0 16px 0; padding: 12px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;">
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #0369a1;">
                    <?= htmlspecialchars(sf_term('bundle_confirm_sending_label', $uiLang) ?? 'Tarkistukseen lähetetään seuraavat kieliversiot:', ENT_QUOTES, 'UTF-8') ?>
                </p>
                <ul style="margin: 0; padding: 0 0 0 20px; color: #0c4a6e;">
                    <?php foreach ($bundleAllMembers as $bm): ?>
                    <li style="margin-bottom: 4px;">
                        <strong><?= htmlspecialchars(strtoupper($bm['lang'] ?? '')) ?></strong>
                        <?php if (!empty($bm['title_short'])): ?>
                        — <?= htmlspecialchars($bm['title_short']) ?>
                        <?php endif; ?>
                        <span style="font-size:0.85em; color:#64748b;">(ID #<?= (int)$bm['id'] ?>)</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <p style="margin: 0 0 16px 0; color: #64748b;">
                <?= htmlspecialchars(sf_term('submit_confirm_modal_intro', $uiLang) ?? 'SafetyFlash lähetetään seuraavalle työmaavastaavalle tarkistettavaksi:', ENT_QUOTES, 'UTF-8') ?>
            </p>
            
            <!-- Selected Supervisors Summary -->
            <div class="sf-selected-supervisors" id="sfModalSupervisorsSummary">
                <!-- Populated by JavaScript -->
            </div>
            
            <!-- Workflow Visualization -->
            <div class="sf-send-flow">
                <div class="sf-flow-step"><?= htmlspecialchars(sf_term('site_manager_role_label', $uiLang) ?? 'Työmaavastaava', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="sf-flow-arrow">→</div>
                <div class="sf-flow-step"><?= htmlspecialchars(sf_term('type_safety_team', $uiLang) ?? 'Turvatiimi', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="sf-flow-arrow">→</div>
                <div class="sf-flow-step"><?= htmlspecialchars(sf_term('type_communications', $uiLang) ?? 'Viestintä', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="sf-flow-arrow">→</div>
                <div class="sf-flow-step"><?= htmlspecialchars(sf_term('published', $uiLang) ?? 'Julkaistu', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            
            <!-- Submission Comment Field -->
            <div class="sf-field" style="margin-top: 1rem;">
                <label for="submissionComment" class="sf-label">
                    <?= htmlspecialchars(sf_term('submission_comment_label', $uiLang) ?? 'Viesti hyväksyjälle (valinnainen)', ENT_QUOTES, 'UTF-8') ?>
                </label>
                <textarea 
                    id="submissionComment" 
                    name="submission_comment" 
                    class="sf-textarea"
                    rows="3"
                    maxlength="1000"
                    placeholder="<?= htmlspecialchars(sf_term('submission_comment_placeholder', $uiLang) ?? 'Lisää tarvittaessa lisätietoja käsittelijöille (työmaavastaava, turvatiimi ja viestintä)', ENT_QUOTES, 'UTF-8') ?>"
                ></textarea>
                <div class="sf-help-text"><?= htmlspecialchars(sf_term('submission_comment_help', $uiLang) ?? 'Viesti näkyy hyväksyjälle sähköpostissa ja SafetyFlashin kommenteissa.', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            
            <p style="font-size: 0.9rem; color: #64748b; margin: 16px 0 0 0;">
                <?= htmlspecialchars(sf_term('submit_confirm_modal_back_help', $uiLang) ?? 'Voit muokata valintoja palaamalla takaisin.', ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        
        <div class="sf-modal-footer">
            <button type="button" class="sf-btn sf-btn-secondary" onclick="window.sfEditSupervisors()">
                ← <?= htmlspecialchars(sf_term('btn_edit', $uiLang) ?? 'Muokkaa', ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-secondary" onclick="window.sfCloseSubmitModal()">
                <?= htmlspecialchars(sf_term('btn_cancel', $uiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" onclick="window.sfConfirmSubmit()">
                <?= htmlspecialchars(sf_term('step6_short', $uiLang) ?? 'Lähetä', ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<?php
// Kuvapankki-modaali
$currentUiLang = $uiLang;
include __DIR__ . '/../partials/image_library_modal.php';
?>

<?php
// Kehokarttamodaali (Ensitiedote-loukkaantumiset)
include __DIR__ . '/../partials/body_map_modal.php';
?>

<script src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/js/body-map.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  if (window.ImageLibrary) {
    ImageLibrary.init('<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>');
  }
});
</script>

<div id="sfConfirmRemoveModal" class="sf-modal hidden">
  <div class="sf-modal-dialog sf-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="sfConfirmRemoveTitle">
    <div class="sf-modal-header">
      <h3 id="sfConfirmRemoveTitle">
        <?= htmlspecialchars(sf_term('confirm_remove_image_title', $uiLang) ?? 'Poista kuva', ENT_QUOTES, 'UTF-8') ?>
      </h3>
      <button type="button" class="sf-modal-close" id="sfConfirmRemoveClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $uiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">×</button>
    </div>

    <div class="sf-modal-body">
      <p id="sfConfirmRemoveText" class="sf-confirm-text">
        <?= htmlspecialchars(sf_term('confirm_remove_image_text', $uiLang) ?? 'Haluatko poistaa tämän kuvan? Kuva ja sen säädöt poistetaan.', ENT_QUOTES, 'UTF-8') ?>
      </p>
    </div>

    <div class="sf-modal-footer">
      <button type="button" id="sfConfirmRemoveNo" class="sf-btn sf-btn-secondary">
        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
      </button>
      <button type="button" id="sfConfirmRemoveYes" class="sf-btn sf-btn-danger">
        <?= htmlspecialchars(sf_term('btn_delete', $uiLang) ?? 'Poista', ENT_QUOTES, 'UTF-8') ?>
      </button>
    </div>
  </div>
</div>

<!-- Close-form confirmation modal -->
<div id="sfCloseConfirmModal" class="sf-modal hidden sf-modal-small sf-modal-centered" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="sfCloseConfirmTitle">
  <div class="sf-modal-content">
    <div class="sf-modal-header">
      <h3 id="sfCloseConfirmTitle">
        <?= htmlspecialchars(sf_term('form_close_confirm_title', $uiLang) ?: 'Poistu lomakkeelta?', ENT_QUOTES, 'UTF-8') ?>
      </h3>
      <button type="button" class="sf-modal-close-btn" id="sfCloseConfirmDismiss" aria-label="<?= htmlspecialchars(sf_term('btn_close', $uiLang) ?: 'Sulje', ENT_QUOTES, 'UTF-8') ?>">×</button>
    </div>
    <div class="sf-modal-body">
      <p><?= htmlspecialchars(sf_term('form_close_confirm_text', $uiLang) ?: 'Haluatko varmasti poistua? Tallentamattomat muutokset menetetään.', ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="sf-modal-actions">
      <button type="button" class="sf-btn sf-btn-secondary" id="sfCloseConfirmCancel">
        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang) ?: 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
      </button>
      <button type="button" class="sf-btn sf-btn-danger" id="sfCloseConfirmLeave">
        <?= htmlspecialchars(sf_term('form_close_confirm_leave', $uiLang) ?: 'Poistu', ENT_QUOTES, 'UTF-8') ?>
      </button>
    </div>
  </div>
</div>

<script>
(function () {
    'use strict';

    var isFormDirty = <?= $editing ? 'true' : 'false' ?>;
    var listUrl = <?= json_encode($base . '/index.php?page=list', JSON_UNESCAPED_SLASHES) ?>;

    // Mark dirty on any input/change in the form
    var sfForm = document.getElementById('sf-form');
    if (sfForm) {
        sfForm.addEventListener('change', function () { isFormDirty = true; }, { passive: true });
        sfForm.addEventListener('input', function () { isFormDirty = true; }, { passive: true });
    }

    function openCloseModal() {
        var modal = document.getElementById('sfCloseConfirmModal');
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        var firstBtn = modal.querySelector('button');
        if (firstBtn) firstBtn.focus();
    }

    function closeCloseModal() {
        var modal = document.getElementById('sfCloseConfirmModal');
        if (!modal) return;
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    }

    function handleCloseBtn() {
        if (!isFormDirty) {
            window.location.href = listUrl;
        } else {
            openCloseModal();
        }
    }

    // X button in progress bar
    var closeBtn = document.getElementById('sfFormCloseBtn');
    if (closeBtn) {
        closeBtn.addEventListener('click', handleCloseBtn);
    }

    // Modal: "Peruuta" – close modal
    var cancelBtn = document.getElementById('sfCloseConfirmCancel');
    var dismissBtn = document.getElementById('sfCloseConfirmDismiss');
    if (cancelBtn) cancelBtn.addEventListener('click', closeCloseModal);
    if (dismissBtn) dismissBtn.addEventListener('click', closeCloseModal);

    // Modal: "Poistu" – navigate away
    var leaveBtn = document.getElementById('sfCloseConfirmLeave');
    if (leaveBtn) {
        leaveBtn.addEventListener('click', function () {
            window.location.href = listUrl;
        });
    }

    // Close modal on backdrop click
    var modal = document.getElementById('sfCloseConfirmModal');
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeCloseModal();
        });
    }

    // Expose so external modules can mark dirty if needed
    window.sfFormSetDirty = function () { isFormDirty = true; };
})();
</script>

<!-- ===== KIELIVERSIO-MODAL (Bundle workflow) ===== -->
<div id="sfAddLanguageModal" class="sf-modal hidden" role="dialog" aria-modal="true" aria-labelledby="sfAddLanguageModalTitle">
  <div class="sf-modal-overlay" id="sfAddLanguageOverlay"></div>
  <div class="sf-modal-content" style="max-width:480px">
    <div class="sf-modal-header">
      <h3 id="sfAddLanguageModalTitle">
        ➕ <?= htmlspecialchars(sf_term('add_language_modal_title', $uiLang) ?? 'Lisää kieliversio', ENT_QUOTES, 'UTF-8') ?>
      </h3>
      <button type="button" class="sf-modal-close" id="sfAddLanguageClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $uiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">×</button>
    </div>
    <div class="sf-modal-body">
      <p style="margin:0 0 16px 0;color:#64748b">
        <?= htmlspecialchars(sf_term('add_language_modal_intro', $uiLang) ?? 'Nykyinen versio tallennetaan luonnokseksi. Valitse kieli uudelle kieliversion:', ENT_QUOTES, 'UTF-8') ?>
      </p>
      <div id="sfLangOptions" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px"></div>
      <p id="sfLangOptionsEmpty" style="display:none;color:#e53e3e;font-size:.9rem">
        <?= htmlspecialchars(sf_term('add_language_all_used', $uiLang) ?? 'Kaikki tuetut kieliversiot on jo luotu.', ENT_QUOTES, 'UTF-8') ?>
      </p>
    </div>
    <div class="sf-modal-footer" style="display:flex;justify-content:flex-end;gap:8px">
      <button type="button" class="sf-btn sf-btn-secondary" id="sfAddLanguageCancel">
        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
      </button>
      <button type="button" class="sf-btn sf-btn-primary" id="sfAddLanguageConfirm" disabled>
        <?= htmlspecialchars(sf_term('btn_continue', $uiLang) ?? 'Jatka →', ENT_QUOTES, 'UTF-8') ?>
      </button>
    </div>
  </div>
</div>

<script>
(function () {
    'use strict';

    var addBtn       = document.getElementById('sfAddLanguageVersion');
    if (!addBtn) return;  // Not shown in current state

    var modal        = document.getElementById('sfAddLanguageModal');
    var overlay      = document.getElementById('sfAddLanguageOverlay');
    var closeBtn     = document.getElementById('sfAddLanguageClose');
    var cancelBtn    = document.getElementById('sfAddLanguageCancel');
    var confirmBtn   = document.getElementById('sfAddLanguageConfirm');
    var optionsDiv   = document.getElementById('sfLangOptions');
    var emptyMsg     = document.getElementById('sfLangOptionsEmpty');

    var allLangs     = <?= json_encode($configLanguages, JSON_UNESCAPED_UNICODE) ?>;
    var usedLangs    = <?= json_encode($bundleUsedLangs, JSON_UNESCAPED_UNICODE) ?>;
    var langNames    = {fi:'Suomi 🇫🇮', sv:'Ruotsi 🇸🇪', en:'Englanti 🇬🇧', it:'Italia 🇮🇹', el:'Kreikka 🇬🇷'};
    var baseUrl      = (window.SF_BASE_URL || '').replace(/\/$/, '');
    var currentFlashLang = <?= json_encode((string)($flash['lang'] ?? 'fi'), JSON_UNESCAPED_UNICODE) ?>;
    var bundleRootId = <?= (int)($bundleGroupId ?? $editId ?? 0) ?>;

    var selectedLang = null;

    function openModal() {
        selectedLang = null;
        confirmBtn.disabled = true;
        optionsDiv.innerHTML = '';

        // Re-read the currently selected language from the form (may differ from page-load value)
        var currentLangInput = document.querySelector('input[name="lang"]:checked') ||
                               document.querySelector('input[name="lang"]');
        var currentLang = currentLangInput ? currentLangInput.value : usedLangs[0];

        // Merge DB-used langs with the current form lang
        var effectiveUsed = usedLangs.slice();
        if (currentLang && effectiveUsed.indexOf(currentLang) === -1) {
            effectiveUsed.push(currentLang);
        }

        var available = allLangs.filter(function(l) { return effectiveUsed.indexOf(l) === -1; });

        if (available.length === 0) {
            emptyMsg.style.display = 'block';
            optionsDiv.style.display = 'none';
        } else {
            emptyMsg.style.display = 'none';
            optionsDiv.style.display = 'flex';
            available.forEach(function(lang) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'sf-lang-option-btn';
                btn.dataset.lang = lang;
                btn.textContent = langNames[lang] || lang.toUpperCase();
                btn.style.cssText = 'padding:10px 18px;border:2px solid #cbd5e1;border-radius:8px;background:#fff;cursor:pointer;font-size:1rem;transition:all .15s';
                btn.addEventListener('click', function() {
                    optionsDiv.querySelectorAll('.sf-lang-option-btn').forEach(function(b) {
                        b.style.borderColor = '#cbd5e1';
                        b.style.background  = '#fff';
                        b.style.fontWeight  = '';
                    });
                    btn.style.borderColor = '#2563eb';
                    btn.style.background  = '#eff6ff';
                    btn.style.fontWeight  = '600';
                    selectedLang = lang;
                    confirmBtn.disabled = false;
                });
                optionsDiv.appendChild(btn);
            });
        }

        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
    }

    function closeModal() {
        modal.classList.add('hidden');
        document.body.classList.remove('sf-modal-open');
    }

    addBtn.addEventListener('click', openModal);
    closeBtn  && closeBtn.addEventListener('click', closeModal);
    cancelBtn && cancelBtn.addEventListener('click', closeModal);
    overlay   && overlay.addEventListener('click', closeModal);

    confirmBtn.addEventListener('click', async function() {
        if (!selectedLang) return;
        closeModal();

        var form = document.getElementById('sf-form');
        if (!form) return;

        // Disable the button to prevent double-clicks
        addBtn.disabled = true;

        // Show a loading indicator if available
        if (typeof window.sfFormSubmit !== 'function') {
            // Minimal fallback loading indication
            var i18nLoading = window.SF_I18N || {};
            addBtn.textContent = '⏳ ' + (i18nLoading.saving_draft || 'Tallennetaan…');
        }

        try {
            // 1. Save current form as a draft
            var draftData = new FormData(form);
            draftData.set('submission_type', 'draft');
            draftData.set('is_ajax', '1');
            if (currentFlashLang) {
                draftData.set('lang', currentFlashLang);
            }

            var draftResp = await fetch(form.action, { method: 'POST', body: draftData });
            var draftText = await draftResp.text();
            var draftResult = null;
            try { draftResult = JSON.parse(draftText); } catch (parseErr) { console.error('Draft response parse error:', parseErr); }

            if (!draftResult || !draftResult.ok || !draftResult.flash_id) {
                var i18nSave = window.SF_I18N || {};
                throw new Error((draftResult && draftResult.error) ? draftResult.error : (i18nSave.save_failed || 'Tallennus epäonnistui'));
            }

            var flashId = draftResult.flash_id;

            // 2. Create the new language version (images preserved, drawings cleared)
            var bundleData = new FormData();
            // bundleRootId is the translation-group root id from server-side bundle data.
            // Fallback to just-saved flashId only if root id is unexpectedly unavailable.
            bundleData.append('source_id', String(bundleRootId > 0 ? bundleRootId : flashId));
            bundleData.append('target_lang', selectedLang);

            var csrfInput = form.querySelector('input[name="csrf_token"]');
            if (csrfInput) bundleData.append('csrf_token', csrfInput.value);

            var bundleResp = await fetch(baseUrl + '/app/api/bundle_add_language.php', {
                method: 'POST',
                body: bundleData
            });
            var bundleText = await bundleResp.text();
            var bundleResult = null;
            try { bundleResult = JSON.parse(bundleText); } catch (parseErr) { console.error('Bundle response parse error:', parseErr); }

            if (!bundleResult || !bundleResult.success) {
                var i18nBundle = window.SF_I18N || {};
                throw new Error((bundleResult && bundleResult.error) ? bundleResult.error : (i18nBundle.bundle_language_version_error || 'Kieliversion luonti epäonnistui'));
            }

            // 3. Navigate to the new language version's form
            var i18nNav = window.SF_I18N || {};
            if (i18nNav.bundle_language_version_saving && typeof window.sfToast === 'function') {
                window.sfToast('success', i18nNav.bundle_language_version_saving);
            }
            window.location.href = bundleResult.redirect;

        } catch (err) {
            addBtn.disabled = false;
            addBtn.innerHTML = '➕ <?= htmlspecialchars(sf_term('btn_add_language_version', $uiLang) ?? 'Lisää kieliversio', ENT_QUOTES, 'UTF-8') ?>';
            var i18n = window.SF_I18N || {};
            var errorMsg = (i18n.error_prefix || 'Virhe:') + ' ' + err.message;
            if (typeof window.sfToast === 'function') {
                window.sfToast('error', errorMsg);
            } else {
                alert(errorMsg);
            }
        }
    });
})();
</script>
<?php if ($showSavedNotice): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var msg = <?= json_encode(sf_term('draft_saved', $uiLang) ?? 'Luonnos tallennettu.', JSON_UNESCAPED_UNICODE) ?>;
    if (typeof window.sfToast === 'function') {
        window.sfToast('success', msg);
    }
    // Clean up URL to remove ?saved=1 without reload
    if (window.history && window.history.replaceState) {
        var url = window.location.href.replace(/[?&]saved=1/, '').replace(/\?$/, '');
        window.history.replaceState({}, '', url);
    }
});
</script>
<?php endif; ?>