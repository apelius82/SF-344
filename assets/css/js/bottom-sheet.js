(function () {
    'use strict';

    const DEFAULT_DRAG_THRESHOLD = 120;
    const BACKDROP_FADE_DISTANCE = 260;
    // Cap threshold to 30% of visible sheet height so short sheets can still be dismissed comfortably.
    const DRAG_THRESHOLD_HEIGHT_RATIO = 0.3;
    const DRAG_MODE_POINTER = 'pointer';
    const DRAG_MODE_TOUCH = 'touch';

    function getFocusableElements(container) {
        if (!container) return [];
        return Array.from(container.querySelectorAll(
            'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((el) => !el.hasAttribute('hidden') && !el.closest('[hidden]'));
    }

    class SFBottomSheetController {
        constructor(root, options = {}) {
            this.root = root;
            this.options = options || {};
            const contentSelector = this.options.contentSelector || '[data-sf-bottom-sheet-content], #sfBottomSheetContent, .sf-modal-content, .sf-library-modal-content';
            const handleSelector = this.options.handleSelector || '[data-sf-bottom-sheet-handle], .sf-bottom-sheet-handle';
            this.backdrop = root ? root.querySelector('[data-sf-bottom-sheet-backdrop], #sfBottomSheetBackdrop') : null;
            this.content = root ? root.querySelector(contentSelector) : null;
            this.handle = root ? root.querySelector(handleSelector) : null;
            this.header = root ? root.querySelector('.sf-bottom-sheet-header') : null;
            const useRootAsBackdrop = this.options.useRootAsBackdrop ?? true;
            if (!this.backdrop && useRootAsBackdrop) {
                this.backdrop = this.root;
            }
            this.isOpen = false;
            this.dragState = null;
            this.lastFocused = null;
            this.onClose = null;
            this.isVisible = (typeof this.options.isVisible === 'function') ? this.options.isVisible : null;
            this.onDismiss = (typeof this.options.onDismiss === 'function') ? this.options.onDismiss : null;

            if (!this.root || !this.content) return;

            this.boundOnKeyDown = this.onKeyDown.bind(this);
            this.boundOnPointerMove = this.onPointerMove.bind(this);
            this.boundOnPointerUp = this.onPointerUp.bind(this);
            this.boundOnTouchMove = this.onTouchMove.bind(this);
            this.boundOnTouchEnd = this.onTouchEnd.bind(this);

            if (this.backdrop) {
                this.backdrop.addEventListener('click', (event) => {
                    if (event.target !== this.backdrop) {
                        return;
                    }

                    this.close('backdrop');
                });
            }
            this.modalHeader = root ? root.querySelector('.sf-modal-header, .sf-library-modal-header, .sf-bottom-sheet-header') : null;

            this.content.addEventListener('pointerdown', (event) => this.onPointerDown(event));
            this.content.addEventListener('touchstart', (event) => this.onTouchStart(event), { passive: true });
        }

        isCurrentlyOpen() {
            return this.isVisible ? !!this.isVisible() : this.isOpen;
        }

        open(options = {}) {
            if (!this.root || !this.content) return;
            this.lastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            this.onClose = typeof options.onClose === 'function' ? options.onClose : null;
            this.isOpen = true;
            this.root.classList.add('open');
            document.body.style.overflow = 'hidden';
            document.addEventListener('keydown', this.boundOnKeyDown);

            const autofocusSelector = options.autofocusSelector || null;
            requestAnimationFrame(() => {
                const target = autofocusSelector ? this.root.querySelector(autofocusSelector) : null;
                const focusTarget = (target && typeof target.focus === 'function') ? target : this.content;
                if (focusTarget && typeof focusTarget.focus === 'function') {
                    focusTarget.focus({ preventScroll: true });
                }
            });
        }

        close(reason = 'programmatic') {
            if (!this.root || !this.content || !this.isCurrentlyOpen()) return;

            this.isOpen = false;

            const finishClose = () => {
                this.cleanupDrag();

                document.body.style.overflow = '';
                document.removeEventListener('keydown', this.boundOnKeyDown);

                if (this.onDismiss) {
                    this.onDismiss(reason);
                } else {
                    this.root.classList.remove('open');
                }

                if (this.lastFocused && typeof this.lastFocused.focus === 'function') {
                    this.lastFocused.focus({ preventScroll: true });
                }

                if (this.onClose) {
                    this.onClose(reason);
                }
            };

            if (this.onDismiss && window.matchMedia('(max-width: 768px)').matches) {
                this.content.style.transition = 'transform 0.3s cubic-bezier(0.32, 0.72, 0, 1)';
                this.content.style.transform = 'translateY(110%)';

                if (this.backdrop) {
                    this.backdrop.style.transition = 'opacity 0.22s ease';
                    this.backdrop.style.opacity = '0';
                }

                window.setTimeout(finishClose, 300);
                return;
            }

            finishClose();
        }

        onKeyDown(event) {
            if (!this.isCurrentlyOpen()) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                this.close('escape');
                return;
            }

            if (event.key !== 'Tab') return;
            const focusable = getFocusableElements(this.content);
            if (focusable.length === 0) {
                event.preventDefault();
                this.content.focus({ preventScroll: true });
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            const active = document.activeElement;

            if (event.shiftKey && active === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && active === last) {
                event.preventDefault();
                first.focus();
            }
        }

        isInteractiveDragTarget(target) {
            if (!target || typeof target.closest !== 'function') {
                return false;
            }

            return Boolean(target.closest(
                'button, a, input, select, textarea, label, [contenteditable="true"], [data-no-sheet-drag]'
            ));
        }

        isDragStartAllowed(target, clientY) {
            if (!this.content) {
                return false;
            }

            if (this.handle && this.handle.contains(target)) {
                return true;
            }

            if (this.isInteractiveDragTarget(target)) {
                return false;
            }

            if (this.modalHeader && this.modalHeader.contains(target)) {
                return true;
            }

            const rect = this.content.getBoundingClientRect();
            const distanceFromTop = clientY - rect.top;

            return distanceFromTop >= 0 && distanceFromTop <= 72;
        }
		
        onPointerDown(event) {
            if (!this.isCurrentlyOpen()) return;
            if (event.pointerType === 'mouse' && event.button !== 0) return;
            if (!this.isDragStartAllowed(event.target, event.clientY)) return;

            this.dragState = {
                mode: DRAG_MODE_POINTER,
                pointerId: event.pointerId,
                startY: event.clientY,
                currentY: event.clientY,
                startTime: performance.now(),
                captureElement: (this.content && typeof this.content.setPointerCapture === 'function')
                    ? this.content
                    : null
            };

            if (this.dragState.captureElement) {
                this.dragState.captureElement.setPointerCapture(event.pointerId);
            }

            this.content.style.transition = 'none';
            window.addEventListener('pointermove', this.boundOnPointerMove);
            window.addEventListener('pointerup', this.boundOnPointerUp);
        }

        onPointerMove(event) {
            if (!this.dragState || this.dragState.mode !== DRAG_MODE_POINTER || event.pointerId !== this.dragState.pointerId) return;
            this.dragState.currentY = event.clientY;
            const diff = Math.max(0, this.dragState.currentY - this.dragState.startY);
            this.content.style.transform = `translateY(${diff}px)`;
            if (this.backdrop) {
                const opacity = Math.max(0, 1 - (diff / BACKDROP_FADE_DISTANCE));
                this.backdrop.style.opacity = String(opacity);
            }
        }

        onPointerUp(event) {
            if (!this.dragState || this.dragState.mode !== DRAG_MODE_POINTER || event.pointerId !== this.dragState.pointerId) return;

            const diff = Math.max(0, this.dragState.currentY - this.dragState.startY);
            const elapsed = Math.max(1, performance.now() - (this.dragState.startTime || performance.now()));
            const velocity = diff / elapsed;
            const threshold = Math.min(DEFAULT_DRAG_THRESHOLD, this.content.clientHeight * DRAG_THRESHOLD_HEIGHT_RATIO);
            const shouldClose = diff >= threshold || (diff >= 34 && velocity >= 0.45);

            if (this.dragState.captureElement && typeof this.dragState.captureElement.releasePointerCapture === 'function') {
                this.dragState.captureElement.releasePointerCapture(event.pointerId);
            }

            if (shouldClose) {
                this.close('drag');
                return;
            }

            this.content.style.transition = 'transform 0.22s cubic-bezier(0.32, 0.72, 0, 1)';
            this.content.style.transform = '';

            if (this.backdrop) {
                this.backdrop.style.opacity = '';
            }

            window.setTimeout(() => {
                this.cleanupDrag();
            }, 220);
        }

        onTouchStart(event) {
            if (!this.isCurrentlyOpen() || !event.touches || event.touches.length !== 1) return;

            const touch = event.touches[0];

            if (!this.isDragStartAllowed(event.target, touch.clientY)) return;

            this.dragState = {
                mode: DRAG_MODE_TOUCH,
                startY: touch.clientY,
                currentY: touch.clientY,
                startTime: performance.now()
            };

            this.content.style.transition = 'none';

            window.addEventListener('touchmove', this.boundOnTouchMove, { passive: false });
            window.addEventListener('touchend', this.boundOnTouchEnd, { passive: true });
            window.addEventListener('touchcancel', this.boundOnTouchEnd, { passive: true });
        }

        onTouchMove(event) {
            if (!this.dragState || this.dragState.mode !== DRAG_MODE_TOUCH || !event.touches || event.touches.length !== 1) return;
            this.dragState.currentY = event.touches[0].clientY;
            const diff = Math.max(0, this.dragState.currentY - this.dragState.startY);
            if (diff > 0) {
                event.preventDefault();
            }
            this.content.style.transform = `translateY(${diff}px)`;
            if (this.backdrop) {
                const opacity = Math.max(0, 1 - (diff / BACKDROP_FADE_DISTANCE));
                this.backdrop.style.opacity = String(opacity);
            }
        }

        onTouchEnd() {
            if (!this.dragState || this.dragState.mode !== DRAG_MODE_TOUCH) return;

            const diff = Math.max(0, this.dragState.currentY - this.dragState.startY);
            const elapsed = Math.max(1, performance.now() - (this.dragState.startTime || performance.now()));
            const velocity = diff / elapsed;
            const threshold = Math.min(DEFAULT_DRAG_THRESHOLD, this.content.clientHeight * DRAG_THRESHOLD_HEIGHT_RATIO);
            const shouldClose = diff >= threshold || (diff >= 34 && velocity >= 0.45);

            if (shouldClose) {
                this.close('drag');
                return;
            }

            this.content.style.transition = 'transform 0.22s cubic-bezier(0.32, 0.72, 0, 1)';
            this.content.style.transform = '';

            if (this.backdrop) {
                this.backdrop.style.opacity = '';
            }

            window.setTimeout(() => {
                this.cleanupDrag();
            }, 220);
        }

        cleanupDrag() {
            this.resetDragStyles();
            window.removeEventListener('pointermove', this.boundOnPointerMove);
            window.removeEventListener('pointerup', this.boundOnPointerUp);
            window.removeEventListener('touchmove', this.boundOnTouchMove);
            window.removeEventListener('touchend', this.boundOnTouchEnd);
            window.removeEventListener('touchcancel', this.boundOnTouchEnd);
            this.dragState = null;
        }

        resetDragStyles() {
            if (!this.content) return;
            this.content.style.transition = '';
            this.content.style.transform = '';
            if (this.backdrop) {
                this.backdrop.style.opacity = '';
            }
        }
    }

    window.SFBottomSheetController = SFBottomSheetController;
})();