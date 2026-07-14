/**
 * Safetyflash - View-sivun yleiset toiminnot
 * Modal helpers, log toggles, avatars, footer buttons
 */
(function () {
    'use strict';

    // Utilities
    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }
    const modalControllers = {};
    let bottomSheetObserver = null;

    function ensureBottomSheetHandle(modal) {
        if (!modal) return;
        var content = modal.querySelector('.sf-modal-content, .sf-library-modal-content');
        if (!content) return;
        if (!content.querySelector('[data-sf-bottom-sheet-handle]')) {
            var handle = document.createElement('div');
            handle.className = 'sf-bottom-sheet-handle';
            handle.setAttribute('data-sf-bottom-sheet-handle', 'true');
            handle.setAttribute('aria-hidden', 'true');
            content.insertBefore(handle, content.firstChild);
        }
        content.classList.add('sf-mobile-sheet-content');
    }

    function getBottomSheetModals(ctx) {
        return qsa('.sf-modal[data-bottom-sheet], .sf-library-modal[data-bottom-sheet]', ctx);
    }

    function initBottomSheetModal(modal) {
        if (!modal || !modal.id || modalControllers[modal.id] || !window.SFBottomSheetController) return;
        var content = modal.querySelector('.sf-modal-content, .sf-library-modal-content');
        if (!content) return;
        ensureBottomSheetHandle(modal);
        modalControllers[modal.id] = new window.SFBottomSheetController(modal, {
            isVisible: function () {
                return !modal.classList.contains('hidden');
            },
            onDismiss: function () {
                closeModal(modal.id);
            }
        });
    }

    function initBottomSheetModals(ctx) {
        if (!window.SFBottomSheetController) return;
        getBottomSheetModals(ctx).forEach(function (modal) {
            initBottomSheetModal(modal);
        });
    }

    function observeBottomSheetModals() {
        if (bottomSheetObserver || !window.MutationObserver) return;
        var modalHost = document.getElementById('sfContainer') || document.body;
        if (!modalHost) return;
        bottomSheetObserver = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (!node || node.nodeType !== 1) return;
                    if (node.matches && node.matches('.sf-modal[data-bottom-sheet], .sf-library-modal[data-bottom-sheet]')) {
                        initBottomSheetModal(node);
                    }
                    initBottomSheetModals(node);
                });
            });
        });
        bottomSheetObserver.observe(modalHost, { childList: true, subtree: true });
    }

    // Modal helpers
    function openModal(id) {
        var el = document.getElementById(id);
        if (!el) return;

        if (typeof window.sfOpenModal === 'function') {
            window.sfOpenModal(el);
            return;
        }

        el.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');

        var focusable = el.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable) focusable.focus();
    }

    function closeModal(id) {
        var el = document.getElementById(id);
        if (!el) return;

        if (typeof window.sfCloseModal === 'function') {
            window.sfCloseModal(el);
            return;
        }

        el.classList.add('hidden');

        var anyOpen = document.querySelector('.sf-modal:not(.hidden), .sf-library-modal:not(.hidden)');
        if (!anyOpen) {
            document.body.classList.remove('sf-modal-open');
            document.body.style.overflow = '';
        }
    }
    // Log "Näytä lisää" toggles
    function attachLogMoreHandlers() {
        var showMore = window.SF_LOG_SHOW_MORE || 'Näytä lisää';
        var showLess = window.SF_LOG_SHOW_LESS || 'Näytä vähemmän';

        qsa('.sf-log-more').forEach(function (btn) {
            if (btn._sf_attached) return;
            btn.addEventListener('click', function () {
                var item = this.closest('.sf-log-item');
                if (!item) return;
                var msg = item.querySelector('.sf-log-message');
                if (!msg) return;
                var expanded = msg.classList.toggle('expanded');
                this.textContent = expanded ? showLess : showMore;
                this.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            });
            btn._sf_attached = true;
        });
    }

    // Avatar initials
    function initAvatars() {
        qsa('.sf-log-avatar[data-name]').forEach(function (el) {
            if (el.textContent.trim() !== '') return;
            var name = el.getAttribute('data-name') || '';
            var initials = name.split(/\s+/).map(function (s) { return s.charAt(0); }).join('').substring(0, 2).toUpperCase();
            el.textContent = initials || 'SF';
        });
    }

    // Footer buttons -> modals
    function attachFooterActions() {
        var footerMap = [
            { id: 'footerEdit', modal: 'modalEdit' },
            { id: 'footerRequest', modal: 'modalRequestInfo' },
            { id: 'footerComms', modal: 'modalToComms' },
            { id: 'footerApproveToComms', modal: 'modalToComms' },
            { id: 'footerSendSafety', modal: 'modalSendSafety' },
            { id: 'footerPublish', modal: 'modalPublish' },
            { id: 'footerDelete', modal: 'modalDelete' },
            { id: 'footerComment', modal: 'modalComment' },
            { id: 'footerArchive', modal: 'modalArchive' },
            { id: 'footerDisplayTargets', modal: 'displayTargetsModal' }
        ];

        footerMap.forEach(function (mapping) {
            var el = document.getElementById(mapping.id);
            if (!el || el._sf_attached) return;

            el.addEventListener('click', function () {
                if (mapping.id === 'footerDisplayTargets' && typeof window.openDisplayTargetsModal === 'function') {
                    window.openDisplayTargetsModal();
                    return;
                }

                openModal(mapping.modal);
            });

            el._sf_attached = true;
        });

        qsa('[data-open-display-targets]').forEach(function (button) {
            if (button._sf_displayTargetsAttached) return;

            button.addEventListener('click', function () {
                if (typeof window.openDisplayTargetsModal === 'function') {
                    window.openDisplayTargetsModal();
                    return;
                }

                openModal('displayTargetsModal');
            });

            button._sf_displayTargetsAttached = true;
        });

        // Edit OK button (normaali muokkaus)
        var modalEditOk = document.getElementById('modalEditOk');
        if (modalEditOk && !modalEditOk._sf_attached) {
            modalEditOk.addEventListener('click', function () {
                if (window.SF_EDIT_URL) {
                    window.location.href = window.SF_EDIT_URL;
                }
            });
            modalEditOk._sf_attached = true;
        }
    }

    // Modal close buttons
    function attachModalCloseButtons() {
        qsa('[data-modal-close]').forEach(function (btn) {
            if (btn._sf_attached) return;
            btn.addEventListener('click', function () {
                var target = this.getAttribute('data-modal-close');
                if (target) closeModal(target);
            });
            btn._sf_attached = true;
        });
    }

    // UUSI JA YKSINKERTAISTETTU KOODI
    function attachDownloadPreviewHandlers() {
        document.addEventListener('click', function (e) {
            // Etsi latauslinkki, jota klikattiin
            const downloadLink = e.target.closest('a.btn-download-preview[download]');
            if (!downloadLink) {
                return;
            }

            // AINOASTAAN estetään tapahtuman kupliminen globaalille skriptille,
            // jotta lataus-overlay ei aktivoidu.
            // Annetaan selaimen oman, normaalin lataustoiminnon hoitaa loput.
            e.stopPropagation();
        });
    }
    // Footer keyboard support
    function attachFooterKeyboardSupport() {
        qsa('.footer-btn').forEach(function (btn) {
            if (btn._sf_keyboardAttached) return;
            btn.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
            btn._sf_keyboardAttached = true;
        });
    }

    // Report download handler with spinner and toast notifications
    function attachReportDownloadHandler() {
        const reportBtn = document.getElementById('btnGenerateReport');
        if (!reportBtn || reportBtn._sf_reportAttached) return;

        reportBtn.addEventListener('click', async function (e) {
            e.preventDefault();

            const reportUrl = this.getAttribute('data-report-url');
            if (!reportUrl) return;

            const btn = this;
            const originalContent = btn.innerHTML;

            // Get i18n strings with fallbacks
            const i18n = window.SF_I18N || {};
            const loadingText = i18n.report_button_loading || 'Luodaan...';
            const doneText = i18n.report_button_done || 'Valmis!';
            const errorText = i18n.report_button_error || 'Virhe!';
            const successMsg = i18n.report_success || 'Raportti ladattu onnistuneesti';
            const errorMsg = i18n.report_error || 'Raportin generointi epäonnistui';

            // Show loading state with spinner
            btn.disabled = true;
            btn.classList.add('btn-loading');
            btn.innerHTML = `
            <svg class="btn-spinner" width="16" height="16" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round" stroke-dasharray="31.4 31.4">
                    <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="1s" repeatCount="indefinite"/>
                </circle>
            </svg>
            <span>${loadingText}</span>
        `;

            // Toast helper
            const showToast = (msg, type) => {
                if (typeof window.sfToast === 'function') {
                    window.sfToast(type, msg);
                }
            };

            try {
                const response = await fetch(reportUrl, {
                    method: 'GET',
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const blob = await response.blob();

                // Extract filename from Content-Disposition header
                const contentDisposition = response.headers.get('Content-Disposition');
                let filename = 'safetyflash_report.pdf';
                if (contentDisposition) {
                    const filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/);
                    if (filenameMatch && filenameMatch[1]) {
                        filename = filenameMatch[1];
                    }
                }

                // Create download link
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();

                // Cleanup
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);

                // Show success state
                btn.classList.remove('btn-loading');
                btn.classList.add('btn-success');
                btn.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                <span>${doneText}</span>
            `;

                showToast(successMsg, 'success');

                // Reset button after delay
                setTimeout(() => {
                    btn.innerHTML = originalContent;
                    btn.disabled = false;
                    btn.classList.remove('btn-success');
                }, 2000);

            } catch (error) {
                console.error('Report generation error:', error);

                // Show error state
                btn.classList.remove('btn-loading');
                btn.classList.add('btn-error');
                btn.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="15" y1="9" x2="9" y2="15"></line>
                    <line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
                <span>${errorText}</span>
            `;

                showToast(errorMsg, 'error');

                // Reset button after delay
                setTimeout(() => {
                    btn.innerHTML = originalContent;
                    btn.disabled = false;
                    btn.classList.remove('btn-error');
                }, 3000);
            }
        });

        reportBtn._sf_reportAttached = true;
    }

	    function initPreviewImageLoaders() {
        qsa('[data-sf-preview-loader]').forEach(function (loader) {
            var image = loader.querySelector('img.preview-image');

            if (!image) {
                loader.classList.add('is-loaded');
                return;
            }

            function markLoaded() {
                image.classList.remove('sf-preview-image-loading');
                image.classList.add('sf-preview-image-loaded');
                loader.classList.add('is-loaded');
            }

            if (image.complete && image.naturalWidth > 0) {
                markLoaded();
                return;
            }

            image.addEventListener('load', markLoaded, { once: true });
            image.addEventListener('error', markLoaded, { once: true });
        });
    }
    // Init
    function init() {
        attachLogMoreHandlers();
        initAvatars();
        attachFooterActions();
        attachModalCloseButtons();
        attachFooterKeyboardSupport();
        attachDownloadPreviewHandlers();
        attachReportDownloadHandler();
        initPreviewImageLoaders();
        initBottomSheetModals();
        observeBottomSheetModals();

        // Varmista että kaikki modaalit ovat piilossa sivun latautuessa
        qsa('.sf-modal').forEach(function (modal) {
            if (!modal.classList.contains('hidden')) {
                modal.classList.add('hidden');
            }
        });
        // Varmista että body ei ole modal-open tilassa
        document.body.classList.remove('sf-modal-open');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for debugging
    window.openModal = openModal;
    window.closeModal = closeModal;
    window._sf = window._sf || {};
    window._sf.openModal = openModal;
    window._sf.closeModal = closeModal;



    // Modaalin preview-kortin skaalaus
    function scaleModalPreview() {
        var container = document.getElementById('sfTranslationPreviewContainer');
        if (!container) return;

        var card = container.querySelector('.sf-preview-card');
        if (!card) return;

        var containerWidth = container.offsetWidth;
        if (containerWidth <= 0) return;

        var scale = containerWidth / 1920;
        card.style.transform = 'scale(' + scale + ')';
    }

    // Kutsu kun modaali avataan (muokattu openModal-funktio)
    var originalOpenModal = openModal;
    openModal = function (id) {
        originalOpenModal(id);
        if (id === 'modalTranslation') {
            // Pieni viive että modaali ehtii renderöityä
            setTimeout(scaleModalPreview, 50);
        }
    };

    // Resize-kuuntelija modaalille
    window.addEventListener('resize', function () {
        var modal = document.querySelector('#modalTranslation:not(.hidden)');
        if (modal) scaleModalPreview();
    });

    // Expose
    window._sf.scaleModalPreview = scaleModalPreview;
})();

