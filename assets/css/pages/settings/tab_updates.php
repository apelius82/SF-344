<?php
// assets/pages/settings/tab_updates.php
declare(strict_types=1);

// $currentUiLang and $baseUrl come from settings.php
// Database is already configured via config.php

$db = Database::getInstance();

// Fetch all changelog entries (published and drafts) newest first
$stmt = $db->prepare(
    "SELECT c.*, u.first_name, u.last_name
     FROM sf_changelog c
     LEFT JOIN sf_users u ON c.created_by = u.id
     ORDER BY c.created_at DESC"
);
$stmt->execute();
$entries = $stmt->fetchAll();

// Supported languages from terms config
$termsConfig = sf_get_terms_config();
$supportedLangs = $termsConfig['languages'] ?? ['fi', 'sv', 'en', 'it', 'el'];
$langLabels = [
    'fi' => 'Suomi (FI)',
    'sv' => 'Svenska (SV)',
    'en' => 'English (EN)',
    'it' => 'Italiano (IT)',
    'el' => 'Ελληνικά (EL)',
];

// Current admin user id
$adminUser = sf_current_user();
$adminUserId = (int)($adminUser['id'] ?? 0);

$csrfField = sf_csrf_field();
$csrfToken = sf_csrf_token();
?>

<div class="sf-settings-section">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
        <h3 style="margin:0;padding:0;border:none;">
            <?= htmlspecialchars(sf_term('admin_updates_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h3>
        <button type="button" class="sf-btn sf-btn-primary" id="btnNewUpdate">
            <img src="<?= $baseUrl ?>/assets/img/icons/changelog_icon.svg" alt="" class="sf-btn-icon" aria-hidden="true">
            <?= htmlspecialchars(sf_term('admin_updates_new', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <?php if (empty($entries)): ?>
        <p style="color:var(--sf-muted);">
            <?= htmlspecialchars(sf_term('updates_empty', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php else: ?>
        <div class="sf-updates-admin-list">
            <?php foreach ($entries as $entry): ?>
                <?php
                $translations = [];
                if (!empty($entry['translations'])) {
                    $decoded = json_decode($entry['translations'], true);
                    if (is_array($decoded)) {
                        $translations = $decoded;
                    }
                }
                // Show Finnish title if available, otherwise first available
                $displayTitle = '';
                foreach (['fi', 'en', 'sv'] as $tryLang) {
                    if (!empty($translations[$tryLang]['title'])) {
                        $displayTitle = $translations[$tryLang]['title'];
                        break;
                    }
                }
                if ($displayTitle === '') {
                    foreach ($translations as $t) {
                        if (!empty($t['title'])) {
                            $displayTitle = $t['title'];
                            break;
                        }
                    }
                }
                $isPublished = (int)$entry['is_published'];
                $authorName = trim(($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? ''));
                if ($authorName === '') $authorName = 'Admin';
                // Show publish_date in admin listing when set, otherwise created_at
                $rawDisplayDate = !empty($entry['publish_date']) ? $entry['publish_date'] : $entry['created_at'];
                $displayTs = strtotime($rawDisplayDate);
                if ($displayTs === false) { $displayTs = time(); }
                $dateStr = date('d.m.Y', $displayTs);
                ?>
                <div class="sf-updates-admin-item" id="update-row-<?= (int)$entry['id'] ?>">
                    <div class="sf-updates-admin-item-info">
                        <span class="sf-updates-admin-status <?= $isPublished ? 'published' : 'draft' ?>">
                            <?= $isPublished
                                ? htmlspecialchars(sf_term('admin_updates_publish', $currentUiLang), ENT_QUOTES, 'UTF-8')
                                : htmlspecialchars(sf_term('admin_updates_draft', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <strong class="sf-updates-admin-title">
                            <?= htmlspecialchars($displayTitle ?: '—', ENT_QUOTES, 'UTF-8') ?>
                        </strong>
                        <span class="sf-updates-admin-meta"><?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($entry['feedback_id'])): ?>
                            <span class="sf-updates-admin-meta">
                                <?= htmlspecialchars(sf_term('updates_linked_feedback', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                #<?= (int)$entry['feedback_id'] ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="sf-updates-admin-item-actions">
                        <button type="button"
                                class="sf-btn sf-btn-small sf-btn-secondary btn-edit-update"
                                data-id="<?= (int)$entry['id'] ?>"
                                data-translations="<?= htmlspecialchars(json_encode($translations), ENT_QUOTES, 'UTF-8') ?>"
                                data-is-published="<?= $isPublished ?>"
                                data-feedback-id="<?= (int)($entry['feedback_id'] ?? 0) ?>"
                                data-publish-date="<?= htmlspecialchars($entry['publish_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-images="<?= htmlspecialchars($entry['images'] ?? '[]', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(sf_term('admin_updates_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button"
                                class="sf-btn sf-btn-small sf-btn-secondary btn-test-email-update"
                                data-id="<?= (int)$entry['id'] ?>"
                                data-toast-success="<?= htmlspecialchars(sf_term('updates_test_email_sent', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                                data-toast-error="<?= htmlspecialchars(sf_term('updates_test_email_error', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                                title="<?= htmlspecialchars(sf_term('updates_send_test_email', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                            ✉️ <?= htmlspecialchars(sf_term('updates_send_test_email', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button"
                                class="sf-btn sf-btn-small sf-btn-danger btn-delete-update"
                                data-id="<?= (int)$entry['id'] ?>">
                            <?= htmlspecialchars(sf_term('feedback_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Create / Edit Update Modal -->
<div id="modalUpdateEdit" class="sf-modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content sf-update-modal-wide">
        <div class="sf-modal-header">
            <h3 id="modalUpdateEditTitle">
                <?= htmlspecialchars(sf_term('admin_updates_new', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>

        <form id="formUpdateEdit" class="sf-modal-body">
            <?= $csrfField ?>
            <input type="hidden" id="updateEditId" name="update_id" value="0">
            <input type="hidden" id="updateEditFeedbackId" name="feedback_id" value="0">

            <div class="sf-update-modal-grid">
            <div class="sf-update-modal-left">

            <!-- Language tabs -->
            <div class="sf-lang-tabs" style="display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap;">
                <?php foreach ($supportedLangs as $idx => $lang): ?>
                    <button type="button"
                            class="sf-btn sf-btn-small <?= $idx === 0 ? 'sf-btn-primary' : 'sf-btn-secondary' ?> sf-update-lang-tab"
                            data-lang="<?= htmlspecialchars($lang) ?>">
                        <?= htmlspecialchars($langLabels[$lang] ?? strtoupper($lang)) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Per-language fields -->
            <?php foreach ($supportedLangs as $idx => $lang): ?>
                <div class="sf-update-lang-panel" data-lang="<?= htmlspecialchars($lang) ?>"
                     <?= $idx !== 0 ? 'style="display:none;"' : '' ?>>
                    <div class="sf-form-group">
                        <label for="updateTitle_<?= $lang ?>">
                            <?= htmlspecialchars(sf_term('updates_field_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            (<?= htmlspecialchars(strtoupper($lang)) ?>)
                        </label>
                        <input type="text"
                               id="updateTitle_<?= $lang ?>"
                               name="title_<?= $lang ?>"
                               class="sf-form-input sf-update-title-field"
                               data-lang="<?= htmlspecialchars($lang) ?>">
                    </div>
                    <div class="sf-form-group">
                        <label for="updateContent_<?= $lang ?>">
                            <?= htmlspecialchars(sf_term('updates_field_content', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            (<?= htmlspecialchars(strtoupper($lang)) ?>)
                        </label>
                        <div id="updateContentEditor_<?= $lang ?>"
                             class="sf-update-quill-editor"
                             data-lang="<?= htmlspecialchars($lang) ?>"
                             style="min-height:140px;background:#fff;"></div>
                        <input type="hidden"
                               id="updateContent_<?= $lang ?>"
                               name="content_<?= $lang ?>"
                               class="sf-update-content-field"
                               data-lang="<?= htmlspecialchars($lang) ?>">
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="sf-form-group" style="margin-top:12px;">
                <label for="updatePublishDate">
                    <?= htmlspecialchars(sf_term('updates_field_publish_date', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input type="date"
                       id="updatePublishDate"
                       name="publish_date"
                       class="sf-form-input">
            </div>

            <div class="sf-form-group" style="margin-top:12px;">
                <label class="sf-checkbox-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="updateIsPublished" name="is_published" value="1"
                           style="width:18px;height:18px;cursor:pointer;">
                    <span><?= htmlspecialchars(sf_term('updates_field_is_published', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </label>
            </div>

            </div><!-- /.sf-update-modal-left -->

            <!-- Right column: image uploads -->
            <div class="sf-update-modal-right">
                <span class="sf-update-images-label">
                    <?= htmlspecialchars(sf_term('updates_field_images', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <p class="sf-update-images-help">
                    <?= htmlspecialchars(sf_term('updates_images_help', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <div class="sf-update-image-slots">
                    <?php for ($slot = 1; $slot <= 3; $slot++): ?>
                    <div class="sf-update-image-slot" data-slot="<?= $slot ?>">
                        <input type="file" id="updateImage_<?= $slot ?>" name="image_<?= $slot ?>" accept="image/*" class="sf-update-image-input" hidden>
                        <input type="hidden" id="updateImageExisting_<?= $slot ?>" name="existing_image_<?= $slot ?>" value="">
                        <div class="sf-update-image-preview" id="updateImagePreview_<?= $slot ?>">
                            <div class="sf-update-image-placeholder" id="updateImagePlaceholder_<?= $slot ?>">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5">
                                    <rect x="3" y="3" width="18" height="18" rx="3"/>
                                    <circle cx="8.5" cy="8.5" r="1.5"/>
                                    <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>
                                </svg>
                                <span><?= htmlspecialchars(sf_term('updates_field_images', $currentUiLang), ENT_QUOTES, 'UTF-8') ?> <?= $slot ?></span>
                            </div>
                            <img id="updateImageThumb_<?= $slot ?>" class="sf-update-image-thumb hidden" alt="Screenshot <?= $slot ?>">
                            <button type="button" class="sf-update-image-remove hidden" data-slot="<?= $slot ?>"
                                    title="<?= htmlspecialchars(sf_term('updates_image_remove', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">×</button>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div><!-- /.sf-update-modal-right -->

            </div><!-- /.sf-update-modal-grid -->
        </form>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('feedback_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnSaveUpdate">
                <?= htmlspecialchars(sf_term('feedback_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<style>
.sf-update-quill-editor .ql-toolbar {
    border-radius: var(--sf-radius, 14px) var(--sf-radius, 14px) 0 0;
    border-color: var(--sf-border);
}
.sf-update-quill-editor .ql-container {
    border-radius: 0 0 var(--sf-radius, 14px) var(--sf-radius, 14px);
    border-color: var(--sf-border);
    font-size: 0.9rem;
    font-family: inherit;
}
.sf-updates-admin-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.sf-updates-admin-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 18px;
    background: var(--sf-surface, #fff);
    border: 1px solid var(--sf-border);
    border-radius: var(--sf-radius, 14px);
    flex-wrap: wrap;
}

.sf-updates-admin-item-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    flex: 1;
    min-width: 0;
}

.sf-updates-admin-title {
    font-size: 0.95rem;
    color: var(--sf-text, #111827);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 280px;
}

.sf-updates-admin-meta {
    font-size: 0.78rem;
    color: var(--sf-muted);
}

.sf-updates-admin-status {
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 20px;
    white-space: nowrap;
}

.sf-updates-admin-status.published {
    background: #dcfce7;
    color: #166534;
}

.sf-updates-admin-status.draft {
    background: #f3f4f6;
    color: #6b7280;
}

.sf-updates-admin-item-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

/* Remove yellow focus outline from Quill rich text editor */
.sf-update-quill-editor .ql-editor:focus,
.sf-update-quill-editor .ql-editor:focus-visible {
    outline: none;
    box-shadow: none;
}

/* Wide modal layout */
.sf-update-modal-wide { max-width: 960px !important; }

.sf-update-modal-grid {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 24px;
    align-items: start;
}

.sf-update-modal-left { min-width: 0; }
.sf-update-modal-right { position: sticky; top: 0; }

.sf-update-images-label { display: block; font-size: 0.9rem; font-weight: 600; color: #374151; margin-bottom: 4px; }
.sf-update-images-help { font-size: 0.8rem; color: #6b7280; margin: 0 0 12px; line-height: 1.4; }

.sf-update-image-slots { display: flex; flex-direction: column; gap: 12px; }

.sf-update-image-preview {
    position: relative; width: 100%; aspect-ratio: 16 / 10;
    border: 2px dashed #d1d5db; border-radius: 12px; overflow: hidden;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    background: #f9fafb; transition: border-color 0.2s ease, background 0.2s ease;
}
.sf-update-image-preview:hover { border-color: #93c5fd; background: #eff6ff; }
.sf-update-image-preview.has-image { border-style: solid; border-color: #e5e7eb; }

.sf-update-image-placeholder { display: flex; flex-direction: column; align-items: center; gap: 6px; color: #9ca3af; font-size: 0.8rem; pointer-events: none; }

.sf-update-image-thumb { width: 100%; height: 100%; object-fit: cover; display: block; }
.sf-update-image-thumb.hidden { display: none; }

.sf-update-image-remove {
    position: absolute; top: 6px; right: 6px; width: 26px; height: 26px;
    border-radius: 50%; border: none; background: rgba(239, 68, 68, 0.9); color: #fff;
    font-size: 16px; font-weight: 700; cursor: pointer; display: flex;
    align-items: center; justify-content: center; opacity: 0; transition: opacity 0.15s ease; z-index: 2;
}
.sf-update-image-preview:hover .sf-update-image-remove:not(.hidden) { opacity: 1; }
.sf-update-image-remove:hover { background: #dc2626; }
.sf-update-image-remove.hidden { display: none !important; }

@media (max-width: 768px) {
    .sf-update-modal-wide { max-width: 100% !important; }
    .sf-update-modal-grid { grid-template-columns: 1fr; gap: 20px; }
    .sf-update-modal-right { position: static; order: 2; }
    .sf-update-image-slots { flex-direction: row; gap: 8px; }
    .sf-update-image-slot { flex: 1; min-width: 0; }
    .sf-update-image-preview { aspect-ratio: 1 / 1; }
}
</style>

<script src="<?= $baseUrl ?>/assets/js/vendor/quill.min.js"></script>
<script src="<?= $baseUrl ?>/assets/js/vendor/purify.min.js"></script>
<script>
(function () {
    const BASE_URL = <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    const SUPPORTED_LANGS = <?= json_encode($supportedLangs) ?>;

    const i18n = {
        deleteConfirm: <?= json_encode(sf_term('admin_updates_delete_confirm', $currentUiLang)) ?>,
        saved:         <?= json_encode(sf_term('admin_updates_saved', $currentUiLang)) ?>,
        deleted:       <?= json_encode(sf_term('admin_updates_deleted', $currentUiLang)) ?>,
        errorSave:     <?= json_encode(sf_term('updates_error_save', $currentUiLang)) ?>,
        errorDelete:   <?= json_encode(sf_term('updates_error_delete', $currentUiLang)) ?>,
        titleNew:      <?= json_encode(sf_term('admin_updates_new', $currentUiLang)) ?>,
        titleEdit:     <?= json_encode(sf_term('admin_updates_edit', $currentUiLang)) ?>,
        imageTooLarge: <?= json_encode(sf_term('updates_image_too_large', $currentUiLang)) ?>,
    };

    // Quill instances keyed by language code
    const quillEditors = {};
    const SAFE_TAGS = ['P', 'BR', 'STRONG', 'EM', 'U', 'OL', 'UL', 'LI', 'SPAN'];
    const QUILL_EMPTY_HTML = '<p><br></p>';

    function sanitizeHtml(html) {
        if (typeof DOMPurify !== 'undefined') {
            return DOMPurify.sanitize(html, { ALLOWED_TAGS: SAFE_TAGS, ALLOWED_ATTR: [] });
        }
        return html;
    }

    // ---- Image slot helpers ----
    const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    function clearImageSlot(slot) {
        const fileInput = document.getElementById('updateImage_' + slot);
        const existingInput = document.getElementById('updateImageExisting_' + slot);
        const thumb = document.getElementById('updateImageThumb_' + slot);
        const placeholder = document.getElementById('updateImagePlaceholder_' + slot);
        const removeBtn = document.querySelector('.sf-update-image-remove[data-slot="' + slot + '"]');
        const preview = document.getElementById('updateImagePreview_' + slot);
        if (fileInput) { fileInput.value = ''; }
        if (existingInput) { existingInput.value = ''; }
        if (thumb) { thumb.src = ''; thumb.classList.add('hidden'); }
        if (placeholder) { placeholder.style.display = ''; }
        if (removeBtn) { removeBtn.classList.add('hidden'); }
        if (preview) { preview.classList.remove('has-image'); }
    }

    function showImageThumb(slot, src) {
        const thumb = document.getElementById('updateImageThumb_' + slot);
        const placeholder = document.getElementById('updateImagePlaceholder_' + slot);
        const removeBtn = document.querySelector('.sf-update-image-remove[data-slot="' + slot + '"]');
        const preview = document.getElementById('updateImagePreview_' + slot);
        if (thumb) { thumb.src = src; thumb.classList.remove('hidden'); }
        if (placeholder) { placeholder.style.display = 'none'; }
        if (removeBtn) { removeBtn.classList.remove('hidden'); }
        if (preview) { preview.classList.add('has-image'); }
    }

    function loadExistingImages(images) {
        // images: array of path strings
        for (let i = 0; i < 3; i++) {
            const slot = i + 1;
            clearImageSlot(slot);
            if (images && images[i]) {
                const path = images[i];
                const existingInput = document.getElementById('updateImageExisting_' + slot);
                if (existingInput) { existingInput.value = path; }
                showImageThumb(slot, BASE_URL + '/' + path);
            }
        }
    }

    function resetImageSlots() {
        for (let slot = 1; slot <= 3; slot++) {
            clearImageSlot(slot);
        }
    }

    // Wire up image preview click → file input
    document.querySelectorAll('.sf-update-image-preview').forEach(function (preview) {
        preview.addEventListener('click', function (e) {
            // Don't trigger file input when clicking the remove button
            if (e.target.classList.contains('sf-update-image-remove') || e.target.closest('.sf-update-image-remove')) { return; }
            const slot = this.id.replace('updateImagePreview_', '');
            const fileInput = document.getElementById('updateImage_' + slot);
            if (fileInput) { fileInput.click(); }
        });
    });

    // Wire up file input change → preview
    document.querySelectorAll('.sf-update-image-input').forEach(function (fileInput) {
        fileInput.addEventListener('change', function () {
            const slot = this.id.replace('updateImage_', '');
            const file = this.files && this.files[0];
            if (!file) { return; }
            if (file.size > MAX_IMAGE_BYTES) {
                if (typeof window.sfToast === 'function') { window.sfToast('danger', i18n.imageTooLarge); }
                else { alert(i18n.imageTooLarge); }
                this.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function (ev) { showImageThumb(slot, ev.target.result); };
            reader.readAsDataURL(file);
            // Clear existing path so the new file takes precedence
            const existingInput = document.getElementById('updateImageExisting_' + slot);
            if (existingInput) { existingInput.value = ''; }
        });
    });

    // Wire up remove buttons
    document.querySelectorAll('.sf-update-image-remove').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var slot = this.dataset.slot;
            clearImageSlot(slot);
        });
    });

    function getQuillEditor(lang) {
        if (quillEditors[lang]) { return quillEditors[lang]; }
        if (typeof Quill === 'undefined') { return null; }
        const editorEl = document.getElementById('updateContentEditor_' + lang);
        if (!editorEl) { return null; }
        quillEditors[lang] = new Quill('#updateContentEditor_' + lang, {
            theme: 'snow',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['clean']
                ]
            }
        });
        return quillEditors[lang];
    }

    // Do NOT initialize Quill editors up front — the modal is hidden (display:none)
    // and Quill cannot measure/render inside an invisible container.
    // Editors are initialized lazily when the modal is first opened.

    function openModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.remove('hidden'); m.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; }
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.add('hidden'); m.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
    }

    document.querySelectorAll('[data-modal-close]').forEach(btn => {
        btn.addEventListener('click', function () {
            const modal = this.closest('.sf-modal');
            if (modal) closeModal(modal.id);
        });
    });

    // Language tab switching
    document.querySelectorAll('.sf-update-lang-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            const lang = this.dataset.lang;
            document.querySelectorAll('.sf-update-lang-tab').forEach(t => {
                t.classList.remove('sf-btn-primary');
                t.classList.add('sf-btn-secondary');
            });
            this.classList.add('sf-btn-primary');
            this.classList.remove('sf-btn-secondary');
            document.querySelectorAll('.sf-update-lang-panel').forEach(p => {
                p.style.display = p.dataset.lang === lang ? '' : 'none';
            });
        });
    });

    function resetForm() {
        document.getElementById('updateEditId').value = '0';
        document.getElementById('updateEditFeedbackId').value = '0';
        document.getElementById('updateIsPublished').checked = false;
        document.getElementById('updatePublishDate').value = '';
        SUPPORTED_LANGS.forEach(lang => {
            const titleEl = document.getElementById('updateTitle_' + lang);
            if (titleEl) titleEl.value = '';
            const q = quillEditors[lang];
            if (q) { q.setContents([]); }
        });
        resetImageSlots();
        // Activate first language tab
        const firstTab = document.querySelector('.sf-update-lang-tab');
        if (firstTab) firstTab.click();
    }

    // New update
    document.getElementById('btnNewUpdate')?.addEventListener('click', function () {
        document.getElementById('modalUpdateEditTitle').textContent = i18n.titleNew;
        // Open modal first so editors are visible (not display:none) before Quill initializes
        openModal('modalUpdateEdit');
        resetForm();
        // Pre-fill publish date with today's date
        var today = new Date().toISOString().slice(0, 10);
        document.getElementById('updatePublishDate').value = today;
    });

    // Edit update
    document.querySelectorAll('.btn-edit-update').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('modalUpdateEditTitle').textContent = i18n.titleEdit;
            const id = this.dataset.id;
            const translations = JSON.parse(this.dataset.translations || '{}');
            const isPublished = parseInt(this.dataset.isPublished, 10) === 1;
            const feedbackId = parseInt(this.dataset.feedbackId, 10) || 0;
            const images = JSON.parse(this.dataset.images || '[]');

            // Open modal FIRST so editors become visible before Quill initializes
            openModal('modalUpdateEdit');
            // Reset (clears existing editor content if already initialized)
            resetForm();

            // Set form values AFTER reset so they are not cleared
            document.getElementById('updateEditId').value = id;
            document.getElementById('updateEditFeedbackId').value = feedbackId;
            document.getElementById('updateIsPublished').checked = isPublished;
            document.getElementById('updatePublishDate').value = this.dataset.publishDate || '';

            SUPPORTED_LANGS.forEach(lang => {
                const titleEl = document.getElementById('updateTitle_' + lang);
                if (titleEl) titleEl.value = (translations[lang] && translations[lang].title) || '';
                // Use getQuillEditor (lazy init) so editor is created now that modal is visible
                const q = getQuillEditor(lang);
                if (q) {
                    const rawContent = (translations[lang] && translations[lang].content) || '';
                    if (rawContent) {
                        q.clipboard.dangerouslyPasteHTML(sanitizeHtml(rawContent));
                    } else {
                        q.setContents([]);
                    }
                }
            });

            // Load existing images after modal opens
            setTimeout(() => loadExistingImages(images), 50);
        });
    });

    // Save update
    document.getElementById('btnSaveUpdate')?.addEventListener('click', async function () {
        const updateId = parseInt(document.getElementById('updateEditId').value, 10) || 0;
        const feedbackId = parseInt(document.getElementById('updateEditFeedbackId').value, 10) || 0;
        const isPublished = document.getElementById('updateIsPublished').checked ? 1 : 0;

        // Build translations object using Quill editor content
        const translations = {};
        SUPPORTED_LANGS.forEach(lang => {
            const title = (document.getElementById('updateTitle_' + lang)?.value || '').trim();
            const q = quillEditors[lang];
            const content = q ? sanitizeHtml(q.root.innerHTML).trim() : '';
            // Quill empty state produces QUILL_EMPTY_HTML — treat as empty
            const isEmpty = !content || content === QUILL_EMPTY_HTML;
            if (title || !isEmpty) {
                translations[lang] = { title, content: isEmpty ? '' : content };
            }
        });

        const formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('update_id', updateId);
        formData.append('feedback_id', feedbackId);
        formData.append('is_published', isPublished);
        formData.append('publish_date', document.getElementById('updatePublishDate').value || '');
        formData.append('translations', JSON.stringify(translations));

        // Append image files and existing image paths
        for (let slot = 1; slot <= 3; slot++) {
            const fileInput = document.getElementById('updateImage_' + slot);
            const existingInput = document.getElementById('updateImageExisting_' + slot);
            if (fileInput && fileInput.files && fileInput.files[0]) {
                formData.append('image_' + slot, fileInput.files[0]);
            }
            if (existingInput) {
                formData.append('existing_image_' + slot, existingInput.value || '');
            }
        }

        const endpoint = updateId > 0
            ? BASE_URL + '/app/api/changelog_update.php'
            : BASE_URL + '/app/api/changelog_create.php';

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF_TOKEN },
                body: formData
            });
            const data = await response.json();
            if (data.ok) {
                if (typeof window.sfToast === 'function') window.sfToast('success', i18n.saved);
                closeModal('modalUpdateEdit');
                setTimeout(() => window.location.reload(), 400);
            } else {
                if (typeof window.sfToast === 'function') window.sfToast('danger', data.error || i18n.errorSave);
                else alert(data.error || i18n.errorSave);
            }
        } catch (e) {
            console.error(e);
            if (typeof window.sfToast === 'function') window.sfToast('danger', i18n.errorSave);
            else alert(i18n.errorSave);
        }
    });

    // Delete update
    document.querySelectorAll('.btn-delete-update').forEach(btn => {
        btn.addEventListener('click', async function () {
            if (!confirm(i18n.deleteConfirm)) return;
            const id = this.dataset.id;
            const formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('update_id', id);
            try {
                const response = await fetch(BASE_URL + '/app/api/changelog_delete.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN },
                    body: formData
                });
                const data = await response.json();
                if (data.ok) {
                    if (typeof window.sfToast === 'function') window.sfToast('success', i18n.deleted);
                    const row = document.getElementById('update-row-' + id);
                    if (row) row.remove();
                } else {
                    if (typeof window.sfToast === 'function') window.sfToast('danger', data.error || i18n.errorDelete);
                    else alert(data.error || i18n.errorDelete);
                }
            } catch (e) {
                console.error(e);
                if (typeof window.sfToast === 'function') window.sfToast('danger', i18n.errorDelete);
                else alert(i18n.errorDelete);
            }
        });
    });

    // Send test email
    document.querySelectorAll('.btn-test-email-update').forEach(btn => {
        btn.addEventListener('click', async function () {
            const id         = this.dataset.id;
            const toastOk    = this.dataset.toastSuccess || '';
            const toastErr   = this.dataset.toastError   || '';
            const self       = this;

            self.disabled = true;
            const formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('update_id', id);
            try {
                const response = await fetch(BASE_URL + '/app/api/changelog_send_test_email.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await response.json();
                if (data.ok) {
                    if (typeof window.sfToast === 'function') window.sfToast('success', toastOk);
                } else {
                    if (typeof window.sfToast === 'function') window.sfToast('danger', data.error || toastErr);
                }
            } catch (e) {
                console.error(e);
                if (typeof window.sfToast === 'function') window.sfToast('danger', toastErr);
            } finally {
                self.disabled = false;
            }
        });
    });
})();
</script>