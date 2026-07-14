<?php
/**
 * SafetyFlash - Display Target Selector Partial
 *
 * Näyttää kaikki aktiiviset näytöt maa/kieliryhmitetyillä chip-napeilla,
 * hakukentällä yksittäisten näyttöjen löytämiseen sekä valintanäytöllä.
 *
 * Odottaa muuttujia:
 *   $flash        — array, kieliversion data (id, lang, title)
 *   $pdo          — PDO-yhteys
 *   $currentUiLang — string, UI-kieli
 *   $context      — string, 'publish' | 'safety_team'
 *
 * Valinnainen override:
 *   $preselectedIds — array, jos asetettu ennen includeaa, käytetään sellaisenaan
 *
 * @package SafetyFlash
 * @subpackage Partials
 * @created 2026-02-19
 * @updated 2026-02-23 - country/lang group chips + search + selection display
 */

// Flashin oma kieliversiokohtainen ID (EI translation_group_id)
$flashId = (int)($flash['id'] ?? 0);

// Hae KAIKKI aktiiviset näytöt (join worksites for site_type + visibility/default flags)
$availableDisplays = [];
try {
    $stmtDisplays = $pdo->prepare("
        SELECT k.id, k.site, k.site_group, k.label, k.lang, k.sort_order, k.worksite_id,
               COALESCE(w.site_type, '') AS site_type,
               COALESCE(w.country_code, '') AS country_code,
               w.supported_publish_languages_json,
               COALESCE(w.show_in_worksite_lists, 1) AS show_in_worksite_lists,
               COALESCE(w.is_default_display, 0) AS is_default_display
        FROM sf_display_api_keys k
        LEFT JOIN sf_worksites w ON w.id = k.worksite_id
        WHERE k.is_active = 1
          AND (w.id IS NULL OR w.show_in_display_targets = 1)
        ORDER BY k.lang ASC, k.sort_order ASC, k.label ASC
    ");
    $stmtDisplays->execute();
    $availableDisplays = $stmtDisplays->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $eDtSel) {
    // Silently ignore — taulu saattaa puuttua ennen migraatiota
}

$publishTargetLang = strtolower((string)($flash['lang'] ?? 'fi'));

if (($context ?? '') === 'publish') {
    $availableDisplays = array_values(array_filter($availableDisplays, static function (array $display) use ($publishTargetLang): bool {
        $worksiteId = (int)($display['worksite_id'] ?? 0);
        $displayLang = strtolower((string)($display['lang'] ?? ''));

        if ($worksiteId <= 0) {
            return $displayLang === '' || $displayLang === $publishTargetLang;
        }

        $supportedLanguagesJson = (string)($display['supported_publish_languages_json'] ?? '');
        $supportedLanguages = [];

        if ($supportedLanguagesJson !== '') {
            $decodedLanguages = json_decode($supportedLanguagesJson, true);
            if (is_array($decodedLanguages)) {
                $supportedLanguages = array_values(array_map('strtolower', array_map('strval', $decodedLanguages)));
            }
        }

        if (empty($supportedLanguages)) {
            $supportedLanguages = ['fi'];
        }

        return in_array($publishTargetLang, $supportedLanguages, true);
    }));
}

// Hae esivalinnat — käytä annettua $preselectedIds jos asetettu, muuten hae kannasta.
// Julkaisuvaiheessa kieliversio voi olla luotu vasta viestintävaiheessa, jolloin sillä
// ei vielä ole omia sf_flash_display_targets-rivejä. Tällöin peritään valinnat
// translation_group_id:n tai ryhmän pääversion kautta.
if (!isset($preselectedIds)) {
    $preselectedIds = [];

    if ($flashId > 0) {
        try {
            $stmtPre = $pdo->prepare("
                SELECT display_key_id
                FROM sf_flash_display_targets
                WHERE flash_id = ?
                ORDER BY selected_at ASC, id ASC
            ");
            $stmtPre->execute([$flashId]);
            $preselectedIds = $stmtPre->fetchAll(PDO::FETCH_COLUMN);

            if (empty($preselectedIds) && ($context ?? '') !== 'publish') {
                $stmtGroup = $pdo->prepare("
                    SELECT id, translation_group_id
                    FROM sf_flashes
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmtGroup->execute([$flashId]);
                $flashGroupRow = $stmtGroup->fetch(PDO::FETCH_ASSOC);

                if ($flashGroupRow) {
                    $fallbackIds = [];

                    $translationGroupId = !empty($flashGroupRow['translation_group_id'])
                        ? (int)$flashGroupRow['translation_group_id']
                        : (int)$flashGroupRow['id'];

                    if ($translationGroupId > 0 && $translationGroupId !== $flashId) {
                        $fallbackIds[] = $translationGroupId;
                    }

                    $stmtSiblings = $pdo->prepare("
                        SELECT id
                        FROM sf_flashes
                        WHERE translation_group_id = ?
                           OR id = ?
                        ORDER BY
                            CASE WHEN id = ? THEN 0 ELSE 1 END,
                            FIELD(lang, 'fi', 'sv', 'en', 'it', 'el'),
                            id ASC
                    ");
                    $stmtSiblings->execute([$translationGroupId, $translationGroupId, $translationGroupId]);
                    $siblingIds = array_map('intval', $stmtSiblings->fetchAll(PDO::FETCH_COLUMN));

                    foreach ($siblingIds as $siblingId) {
                        if ($siblingId > 0 && $siblingId !== $flashId) {
                            $fallbackIds[] = $siblingId;
                        }
                    }

                    $fallbackIds = array_values(array_unique($fallbackIds));

                    if (!empty($fallbackIds)) {
                        $placeholders = implode(',', array_fill(0, count($fallbackIds), '?'));

                        $stmtFallbackPre = $pdo->prepare("
                            SELECT DISTINCT display_key_id
                            FROM sf_flash_display_targets
                            WHERE flash_id IN ($placeholders)
                            ORDER BY display_key_id ASC
                        ");
                        $stmtFallbackPre->execute($fallbackIds);
                        $preselectedIds = $stmtFallbackPre->fetchAll(PDO::FETCH_COLUMN);
                    }
                }
            }
        } catch (Throwable $eDtSelPre) {
            $preselectedIds = [];
        }
    }
}

$preselectedIds = array_values(array_unique(array_map('intval', $preselectedIds)));

if (($context ?? '') === 'publish') {
    $availableDisplayIds = array_values(array_unique(array_map('intval', array_column($availableDisplays, 'id'))));
    $preselectedIds = array_values(array_intersect($preselectedIds, $availableDisplayIds));

    if (empty($preselectedIds) && !empty($availableDisplayIds)) {
        $preselectedIds = $availableDisplayIds;
    }
}

// Maa/kielikartta
$dtLangMap = [
    'fi' => ['flag' => '🇫🇮', 'name' => sf_term('country_finland', $currentUiLang)],
    'sv' => ['flag' => '🇸🇪', 'name' => sf_term('country_sweden', $currentUiLang)],
    'en' => ['flag' => '🇬🇧', 'name' => sf_term('country_uk', $currentUiLang)],
    'it' => ['flag' => '🇮🇹', 'name' => sf_term('country_italy', $currentUiLang)],
    'el' => ['flag' => '🇬🇷', 'name' => sf_term('country_greece', $currentUiLang)],
];

// Ryhmittele näytöt kielen mukaan
$dtByLang = [];
foreach ($availableDisplays as $dtDisp) {
    $dtLang = $dtDisp['lang'] ?: 'fi';
    $dtByLang[$dtLang][] = $dtDisp;
}

$priorityDisplays = [];
$searchOnlyDisplays = [];
foreach ($availableDisplays as $dtDisp) {
    $isDisplayOnly = (int)($dtDisp['show_in_worksite_lists'] ?? 1) !== 1;
    $isUncategorized = trim((string)($dtDisp['site_type'] ?? '')) === '';
    $isDefaultDisplay = (int)($dtDisp['is_default_display'] ?? 0) === 1;
    if ($isDisplayOnly || $isUncategorized || $isDefaultDisplay) {
        $priorityDisplays[] = $dtDisp;
    } else {
        $searchOnlyDisplays[] = $dtDisp;
    }
}
?>

<div class="sf-display-target-selector">
    <?php if (empty($availableDisplays)): ?>
        <p class="sf-help-text sf-help-text-muted">
            <?= htmlspecialchars(sf_term('display_targets_no_allowed_for_language', $currentUiLang) ?? 'Tälle kieliversiolle ei löytynyt sallittuja infonäyttöjä.', ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php else: ?>

        <?php if (($context ?? '') === 'publish'): ?>
            <div class="sf-dt-auto-filter-note">
                <?= htmlspecialchars(sf_term('display_targets_auto_filter_note', $currentUiLang) ?? 'Infonäytöt on rajattu automaattisesti kieliversion ja työmaan maa-asetusten perusteella.', ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($dtByLang)): ?>
        <div class="sf-dt-lang-chips">
            <?php foreach ($dtByLang as $dtLang => $dtLangDisplays): ?>
                <?php $dtLInfo = $dtLangMap[$dtLang] ?? ['flag' => '🌐', 'name' => strtoupper($dtLang)]; ?>
                <?php
                // Kaikki kyseisen kielen näytöt valittuna?
                $dtLangIds = array_map('intval', array_column($dtLangDisplays, 'id'));
                $dtAllSelected = !empty($dtLangIds) && empty(array_diff($dtLangIds, $preselectedIds));
                ?>
                <button type="button"
                        class="sf-dt-lang-chip<?= $dtAllSelected ? ' sf-dt-lang-chip-active' : '' ?>"
                        data-lang="<?= htmlspecialchars($dtLang, ENT_QUOTES, 'UTF-8') ?>">
                    <?= $dtLInfo['flag'] ?> <?= htmlspecialchars($dtLInfo['name'], ENT_QUOTES, 'UTF-8') ?>
                    <span class="sf-dt-lang-count">(<?= count($dtLangDisplays) ?>)</span>
                </button>
            <?php endforeach; ?>
            <?php
            // Special quick-select chips
            $dtTunnelIds   = array_map('intval', array_column(array_filter($availableDisplays, fn($d) => ($d['site_type'] ?? '') === 'tunnel'),   'id'));
            $dtOpencastIds = array_map('intval', array_column(array_filter($availableDisplays, fn($d) => ($d['site_type'] ?? '') === 'opencast'), 'id'));
            $dtOtherIds    = array_map('intval', array_column(array_filter($availableDisplays, fn($d) => ($d['site_type'] ?? '') === 'other'),    'id'));
            $dtDisplayOnlyIds = array_map('intval', array_column(array_filter($availableDisplays, fn($d) => (int)($d['show_in_worksite_lists'] ?? 1) !== 1), 'id'));
            $dtUncategorizedIds = array_map('intval', array_column(array_filter($availableDisplays, fn($d) => trim((string)($d['site_type'] ?? '')) === ''), 'id'));
            $dtDefaultIds = array_map('intval', array_column(array_filter($availableDisplays, fn($d) => (int)($d['is_default_display'] ?? 0) === 1), 'id'));
            $dtAllIds      = array_map('intval', array_column($availableDisplays, 'id'));
            $dtAllChipActive      = !empty($dtAllIds)      && empty(array_diff($dtAllIds,      $preselectedIds));
            $dtTunnelChipActive   = !empty($dtTunnelIds)   && empty(array_diff($dtTunnelIds,   $preselectedIds));
            $dtOpencastChipActive = !empty($dtOpencastIds) && empty(array_diff($dtOpencastIds, $preselectedIds));
            $dtOtherChipActive    = !empty($dtOtherIds)    && empty(array_diff($dtOtherIds,    $preselectedIds));
            $dtDisplayOnlyChipActive = !empty($dtDisplayOnlyIds) && empty(array_diff($dtDisplayOnlyIds, $preselectedIds));
            $dtUncategorizedChipActive = !empty($dtUncategorizedIds) && empty(array_diff($dtUncategorizedIds, $preselectedIds));
            $dtDefaultChipActive = !empty($dtDefaultIds) && empty(array_diff($dtDefaultIds, $preselectedIds));
            ?>
            <button type="button"
                    class="sf-dt-special-chip<?= $dtAllChipActive ? ' sf-dt-lang-chip-active' : '' ?>"
                    data-select="all">
                <?= htmlspecialchars(sf_term('comms_screens_all', $currentUiLang) ?? 'Kaikki näytöt', ENT_QUOTES, 'UTF-8') ?>
                <span class="sf-dt-lang-count">(<?= count($dtAllIds) ?>)</span>
            </button>
            <?php if (!empty($dtTunnelIds)): ?>
            <button type="button"
                    class="sf-dt-special-chip<?= $dtTunnelChipActive ? ' sf-dt-lang-chip-active' : '' ?>"
                    data-select="tunnel">
                <?= htmlspecialchars(sf_term('site_type_tunnel', $currentUiLang) ?? 'Tunnelityömaat', ENT_QUOTES, 'UTF-8') ?>
                <span class="sf-dt-lang-count">(<?= count($dtTunnelIds) ?>)</span>
            </button>
            <?php endif; ?>
            <?php if (!empty($dtOpencastIds)): ?>
            <button type="button"
                    class="sf-dt-special-chip<?= $dtOpencastChipActive ? ' sf-dt-lang-chip-active' : '' ?>"
                    data-select="opencast">
                <?= htmlspecialchars(sf_term('site_type_opencast', $currentUiLang) ?? 'Avolouhokset', ENT_QUOTES, 'UTF-8') ?>
                <span class="sf-dt-lang-count">(<?= count($dtOpencastIds) ?>)</span>
            </button>
            <?php endif; ?>
            <?php if (!empty($dtOtherIds)): ?>
            <button type="button"
                    class="sf-dt-special-chip<?= $dtOtherChipActive ? ' sf-dt-lang-chip-active' : '' ?>"
                    data-select="other">
                <?= htmlspecialchars(sf_term('site_type_other', $currentUiLang) ?? 'Muut toimipisteet', ENT_QUOTES, 'UTF-8') ?>
                <span class="sf-dt-lang-count">(<?= count($dtOtherIds) ?>)</span>
            </button>
            <?php endif; ?>
            <?php if (!empty($dtDisplayOnlyIds)): ?>
            <button type="button"
                    class="sf-dt-special-chip<?= $dtDisplayOnlyChipActive ? ' sf-dt-lang-chip-active' : '' ?>"
                    data-select="display-only">
                <?= htmlspecialchars(sf_term('display_targets_display_only_chip', $currentUiLang) ?? 'Vain infonäytöissä', ENT_QUOTES, 'UTF-8') ?>
                <span class="sf-dt-lang-count">(<?= count($dtDisplayOnlyIds) ?>)</span>
            </button>
            <?php endif; ?>
            <?php if (!empty($dtUncategorizedIds)): ?>
            <button type="button"
                    class="sf-dt-special-chip<?= $dtUncategorizedChipActive ? ' sf-dt-lang-chip-active' : '' ?>"
                    data-select="uncategorized">
                <?= htmlspecialchars(sf_term('display_targets_uncategorized_chip', $currentUiLang) ?? 'Kategorisoimattomat', ENT_QUOTES, 'UTF-8') ?>
                <span class="sf-dt-lang-count">(<?= count($dtUncategorizedIds) ?>)</span>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($priorityDisplays)): ?>
        <div class="sf-dt-priority-chip-row">
            <?php foreach ($priorityDisplays as $dtDisplay): ?>
                <?php
                $dtIsChecked = in_array((int)$dtDisplay['id'], $preselectedIds, true);
                $dtIsDisplayOnly = (int)($dtDisplay['show_in_worksite_lists'] ?? 1) !== 1;
                $dtIsUncategorized = trim((string)($dtDisplay['site_type'] ?? '')) === '';
                $dtIsDefaultDisplay = (int)($dtDisplay['is_default_display'] ?? 0) === 1;
                ?>
                <label class="sf-dt-special-chip sf-dt-priority-chip"
                       data-search="<?= htmlspecialchars(strtolower($dtDisplay['label'] ?? $dtDisplay['site']), ENT_QUOTES, 'UTF-8') ?>"
                       data-lang="<?= htmlspecialchars($dtDisplay['lang'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       data-type="<?= htmlspecialchars($dtDisplay['site_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <input type="checkbox"
                           class="sf-display-chip-input dt-display-chip-cb"
                           name="display_targets[<?= $flashId ?>][]"
                           value="<?= (int)$dtDisplay['id'] ?>"
                           data-label="<?= htmlspecialchars($dtDisplay['label'] ?? $dtDisplay['site'], ENT_QUOTES, 'UTF-8') ?>"
                           data-lang="<?= htmlspecialchars($dtDisplay['lang'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           data-type="<?= htmlspecialchars($dtDisplay['site_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           data-display-only="<?= $dtIsDisplayOnly ? '1' : '0' ?>"
                           data-uncategorized="<?= $dtIsUncategorized ? '1' : '0' ?>"
                           data-default-display="<?= $dtIsDefaultDisplay ? '1' : '0' ?>"
                           <?= $dtIsChecked ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($dtDisplay['label'] ?? $dtDisplay['site'], ENT_QUOTES, 'UTF-8') ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Hakukenttä -->
        <div class="sf-dt-search-row">
            <input type="text"
                   class="sf-dt-search-input"
                   placeholder="🔍 <?= htmlspecialchars(sf_term('comms_search_worksites', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                   autocomplete="off">
            <p class="sf-dt-search-hint"><?= htmlspecialchars(sf_term('comms_search_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <!-- Hakutulokset (piilotettu oletuksena) -->
        <div class="sf-dt-search-results hidden">
            <?php foreach ($searchOnlyDisplays as $dtDisplay): ?>
                <?php $dtIsChecked = in_array((int)$dtDisplay['id'], $preselectedIds, true); ?>
                <?php
                $dtIsDisplayOnly = (int)($dtDisplay['show_in_worksite_lists'] ?? 1) !== 1;
                $dtIsUncategorized = trim((string)($dtDisplay['site_type'] ?? '')) === '';
                $dtIsDefaultDisplay = (int)($dtDisplay['is_default_display'] ?? 0) === 1;
                ?>
                <label class="sf-dt-result-item hidden"
                       data-search="<?= htmlspecialchars(strtolower($dtDisplay['label'] ?? $dtDisplay['site']), ENT_QUOTES, 'UTF-8') ?>"
                       data-lang="<?= htmlspecialchars($dtDisplay['lang'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       data-type="<?= htmlspecialchars($dtDisplay['site_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <input type="checkbox"
                           class="sf-display-chip-input dt-display-chip-cb"
                           name="display_targets[<?= $flashId ?>][]"
                           value="<?= (int)$dtDisplay['id'] ?>"
                           data-label="<?= htmlspecialchars($dtDisplay['label'] ?? $dtDisplay['site'], ENT_QUOTES, 'UTF-8') ?>"
                           data-lang="<?= htmlspecialchars($dtDisplay['lang'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           data-type="<?= htmlspecialchars($dtDisplay['site_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           data-display-only="<?= $dtIsDisplayOnly ? '1' : '0' ?>"
                           data-uncategorized="<?= $dtIsUncategorized ? '1' : '0' ?>"
                           data-default-display="<?= $dtIsDefaultDisplay ? '1' : '0' ?>"
                           <?= $dtIsChecked ? 'checked' : '' ?>>
                    <span class="sf-ws-name"><?= htmlspecialchars($dtDisplay['label'] ?? $dtDisplay['site'], ENT_QUOTES, 'UTF-8') ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <!-- Valintanäyttö -->
        <div class="sf-dt-selection-display<?= empty($preselectedIds) ? ' hidden' : '' ?>">
            <div class="sf-dt-selection-label"><?= htmlspecialchars(sf_term('comms_your_selection', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="sf-dt-selection-tags"></div>
            <button type="button" class="sf-dt-clear-all-btn">
                <?= htmlspecialchars(sf_term('clear_all_selections', $currentUiLang) ?? 'Tyhjennä kaikki', ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>

    <?php endif; ?>
</div>