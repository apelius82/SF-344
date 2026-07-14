<?php
// assets/pages/settings/tab_images_fallback.php
declare(strict_types=1);

// Fallback image current value
$fallbackImagePath = sf_get_setting('display_fallback_image', '');
$fallbackImageUrl  = ($fallbackImagePath && $baseUrl)
    ? rtrim($baseUrl, '/') . '/' . ltrim($fallbackImagePath, '/')
    : '';
?>

<h2>
    <img src="<?= $baseUrl ?>/assets/img/icons/display.svg" alt="" class="sf-heading-icon" aria-hidden="true">
    <?= htmlspecialchars(sf_term('display_fallback_heading', $currentUiLang) ?? 'Infonäyttöjen fallback-kuva', ENT_QUOTES, 'UTF-8') ?>
</h2>
<p style="margin-bottom:1rem;color:#64748b;font-size:0.9rem;">
    <?= htmlspecialchars(sf_term('display_fallback_description', $currentUiLang) ?? 'Näytetään kun playlistassa ei ole flasheja. Suositeltu koko 1920×1080. Näkyy 5 sekuntia.', ENT_QUOTES, 'UTF-8') ?>
</p>

<div id="sfFallbackPreview" style="margin-bottom:0.75rem;<?= $fallbackImageUrl ? '' : 'display:none;' ?>">
    <p style="font-size:0.85rem;color:#475569;margin-bottom:0.4rem;">
        <?= htmlspecialchars(sf_term('display_fallback_current', $currentUiLang) ?? 'Nykyinen kuva', ENT_QUOTES, 'UTF-8') ?>
    </p>
    <img id="sfFallbackImg" src="<?= htmlspecialchars($fallbackImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""
         style="max-width:200px;border:1px solid #cbd5e1;border-radius:4px;">
</div>
<?php if (!$fallbackImageUrl): ?>
<p id="sfFallbackNone" style="color:#94a3b8;font-size:0.85rem;margin-bottom:0.75rem;">
    <?= htmlspecialchars(sf_term('display_fallback_none', $currentUiLang) ?? 'Ei fallback-kuvaa asetettu', ENT_QUOTES, 'UTF-8') ?>
</p>
<?php endif; ?>

<div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
    <label class="sf-btn sf-btn-outline-primary sf-btn-sm" style="cursor:pointer;margin:0;">
        <?= htmlspecialchars(sf_term('display_fallback_choose', $currentUiLang) ?? 'Valitse kuva...', ENT_QUOTES, 'UTF-8') ?>
        <input type="file" id="sfFallbackFile" accept="image/jpeg,image/png,image/webp" style="display:none;">
    </label>
    <button type="button" id="sfFallbackRemove"
            class="sf-btn sf-btn-sm sf-btn-outline-danger"
            style="<?= $fallbackImageUrl ? '' : 'display:none;' ?>">
        <?= htmlspecialchars(sf_term('display_fallback_remove', $currentUiLang) ?? 'Poista', ENT_QUOTES, 'UTF-8') ?>
    </button>
</div>

<script>
(function() {
    'use strict';
    var baseUrl      = <?= json_encode(rtrim($baseUrl, '/'), JSON_UNESCAPED_SLASHES) ?>;
    var csrfToken    = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_UNESCAPED_SLASHES) ?>;
    var apiUrl       = baseUrl + '/app/api/upload_display_fallback.php';
    var msgUploadErr = <?= json_encode(sf_term('save_error', $currentUiLang) ?? 'Upload failed', JSON_UNESCAPED_UNICODE) ?>;
    var msgRemoveErr = <?= json_encode(sf_term('save_error', $currentUiLang) ?? 'Remove failed', JSON_UNESCAPED_UNICODE) ?>;

    var fileInput    = document.getElementById('sfFallbackFile');
    var removeBtn    = document.getElementById('sfFallbackRemove');
    var previewWrap  = document.getElementById('sfFallbackPreview');
    var previewImg   = document.getElementById('sfFallbackImg');
    var noneMsg      = document.getElementById('sfFallbackNone');

    function showPreview(url) {
        if (previewImg)  previewImg.src = url;
        if (previewWrap) previewWrap.style.display = '';
        if (noneMsg)     noneMsg.style.display = 'none';
        if (removeBtn)   removeBtn.style.display = '';
    }

    function hidePreview() {
        if (previewWrap) previewWrap.style.display = 'none';
        if (noneMsg)     noneMsg.style.display = '';
        if (removeBtn)   removeBtn.style.display = 'none';
    }

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            if (!fileInput.files || !fileInput.files.length) return;
            var fd = new FormData();
            fd.append('action', 'upload');
            fd.append('csrf_token', csrfToken);
            fd.append('image', fileInput.files[0]);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', apiUrl, true);
            xhr.onload = function() {
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.ok) {
                        showPreview(r.url);
                    } else {
                        alert(r.error || msgUploadErr);
                    }
                } catch(e) { alert(msgUploadErr); }
                fileInput.value = '';
            };
            xhr.onerror = function() { alert(msgUploadErr); fileInput.value = ''; };
            xhr.send(fd);
        });
    }

    if (removeBtn) {
        removeBtn.addEventListener('click', function() {
            var fd = new FormData();
            fd.append('action', 'remove');
            fd.append('csrf_token', csrfToken);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', apiUrl, true);
            xhr.onload = function() {
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.ok) {
                        hidePreview();
                    } else {
                        alert(r.error || msgRemoveErr);
                    }
                } catch(e) { alert(msgRemoveErr); }
            };
            xhr.onerror = function() { alert(msgRemoveErr); };
            xhr.send(fd);
        });
    }
})();
</script>