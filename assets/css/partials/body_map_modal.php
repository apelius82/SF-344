<?php
/**
 * assets/partials/body_map_modal.php
 * Kehokarttamodaali — loukkaantuneiden ruumiinosien valinta (Ensitiedote)
 *
 * Variables available from form.php: $base, $uiLang
 */

/**
 * Reads a whole-body SVG file and returns the cleaned inner content (without
 * the outer <svg> wrapper) ready for embedding as inline SVG.
 *
 * @param string $svgFile  Absolute path to the .svg file
 */
function loadBodySvg(string $svgFile): string
{
    // Validate that the resolved path stays within the body-map asset directory
    $expectedDir = realpath(__DIR__ . '/../../assets/img/body-map');
    $realFile    = realpath($svgFile);
    if (
        $realFile === false || $expectedDir === false
        || strncmp($realFile, $expectedDir . DIRECTORY_SEPARATOR, strlen($expectedDir) + 1) !== 0
    ) {
        return '';
    }

    $raw = file_get_contents($realFile);
    if ($raw === false || $raw === '') {
        return '';
    }

    // Remove the XML declaration and any following whitespace
    $raw = preg_replace('/<\?xml[^?]*\?>\s*/', '', $raw);

    // Extract everything between the root <svg> tags
    if (!preg_match('/<svg[^>]*>(.*?)<\/svg>/s', $raw, $cm)) {
        return '';
    }
    $inner = trim($cm[1]);

    // Strip potentially dangerous SVG elements and attributes
    $inner = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $inner);
    $inner = preg_replace('/\s+on\w+="[^"]*"/i', '', $inner);
    $inner = preg_replace('/\s+on\w+=\'[^\']*\'/i', '', $inner);
    $inner = preg_replace('/href\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', '', $inner);

    // Remove hardcoded fill/stroke so CSS (.sf-bp) can control appearance
    $inner = preg_replace('/\s+fill="[^"]*"/', '', $inner);
    $inner = preg_replace('/\s+stroke="[^"]*"/', '', $inner);
    $inner = preg_replace('/\s+stroke-width="[^"]*"/', '', $inner);

    // Add class="sf-bp" to every <path> element so CSS and JS can target them
    $inner = preg_replace('/<path /', '<path class="sf-bp" ', $inner);
    // Add class="sf-bp" to <g> elements that are named body parts (id="bp-..."),
    // but only when the element does not already carry a class attribute
    $inner = preg_replace('/<g\b(?=[^>]*\bid="bp-[^"]*")(?![^>]*\bclass=)/', '<g class="sf-bp" ', $inner);

    return $inner;
}

