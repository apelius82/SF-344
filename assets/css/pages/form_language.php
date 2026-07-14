<?php
// assets/pages/form_language.php
declare(strict_types=1);

require_once __DIR__ .'/../../app/includes/protect.php';


// --- DB: PDO (sama kuin view.php:ssa) ---
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    echo '<p>Tietokantavirhe (form_language)</p>';
    exit;
}

$fromId = isset($_GET['from_id']) ? (int) $_GET['from_id'] : 0;
$newLang = isset($_GET['lang']) ? trim($_GET['lang']) : '';

if ($fromId <= 0 || $newLang === '') {
    echo '<div class="sf-error">Virhe: kieliversion luomiseen tarvitaan from_id ja lang.</div>';
    return;
}

// Hae pohjaflash kannasta
$stmt = $pdo->prepare('SELECT * FROM sf_flashes WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $fromId]);
$baseFlash = $stmt->fetch();

if (!$baseFlash) {
    echo '<div class="sf-error">Virhe: pohjatiedotetta ei löytynyt.</div>';
    return;
}

// Määritä translation_group_id
if (!empty($baseFlash['translation_group_id'])) {
    $translationGroupId = (int) $baseFlash['translation_group_id'];
} else {
    $translationGroupId = (int) $baseFlash['id'];
}

// Hae jo olemassa olevat kieliversiot ryhmässä
$usedLangsInGroup = [$baseFlash['lang'] ?? 'fi', $newLang];
try {
    $groupLangStmt = $pdo->prepare(
        'SELECT lang FROM sf_flashes WHERE (id = :gid OR translation_group_id = :gid2) AND lang IS NOT NULL'
    );
    $groupLangStmt->execute([':gid' => $translationGroupId, ':gid2' => $translationGroupId]);
    foreach ($groupLangStmt->fetchAll(PDO::FETCH_COLUMN) as $gl) {
        if ($gl !== '' && !in_array($gl, $usedLangsInGroup, true)) {
            $usedLangsInGroup[] = $gl;
        }
    }
} catch (Throwable $e) {
    // Non-critical: if we can't fetch existing langs, just use defaults
}

$supportedLangs = [
    'fi' => 'Suomi',
    'sv' => 'Ruotsi',
    'en' => 'Englanti',
    'it' => 'Italia',
    'el' => 'Kreikka',
];

$langLabel = $supportedLangs[$newLang] ?? strtoupper($newLang);
?>

<!-- Fixed top bar with close button (full-screen form mode) -->
<div class="sf-form-topbar">
    <span class="sf-form-topbar__title">
        <?php
        $closeTitle = isset($uiLang) ? (sf_term('form_language_label', $uiLang) ?: 'Kieliversio') : 'Kieliversio';
        echo htmlspecialchars($closeTitle, ENT_QUOTES, 'UTF-8');
        ?>: <strong><?php echo htmlspecialchars($langLabel); ?></strong>
    </span>
    <button type="button" id="sfFormLangCloseBtn" class="sf-form-progress__close"
            aria-label="<?php echo htmlspecialchars(isset($uiLang) ? (sf_term('btn_close_form', $uiLang) ?: 'Sulje lomake') : 'Sulje lomake', ENT_QUOTES, 'UTF-8'); ?>">
        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="4.5" y1="4.5" x2="13.5" y2="13.5"/>
            <line x1="13.5" y1="4.5" x2="4.5" y2="13.5"/>
        </svg>
    </button>
</div>

<!-- === VAIHE 2: PREVIEW (modaalissa) === -->
<div class="sf-translation-preview-wrapper">
    <div id="sfTranslationPreviewContainer">
        <?php
        // Valmistele kuvainfo preview-modaaliin
        $flash = $baseFlash; // Käytä pohjaflashia!
        require __DIR__ .'/../partials/preview_modal.php';
        ?>
    </div>
</div>

<div class="sf-form-container sf-form-language">

    <div class="sf-translation-banner">
        <div class="sf-translation-banner-title">
            <?php echo htmlspecialchars(sf_term('form_language_label', $uiLang) ?: 'Kieliversio', ENT_QUOTES, 'UTF-8'); ?>: <strong><?php echo htmlspecialchars($langLabel); ?></strong>
        </div>
        <div class="sf-translation-banner-meta">
            <?php echo htmlspecialchars(sf_term('source_flash_from_label', $uiLang) ?: 'Tiedotteesta', ENT_QUOTES, 'UTF-8'); ?> ID #<?php echo (int) $baseFlash['id']; ?> ·
            <?php echo htmlspecialchars(sf_term('step1_short', $uiLang) ?: 'Tyyppi', ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars($baseFlash['type']); ?> ·
            <?php echo htmlspecialchars(sf_term('site_label', $uiLang) ?: 'Työmaa', ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars($baseFlash['site']); ?> ·
            <?php echo htmlspecialchars(sf_term('occurred_at', $uiLang) ?: 'Tapahtuma-aika', ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars($baseFlash['occurred_at']); ?>
        </div>
        <div class="sf-translation-banner-info">
            <?php echo htmlspecialchars(sf_term('form_lang_info_text', $uiLang) ?: 'Tämä näkymä on tarkoitettu käännösten ja kielenhuollon tekemiseen valmiille safetyflashille. Perustiedot (tyyppi, työmaa, tapahtuma-aika, kuvat) on lukittu pohjatiedotteen mukaisiksi, eikä niitä muuteta.', ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>

    <form class="sf-form" method="post" action="<?php echo $config['base_url']; ?>/app/api/save_translation.php">
        <?php echo sf_csrf_field(); ?>
        <input type="hidden" name="from_id" value="<?php echo (int) $fromId; ?>">
        <input type="hidden" name="lang" value="<?php echo htmlspecialchars($newLang); ?>">
        <input type="hidden" name="translation_group_id" value="<?php echo (int) $translationGroupId; ?>">
        <input type="hidden" name="preview_image" id="preview_image">

        <!-- Perustietojen "snapshot" (vain luettavaksi) -->
        <div class="sf-form-section sf-form-language-meta">
            <h2><?php echo htmlspecialchars(sf_term('form_lang_locked_meta_heading', $uiLang) ?: 'Perustiedot (lukittu)', ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="sf-form-meta-grid">
                <div>
                    <label><?php echo htmlspecialchars(sf_term('step1_short', $uiLang) ?: 'Tyyppi', ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="sf-meta-value"><?php echo htmlspecialchars($baseFlash['type']); ?></div>
                </div>
                <div>
                    <label><?php echo htmlspecialchars(sf_term('site_label', $uiLang) ?: 'Työmaa', ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="sf-meta-value"><?php echo htmlspecialchars($baseFlash['site']); ?></div>
                </div>
                <div>
                    <label><?php echo htmlspecialchars(sf_term('site_detail_label', $uiLang) ?: 'Sijainti / tarkenne', ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="sf-meta-value"><?php echo htmlspecialchars($baseFlash['site_detail']); ?></div>
                </div>
                <div>
                    <label><?php echo htmlspecialchars(sf_term('occurred_at', $uiLang) ?: 'Tapahtuma-aika', ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="sf-meta-value"><?php echo htmlspecialchars($baseFlash['occurred_at']); ?></div>
                </div>
            </div>
        </div>

        <!-- Varsinainen käännöslomake -->
        <div class="sf-form-section sf-form-language-grid">
            <div class="sf-lang-column sf-lang-base">
                <h3><?php echo htmlspecialchars(sf_term('form_lang_base_column_heading', $uiLang) ?: 'Pohjakieli', ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars(strtoupper($baseFlash['lang'] ?? 'FI')); ?>)</h3>
<div class="sf-field">
    <label>Sisäinen otsikko (pohja)</label>
    <div class="sf-meta-value">
        <?php echo htmlspecialchars($baseFlash['title']); ?>
    </div>
</div>
                <div class="sf-field">
                    <label>Näkyvä otsikko (pohja)</label>
                    <div class="sf-meta-value">
                        <?php echo htmlspecialchars($baseFlash['title_short'] ?: $baseFlash['title']); ?>
                    </div>
                </div>

                <div class="sf-field">
                    <label>Lyhyt kuvaus (pohja)</label>
                    <div class="sf-meta-value">
                        <?php echo nl2br(htmlspecialchars($baseFlash['summary'])); ?>
                    </div>
                </div>

                <div class="sf-field">
                    <label>Laaja kuvaus (pohja)</label>
                    <div class="sf-meta-value">
                        <?php echo nl2br(htmlspecialchars($baseFlash['description'])); ?>
                    </div>
                </div>
            </div>

            <div class="sf-lang-column sf-lang-target">
                <h3><?php echo htmlspecialchars(sf_term('form_lang_target_column_heading', $uiLang) ?: 'Käännös', ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars($langLabel); ?></h3>
<div class="sf-field">
    <label>Sisäinen otsikko (käännös)</label>
    <input type="text" name="title"
           value="<?php echo htmlspecialchars($baseFlash['title']); ?>">
</div>
                <div class="sf-field">
                    <label>Näkyvä otsikko (käännös)</label>
                    <input type="text" name="title_short"
                           value="<?php echo htmlspecialchars($baseFlash['title_short'] ?: $baseFlash['title']); ?>">
                </div>

                <div class="sf-field">
                    <label>Lyhyt kuvaus (käännös)</label>
                    <textarea name="summary" rows="3"><?php echo htmlspecialchars($baseFlash['summary']); ?></textarea>
                </div>

                <div class="sf-field">
                    <label>Laaja kuvaus (käännös)</label>
                    <textarea name="description" rows="8"><?php echo htmlspecialchars($baseFlash['description']); ?></textarea>
                </div>
            </div>
        </div>

        <div class="sf-form-actions">
            <a href="index.php?page=view&id=<?php echo (int)$baseFlash['id']; ?>" class="sf-btn sf-btn-secondary">
                <?php echo htmlspecialchars(sf_term('btn_cancel', $uiLang) ?: 'Peruuta', ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <button type="submit" name="fl_action" value="save" class="sf-btn sf-btn-outline" id="flSaveBtn">
                <?php echo htmlspecialchars(sf_term('btn_save', $uiLang) ?: 'Tallenna', ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <button type="button" class="sf-btn sf-btn-outline" id="flAddLanguageBtn"
                    title="<?php echo htmlspecialchars(sf_term('btn_add_language_version_title', $uiLang) ?: 'Tallenna ensin luonnoksena, luo sitten uusi kieliversio', ENT_QUOTES, 'UTF-8'); ?>">
                ➕ <?php echo htmlspecialchars(sf_term('btn_add_language_version', $uiLang) ?: 'Lisää kieliversio', ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <button type="submit" name="fl_action" value="review" class="sf-btn sf-btn-primary" id="flSendReviewBtn">
                <?php echo htmlspecialchars(sf_term('btn_send_review', $uiLang) ?: 'Lähetä tarkistettavaksi', ENT_QUOTES, 'UTF-8'); ?>
            </button>
        </div>
    </form>
</div>

<!-- Lisää kieliversio -modaali (form_language) -->
<div id="flAddLanguageModal" class="sf-modal hidden" role="dialog" aria-modal="true" aria-labelledby="flAddLanguageModalTitle">
  <div class="sf-modal-overlay" id="flAddLanguageOverlay"></div>
  <div class="sf-modal-content" style="max-width:480px">
    <div class="sf-modal-header">
      <h3 id="flAddLanguageModalTitle">
        ➕ <?php echo htmlspecialchars(sf_term('add_language_modal_title', $uiLang) ?: 'Lisää kieliversio', ENT_QUOTES, 'UTF-8'); ?>
      </h3>
      <button type="button" class="sf-modal-close" id="flAddLanguageClose" aria-label="<?php echo htmlspecialchars(sf_term('btn_close', $uiLang) ?: 'Sulje', ENT_QUOTES, 'UTF-8'); ?>">×</button>
    </div>
    <div class="sf-modal-body">
      <p style="margin:0 0 16px 0;color:#64748b">
        <?php echo htmlspecialchars(sf_term('add_language_modal_intro', $uiLang) ?: 'Nykyinen versio tallennetaan luonnokseksi. Valitse kieli uudelle kieliversion:', ENT_QUOTES, 'UTF-8'); ?>
      </p>
      <div id="flLangOptions" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px"></div>
      <p id="flLangOptionsEmpty" style="display:none;color:#e53e3e;font-size:.9rem">
        <?php echo htmlspecialchars(sf_term('add_language_all_used', $uiLang) ?: 'Kaikki tuetut kieliversiot on jo luotu.', ENT_QUOTES, 'UTF-8'); ?>
      </p>
    </div>
    <div class="sf-modal-footer" style="display:flex;justify-content:flex-end;gap:8px">
      <button type="button" class="sf-btn sf-btn-secondary" id="flAddLanguageCancel">
        <?php echo htmlspecialchars(sf_term('btn_cancel', $uiLang) ?: 'Peruuta', ENT_QUOTES, 'UTF-8'); ?>
      </button>
      <button type="button" class="sf-btn sf-btn-primary" id="flAddLanguageConfirm" disabled>
        <?php echo htmlspecialchars(sf_term('btn_continue', $uiLang) ?: 'Jatka →', ENT_QUOTES, 'UTF-8'); ?>
      </button>
    </div>
  </div>
</div>
<!-- Kuvainfo JavaScript-datan muodossa -->
<script>
window.SF_BASE_URL = <?php echo json_encode($config['base_url'] ?? ''); ?>;

// Apufunktio kuva-URL:ien muodostamiseen
function getImageUrlForJs(filename) {
    if (! filename) return '';
    const base = window.SF_BASE_URL;
    const dir = filename.startsWith('lib_') ? 'library' : 'images';
    return base + '/uploads/' + dir + '/' + filename;
}

// Kuvainfo preview-modalille
window.SF_FLASH_DATA = {
    id: <?php echo (int)$baseFlash['id']; ?>,
    type: <?php echo json_encode($baseFlash['type']); ?>,
    lang: <?php echo json_encode($baseFlash['lang'] ?? 'fi'); ?>,
    title: <?php echo json_encode($baseFlash['title']); ?>,
    title_short: <?php echo json_encode($baseFlash['title_short'] ?? $baseFlash['title']); ?>,
    summary: <?php echo json_encode($baseFlash['summary'] ?? ''); ?>,
    description: <?php echo json_encode($baseFlash['description'] ?? ''); ?>,
    site:  <?php echo json_encode($baseFlash['site'] ?? ''); ?>,
    site_detail: <?php echo json_encode($baseFlash['site_detail'] ?? ''); ?>,
    occurred_at: <?php echo json_encode($baseFlash['occurred_at'] ?? ''); ?>,
    
    // Kuvatiedostot
    image_main: <?php echo json_encode($baseFlash['image_main'] ?? ''); ?>,
    image_2: <?php echo json_encode($baseFlash['image_2'] ?? ''); ?>,
    image_3: <?php echo json_encode($baseFlash['image_3'] ?? ''); ?>,
    
    // Kuva-URLit
    image_main_url: getImageUrlForJs(<?php echo json_encode($baseFlash['image_main'] ??  ''); ?>),
    image_2_url: getImageUrlForJs(<?php echo json_encode($baseFlash['image_2'] ?? ''); ?>),
    image_3_url: getImageUrlForJs(<?php echo json_encode($baseFlash['image_3'] ?? ''); ?>),
    
    // Muunnokset ja grid-tyyli
    image1_transform: <?php echo json_encode($baseFlash['image1_transform'] ?? ''); ?>,
    image2_transform: <?php echo json_encode($baseFlash['image2_transform'] ?? ''); ?>,
    image3_transform: <?php echo json_encode($baseFlash['image3_transform'] ?? ''); ?>,
    grid_style: <?php echo json_encode($baseFlash['grid_style'] ?? 'grid-3-main-top'); ?>,
};

window.SF_SUPPORTED_LANGS = {
    'fi': { label: 'FI', icon: 'finnish-flag.png' },
    'sv': { label: 'SV', icon: 'swedish-flag.png' },
    'en': { label: 'EN', icon: 'english-flag.png' },
    'it': { label: 'IT', icon: 'italian-flag.png' },
    'el': { label: 'EL', icon: 'greece-flag.png' }
};

// All supported languages
window.SF_FL_ALL_LANGS = <?php echo json_encode(array_keys($supportedLangs), JSON_UNESCAPED_UNICODE); ?>;
// Already used languages in this translation group
window.SF_FL_USED_LANGS = <?php echo json_encode(array_values($usedLangsInGroup), JSON_UNESCAPED_UNICODE); ?>;
// CSRF token for API calls
window.SF_FL_CSRF_TOKEN = <?php
    $csrfToken = '';
    if (function_exists('sf_csrf_token')) {
        $csrfToken = sf_csrf_token();
    } elseif (isset($_SESSION['csrf_token'])) {
        $csrfToken = $_SESSION['csrf_token'];
    }
    echo json_encode($csrfToken);
?>;
</script>

<script>
(function () {
    'use strict';

    var baseUrl     = (window.SF_BASE_URL || '').replace(/\/$/, '');
    var allLangs    = window.SF_FL_ALL_LANGS || ['fi', 'sv', 'en', 'it', 'el'];
    var usedLangs   = window.SF_FL_USED_LANGS || [];
    var csrfToken   = window.SF_FL_CSRF_TOKEN || '';
    var langNames   = {fi:'Suomi 🇫🇮', sv:'Ruotsi 🇸🇪', en:'Englanti 🇬🇧', it:'Italia 🇮🇹', el:'Kreikka 🇬🇷'};

    var sfForm = document.querySelector('form.sf-form');
    var addLanguageBtn  = document.getElementById('flAddLanguageBtn');
    var sendReviewBtn   = document.getElementById('flSendReviewBtn');
    var saveBtn         = document.getElementById('flSaveBtn');
    var errorPrefix     = <?= json_encode(sf_term('error_prefix', $uiLang) ?: 'Virhe:', JSON_UNESCAPED_UNICODE) ?>;

    // Helper: show error with i18n prefix
    function showError(msg) {
        alert(errorPrefix + ' ' + msg);
    }

    // Helper: submit the form and return JSON result
    async function submitFormAsJson(actionValue) {
        if (!sfForm) return null;
        var data = new FormData(sfForm);
        data.set('fl_action', actionValue);
        var resp = await fetch(sfForm.action, { method: 'POST', body: data });
        var text = await resp.text();
        try { return JSON.parse(text); } catch (e) { return null; }
    }

    // Helper: show/hide loading state on a button
    function setLoading(btn, loading) {
        if (!btn) return;
        btn.disabled = loading;
        btn.style.opacity = loading ? '0.6' : '';
    }

    // "Lisää kieliversio" button: open language selection modal
    var modal        = document.getElementById('flAddLanguageModal');
    var overlay      = document.getElementById('flAddLanguageOverlay');
    var closeBtn     = document.getElementById('flAddLanguageClose');
    var cancelBtn    = document.getElementById('flAddLanguageCancel');
    var confirmBtn   = document.getElementById('flAddLanguageConfirm');
    var optionsDiv   = document.getElementById('flLangOptions');
    var emptyMsg     = document.getElementById('flLangOptionsEmpty');
    var selectedLang = null;

    function openModal() {
        selectedLang = null;
        if (confirmBtn) confirmBtn.disabled = true;
        if (optionsDiv) optionsDiv.innerHTML = '';

        var available = allLangs.filter(function (l) { return usedLangs.indexOf(l) === -1; });

        if (available.length === 0) {
            if (emptyMsg) emptyMsg.style.display = 'block';
            if (optionsDiv) optionsDiv.style.display = 'none';
        } else {
            if (emptyMsg) emptyMsg.style.display = 'none';
            if (optionsDiv) optionsDiv.style.display = 'flex';
            available.forEach(function (lang) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'sf-lang-option-btn';
                btn.dataset.lang = lang;
                btn.textContent = langNames[lang] || lang.toUpperCase();
                btn.style.cssText = 'padding:10px 18px;border:2px solid #cbd5e1;border-radius:8px;background:#fff;cursor:pointer;font-size:1rem;transition:all .15s';
                btn.addEventListener('click', function () {
                    if (optionsDiv) {
                        optionsDiv.querySelectorAll('.sf-lang-option-btn').forEach(function (b) {
                            b.style.borderColor = '#cbd5e1';
                            b.style.background  = '#fff';
                            b.style.fontWeight  = '';
                        });
                    }
                    btn.style.borderColor = '#2563eb';
                    btn.style.background  = '#eff6ff';
                    btn.style.fontWeight  = '600';
                    selectedLang = lang;
                    if (confirmBtn) confirmBtn.disabled = false;
                });
                if (optionsDiv) optionsDiv.appendChild(btn);
            });
        }

        if (modal) {
            modal.classList.remove('hidden');
            document.body.classList.add('sf-modal-open');
        }
    }

    function closeModal() {
        if (modal) {
            modal.classList.add('hidden');
            document.body.classList.remove('sf-modal-open');
        }
    }

    if (addLanguageBtn) addLanguageBtn.addEventListener('click', openModal);
    if (closeBtn)       closeBtn.addEventListener('click', closeModal);
    if (cancelBtn)      cancelBtn.addEventListener('click', closeModal);
    if (overlay)        overlay.addEventListener('click', closeModal);

    if (confirmBtn) {
        confirmBtn.addEventListener('click', async function () {
            if (!selectedLang) return;
            closeModal();

            setLoading(addLanguageBtn, true);
            setLoading(confirmBtn, true);

            try {
                // 1. Save current translation form
                var saveResult = await submitFormAsJson('save');
                if (!saveResult || !saveResult.success) {
                    throw new Error((saveResult && saveResult.error) ? saveResult.error : 'Tallennus epäonnistui');
                }

                var newId = saveResult.new_id;

                // 2. Create another language draft using bundle_add_language API
                var bundleData = new FormData();
                bundleData.append('source_id', newId);
                bundleData.append('target_lang', selectedLang);
                bundleData.append('csrf_token', csrfToken);

                var bundleResp = await fetch(baseUrl + '/app/api/bundle_add_language.php', {
                    method: 'POST',
                    body: bundleData
                });
                var bundleText = await bundleResp.text();
                var bundleResult = null;
                try { bundleResult = JSON.parse(bundleText); } catch (e) { }

                if (!bundleResult || !bundleResult.success) {
                    throw new Error((bundleResult && bundleResult.error) ? bundleResult.error : 'Kieliversion luonti epäonnistui');
                }

                // 3. Navigate to the new language version form
                window.location.href = bundleResult.redirect;

            } catch (err) {
                setLoading(addLanguageBtn, false);
                setLoading(confirmBtn, false);
                showError(err.message);
            }
        });
    }

    // "Lähetä tarkistettavaksi": save then go to form step 6 for supervisor selection
    if (sendReviewBtn) {
        sendReviewBtn.addEventListener('click', async function (e) {
            e.preventDefault();
            setLoading(sendReviewBtn, true);
            try {
                var saveResult = await submitFormAsJson('save');
                if (!saveResult || !saveResult.success) {
                    throw new Error((saveResult && saveResult.error) ? saveResult.error : 'Tallennus epäonnistui');
                }
                // Navigate to the form step 6 to select supervisor and submit
                window.location.href = baseUrl + '/index.php?page=form&id=' + encodeURIComponent(saveResult.new_id) + '&step=6';
            } catch (err) {
                setLoading(sendReviewBtn, false);
                showError(err.message);
            }
        });
    }

    // "Tallenna": submit form normally (handled by form_language.js / server redirect)
    // No special JS needed – the default form submit goes to save_translation.php which returns JSON with redirect
    if (saveBtn && sfForm) {
        saveBtn.addEventListener('click', async function (e) {
            e.preventDefault();
            setLoading(saveBtn, true);
            try {
                var saveResult = await submitFormAsJson('save');
                if (!saveResult || !saveResult.success) {
                    throw new Error((saveResult && saveResult.error) ? saveResult.error : 'Tallennus epäonnistui');
                }
                window.location.href = saveResult.redirect || (baseUrl + '/index.php?page=view&id=' + encodeURIComponent(saveResult.new_id));
            } catch (err) {
                setLoading(saveBtn, false);
                showError(err.message);
            }
        });
    }
})();

<!-- Close-form confirmation modal (form_language) -->
<?php
$_flCloseTitle = isset($uiLang) ? (sf_term('form_close_confirm_title', $uiLang) ?: 'Poistu lomakkeelta?') : 'Poistu lomakkeelta?';
$_flCloseText  = isset($uiLang) ? (sf_term('form_close_confirm_text', $uiLang) ?: 'Haluatko varmasti poistua? Tallentamattomat muutokset menetetään.') : 'Haluatko varmasti poistua? Tallentamattomat muutokset menetetään.';
$_flLeave      = isset($uiLang) ? (sf_term('form_close_confirm_leave', $uiLang) ?: 'Poistu') : 'Poistu';
$_flCancel     = isset($uiLang) ? (sf_term('btn_cancel', $uiLang) ?: 'Peruuta') : 'Peruuta';
$_flBtnClose   = isset($uiLang) ? (sf_term('btn_close', $uiLang) ?: 'Sulje') : 'Sulje';
$_flBase       = rtrim($config['base_url'] ?? '/', '/');
?>
<div id="sfCloseConfirmModal" class="sf-modal hidden sf-modal-small sf-modal-centered" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="sfCloseConfirmTitle">
  <div class="sf-modal-content">
    <div class="sf-modal-header">
      <h3 id="sfCloseConfirmTitle"><?= htmlspecialchars($_flCloseTitle, ENT_QUOTES, 'UTF-8') ?></h3>
      <button type="button" class="sf-modal-close-btn" id="sfCloseConfirmDismiss" aria-label="<?= htmlspecialchars($_flBtnClose, ENT_QUOTES, 'UTF-8') ?>">×</button>
    </div>
    <div class="sf-modal-body">
      <p><?= htmlspecialchars($_flCloseText, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="sf-modal-actions">
      <button type="button" class="sf-btn sf-btn-secondary" id="sfCloseConfirmCancel">
        <?= htmlspecialchars($_flCancel, ENT_QUOTES, 'UTF-8') ?>
      </button>
      <button type="button" class="sf-btn sf-btn-danger" id="sfCloseConfirmLeave">
        <?= htmlspecialchars($_flLeave, ENT_QUOTES, 'UTF-8') ?>
      </button>
    </div>
  </div>
</div>

<script>
(function () {
    'use strict';
    var isFormDirty = false;
    var listUrl = <?= json_encode($_flBase . '/index.php?page=list', JSON_UNESCAPED_SLASHES) ?>;

    var sfForm = document.querySelector('.sf-form-language .sf-form, form.sf-form');
    if (sfForm) {
        sfForm.addEventListener('change', function () { isFormDirty = true; }, { passive: true });
        sfForm.addEventListener('input', function () { isFormDirty = true; }, { passive: true });
    }

    function openCloseModal() {
        var modal = document.getElementById('sfCloseConfirmModal');
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeCloseModal() {
        var modal = document.getElementById('sfCloseConfirmModal');
        if (!modal) return;
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    }
    function handleClose() {
        if (!isFormDirty) {
            window.location.href = listUrl;
        } else {
            openCloseModal();
        }
    }

    var closeBtn = document.getElementById('sfFormLangCloseBtn');
    if (closeBtn) closeBtn.addEventListener('click', handleClose);

    var cancelBtn = document.getElementById('sfCloseConfirmCancel');
    var dismissBtn = document.getElementById('sfCloseConfirmDismiss');
    if (cancelBtn) cancelBtn.addEventListener('click', closeCloseModal);
    if (dismissBtn) dismissBtn.addEventListener('click', closeCloseModal);

    var leaveBtn = document.getElementById('sfCloseConfirmLeave');
    if (leaveBtn) {
        leaveBtn.addEventListener('click', function () {
            window.location.href = listUrl;
        });
    }

    var modal = document.getElementById('sfCloseConfirmModal');
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeCloseModal();
        });
    }
})();
</script>