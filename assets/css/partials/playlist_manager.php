<?php
/**
 * SafetyFlash - Playlist Manager
 *
 * Ajolistan hallintanäkymä. Näyttää työmaan ajolistan ja mahdollistaa
 * järjestyksen muuttamisen ylös/alas-nuolilla tai drag & drop -toiminnolla.
 *
 * @package SafetyFlash
 * @subpackage Partials
 * @created 2026-02-22
 *
 * Required variables (from parent or URL params):
 * @var PDO|null   $pdo             Database connection (optional — creates own if missing)
 * @var string     $baseUrl         Base URL
 * @var string     $currentUiLang   Current UI language
 */

$displayKeyId = (int)($_GET['display_key_id'] ?? 0);
$selectionOnly = ($displayKeyId <= 0);

// Ensure DB connection
if (!isset($pdo)) {
    require_once __DIR__ . '/../../assets/lib/Database.php';
    try {
        $pdo = Database::getInstance();
    } catch (Throwable $e) {
        echo '<p class="sf-notice sf-notice-error">DB error</p>';
        return;
    }
}

// Fetch all active displays for navigation (always)
$allDisplays = [];
try {
    $stmtAll = $pdo->prepare("
        SELECT id, label, site, site_group
        FROM sf_display_api_keys
        WHERE is_active = 1
        ORDER BY site_group ASC, sort_order ASC, label ASC
    ");
    $stmtAll->execute();
    $allDisplays = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $allDisplays = [];
}

// Group displays by site_group for <optgroup>
$displayGroups = [];
foreach ($allDisplays as $d) {
    $g = $d['site_group'] ?: '';
    $displayGroups[$g][] = $d;
}

// Default vars
$displayKey = null;
$items = [];
$displayLabel = '—';
$playlistUrl = '';
$csrfToken = sf_csrf_token();

// If a display is selected, fetch display info + items
if (!$selectionOnly) {

    // Fetch display info
    try {
        $stmtKey = $pdo->prepare("SELECT id, label, site, lang, api_key FROM sf_display_api_keys WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmtKey->execute([$displayKeyId]);
        $displayKey = $stmtKey->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $displayKey = null;
    }

    if (!$displayKey) {
        echo '<p class="sf-notice sf-notice-error">Näyttöä ei löydy tai se ei ole aktiivinen.</p>';
        return;
    }

// Fetch playlist items
try {
    $stmtItems = $pdo->prepare("
        SELECT
            f.id,
            f.title,
            f.preview_filename,
            f.type,
            f.display_expires_at,
            COALESCE(t.sort_order, 0) AS sort_order
        FROM sf_flashes f
        INNER JOIN sf_flash_display_targets t ON t.flash_id = f.id
        WHERE t.display_key_id = :display_key_id
          AND t.is_active = 1
          AND f.state = 'published'
          AND (f.display_expires_at IS NULL OR f.display_expires_at > NOW())
          AND f.display_removed_at IS NULL
        ORDER BY COALESCE(t.sort_order, 0) ASC, f.published_at DESC
        LIMIT 100
    ");
    $stmtItems->execute([':display_key_id' => $displayKeyId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $items = [];
}

    $displayLabel = htmlspecialchars($displayKey['label'] ?? $displayKey['site'], ENT_QUOTES, 'UTF-8');
    $playlistUrl  = "{$baseUrl}/app/api/display_playlist.php?key={$displayKey['api_key']}&format=html";
}
?>

<div class="sf-page-container" id="playlistManagerWrap">
    <div class="sf-page-header sf-pm-page-header">
        <h1 class="sf-page-title">
            <svg class="sf-pm-heading-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>
                <polyline points="3 6 4 7 6 5"/><polyline points="3 12 4 13 6 11"/><polyline points="3 18 4 19 6 17"/>
            </svg>
            <?= htmlspecialchars(sf_term('playlist_manager_heading', $currentUiLang) ?? 'Ajolistan hallinta', ENT_QUOTES, 'UTF-8') ?>
            <?php if (!$selectionOnly): ?>
                — <?= $displayLabel ?>
            <?php endif; ?>
        </h1>

        <?php if (!$selectionOnly): ?>
        <button type="button"
                class="sf-btn sf-btn-outline-primary"
                data-modal-open="#modalPlaylistPreview">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:middle;">
                <rect x="2" y="3" width="20" height="14" rx="2"/><polyline points="8 21 12 17 16 21"/>
            </svg>
            <?= htmlspecialchars(sf_term('btn_view_playlist', $currentUiLang) ?? 'Katso ajolista', ENT_QUOTES, 'UTF-8') ?>
        </button>
        <?php endif; ?>
    </div>

    <?php if (count($allDisplays) > 0): ?>
        <div class="sf-pm-nav">
            <label for="sfPmDisplaySelect" class="sf-pm-nav-label">Valitse työmaanäyttö</label>

            <select id="sfPmDisplaySelect" class="sf-pm-nav-select"
                    data-nav-url="<?= htmlspecialchars("{$baseUrl}/index.php?page=playlist_manager&display_key_id=", ENT_QUOTES, 'UTF-8') ?>">
                <option value="" <?= ($selectionOnly ? 'selected' : '') ?> disabled>Valitse työmaanäyttö…</option>

                <?php foreach ($displayGroups as $groupName => $groupDisplays): ?>
                    <?php if ($groupName !== ''): ?>
                        <optgroup label="<?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>

                    <?php foreach ($groupDisplays as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= ((!$selectionOnly && (int)$d['id'] === $displayKeyId) ? 'selected' : '') ?>>
                            <?= htmlspecialchars($d['label'] ?? $d['site'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>

                    <?php if ($groupName !== ''): ?>
                        </optgroup>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
    <?php else: ?>
        <p class="sf-notice sf-notice-error">Näyttöjä ei löydy tai ne eivät ole aktiivisia.</p>
    <?php endif; ?>

    <?php if ($selectionOnly): ?>
        <div class="sf-empty-state">
            <img src="<?= htmlspecialchars("{$baseUrl}/assets/img/icons/display.svg", ENT_QUOTES, 'UTF-8') ?>" alt="" class="sf-empty-state-icon" aria-hidden="true">
            <p>Valitse työmaanäyttö ylhäältä nähdäksesi ajolistan.</p>
        </div>

    <?php elseif (empty($items)): ?>
        <div class="sf-empty-state">
            <img src="<?= htmlspecialchars("{$baseUrl}/assets/img/icons/display.svg", ENT_QUOTES, 'UTF-8') ?>" alt="" class="sf-empty-state-icon" aria-hidden="true">
            <p><?= htmlspecialchars(sf_term('playlist_empty', $currentUiLang) ?? 'Ajolista on tyhjä — ei aktiivisia flasheja tällä näytöllä', ENT_QUOTES, 'UTF-8') ?></p>
        </div>

    <?php else: ?>
        <div id="sfPlaylistSaveMsg" class="sf-notice sf-notice-success" style="display:none;">
            <?= htmlspecialchars(sf_term('playlist_reorder_saved', $currentUiLang) ?? 'Järjestys tallennettu', ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div class="sf-pm-stats-card">
            <div class="sf-pm-stat">
                <span class="sf-pm-stat-value"><?= count($items) ?></span>
                <span class="sf-pm-stat-label">Flashiä</span>
            </div>
            <div class="sf-pm-stat">
                <span class="sf-pm-stat-value"><?= htmlspecialchars(strtoupper($displayKey['lang'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="sf-pm-stat-label">Kieli</span>
            </div>
            <div class="sf-pm-stat-divider"></div>
            <span class="sf-pm-stat-hint">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
                    <path d="M12 16v-4"/><path d="M12 8h.01"/>
                </svg>
                Vedä tai käytä nuolia järjestyksen muuttamiseen
            </span>
        </div>

        <ul id="sfPlaylistItems" class="sf-playlist-manager-list"
            data-display-key-id="<?= $displayKeyId ?>"
            data-reorder-url="<?= htmlspecialchars("{$baseUrl}/app/api/playlist_reorder.php", ENT_QUOTES, 'UTF-8') ?>"
            data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <?php foreach ($items as $i => $item): ?>
                <?php
                $previewUrl = $item['preview_filename']
                    ? htmlspecialchars("{$baseUrl}/uploads/previews/{$item['preview_filename']}", ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars("{$baseUrl}/assets/img/camera-placeholder.png", ENT_QUOTES, 'UTF-8');

                $expiresAtRaw = $item['display_expires_at'] ?? null;
                $expiresAtText = 'Ei vanhene';

                if (!empty($expiresAtRaw)) {
                    $expiresTs = strtotime($expiresAtRaw);
                    if ($expiresTs) {
                        $expiresAtText = date('d.m.Y H:i', $expiresTs);
                    }
                }

                $viewUrl = htmlspecialchars("{$baseUrl}/index.php?page=view&id=" . (int)$item['id'], ENT_QUOTES, 'UTF-8');
                ?>
                <li class="sf-playlist-manager-item" data-flash-id="<?= (int)$item['id'] ?>">
                    <span class="sf-pm-drag-handle" title="Vedä siirtääksesi" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/>
                            <circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>
                            <circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/>
                        </svg>
                    </span>
                    <span class="sf-pm-item-index"><?= $i + 1 ?></span>
                    <img src="<?= $previewUrl ?>"
                         alt=""
                         class="sf-pm-thumb"
                         loading="lazy">
                    <div class="sf-pm-item-content">
                        <span class="sf-pm-title"><?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($item['type'])): ?>
                        <span class="sf-pm-type-badge"><?= htmlspecialchars($item['type'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <div class="sf-pm-meta">
                            <span class="sf-pm-expiry-label">Vanhenee:</span>
                            <span class="sf-pm-expiry-value"><?= htmlspecialchars($expiresAtText, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <span class="sf-pm-order-btns">
                        <a href="<?= $viewUrl ?>"
                           class="sf-btn sf-btn-outline-primary"
                           style="padding:6px 10px;font-size:12px;line-height:1;white-space:nowrap;">
                            Muokkaa
                        </a>
                        <button type="button"
                                class="sf-pm-btn-up"
                                title="<?= htmlspecialchars(sf_term('playlist_move_up', $currentUiLang) ?? 'Siirrä ylös', ENT_QUOTES, 'UTF-8') ?>"
                                aria-label="<?= htmlspecialchars(sf_term('playlist_move_up', $currentUiLang) ?? 'Siirrä ylös', ENT_QUOTES, 'UTF-8') ?>"
                                <?= ($i === 0) ? 'disabled' : '' ?>>
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="18 15 12 9 6 15"/>
                            </svg>
                        </button>
                        <button type="button"
                                class="sf-pm-btn-down"
                                title="<?= htmlspecialchars(sf_term('playlist_move_down', $currentUiLang) ?? 'Siirrä alas', ENT_QUOTES, 'UTF-8') ?>"
                                aria-label="<?= htmlspecialchars(sf_term('playlist_move_down', $currentUiLang) ?? 'Siirrä alas', ENT_QUOTES, 'UTF-8') ?>"
                                <?= ($i === count($items) - 1) ? 'disabled' : '' ?>>
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </button>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="sf-playlist-manager-actions">
            <button type="button" id="sfPlaylistSaveBtn" class="sf-btn sf-btn-primary">
                <?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna järjestys', ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    <?php endif; ?>
</div>

<link rel="stylesheet" href="<?= htmlspecialchars("{$baseUrl}/assets/css/display-ttl.css", ENT_QUOTES, 'UTF-8') ?>">
<script src="<?= htmlspecialchars("{$baseUrl}/assets/js/playlist-manager.js", ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars("{$baseUrl}/assets/js/display-playlist.js", ENT_QUOTES, 'UTF-8') ?>"></script>

<?php if (!$selectionOnly): ?>
<!-- Ajolistan esikatselu -modaali -->
<div class="sf-modal hidden" id="modalPlaylistPreview" role="dialog" aria-modal="true" aria-labelledby="modalPlaylistPreviewTitle">
    <div class="sf-modal-content sf-pm-preview-modal">
        <div class="sf-modal-header">
            <h3 id="modalPlaylistPreviewTitle">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:middle;margin-right:6px;">
                    <rect x="2" y="3" width="20" height="14" rx="2"/><polyline points="8 21 12 17 16 21"/>
                </svg>
                <?= htmlspecialchars(sf_term('btn_view_playlist', $currentUiLang) ?? 'Ajolista', ENT_QUOTES, 'UTF-8') ?> — <?= $displayLabel ?>
            </h3>
            <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>

        <div class="sf-playlist-nav" id="sfPlaylistNav">
            <button type="button" id="btnPlaylistPrev" class="sf-playlist-nav-btn"
                title="<?= htmlspecialchars(sf_term('btn_playlist_prev', $currentUiLang) ?? 'Edellinen', ENT_QUOTES, 'UTF-8') ?>"
                aria-label="<?= htmlspecialchars(sf_term('btn_playlist_prev', $currentUiLang) ?? 'Edellinen', ENT_QUOTES, 'UTF-8') ?>">&#9664;</button>

            <span id="sfPlaylistCounter" class="sf-playlist-counter">&#x2013; / &#x2013;</span>

            <button type="button" id="btnPlaylistPause" class="sf-playlist-nav-btn"
                data-label-pause="<?= htmlspecialchars(sf_term('btn_playlist_pause', $currentUiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>"
                data-label-resume="<?= htmlspecialchars(sf_term('btn_playlist_resume', $currentUiLang) ?? 'Jatka', ENT_QUOTES, 'UTF-8') ?>"
                title="<?= htmlspecialchars(sf_term('btn_playlist_pause', $currentUiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>"
                aria-label="<?= htmlspecialchars(sf_term('btn_playlist_pause', $currentUiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>"
                aria-pressed="false">&#x23F8;</button>

            <button type="button" id="btnPlaylistNext" class="sf-playlist-nav-btn"
                title="<?= htmlspecialchars(sf_term('btn_playlist_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?>"
                aria-label="<?= htmlspecialchars(sf_term('btn_playlist_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?>">&#9654;</button>
        </div>

        <div class="sf-pm-preview-body">
            <iframe src="<?= htmlspecialchars($playlistUrl, ENT_QUOTES, 'UTF-8') ?>"
                    title="<?= $displayLabel ?>"
                    class="sf-pm-preview-iframe"
                    sandbox="allow-scripts allow-same-origin"
                    loading="lazy"></iframe>
        </div>
    </div>
</div>
<?php endif; ?>