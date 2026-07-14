<?php
/**
 * SafetyFlash - View Page: Display Targets Status
 *
 * Shows which info screens a flash is targeted to, with status indicators.
 * ✅ is_active = 1 — published/active on this display
 * ⏳ is_active = 0 — preselected by safety team, not yet published
 *
 * Required variables:
 * @var array $flash Flash data from database (must include 'id')
 * @var PDO $pdo Database connection
 * @var string $currentUiLang Current UI language
 */

$flashId = (int)($flash['id'] ?? 0);

if ($flashId <= 0) {
    return;
}

try {
    $stmtTargets = $pdo->prepare("
        SELECT t.display_key_id, t.is_active, k.label, k.site, k.site_group
        FROM sf_flash_display_targets t
        JOIN sf_display_api_keys k ON k.id = t.display_key_id
        WHERE t.flash_id = ?
        ORDER BY k.site_group ASC, k.sort_order ASC, k.label ASC
    ");
    $stmtTargets->execute([$flashId]);
    $targets = $stmtTargets->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    return;
}

if (empty($targets)) {
    return;
}

$activeCount = 0;
$totalCount = count($targets);
foreach ($targets as $t) {
    if ((int)$t['is_active'] === 1) {
        $activeCount++;
    }
}

// Group by site_group
$grouped = [];
foreach ($targets as $t) {
    $group = $t['site_group'] ?: '';
    $grouped[$group][] = $t;
}
?>

<div class="sf-card sf-display-targets-card">
    <h4>
        <img src="<?= $baseUrl ?? '' ?>/assets/img/icons/display.svg" alt="" class="sf-icon" aria-hidden="true" style="width:18px;height:18px;vertical-align:middle;">
        <?= htmlspecialchars(sf_term('display_targets_heading', $currentUiLang) ?? 'Infonäyttökohteet', ENT_QUOTES, 'UTF-8') ?>
    </h4>
    <p class="sf-display-targets-count">
        <?= htmlspecialchars(
            sprintf(
                sf_term('display_targets_count', $currentUiLang) ?? '%d / %d aktiivinen',
                $activeCount,
                $totalCount
            ),
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>
    <?php foreach ($grouped as $groupName => $items): ?>
        <?php if ($groupName !== ''): ?>
            <p class="sf-display-group-label"><strong><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></strong></p>
        <?php endif; ?>
        <ul class="sf-display-targets-list">
            <?php foreach ($items as $t): ?>
                <li class="sf-display-target-item">
                    <?php if ((int)$t['is_active'] === 1): ?>
                        <span class="sf-display-target-active" title="<?= htmlspecialchars(sf_term('display_target_active', $currentUiLang) ?? 'Aktiivinen', ENT_QUOTES, 'UTF-8') ?>">
                            <img src="<?= $baseUrl ?? '' ?>/assets/img/icons/display.svg" alt="✓" style="width:14px;height:14px;vertical-align:middle;color:green;">
                        </span>
                    <?php else: ?>
                        <span class="sf-display-target-preselected" title="<?= htmlspecialchars(sf_term('display_target_preselected', $currentUiLang) ?? 'Esiasetettu', ENT_QUOTES, 'UTF-8') ?>">
                            <img src="<?= $baseUrl ?? '' ?>/assets/img/icons/pending.svg" alt="…" style="width:14px;height:14px;vertical-align:middle;">
                        </span>
                    <?php endif; ?>
                    <?= htmlspecialchars($t['label'] ?? $t['site'], ENT_QUOTES, 'UTF-8') ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</div>