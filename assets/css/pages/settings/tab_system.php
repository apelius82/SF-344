<?php
// assets/pages/settings/tab_system.php
declare(strict_types=1);

$baseUrl = rtrim($config['base_url'] ?? '', '/');
$csrfToken = $_SESSION['csrf_token'] ?? '';
$xiboSummaryApiKeySetting = sf_get_setting('xibo_summary_api_key', null);
$xiboSummaryApiKey = $xiboSummaryApiKeySetting === null ? '' : trim((string)$xiboSummaryApiKeySetting);
if ($xiboSummaryApiKeySetting === null) {
    $xiboSummaryApiKey = trim((string)(getenv('XIBO_SUMMARY_API_KEY') ?: ''));
}

$xiboSummaryBackgroundPath = trim((string)sf_get_setting('xibo_summary_background_image', ''));
$xiboSummaryBackgroundUrl = ($xiboSummaryBackgroundPath !== '' && $baseUrl !== '')
    ? $baseUrl . '/' . ltrim($xiboSummaryBackgroundPath, '/')
    : '';

$xiboSummaryUrl = $baseUrl . '/xibo/safetyflash-summary/?api_key=' . rawurlencode($xiboSummaryApiKey);

$templateDir = dirname(__DIR__, 3) . '/assets/img/templates';
$templateFiles = [];

if (is_dir($templateDir)) {
    $allFiles = scandir($templateDir);
    if (is_array($allFiles)) {
        foreach ($allFiles as $file) {
            if (!is_string($file) || $file === '.' || $file === '..') {
                continue;
            }

            if (!preg_match('/\.jpg$/i', $file)) {
                continue;
            }

            $absolutePath = $templateDir . '/' . $file;
            if (!is_file($absolutePath)) {
                continue;
            }

            $dimensions = @getimagesize($absolutePath);
            $width = (int)($dimensions[0] ?? 0);
            $height = (int)($dimensions[1] ?? 0);

            $templateFiles[] = [
                'name' => $file,
                'url' => $baseUrl . '/assets/img/templates/' . rawurlencode($file) . '?v=' . (int)filemtime($absolutePath),
                'width' => $width,
                'height' => $height,
                'modified_at' => date('d.m.Y H:i', (int)filemtime($absolutePath)),
                'category' => str_starts_with($file, 'SF_report_') ? 'report' : 'flash',
            ];
        }
    }
}

usort($templateFiles, static function (array $a, array $b): int {
    return strcmp($a['name'], $b['name']);
});
?>

<style>
.sf-settings-section {
    margin-bottom: 32px;
}

.sf-settings-section h3 {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e2e8f0;
}

.sf-setting-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 0;
    border-bottom: 1px solid #f1f5f9;
}

.sf-setting-row:last-child {
    border-bottom: none;
}

.sf-setting-info {
    flex: 1;
}

.sf-setting-info label {
    font-weight: 500;
    color: #1e293b;
    display: block;
    margin-bottom: 4px;
}

.sf-setting-description {
    font-size: 13px;
    color: #64748b;
    margin: 0;
}

.sf-setting-control {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-left: 24px;
}

.sf-input-small {
    width: 80px;
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 14px;
    text-align: center;
}

.sf-input-suffix {
    font-size: 14px;
    color: #64748b;
}

.sf-toggle {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 26px;
}

.sf-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.sf-toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #cbd5e1;
    transition: 0.3s;
    border-radius: 26px;
}

.sf-toggle-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}

.sf-toggle input:checked + .sf-toggle-slider {
    background-color: #10b981;
}

.sf-toggle input:checked + .sf-toggle-slider:before {
    transform: translateX(22px);
}

.sf-template-help {
    margin: 0 0 18px 0;
    color: #64748b;
    font-size: 14px;
    line-height: 1.5;
}

.sf-template-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 20px;
}

.sf-template-card {
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: #ffffff;
    overflow: hidden;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
}

