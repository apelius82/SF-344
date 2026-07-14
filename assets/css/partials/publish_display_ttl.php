<?php
/**
 * SafetyFlash - Publish Modal: Display TTL Selector
 * 
 * Infonäyttöjen näkyvyysajan valitsin julkaisumodaaliin.
 * Chip-tyyliset radio-valinnat eri TTL-ajoille.
 * 
 * @package SafetyFlash
 * @subpackage Partials
 * @created 2026-02-19
 */

// Käytä sessiosta saatavaa UI-kieltä tai fallback
$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

// TTL-vaihtoehdot (päivissä)
$ttlOptions = [
    0 => 'ttl_no_limit',
    7 => 'ttl_1_week',
    14 => 'ttl_2_weeks',      // Oletus
    30 => 'ttl_1_month',
    60 => 'ttl_2_months',
    90 => 'ttl_3_months',
];

$defaultTtlDays = 14;
?>

<div class="sf-publish-ttl-section">
    <div class="sf-ttl-header">
        <svg class="sf-ttl-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="17" x2="12" y2="21"/>
        </svg>
        <h4><?= htmlspecialchars(sf_term('display_ttl_heading', $currentUiLang) ?? 'Näkyvyysaika infonäytöillä', ENT_QUOTES, 'UTF-8') ?></h4>
    </div>
    
    <p class="sf-ttl-description">
        <?= htmlspecialchars(sf_term('display_ttl_description', $currentUiLang) ?? 'Valitse kuinka kauan flash näytetään Xibo-infonäytöillä', ENT_QUOTES, 'UTF-8') ?>
    </p>
    
    <div class="sf-ttl-chips">
        <?php foreach ($ttlOptions as $days => $termKey): ?>
            <?php
            $isDefault = ($days === $defaultTtlDays);
            $chipId = "ttl_chip_{$days}";
            ?>
            <label for="<?= $chipId ?>" class="sf-ttl-chip <?= $isDefault ? 'sf-ttl-chip-selected' : '' ?>">
                <input 
                    type="radio" 
                    name="display_ttl_days" 
                    id="<?= $chipId ?>" 
                    value="<?= $days ?>"
                    <?= $isDefault ? 'checked' : '' ?>
                    class="sf-ttl-radio"
                />
                <?php if ($days > 0): ?>
                    <svg class="sf-chip-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                <?php else: ?>
                    <svg class="sf-chip-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                <?php endif; ?>
                <span class="sf-chip-label">
                    <?= htmlspecialchars(sf_term($termKey, $currentUiLang) ?? $termKey, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
    
    <div class="sf-ttl-preview" id="ttlPreview">
        <svg class="sf-preview-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
            <line x1="16" y1="2" x2="16" y2="6"/>
            <line x1="8" y1="2" x2="8" y2="6"/>
            <line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        <span id="ttlPreviewText">
            <?= htmlspecialchars(sf_term('ttl_preview_default', $currentUiLang) ?? 'Vanhenee', ENT_QUOTES, 'UTF-8') ?>: 
            <strong id="ttlPreviewDate" class="sf-ttl-preview-date"></strong>
        </span>
    </div>
</div>