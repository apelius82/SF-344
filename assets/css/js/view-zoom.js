/**
 * Preview Zoom Functionality for View Modal
 * Handles zoom in/out, keyboard shortcuts, mouse wheel, and touch gestures
 */

class PreviewZoom {
    constructor() {
        this.currentZoom = 100;
        this.minZoom = 50;
        this.maxZoom = 400;
        this.zoomStep = 10;
        this.container = document.getElementById('previewImageContainer');
        this.image = document.getElementById('previewModalImage');
        this.zoomPercentDisplay = document.getElementById('modalZoomPercent');

        if (!this.container || !this.image || !this.zoomPercentDisplay) {
            console.warn('Preview zoom: Required elements not found');
            return;
        }

        this.init();
    }

    init() {
        this.setupButtons();
        this.setupMouseWheel();
        this.setupTouchZoom();
        this.setupKeyboardShortcuts();
        this.updateDisplay();
    }

    setupButtons() {
        const zoomInBtn = document.getElementById('modalZoomIn');
        const zoomOutBtn = document.getElementById('modalZoomOut');
        const zoomResetBtn = document.getElementById('modalZoomReset');

        if (zoomInBtn) zoomInBtn.addEventListener('click', () => this.zoomIn());
        if (zoomOutBtn) zoomOutBtn.addEventListener('click', () => this.zoomOut());
        if (zoomResetBtn) zoomResetBtn.addEventListener('click', () => this.reset());
    }

    setupMouseWheel() {
        this.container.addEventListener('wheel', (e) => {
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();

                if (e.deltaY < 0) {
                    this.zoomIn();
                } else {
                    this.zoomOut();
                }
            }
        }, { passive: false });
    }

    setupTouchZoom() {
        let lastDistance = 0;

        this.container.addEventListener('touchmove', (e) => {
            if (e.touches.length === 2) {
                e.preventDefault();

                const touch1 = e.touches[0];
                const touch2 = e.touches[1];
                const distance = Math.hypot(
                    touch2.clientX - touch1.clientX,
                    touch2.clientY - touch1.clientY
                );

                if (lastDistance > 0) {
                    if (distance > lastDistance) {
                        this.zoomIn();
                    } else {
                        this.zoomOut();
                    }
                }

                lastDistance = distance;
            }
        }, { passive: false });

        this.container.addEventListener('touchend', () => {
            lastDistance = 0;
        });
    }

    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            const previewModal = document.getElementById('previewImageModal');
            if (!previewModal || previewModal.classList.contains('hidden')) {
                return;
            }

            if ((e.ctrlKey || e.metaKey) && (e.key === '+' || e.key === '=')) {
                e.preventDefault();
                this.zoomIn();
            }

            if ((e.ctrlKey || e.metaKey) && e.key === '-') {
                e.preventDefault();
                this.zoomOut();
            }

            if ((e.ctrlKey || e.metaKey) && e.key === '0') {
                e.preventDefault();
                this.reset();
            }
        });
    }

    zoomIn() {
        const newZoom = Math.min(this.currentZoom + this.zoomStep, this.maxZoom);
        this.setZoom(newZoom);
    }

    zoomOut() {
        const newZoom = Math.max(this.currentZoom - this.zoomStep, this.minZoom);
        this.setZoom(newZoom);
    }

    reset() {
        this.setZoom(100);
    }

    setZoom(value) {
        this.currentZoom = Math.max(this.minZoom, Math.min(value, this.maxZoom));
        const scale = this.currentZoom / 100;
        this.image.style.transform = `scale(${scale})`;
        this.updateDisplay();
    }

    updateDisplay() {
        this.zoomPercentDisplay.textContent = this.currentZoom;

        const zoomOutBtn = document.getElementById('modalZoomOut');
        const zoomInBtn = document.getElementById('modalZoomIn');

        if (zoomOutBtn) zoomOutBtn.disabled = this.currentZoom <= this.minZoom;
        if (zoomInBtn) zoomInBtn.disabled = this.currentZoom >= this.maxZoom;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    window.previewZoomInstance = new PreviewZoom();
});