$bpDir    = __DIR__ . '/../../assets/img/body-map/';
$frontSvg = loadBodySvg($bpDir . 'front.svg');
$backSvg  = loadBodySvg($bpDir . 'back.svg');
?>
<div class="sf-modal hidden" id="sfBodyMapModal" role="dialog" aria-modal="true" aria-labelledby="sfBodyMapModalTitle">
    <div class="sf-modal-content sf-body-map-modal-content">

        <div class="sf-modal-header">
            <h2 id="sfBodyMapModalTitle">
                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/injury_icon.svg"
                     width="22" height="22" alt="" aria-hidden="true" class="sf-modal-icon sf-modal-icon-img">
                <?= htmlspecialchars(sf_term('body_map_modal_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <button type="button" class="sf-modal-close" data-modal-close
                    aria-label="<?= htmlspecialchars(sf_term('body_map_close_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <p class="sf-help-text sf-body-map-instruction"><?= htmlspecialchars(sf_term('body_map_instruction', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>

        <div class="sf-modal-body sf-body-map-modal-body">

            <!-- Dropdown valinta -->
            <div class="sf-body-map-select-row">
                <label for="sfBodyPartSelect" class="sf-label">
                    <?= htmlspecialchars(sf_term('body_map_select_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select id="sfBodyPartSelect" multiple class="sf-body-part-select" size="8">
                    <optgroup label="<?= htmlspecialchars(sf_term('bp_cat_head_neck', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <option value="bp-head"><?= htmlspecialchars(sf_term('bp_head', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-eyes"><?= htmlspecialchars(sf_term('bp_eyes', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-ear"><?= htmlspecialchars(sf_term('bp_ear',   $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-neck"><?= htmlspecialchars(sf_term('bp_neck', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    </optgroup>
                    <optgroup label="<?= htmlspecialchars(sf_term('bp_cat_torso', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <option value="bp-chest"><?= htmlspecialchars(sf_term('bp_chest',      $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-abdomen"><?= htmlspecialchars(sf_term('bp_abdomen',  $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-pelvis"><?= htmlspecialchars(sf_term('bp_pelvis',    $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-upper-back"><?= htmlspecialchars(sf_term('bp_upper_back', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-lower-back"><?= htmlspecialchars(sf_term('bp_lower_back', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    </optgroup>
                    <optgroup label="<?= htmlspecialchars(sf_term('bp_cat_upper_limbs', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <option value="bp-shoulder-left"><?= htmlspecialchars(sf_term('bp_shoulder_left',  $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-shoulder-right"><?= htmlspecialchars(sf_term('bp_shoulder_right', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-arm-left"><?= htmlspecialchars(sf_term('bp_arm_left',   $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-arm-right"><?= htmlspecialchars(sf_term('bp_arm_right', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-hand-left"><?= htmlspecialchars(sf_term('bp_hand_left', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-hand-right"><?= htmlspecialchars(sf_term('bp_hand_right', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    </optgroup>
                    <optgroup label="<?= htmlspecialchars(sf_term('bp_cat_lower_limbs', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <option value="bp-thigh-left"><?= htmlspecialchars(sf_term('bp_thigh_left',  $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-thigh-right"><?= htmlspecialchars(sf_term('bp_thigh_right', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-knee-left"><?= htmlspecialchars(sf_term('bp_knee_left',   $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-knee-right"><?= htmlspecialchars(sf_term('bp_knee_right', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-calf-left"><?= htmlspecialchars(sf_term('bp_calf_left',   $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-calf-right"><?= htmlspecialchars(sf_term('bp_calf_right', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-ankle-left"><?= htmlspecialchars(sf_term('bp_ankle_left', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-ankle-right"><?= htmlspecialchars(sf_term('bp_ankle_right', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-foot-left"><?= htmlspecialchars(sf_term('bp_foot_left',   $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="bp-foot-right"><?= htmlspecialchars(sf_term('bp_foot_right', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    </optgroup>
                </select>
                <p class="sf-help-text">
                    <?= htmlspecialchars(sf_term('body_map_select_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <!-- SVG kehokuva -->
            <div class="sf-body-map-svg-row">
                <p class="sf-body-map-svg-hint">
                    <?= htmlspecialchars(sf_term('body_map_svg_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <div class="sf-body-map-figures">

                    <!-- Etupuoli -->
                    <figure class="sf-body-figure">
                        <figcaption><?= htmlspecialchars(sf_term('body_map_front_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></figcaption>
                        <svg class="sf-body-svg" viewBox="0 0 261.58 620.34" xmlns="http://www.w3.org/2000/svg"
                             aria-label="<?= htmlspecialchars(sf_term('body_map_front_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>" role="img">
                            <?= $frontSvg ?>
                        </svg>
                    </figure>

                    <!-- Takapuoli -->
                    <figure class="sf-body-figure">
                        <figcaption><?= htmlspecialchars(sf_term('body_map_back_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></figcaption>
                        <svg class="sf-body-svg sf-body-svg-back" viewBox="0 0 261.58 597.52"
                             xmlns="http://www.w3.org/2000/svg"
                             aria-label="<?= htmlspecialchars(sf_term('body_map_back_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>" role="img">
                            <?= $backSvg ?>
                        </svg>
                    </figure>

                </div><!-- /.sf-body-map-figures -->
            </div><!-- /.sf-body-map-svg-row -->

        </div><!-- /.sf-modal-body -->

        <div class="sf-modal-footer">
            <div class="sf-body-map-selection-summary" id="sfBodyMapSelectionSummary">
                <span id="sfBodyMapSelectionCount" class="sf-body-map-count"></span>
            </div>
            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                    <?= htmlspecialchars(sf_term('body_map_cancel_btn', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="sfBodyMapSaveBtn">
                    <?= htmlspecialchars(sf_term('body_map_save_btn', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </div>

    </div><!-- /.sf-modal-content -->
</div><!-- /#sfBodyMapModal -->

<script>
window.BODY_MAP_I18N = {
    countSingle: <?= json_encode(sf_term('body_map_count_single', $uiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    countPlural: <?= json_encode(sf_term('body_map_count_plural', $uiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    removeLabel: <?= json_encode(sf_term('body_map_remove_label', $uiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
};
</script>