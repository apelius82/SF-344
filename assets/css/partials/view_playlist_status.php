<?php
/**
 * SafetyFlash - View Page: Playlist Status Display
 * 
 * Näyttää flashin tilan infonäyttö-playlistassa.
 * Vain julkaistuille flasheille. Admineille, turvatiimille ja viestinnälle
 * toiminnot poistaa/palauttaa.
 * 
 * @package SafetyFlash
 * @subpackage Partials
 * @created 2026-02-19
 * 
 * Required variables:
 * @var array $flash Flash data from database
 * @var string $currentUiLang Current UI language
 * @var int $id Flash ID
 * @var bool $isAdmin User is admin
 * @var bool $isSafety User is safety team
 * @var bool $isComms User is communications team
 */

// Näytetään vain julkaistuille flasheille
if (!isset($flash['state']) || $flash['state'] !== 'published') {
    return;
}

// Tarkista onko flashilla aktiivisia display target -rivejä
$hasActiveTargets = false;
if (isset($pdo)) {
    try {
        $stmtActiveCount = $pdo->prepare("SELECT 1 FROM sf_flash_display_targets WHERE flash_id = ? AND is_active = 1 LIMIT 1");
        $stmtActiveCount->execute([(int)$id]);
        $hasActiveTargets = $stmtActiveCount->fetch() !== false;
    } catch (Throwable $eac) {
        // Silently ignore — migration may not be applied yet
    }
}

if (!$hasActiveTargets) {
    return;
}

// Määritä playlist-status
$displayStatus = 'active'; // oletus
$displayExpiresAt = $flash['display_expires_at'] ?? null;
$displayRemovedAt = $flash['display_removed_at'] ?? null;

$displayDurationSeconds = isset($flash['display_duration_seconds']) && (int)$flash['display_duration_seconds'] > 0
    ? (int)$flash['display_duration_seconds']
    : 30;

if ($displayRemovedAt !== null) {
    $displayStatus = 'removed';
} elseif ($displayExpiresAt !== null && strtotime($displayExpiresAt) < time()) {
    $displayStatus = 'expired';
}

// Oikeudet hallintaan (admin, turvatiimi, viestintä)
$canManage = $isAdmin || $isSafety || $isComms;

// Hae kaikki aktiiviset ajolistat, joihin tämä SafetyFlash on liitetty
$worksiteApiKey = null;
$worksiteLabel = null;
$activeDisplayLabels = [];
$activeDisplayTargets = [];
$allScreensSelected = false;