// ===== TUTKINTATIEDOTTEEN PREVIEW-VÄLILEHDET =====
(function () {
    'use strict';

    function initViewPreviewTabs() {
        var tabsContainer = document.getElementById('sfViewPreviewTabs');
        if (!tabsContainer) return;

        var buttons = tabsContainer.querySelectorAll('.sf-view-tab-btn');
        var preview1 = document.getElementById('viewPreview1');
        var preview2 = document.getElementById('viewPreview2');

        if (!preview1 || !preview2) return;

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = this.getAttribute('data-target');

                buttons.forEach(function (b) {
                    b.classList.remove('active');
                });
                this.classList.add('active');

                if (target === 'preview1') {
                    preview1.style.display = '';
                    preview1.classList.add('active');
                    preview2.style.display = 'none';
                    preview2.classList.remove('active');
                } else if (target === 'preview2') {
                    preview1.style.display = 'none';
                    preview1.classList.remove('active');
                    preview2.style.display = '';
                    preview2.classList.add('active');
                }
            });
        });
    }

    function initViewPreviewFullscreen() {
        var modal = document.getElementById('sfViewPreviewFullscreenModal');
        var backdrop = document.getElementById('sfViewPreviewFullscreenBackdrop');
        var closeButton = document.getElementById('sfViewPreviewFullscreenClose');
        var fullscreenBody = document.getElementById('sfViewPreviewFullscreenBody');
        var fullscreenImage = document.getElementById('sfViewPreviewFullscreenImage');
        var titleElement = document.getElementById('sfViewPreviewFullscreenTitle');
        var zoomInButton = document.getElementById('sfViewPreviewZoomIn');
        var zoomOutButton = document.getElementById('sfViewPreviewZoomOut');
        var zoomResetButton = document.getElementById('sfViewPreviewZoomReset');
        var triggers = document.querySelectorAll('[data-preview-fullscreen-trigger="true"]');

        if (!modal || !backdrop || !closeButton || !fullscreenBody || !fullscreenImage || !titleElement || !zoomInButton || !zoomOutButton || !zoomResetButton || !triggers.length) {
            return;
        }

        if (modal.dataset.initialized === '1') {
            return;
        }
        modal.dataset.initialized = '1';

        var currentScale = 1;
        var minScale = 0.1;
        var isDragging = false;
        var dragStartX = 0;
        var dragStartY = 0;
        var startScrollLeft = 0;
        var startScrollTop = 0;
        var lastFocusedTrigger = null;
        var touchStartDistance = 0;
        var touchStartScale = 1;
        var touchStartCenterX = 0;
        var touchStartCenterY = 0;
        var touchStartScrollLeft = 0;
        var touchStartScrollTop = 0;
        var touchStartContentX = 0;
        var touchStartContentY = 0;
        var singleTouchStartX = 0;
        var singleTouchStartY = 0;
        var singleTouchScrollLeft = 0;
        var singleTouchScrollTop = 0;
        var lastTouchX = 0;
        var lastTouchY = 0;
        var lastTouchTime = 0;
        var velocityX = 0;
        var velocityY = 0;
        var momentumFrame = null;

        function stopMomentum() {
            if (momentumFrame) {
                cancelAnimationFrame(momentumFrame);
                momentumFrame = null;
            }
        }

        function startMomentum() {
            stopMomentum();

            var friction = 0.94;
            var minVelocity = 0.15;

            function step() {
                fullscreenBody.scrollLeft -= velocityX;
                fullscreenBody.scrollTop -= velocityY;

                velocityX *= friction;
                velocityY *= friction;

                if (Math.abs(velocityX) > minVelocity || Math.abs(velocityY) > minVelocity) {
                    momentumFrame = requestAnimationFrame(step);
                } else {
                    momentumFrame = null;
                }
            }

            momentumFrame = requestAnimationFrame(step);
        }

        function getTouchDistance(touches) {
            var dx = touches[0].clientX - touches[1].clientX;
            var dy = touches[0].clientY - touches[1].clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }

        function getTouchCenter(touches) {
            return {
                x: (touches[0].clientX + touches[1].clientX) / 2,
                y: (touches[0].clientY + touches[1].clientY) / 2
            };
        }
        function applyZoom() {
            var naturalWidth = fullscreenImage.naturalWidth || 1;
            var naturalHeight = fullscreenImage.naturalHeight || 1;

            fullscreenImage.style.width = Math.round(naturalWidth * currentScale) + 'px';
            fullscreenImage.style.height = Math.round(naturalHeight * currentScale) + 'px';
            fullscreenImage.style.transform = 'none';
        }

        function fitImageToViewport() {
            var bodyRect = fullscreenBody.getBoundingClientRect();
            var naturalWidth = fullscreenImage.naturalWidth || 1;
            var naturalHeight = fullscreenImage.naturalHeight || 1;

            var fitScaleX = bodyRect.width / naturalWidth;
            var fitScaleY = bodyRect.height / naturalHeight;
            var fitScale = Math.min(fitScaleX, fitScaleY, 1.35);

            minScale = Math.max(0.08, fitScale);
            currentScale = fitScale;
            applyZoom();

            requestAnimationFrame(function () {
                fullscreenBody.scrollLeft = Math.max(0, (fullscreenBody.scrollWidth - fullscreenBody.clientWidth) / 2);
                fullscreenBody.scrollTop = Math.max(0, (fullscreenBody.scrollHeight - fullscreenBody.clientHeight) / 2);
            });
        }

        function resetZoom() {
            fitImageToViewport();
        }

        function zoomIn() {
            currentScale = Math.min(currentScale + 0.2, 4);
            applyZoom();
        }

        function zoomOut() {
            currentScale = Math.max(currentScale - 0.2, minScale);
            applyZoom();
        }

        function openModalFromImage(sourceImage) {
            if (!sourceImage || !sourceImage.getAttribute('src')) {
                return;
            }

            lastFocusedTrigger = sourceImage;

            fullscreenImage.classList.remove('is-visible');
            fullscreenImage.style.transform = '';

            fullscreenImage.src = sourceImage.getAttribute('src');
            fullscreenImage.alt = sourceImage.getAttribute('alt') || 'Esikatselu';
            titleElement.textContent = sourceImage.getAttribute('data-preview-title') || 'Esikatselu';

            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('sf-modal-open');

            function showImage() {
                resetZoom();

                requestAnimationFrame(function () {
                    fullscreenImage.classList.add('is-visible');
                });
            }

            if (fullscreenImage.complete && fullscreenImage.naturalWidth > 0) {
                showImage();
            } else {
                fullscreenImage.onload = function () {
                    showImage();
                    fullscreenImage.onload = null;
                };
            }

            closeButton.focus();
        }

        function closeModal() {
            stopMomentum();
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
            fullscreenImage.classList.remove('is-visible');
            fullscreenImage.src = '';
            fullscreenImage.alt = '';
             fullscreenImage.style.width = '';
            fullscreenImage.style.height = '';
            fullscreenImage.style.transform = 'none';
            currentScale = 1;

            var anyOpen = document.querySelector('.sf-modal:not(.hidden), .sf-view-preview-fullscreen-modal:not(.hidden)');
            if (!anyOpen) {
                document.body.classList.remove('sf-modal-open');
            }

            if (lastFocusedTrigger) {
                lastFocusedTrigger.focus();
            }
        }

        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                openModalFromImage(this);
            });

            trigger.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openModalFromImage(this);
                }
            });
        });

        closeButton.addEventListener('click', closeModal);
        backdrop.addEventListener('click', closeModal);

        zoomInButton.addEventListener('click', zoomIn);
        zoomOutButton.addEventListener('click', zoomOut);
        zoomResetButton.addEventListener('click', resetZoom);

        document.addEventListener('keydown', function (e) {
            if (modal.classList.contains('hidden')) {
                return;
            }

            if (e.key === 'Escape') {
                closeModal();
            } else if (e.key === '+') {
                e.preventDefault();
                zoomIn();
            } else if (e.key === '-') {
                e.preventDefault();
                zoomOut();
            } else if (e.key === '0') {
                e.preventDefault();
                resetZoom();
            }
        });

        fullscreenBody.addEventListener('mousedown', function (e) {
            if (currentScale <= 1) {
                return;
            }

            isDragging = true;
            dragStartX = e.clientX;
            dragStartY = e.clientY;
            startScrollLeft = fullscreenBody.scrollLeft;
            startScrollTop = fullscreenBody.scrollTop;
            fullscreenBody.classList.add('is-dragging');
        });

        window.addEventListener('mousemove', function (e) {
            if (!isDragging) {
                return;
            }

            var dx = e.clientX - dragStartX;
            var dy = e.clientY - dragStartY;

            fullscreenBody.scrollLeft = startScrollLeft - dx;
            fullscreenBody.scrollTop = startScrollTop - dy;
        });

        window.addEventListener('mouseup', function () {
            if (!isDragging) {
                return;
            }

            isDragging = false;
            fullscreenBody.classList.remove('is-dragging');
        });

        fullscreenBody.addEventListener('mouseleave', function () {
            if (!isDragging) {
                return;
            }

            isDragging = false;
            fullscreenBody.classList.remove('is-dragging');
        });

        fullscreenBody.addEventListener('touchstart', function (e) {
            if (modal.classList.contains('hidden')) {
                return;
            }

            if (e.touches.length === 2) {
                e.preventDefault();

                touchStartDistance = getTouchDistance(e.touches);
                touchStartScale = currentScale;

                var center = getTouchCenter(e.touches);
                touchStartCenterX = center.x;
                touchStartCenterY = center.y;
                touchStartScrollLeft = fullscreenBody.scrollLeft;
                touchStartScrollTop = fullscreenBody.scrollTop;

                var bodyRect = fullscreenBody.getBoundingClientRect();
                touchStartContentX = touchStartScrollLeft + center.x - bodyRect.left;
                touchStartContentY = touchStartScrollTop + center.y - bodyRect.top;
            }

            if (e.touches.length === 1) {
                stopMomentum();
                singleTouchStartX = e.touches[0].clientX;
                singleTouchStartY = e.touches[0].clientY;
                singleTouchScrollLeft = fullscreenBody.scrollLeft;
                singleTouchScrollTop = fullscreenBody.scrollTop;
                lastTouchX = e.touches[0].clientX;
                lastTouchY = e.touches[0].clientY;
                lastTouchTime = Date.now();
                velocityX = 0;
                velocityY = 0;
            }
        }, { passive: false });

        fullscreenBody.addEventListener('touchmove', function (e) {
            if (modal.classList.contains('hidden')) {
                return;
            }

            if (e.touches.length === 2 && touchStartDistance > 0) {
                e.preventDefault();

                var newDistance = getTouchDistance(e.touches);
                var scaleFactor = newDistance / touchStartDistance;
                currentScale = Math.max(minScale, Math.min(touchStartScale * scaleFactor, 4));
                applyZoom();

                var center = getTouchCenter(e.touches);
                var bodyRect = fullscreenBody.getBoundingClientRect();
                var scaleRatio = currentScale / touchStartScale;

                fullscreenBody.scrollLeft = (touchStartContentX * scaleRatio) - (center.x - bodyRect.left);
                fullscreenBody.scrollTop = (touchStartContentY * scaleRatio) - (center.y - bodyRect.top);
            }

            if (e.touches.length === 1 && currentScale > minScale) {
                e.preventDefault();

                var currentX = e.touches[0].clientX;
                var currentY = e.touches[0].clientY;
                var now = Date.now();
                var elapsed = Math.max(16, now - lastTouchTime);

                var deltaX = currentX - singleTouchStartX;
                var deltaY = currentY - singleTouchStartY;

                fullscreenBody.scrollLeft = singleTouchScrollLeft - deltaX;
                fullscreenBody.scrollTop = singleTouchScrollTop - deltaY;

                velocityX = ((currentX - lastTouchX) / elapsed) * 16;
                velocityY = ((currentY - lastTouchY) / elapsed) * 16;

                lastTouchX = currentX;
                lastTouchY = currentY;
                lastTouchTime = now;
            }
        }, { passive: false });

        fullscreenBody.addEventListener('touchend', function () {
            if (touchStartDistance === 0 && currentScale > minScale) {
                startMomentum();
            }

            touchStartDistance = 0;
        }, { passive: false });

        window.addEventListener('resize', function () {
            if (!modal.classList.contains('hidden') && fullscreenImage.naturalWidth > 0) {
                resetZoom();
            }
        });
    }

    function initAll() {
        initViewPreviewTabs();
        initViewPreviewFullscreen();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
// Archive functionality
(function () {
    function initArchive() {
        var archiveConfirmBtn = document.getElementById('modalArchiveConfirm');
        if (!archiveConfirmBtn) return;

        // Prevent duplicate event listener
        if (archiveConfirmBtn._sf_attached) return;
        archiveConfirmBtn._sf_attached = true;

        archiveConfirmBtn.addEventListener('click', function () {
            var flashId = window.SF_FLASH_DATA ? window.SF_FLASH_DATA.id : null;
            var csrfToken = window.SF_CSRF_TOKEN;

            if (!flashId || !csrfToken) {
                alert('Error: Missing required data');
                return;
            }

            // Disable button during request
            archiveConfirmBtn.disabled = true;
            archiveConfirmBtn.textContent = window.SF_ARCHIVING_TEXT || 'Archiving...';

            // Create form data
            var formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('csrf_token', csrfToken);

            // Send archive request
            fetch(window.SF_BASE_URL + '/app/api/archive_flash.php', {
                method: 'POST',
                body: formData
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success) {
                        // Redirect with success notice
                        var currentUrl = new URL(window.location.href);
                        currentUrl.searchParams.set('notice', 'archived');
                        window.location.href = currentUrl.toString();
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                        archiveConfirmBtn.disabled = false;
                        archiveConfirmBtn.textContent = window.SF_ARCHIVE_BTN_TEXT || 'Archive';
                    }
                })
                .catch(function (error) {
                    console.error('Archive error:', error);
                    alert('Error: Failed to archive SafetyFlash');
                    archiveConfirmBtn.disabled = false;
                    archiveConfirmBtn.textContent = window.SF_ARCHIVE_BTN_TEXT || 'Archive';
                });
        });  // <-- LISÄTTY:  Sulkee addEventListener callback
    }  // <-- LISÄTTY:  Sulkee initArchive funktion

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initArchive);
    } else {
        initArchive();
    }
})();

