document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('previewImageModal');
    const modalImage = document.getElementById('previewModalImage');
    const modalOverlay = document.getElementById('previewModalOverlay');
    const closeBtn = document.getElementById('previewModalClose');
    const previewImages = document.querySelectorAll('.preview-image-clickable');
    
    let currentZoom = 100;
    const minZoom = 50;
    const maxZoom = 300;
    const zoomStep = 25;

    previewImages.forEach(img => {
        img.addEventListener('click', function() {
            const imageUrl = this.getAttribute('data-modal-image');
            modalImage.src = imageUrl;
            modal.classList.add('active');
            currentZoom = 100;
            updateZoomLevel();
            document.body.style.overflow = 'hidden';
        });
    });

    function closeModal() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    closeBtn.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', closeModal);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('active')) {
            closeModal();
        }
    });

    function updateZoomLevel() {
        document.getElementById('modalZoomPercent').textContent = currentZoom;
        modalImage.style.transform = 'scale(' + (currentZoom / 100) + ')';
    }

    document.getElementById('modalZoomIn').addEventListener('click', function() {
        if (currentZoom < maxZoom) {
            currentZoom = Math.min(currentZoom + zoomStep, maxZoom);
            updateZoomLevel();
        }
    });

    document.getElementById('modalZoomOut').addEventListener('click', function() {
        if (currentZoom > minZoom) {
            currentZoom = Math.max(currentZoom - zoomStep, minZoom);
            updateZoomLevel();
        }
    });

    document.getElementById('modalZoomReset').addEventListener('click', function() {
        currentZoom = 100;
        updateZoomLevel();
    });

    document.addEventListener('wheel', function(e) {
        if (modal.classList.contains('active')) {
            e.preventDefault();
            if (e.deltaY < 0) {
                currentZoom = Math.max(currentZoom - zoomStep, minZoom);
            } else {
                currentZoom = Math.min(currentZoom + zoomStep, maxZoom);
            }
            updateZoomLevel();
        }
    }, { passive: false });
});