<?php
// app/pages/partials/view_meta_box.php

if (!function_exists('sf_term')) {
    require_once __DIR__ . '/../../includes/statuses.php';
}

$stateValue = (string)($flash['state'] ?? '');
if ($stateValue === '') {
    $stateValue = 'draft';
}

$statusDef = function_exists('sf_status_get') ? sf_status_get($stateValue) : null;
$metaStatusClass = trim((string)($statusDef['badge_class'] ?? 'sf-status--other'));
$statusLabel = function_exists('sf_status_label') ? (sf_status_label($stateValue, $currentUiLang) ?? '') : '';

$assetBase = '';
if (isset($base)) {
    $assetBase = rtrim((string)$base, '/');
} elseif (defined('BASE_URL')) {
    $assetBase = rtrim((string)BASE_URL, '/');
}

if (!function_exists('sf_view_meta_icon_url')) {
    function sf_view_meta_icon_url(string $assetBase, string $iconFile): string
    {
        return $assetBase . '/assets/img/icons/' . ltrim($iconFile, '/');
    }
}

$typeIconFile = 'safetyflash-icon.svg';

if (($flash['type'] ?? '') === 'red') {
    $typeIconFile = 'type-red.svg';
} elseif (($flash['type'] ?? '') === 'yellow') {
    $typeIconFile = 'type-yellow.svg';
} elseif (($flash['type'] ?? '') === 'green') {
    $typeIconFile = 'type-green.svg';
}

$siteText = trim((string)($flash['site'] ?? ''));
if (!empty($flash['site_detail'])) {
    $siteText .= ($siteText !== '' ? ' – ' : '') . (string)$flash['site_detail'];
}
if ($siteText === '') {
    $siteText = '-';
}

$occurredText = trim((string)($flash['occurredFmt'] ?? ''));
if ($occurredText === '') {
    $occurredText = '-';
}

$languageText = trim((string)($flash['lang'] ?? ''));
if ($languageText === '') {
    $languageText = '-';
}

$createdText = trim((string)($flash['createdFmt'] ?? ''));
if ($createdText === '') {
    $createdText = '-';
}

$updatedText = trim((string)($flash['updatedFmt'] ?? ''));
if ($updatedText === '') {
    $updatedText = '-';
}

$flashIdText = trim((string)($flash['id'] ?? ''));
if ($flashIdText === '') {
    $flashIdText = '-';
}

$hasBodyParts = !empty($existing_body_parts);
$canMarkBodyPartsFromMeta = !empty($canEditBodyParts);
$bodyPartsEmptyText = sf_term('body_map_none_marked', $currentUiLang) ?: '-';
$bodyPartsAddText = sf_term('body_map_add_short', $currentUiLang) ?: '+ Merkitse';

if (!function_exists('sf_view_plain_text_value')) {
    function sf_view_plain_text_value($value): string
    {
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace("/[ \t]+/", " ", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim((string)$text);
    }
}

if (!function_exists('sf_view_plain_text_to_html')) {
    function sf_view_plain_text_to_html(string $text): string
    {
        $lines = preg_split("/\n/", trim($text));
        $html = '';
        $inList = false;
        $currentListItem = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                if ($currentListItem !== '') {
                    $html .= '<li>' . nl2br(htmlspecialchars($currentListItem, ENT_QUOTES, 'UTF-8')) . '</li>';
                    $currentListItem = '';
                }

                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }

                continue;
            }

            if (preg_match('/^[•\-\*]\s*(.+)$/u', $line, $matches)) {
                if (!$inList) {
                    $html .= '<ul class="sf-view-plain-list-items">';
                    $inList = true;
                }

                if ($currentListItem !== '') {
                    $html .= '<li>' . nl2br(htmlspecialchars($currentListItem, ENT_QUOTES, 'UTF-8')) . '</li>';
                }

                $currentListItem = trim($matches[1]);
                continue;
            }

            if ($inList && $currentListItem !== '') {
                $currentListItem .= "\n" . $line;
                continue;
            }

            $html .= '<p>' . nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        if ($currentListItem !== '') {
            $html .= '<li>' . nl2br(htmlspecialchars($currentListItem, ENT_QUOTES, 'UTF-8')) . '</li>';
        }

        if ($inList) {
            $html .= '</ul>';
        }

        return $html;
    }
}