.sf-template-card-preview {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    aspect-ratio: 16 / 9;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.sf-template-card-preview.sf-template-card-preview-report {
    aspect-ratio: 1786 / 2526;
    max-height: 420px;
}

.sf-template-card-preview img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}

.sf-template-card-body {
    padding: 16px;
}

.sf-template-card-title {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 10px 0;
    word-break: break-word;
}

.sf-template-meta {
    display: grid;
    gap: 6px;
    margin-bottom: 14px;
    font-size: 13px;
    color: #475569;
}

.sf-template-meta strong {
    color: #0f172a;
}

.sf-template-upload-form {
    display: grid;
    gap: 12px;
}

.sf-template-upload-form input[type="file"] {
    width: 100%;
}

.sf-template-note {
    font-size: 12px;
    color: #64748b;
    line-height: 1.4;
    margin: 0;
}

.sf-template-empty {
    padding: 18px;
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    background: #f8fafc;
    color: #475569;
}

.sf-settings-actions {
    padding-top: 24px;
    border-top: 1px solid #e2e8f0;
    margin-top: 16px;
}

.sf-xibo-summary-box {
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    background: #f8fafc;
    padding: 16px;
    margin-top: 16px;
}

.sf-xibo-summary-url {
    margin: 0 0 10px;
    font-size: 13px;
    color: #475569;
}

.sf-xibo-summary-url code {
    display: block;
    margin-top: 8px;
    padding: 10px 12px;
    border-radius: 8px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #0f172a;
    word-break: break-all;
}

.sf-xibo-preview-wrap {
    width: 960px;
    height: 540px;
    overflow: hidden;
    border-radius: 12px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
}

.sf-xibo-preview-frame {
    width: 1920px;
    height: 1080px;
    border: 0;
    transform: scale(0.5);
    transform-origin: top left;
}
</style>

