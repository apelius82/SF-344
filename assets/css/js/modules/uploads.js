import { getters } from './state.js';
const { getEl } = getters;

function autoFitImageToFrame(img, frame) {
    if (!img || !frame) return;
    const doFit = () => {
        const frameRect = frame.getBoundingClientRect();
        const frameW = frameRect.width;
        const frameH = frameRect.height;
        const imgW = img.naturalWidth;
        const imgH = img.naturalHeight;
        if (!imgW || !imgH || !frameW || !frameH) return;
        const scale = Math.max(frameW / imgW, frameH / imgH);
        const offsetX = (frameW - imgW * scale) / 2;
        const offsetY = (frameH - imgH * scale) / 2;
        img.style.position = 'absolute';
        img.style.width = imgW + 'px';
        img.style.height = imgH + 'px';
        img.style.left = offsetX + 'px';
        img.style.top = offsetY + 'px';
        img.style.transform = `scale(${scale})`;
        img.style.transformOrigin = 'top left';
        img.style.maxWidth = 'none';
        img.style.maxHeight = 'none';
    };
    if (img.complete && img.naturalWidth) doFit(); else img.onload = doFit;
}

export function bindUploads() {
    [1, 2, 3].forEach(slot => {
        const fileInput = document.getElementById(`sf-image${slot}`);
        const previewImg = document.getElementById(`sfImageThumb${slot}`) || document.getElementById(`sf-upload-preview${slot}`);

        const getPlaceholder = (thumb) => {
    if (thumb?.dataset?.placeholder) {
        return thumb.dataset.placeholder;
    }

    if (typeof window !== 'undefined' && window.SF_BASE_URL) {
        return window.SF_BASE_URL.replace(/\/$/, '') + '/assets/img/camera-placeholder.png';
    }

    return '/assets/img/camera-placeholder.png';
};

        if (previewImg && fileInput && !previewImg.dataset.sfUploadClickBound) {
            previewImg.dataset.sfUploadClickBound = '1';
            previewImg.style.cursor = 'pointer';
            previewImg.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                if (e.target.closest('.sf-image-remove-btn, .sf-upload-remove')) return;
                fileInput.click();
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', async function () {
                const file = fileInput.files[0];
                if (!file) return;

                const thumb = document.getElementById(`sfImageThumb${slot}`) || document.getElementById(`sf-upload-preview${slot}`);
                const previewArea = thumb?.closest('.sf-image-preview') || thumb?.parentElement;

                // Näytä välittömästi paikallinen esikatselu
                const reader = new FileReader();
                reader.onload = function (e) {
                    const dataUrl = e.target.result;

                    if (thumb) {
                        thumb.src = dataUrl;
                        thumb.dataset.hasRealImage = '1';
                        thumb.parentElement?.classList.add('has-image');
                    }

                    const removeBtn = document.querySelector(`.sf-image-remove-btn[data-slot="${slot}"]`) || document.querySelector(`.sf-upload-remove[data-slot="${slot}"]`);
                    if (removeBtn) removeBtn.classList.remove('hidden');

                    const libraryInput = document.getElementById(`sfLibraryImage${slot}`);
                    if (libraryInput) libraryInput.value = '';

                    const cardImg = document.getElementById(`sfPreviewImg${slot}`);
                    const cardImgGreen = document.getElementById(`sfPreviewImg${slot}Green`);

                    if (cardImg) {
                        cardImg.src = dataUrl;
                        cardImg.dataset.hasRealImage = '1';
                    }

                    if (cardImgGreen) {
                        cardImgGreen.src = dataUrl;
                        cardImgGreen.dataset.hasRealImage = '1';
                    }

                    window.Preview?.applyGridClass?.();
                    window.PreviewTutkinta?.applyGridClass?.();

                    document.dispatchEvent(new CustomEvent('sf:image-selected', {
                        detail: { slot: slot, src: dataUrl }
                    }));
                };
                reader.readAsDataURL(file);

                // Näytä loading-indikaattori
                let loadingOverlay = previewArea?.querySelector('.sf-upload-loading');
                if (!loadingOverlay && previewArea) {
                    loadingOverlay = document.createElement('div');
                    loadingOverlay.className = 'sf-upload-loading';
                    loadingOverlay.innerHTML = `
                        <div class="sf-upload-spinner"></div>
                        <span class="sf-upload-text">Prosessoidaan...</span>
                    `;
                    previewArea.appendChild(loadingOverlay);
                }

                // Upload HETI serverille
                try {
                    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
                    if (!csrfToken) {
                        throw new Error('CSRF token not found');
                    }

                    const formData = new FormData();
                    formData.append('image', file);
                    formData.append('slot', slot);
                    formData.append('csrf_token', csrfToken);

                    const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
                    const response = await fetch(`${baseUrl}/app/api/upload_temp_image.php`, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });

                    const result = await response.json();

                    if (response.ok && result.ok) {
                        // Tallenna tiedostonimi hidden-kenttään
                        let hiddenInput = document.getElementById(`sf-temp-image${slot}`);
                        if (!hiddenInput) {
                            hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.id = `sf-temp-image${slot}`;
                            hiddenInput.name = `temp_image${slot}`;
                            document.getElementById('sf-form')?.appendChild(hiddenInput);
                        }
                        hiddenInput.value = result.filename;

                        // Näytä onnistuminen VASTA kun palvelin on palannut
                        if (loadingOverlay) {
                            loadingOverlay.innerHTML = `<span class="sf-upload-success">Valmis</span>`;
                            setTimeout(() => {
                                loadingOverlay.remove();
                                // Toast removed: it will be shown after crop editor saves
                            }, 800);
                        }
                    } else {
                        throw new Error(result.error || 'Upload failed');
                    }
                } catch (err) {
                    console.error('Image upload error:', err);
                    // Näytä virhe
                    if (loadingOverlay) {
                        loadingOverlay.innerHTML = `<span class="sf-upload-error">Virhe!</span>`;
                        setTimeout(() => loadingOverlay.remove(), 2000);
                    }
                }
            });
        }

        const removeBtn = document.querySelector(`.sf-image-remove-btn[data-slot="${slot}"]`) || document.querySelector(`.sf-upload-remove[data-slot="${slot}"]`);
        if (removeBtn) {
            removeBtn.addEventListener('click', async function (e) {
                e.preventDefault(); e.stopPropagation();

                // Hae tallennettu tiedostonimi
                const hiddenInput = document.getElementById(`sf-temp-image${slot}`);
                const filename = hiddenInput?.value;

                // Poista serveriltä jos löytyy
                if (filename) {
                    try {
                        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
                        if (!csrfToken) {
                            console.warn('CSRF token not found, skipping server cleanup');
                        } else {
                            const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
                            await fetch(`${baseUrl}/app/api/delete_temp_image.php`, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: `filename=${encodeURIComponent(filename)}&csrf_token=${encodeURIComponent(csrfToken)}`,
                                credentials: 'same-origin'
                            });
                        }
                    } catch (err) {
                        console.warn('Failed to delete temp image:', err);
                    }
                    hiddenInput.value = '';
                }

                if (fileInput) fileInput.value = '';
                const libraryInput = document.getElementById(`sfLibraryImage${slot}`);
                if (libraryInput) libraryInput.value = '';
                const thumb = document.getElementById(`sfImageThumb${slot}`) || document.getElementById(`sf-upload-preview${slot}`);
                const placeholder = getPlaceholder(thumb);
                if (thumb) {
                    thumb.src = placeholder;
                    thumb.dataset.hasRealImage = '0';
                    thumb.parentElement?.classList.remove('has-image');
                }
                this.classList.add('hidden');
                const cardImg = document.getElementById(`sfPreviewImg${slot}`);
                if (cardImg) { cardImg.src = placeholder; cardImg.dataset.hasRealImage = '0'; }
                const cardImgGreen = document.getElementById(`sfPreviewImg${slot}Green`);
                if (cardImgGreen) { cardImgGreen.src = placeholder; cardImgGreen.dataset.hasRealImage = '0'; }
                // Reset editor data for this slot (transform + baked data + annotations)
                const transformInput = document.getElementById(`sf-image${slot}-transform`);
                if (transformInput) transformInput.value = '';

                const editedInput = document.getElementById(`sf-image${slot}-edited-data`);
                if (editedInput) editedInput.value = '';

                const annoStoreEl = document.getElementById('sf-edit-annotations-data');
                if (annoStoreEl) {
                    let store = {};
                    try { store = JSON.parse(annoStoreEl.value || '{}'); } catch (e) { store = {}; }
                    const key = `image${slot}`;
                    if (store && typeof store === 'object') {
                        delete store[key];
                    }
                    annoStoreEl.value = JSON.stringify(store || {});
                }

                // Tell other modules (edit-flow + grid) that an image was removed
                document.dispatchEvent(new CustomEvent('sf:image-removed', { detail: { slot } }));

                if (window.Preview?.state) {
                    window.Preview.state[slot] = { x: 0, y: 0, scale: 1 };
                    window.Preview.applyGridClass?.();
                }
                if (window.PreviewTutkinta?.state) {
                    window.PreviewTutkinta.state[slot] = { x: 0, y: 0, scale: 1 };
                    window.PreviewTutkinta.applyGridClass?.();
                }
            });
        }
    });

    // Issue 5: Bind camera capture inputs separately
    // Camera inputs don't have IDs, so we need to select them by name attribute
    [1, 2, 3].forEach(slot => {
        const cameraInput = document.querySelector(`input[name="image${slot}_camera"]`);
        if (cameraInput && !cameraInput.dataset.sfCameraBound) {
            cameraInput.dataset.sfCameraBound = '1';

            cameraInput.addEventListener('change', async function () {
                const file = cameraInput.files[0];
                if (!file) return;

                const thumb = document.getElementById(`sfImageThumb${slot}`) || document.getElementById(`sf-upload-preview${slot}`);
                const previewArea = thumb?.closest('.sf-image-preview') || thumb?.parentElement;

                // Show immediate local preview
                const reader = new FileReader();
                reader.onload = function (e) {
                    const dataUrl = e.target.result;

                    if (thumb) {
                        thumb.src = dataUrl;
                        thumb.dataset.hasRealImage = '1';
                        thumb.parentElement?.classList.add('has-image');
                    }

                    const removeBtn = document.querySelector(`.sf-image-remove-btn[data-slot="${slot}"]`) || document.querySelector(`.sf-upload-remove[data-slot="${slot}"]`);
                    if (removeBtn) removeBtn.classList.remove('hidden');

                    const libraryInput = document.getElementById(`sfLibraryImage${slot}`);
                    if (libraryInput) libraryInput.value = '';

                    const cardImg = document.getElementById(`sfPreviewImg${slot}`);
                    const cardImgGreen = document.getElementById(`sfPreviewImg${slot}Green`);

                    if (cardImg) {
                        cardImg.src = dataUrl;
                        cardImg.dataset.hasRealImage = '1';
                    }

                    if (cardImgGreen) {
                        cardImgGreen.src = dataUrl;
                        cardImgGreen.dataset.hasRealImage = '1';
                    }

                    window.Preview?.applyGridClass?.();
                    window.PreviewTutkinta?.applyGridClass?.();

                    document.dispatchEvent(new CustomEvent('sf:image-selected', {
                        detail: { slot: slot, src: dataUrl }
                    }));
                };
                reader.readAsDataURL(file);

                // Show loading indicator
                let loadingOverlay = previewArea?.querySelector('.sf-upload-loading');
                if (!loadingOverlay && previewArea) {
                    loadingOverlay = document.createElement('div');
                    loadingOverlay.className = 'sf-upload-loading';
                    loadingOverlay.innerHTML = `
                        <div class="sf-upload-spinner"></div>
                        <span class="sf-upload-text">Prosessoidaan...</span>
                    `;
                    previewArea.appendChild(loadingOverlay);
                }

                // Upload to server
                const formData = new FormData();
                formData.append('image', file);
                formData.append('slot', slot);

                const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }

                try {
                    const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
                    const response = await fetch(`${baseUrl}/app/api/upload_temp_image.php`, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });

                    const result = await response.json();

                    if (response.ok && result.ok) {
                        if (loadingOverlay) {
                            loadingOverlay.innerHTML = '<span class="sf-upload-success">Valmis</span>';
                            setTimeout(() => loadingOverlay.remove(), 800);
                        }

                        let hiddenInput = document.getElementById(`sf-temp-image${slot}`);
                        if (!hiddenInput) {
                            hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.id = `sf-temp-image${slot}`;
                            hiddenInput.name = `temp_image${slot}`;
                            document.getElementById('sf-form')?.appendChild(hiddenInput);
                        }

                        hiddenInput.value = result.filename;

                        document.dispatchEvent(new CustomEvent('sf:image-uploaded', {
                            detail: { slot: slot, path: result.url, filename: result.filename }
                        }));
                    } else {
                        throw new Error(result.error || 'Upload failed');
                    }
                } catch (error) {
                    console.error('Upload error:', error);
                    if (loadingOverlay) {
                        loadingOverlay.innerHTML = '<span class="sf-upload-error">Virhe latauksessa</span>';
                        setTimeout(() => loadingOverlay.remove(), 2000);
                    }
                }
            });
        }
    });
}