/**
 * Käsittele workflow-modaalien lomakkeet AJAX:lla
 * Estää JSON-vastauksen näkymisen tyhjällä sivulla
 */
(function () {
    'use strict';

    // Lista modaaleista jotka tarvitsevat AJAX-käsittelyn
    const workflowModalSelectors = [
        { modal: '#modalSendSafety', form: 'form', successMsg: 'Sent to safety team' },
        { modal: '#modalToComms', form: 'form', successMsg: 'Sent to communications' },
        { modal: '#modalRequestInfo', form: 'form', successMsg: 'Returned for corrections' },
        { modal: '#modalPublish', form: 'form', successMsg: 'Published' }
    ];

    function initWorkflowModals() {
        workflowModalSelectors.forEach(config => {
            const modal = document.querySelector(config.modal);
            if (!modal) return;

            const form = modal.querySelector(config.form);
            if (!form) return;

            // Estä duplikaattien lisäys
            if (form.dataset.ajaxBound === '1') return;
            form.dataset.ajaxBound = '1';

            form.addEventListener('submit', async function (e) {
                e.preventDefault();  // Estä normaali form submit

                const formData = new FormData(this);
                const submitBtn = this.querySelector('button[type="submit"]');
                if (!submitBtn) return;

                const originalText = submitBtn.textContent;
                const modalElement = this.closest('.sf-modal');

                // Disable nappi ja näytä lataus
                submitBtn.disabled = true;
                submitBtn.textContent = 'Processing...';

                try {
                    console.log('[WorkflowModal] Submitting to:', this.action);

                    const response = await fetch(this.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    console.log('[WorkflowModal] Response status:', response.status);

                    // Yritä parsea JSON riippumatta Content-Type headerista
                    const text = await response.text();
                    console.log('[WorkflowModal] Raw response:', text.substring(0, 500));

                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (parseError) {
                        console.error('[WorkflowModal] JSON parse failed:', parseError);
                        console.error('[WorkflowModal] Response was:', text);
                        throw new Error('Server returned invalid JSON');
                    }

                    console.log('[WorkflowModal] Parsed data:', data);

                    if (data.ok) {                        // Sulje modal
                        if (modalElement) {
                            modalElement.classList.add('hidden');
                            document.body.classList.remove('sf-modal-open');
                            // Tyhjennä lomake
                            form.reset();
                        }

                        // Näytä toast-ilmoitus
                        if (typeof window.sfToast === 'function') {
                            window.sfToast('success', data.message || config.successMsg);
                        }

                        // Redirectaa tai päivitä sivu
                        if (data.redirect) {
                            // Odota hetki jotta toast ehtii näkyä
                            setTimeout(() => {
                                window.location.href = data.redirect;
                            }, 500);
                        } else {
                            // Päivitä sivu jos ei redirectia
                            setTimeout(() => {
                                window.location.reload();
                            }, 500);
                        }
                    } else {
                        // Virhe backendiltä
                        console.error('[WorkflowModal] Backend error:', data.error);

                        const errorMsg = data.error || 'Error in submission';
                        if (typeof window.sfToast === 'function') {
                            window.sfToast('error', errorMsg);
                        } else {
                            alert(errorMsg);
                        }

                        // Palauta nappi
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;

                        // KRIITTINEN: Sulje modal myös virheen sattuessa
                        if (modalElement) {
                            modalElement.classList.add('hidden');
                            document.body.classList.remove('sf-modal-open');
                        }
                    }
                } catch (error) {
                    console.error('[WorkflowModal] Exception:', error);

                    // KRIITTINEN: Sulje overlay aina virheen sattuessa
                    if (modalElement) {
                        modalElement.classList.add('hidden');
                        document.body.classList.remove('sf-modal-open');
                    }

                    const errorMsg = 'Network error. Check connection and try again.';
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('error', errorMsg);
                    } else {
                        alert(errorMsg);
                    }

                    // Palauta nappi
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                }
            });
        });
    }

    // Alusta kun DOM on valmis
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWorkflowModals);
    } else {
        initWorkflowModals();
    }
})();