if (isset($pdo) && in_array($displayStatus, ['active', 'expired'], true)) {
    try {
        $stmtTargets = $pdo->prepare("
            SELECT DISTINCT
                k.id,
                k.api_key,
                COALESCE(NULLIF(k.label, ''), NULLIF(k.site, ''), CONCAT('#', k.id)) AS display_name
            FROM sf_flash_display_targets t
            JOIN sf_display_api_keys k ON k.id = t.display_key_id
            WHERE t.flash_id = ?
              AND t.is_active = 1
              AND k.is_active = 1
            ORDER BY k.sort_order ASC, display_name ASC, k.id ASC
        ");
        $stmtTargets->execute([(int)$id]);
        $activeDisplayTargets = $stmtTargets->fetchAll(PDO::FETCH_ASSOC);

        foreach ($activeDisplayTargets as $targetRow) {
            $activeDisplayLabels[] = (string)$targetRow['display_name'];
        }

        if (!empty($activeDisplayTargets)) {
            $worksiteApiKey = $activeDisplayTargets[0]['api_key'] ?? null;
            $worksiteLabel = $activeDisplayTargets[0]['display_name'] ?? null;
        }

        $stmtTotal = $pdo->prepare("
            SELECT COUNT(*)
            FROM sf_display_api_keys
            WHERE is_active = 1
        ");
        $stmtTotal->execute();
        $totalDisplays = (int)$stmtTotal->fetchColumn();

        if ($totalDisplays > 0 && count($activeDisplayLabels) >= $totalDisplays) {
            $allScreensSelected = true;
        }
    } catch (Throwable $ek) {
        // Silently ignore — migration may not be applied yet
    }
}

?>

<div class="sf-playlist-status-card sf-playlist-status-<?= htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8') ?>">
    <div class="sf-playlist-status-header">
        <h4>
            <?php if ($displayStatus === 'active'): ?>
                <?= htmlspecialchars(sf_term('playlist_status_active', $currentUiLang) ?? 'Näytetään infonäytöillä', ENT_QUOTES, 'UTF-8') ?>
            <?php elseif ($displayStatus === 'expired'): ?>
                <?= htmlspecialchars(sf_term('playlist_status_expired', $currentUiLang) ?? 'Näyttöaika päättynyt', ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
                <?= htmlspecialchars(sf_term('playlist_status_removed', $currentUiLang) ?? 'Poistettu playlistasta', ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </h4>
    </div>

    <div class="sf-playlist-status-body">
        <?php if (($displayStatus === 'active' || $displayStatus === 'expired') && !empty($activeDisplayTargets)): ?>
            <div class="sf-playlist-display-chip-list">
                <?php foreach ($activeDisplayTargets as $displayTarget): ?>
                    <?php
                    $displayLabel = (string)($displayTarget['display_name'] ?? '');
                    $targetApiKey = (string)($displayTarget['api_key'] ?? '');
                    $targetPlaylistUrl = "{$base}/app/api/display_playlist.php?key=" . rawurlencode($targetApiKey) . "&format=html&preview=1";
                    ?>

                    <?php if ($displayStatus === 'active' && $targetApiKey !== ''): ?>
                        <button
                            type="button"
                            class="sf-playlist-display-chip sf-playlist-display-chip-button"
                            data-modal-open="#modalKatsoAjolista"
                            data-playlist-url="<?= htmlspecialchars($targetPlaylistUrl, ENT_QUOTES, 'UTF-8') ?>"
                            data-playlist-label="<?= htmlspecialchars($displayLabel, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($displayLabel, ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    <?php else: ?>
                        <span class="sf-playlist-display-chip">
                            <?= htmlspecialchars($displayLabel, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php elseif ($allScreensSelected): ?>
            <div class="sf-playlist-display-chip-list">
                <span class="sf-playlist-display-chip">
                    <?= htmlspecialchars(sf_term('display_all_screens', $currentUiLang) ?? 'Kaikki näytöt', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if ($displayStatus === 'active'): ?>
            <?php if ($displayExpiresAt): ?>
                <?php
                $expiryDate = new DateTime($displayExpiresAt);
                $now = new DateTime();
                $interval = $now->diff($expiryDate);

                if ($interval->days > 0) {
                    $remainingText = sprintf(
                        sf_term('playlist_expires_in_days', $currentUiLang) ?? 'Vanhenee %d päivän kuluttua',
                        $interval->days
                    );
                } else {
                    $remainingText = sf_term('playlist_expires_today', $currentUiLang) ?? 'Vanhenee tänään';
                }
                ?>
                <div class="sf-playlist-meta-stack">
                    <span class="sf-playlist-meta-label"><?= htmlspecialchars($remainingText, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sf-playlist-meta-value"><?= htmlspecialchars($expiryDate->format('d.m.Y H:i'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php else: ?>
                <div class="sf-playlist-meta-stack">
                    <span class="sf-playlist-meta-label"><?= htmlspecialchars(sf_term('playlist_no_expiry', $currentUiLang) ?? 'Ei vanhenemisaikaa', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>
        <?php elseif ($displayStatus === 'expired'): ?>
            <div class="sf-playlist-meta-stack">
                <span class="sf-playlist-meta-label"><?= htmlspecialchars(sf_term('playlist_expired_at', $currentUiLang) ?? 'Vanheni', ENT_QUOTES, 'UTF-8') ?></span>
                <span class="sf-playlist-meta-value"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($displayExpiresAt)), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php else: ?>
            <div class="sf-playlist-meta-stack">
                <span class="sf-playlist-meta-label"><?= htmlspecialchars(sf_term('playlist_removed_at', $currentUiLang) ?? 'Poistettu', ENT_QUOTES, 'UTF-8') ?></span>
                <span class="sf-playlist-meta-value"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($displayRemovedAt)), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canManage && $displayStatus === 'expired'): ?>
        <div class="sf-playlist-actions">
            <button type="button" class="sf-playlist-view-button sf-playlist-republish-button" data-open-display-targets>
                <span class="sf-playlist-view-button-text">
                    <?= htmlspecialchars(sf_term('btn_republish_to_displays', $currentUiLang) ?? 'Julkaise uudelleen', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </button>
        </div>
    <?php endif; ?>
</div>

<?php if ($worksiteApiKey && $displayStatus === 'active'): ?>
<!-- Katso ajolistat -modaali -->
<div class="sf-modal hidden" id="modalKatsoAjolista" role="dialog" aria-modal="true" aria-labelledby="modalKatsoAjolistaTitle">
    <div class="sf-modal-content sf-pm-preview-modal">
        <div class="sf-playlist-compact-head">
            <div class="sf-playlist-compact-title">
                <h3 id="modalKatsoAjolistaTitle">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/display.svg" alt="" aria-hidden="true">
                    <?= htmlspecialchars(sf_term('playlist_modal_title', $currentUiLang) ?? 'Infonäyttöjen ajolistat', ENT_QUOTES, 'UTF-8') ?>
                </h3>
                <p>
                    <?= htmlspecialchars(sf_term('playlist_modal_description', $currentUiLang) ?? 'Valitse infonäyttö nähdäksesi, miten tämä SafetyFlash näkyy kyseisen näytön ajolistassa.', ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>

        <div class="sf-playlist-compact-toolbar">
            <?php if (!empty($activeDisplayTargets)): ?>
                <div class="sf-playlist-target-selector sf-playlist-target-selector-compact">
                    <label for="sfPlaylistTargetSelect">
                        <?= htmlspecialchars(sf_term('label_select_display', $currentUiLang) ?? 'Näyttö', ENT_QUOTES, 'UTF-8') ?>
                    </label>

                    <select id="sfPlaylistTargetSelect" class="sf-playlist-target-select">
                        <?php foreach ($activeDisplayTargets as $displayTarget): ?>
                            <?php
                            $targetLabel = (string)($displayTarget['display_name'] ?? '');
                            $targetApiKey = (string)($displayTarget['api_key'] ?? '');
                            $targetPlaylistUrl = "{$base}/app/api/display_playlist.php?key=" . rawurlencode($targetApiKey) . "&format=html&preview=1";
                            ?>
                            <option
                                value="<?= htmlspecialchars($targetPlaylistUrl, ENT_QUOTES, 'UTF-8') ?>"
                                data-label="<?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="sf-playlist-meta-row">
                <span class="sf-playlist-meta-pill">
                    <?= htmlspecialchars(sf_term('playlist_duration_label', $currentUiLang) ?? 'Näyttöaika', ENT_QUOTES, 'UTF-8') ?>
                    <strong id="sfPlaylistMetaDuration"><?= (int)$displayDurationSeconds ?> s</strong>
                </span>

                <span class="sf-playlist-meta-pill" id="sfPlaylistMetaExpiresWrap">
                    <?= htmlspecialchars(sf_term('playlist_valid_until_label', $currentUiLang) ?? 'Voimassa asti', ENT_QUOTES, 'UTF-8') ?>
                    <strong id="sfPlaylistMetaExpires">
                        <?= $displayExpiresAt ? htmlspecialchars(date('d.m.Y H:i', strtotime($displayExpiresAt)), ENT_QUOTES, 'UTF-8') : '—' ?>
                    </strong>
                </span>
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
        </div>

        <div class="sf-pm-preview-body">
            <div class="sf-playlist-preview-frame">
                <div class="sf-playlist-loader" aria-live="polite">
                    <span class="sf-playlist-loader-spinner" aria-hidden="true"></span>
                    <span><?= htmlspecialchars(sf_term('playlist_loading', $currentUiLang) ?? 'Ladataan ajolistaa…', ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <iframe src="<?= htmlspecialchars("{$base}/app/api/display_playlist.php?key=" . rawurlencode((string)$worksiteApiKey) . "&format=html&preview=1", ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars($worksiteLabel ?? 'Ajolista', ENT_QUOTES, 'UTF-8') ?>"
                        class="sf-pm-preview-iframe"
                        sandbox="allow-scripts allow-same-origin"
                        loading="lazy"></iframe>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if ($worksiteApiKey && $displayStatus === 'active'): ?>
<script>
(function () {
    'use strict';

    function updatePlaylistModalFromChip(chip) {
        var playlistUrl = chip.getAttribute('data-playlist-url');

        if (!playlistUrl) {
            return;
        }

        var select = document.getElementById('sfPlaylistTargetSelect');

        if (!select) {
            return;
        }

        select.value = playlistUrl;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function initPlaylistChipButtons() {
        var chipButtons = document.querySelectorAll('.sf-playlist-display-chip-button');

        chipButtons.forEach(function (chip) {
            if (chip._sfPlaylistChipAttached) {
                return;
            }

            chip.addEventListener('click', function () {
                updatePlaylistModalFromChip(chip);
            });

            chip._sfPlaylistChipAttached = true;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPlaylistChipButtons);
    } else {
        initPlaylistChipButtons();
    }
})();
</script>
<?php endif; ?>