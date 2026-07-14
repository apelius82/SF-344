<?php
// app/includes/system_notice.php
declare(strict_types=1);

if (!function_exists('sf_get_setting')) {
    require_once __DIR__ . '/settings.php';
}

$systemNoticeEnabled = (bool)sf_get_setting('system_notice_enabled', false);

if ($systemNoticeEnabled) {
    $systemNoticeType = (string)sf_get_setting('system_notice_type', 'info');
    $systemNoticeTitle = trim((string)sf_get_setting('system_notice_title', ''));
    $systemNoticeMessage = trim((string)sf_get_setting('system_notice_message', ''));

    $allowedSystemNoticeTypes = ['info', 'warning', 'danger', 'maintenance'];
    if (!in_array($systemNoticeType, $allowedSystemNoticeTypes, true)) {
        $systemNoticeType = 'info';
    }

    if ($systemNoticeTitle !== '' || $systemNoticeMessage !== '') {
        $systemNoticeKey = hash('sha256', $systemNoticeType . '|' . $systemNoticeTitle . '|' . $systemNoticeMessage);
        ?>
        <div
            class="sf-system-notice-shell"
            data-system-notice-key="<?= htmlspecialchars($systemNoticeKey, ENT_QUOTES, 'UTF-8') ?>"
        >
            <div class="sf-system-notice-banner sf-system-notice-banner-<?= htmlspecialchars($systemNoticeType, ENT_QUOTES, 'UTF-8') ?>">
                <div class="sf-system-notice-icon" aria-hidden="true">
                    <?php if ($systemNoticeType === 'maintenance'): ?>
                        ⚙
                    <?php elseif ($systemNoticeType === 'danger'): ?>
                        !
                    <?php elseif ($systemNoticeType === 'warning'): ?>
                        !
                    <?php else: ?>
                        i
                    <?php endif; ?>
                </div>

                <div class="sf-system-notice-content">
                    <?php if ($systemNoticeTitle !== ''): ?>
                        <strong><?= htmlspecialchars($systemNoticeTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php endif; ?>

                    <?php if ($systemNoticeMessage !== ''): ?>
                        <p><?= nl2br(htmlspecialchars($systemNoticeMessage, ENT_QUOTES, 'UTF-8')) ?></p>
                    <?php endif; ?>
                </div>

                <button
                    type="button"
                    class="sf-system-notice-close"
                    aria-label="<?= htmlspecialchars(sf_term('system_notice_close', $uiLang ?? 'fi'), ENT_QUOTES, 'UTF-8') ?>"
                >
                    ×
                </button>
            </div>
        </div>
        <?php
    }
}