<?php
/**
 * SafetyFlash - Publish Modal: Display Duration Selector
 * 
 * Kuvakohtaisen näyttökeston valitsin julkaisumodaaliin.
 * Chip-tyyliset radio-valinnat eri kestoille.
 * 
 * @package SafetyFlash
 * @subpackage Partials
 * @created 2026-02-19
 */

// Käytä sessiosta saatavaa UI-kieltä tai fallback
$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

// Kesto-vaihtoehdot (sekunteina)
$durationOptions = [
    10 => 'duration_10s',
    15 => 'duration_15s',
    20 => 'duration_20s',
    30 => 'duration_30s',      // Oletus
    45 => 'duration_45s',
    60 => 'duration_60s',
];
?>

<div class="sf-publish-duration-section">
    <div class="sf-duration-header">
        <svg class="sf-duration-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12 6 12 12 16 14"/>
        </svg>
        <h4><?= htmlspecialchars(sf_term('display_duration_heading', $currentUiLang) ?? 'Näyttökesto infonäytöllä', ENT_QUOTES, 'UTF-8') ?></h4>
    </div>
    
    <p class="sf-duration-description">
        <?= htmlspecialchars(sf_term('display_duration_description', $currentUiLang) ?? 'Valitse kuinka monta sekuntia tämä kuva näytetään Xibo-näytöillä', ENT_QUOTES, 'UTF-8') ?>
    </p>
    
    <div class="sf-duration-chips">
        <?php foreach ($durationOptions as $seconds => $termKey): ?>
            <?php 
            $isDefault = ($seconds === 30);
            $chipId = "duration_chip_{$seconds}";
            ?>
            <label for="<?= $chipId ?>" class="sf-duration-chip <?= $isDefault ? 'sf-duration-chip-selected' : '' ?>">
                <input 
                    type="radio" 
                    name="display_duration_seconds" 
                    id="<?= $chipId ?>" 
                    value="<?= $seconds ?>"
                    <?= $isDefault ? 'checked' : '' ?>
                    class="sf-duration-radio"
                />
                <svg class="sf-chip-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
                <span class="sf-chip-label">
                    <?= htmlspecialchars(sf_term($termKey, $currentUiLang) ?? "{$seconds}s", ENT_QUOTES, 'UTF-8') ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
</div>