$plainContentSections = [];

$plainContentMap = [
    [
        'label' => sf_term('view_plain_title_label', $currentUiLang) ?: 'Otsikko',
        'value' => $flash['title'] ?? '',
    ],
    [
        'label' => sf_term('meta_summary_short', $currentUiLang) ?: 'Lyhyt yhteenveto',
        'value' => $flash['summary'] ?? '',
    ],
    [
        'label' => sf_term('meta_description_long', $currentUiLang) ?: 'Pitkä kuvaus',
        'value' => $flash['description'] ?? '',
    ],
    [
        'label' => sf_term('root_causes_label', $currentUiLang) ?: 'Juurisyyt',
        'value' => $flash['root_causes'] ?? '',
    ],
    [
        'label' => sf_term('actions_label', $currentUiLang) ?: 'Toimenpiteet',
        'value' => $flash['actions'] ?? '',
    ],
];

foreach ($plainContentMap as $plainContentItem) {
    $plainValue = sf_view_plain_text_value($plainContentItem['value']);

    if ($plainValue !== '') {
        $plainContentSections[] = [
            'label' => (string)$plainContentItem['label'],
            'value' => $plainValue,
        ];
    }
}

$plainTextCopyParts = [];

foreach ($plainContentSections as $plainContentSection) {
    $plainTextCopyParts[] = $plainContentSection['label'] . ":\n" . $plainContentSection['value'];
}

$plainTextCopy = implode("\n\n", $plainTextCopyParts);
?>

