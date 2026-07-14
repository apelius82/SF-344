/**
 * Extra Images View Module
 * Fetches and displays extra images and videos on the View page (Media tab).
 */
(function () {
    'use strict';

    const MAX_UPLOAD_SIZE_BYTES = 20 * 1024 * 1024;
    const MAX_VIDEO_UPLOAD_SIZE_BYTES = 200 * 1024 * 1024;
    const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
    const ALLOWED_VIDEO_MIME_TYPES = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];
    const ALLOWED_VIDEO_EXTENSIONS = /\.(mp4|webm|ogv|ogg|mov|avi|mkv)$/i;
    const CONCURRENT_UPLOADS = 3;
    let uploadEnhancementsInitialized = false;

    function getTerm(key, fallback) {
        return (window.SF_TERMS && window.SF_TERMS[key]) || fallback;
    }

    function getCsrfToken() {
        return window.SF_CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value || '';
    }

    function isImagesTabActive() {
        const tabContent = document.getElementById('tabImages');
        return !!tabContent && tabContent.classList.contains('active');
    }

    window.initExtraImages = function (flashId, canEdit, mainImages, canAddExtraImages) {
        const grid = document.getElementById('imagesGrid');
        const loading = document.getElementById('imagesLoading');
        const noImages = document.getElementById('noImagesMessage');
        const tabBtn = document.querySelector('.sf-activity-tab[data-tab="images"]');
        const uploadContainer = document.getElementById('imagesUploadContainer');

        if (!grid) return;

        const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

        // Initialize main images array if not provided
        mainImages = mainImages || [];

        // Use canAddExtraImages if provided, otherwise fallback to canEdit for backward compatibility
        canAddExtraImages = (canAddExtraImages !== undefined) ? canAddExtraImages : canEdit;

        // Always initialise video modal so it works for all users
        initVideoModal();

        // Show upload actions immediately for better UX (no need to wait API response)
        if (canAddExtraImages && uploadContainer) {
            uploadContainer.style.display = 'flex';
            initUploadModal(flashId, baseUrl, grid, noImages);
            initVideoUploadModal(flashId, baseUrl, grid, noImages);
        }

        // Fetch extra images from API
        fetch(`${baseUrl}/app/api/get_extra_images.php?id=${flashId}`, {
            credentials: 'same-origin'
        })
            .then(res => res.json())
            .then(data => {
                if (loading) loading.style.display = 'none';

                const extraImages = (data.ok && data.images) ? data.images : [];
                const allImages = [...mainImages, ...extraImages];

                if (allImages.length > 0) {
                    grid.innerHTML = '';
                    grid.style.display = 'grid';

                    // Display main images first (not deletable)
                    mainImages.forEach(img => {
                        const item = createViewItem(img, canEdit, baseUrl, null);
                        grid.appendChild(item);
                    });

                    // Display extra images (deletable if canEdit)
                    extraImages.forEach(img => {
                        const item = createViewItem(img, canEdit, baseUrl, () => {
                            // Check if grid is empty after this item was removed
                            if (grid.querySelectorAll('.sf-gallery-item').length === 0) {
                                grid.style.display = 'none';
                                if (noImages) noImages.style.display = 'block';
                            }
                        });
                        grid.appendChild(item);
                    });

                    if (tabBtn) tabBtn.classList.remove('hidden');
                } else {
                    grid.style.display = 'none';
                    if (noImages) noImages.style.display = 'block';
                }

            })
            .catch(err => {
                console.error('Failed to load extra images:', err);
                const errorMsg = getTerm('images_loading_error', 'Kuvien lataus epäonnistui.');
                if (loading) {
                    loading.style.display = 'none';
                }
                // Still show main images even if extra images fail to load
                if (mainImages.length > 0) {
                    grid.innerHTML = '';
                    grid.style.display = 'grid';
                    mainImages.forEach(img => {
                        const item = createViewItem(img, canEdit, baseUrl, null);
                        grid.appendChild(item);
                    });
                } else {
                    if (noImages) noImages.style.display = 'block';
                }

                if (typeof window.sfToast === 'function') {
                    window.sfToast('error', errorMsg);
                }
            });
    };

    function createViewItem(img, canEdit, baseUrl, onDelete) {
    const isVideo = (img.media_type === 'video');
    const div = document.createElement('div');
    div.className = 'sf-gallery-item is-loading';
    div.setAttribute('aria-busy', 'true');

    const loader = document.createElement('div');
    loader.className = 'sf-gallery-media-loader';
    loader.setAttribute('aria-hidden', 'true');

    const spinner = document.createElement('span');
    spinner.className = 'sf-gallery-media-spinner';
    loader.appendChild(spinner);

    div.appendChild(loader);

    const markLoaded = (mediaEl) => {
        mediaEl.classList.add('is-loaded');
        div.classList.remove('is-loading');
        div.removeAttribute('aria-busy');
    };

    const markError = () => {
        div.classList.remove('is-loading');
        div.classList.add('has-media-error');
        div.removeAttribute('aria-busy');
        loader.innerHTML = '<span class="sf-gallery-media-error-icon" aria-hidden="true">⚠️</span>';
    };

    if (img.isMain) {
        div.classList.add('sf-gallery-item-main');
    }

    if (isVideo) {
        div.classList.add('sf-gallery-item-video');

        const videoEl = document.createElement('video');
        videoEl.preload = 'metadata';
        videoEl.className = 'sf-gallery-img';
        videoEl.muted = true;
        videoEl.playsInline = true;
        videoEl.setAttribute('aria-label', img.original_filename || 'Video');

        videoEl.addEventListener('loadedmetadata', () => markLoaded(videoEl), { once: true });
        videoEl.addEventListener('loadeddata', () => markLoaded(videoEl), { once: true });
        videoEl.addEventListener('error', markError, { once: true });

        videoEl.src = img.url + '#t=0.1';

        div.appendChild(videoEl);

        const playOverlay = document.createElement('div');
        playOverlay.className = 'sf-gallery-play-overlay';
        playOverlay.innerHTML = '<svg viewBox="0 0 24 24" fill="white" width="40" height="40" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';
        div.appendChild(playOverlay);

        div.onclick = () => openVideoModal(img.url, img.original_filename || '');
    } else {
        const imgEl = document.createElement('img');
        imgEl.alt = img.isMain ? 'Main image' : 'Extra image';
        imgEl.className = 'sf-gallery-img';
        imgEl.loading = 'lazy';
        imgEl.decoding = 'async';

        imgEl.__fullUrl = img.url;

        imgEl.onclick = () => openLightbox(img.url);

        imgEl.onload = function () {
            markLoaded(this);
        };

        imgEl.onerror = function () {
            console.error('Failed to load image:', img.url);

            const thumbUrl = img.thumb_url || '';
            const fullUrl = img.url || '';

            if (thumbUrl && fullUrl && this.src === thumbUrl && fullUrl !== thumbUrl) {
                this.src = fullUrl;
                return;
            }

            markError();
        };

        imgEl.src = img.thumb_url || img.url;

        if (imgEl.complete && imgEl.naturalWidth > 0) {
            markLoaded(imgEl);
        }

        div.appendChild(imgEl);
    }

    if (!isVideo) {
        const captionDiv = document.createElement('div');
        captionDiv.className = 'image-caption-display' + (canEdit ? ' editable' : '');
        const currentCaption = img.caption || '';
        captionDiv.textContent = currentCaption || 'Lisää kuvateksti...';

        if (!currentCaption) {
            captionDiv.classList.add('image-caption-placeholder');
        }

        if (canEdit) {
            captionDiv.onclick = (e) => {
                e.stopPropagation();
                showCaptionEditor(captionDiv, img, baseUrl);
            };
        }

        div.appendChild(captionDiv);
    }

    if (canEdit && img.id) {
        const delBtn = document.createElement('button');
        delBtn.className = 'sf-gallery-delete';
        delBtn.innerHTML = '&times;';
        delBtn.setAttribute('aria-label', getTerm('extra_img_remove', 'Poista'));
        delBtn.onclick = (e) => {
            e.stopPropagation();
            showDeleteConfirmModal(() => {
                deleteImage(img.id, div, baseUrl, onDelete);
            });
        };
        div.appendChild(delBtn);
    }

    return div;
}

    function deleteImage(imageId, element, baseUrl, onDelete) {
        const csrfToken = window.SF_CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value;
        fetch(`${baseUrl}/app/api/delete_extra_image.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${imageId}&csrf_token=${encodeURIComponent(csrfToken || '')}`,
            credentials: 'same-origin'
        })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    element.remove();
                    if (onDelete) onDelete();
                    const successMsg = (window.SF_TERMS && window.SF_TERMS['delete_success']) || 'Kuva poistettu';
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', successMsg);
                    }
                } else {
                    const errorMsg = (window.SF_TERMS && window.SF_TERMS['delete_error']) || 'Virhe poistettaessa';
                    const fullMsg = errorMsg + ': ' + (data.error || (window.SF_TERMS && window.SF_TERMS['unknown_error']) || 'Tuntematon virhe');
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('error', fullMsg);
                    } else {
                        alert(fullMsg);
                    }
                }
            })
            .catch(err => {
                const errorMsg = (window.SF_TERMS && window.SF_TERMS['delete_error']) || 'Virhe poistettaessa.';
                if (typeof window.sfToast === 'function') {
                    window.sfToast('error', errorMsg);
                } else {
                    alert(errorMsg);
                }
                console.error(err);
            });
    }

    function showCaptionEditor(captionDiv, img, baseUrl) {
        const currentCaption = img.caption || '';
        const parent = captionDiv.parentNode;

        // Flag to prevent duplicate saves
        let isSaving = false;

        // Create input field
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'image-caption-input';
        input.value = currentCaption;
        input.maxLength = 500;
        input.placeholder = 'Lisää kuvateksti...';

        // Save caption function
        const saveCaption = () => {
            // Prevent duplicate saves
            if (isSaving) {
                return;
            }
            isSaving = true;

            const newCaption = input.value.trim();

            // Determine image type
            let imageType = 'extra';
            let imageId = img.id || 0;
            let flashId = img.flash_id || 0;

            if (img.isMain) {
                // Main images: determine which slot (1, 2, or 3)
                imageType = img.imageType || 'main1';
                flashId = img.flash_id || 0;
            }

            // Get CSRF token
            const csrfToken = window.SF_CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value;

            // Validate CSRF token exists
            if (!csrfToken) {
                console.error('CSRF token not found');
                if (typeof window.sfToast === 'function') {
                    window.sfToast('error', 'Turvavirhe: yritä päivittää sivu');
                } else {
                    alert('Turvavirhe: yritä päivittää sivu');
                }
                // Restore original caption
                captionDiv.textContent = currentCaption || 'Lisää kuvateksti...';
                captionDiv.classList.toggle('image-caption-placeholder', !currentCaption);
                if (input.parentNode === parent) {
                    parent.replaceChild(captionDiv, input);
                }
                isSaving = false;
                return;
            }

            // Call API to save caption
            const formData = new URLSearchParams();
            formData.append('flash_id', flashId.toString());
            formData.append('image_type', imageType);
            if (imageType === 'extra') {
                formData.append('image_id', imageId.toString());
            }
            formData.append('caption', newCaption);
            formData.append('csrf_token', csrfToken);

            fetch(`${baseUrl}/app/api/update_image_caption.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString(),
                credentials: 'same-origin'
            })
                .then(res => res.json())
                .then(data => {
                    if (data.ok) {
                        // Update the caption display
                        img.caption = newCaption;
                        captionDiv.textContent = newCaption || 'Lisää kuvateksti...';
                        captionDiv.classList.toggle('image-caption-placeholder', !newCaption);

                        if (typeof window.sfToast === 'function') {
                            window.sfToast('success', 'Kuvateksti tallennettu');
                        }
                    } else {
                        const errorMsg = 'Virhe tallennettaessa: ' + (data.error || 'Tuntematon virhe');
                        if (typeof window.sfToast === 'function') {
                            window.sfToast('error', errorMsg);
                        } else {
                            alert(errorMsg);
                        }
                        // Restore original caption
                        captionDiv.textContent = currentCaption || 'Lisää kuvateksti...';
                        captionDiv.classList.toggle('image-caption-placeholder', !currentCaption);
                    }
                    // Replace input with caption display after save completes
                    if (input.parentNode === parent) {
                        parent.replaceChild(captionDiv, input);
                    }
                    isSaving = false;
                })
                .catch(err => {
                    console.error('Error saving caption:', err);
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('error', 'Virhe tallennettaessa kuvatekstiä');
                    } else {
                        alert('Virhe tallennettaessa kuvatekstiä');
                    }
                    // Restore original caption
                    captionDiv.textContent = currentCaption || 'Lisää kuvateksti...';
                    captionDiv.classList.toggle('image-caption-placeholder', !currentCaption);
                    // Replace input with caption display after error
                    if (input.parentNode === parent) {
                        parent.replaceChild(captionDiv, input);
                    }
                    isSaving = false;
                });
        };

        // Replace caption display with input
        parent.replaceChild(input, captionDiv);
        input.focus();
        input.select();

        // Flag to track if we should save or cancel
        let shouldSave = true;

        // Handle blur (save on unfocus)
        input.onblur = () => {
            if (shouldSave && !isSaving) {
                saveCaption();
            }
        };

        // Handle Enter key (save directly)
        input.onkeydown = (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Disable blur save to prevent duplicate
                shouldSave = false;
                saveCaption();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                // Cancel editing - don't save on blur
                shouldSave = false;
                captionDiv.textContent = currentCaption || 'Lisää kuvateksti...';
                captionDiv.classList.toggle('image-caption-placeholder', !currentCaption);
                if (input.parentNode === parent) {
                    parent.replaceChild(captionDiv, input);
                }
            }
        };
    }

    // Track all images for navigation
    let allImageUrls = [];
    let currentImageIndex = 0;
    let keyboardListenerAdded = false;

    // Delete confirmation modal singleton
    let deleteConfirmModal = null;
    let deleteConfirmCallback = null;

    function createDeleteConfirmModal() {
        const modal = document.createElement('div');
        modal.className = 'sf-modal hidden';
        modal.id = 'modalDeleteExtraImage';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'modalDeleteExtraImageTitle');

        const cancelBtnText = (window.SF_TERMS && window.SF_TERMS['btn_cancel']) || 'Peruuta';
        const deleteBtnText = (window.SF_TERMS && window.SF_TERMS['btn_delete']) || 'Poista';
        const titleText = (window.SF_TERMS && window.SF_TERMS['extra_img_delete_confirm']) || 'Haluatko varmasti poistaa tämän kuvan?';

        modal.innerHTML = `
            <div class="sf-modal-content">
                <h2 id="modalDeleteExtraImageTitle">${titleText}</h2>
                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" data-action="cancel">
                        ${cancelBtnText}
                    </button>
                    <button type="button" class="sf-btn sf-btn-danger" data-action="confirm">
                        ${deleteBtnText}
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Close on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeDeleteConfirmModal();
            }
        });

        // Setup button event listeners once
        const cancelBtn = modal.querySelector('[data-action="cancel"]');
        const confirmBtn = modal.querySelector('[data-action="confirm"]');

        cancelBtn.addEventListener('click', () => {
            closeDeleteConfirmModal();
        });

        confirmBtn.addEventListener('click', () => {
            const callback = deleteConfirmCallback;  // Tallenna viite ENSIN
            closeDeleteConfirmModal();               // Sitten sulje (joka nollaa deleteConfirmCallback)
            if (callback) {
                callback();                          // Suorita tallennettu callback
            }
        });

        return modal;
    }

    function showDeleteConfirmModal(onConfirm) {
        if (!deleteConfirmModal) {
            deleteConfirmModal = createDeleteConfirmModal();
        }

        deleteConfirmCallback = onConfirm;
        deleteConfirmModal.classList.remove('hidden');
    }

    function closeDeleteConfirmModal() {
        if (deleteConfirmModal) {
            deleteConfirmModal.classList.add('hidden');
            deleteConfirmCallback = null;
        }
    }

    function openLightbox(url) {
        // Collect only image (not video) URLs for navigation
        const gridImgs = document.querySelectorAll('#imagesGrid .sf-gallery-item:not(.sf-gallery-item-video) .sf-gallery-img');
        allImageUrls = [];
        gridImgs.forEach(img => {
            allImageUrls.push(img.__fullUrl || img.src);
        });
        currentImageIndex = allImageUrls.indexOf(url);
        if (currentImageIndex === -1) currentImageIndex = 0;

        let lightbox = document.getElementById('sf-lightbox');
        if (!lightbox) {
            lightbox = document.createElement('div');
            lightbox.id = 'sf-lightbox';
            lightbox.className = 'sf-lightbox';
            lightbox.innerHTML = `
                <button class="sf-lightbox-close" aria-label="Close">&times;</button>
                <button class="sf-lightbox-nav sf-lightbox-prev" aria-label="Previous">&#8249;</button>
                <button class="sf-lightbox-nav sf-lightbox-next" aria-label="Next">&#8250;</button>
                <div class="sf-lightbox-content">
                    <img class="sf-lightbox-img" src="" alt="">
                    <div class="sf-lightbox-caption"></div>
                    <div class="sf-lightbox-counter"></div>
                </div>
            `;
            document.body.appendChild(lightbox);

            // Close on backdrop click
            lightbox.addEventListener('click', (e) => {
                if (e.target === lightbox) closeLightbox();
            });

            // Close button
            lightbox.querySelector('.sf-lightbox-close').addEventListener('click', closeLightbox);

            // Navigation
            lightbox.querySelector('.sf-lightbox-prev').addEventListener('click', (e) => {
                e.stopPropagation();
                navigateLightbox(-1);
            });
            lightbox.querySelector('.sf-lightbox-next').addEventListener('click', (e) => {
                e.stopPropagation();
                navigateLightbox(1);
            });

            // Swipe support for mobile
            let touchStartX = 0;
            lightbox.addEventListener('touchstart', (e) => {
                touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });
            lightbox.addEventListener('touchend', (e) => {
                const touchEndX = e.changedTouches[0].screenX;
                const diff = touchStartX - touchEndX;
                if (Math.abs(diff) > 50) {
                    navigateLightbox(diff > 0 ? 1 : -1);
                }
            }, { passive: true });
        }

        // Keyboard navigation - add listener only once
        if (!keyboardListenerAdded) {
            document.addEventListener('keydown', (e) => {
                const lightbox = document.getElementById('sf-lightbox');
                if (!lightbox || !lightbox.classList.contains('active')) return;
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowLeft') navigateLightbox(-1);
                if (e.key === 'ArrowRight') navigateLightbox(1);
            });
            keyboardListenerAdded = true;
        }

        updateLightboxContent(lightbox, url);
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        const lightbox = document.getElementById('sf-lightbox');
        if (lightbox) {
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    // ---------------------------------------------------------------------------
    // Video Modal
    // ---------------------------------------------------------------------------

    let videoModalKeyListenerAdded = false;

    function openVideoModal(url, title) {
        const modal = document.getElementById('sfVideoModal');
        const player = document.getElementById('sfVideoModalPlayer');
        const source = document.getElementById('sfVideoModalSource');

        if (!modal || !player || !source) return;

        // Detect MIME type from extension
        const ext = (url.split('?')[0].split('.').pop() || '').toLowerCase();
        const mimeMap = { mp4: 'video/mp4', webm: 'video/webm', ogv: 'video/ogg', ogg: 'video/ogg', mov: 'video/quicktime', avi: 'video/x-msvideo', mkv: 'video/x-matroska' };
        source.type = mimeMap[ext] || 'video/mp4';
        source.src = url;
        player.load();

        modal.showModal();
        player.focus();

        if (!videoModalKeyListenerAdded) {
            videoModalKeyListenerAdded = true;
            document.addEventListener('keydown', (e) => {
                const m = document.getElementById('sfVideoModal');
                if (m && m.open && e.key === 'Escape') {
                    closeVideoModal();
                }
            });
        }
    }

    function closeVideoModal() {
        const modal = document.getElementById('sfVideoModal');
        const player = document.getElementById('sfVideoModalPlayer');
        const source = document.getElementById('sfVideoModalSource');

        if (!modal) return;
        if (player) {
            player.pause();
            player.currentTime = 0;
        }
        if (source) {
            source.src = '';
        }
        if (player) {
            player.load();
        }
        if (modal.open) {
            modal.close();
        }
    }

    function initVideoModal() {
        const modal = document.getElementById('sfVideoModal');
        const closeBtn = document.getElementById('sfVideoModalClose');
        if (!modal) return;
        if (modal.dataset.sfVideoModalInit === '1') return;
        modal.dataset.sfVideoModalInit = '1';

        if (closeBtn) {
            closeBtn.addEventListener('click', closeVideoModal);
        }

        // Close on backdrop click (clicking outside the inner dialog content)
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeVideoModal();
            }
        });
    }

    function navigateLightbox(direction) {
        if (allImageUrls.length <= 1) return;
        currentImageIndex += direction;
        if (currentImageIndex < 0) currentImageIndex = allImageUrls.length - 1;
        if (currentImageIndex >= allImageUrls.length) currentImageIndex = 0;

        const lightbox = document.getElementById('sf-lightbox');
        if (lightbox) {
            updateLightboxContent(lightbox, allImageUrls[currentImageIndex]);
        }
    }

    function updateLightboxContent(lightbox, url) {
        const img = lightbox.querySelector('.sf-lightbox-img');
        const caption = lightbox.querySelector('.sf-lightbox-caption');
        const counter = lightbox.querySelector('.sf-lightbox-counter');
        const prevBtn = lightbox.querySelector('.sf-lightbox-prev');
        const nextBtn = lightbox.querySelector('.sf-lightbox-next');

        img.src = url;

        // Show filename as caption (extract from URL, handle query params)
        let filename = '';
        try {
            // Try using URL API for robust parsing
            const urlObj = new URL(url, window.location.href);
            filename = urlObj.pathname.split('/').pop() || '';
        } catch (e) {
            // Fallback to simple parsing if URL API fails
            const urlPath = url.split('?')[0].split('#')[0];
            filename = urlPath.split('/').pop() || '';
        }
        if (caption) caption.textContent = decodeURIComponent(filename);

        // Show counter
        if (counter && allImageUrls.length > 1) {
            counter.textContent = (currentImageIndex + 1) + ' / ' + allImageUrls.length;
            counter.style.display = '';
        } else if (counter) {
            counter.style.display = 'none';
        }

        // Show/hide nav buttons
        if (prevBtn) prevBtn.style.display = allImageUrls.length > 1 ? '' : 'none';
        if (nextBtn) nextBtn.style.display = allImageUrls.length > 1 ? '' : 'none';
    }

    function initUploadModal(flashId, baseUrl, grid, noImages) {
        const uploadBtn = document.getElementById('imagesUploadBtn');
        const modal = document.getElementById('uploadModal');
        const modalClose = document.getElementById('uploadModalClose');
        const backdrop = modal ? modal.querySelector('.sf-modal-backdrop') : null;
        const dropZone = document.getElementById('uploadDropZone');
        const browseBtn = document.getElementById('uploadBrowseBtn');
        const cameraBtn = document.getElementById('uploadCameraBtn');
        const fileInput = document.getElementById('uploadFileInput');
        const cameraInput = document.getElementById('uploadCameraInput');
        const tabContent = document.getElementById('tabImages');

        if (!uploadBtn || !modal || !dropZone || !browseBtn || !fileInput) return;
        if (modal.dataset.sfUploadInit === '1') return;
        modal.dataset.sfUploadInit = '1';

        // Open modal when upload button is clicked
        uploadBtn.onclick = () => {
            modal.classList.remove('hidden');
        };

        // Close modal
        const closeModal = () => {
            modal.classList.add('hidden');
            fileInput.value = '';
        };

        if (modalClose) modalClose.onclick = closeModal;
        if (backdrop) backdrop.onclick = closeModal;

        // Browse button
        browseBtn.onclick = () => fileInput.click();
        if (cameraBtn && cameraInput) {
            cameraBtn.onclick = () => cameraInput.click();
        }

        // File input change
        fileInput.onchange = (e) => {
            const files = Array.from(e.target.files || []);
            if (files.length === 0) return;

            handleFiles(files, flashId, baseUrl, grid, noImages, modal);
            fileInput.value = '';
        };
        if (cameraInput) {
            cameraInput.onchange = (e) => {
                const files = Array.from(e.target.files || []);
                if (files.length === 0) return;
                handleFiles(files, flashId, baseUrl, grid, noImages, modal);
                cameraInput.value = '';
            };
        }

        // Drag and drop
        dropZone.ondragover = (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        };

        dropZone.ondragleave = (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
        };

        dropZone.ondrop = (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');

            const files = Array.from(e.dataTransfer.files || []);
            if (files.length === 0) {
                showUploadError(getTerm('select_image_files', 'Valitse kuvatiedostoja'));
                return;
            }

            handleFiles(files, flashId, baseUrl, grid, noImages, modal);
        };

        if (!uploadEnhancementsInitialized && tabContent) {
            uploadEnhancementsInitialized = true;
            initUploadEnhancements(tabContent, grid, flashId, baseUrl, noImages);
        }
    }

    function initVideoUploadModal(flashId, baseUrl, grid, noImages) {
        const videoBtn = document.getElementById('imagesUploadVideoBtn');
        const modal = document.getElementById('videoUploadModal');
        const modalClose = document.getElementById('videoUploadModalClose');
        const backdrop = modal ? modal.querySelector('.sf-modal-backdrop') : null;
        const dropZone = document.getElementById('videoUploadDropZone');
        const browseBtn = document.getElementById('videoUploadBrowseBtn');
        const fileInput = document.getElementById('videoUploadFileInput');

        if (!videoBtn || !modal || !dropZone || !browseBtn || !fileInput) return;
        if (modal.dataset.sfVideoUploadInit === '1') return;
        modal.dataset.sfVideoUploadInit = '1';

        videoBtn.onclick = () => { modal.classList.remove('hidden'); };

        const closeModal = () => {
            modal.classList.add('hidden');
            fileInput.value = '';
        };

        if (modalClose) modalClose.onclick = closeModal;
        if (backdrop) backdrop.onclick = closeModal;

        browseBtn.onclick = () => fileInput.click();

        fileInput.onchange = (e) => {
            const files = Array.from(e.target.files || []);
            if (files.length === 0) return;
            handleVideoFiles(files, flashId, baseUrl, grid, noImages, modal);
            fileInput.value = '';
        };

        // Drag and drop for videos
        dropZone.ondragover = (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        };
        dropZone.ondragleave = (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
        };
        dropZone.ondrop = (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            const files = Array.from(e.dataTransfer.files || []);
            if (files.length === 0) return;
            handleVideoFiles(files, flashId, baseUrl, grid, noImages, modal);
        };
    }

    function initUploadEnhancements(tabContent, grid, flashId, baseUrl, noImages) {
        initGlobalDropTarget(tabContent, grid, flashId, baseUrl, noImages);

        document.addEventListener('paste', (e) => {
            if (!isImagesTabActive()) return;
            const clipboardItems = Array.from((e.clipboardData && e.clipboardData.items) || []);
            const files = clipboardItems
                .filter(item => item.kind === 'file')
                .map(item => item.getAsFile())
                .filter(Boolean);

            if (files.length === 0) return;
            e.preventDefault();
            handleFiles(files, flashId, baseUrl, grid, noImages, null);
        });
    }

    function initGlobalDropTarget(tabContent, grid, flashId, baseUrl, noImages) {
        const overlay = document.getElementById('imagesDropOverlay');
        const targets = [tabContent, grid].filter(Boolean);
        let dragDepth = 0;

        const hasFiles = (event) => {
            const types = event.dataTransfer && event.dataTransfer.types;
            return !!types && Array.from(types).includes('Files');
        };

        const showOverlay = () => {
            if (overlay) overlay.classList.add('active');
        };

        const hideOverlay = () => {
            dragDepth = 0;
            if (overlay) overlay.classList.remove('active');
        };

        targets.forEach((target) => {
            target.addEventListener('dragenter', (e) => {
                if (!hasFiles(e)) return;
                e.preventDefault();
                dragDepth += 1;
                showOverlay();
            });

            target.addEventListener('dragover', (e) => {
                if (!hasFiles(e)) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                showOverlay();
            });

            target.addEventListener('dragleave', (e) => {
                if (!hasFiles(e)) return;
                e.preventDefault();
                dragDepth = Math.max(0, dragDepth - 1);
                if (dragDepth === 0) {
                    hideOverlay();
                }
            });

            target.addEventListener('drop', (e) => {
                if (!hasFiles(e)) return;
                e.preventDefault();
                hideOverlay();
                const files = Array.from(e.dataTransfer.files || []);
                handleFiles(files, flashId, baseUrl, grid, noImages, null);
            });
        });

        document.addEventListener('dragend', hideOverlay);
    }

    function isAllowedImageFile(file) {
        if (ALLOWED_MIME_TYPES.includes(file.type)) return true;
        const name = (file.name || '').toLowerCase();
        return /\.(jpe?g|png|gif|webp|heic|heif)$/i.test(name);
    }

    function showUploadError(message) {
        if (typeof window.sfToast === 'function') {
            window.sfToast('error', message);
            return;
        }
        alert(message);
    }

    function updatePendingProgress(pendingItem, progressPercent) {
        if (!pendingItem || !pendingItem.progressBar) return;
        pendingItem.progressBar.style.width = `${progressPercent}%`;
        pendingItem.progressText.textContent = `${progressPercent}%`;
    }

    function createPendingItem(file, grid, noImages) {
        const objectUrl = URL.createObjectURL(file);
        const pendingDiv = document.createElement('div');
        pendingDiv.className = 'sf-gallery-item sf-gallery-item-pending';

        const pendingImg = document.createElement('img');
        pendingImg.className = 'sf-gallery-img';
        pendingImg.src = (typeof objectUrl === 'string' && objectUrl.startsWith('blob:')) ? objectUrl : '';
        pendingImg.alt = file.name || 'Upload preview';

        const overlay = document.createElement('div');
        overlay.className = 'sf-gallery-pending-overlay';

        const status = document.createElement('div');
        status.className = 'sf-gallery-pending-status';
        status.setAttribute('role', 'status');
        status.textContent = getTerm('extra_img_processing', 'Prosessoidaan...');

        const progressBar = document.createElement('div');
        progressBar.className = 'sf-gallery-progress-line';
        const progressFill = document.createElement('div');
        progressFill.className = 'sf-gallery-progress-line-fill';
        progressFill.style.width = '0%';
        progressBar.appendChild(progressFill);

        const progressText = document.createElement('div');
        progressText.className = 'sf-gallery-pending-progress-text';
        progressText.textContent = '0%';

        overlay.appendChild(status);
        overlay.appendChild(progressBar);
        overlay.appendChild(progressText);
        pendingDiv.appendChild(pendingImg);
        pendingDiv.appendChild(overlay);

        grid.appendChild(pendingDiv);
        grid.style.display = 'grid';
        if (noImages) noImages.style.display = 'none';

        return {
            element: pendingDiv,
            objectUrl,
            status,
            progressBar: progressFill,
            progressText
        };
    }

    function destroyPendingItem(pendingItem) {
        if (!pendingItem) return;
        if (pendingItem.element && pendingItem.element.parentNode) {
            pendingItem.element.remove();
        }
        if (pendingItem.objectUrl) {
            URL.revokeObjectURL(pendingItem.objectUrl);
        }
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    function isTransientStatus(status) {
        return status >= 500 && status < 600;
    }

    async function fetchWithRetry(url, options, pendingItem) {
        try {
            const firstResponse = await fetch(url, options);
            if (!isTransientStatus(firstResponse.status)) {
                return firstResponse;
            }
            if (pendingItem && pendingItem.status) {
                pendingItem.status.textContent = getTerm('upload_retrying', 'Yritetään uudelleen...');
            }
            await sleep(2000);
            return fetch(url, options);
        } catch (error) {
            if (pendingItem && pendingItem.status) {
                pendingItem.status.textContent = getTerm('upload_retrying', 'Yritetään uudelleen...');
            }
            await sleep(2000);
            return fetch(url, options);
        }
    }

    function uploadTempWithXhr(file, baseUrl, pendingItem) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('image', file);
            const csrfToken = getCsrfToken();
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', `${baseUrl}/app/api/upload_extra_image.php`, true);
            xhr.withCredentials = true;
            xhr.responseType = 'json';

            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                updatePendingProgress(pendingItem, percent);
            };

            xhr.onload = () => {
                const responseData = xhr.response || {};
                if (xhr.status >= 200 && xhr.status < 300 && responseData && responseData.ok) {
                    resolve(responseData);
                    return;
                }
                const error = new Error((responseData && responseData.error) || getTerm('upload_error', 'Lataus epäonnistui'));
                error.status = xhr.status;
                reject(error);
            };

            xhr.onerror = () => {
                const error = new Error(getTerm('upload_error', 'Lataus epäonnistui'));
                error.status = 0;
                reject(error);
            };

            xhr.send(formData);
        });
    }

    async function uploadTempWithRetry(file, baseUrl, pendingItem) {
        try {
            return await uploadTempWithXhr(file, baseUrl, pendingItem);
        } catch (error) {
            if (!error || (error.status && !isTransientStatus(error.status))) {
                throw error;
            }
            if (pendingItem && pendingItem.status) {
                pendingItem.status.textContent = getTerm('upload_retrying', 'Yritetään uudelleen...');
            }
            await sleep(2000);
            return uploadTempWithXhr(file, baseUrl, pendingItem);
        }
    }

    async function handleFiles(files, flashId, baseUrl, grid, noImages, modal) {
        const validFiles = [];

        files.forEach((file) => {
            if (!isAllowedImageFile(file)) {
                showUploadError(getTerm('extra_img_invalid_type', 'Virheellinen tiedostomuoto. Sallitut: JPEG, PNG, GIF, WEBP, HEIC, HEIF'));
                return;
            }
            if (file.size > MAX_UPLOAD_SIZE_BYTES) {
                showUploadError(getTerm('extra_img_too_large', 'Tiedosto on liian suuri. Maksimikoko: 20MB'));
                return;
            }
            validFiles.push(file);
        });

        if (validFiles.length === 0) {
            return;
        }

        const progress = document.getElementById('uploadProgress');
        const progressFill = document.getElementById('uploadProgressFill');
        const progressText = document.getElementById('uploadProgressText');

        let completed = 0;
        const total = validFiles.length;
        let failedCount = 0;
        let cursor = 0;

        if (progress) {
            progress.classList.add('active');
            updateProgress(0, total, progressFill, progressText);
        }

        const workers = Array.from({ length: Math.min(CONCURRENT_UPLOADS, total) }, () => (async () => {
            while (cursor < total) {
                const file = validFiles[cursor++];
                const pendingItem = createPendingItem(file, grid, noImages);
                try {
                    await uploadImage(file, flashId, baseUrl, grid, noImages, pendingItem);
                } catch (error) {
                    failedCount++;
                    destroyPendingItem(pendingItem);
                } finally {
                    completed++;
                    updateProgress(completed, total, progressFill, progressText);
                }
            }
        })());

        await Promise.allSettled(workers);

        if (failedCount > 0) {
            const summaryTemplate = getTerm('images_upload_partial', '{failed} tiedostoa epäonnistui');
            const summaryMessage = summaryTemplate
                .replace('{failed}', String(failedCount))
                .replace('{total}', String(total));
            showUploadError(summaryMessage);
        }

        setTimeout(() => {
            if (progress) progress.classList.remove('active');
            if (modal) modal.classList.add('hidden');
        }, 1000);
    }

    function updateProgress(completed, total, progressFill, progressText) {
        const percent = Math.round((completed / total) * 100);
        if (progressFill) {
            progressFill.style.width = percent + '%';
            progressFill.textContent = percent + '%';
        }
        if (progressText) {
            const template = (window.SF_TERMS && window.SF_TERMS['images_uploading']) || '{count} / {total} kuvaa ladattu';
            const text = template.replace('{count}', completed).replace('{total}', total);
            progressText.textContent = text;
        }
    }

    async function uploadImage(file, flashId, baseUrl, grid, noImages, pendingItem) {
        if (pendingItem && pendingItem.status) {
            pendingItem.status.textContent = getTerm('extra_img_processing', 'Prosessoidaan...');
        }

        const uploadResult = await uploadTempWithRetry(file, baseUrl, pendingItem);
        updatePendingProgress(pendingItem, 100);

        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            throw new Error(getTerm('csrf_invalid_token', 'Virheellinen CSRF token'));
        }

        const addData = new URLSearchParams();
        addData.append('flash_id', flashId);
        addData.append('temp_filename', uploadResult.filename);
        addData.append('original_filename', uploadResult.original_filename);
        addData.append('csrf_token', csrfToken);

        const addResponse = await fetchWithRetry(`${baseUrl}/app/api/add_extra_image.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: addData.toString(),
            credentials: 'same-origin'
        }, pendingItem);
        const addResult = await addResponse.json();

        if (!addResult.ok) {
            throw new Error(addResult.error || 'Failed to save image');
        }

        const newImage = {
            id: addResult.id,
            flash_id: flashId,
            url: addResult.url,
            thumb_url: addResult.thumb_url,
            filename: addResult.filename,
            original_filename: addResult.original_filename,
            caption: ''
        };

        const item = createViewItem(newImage, true, baseUrl, () => {
            if (grid.querySelectorAll('.sf-gallery-item').length === 0) {
                grid.style.display = 'none';
                if (noImages) noImages.style.display = 'block';
            }
        });

        if (pendingItem && pendingItem.element && pendingItem.element.parentNode) {
            pendingItem.element.replaceWith(item);
            if (pendingItem.objectUrl) {
                URL.revokeObjectURL(pendingItem.objectUrl);
            }
        } else {
            grid.appendChild(item);
        }
    }

    // ---------------------------------------------------------------------------
    // Video upload helpers
    // ---------------------------------------------------------------------------

    function isAllowedVideoFile(file) {
        const type = String(file.type || '').toLowerCase();
        if (ALLOWED_VIDEO_MIME_TYPES.includes(type)) return true;
        const name = String(file.name || '').toLowerCase();
        return ALLOWED_VIDEO_EXTENSIONS.test(name);
    }

    function createVideoPendingItem(file, grid, noImages) {
        const pendingDiv = document.createElement('div');
        pendingDiv.className = 'sf-gallery-item sf-gallery-item-pending sf-gallery-item-video';

        const iconDiv = document.createElement('div');
        iconDiv.className = 'sf-gallery-video-placeholder';
        iconDiv.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" width="48" height="48" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';
        pendingDiv.appendChild(iconDiv);

        const overlay = document.createElement('div');
        overlay.className = 'sf-gallery-pending-overlay';

        const status = document.createElement('div');
        status.className = 'sf-gallery-pending-status';
        status.setAttribute('role', 'status');
        status.textContent = getTerm('extra_img_processing', 'Ladataan...');

        const progressBar = document.createElement('div');
        progressBar.className = 'sf-gallery-progress-line';
        const progressFill = document.createElement('div');
        progressFill.className = 'sf-gallery-progress-line-fill';
        progressFill.style.width = '0%';
        progressBar.appendChild(progressFill);

        const progressText = document.createElement('div');
        progressText.className = 'sf-gallery-pending-progress-text';
        progressText.textContent = '0%';

        overlay.appendChild(status);
        overlay.appendChild(progressBar);
        overlay.appendChild(progressText);
        pendingDiv.appendChild(overlay);

        grid.appendChild(pendingDiv);
        grid.style.display = 'grid';
        if (noImages) noImages.style.display = 'none';

        return { element: pendingDiv, status, progressBar: progressFill, progressText };
    }

    async function handleVideoFiles(files, flashId, baseUrl, grid, noImages, modal) {
        const validFiles = [];

        files.forEach((file) => {
            if (!isAllowedVideoFile(file)) {
                showUploadError(getTerm('video_upload_invalid_type', 'Virheellinen videomuoto. Sallitut: MP4, WebM, OGG, MOV'));
                return;
            }
            if (file.size > MAX_VIDEO_UPLOAD_SIZE_BYTES) {
                showUploadError(getTerm('video_upload_too_large', 'Videotiedosto on liian suuri. Maksimikoko: 200 Mt'));
                return;
            }
            validFiles.push(file);
        });

        if (validFiles.length === 0) return;

        const progress = document.getElementById('videoUploadProgress');
        const progressFill = document.getElementById('videoUploadProgressFill');
        const progressText = document.getElementById('videoUploadProgressText');

        let completed = 0;
        const total = validFiles.length;
        let failedCount = 0;
        let cursor = 0;

        if (progress) {
            progress.classList.add('active');
            updateProgress(0, total, progressFill, progressText);
        }

        // Upload videos one by one (no concurrent uploads for large files)
        while (cursor < total) {
            const file = validFiles[cursor++];
            const pendingItem = createVideoPendingItem(file, grid, noImages);
            try {
                await uploadVideo(file, flashId, baseUrl, grid, noImages, pendingItem);
            } catch (err) {
                failedCount++;
                if (pendingItem.element && pendingItem.element.parentNode) {
                    pendingItem.element.remove();
                }
            } finally {
                completed++;
                updateProgress(completed, total, progressFill, progressText);
            }
        }

        if (failedCount > 0) {
            showUploadError(getTerm('upload_error', 'Videon lataus epäonnistui'));
        }

        setTimeout(() => {
            if (progress) progress.classList.remove('active');
            if (modal) modal.classList.add('hidden');
        }, 1000);
    }

    function uploadVideoTempWithXhr(file, baseUrl, pendingItem) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('video', file);
            const csrfToken = getCsrfToken();
            if (csrfToken) formData.append('csrf_token', csrfToken);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', `${baseUrl}/app/api/upload_extra_video.php`, true);
            xhr.withCredentials = true;
            xhr.responseType = 'json';

            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                updatePendingProgress(pendingItem, percent);
            };

            xhr.onload = () => {
                const responseData = xhr.response || {};
                if (xhr.status >= 200 && xhr.status < 300 && responseData && responseData.ok) {
                    resolve(responseData);
                    return;
                }
                const error = new Error((responseData && responseData.error) || getTerm('upload_error', 'Lataus epäonnistui'));
                error.status = xhr.status;
                reject(error);
            };

            xhr.onerror = () => {
                const error = new Error(getTerm('upload_error', 'Lataus epäonnistui'));
                error.status = 0;
                reject(error);
            };

            xhr.send(formData);
        });
    }

    async function uploadVideo(file, flashId, baseUrl, grid, noImages, pendingItem) {
        if (pendingItem && pendingItem.status) {
            pendingItem.status.textContent = getTerm('extra_img_processing', 'Ladataan...');
        }

        const uploadResult = await uploadVideoTempWithXhr(file, baseUrl, pendingItem);
        updatePendingProgress(pendingItem, 100);

        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            throw new Error(getTerm('csrf_invalid_token', 'Virheellinen CSRF token'));
        }

        const addData = new URLSearchParams();
        addData.append('flash_id', flashId);
        addData.append('temp_filename', uploadResult.filename);
        addData.append('original_filename', uploadResult.original_filename || file.name);
        addData.append('csrf_token', csrfToken);

        const addResponse = await fetch(`${baseUrl}/app/api/add_extra_video.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: addData.toString(),
            credentials: 'same-origin'
        });
        const addResult = await addResponse.json();

        if (!addResult.ok) {
            throw new Error(addResult.error || 'Failed to save video');
        }

        const newVideo = {
            id: addResult.id,
            url: addResult.url,
            thumb_url: null,
            filename: addResult.filename,
            original_filename: addResult.original_filename,
            media_type: 'video'
        };

        const item = createViewItem(newVideo, true, baseUrl, () => {
            if (grid.querySelectorAll('.sf-gallery-item').length === 0) {
                grid.style.display = 'none';
                if (noImages) noImages.style.display = 'block';
            }
        });

        if (pendingItem && pendingItem.element && pendingItem.element.parentNode) {
            pendingItem.element.replaceWith(item);
        } else {
            grid.appendChild(item);
        }
    }
})();