/**
 * Sulje modaalit kun klikataan overlay tai ESC-näppäintä
 */
(function () {
    'use strict';

    // ESC-näppäin sulkee modalin
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const visibleModal = document.querySelector('.sf-modal:not(.hidden)');
            if (visibleModal) {
                visibleModal.classList.add('hidden');
                // Poista modal-open luokka jos ei muita avoimia modaaleja
                const anyOpen = document.querySelector('.sf-modal:not(.hidden)');
                if (!anyOpen) {
                    document.body.classList.remove('sf-modal-open');
                }
            }
        }
    });

    // Sulje kun klikataan modalin ulkopuolista overlay-aluetta
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('sf-modal-overlay')) {
            const modal = e.target.closest('.sf-modal');
            if (modal) {
                modal.classList.add('hidden');
                // Poista modal-open luokka jos ei muita avoimia modaaleja
                const anyOpen = document.querySelector('.sf-modal:not(.hidden)');
                if (!anyOpen) {
                    document.body.classList.remove('sf-modal-open');
                }
            }
        }
    });
})();
/**
 * Tab switching for Comments/Events/All activity
 */
(function () {
    'use strict';

    // Tab switching
    const tabs = document.querySelectorAll('.sf-activity-tab');
    const tabContents = document.querySelectorAll('.sf-tab-content');

    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            const targetTab = this.dataset.tab;

            // Remove active from all
            tabs.forEach(t => t.classList.remove('active'));
            tabContents.forEach(tc => tc.classList.remove('active'));

            // Add active to clicked
            this.classList.add('active');
            const targetId = 'tab' + targetTab.charAt(0).toUpperCase() + targetTab.slice(1);
            const targetContent = document.getElementById(targetId);
            if (targetContent) {
                targetContent.classList.add('active');
            }
        });
    });

    // Character counter
    const commentInput = document.getElementById('commentInput');
    const charCount = document.getElementById('charCount');

    if (commentInput && charCount) {
        commentInput.addEventListener('input', function () {
            const length = this.value.length;
            charCount.textContent = length + ' / 2000';

            if (length > 1900) {
                charCount.style.color = '#dc2626';
            } else if (length > 1700) {
                charCount.style.color = '#f59e0b';
            } else {
                charCount.style.color = '#9ca3af';
            }
        });
    }

    // Count comments and update badge
    const comments = document.querySelectorAll('.sf-comment-item');
    const commentBadge = document.getElementById('commentCount');
    if (commentBadge) {
        commentBadge.textContent = comments.length;
    }

    // Get CSRF token
    function getCsrfToken() {
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    // Get base URL
    function getBaseUrl() {
        return window.SF_BASE_URL || '';
    }

    // Get UI translations
    function getTerm(key) {
        if (window.SF_TERMS && window.SF_TERMS[key]) {
            return window.SF_TERMS[key];
        }
        // Fallback translations
        const fallbacks = {
            'comment_delete_confirm': 'Are you sure you want to delete this comment?',
            'comment_deleted': 'Comment deleted',
            'comment_added': 'Comment added',
            'comment_updated': 'Comment updated',
            'comment_delete_error': 'Failed to delete comment',
            'comment_update_error': 'Failed to update comment',
            'comment_add_error': 'Failed to add comment',
            'comment_error_empty': 'Comment cannot be empty',
            'comment_edit': 'Edit',
			'comment_delete': 'Delete',
			'comment_like': 'Like',
			'comment_like_error': 'Failed to update like',
			'time_just_now': 'Just now',
            'modal_comment_edit_title': 'Edit comment',
            'modal_comment_reply_title': 'Reply to comment',
            'modal_comment_title': 'Add comment'
        };
        return fallbacks[key] || key;
    }

    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function renderCommentTextWithMentions(text) {
        const escapedText = escapeHtml(String(text || '')).replace(/\n/g, '<br>');

        return escapedText
            .replace(/(^|[\s>])@([^@\n\r<]+?)\u2060/g, function (match, prefix, name) {
                return prefix + '<span class="sf-comment-mention">@' + name.trim() + '</span>';
            })
            .replace(/(^|[\s>])@([A-ZÅÄÖÀ-ÖØ-Þ][A-Za-zÀ-ÖØ-öø-ÿ'´`-]+(?:\s+[A-ZÅÄÖÀ-ÖØ-Þ][A-Za-zÀ-ÖØ-öø-ÿ'´`-]+)?)/g, function (match, prefix, name) {
                return prefix + '<span class="sf-comment-mention">@' + name.trim() + '</span>';
            });
    }

    function highlightAllCommentMentions() {
        document.querySelectorAll('.sf-comment-body').forEach(function (body) {
            if (body.dataset.mentionsHighlighted === '1') {
                return;
            }

            if (body.querySelector('.sf-comment-structured-list')) {
                body.dataset.mentionsHighlighted = '1';
                return;
            }

            const text = body.innerText || body.textContent || '';
            body.innerHTML = renderCommentTextWithMentions(text);
            body.dataset.mentionsHighlighted = '1';
        });
    }

    function getCommentAuthorColorIndex(authorName) {
        const normalizedName = String(authorName || '')
            .trim()
            .toLowerCase();

        if (!normalizedName) {
            return 0;
        }

        let hash = 0;

        for (let i = 0; i < normalizedName.length; i++) {
            hash = ((hash << 5) - hash) + normalizedName.charCodeAt(i);
            hash |= 0;
        }

        return Math.abs(hash) % 5;
    }

    function applyCommentAuthorColor(commentItem) {
        if (!commentItem) {
            return;
        }

        const authorElement = commentItem.querySelector('.sf-comment-author');
        const authorName = authorElement ? authorElement.textContent.trim() : '';
        const colorIndex = getCommentAuthorColorIndex(authorName);

        const colorPalette = [
            '#2563eb',
            '#1e40af',
            '#334155',
            '#475569',
            '#0f172a'
        ];

        commentItem.classList.remove(
            'sf-comment-author-color-0',
            'sf-comment-author-color-1',
            'sf-comment-author-color-2',
            'sf-comment-author-color-3',
            'sf-comment-author-color-4'
        );

        commentItem.classList.add('sf-comment-author-color-' + colorIndex);
        commentItem.style.setProperty('--sf-comment-avatar-bg', colorPalette[colorIndex] || colorPalette[0]);
    }

    function applyCommentAuthorColors() {
        document.querySelectorAll('.sf-comment-item').forEach(function (commentItem) {
            applyCommentAuthorColor(commentItem);
        });
    }
    // Comment handlers - SINGLE event listener
document.addEventListener('click', function (e) {
    // Like button
    if (e.target.closest('.sf-comment-like-btn')) {
        e.preventDefault();
        e.stopImmediatePropagation();

        const btn = e.target.closest('.sf-comment-like-btn');
        const commentId = btn.dataset.commentId;

        if (!commentId || btn.dataset.loading === '1') {
            return false;
        }

        btn.dataset.loading = '1';
        btn.disabled = true;

        const formData = new FormData();
        formData.append('comment_id', commentId);
        formData.append('csrf_token', getCsrfToken());

        fetch(getBaseUrl() + '/app/api/comment_like_toggle.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data.ok) {
                    throw new Error(data.error || 'Like update failed');
                }

                const countEl = btn.querySelector('.sf-comment-like-count');
                const likeCount = parseInt(data.like_count, 10) || 0;

                btn.dataset.liked = data.liked ? '1' : '0';
                btn.classList.toggle('is-liked', !!data.liked);

                if (countEl) {
                    countEl.textContent = String(likeCount);
                }

                if (Array.isArray(data.liker_names) && data.liker_names.length > 0) {
                    btn.title = data.liker_names.join(', ');
                } else {
                    btn.title = getTerm('comment_like') || 'Like';
                }
            })
            .catch(function (error) {
                console.error('Error updating comment like:', error);

                if (typeof window.sfToast === 'function') {
                    window.sfToast('error', getTerm('comment_like_error') || 'Failed to update like');
                } else {
                    alert(getTerm('comment_like_error') || 'Failed to update like');
                }
            })
            .finally(function () {
                btn.dataset.loading = '0';
                btn.disabled = false;
            });

        return false;
    }

    // Reply button
    if (e.target.closest('.btn-reply-comment')) {
            e.preventDefault();
            e.stopImmediatePropagation();

            const btn = e.target.closest('.btn-reply-comment');
            const commentId = btn.dataset.commentId; // ✅ Get parent comment ID
            const commentItem = btn.closest('.sf-comment-item');
            if (!commentItem) return false;

            const authorEl = commentItem.querySelector('.sf-comment-author');
            const authorName = authorEl ? authorEl.textContent.trim() : '';

            const modal = document.getElementById('modalComment');
            const modalTitle = modal ? modal.querySelector('#modalCommentTitle') : null;
            const textarea = modal ? modal.querySelector('#commentMessage') : null;
            const form = modal ? modal.querySelector('#commentForm') : null;
            const editIdInput = modal ? modal.querySelector('#editCommentId') : null;
            const notifyWrap = modal ? modal.querySelector('#commentNotifyWrap') : null;

            if (modalTitle) modalTitle.textContent = getTerm('modal_comment_reply_title');
            if (textarea) {
                textarea.value = authorName ? '@' + authorName + ' ' : '';
                setTimeout(() => {
                    textarea.focus();
                    textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
                }, 100);
            }
            if (editIdInput) editIdInput.value = '';
            if (notifyWrap) notifyWrap.style.display = '';
            if (form) {
                form.action = getBaseUrl() + '/app/actions/comment.php?id=' + (window.SF_FLASH_ID || '');
                form.removeAttribute('data-edit-mode');

                let parentInput = form.querySelector('input[name="parent_comment_id"]');
                if (!parentInput) {
                    parentInput = document.createElement('input');
                    parentInput.type = 'hidden';
                    parentInput.name = 'parent_comment_id';
                    form.appendChild(parentInput);
                }
                parentInput.value = commentId;

                console.log('[Reply] Setting parent_comment_id:', commentId);
            }

            if (modal) {
                openModal('modalComment');
            }
            return false;
        }

        // Edit button
        if (e.target.closest('.btn-edit-comment')) {
            e.preventDefault();
            e.stopImmediatePropagation();

            const btn = e.target.closest('.btn-edit-comment');
            const commentId = btn.dataset.commentId;
            const commentItem = btn.closest('.sf-comment-item');
            if (!commentItem || !commentId) return false;

            const commentBody = commentItem.querySelector('.sf-comment-body');
            const commentText = commentBody ? commentBody.textContent.trim() : '';

            const modal = document.getElementById('modalComment');
            const modalTitle = modal ? modal.querySelector('#modalCommentTitle') : null;
            const textarea = modal ? modal.querySelector('#commentMessage') : null;
            const form = modal ? modal.querySelector('#commentForm') : null;
            const editIdInput = modal ? modal.querySelector('#editCommentId') : null;
            const notifyWrap = modal ? modal.querySelector('#commentNotifyWrap') : null;

            if (modalTitle) modalTitle.textContent = getTerm('modal_comment_edit_title');
            if (textarea) {
                textarea.value = commentText;
                setTimeout(() => textarea.focus(), 100);
            }
            if (editIdInput) editIdInput.value = commentId;
            if (notifyWrap) notifyWrap.style.display = 'none';
            if (form) form.setAttribute('data-edit-mode', 'true');

            if (modal) {
                openModal('modalComment');
            }
            return false;
        }

        // Delete button
        if (e.target.closest('.btn-delete-comment')) {
            e.preventDefault();
            e.stopImmediatePropagation();

            const btn = e.target.closest('.btn-delete-comment');
            const commentId = btn.dataset.commentId;
            if (!commentId) return false;

            // Tallenna commentId modaliin (sovelluksen pattern)
            const modal = document.getElementById('modalDeleteComment');
            if (modal) {
                modal.dataset.commentId = commentId;
                openModal('modalDeleteComment');
            }
            return false;
        }
    }); // <-- OIKEA SULKEMINEN event listenerille

    // Modal confirm -nappi
    const deleteCommentConfirmBtn = document.getElementById('modalDeleteCommentConfirm');
    if (deleteCommentConfirmBtn) {
        deleteCommentConfirmBtn.addEventListener('click', function () {
            const modal = document.getElementById('modalDeleteComment');
            const commentId = modal ? modal.dataset.commentId : null;

            if (!commentId) return;

            // Prevent double-click
            if (this.dataset.deleting === 'true') return;

            // Mark as processing
            this.dataset.deleting = 'true';
            this.disabled = true;
            const originalHTML = this.innerHTML;
            this.innerHTML = '⏳';

            const formData = new FormData();
            formData.append('comment_id', commentId);
            formData.append('csrf_token', getCsrfToken());

            fetch(getBaseUrl() + '/app/api/comment_delete.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.ok) {
                        // Find and remove comment from DOM
                        const commentBtn = document.querySelector('.btn-delete-comment[data-comment-id="' + commentId + '"]');
                        const commentItem = commentBtn ? commentBtn.closest('.sf-comment-item') : null;

                        if (commentItem) {
                            commentItem.style.transition = 'opacity 0.3s';
                            commentItem.style.opacity = '0';

                            setTimeout(() => {
                                commentItem.remove();

                                // Update comment count
                                const updatedComments = document.querySelectorAll('.sf-comment-item');
                                const badge = document.getElementById('commentCount');
                                if (badge) {
                                    badge.textContent = updatedComments.length;
                                }

                                // Show empty state if no comments left
                                if (updatedComments.length === 0) {
                                    const commentsContainer = document.querySelector('.sf-comments-list');
                                    if (commentsContainer && commentsContainer.parentElement) {
                                        const emptyDiv = document.createElement('div');
                                        emptyDiv.className = 'sf-empty-state';

                                        const img = document.createElement('img');
                                        img.src = getBaseUrl() + '/assets/img/icons/no-comments.svg';
                                        img.alt = '';
                                        img.className = 'sf-empty-icon';

                                        const p = document.createElement('p');
                                        p.textContent = getTerm('comments_empty') || 'No comments yet. Be the first!';

                                        emptyDiv.appendChild(img);
                                        emptyDiv.appendChild(p);

                                        const parent = commentsContainer.parentElement;
                                        parent.innerHTML = '';
                                        parent.appendChild(emptyDiv);
                                    }
                                }
                            }, 300);
                        }

                        // Close modal
                        closeModal('modalDeleteComment');

                        // Show success toast
                        if (typeof window.sfToast === 'function') {
                            window.sfToast('success', getTerm('comment_deleted'));
                        }

                        // Reset button
                        this.dataset.deleting = 'false';
                        this.disabled = false;
                        this.innerHTML = originalHTML;
                    } else {
                        // Error
                        this.dataset.deleting = 'false';
                        this.disabled = false;
                        this.innerHTML = originalHTML;
                        closeModal('modalDeleteComment');

                        alert(data.error || getTerm('comment_delete_error'));
                    }
                })
                .catch(error => {
                    console.error('Error deleting comment:', error);
                    this.dataset.deleting = 'false';
                    this.disabled = false;
                    this.innerHTML = originalHTML;
                    closeModal('modalDeleteComment');

                    alert(getTerm('comment_delete_error'));
                });
        });
    }

    // Handle comment form submission for edit mode AND new comments
    const commentForm = document.getElementById('commentForm');
    if (commentForm) {
        commentForm.addEventListener('submit', function (e) {
            e.preventDefault(); // ALWAYS prevent default submit

            const editIdInput = this.querySelector('#editCommentId');
            const editMode = editIdInput && editIdInput.value;
            const textarea = this.querySelector('#commentMessage');
            const message = textarea ? textarea.value.trim() : '';

            if (!message) {
                alert(getTerm('comment_error_empty') || 'Comment cannot be empty');
                return;
            }

            // EDIT MODE - use existing update API
            if (editMode) {
                const commentId = editIdInput.value;
                const formData = new FormData();
                formData.append('comment_id', commentId);
                formData.append('message', message);
                formData.append('csrf_token', getCsrfToken());

                fetch(getBaseUrl() + '/app/api/comment_update.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.ok) {
                            // Update comment in DOM
                            const commentItem = document.querySelector('.sf-comment-item[data-comment-id="' + commentId + '"]');
                            if (commentItem) {
                                const commentBody = commentItem.querySelector('.sf-comment-body');
                                if (commentBody) {
                                    commentBody.innerHTML = renderCommentTextWithMentions(message);
                                    commentBody.dataset.mentionsHighlighted = '1';
                                }
                            }

                            closeModal('modalComment');
                            document.body.classList.remove('sf-modal-open');
                            document.body.classList.remove('sf-loading');
                            document.body.classList.remove('sf-loading-long');

                            document.querySelectorAll('.sf-modal:not(.hidden)').forEach(function (modal) {
                                modal.classList.add('hidden');
                            });

                            if (typeof window.sfToast === 'function') {
                                window.sfToast('success', getTerm('comment_updated') || 'Comment updated');
                            }

                            // Clear form
                            textarea.value = '';
                            if (editIdInput) editIdInput.value = '';
                            this.removeAttribute('data-edit-mode');
                        } else {
                            alert(data.error || getTerm('comment_update_error'));
                        }
                    })
                    .catch(error => {
                        console.error('Error updating comment:', error);
                        alert(getTerm('comment_update_error'));
                    });
            }
            // NEW COMMENT MODE - use AJAX to add comment
            else {
                const formData = new FormData(this);

                // ✅ DEBUG: Log submission
                console.log('[CommentForm] Submitting AJAX comment, parent_id:', formData.get('parent_comment_id'));

                // Add AJAX header indicator
                fetch(this.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(response => {
                        console.log('[CommentForm] Response status:', response.status);
                        return response.json();
                    })
                    .then(data => {
                        console.log('[CommentForm] Response data:', data);

                        if (data.ok && data.comment) {
                            // Close modal
                            closeModal('modalComment');

                            // ✅ CRITICAL: Force stop ALL loading states IMMEDIATELY
                            document.body.classList.remove('sf-loading');
                            document.body.classList.remove('sf-loading-long');
                            document.body.classList.remove('sf-modal-open');

                            // ✅ Force complete and reset progress bar
                            const progressBar = document.getElementById('sfProgress');
                            if (progressBar) {
                                progressBar.style.width = '100%';
                                progressBar.style.transition = 'width 0.1s ease';
                                setTimeout(() => {
                                    progressBar.style.width = '0%';
                                }, 150);
                            }

                            // ✅ Double-check with timeout (in case something tries to re-add classes)
                            setTimeout(() => {
                                document.body.classList.remove('sf-modal-open');
                                document.body.classList.remove('sf-loading');
                                document.body.classList.remove('sf-loading-long');

                                // Ensure ALL modals are hidden
                                document.querySelectorAll('.sf-modal:not(.hidden)').forEach(m => {
                                    m.classList.add('hidden');
                                });

                                // Reset progress bar completely
                                if (progressBar) {
                                    progressBar.style.width = '0%';
                                }
                            }, 200);

                            // Clear form
                            textarea.value = '';

                            // Show success toast
                            if (typeof window.sfToast === 'function') {
                                window.sfToast('success', getTerm('comment_added') || 'Comment added');
                            }

                            // Add new comment to DOM
                            addCommentToDOM(data.comment);

                            // Update comment count badge
                            updateCommentCount();

                            // Scroll to new comment (smooth)
                            scrollToComment(data.comment.id);

                        } else {
                            alert(data.error || getTerm('comment_add_error') || 'Failed to add comment');
                        }
                    })
                    .catch(error => {
                        console.error('Error adding comment:', error);
                        alert(getTerm('comment_add_error') || 'Failed to add comment');
                    });
            }
        });
    }

    // Helper: Add new comment to DOM
    function addCommentToDOM(comment) {
        const existingComment = document.querySelector(`.sf-comment-item[data-comment-id="${comment.id}"]`);
        if (existingComment) {
            console.log('Comment already exists in DOM, skipping duplicate:', comment.id);
            return;
        }

        let commentsList = document.querySelector('.sf-comments-list');

        const emptyState = document.querySelector('.sf-comments-container .sf-empty-state');
        if (emptyState) {
            emptyState.remove();

            if (!commentsList) {
                const container = document.querySelector('.sf-comments-container');
                if (container) {
                    const newList = document.createElement('div');
                    newList.className = 'sf-comments-list';
                    container.appendChild(newList);
                    commentsList = newList;
                }
            }
        }

        if (!commentsList) return;

        const initials = String(comment.author || 'SF')
            .split(' ')
            .filter(Boolean)
            .map(function (namePart) {
                return namePart[0];
            })
            .join('')
            .toUpperCase()
            .substring(0, 2) || 'SF';

        const isReply = comment.parent_comment_id && comment.parent_comment_id > 0;
        const indentClass = isReply ? 'sf-comment-reply' : '';
        const flashType = String(comment.flash_type || '');
        const flashTypeLabel = String(comment.flash_type_label || '');
        const flashTypeBadge = flashTypeLabel !== ''
            ? `<span class="sf-comment-badge sf-comment-type-badge sf-comment-type-badge--${escapeHtml(flashType)}">${escapeHtml(flashTypeLabel)}</span>`
            : '';

        const commentHtml = `
            <div class="sf-comment-item sf-comment-own sf-comment-new ${indentClass}" data-comment-id="${comment.id}" data-parent-id="${comment.parent_comment_id || ''}">
                <div class="sf-comment-avatar" data-name="${escapeHtml(comment.author || '')}">
                    ${escapeHtml(initials)}
                </div>
                <div class="sf-comment-content">
                    <div class="sf-comment-header">
                        <span class="sf-comment-author">${escapeHtml(comment.author || '')}</span>
                        ${flashTypeBadge}
                        <span class="sf-comment-time">${getTerm('time_just_now') || 'Just now'}</span>
                    </div>
<div class="sf-comment-body" data-mentions-highlighted="1">${renderCommentTextWithMentions(comment.text || '')}</div>
<div class="sf-comment-footer">
    <div class="sf-comment-actions">
        <button type="button" class="sf-comment-action-btn btn-reply-comment" data-comment-id="${comment.id}">
            <img src="${getBaseUrl()}/assets/img/icons/reply.svg" alt="" class="sf-action-icon">
            ${getTerm('comment_reply') || 'Reply'}
        </button>
        <button type="button" class="sf-comment-action-btn btn-edit-comment" data-comment-id="${comment.id}">
            <img src="${getBaseUrl()}/assets/img/icons/edit.svg" alt="" class="sf-action-icon">
            ${getTerm('comment_edit') || 'Edit'}
        </button>
        <button type="button" class="sf-comment-action-btn btn-delete-comment sf-text-danger" data-comment-id="${comment.id}">
            <img src="${getBaseUrl()}/assets/img/icons/delete.svg" alt="" class="sf-action-icon">
            ${getTerm('comment_delete') || 'Delete'}
        </button>
    </div>

    <button
        type="button"
        class="sf-comment-like-btn"
        data-comment-id="${comment.id}"
        data-liked="0"
        title="${escapeHtml(getTerm('comment_like') || 'Like')}"
        aria-label="${escapeHtml(getTerm('comment_like') || 'Like')}"
    >
        <span class="sf-comment-like-icon" aria-hidden="true">👍</span>
        <span class="sf-comment-like-count">0</span>
    </button>
</div>
                </div>
            </div>
        `;

        if (isReply) {
            const parentComment = commentsList.querySelector(`.sf-comment-item[data-comment-id="${comment.parent_comment_id}"]`);
            if (parentComment) {
                let insertAfter = parentComment;
                let nextSibling = parentComment.nextElementSibling;

                while (
                    nextSibling &&
                    nextSibling.classList.contains('sf-comment-reply') &&
                    String(nextSibling.dataset.parentId) === String(comment.parent_comment_id)
                ) {
                    insertAfter = nextSibling;
                    nextSibling = nextSibling.nextElementSibling;
                }

                insertAfter.insertAdjacentHTML('afterend', commentHtml);
            } else {
                commentsList.insertAdjacentHTML('afterbegin', commentHtml);
            }
        } else {
            // Top-level comment, add to beginning
            commentsList.insertAdjacentHTML('afterbegin', commentHtml);
        }

        const addedComment = commentsList.querySelector(`.sf-comment-item[data-comment-id="${comment.id}"]`);
        applyCommentAuthorColor(addedComment);
    }

    applyCommentAuthorColors();
    highlightAllCommentMentions();

    // Helper: Update comment count badge
    function updateCommentCount() {
        const badge = document.getElementById('commentCount');
        const comments = document.querySelectorAll('.sf-comment-item');
        if (badge) {
            badge.textContent = comments.length;
        }
    }

    // Helper: Scroll to comment with smooth animation
    function scrollToComment(commentId) {
        setTimeout(() => {
            const commentItem = document.querySelector(`.sf-comment-item[data-comment-id="${commentId}"]`);
            if (commentItem) {
                commentItem.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });

                setTimeout(() => {
                    commentItem.classList.remove('sf-comment-new');
                }, 6500);
            }
        }, 100);
    }

    // Inline quick comment form: submit with AJAX so the page does not jump to top
const quickCommentForm = document.querySelector('.sf-quick-comment-form');
if (quickCommentForm && !quickCommentForm._sfAjaxAttached) {
    quickCommentForm._sfAjaxAttached = true;

    const quickCommentTextarea = quickCommentForm.querySelector('.sf-quick-comment-input');

    if (quickCommentTextarea && !quickCommentTextarea._sfEnterAttached) {
        quickCommentTextarea._sfEnterAttached = true;

        quickCommentTextarea.addEventListener('keydown', function (e) {
            const mentionDropdown = document.getElementById('mentionDropdown');
            const mentionVisible =
                mentionDropdown &&
                mentionDropdown.style.display !== 'none' &&
                mentionDropdown.children.length > 0;

            if (mentionVisible) {
                return;
            }

            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();

                if (this.value.trim() !== '') {
                    quickCommentForm.requestSubmit();
                }
            }
        });
    }

    quickCommentForm.addEventListener('submit', function (e) {
        e.preventDefault();

            const form = this;
            const textarea = form.querySelector('.sf-quick-comment-input');
            const submitBtn = form.querySelector('.sf-quick-comment-submit');
            const message = textarea ? textarea.value.trim() : '';

            if (!message) {
                alert(getTerm('comment_error_empty') || 'Comment cannot be empty');
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
            }

            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data.ok && data.comment) {
                        if (textarea) {
                            textarea.value = '';
                        }

                        addCommentToDOM(data.comment);
                        updateCommentCount();
                        scrollToComment(data.comment.id);

                        if (typeof window.sfToast === 'function') {
                            window.sfToast('success', getTerm('comment_added') || 'Comment added');
                        }
                    } else {
                        alert(data.error || data.message || getTerm('comment_add_error') || 'Failed to add comment');
                    }
                })
                .catch(function (error) {
                    console.error('Error adding quick comment:', error);
                    alert(getTerm('comment_add_error') || 'Failed to add comment');
                })
                .finally(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }

                    document.body.classList.remove('sf-loading');
                    document.body.classList.remove('sf-loading-long');
                });
        });
    }
	
    // Reset modal when opening for new comment (footer button)
    const footerComment = document.getElementById('footerComment');
    if (footerComment) {
        footerComment.addEventListener('click', function () {
            const modal = document.getElementById('modalComment');
            if (!modal) return;

            const modalTitle = modal.querySelector('#modalCommentTitle');
            const textarea = modal.querySelector('#commentMessage');
            const form = modal.querySelector('#commentForm');
            const editIdInput = modal.querySelector('#editCommentId');
            const notifyWrap = modal.querySelector('#commentNotifyWrap');
            const modalCheckbox = modal.querySelector('#commentNotificationsEnabled');
            const inlineCheckbox = document.getElementById('commentNotifyInline');

            if (modalTitle) modalTitle.textContent = getTerm('modal_comment_title');
            if (textarea) textarea.value = '';
            if (editIdInput) editIdInput.value = '';
            if (notifyWrap) notifyWrap.style.display = '';

            if (modalCheckbox && inlineCheckbox) {
                modalCheckbox.checked = inlineCheckbox.checked;
            }

            if (form) {
                form.removeAttribute('data-edit-mode');
                form.action = getBaseUrl() + '/app/actions/comment.php?id=' + (window.SF_FLASH_ID || '');

                const parentInput = form.querySelector('input[name="parent_comment_id"]');
                if (parentInput) {
                    parentInput.value = '';
                }
            }
        });
    }

    // Inline comment notification toggle
    const commentNotifyInline = document.getElementById('commentNotifyInline');
    if (commentNotifyInline && !commentNotifyInline._sfAttached) {
        commentNotifyInline.addEventListener('change', function () {
            const checkbox = this;
            const previousValue = !checkbox.checked;

            const formData = new FormData();
            formData.append('comment_notifications_enabled', checkbox.checked ? '1' : '0');
            formData.append('csrf_token', getCsrfToken());

            fetch(getBaseUrl() + '/app/actions/comment_subscription.php?id=' + (window.SF_FLASH_ID || ''), {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (!data.ok) {
                        checkbox.checked = previousValue;
                        alert(data.error || 'Asetuksen tallennus epäonnistui');
                        return;
                    }

                    const modalCheckbox = document.getElementById('commentNotificationsEnabled');
                    if (modalCheckbox) {
                        modalCheckbox.checked = checkbox.checked;
                    }

                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', data.message || 'Asetus tallennettu');
                    }
                })
                .catch(() => {
                    checkbox.checked = previousValue;
                    alert('Asetuksen tallennus epäonnistui');
                });
        });

        commentNotifyInline._sfAttached = true;
    }

})();

// =========================
// Log delete functionality (admin only)
// =========================
(function () {
    'use strict';

    let logIdToDelete = null;

    // Helper to get term
    function getTerm(key) {
        return (window.SF_TERMS && window.SF_TERMS[key]) || '';
    }

    // Handle delete button clicks
    document.addEventListener('click', function (e) {
        const deleteBtn = e.target.closest('.sf-log-delete-btn');
        if (deleteBtn) {
            e.preventDefault();
            logIdToDelete = deleteBtn.dataset.logId;
            const modal = document.getElementById('modalDeleteLog');
            if (modal) {
                modal.classList.remove('hidden');
            }
        }
    });

    // Handle confirm delete
    const confirmDeleteBtn = document.getElementById('confirmDeleteLog');
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', async function () {
            if (!logIdToDelete) return;

            // Validate logIdToDelete is numeric
            const numericLogId = parseInt(logIdToDelete, 10);
            if (isNaN(numericLogId) || numericLogId <= 0) {
                console.error('Invalid log ID:', logIdToDelete);
                return;
            }

            try {
                const response = await fetch(window.SF_BASE_URL + '/app/api/delete_log.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        log_id: numericLogId,
                        csrf_token: window.SF_CSRF_TOKEN
                    })
                });

                const result = await response.json();

                if (result.ok) {
                    // Remove the log item with animation
                    const logItem = document.getElementById('log-' + numericLogId);
                    if (logItem) {
                        logItem.style.transition = 'opacity 0.3s, transform 0.3s';
                        logItem.style.opacity = '0';
                        logItem.style.transform = 'translateX(-20px)';
                        setTimeout(() => logItem.remove(), 300);
                    }

                    // Also remove from events timeline if present
                    const eventItem = document.querySelector(`.sf-event-item [data-log-id="${numericLogId}"]`)?.closest('.sf-event-item');
                    if (eventItem) {
                        eventItem.style.transition = 'opacity 0.3s';
                        eventItem.style.opacity = '0';
                        setTimeout(() => eventItem.remove(), 300);
                    }
                } else {
                    const errorMsg = result.error || getTerm('log_delete_error');
                    if (!errorMsg) {
                        console.error('Missing translation for log_delete_error');
                    }
                    if (errorMsg) {
                        alert(errorMsg);
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                const networkError = getTerm('error_network');
                if (!networkError) {
                    console.error('Missing translation for error_network');
                }
                if (networkError) {
                    alert(networkError);
                }
            } finally {
                logIdToDelete = null;
                const modal = document.getElementById('modalDeleteLog');
                if (modal) {
                    modal.classList.add('hidden');
                }
            }
        });
    }
})();
// =========================
// @mention autocomplete in comment textareas
// =========================
(function () {
    'use strict';

    var mentionedUserIdsByForm = new WeakMap();
    var dropdownVisible = false;
    var activeIndex = -1;
    var fetchTimer = null;
    var activeTextarea = null;

    function getDropdown() {
        return document.getElementById('mentionDropdown');
    }

    function getBaseUrl() {
        return (window.SF_BASE_URL || '').replace(/\/$/, '');
    }

    function getMentionForm(textarea) {
        return textarea ? textarea.closest('form') : null;
    }

    function getMentionContainer(textarea) {
        var form = getMentionForm(textarea);
        if (!form) {
            return null;
        }

        var container = form.querySelector('[data-mention-container="true"], #mentionedUsersContainer');

        if (!container) {
            container = document.createElement('div');
            container.hidden = true;
            container.setAttribute('data-mention-container', 'true');
            form.appendChild(container);
        }

        return container;
    }

    function getMentionSet(textarea) {
        var form = getMentionForm(textarea);
        if (!form) {
            return new Set();
        }

        if (!mentionedUserIdsByForm.has(form)) {
            mentionedUserIdsByForm.set(form, new Set());
        }

        return mentionedUserIdsByForm.get(form);
    }

    function getMentionQuery(textarea) {
        var val = textarea.value;
        var cursor = textarea.selectionStart;
        var before = val.substring(0, cursor);

        var atPos = -1;

        for (var i = cursor - 1; i >= 0; i--) {
            if (val[i] === '@') {
                if (i === 0 || /\s/.test(val[i - 1])) {
                    atPos = i;
                }
                break;
            }

            if (/\s/.test(val[i])) {
                break;
            }
        }

        if (atPos < 0) {
            return null;
        }

        var query = before.substring(atPos + 1);

        if (query.length < 1) {
            return null;
        }

        if ((query.match(/ /g) || []).length > 1) {
            return null;
        }

        if (/  /.test(query)) {
            return null;
        }

        return {
            query: query,
            atPos: atPos
        };
    }

    function placeDropdownNearTextarea(textarea) {
        var dropdown = getDropdown();

        if (!dropdown || !textarea) {
            return;
        }

        var host = textarea.parentElement;

        if (!host) {
            return;
        }

        if (window.getComputedStyle(host).position === 'static') {
            host.style.position = 'relative';
        }

        if (dropdown.parentElement !== host) {
            host.appendChild(dropdown);
        }

        var textareaStyles = window.getComputedStyle(textarea);
        var mirror = document.createElement('div');
        var cursorPosition = textarea.selectionStart || 0;
        var textBeforeCursor = textarea.value.substring(0, cursorPosition);
        var lineHeight = parseFloat(textareaStyles.lineHeight);

        if (!Number.isFinite(lineHeight)) {
            lineHeight = parseFloat(textareaStyles.fontSize) * 1.4;
        }

        mirror.style.position = 'absolute';
        mirror.style.visibility = 'hidden';
        mirror.style.pointerEvents = 'none';
        mirror.style.whiteSpace = 'pre-wrap';
        mirror.style.wordWrap = 'break-word';
        mirror.style.overflowWrap = 'break-word';
        mirror.style.boxSizing = 'border-box';
        mirror.style.left = textarea.offsetLeft + 'px';
        mirror.style.top = textarea.offsetTop + 'px';
        mirror.style.width = textarea.clientWidth + 'px';
        mirror.style.minHeight = textarea.clientHeight + 'px';
        mirror.style.font = textareaStyles.font;
        mirror.style.fontSize = textareaStyles.fontSize;
        mirror.style.fontFamily = textareaStyles.fontFamily;
        mirror.style.fontWeight = textareaStyles.fontWeight;
        mirror.style.letterSpacing = textareaStyles.letterSpacing;
        mirror.style.lineHeight = textareaStyles.lineHeight;
        mirror.style.padding = textareaStyles.padding;
        mirror.style.border = textareaStyles.border;
        mirror.style.overflow = 'hidden';

        var safeText = textBeforeCursor
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\n$/g, '\n\u200b');

        mirror.innerHTML = safeText + '<span data-caret-marker="true">&#8203;</span>';

        host.appendChild(mirror);

        var marker = mirror.querySelector('[data-caret-marker="true"]');
        var markerRect = marker.getBoundingClientRect();
        var hostRect = host.getBoundingClientRect();

        var dropdownWidth = Math.min(360, Math.max(260, textarea.offsetWidth * 0.42));
        var textareaLeft = textarea.offsetLeft;
        var textareaRight = textarea.offsetLeft + textarea.offsetWidth;
        var calculatedLeft = markerRect.left - hostRect.left;
        var maxLeft = Math.max(textareaLeft, textareaRight - dropdownWidth);

        var left = Math.min(Math.max(textareaLeft, calculatedLeft - 18), maxLeft);
        var top = markerRect.top - hostRect.top + lineHeight + 8 - textarea.scrollTop;

        mirror.remove();

        dropdown.style.setProperty('width', dropdownWidth + 'px');
        dropdown.style.setProperty('left', left + 'px', 'important');
        dropdown.style.setProperty('right', 'auto', 'important');
        dropdown.style.setProperty('top', top + 'px', 'important');
        dropdown.style.setProperty('bottom', 'auto', 'important');
    }

    function showDropdown(textarea, items, atPos) {
        var dropdown = getDropdown();

        if (!dropdown || !textarea) {
            return;
        }

        placeDropdownNearTextarea(textarea);

        dropdown.innerHTML = '';
        activeIndex = -1;
        activeTextarea = textarea;

        if (items.length === 0) {
            hideDropdown();
            return;
        }

        items.forEach(function (user, idx) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'sf-mention-item';
            item.dataset.userId = user.id;
            item.dataset.userName = user.name;
            item.dataset.atPos = atPos;
            item.setAttribute('role', 'option');

            item.innerHTML =
                '<span class="sf-mention-item-avatar">' + escapeInitials(user.name) + '</span>' +
                '<span class="sf-mention-item-name">' + escapeHtmlLocal(user.name) + '</span>';

            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                selectUser(
                    textarea,
                    {
                        id: parseInt(item.dataset.userId, 10),
                        name: item.dataset.userName
                    },
                    parseInt(item.dataset.atPos, 10)
                );
            });

            dropdown.appendChild(item);
        });

        dropdown.style.display = 'block';
        dropdownVisible = true;
    }

    function hideDropdown() {
        var dropdown = getDropdown();

        if (dropdown) {
            dropdown.style.display = 'none';
        }

        dropdownVisible = false;
        activeIndex = -1;
        activeTextarea = null;
    }

    function setActiveItem(idx) {
        var dropdown = getDropdown();

        if (!dropdown) {
            return;
        }

        var items = dropdown.querySelectorAll('.sf-mention-item');

        items.forEach(function (item, i) {
            item.classList.toggle('sf-mention-item--active', i === idx);
        });

        activeIndex = idx;
    }

    function selectUser(textarea, user, atPos) {
        if (!textarea) {
            return;
        }

        var val = textarea.value;
        var cursor = textarea.selectionStart;
        var before = val.substring(0, atPos);
        var after = val.substring(cursor);
        var insertion = '@' + user.name + '\u2060 ';

        textarea.value = before + insertion + after;

        var newPos = (before + insertion).length;
        textarea.setSelectionRange(newPos, newPos);
        textarea.focus();

        addMentionedUser(textarea, user);
        hideDropdown();

        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function addMentionedUser(textarea, user) {
        var mentionedUserIds = getMentionSet(textarea);

        if (mentionedUserIds.has(user.id)) {
            return;
        }

        mentionedUserIds.add(user.id);

        var container = getMentionContainer(textarea);

        if (!container) {
            return;
        }

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'mentioned_user_ids[]';
        input.value = user.id;
        input.dataset.mentionId = user.id;

        container.appendChild(input);
    }

    function clearMentionedUsers(textarea) {
        var form = getMentionForm(textarea);

        if (!form) {
            return;
        }

        mentionedUserIdsByForm.set(form, new Set());

        var container = getMentionContainer(textarea);

        if (container) {
            container.innerHTML = '';
        }
    }

    function fetchSuggestions(textarea, query, atPos) {
        clearTimeout(fetchTimer);

        fetchTimer = setTimeout(function () {
            var url = getBaseUrl() + '/app/api/users_search.php?query=' + encodeURIComponent(query) + '&limit=8';

            fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data.ok && Array.isArray(data.users)) {
                        showDropdown(textarea, data.users, atPos);
                    } else {
                        hideDropdown();
                    }
                })
                .catch(function () {
                    hideDropdown();
                });
        }, 150);
    }

    function attachMentionListeners(textarea) {
        if (!textarea || textarea._sfMentionAttached) {
            return;
        }

        textarea._sfMentionAttached = true;

        textarea.addEventListener('input', function () {
            var result = getMentionQuery(textarea);

            if (result) {
                fetchSuggestions(textarea, result.query, result.atPos);
            } else {
                clearTimeout(fetchTimer);
                hideDropdown();
            }
        });

        textarea.addEventListener('keydown', function (e) {
            if (!dropdownVisible || activeTextarea !== textarea) {
                return;
            }

            var dropdown = getDropdown();

            if (!dropdown) {
                return;
            }

            var items = dropdown.querySelectorAll('.sf-mention-item');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActiveItem(Math.min(activeIndex + 1, items.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActiveItem(Math.max(activeIndex - 1, 0));
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                if (activeIndex >= 0 && items[activeIndex]) {
                    e.preventDefault();

                    var item = items[activeIndex];

                    selectUser(
                        textarea,
                        {
                            id: parseInt(item.dataset.userId, 10),
                            name: item.dataset.userName
                        },
                        parseInt(item.dataset.atPos, 10)
                    );
                } else if (items.length === 1) {
                    e.preventDefault();

                    selectUser(
                        textarea,
                        {
                            id: parseInt(items[0].dataset.userId, 10),
                            name: items[0].dataset.userName
                        },
                        parseInt(items[0].dataset.atPos, 10)
                    );
                } else if (e.key === 'Tab') {
                    hideDropdown();
                }
            } else if (e.key === 'Escape') {
                hideDropdown();
            }
        });

        textarea.addEventListener('blur', function () {
            setTimeout(hideDropdown, 150);
        });
    }

    function attachAllMentionListeners() {
        document.querySelectorAll('#commentMessage, .sf-quick-comment-input').forEach(function (textarea) {
            attachMentionListeners(textarea);
        });
    }

    function resetMentionsForCommentModal() {
        var textarea = document.getElementById('commentMessage');

        if (textarea) {
            clearMentionedUsers(textarea);
        }

        hideDropdown();
    }

    function resetMentionsForQuickComment() {
        document.querySelectorAll('.sf-quick-comment-input').forEach(function (textarea) {
            clearMentionedUsers(textarea);
        });

        hideDropdown();
    }

    function escapeHtmlLocal(text) {
        var div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    function escapeInitials(name) {
        return escapeHtmlLocal(
            String(name || 'SF')
                .split(/\s+/)
                .filter(Boolean)
                .map(function (part) {
                    return part.charAt(0);
                })
                .join('')
                .substring(0, 2)
                .toUpperCase() || 'SF'
        );
    }

    document.addEventListener('click', function (e) {
        if (
            e.target.closest('#footerComment') ||
            e.target.closest('.btn-reply-comment') ||
            e.target.closest('.btn-edit-comment')
        ) {
            setTimeout(function () {
                resetMentionsForCommentModal();
                attachAllMentionListeners();
            }, 0);
        }
    });

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-modal-close="modalComment"]')) {
            resetMentionsForCommentModal();
        }
    });

    document.addEventListener('submit', function (e) {
        if (e.target && e.target.matches('.sf-quick-comment-form')) {
            setTimeout(resetMentionsForQuickComment, 250);
        }

        if (e.target && e.target.matches('#commentForm')) {
            setTimeout(resetMentionsForCommentModal, 250);
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachAllMentionListeners);
    } else {
        attachAllMentionListeners();
    }
})();