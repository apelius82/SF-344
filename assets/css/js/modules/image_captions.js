/**
 * Image Captions Module - Modern inline editing
 */
(function () {
    'use strict';

    const API_URL = (window.SF_BASE_URL || '') + '/app/api/update_image_caption.php';

    function getCsrfToken() {
        const input = document.querySelector('input[name="csrf_token"]');
        if (input) return input.value;
        const el = document.querySelector('[data-csrf-token]');
        if (el) return el.getAttribute('data-csrf-token');
        return window.SF_CSRF_TOKEN || '';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showToast(message, type) {
        if (window.SF && window.SF.toast) {
            window.SF.toast(message, type);
            return;
        }
        const existing = document.querySelector('.caption-toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = `caption-toast caption-toast-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function createCaptionElement(container, config) {
        const { flashId, imageType, imageId, caption, canEdit } = config;

        const wrapper = document.createElement('div');
        wrapper.className = 'image-caption-wrapper';
        wrapper.dataset.flashId = flashId;
        wrapper.dataset.imageType = imageType;
        if (imageId) wrapper.dataset.imageId = imageId;

        const display = document.createElement('div');
        display.className = 'image-caption-display' + (canEdit ? ' editable' : '');
        display.innerHTML = caption
            ? `<span class="caption-text">${escapeHtml(caption)}</span>`
            : (canEdit ? '<span class="caption-placeholder">Lisää kuvateksti...</span>' : '');

        wrapper.appendChild(display);

        if (canEdit) {
            display.addEventListener('click', () => openEditor(wrapper, caption));
        }

        container.appendChild(wrapper);
        return wrapper;
    }

    function openEditor(wrapper, currentCaption) {
        if (wrapper.querySelector('.caption-editor')) return;

        const display = wrapper.querySelector('.image-caption-display');
        display.style.display = 'none';

        const editor = document.createElement('div');
        editor.className = 'caption-editor';

        const textarea = document.createElement('textarea');
        textarea.className = 'caption-input';
        textarea.value = currentCaption || '';
        textarea.placeholder = 'Kirjoita kuvateksti...';
        textarea.maxLength = 500;
        textarea.rows = 2;

        const actions = document.createElement('div');
        actions.className = 'caption-actions';

        const charCount = document.createElement('span');
        charCount.className = 'caption-char-count';
        charCount.textContent = `${textarea.value.length}/500`;

        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'caption-btn caption-btn-save';
        saveBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20,6 9,17 4,12"></polyline></svg>';
        saveBtn.title = 'Tallenna';

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'caption-btn caption-btn-cancel';
        cancelBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
        cancelBtn.title = 'Peruuta';

        actions.appendChild(charCount);
        actions.appendChild(cancelBtn);
        actions.appendChild(saveBtn);

        editor.appendChild(textarea);
        editor.appendChild(actions);
        wrapper.appendChild(editor);

        textarea.focus();
        textarea.select();

        textarea.addEventListener('input', () => {
            charCount.textContent = `${textarea.value.length}/500`;
        });

        textarea.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                saveCaption(wrapper, textarea.value);
            } else if (e.key === 'Escape') {
                closeEditor(wrapper, currentCaption);
            }
        });

        saveBtn.addEventListener('click', () => saveCaption(wrapper, textarea.value));
        cancelBtn.addEventListener('click', () => closeEditor(wrapper, currentCaption));
    }

    function closeEditor(wrapper, caption) {
        const editor = wrapper.querySelector('.caption-editor');
        const display = wrapper.querySelector('.image-caption-display');

        if (editor) editor.remove();

        display.style.display = '';
        display.innerHTML = caption
            ? `<span class="caption-text">${escapeHtml(caption)}</span>`
            : '<span class="caption-placeholder">Lisää kuvateksti...</span>';
    }

    async function saveCaption(wrapper, newCaption) {
        const flashId = wrapper.dataset.flashId;
        const imageType = wrapper.dataset.imageType;
        const imageId = wrapper.dataset.imageId || 0;

        const editor = wrapper.querySelector('.caption-editor');
        const saveBtn = editor.querySelector('.caption-btn-save');

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="caption-spinner"></span>';

        try {
            const formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('image_type', imageType);
            formData.append('image_id', imageId);
            formData.append('caption', newCaption.trim());
            formData.append('csrf_token', getCsrfToken());

            const response = await fetch(API_URL, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const data = await response.json();

            if (data.ok) {
                closeEditor(wrapper, newCaption.trim());
                showToast('Kuvateksti tallennettu', 'success');
            } else {
                throw new Error(data.error || 'Tallennus epäonnistui');
            }
        } catch (error) {
            console.error('Caption save error:', error);
            showToast(error.message || 'Virhe tallennuksessa', 'error');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20,6 9,17 4,12"></polyline></svg>';
        }
    }

    function initCaptions() {
        const flashId = window.SF_FLASH_ID || document.querySelector('[data-flash-id]')?.dataset.flashId;
        if (!flashId) return;

        const canEdit = window.SF_CAN_EDIT === true || document.querySelector('[data-can-edit="true"]') !== null;

        document.querySelectorAll('.main-image-container').forEach((container, index) => {
            if (container.querySelector('.image-caption-wrapper')) return;
            createCaptionElement(container, {
                flashId: flashId,
                imageType: 'main' + (index + 1),
                imageId: null,
                caption: container.dataset.caption || '',
                canEdit: canEdit
            });
        });

        document.querySelectorAll('.extra-image-container').forEach((container) => {
            if (container.querySelector('.image-caption-wrapper')) return;
            createCaptionElement(container, {
                flashId: flashId,
                imageType: 'extra',
                imageId: container.dataset.imageId,
                caption: container.dataset.caption || '',
                canEdit: canEdit
            });
        });
    }

    window.ImageCaptions = { init: initCaptions, createCaptionElement };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCaptions);
    } else {
        initCaptions();
    }
})();