<div class="sf-settings-section">
    <h3><?= htmlspecialchars(sf_term('settings_list_page', $currentUiLang) ?? 'Lista-sivu', ENT_QUOTES, 'UTF-8') ?></h3>

    <div class="sf-setting-row">
        <div class="sf-setting-info">
            <label><?= htmlspecialchars(sf_term('settings_editing_indicator', $currentUiLang) ?? 'Näytä muokkaus-indikaattori', ENT_QUOTES, 'UTF-8') ?></label>
            <p class="sf-setting-description"><?= htmlspecialchars(sf_term('settings_editing_indicator_desc', $currentUiLang) ?? 'Näyttää listalla reaaliaikaisesti kuka muokkaa mitäkin SafetyFlashia', ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="sf-setting-control">
            <label class="sf-toggle">
                <input type="checkbox" id="editing_indicator_enabled" name="editing_indicator_enabled" <?= sf_get_setting('editing_indicator_enabled', false) ? 'checked' : '' ?>>
                <span class="sf-toggle-slider"></span>
            </label>
        </div>
    </div>

    <div class="sf-setting-row">
        <div class="sf-setting-info">
            <label for="editing_indicator_interval"><?= htmlspecialchars(sf_term('settings_polling_interval', $currentUiLang) ?? 'Päivitysväli', ENT_QUOTES, 'UTF-8') ?></label>
        </div>
        <div class="sf-setting-control">
            <input type="number" id="editing_indicator_interval" name="editing_indicator_interval" value="<?= (int)sf_get_setting('editing_indicator_interval', 30) ?>" min="10" max="120" class="sf-input-small">
            <span class="sf-input-suffix"><?= htmlspecialchars(sf_term('seconds', $currentUiLang) ?? 'sekuntia', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <div class="sf-setting-row">
        <div class="sf-setting-info">
            <label for="soft_lock_timeout"><?= htmlspecialchars(sf_term('settings_lock_timeout', $currentUiLang) ?? 'Lukituksen vanhenemisaika', ENT_QUOTES, 'UTF-8') ?></label>
        </div>
        <div class="sf-setting-control">
            <input type="number" id="soft_lock_timeout" name="soft_lock_timeout" value="<?= (int)sf_get_setting('soft_lock_timeout', 15) ?>" min="5" max="60" class="sf-input-small">
            <span class="sf-input-suffix"><?= htmlspecialchars(sf_term('minutes', $currentUiLang) ?? 'minuuttia', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
</div>

<div class="sf-settings-section">
    <h3>SafetyFlash-pohjat</h3>
    <p class="sf-template-help">
        Tässä osiossa voit korvata käytössä olevia SafetyFlash-pohjia uusilla versioilla.
        Tiedostonimi pysyy samana, jolloin kaikki nykyiset linkitykset, esikatselut ja generointi jatkavat toimintaansa ilman muita muutoksia.
        Korvaavan tiedoston pitää olla JPG-muotoinen ja kooltaan täsmälleen sama kuin nykyinen tiedosto.
    </p>

    <?php if (empty($templateFiles)): ?>
        <div class="sf-template-empty">Pohjatiedostoja ei löytynyt kansiosta assets/img/templates.</div>
    <?php else: ?>
        <div class="sf-template-grid">
            <?php foreach ($templateFiles as $template): ?>
                <div class="sf-template-card">
                    <div class="sf-template-card-preview <?= $template['category'] === 'report' ? 'sf-template-card-preview-report' : '' ?>">
                        <img src="<?= htmlspecialchars($template['url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="sf-template-card-body">
                        <h4 class="sf-template-card-title"><?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?></h4>

                        <div class="sf-template-meta">
                            <div><strong>Tyyppi:</strong> <?= htmlspecialchars($template['category'] === 'report' ? 'Raporttipohja' : 'SafetyFlash-pohja', ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>Koko:</strong> <?= (int)$template['width'] ?> × <?= (int)$template['height'] ?> px</div>
                            <div><strong>Päivitetty:</strong> <?= htmlspecialchars($template['modified_at'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <form
                            method="post"
                            action="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/app/actions/template_replace.php"
                            enctype="multipart/form-data"
                            class="sf-template-upload-form"
                        >
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="template_name" value="<?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?>">

                            <input
                                type="file"
                                name="template_file"
                                accept=".jpg,.jpeg,image/jpeg"
                                required
                                class="sf-input"
                            >

                            <button type="submit" class="sf-btn sf-btn-primary">
                                Korvaa pohja
                            </button>

                            <p class="sf-template-note">
                                Uusi tiedosto tallennetaan samalla tiedostonimellä:
                                <strong><?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </p>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="sf-settings-section">
    <h3>Xibo SafetyFlash -koontinäkymä</h3>
    <p class="sf-template-help">
        Koontinäkymä näyttää kaikki aktiiviset SafetyFlashit yhdellä 1920×1080 -dialla.
        Valitse halutessasi taustakuva tai käytä oletuksena valkoista taustaa.
    </p>

    <div id="sfXiboSummaryBgPreview" style="margin-bottom:0.75rem;<?= $xiboSummaryBackgroundUrl !== '' ? '' : 'display:none;' ?>">
        <p style="font-size:0.85rem;color:#475569;margin-bottom:0.4rem;">Nykyinen taustakuva</p>
        <img
            id="sfXiboSummaryBgImg"
            src="<?= htmlspecialchars($xiboSummaryBackgroundUrl, ENT_QUOTES, 'UTF-8') ?>"
            alt=""
            style="max-width:200px;border:1px solid #cbd5e1;border-radius:4px;"
        >
    </div>
    <?php if ($xiboSummaryBackgroundUrl === ''): ?>
        <p id="sfXiboSummaryBgNone" style="color:#94a3b8;font-size:0.85rem;margin-bottom:0.75rem;">
            Ei taustakuvaa asetettu (käytetään valkoista taustaa).
        </p>
    <?php endif; ?>

    <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
        <label class="sf-btn sf-btn-outline-primary sf-btn-sm" style="cursor:pointer;margin:0;">
            Valitse taustakuva...
            <input type="file" id="sfXiboSummaryBgFile" accept="image/jpeg,image/png,image/webp" style="display:none;">
        </label>
        <button
            type="button"
            id="sfXiboSummaryBgRemove"
            class="sf-btn sf-btn-sm sf-btn-outline-danger"
            style="<?= $xiboSummaryBackgroundUrl !== '' ? '' : 'display:none;' ?>"
        >
            Poista taustakuva
        </button>
    </div>

    <div class="sf-xibo-summary-box">
        <p class="sf-xibo-summary-url">
            Xibo URL:
            <code id="sfXiboSummaryUrl"><?= htmlspecialchars($xiboSummaryUrl, ENT_QUOTES, 'UTF-8') ?></code>
        </p>

        <?php if ($xiboSummaryApiKey === ''): ?>
            <p class="sf-setting-description">
                Aseta ympäristömuuttuja <strong>XIBO_SUMMARY_API_KEY</strong> (tai asetus <strong>xibo_summary_api_key</strong>),
                jotta näkymä voidaan avata API-avaimella.
            </p>
        <?php endif; ?>

        <p class="sf-setting-description" style="margin:10px 0 8px;">Esikatselu (50% / 960×540)</p>
        <div class="sf-xibo-preview-wrap">
            <iframe
                id="sfXiboSummaryPreview"
                class="sf-xibo-preview-frame"
                src="<?= htmlspecialchars($xiboSummaryApiKey !== '' ? $xiboSummaryUrl : 'about:blank', ENT_QUOTES, 'UTF-8') ?>"
                loading="lazy"
            ></iframe>
        </div>
    </div>
</div>

<div class="sf-settings-actions">
    <button type="button" id="saveSystemSettings" class="sf-btn sf-btn-primary"><?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?></button>
</div>

<script>
(function() {
    'use strict';

    const baseUrl = window.SF_BASE_URL || '<?= $baseUrl ?>';
    const csrfToken = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';
    const saveBtn = document.getElementById('saveSystemSettings');
    const xiboBgApiUrl = baseUrl + '/app/api/upload_display_fallback.php';
    const xiboBgFileInput = document.getElementById('sfXiboSummaryBgFile');
    const xiboBgRemoveBtn = document.getElementById('sfXiboSummaryBgRemove');
    const xiboBgPreview = document.getElementById('sfXiboSummaryBgPreview');
    const xiboBgImg = document.getElementById('sfXiboSummaryBgImg');
    const xiboBgNone = document.getElementById('sfXiboSummaryBgNone');

    function reloadXiboPreview() {
        const previewFrame = document.getElementById('sfXiboSummaryPreview');
        if (!previewFrame) {
            return;
        }
        const src = previewFrame.getAttribute('src') || '';
        if (!src || src === 'about:blank') {
            return;
        }
        try {
            const url = new URL(src, window.location.origin);
            url.searchParams.set('_ts', String(Date.now()));
            previewFrame.src = url.toString();
        } catch (e) {
            previewFrame.src = src;
        }
    }

    const setXiboBgVisible = function(url) {
        if (xiboBgImg) {
            xiboBgImg.src = url;
        }
        if (xiboBgPreview) {
            xiboBgPreview.style.display = '';
        }
        if (xiboBgNone) {
            xiboBgNone.style.display = 'none';
        }
        if (xiboBgRemoveBtn) {
            xiboBgRemoveBtn.style.display = '';
        }
    };

    const setXiboBgHidden = function() {
        if (xiboBgPreview) {
            xiboBgPreview.style.display = 'none';
        }
        if (xiboBgNone) {
            xiboBgNone.style.display = '';
        }
        if (xiboBgRemoveBtn) {
            xiboBgRemoveBtn.style.display = 'none';
        }
    };

    if (xiboBgFileInput) {
        xiboBgFileInput.addEventListener('change', function() {
            if (!xiboBgFileInput.files || !xiboBgFileInput.files.length) {
                return;
            }

            const fd = new FormData();
            fd.append('action', 'upload');
            fd.append('target', 'xibo_summary_background');
            fd.append('csrf_token', csrfToken);
            fd.append('image', xiboBgFileInput.files[0]);

            fetch(xiboBgApiUrl, {
                method: 'POST',
                body: fd
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.ok && data.url) {
                        setXiboBgVisible(data.url);
                        reloadXiboPreview();
                    } else {
                        alert(data.error || '<?= htmlspecialchars(sf_term('save_error', $currentUiLang) ?? 'Tallennus epäonnistui', ENT_QUOTES, 'UTF-8') ?>');
                    }
                })
                .catch(() => {
                    alert('<?= htmlspecialchars(sf_term('save_error', $currentUiLang) ?? 'Tallennus epäonnistui', ENT_QUOTES, 'UTF-8') ?>');
                })
                .finally(() => {
                    xiboBgFileInput.value = '';
                });
        });
    }

    if (xiboBgRemoveBtn) {
        xiboBgRemoveBtn.addEventListener('click', function() {
            const fd = new FormData();
            fd.append('action', 'remove');
            fd.append('target', 'xibo_summary_background');
            fd.append('csrf_token', csrfToken);

            fetch(xiboBgApiUrl, {
                method: 'POST',
                body: fd
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.ok) {
                        setXiboBgHidden();
                        reloadXiboPreview();
                    } else {
                        alert(data.error || '<?= htmlspecialchars(sf_term('save_error', $currentUiLang) ?? 'Tallennus epäonnistui', ENT_QUOTES, 'UTF-8') ?>');
                    }
                })
                .catch(() => {
                    alert('<?= htmlspecialchars(sf_term('save_error', $currentUiLang) ?? 'Tallennus epäonnistui', ENT_QUOTES, 'UTF-8') ?>');
                });
        });
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', async function() {
            const data = {
                editing_indicator_enabled: document.getElementById('editing_indicator_enabled')?.checked || false,
                editing_indicator_interval: parseInt(document.getElementById('editing_indicator_interval')?.value || '30', 10),
                soft_lock_timeout: parseInt(document.getElementById('soft_lock_timeout')?.value || '15', 10)
            };

            saveBtn.disabled = true;
            saveBtn.textContent = '<?= htmlspecialchars(sf_term('saving', $currentUiLang) ?? 'Tallennetaan...', ENT_QUOTES, 'UTF-8') ?>';

            try {
                const response = await fetch(baseUrl + '/app/api/save_settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(data)
                });

                if (response.ok) {
                    saveBtn.textContent = '<?= htmlspecialchars(sf_term('saved', $currentUiLang) ?? 'Tallennettu!', ENT_QUOTES, 'UTF-8') ?>';
                    setTimeout(() => {
                        saveBtn.textContent = '<?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>';
                        saveBtn.disabled = false;
                    }, 2000);
                } else {
                    const errorData = await response.json().catch(() => ({}));
                    const errorMsg = errorData.error || '<?= htmlspecialchars(sf_term('save_error', $currentUiLang) ?? 'Tallennus epäonnistui', ENT_QUOTES, 'UTF-8') ?>';
                    throw new Error(errorMsg);
                }
            } catch (e) {
                alert(e.message || '<?= htmlspecialchars(sf_term('save_error', $currentUiLang) ?? 'Tallennus epäonnistui', ENT_QUOTES, 'UTF-8') ?>');
                saveBtn.textContent = '<?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>';
                saveBtn.disabled = false;
            }
        });
    }
})();
</script>