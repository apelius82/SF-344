/**
 * Extra Images Upload Module
 *
 * Handles the UI and logic for uploading additional images and videos
 * to SafetyFlash reports.
 * Uses window.SF_TERMS for translations.
 */

(function () {
    'use strict';

    const MAX_EXTRA_IMAGES = 20;
    const MAX_UPLOAD_SIZE_BYTES = 20 * 1024 * 1024;
    const MAX_VIDEO_UPLOAD_SIZE_BYTES = 200 * 1024 * 1024;
    const CONCURRENT_UPLOADS = 3;
    const RETRY_DELAY_MS = 2000;
    const ALLOWED_MIME_REGEX = /^image\/(jpeg|png|gif|webp|heic|heif)$/i;
    const ALLOWED_EXTENSION_REGEX = /\.(jpe?g|png|gif|webp|heic|heif)$/i;
    const ALLOWED_VIDEO_MIME_REGEX = /^video\/(mp4|webm|ogg|quicktime|x-msvideo|x-matroska)$/i;
    const ALLOWED_VIDEO_EXTENSION_REGEX = /\.(mp4|webm|ogv|ogg|mov|avi|mkv)$/i;

    let extraImages = [];
    let extraVideos = [];
    let initialized = false;
    let pasteListenerBound = false;
    let activeUploads = 0;
    let uploadQueue = [];
    let batchTotal = 0;
    let batchDone = 0;
    let batchFailed = 0;
    let hideProgressTimeout = null;

    // Helper to retrieve translations
    function getTerm(key, fallback) {
        if (window.SF_TERMS && window.SF_TERMS[key]) {
            return window.SF_TERMS[key];
        }

        if (window.SF_I18N && window.SF_I18N[key]) {
            return window.SF_I18N[key];
        }

        return fallback || key;
    }

    function showToast(type, message) {
        if (typeof window.sfToast === 'function') {
            window.sfToast(type, message);
            return;
        }
        alert(message);
    }

    function showUploadError(message) {
        showToast('error', message);
    }

    function getFormBaseUrl() {
        return (window.SF_BASE_URL || '').replace(/\/$/, '');
    }

    function getCsrfToken() {
        return document.querySelector('input[name="csrf_token"]')?.value || '';
    }

    function isAllowedImageFile(file) {
        if (!file) return false;
        const type = String(file.type || '').toLowerCase();
        if (ALLOWED_MIME_REGEX.test(type)) return true;
        const name = String(file.name || '').toLowerCase();
        return ALLOWED_EXTENSION_REGEX.test(name);
    }

    function isAllowedVideoFile(file) {
        if (!file) return false;
        const type = String(file.type || '').toLowerCase();
        if (ALLOWED_VIDEO_MIME_REGEX.test(type)) return true;
        const name = String(file.name || '').toLowerCase();
        return ALLOWED_VIDEO_EXTENSION_REGEX.test(name);
    }

    function isSafeImageUrl(url, allowBlob) {
        if (typeof url !== 'string') return false;
        const trimmed = url.trim();
        if (!trimmed) return false;
        if (allowBlob && trimmed.startsWith('blob:')) return true;
        return /^https?:\/\//i.test(trimmed)
            || trimmed.startsWith('/')
            || trimmed.startsWith('./')
            || trimmed.startsWith('../');
    }

    function setImageSource(imgElement, url, allowBlob) {
        if (!imgElement || !isSafeImageUrl(url, allowBlob)) return false;
        const trimmed = String(url).trim();
        if (allowBlob && trimmed.startsWith('blob:')) {
            imgElement.src = trimmed;
            return true;
        }

        try {
            const parsed = new URL(trimmed, window.location.origin);
            if (!['http:', 'https:'].includes(parsed.protocol)) {
                return false;
            }
            if (parsed.origin !== window.location.origin) {
                return false;
            }
            imgElement.src = parsed.href;
            return true;
        } catch (error) {
            return false;
        }
    }

    function getCurrentImageCount() {
        return extraImages.length + activeUploads + uploadQueue.length;
    }

    function isExtraImagesStepVisible() {
        const container = document.getElementById('extra-images-container');
        if (!container) return false;
        const step = container.closest('.sf-step-content[data-step="4"]');
        return !step || step.classList.contains('active');
    }

    function isEditableElement(element) {
        if (!element || !(element instanceof HTMLElement)) return false;
        if (element.isContentEditable) return true;
        const tag = element.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    }

    function init() {
        const container = document.getElementById('extra-images-container');
        if (!container) return; // Not on the form page
        if (initialized) return;
        initialized = true;

        const uploadBtn = document.getElementById('extra-image-upload-btn');
        const cameraBtn = document.getElementById('extra-image-camera-btn');
        const fileInput = document.getElementById('extra-image-input');
        const cameraInput = document.getElementById('extra-image-camera-input');
        const videoUploadBtn = document.getElementById('extra-video-upload-btn');
        const videoInput = document.getElementById('extra-video-input');

        if (uploadBtn && fileInput) {
            uploadBtn.addEventListener('click', function (e) {
                e.preventDefault();
                fileInput.click();
            });
        }

        if (cameraBtn && cameraInput) {
            cameraBtn.addEventListener('click', function (e) {
                e.preventDefault();
                cameraInput.click();
            });
        }

        if (videoUploadBtn && videoInput) {
            videoUploadBtn.addEventListener('click', function (e) {
                e.preventDefault();
                videoInput.click();
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', handleFileSelect);
        }
        if (cameraInput) {
            cameraInput.addEventListener('change', handleFileSelect);
        }
        if (videoInput) {
            videoInput.addEventListener('change', handleVideoFileSelect);
        }

        const form = document.getElementById('sf-form');
        if (form) {
            form.addEventListener('submit', function () {
                injectExtraImagesData();
                injectExtraVideosData();
            });
            bindDropPrevention(form);
        }

        initContainerDropZone(container);
        initPasteSupport();

        // Initial button state update
        updateUploadButtonState();
        updateBatchProgressUI();
    }

    function bindDropPrevention(form) {
        const preventDefaultDrop = function (e) {
            const types = Array.from((e.dataTransfer && e.dataTransfer.types) || []);
            if (types.includes('Files')) {
                e.preventDefault();
            }
        };

        form.addEventListener('dragover', preventDefaultDrop);
        form.addEventListener('drop', preventDefaultDrop);
    }

    function initContainerDropZone(container) {
        let dragDepth = 0;

        const hasFiles = function (event) {
            const types = Array.from((event.dataTransfer && event.dataTransfer.types) || []);
            return types.includes('Files');
        };

        container.addEventListener('dragenter', function (e) {
            if (!hasFiles(e) || !isExtraImagesStepVisible()) return;
            e.preventDefault();
            dragDepth += 1;
            container.classList.add('drag-over');
        });

        container.addEventListener('dragover', function (e) {
            if (!hasFiles(e) || !isExtraImagesStepVisible()) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            container.classList.add('drag-over');
        });

        container.addEventListener('dragleave', function (e) {
            if (!hasFiles(e)) return;
            e.preventDefault();
            dragDepth = Math.max(0, dragDepth - 1);
            if (dragDepth === 0) {
                container.classList.remove('drag-over');
            }
        });

        container.addEventListener('drop', function (e) {
            if (!hasFiles(e) || !isExtraImagesStepVisible()) return;
            e.preventDefault();
            dragDepth = 0;
            container.classList.remove('drag-over');
            handleIncomingFiles(Array.from(e.dataTransfer.files || []));
        });

        document.addEventListener('dragend', function () {
            dragDepth = 0;
            container.classList.remove('drag-over');
        });
    }

    function initPasteSupport() {
        if (pasteListenerBound) return;
        pasteListenerBound = true;

        document.addEventListener('paste', function (e) {
            if (!isExtraImagesStepVisible()) return;
            if (isEditableElement(e.target)) return;

            const items = Array.from((e.clipboardData && e.clipboardData.items) || []);
            const files = items
                .filter(item => item.kind === 'file')
                .map(item => item.getAsFile())
                .filter(Boolean);

            if (files.length === 0) return;
            e.preventDefault();
            handleIncomingFiles(files);
        });
    }

    function handleFileSelect(e) {
        const files = Array.from((e.target && e.target.files) || []);
        if (!files || files.length === 0) return;
        handleIncomingFiles(files);
        e.target.value = '';
    }

    function handleIncomingFiles(files) {
        if (!files || files.length === 0) return;

        const currentImageCount = getCurrentImageCount();
        const availableSlots = MAX_EXTRA_IMAGES - currentImageCount;

        if (availableSlots <= 0) {
            const msg = getTerm('extra_img_max_limit', 'Maksimimäärä lisäkuvia on {n}').replace('{n}', MAX_EXTRA_IMAGES);
            showUploadError(msg);
            return;
        }

        const limitedFiles = files.slice(0, availableSlots);
        if (limitedFiles.length < files.length) {
            const msg = getTerm('extra_img_max_limit', 'Maksimimäärä lisäkuvia on {n}').replace('{n}', MAX_EXTRA_IMAGES);
            showUploadError(msg);
        }

        let invalidTypeCount = 0;
        let tooLargeCount = 0;
        const validFiles = [];
        limitedFiles.forEach(file => {
            if (!isAllowedImageFile(file)) {
                invalidTypeCount++;
                return;
            }
            if (file.size > MAX_UPLOAD_SIZE_BYTES) {
                tooLargeCount++;
                return;
            }
            validFiles.push(file);
        });

        if (invalidTypeCount > 0) {
            showUploadError(getTerm('extra_img_invalid_type', 'Virheellinen tiedostomuoto. Sallitut: JPEG, PNG, GIF, WEBP, HEIC, HEIF'));
        }
        if (tooLargeCount > 0) {
            showUploadError(getTerm('extra_img_too_large', 'Tiedosto on liian suuri. Maksimikoko: 20MB'));
        }

        if (validFiles.length === 0) return;
        queueUploads(validFiles);
    }

    function queueUploads(files) {
        if (activeUploads === 0 && uploadQueue.length === 0) {
            batchTotal = 0;
            batchDone = 0;
            batchFailed = 0;
            clearTimeout(hideProgressTimeout);
            hideProgressTimeout = null;
        }

        files.forEach(file => uploadQueue.push(file));
        batchTotal += files.length;
        updateBatchProgressUI();
        updateUploadButtonState();
        runUploadQueue();
    }

    function runUploadQueue() {
        while (activeUploads < CONCURRENT_UPLOADS && uploadQueue.length > 0) {
            const nextFile = uploadQueue.shift();
            activeUploads++;
            updateUploadButtonState();

            uploadFile(nextFile)
                .catch(() => {
                    // Error is already surfaced per file
                })
                .finally(() => {
                    activeUploads--;
                    batchDone++;
                    updateBatchProgressUI();
                    updateUploadButtonState();
                    runUploadQueue();
                    finalizeBatchIfFinished();
                });
        }
    }

    function finalizeBatchIfFinished() {
        if (activeUploads > 0 || uploadQueue.length > 0 || batchDone < batchTotal) {
            return;
        }

        if (batchFailed > 0) {
            const summaryTemplate = getTerm('images_upload_partial', '{failed} / {total} kuvan lataus epäonnistui');
            const summaryMessage = summaryTemplate
                .replace('{failed}', String(batchFailed))
                .replace('{total}', String(batchTotal));
            showUploadError(summaryMessage);
        }

        hideProgressTimeout = setTimeout(function () {
            batchTotal = 0;
            batchDone = 0;
            batchFailed = 0;
            updateBatchProgressUI();
        }, 1000);
    }

    function updateBatchProgressUI() {
        const progress = document.getElementById('extra-images-progress');
        const fill = document.getElementById('extra-images-progress-fill');
        const text = document.getElementById('extra-images-progress-text');
        if (!progress || !fill || !text) return;

        const isActive = batchTotal > 0 && (activeUploads > 0 || uploadQueue.length > 0 || batchDone < batchTotal);
        progress.classList.toggle('active', isActive || (batchTotal > 0 && batchDone > 0));

        if (batchTotal <= 0) {
            fill.style.width = '0%';
            text.textContent = '';
            return;
        }

        const percent = Math.round((batchDone / batchTotal) * 100);
        fill.style.width = percent + '%';
        const progressTemplate = getTerm('images_uploading', '{count} / {total} kuvaa ladattu');
        text.textContent = progressTemplate.replace('{count}', String(batchDone)).replace('{total}', String(batchTotal));
    }

    function delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    function isTransientStatus(status) {
        return status === 0 || status === 408 || status === 429 || (status >= 500 && status < 600);
    }

    function uploadFile(file) {
        const previewId = 'preview-' + Date.now() + '-' + Math.random().toString(36).slice(2, 11);
        const pendingItem = addPreviewItem(previewId, file.name, file, true);

        return uploadFileWithRetry(file, pendingItem)
            .then(data => {
                extraImages.push({
                    id: previewId,
                    filename: data.filename,
                    original_filename: data.original_filename,
                    url: data.url,
                    thumb_url: data.thumb_url
                });
                updatePreviewItem(previewId, data.thumb_url || data.url, false, data.original_filename || file.name);
                updateUploadButtonState();
            })
            .catch(error => {
                batchFailed++;
                removePreviewItem(previewId);
                const errorMessage = (error && error.message) ? error.message : getTerm('extra_img_upload_failed', 'Lataus epäonnistui');
                showUploadError(getTerm('extra_img_upload_failed', 'Lataus epäonnistui') + ': ' + errorMessage);
                throw error;
            });
    }

    function uploadFileWithRetry(file, pendingItem) {
        return uploadTempWithXhr(file, pendingItem).catch(async function (error) {
            const status = Number(error && error.status ? error.status : 0);
            if (!isTransientStatus(status)) {
                throw error;
            }

            if (pendingItem && pendingItem.statusLabel) {
                pendingItem.statusLabel.textContent = getTerm('upload_retrying', 'Yritetään uudelleen...');
                pendingItem.isRetrying = true;
            }
            await delay(RETRY_DELAY_MS);
            return uploadTempWithXhr(file, pendingItem);
        });
    }

    function uploadTempWithXhr(file, pendingItem) {
        return new Promise(function (resolve, reject) {
            const formData = new FormData();
            formData.append('image', file);

            const csrfToken = getCsrfToken();
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', getFormBaseUrl() + '/app/api/upload_extra_image.php', true);
            xhr.responseType = 'json';
            xhr.withCredentials = true;

            if (pendingItem && pendingItem.statusLabel) {
                pendingItem.statusLabel.textContent = pendingItem.isRetrying
                    ? getTerm('upload_retrying', 'Yritetään uudelleen...')
                    : getTerm('extra_img_processing', 'Prosessoidaan...');
            }

            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable || !pendingItem) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                pendingItem.progressFill.style.width = percent + '%';
                pendingItem.progressText.textContent = percent + '%';
            };

            xhr.onload = function () {
                let data = xhr.response;
                if (!data && xhr.responseText) {
                    try {
                        data = JSON.parse(xhr.responseText);
                    } catch (parseErr) {
                        data = null;
                    }
                }

                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    if (pendingItem) {
                        pendingItem.isRetrying = false;
                        pendingItem.progressFill.style.width = '100%';
                        pendingItem.progressText.textContent = '100%';
                    }
                    resolve(data);
                    return;
                }

                const error = new Error((data && data.error) || getTerm('extra_img_upload_failed', 'Lataus epäonnistui'));
                error.status = xhr.status;
                if (pendingItem) pendingItem.isRetrying = false;
                reject(error);
            };

            xhr.onerror = function () {
                const error = new Error(getTerm('extra_img_upload_failed', 'Lataus epäonnistui'));
                error.status = 0;
                if (pendingItem) pendingItem.isRetrying = false;
                reject(error);
            };

            xhr.send(formData);
        });
    }

    function createPreviewFallback(container, filename) {
        container.innerHTML = '';
        container.classList.add('extra-image-preview-placeholder');
        const wrapper = document.createElement('div');
        wrapper.className = 'extra-image-placeholder';
        const title = document.createElement('span');
        title.className = 'extra-image-placeholder-title';
        title.textContent = getTerm('extra_img_heic_placeholder', 'HEIC/HEIF');
        const detail = document.createElement('span');
        detail.textContent = filename || '';
        wrapper.appendChild(title);
        wrapper.appendChild(detail);
        container.appendChild(wrapper);
    }

    function addPreviewItem(id, filename, file, isLoading) {
        const grid = document.getElementById('extra-images-grid');
        if (!grid) return;

        const item = document.createElement('div');
        item.className = 'extra-image-item';
        item.id = id;

        const imgContainer = document.createElement('div');
        imgContainer.className = 'extra-image-preview';
        const canCreateObjectUrl = (typeof URL !== 'undefined')
            && typeof URL.createObjectURL === 'function'
            && file instanceof Blob;

        if (canCreateObjectUrl) {
            const objectUrl = URL.createObjectURL(file);
            if (typeof objectUrl === 'string' && objectUrl.startsWith('blob:')) {
                const previewImg = document.createElement('img');
                if (setImageSource(previewImg, objectUrl, true)) {
                    item.dataset.objectUrl = objectUrl;
                    previewImg.alt = filename || 'Extra image';
                    previewImg.onerror = function () {
                        createPreviewFallback(imgContainer, filename);
                    };
                    imgContainer.appendChild(previewImg);
                } else {
                    URL.revokeObjectURL(objectUrl);
                    createPreviewFallback(imgContainer, filename);
                }
            } else {
                if (objectUrl) {
                    URL.revokeObjectURL(objectUrl);
                }
                createPreviewFallback(imgContainer, filename);
            }
        } else {
            createPreviewFallback(imgContainer, filename);
        }

        const overlay = document.createElement('div');
        overlay.className = 'extra-image-overlay' + (isLoading ? '' : ' hidden');

        const status = document.createElement('div');
        status.className = 'extra-image-status';
        status.setAttribute('role', 'status');

        const spinner = document.createElement('span');
        spinner.className = 'extra-image-spinner';
        spinner.setAttribute('aria-hidden', 'true');

        const statusLabel = document.createElement('span');
        statusLabel.textContent = getTerm('extra_img_processing', 'Prosessoidaan...');

        status.appendChild(spinner);
        status.appendChild(statusLabel);

        const progressLine = document.createElement('div');
        progressLine.className = 'extra-image-progress-line';
        const progressFill = document.createElement('div');
        progressFill.className = 'extra-image-progress-fill';
        progressLine.appendChild(progressFill);

        const progressText = document.createElement('div');
        progressText.className = 'extra-image-progress-text';
        progressText.textContent = '0%';

        overlay.appendChild(status);
        overlay.appendChild(progressLine);
        overlay.appendChild(progressText);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'extra-image-remove';
        removeBtn.innerHTML = '&times;';
        removeBtn.setAttribute('aria-label', getTerm('extra_img_remove', 'Poista kuva'));
        removeBtn.title = getTerm('extra_img_remove', 'Poista kuva');
        removeBtn.addEventListener('click', function () {
            removeImage(id);
        });

        const filenameDiv = document.createElement('div');
        filenameDiv.className = 'extra-image-filename';
        filenameDiv.textContent = filename;
        filenameDiv.title = filename;

        item.appendChild(imgContainer);
        item.appendChild(overlay);
        item.appendChild(removeBtn);
        item.appendChild(filenameDiv);

        grid.appendChild(item);
        updateUploadButtonState();

        return {
            item,
            statusLabel,
            progressFill,
            progressText
        };
    }

    function revokeObjectUrl(item) {
        if (!item || !item.dataset.objectUrl) return;
        URL.revokeObjectURL(item.dataset.objectUrl);
        delete item.dataset.objectUrl;
    }

    function updatePreviewItem(id, thumbUrl, isLoading, filename) {
        const item = document.getElementById(id);
        if (!item) return;

        const imgContainer = item.querySelector('.extra-image-preview');
        const overlay = item.querySelector('.extra-image-overlay');
        if (!imgContainer) return;

        if (isLoading) {
            if (overlay) overlay.classList.remove('hidden');
        } else {
            revokeObjectUrl(item);
            imgContainer.innerHTML = '';
            imgContainer.classList.remove('extra-image-preview-placeholder');

            if (thumbUrl) {
                const img = document.createElement('img');
                if (setImageSource(img, thumbUrl, false)) {
                    img.alt = filename || 'Extra image';
                    img.onerror = function () {
                        createPreviewFallback(imgContainer, filename || '');
                    };
                    imgContainer.appendChild(img);
                } else {
                    createPreviewFallback(imgContainer, filename || '');
                }
            } else {
                createPreviewFallback(imgContainer, filename || '');
            }
            if (overlay) overlay.classList.add('hidden');
        }
    }

    function removePreviewItem(id) {
        const item = document.getElementById(id);
        if (item) {
            revokeObjectUrl(item);
            item.remove();
        }
        updateUploadButtonState();
    }

    function removeImage(id) {
        const index = extraImages.findIndex(img => img.id === id);
        if (index !== -1) {
            const image = extraImages[index];
            const csrfToken = getCsrfToken();
            const baseUrl = getFormBaseUrl();

            if (image.filename) {
                fetch(baseUrl + '/app/api/delete_temp_image.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `filename=${encodeURIComponent(image.filename)}&csrf_token=${encodeURIComponent(csrfToken || '')}`
                }).catch(e => console.warn('Delete temp failed', e));
            }
            extraImages.splice(index, 1);
        }
        removePreviewItem(id);
    }

    function updateUploadButtonState() {
        const uploadBtn = document.getElementById('extra-image-upload-btn');
        const cameraBtn = document.getElementById('extra-image-camera-btn');
        const count = document.getElementById('extra-images-count');
        const currentImageCount = getCurrentImageCount();
        const maxReached = currentImageCount >= MAX_EXTRA_IMAGES;

        if (count) {
            count.textContent = extraImages.length + '/' + MAX_EXTRA_IMAGES;
        }

        if (uploadBtn) {
            uploadBtn.disabled = maxReached;
            if (maxReached) {
                uploadBtn.textContent = getTerm('extra_img_max_reached', 'Maksimimäärä saavutettu');
            } else {
                uploadBtn.textContent = getTerm('extra_img_add_btn', 'Lisää kuvia');
            }
        }

        if (cameraBtn) {
            cameraBtn.disabled = maxReached;
        }
    }

    function injectExtraImagesData() {
        const existingInput = document.getElementById('extra_images_data');
        if (existingInput) existingInput.remove();

        const form = document.getElementById('sf-form');
        if (form && extraImages.length > 0) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.id = 'extra_images_data';
            input.name = 'extra_images';
            input.value = JSON.stringify(extraImages);
            form.appendChild(input);
        }
    }

    function injectExtraVideosData() {
        const existingInput = document.getElementById('extra_videos_data');
        if (existingInput) existingInput.remove();

        const form = document.getElementById('sf-form');
        if (form && extraVideos.length > 0) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.id = 'extra_videos_data';
            input.name = 'extra_videos';
            input.value = JSON.stringify(extraVideos);
            form.appendChild(input);
        }
    }

    function handleVideoFileSelect(e) {
        const files = Array.from((e.target && e.target.files) || []);
        if (!files || files.length === 0) return;
        handleIncomingVideoFiles(files);
        e.target.value = '';
    }

    function handleIncomingVideoFiles(files) {
        if (!files || files.length === 0) return;

        let invalidTypeCount = 0;
        let tooLargeCount = 0;
        const validFiles = [];

        files.forEach(file => {
            if (!isAllowedVideoFile(file)) {
                invalidTypeCount++;
                return;
            }
            if (file.size > MAX_VIDEO_UPLOAD_SIZE_BYTES) {
                tooLargeCount++;
                return;
            }
            validFiles.push(file);
        });

        if (invalidTypeCount > 0) {
            showUploadError(getTerm('video_upload_invalid_type', 'Virheellinen videomuoto. Sallitut: MP4, WebM, OGG, MOV'));
        }
        if (tooLargeCount > 0) {
            showUploadError(getTerm('video_upload_too_large', 'Videotiedosto on liian suuri. Maksimikoko: 200 Mt'));
        }

        if (validFiles.length === 0) return;
        validFiles.forEach(file => uploadVideoFile(file));
    }

    function uploadVideoFile(file) {
        const previewId = 'video-preview-' + Date.now() + '-' + Math.random().toString(36).slice(2, 11);
        addVideoPreviewItem(previewId, file.name);

        const formData = new FormData();
        formData.append('video', file);
        const csrfToken = getCsrfToken();
        if (csrfToken) formData.append('csrf_token', csrfToken);

        const baseUrl = getFormBaseUrl();
        const xhr = new XMLHttpRequest();
        xhr.open('POST', baseUrl + '/app/api/upload_extra_video.php', true);
        xhr.responseType = 'json';
        xhr.withCredentials = true;

        const progressFill = document.querySelector('#' + previewId + ' .extra-image-progress-fill');
        const progressText = document.querySelector('#' + previewId + ' .extra-image-progress-text');

        xhr.upload.onprogress = function (event) {
            if (!event.lengthComputable) return;
            const percent = Math.round((event.loaded / event.total) * 100);
            if (progressFill) progressFill.style.width = percent + '%';
            if (progressText) progressText.textContent = percent + '%';
        };

        xhr.onload = function () {
            let data = xhr.response;
            if (!data && xhr.responseText) {
                try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
            }
            if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                extraVideos.push({
                    id: previewId,
                    filename: data.filename,
                    original_filename: data.original_filename,
                    url: data.url,
                    media_type: 'video'
                });
                updateVideoPreviewItem(previewId, file.name);
                return;
            }
            showUploadError(getTerm('upload_error', 'Videon lataus epäonnistui') + ': ' + ((data && data.error) || ''));
            removeVideoPreviewItem(previewId);
        };

        xhr.onerror = function () {
            showUploadError(getTerm('upload_error', 'Videon lataus epäonnistui'));
            removeVideoPreviewItem(previewId);
        };

        xhr.send(formData);
    }

    function addVideoPreviewItem(id, filename) {
        const grid = document.getElementById('extra-images-grid');
        if (!grid) return;

        const item = document.createElement('div');
        item.className = 'extra-image-item extra-video-item';
        item.id = id;

        const iconDiv = document.createElement('div');
        iconDiv.className = 'extra-image-preview extra-video-preview';
        iconDiv.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" width="48" height="48" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';
        item.appendChild(iconDiv);

        const overlay = document.createElement('div');
        overlay.className = 'extra-image-overlay';

        const spinner = document.createElement('span');
        spinner.className = 'extra-image-spinner';
        spinner.setAttribute('aria-hidden', 'true');

        const statusLabel = document.createElement('span');
        statusLabel.textContent = getTerm('extra_img_processing', 'Ladataan...');

        const statusDiv = document.createElement('div');
        statusDiv.className = 'extra-image-status';
        statusDiv.setAttribute('role', 'status');
        statusDiv.appendChild(spinner);
        statusDiv.appendChild(statusLabel);

        const progressLine = document.createElement('div');
        progressLine.className = 'extra-image-progress-line';
        const progressFill = document.createElement('div');
        progressFill.className = 'extra-image-progress-fill';
        progressLine.appendChild(progressFill);
        const progressText = document.createElement('div');
        progressText.className = 'extra-image-progress-text';
        progressText.textContent = '0%';

        overlay.appendChild(statusDiv);
        overlay.appendChild(progressLine);
        overlay.appendChild(progressText);
        item.appendChild(overlay);

        const filenameDiv = document.createElement('div');
        filenameDiv.className = 'extra-image-filename';
        filenameDiv.textContent = filename;
        filenameDiv.title = filename;
        item.appendChild(filenameDiv);

        grid.appendChild(item);
    }

    function updateVideoPreviewItem(id, filename) {
        const item = document.getElementById(id);
        if (!item) return;
        const overlay = item.querySelector('.extra-image-overlay');
        if (overlay) overlay.classList.add('hidden');

        // Add remove button once upload succeeds
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'extra-image-remove';
        removeBtn.innerHTML = '&times;';
        removeBtn.setAttribute('aria-label', getTerm('extra_img_remove', 'Poista'));
        removeBtn.title = getTerm('extra_img_remove', 'Poista');
        removeBtn.addEventListener('click', function () {
            removeVideo(id);
        });
        item.appendChild(removeBtn);
    }

    function removeVideoPreviewItem(id) {
        const item = document.getElementById(id);
        if (item) item.remove();
    }

    function removeVideo(id) {
        const index = extraVideos.findIndex(v => v.id === id);
        if (index !== -1) {
            const video = extraVideos[index];
            const csrfToken = getCsrfToken();
            const baseUrl = getFormBaseUrl();
            if (video.filename) {
                fetch(baseUrl + '/app/api/delete_temp_image.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `filename=${encodeURIComponent(video.filename)}&csrf_token=${encodeURIComponent(csrfToken || '')}`
                }).catch(e => console.warn('Delete temp video failed', e));
            }
            extraVideos.splice(index, 1);
        }
        removeVideoPreviewItem(id);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose API globally for AJAX form submission (form.js / submit.js)
    window.ExtraImagesUpload = {
        init: init,
        getImages: function () { return extraImages; },
        getVideos: function () { return extraVideos; },
        injectData: function () { injectExtraImagesData(); injectExtraVideosData(); }
    };
})();