<div class="view-box meta-box sf-view-meta-panel">
    <div class="sf-editing-indicator" data-flash-id="<?= (int)$flash['id'] ?>">
        <div class="sf-editing-spinner"></div>
        <span class="sf-editing-text"></span>
    </div>

    <?php if (($flash['state'] ?? '') === 'pending_supervisor'): ?>
        <?php
        $canManageReviewers = function_exists('sf_is_admin_or_safety') && sf_is_admin_or_safety();

        $reviewers = [];
        try {
            $reviewerStmt = $pdo->prepare("
                SELECT 
                    fs.id,
                    fs.user_id,
                    fs.assigned_at,
                    u.first_name,
                    u.last_name,
                    u.email,
                    DATE_FORMAT(fs.assigned_at, '%d.%m.%Y %H:%i') as assigned_at_formatted
                FROM flash_supervisors fs
                INNER JOIN sf_users u ON u.id = fs.user_id
                WHERE fs.flash_id = ?
                ORDER BY fs.assigned_at DESC
            ");
            $reviewerStmt->execute([$flash['id']]);
            $reviewers = $reviewerStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Error fetching reviewers: ' . $e->getMessage());
        }
        ?>

        <section class="sf-view-meta-section">
            <div class="sf-view-meta-section-head">
                <span class="sf-view-meta-head-icon">
                    <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'supervisor_icon.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                </span>

                <h2><?= htmlspecialchars(sf_term('reviewer_section_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>

                <?php if ($canManageReviewers): ?>
                    <span class="reviewer-actions">
                        <button type="button"
                                class="reviewer-action-btn"
                                data-action="add"
                                data-flash-id="<?= (int)$flash['id'] ?>"
                                title="<?= htmlspecialchars(sf_term('add_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                            <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'add_new_icon.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                        </button>

                        <button type="button"
                                class="reviewer-action-btn"
                                data-action="replace"
                                data-flash-id="<?= (int)$flash['id'] ?>"
                                title="<?= htmlspecialchars(sf_term('replace_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                            <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'reverse_icon.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                        </button>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!empty($reviewers)): ?>
                <div class="reviewer-list" id="reviewerList">
                    <?php foreach ($reviewers as $reviewer): ?>
                        <?php
                        $name = trim(($reviewer['first_name'] ?? '') . ' ' . ($reviewer['last_name'] ?? ''));
                        $email = $reviewer['email'] ?? '';
                        $assignedAt = $reviewer['assigned_at_formatted'] ?? '';
                        ?>

                        <div class="reviewer-card" data-user-id="<?= (int)$reviewer['user_id'] ?>">
                            <div class="reviewer-info">
                                <div class="reviewer-name">
                                    <?= htmlspecialchars($name !== '' ? $name : ('ID ' . (int)$reviewer['user_id']), ENT_QUOTES, 'UTF-8') ?>
                                </div>

                                <?php if ($email): ?>
                                    <div class="reviewer-email">
                                        <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($assignedAt): ?>
                                    <div class="reviewer-assigned">
                                        <?= htmlspecialchars(sf_term('reviewer_assigned_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:
                                        <?= htmlspecialchars($assignedAt, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($canManageReviewers): ?>
                                <button type="button"
                                        class="reviewer-remove-btn"
                                        data-user-id="<?= (int)$reviewer['user_id'] ?>"
                                        data-flash-id="<?= (int)$flash['id'] ?>"
                                        title="<?= htmlspecialchars(sf_term('remove_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                                    <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'delete.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="reviewer-empty" id="reviewerEmpty">
                    <?= htmlspecialchars(sf_term('reviewer_no_reviewers', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php
    $languageReviewers = [];
    $currentMetaUser = function_exists('sf_current_user') ? sf_current_user() : null;
    $currentMetaUserId = (int)($currentMetaUser['id'] ?? 0);
    $currentMetaRoleId = (int)($currentMetaUser['role_id'] ?? 0);

	$canViewLanguageReviewersByRole = in_array($currentMetaRoleId, [1, 3, 4], true);
	$canViewLanguageReviewers = $canViewLanguageReviewersByRole && (($flash['state'] ?? '') !== 'published');

    if (!$canViewLanguageReviewers && $currentMetaUserId > 0 && (($flash['state'] ?? '') !== 'published')) {
        try {
            $assignedLanguageReviewerStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM sf_flash_language_reviewers
                WHERE flash_id = ?
                  AND user_id = ?
                  AND status IN ('pending', 'in_progress', 'completed')
            ");
            $assignedLanguageReviewerStmt->execute([(int)$flash['id'], $currentMetaUserId]);
            $canViewLanguageReviewers = ((int)$assignedLanguageReviewerStmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            error_log('Error checking current language reviewer visibility: ' . $e->getMessage());
        }
    }

    if ($canViewLanguageReviewers) {
        try {
            $languageReviewerStmt = $pdo->prepare("
            SELECT
                lr.id,
                lr.flash_id,
                lr.language_code,
                lr.user_id,
                lr.assigned_at,
                lr.can_publish,
                lr.status,
                u.first_name,
                u.last_name,
                u.email,
                DATE_FORMAT(lr.assigned_at, '%d.%m.%Y %H:%i') AS assigned_at_formatted
            FROM sf_flash_language_reviewers lr
            INNER JOIN sf_users u ON u.id = lr.user_id
            WHERE lr.flash_id = ?
              AND lr.status = 'pending'
            ORDER BY lr.assigned_at DESC
        ");
        $languageReviewerStmt->execute([(int)$flash['id']]);
            $languageReviewers = $languageReviewerStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Error fetching language reviewers: ' . $e->getMessage());
        }
    }
    ?>

    <?php if ($canViewLanguageReviewers && !empty($languageReviewers)): ?>
        <section class="sf-view-meta-section sf-view-language-review-section">
            <div class="sf-view-meta-section-head">
                <span class="sf-view-meta-head-icon">
                    <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'translate_icon.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                </span>

                <h2><?= htmlspecialchars(sf_term('language_review_current_section_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>

            <div class="reviewer-list sf-language-reviewer-list">
                <?php foreach ($languageReviewers as $languageReviewer): ?>
                    <?php
                    $languageReviewerName = trim((string)($languageReviewer['first_name'] ?? '') . ' ' . (string)($languageReviewer['last_name'] ?? ''));
                    $languageReviewerEmail = (string)($languageReviewer['email'] ?? '');
                    $languageReviewerAssignedAt = (string)($languageReviewer['assigned_at_formatted'] ?? '');
                    $languageCode = strtolower((string)($languageReviewer['language_code'] ?? ''));
                    $languageLabel = sf_term('language_review_language_' . $languageCode, $currentUiLang);
                    if ($languageLabel === 'language_review_language_' . $languageCode) {
                        $languageLabel = strtoupper($languageCode);
                    }
                    ?>

                    <div class="reviewer-card sf-language-reviewer-card" data-user-id="<?= (int)$languageReviewer['user_id'] ?>">
                        <div class="reviewer-info">
                            <div class="reviewer-name">
                                <?= htmlspecialchars($languageReviewerName !== '' ? $languageReviewerName : ('ID ' . (int)$languageReviewer['user_id']), ENT_QUOTES, 'UTF-8') ?>
                            </div>

                            <?php if ($languageReviewerEmail !== ''): ?>
                                <div class="reviewer-email">
                                    <?= htmlspecialchars($languageReviewerEmail, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>

                            <div class="reviewer-assigned">
                                <?= htmlspecialchars(sf_term('language_review_current_language', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:
                                <?= htmlspecialchars($languageLabel, ENT_QUOTES, 'UTF-8') ?>
                            </div>

                            <?php if ($languageReviewerAssignedAt !== ''): ?>
                                <div class="reviewer-assigned">
                                    <?= htmlspecialchars(sf_term('reviewer_assigned_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:
                                    <?= htmlspecialchars($languageReviewerAssignedAt, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($languageReviewer['can_publish'])): ?>
                                <div class="sf-language-review-publish-note">
                                    <?= htmlspecialchars(sf_term('language_review_current_can_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="sf-view-meta-hero">
        <span class="sf-view-meta-type-icon">
            <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, $typeIconFile), ENT_QUOTES, 'UTF-8') ?>" alt="">
        </span>

        <div class="sf-view-meta-hero-content">
            <span class="sf-view-meta-eyebrow"><?= htmlspecialchars(sf_term('view_details_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></strong>

            <?php if ($statusLabel !== ''): ?>
                <span class="sf-view-meta-status <?= htmlspecialchars($metaStatusClass, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                </span>
            <?php endif; ?>
        </div>
    </section>

    <section class="sf-view-meta-section sf-view-meta-section--compact">
       

        <div class="sf-view-meta-list">
            <div class="sf-view-meta-row">
                <span class="sf-view-meta-row-icon">
                    <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'worksite.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                </span>
                <span class="sf-view-meta-row-label"><?= htmlspecialchars(sf_term('meta_site', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="sf-view-meta-row-value"><?= htmlspecialchars($siteText, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="sf-view-meta-row">
                <span class="sf-view-meta-row-icon">
                    <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'calendar.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                </span>
                <span class="sf-view-meta-row-label"><?= htmlspecialchars(sf_term('meta_occurred_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="sf-view-meta-row-value"><?= htmlspecialchars($occurredText, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="sf-view-meta-row" id="sfViewBodyPartsSection">
                <span class="sf-view-meta-row-icon">
                    <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'injury_icon.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                </span>

                <span class="sf-view-meta-row-label"><?= htmlspecialchars(sf_term('body_parts_section_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>

                <span class="sf-view-meta-row-value sf-view-body-parts-value">
                    <span id="sfInjuryTags" class="sf-injury-tags"></span>

                    <?php if ($canMarkBodyPartsFromMeta): ?>
                        <button type="button" id="sfMetaBodyMapBtn" class="sf-body-parts-empty-action">
                            <?= htmlspecialchars($bodyPartsAddText, ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    <?php else: ?>
                        <span class="sf-body-parts-empty-note">
                            <?= htmlspecialchars($bodyPartsEmptyText, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </section>

    <details class="sf-view-meta-section sf-view-meta-details sf-view-plain-details">
        <summary>
            <span class="sf-view-meta-head-icon">
                <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'text.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
            </span>

            <span><?= htmlspecialchars(sf_term('view_plain_content_title', $currentUiLang) ?: 'Tekstiversio', ENT_QUOTES, 'UTF-8') ?></span>

            <img class="sf-view-meta-chevron" src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'chevron-down.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
        </summary>

        <div class="sf-view-plain-content">
            <?php if (!empty($plainContentSections)): ?>
                <button
                    type="button"
                    class="sf-view-plain-copy-btn"
                    data-copy-text="<?= htmlspecialchars($plainTextCopy, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(sf_term('view_plain_copy_btn', $currentUiLang) ?: 'Kopioi tekstit', ENT_QUOTES, 'UTF-8') ?>
                </button>

                <div class="sf-view-plain-list">
                    <?php foreach ($plainContentSections as $plainContentSection): ?>
                        <section class="sf-view-plain-block">
                            <h3><?= htmlspecialchars($plainContentSection['label'], ENT_QUOTES, 'UTF-8') ?></h3>
<div class="sf-view-plain-text">
    <?= sf_view_plain_text_to_html((string)$plainContentSection['value']) ?>
</div>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="sf-view-plain-empty">
                    <?= htmlspecialchars(sf_term('view_plain_empty', $currentUiLang) ?: 'Ei tekstisisältöä', ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </div>
    </details>

    <script>
    (function () {
        'use strict';

        function copyPlainText(button) {
            var text = button.getAttribute('data-copy-text') || '';

            if (!text) {
                return;
            }

            function showSuccess() {
                if (typeof window.sfToast === 'function') {
                    window.sfToast('success', <?= json_encode(sf_term('view_plain_copy_success', $currentUiLang) ?: 'Tekstit kopioitu', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
                }
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(showSuccess).catch(function () {
                    fallbackCopy(text);
                    showSuccess();
                });
                return;
            }

            fallbackCopy(text);
            showSuccess();
        }

        function fallbackCopy(text) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }

        function initPlainCopyButtons() {
            document.querySelectorAll('.sf-view-plain-copy-btn').forEach(function (button) {
                if (button._sfPlainCopyAttached) {
                    return;
                }

                button.addEventListener('click', function () {
                    copyPlainText(button);
                });

                button._sfPlainCopyAttached = true;
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPlainCopyButtons);
        } else {
            initPlainCopyButtons();
        }
    })();
    </script>
	
    <details class="sf-view-meta-section sf-view-meta-details">
        <summary>
            <span class="sf-view-meta-head-icon">
                <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'settings.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
            </span>

            <span><?= htmlspecialchars(sf_term('meta_system_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>

            <img class="sf-view-meta-chevron" src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'chevron-down.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
        </summary>

        <div class="sf-view-meta-list sf-view-meta-system-list">
            <div class="sf-view-meta-row">
                <span class="sf-view-meta-row-icon">
                    <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'info.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                </span>
                <span class="sf-view-meta-row-label">ID</span>
                <span class="sf-view-meta-row-value"><?= htmlspecialchars($flashIdText, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="sf-view-meta-row">
                <span class="sf-view-meta-row-icon">
                    <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'globe.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                </span>
                <span class="sf-view-meta-row-label"><?= htmlspecialchars(sf_term('meta_language', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="sf-view-meta-row-value"><?= htmlspecialchars(ucfirst($languageText), ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="sf-view-meta-row">
                <span class="sf-view-meta-row-icon">
                    <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'create.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                </span>
                <span class="sf-view-meta-row-label"><?= htmlspecialchars(sf_term('meta_created_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="sf-view-meta-row-value"><?= htmlspecialchars($createdText, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="sf-view-meta-row">
                <span class="sf-view-meta-row-icon">
                    <img src="<?= htmlspecialchars(sf_view_meta_icon_url($assetBase, 'status-change.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="">
                </span>
                <span class="sf-view-meta-row-label"><?= htmlspecialchars(sf_term('meta_updated_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="sf-view-meta-row-value"><?= htmlspecialchars($updatedText, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </details>
</div>