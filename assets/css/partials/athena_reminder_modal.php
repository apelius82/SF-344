<?php
/**
 * SafetyFlash - Athena Reminder Modal
 *
 * Pop-up muistutus raportin viemisestä Athenaan julkaisun jälkeen.
 * Näytetään vain tutkintatiedotteille (type='green').
 *
 * Required variables:
 * @var array  $flash           Flash data
 * @var string $currentUiLang  Current UI language
 * @var string $base            Base URL
 * @var int    $id              Flash ID
 * @var int    $logFlashId      Log Flash ID (translation group root or flash id)
 */
?>
<div class="sf-modal hidden"
     id="sfAthenaReminderModal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="sfAthenaReminderModalTitle">
    <div class="sf-modal-backdrop" onclick="sfAthenaCloseModal()"></div>
    <div class="sf-modal-content" style="max-width:480px;">
        <div class="sf-modal-header">
            <h3 id="sfAthenaReminderModalTitle" style="display:flex;align-items:center;gap:8px;">
                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/report.svg"
                     alt="" aria-hidden="true"
                     style="width:20px;height:20px;filter:invert(27%) sepia(80%) saturate(600%) hue-rotate(210deg);">
                <?= htmlspecialchars(sf_term('modal_athena_reminder_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" class="sf-modal-close" onclick="sfAthenaCloseModal()" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>
        <div class="sf-modal-body">
            <p style="margin:0 0 12px;color:#374151;font-size:0.93rem;line-height:1.5;">
                <?= htmlspecialchars(sf_term('modal_athena_reminder_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <p style="margin:0;color:#6b7280;font-size:0.87rem;line-height:1.4;">
                <?= htmlspecialchars(sf_term('modal_athena_reminder_already_exported_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        <div class="sf-modal-footer" style="display:flex;flex-direction:column;gap:8px;padding:16px 20px 20px;">
            <!-- Lataa PDF ja merkitse vietyksi -->
            <button type="button"
                    id="sfAthenaBtnDownload"
                    class="sf-btn sf-btn-primary"
                    style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;"
                    onclick="sfAthenaDownloadAndMark()">
                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/download.svg"
                     alt="" aria-hidden="true"
                     style="width:16px;height:16px;filter:invert(1);">
                <?= htmlspecialchars(sf_term('btn_download_and_mark_athena', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <!-- Olen jo vienyt -->
            <button type="button"
                    id="sfAthenaBtnAlreadyDone"
                    class="sf-btn sf-btn-secondary"
                    style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;"
                    onclick="sfAthenaMarkDone()">
                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/check.svg"
                     alt="" aria-hidden="true"
                     style="width:16px;height:16px;">
                <?= htmlspecialchars(sf_term('btn_already_exported_athena', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <!-- Muistuta myöhemmin -->
            <button type="button"
                    class="sf-athena-remind-later-btn"
                    onclick="sfAthenaRemindLater()">
                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/clock.svg"
                     alt="" aria-hidden="true"
                     style="width:14px;height:14px;opacity:0.6;">
                <?= htmlspecialchars(sf_term('btn_remind_later', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>