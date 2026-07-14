// sf-grid-step.js
(function () {
    const el = (id) => document.getElementById(id);

    /**
     * Upload a base64 data URL to the server as a temp grid file.
     * Returns the server filename on success, or null on failure.
     * Falls back gracefully so the caller can keep the base64 value if needed.
     */
    async function uploadTempGrid(dataUrl) {
        try {
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
            if (!csrfToken || !dataUrl || typeof dataUrl !== 'string') {
                return null;
            }

            const matches = dataUrl.match(/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/);
            if (!matches) {
                console.warn('[Grid] Invalid data URL format');
                return null;
            }

            const mimeType = matches[1];
            const base64Data = matches[2];

            const byteString = atob(base64Data);
            const byteLength = byteString.length;
            const byteArray = new Uint8Array(byteLength);

            for (let i = 0; i < byteLength; i++) {
                byteArray[i] = byteString.charCodeAt(i);
            }

            const extensionMap = {
                'image/png': 'png',
                'image/jpeg': 'jpg',
                'image/jpg': 'jpg',
                'image/gif': 'gif',
                'image/webp': 'webp',
            };

            const extension = extensionMap[mimeType] || 'png';
            const blob = new Blob([byteArray], { type: mimeType });

            const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
            const formData = new FormData();
            formData.append('grid_image', blob, `grid_bitmap.${extension}`);
            formData.append('csrf_token', csrfToken);

            const uploadResponse = await fetch(`${baseUrl}/app/api/upload_temp_grid.php`, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });

            if (!uploadResponse.ok) {
                console.warn('[Grid] Temp upload failed with status:', uploadResponse.status);
                return null;
            }

            const result = await uploadResponse.json();
            if (result && result.ok && result.filename) {
                return result.filename;
            }

            console.warn('[Grid] Temp upload returned unexpected response:', result);
            return null;
        } catch (e) {
            console.warn('[Grid] Temp upload failed, using base64 fallback:', e);
            return null;
        }
    }

    window.SF_GRID_UPLOAD_TEMP = uploadTempGrid;

    const CANVAS_W = 1080;
    const CANVAS_H = 1080;

    // Ei turvamarginaaleja - kuva täyttää koko alueen
    const PAD = 0;
    const GAP = 12;
    const RADIUS = 24;

    // Pääkuvan hienosäätö neliöbitmapiin:
    // - hieman lisää kokoa
    // - hieman alemmas ruudussa
    const MAIN_IMAGE_ZOOM = 1.14;
    const MAIN_IMAGE_FOCUS_X = 0.5;
    const MAIN_IMAGE_FOCUS_Y = 0.30;

    function t(key, fallback) {
        if (window.SF_I18N && window.SF_I18N[key]) return window.SF_I18N[key];
        return fallback || key;
    }

    function getOptionsByCount(count) {
        const L = (upperKey, lowerKey, fallback) => t(upperKey, t(lowerKey, fallback));

        if (count === 1) {
            return [{ id: "grid-1", label: L("GRID_LAYOUT_1", "grid_layout_1", "Layout 1") }];
        }
        if (count === 2) {
            return [
                { id: "grid-2a", label: L("GRID_LAYOUT_2A", "grid_layout_2a", "Layout 1") },
                { id: "grid-2b", label: L("GRID_LAYOUT_2B", "grid_layout_2b", "Layout 2") },
            ];
        }
        if (count === 3) {
            return [
                { id: "grid-3a", label: L("GRID_LAYOUT_3A", "grid_layout_3a", "Layout 1") },
                { id: "grid-3b", label: L("GRID_LAYOUT_3B", "grid_layout_3b", "Layout 2") },
                { id: "grid-3c", label: L("GRID_LAYOUT_3C", "grid_layout_3c", "Layout 3") },
            ];
        }
        return [];
    }

    function roundRectPath(ctx, x, y, w, h, r) {
        const rr = Math.max(0, Math.min(r, Math.floor(Math.min(w, h) / 2)));
        ctx.beginPath();
        ctx.moveTo(x + rr, y);
        ctx.arcTo(x + w, y, x + w, y + h, rr);
        ctx.arcTo(x + w, y + h, x, y + h, rr);
        ctx.arcTo(x, y + h, x, y, rr);
        ctx.arcTo(x, y, x + w, y, rr);
        ctx.closePath();
    }

    function drawImageCover(ctx, img, x, y, w, h, options = {}) {
        const iw = img.naturalWidth || img.width;
        const ih = img.naturalHeight || img.height;
        if (!iw || !ih) return;

        const zoom = typeof options.zoom === "number" ? options.zoom : 1;
        const focusX = typeof options.focusX === "number" ? options.focusX : 0.5;
        const focusY = typeof options.focusY === "number" ? options.focusY : 0.5;

        const scale = Math.max(w / iw, h / ih) * zoom;
        const dw = iw * scale;
        const dh = ih * scale;

        const overflowX = Math.max(0, dw - w);
        const overflowY = Math.max(0, dh - h);

        const dx = x - overflowX * focusX;
        const dy = y - overflowY * focusY;

        ctx.save();
        ctx.beginPath();
        ctx.rect(x, y, w, h);
        ctx.clip();
        ctx.drawImage(img, dx, dy, dw, dh);
        ctx.restore();
    }

    function drawSlot(ctx, img, slot) {
        const { x, y, w, h, r, border = 0, shadow = false } = slot;

        ctx.save();

        if (border > 0) {
            if (shadow) {
                ctx.save();
                ctx.shadowColor = "rgba(15, 23, 42, 0.18)";
                ctx.shadowBlur = 18;
                ctx.shadowOffsetX = 0;
                ctx.shadowOffsetY = 8;

                roundRectPath(ctx, x, y, w, h, r);
                ctx.fillStyle = "#ffffff";
                ctx.fill();

                ctx.restore();
            } else {
                roundRectPath(ctx, x, y, w, h, r);
                ctx.fillStyle = "#ffffff";
                ctx.fill();
            }
        }

        const inset = border > 0 ? border : 0;
        const innerX = x + inset;
        const innerY = y + inset;
        const innerW = w - inset * 2;
        const innerH = h - inset * 2;
        const innerR = Math.max(0, r - Math.round(inset / 2));

        roundRectPath(ctx, innerX, innerY, innerW, innerH, innerR);
        ctx.clip();
        drawImageCover(ctx, img, innerX, innerY, innerW, innerH, {
            zoom: slot.zoom,
            focusX: slot.focusX,
            focusY: slot.focusY,
        });

        ctx.restore();
    }

    function getLayout(layoutId) {
        // Kuva-alue on neliö (1080x1080) - vastaa esikatselun kuva-aluetta
        const area = {
            x: PAD,
            y: PAD,
            w: CANVAS_W - PAD * 2,
            h: CANVAS_H - PAD * 2,
        };

        const mainImageProps = {
            zoom: MAIN_IMAGE_ZOOM,
            focusX: MAIN_IMAGE_FOCUS_X,
            focusY: MAIN_IMAGE_FOCUS_Y,
        };

        // 1 kuva: yksi iso ruutu
        if (layoutId === "grid-1") {
            return {
                slots: [
                    { key: "main", z: 1, x: area.x, y: area.y, w: area.w, h: area.h, r: RADIUS, ...mainImageProps },
                ],
            };
        }

        // 2 kuvaa, asettelu 1 (overlay): iso + pieni oikeaan alakulmaan
        if (layoutId === "grid-2a") {
            const oS = Math.round(area.w * 0.35);
            const margin = Math.round(area.w * 0.03);

            return {
                slots: [
                    { key: "main", z: 1, x: area.x, y: area.y, w: area.w, h: area.h, r: RADIUS, ...mainImageProps },
                    {
                        key: "img2",
                        z: 2,
                        x: area.x + area.w - oS - margin,
                        y: area.y + area.h - oS - margin,
                        w: oS,
                        h: oS,
                        r: Math.round(RADIUS * 0.85),
                        border: 10,
                        shadow: true,
                    },
                ],
            };
        }

        // 2 kuvaa, asettelu 2 (päällekkäin): kaksi vaakaruutua päällekkäin
        if (layoutId === "grid-2b") {
            const totalH = area.h;
            const h1 = Math.floor((totalH - GAP) / 2);
            const h2 = totalH - GAP - h1;
            const slotW = area.w;
            const slotX = area.x;

            return {
                slots: [
                    { key: "main", z: 1, x: slotX, y: area.y, w: slotW, h: h1, r: RADIUS, ...mainImageProps },
                    { key: "img2", z: 1, x: slotX, y: area.y + h1 + GAP, w: slotW, h: h2, r: RADIUS },
                ],
            };
        }

        // 3 kuvaa, asettelu 1: iso ylhäällä + 2 ruutua alhaalla vierekkäin
        if (layoutId === "grid-3a") {
            const topH = Math.round(area.h * 0.62);
            const bottomH = area.h - topH - GAP;
            const bottomW = Math.round((area.w - GAP) / 2);
            return {
                slots: [
                    { key: "main", z: 1, x: area.x, y: area.y, w: area.w, h: topH, r: RADIUS, ...mainImageProps },
                    {
                        key: "img2",
                        z: 1,
                        x: area.x,
                        y: area.y + topH + GAP,
                        w: bottomW,
                        h: bottomH,
                        r: RADIUS,
                        border: 8,
                        shadow: true,
                    },
                    {
                        key: "img3",
                        z: 1,
                        x: area.x + bottomW + GAP,
                        y: area.y + topH + GAP,
                        w: area.w - bottomW - GAP,
                        h: bottomH,
                        r: RADIUS,
                        border: 8,
                        shadow: true,
                    },
                ],
            };
        }

        // 3 kuvaa, asettelu 2: iso tausta + 2 overlay-ruutua alareunaan
        if (layoutId === "grid-3b") {
            const smallS = Math.round(area.w * 0.28);
            const largeS = Math.round(area.w * 0.38);
            const margin = Math.round(area.w * 0.03);

            return {
                slots: [
                    { key: "main", z: 1, x: area.x, y: area.y, w: area.w, h: area.h, r: RADIUS, ...mainImageProps },
                    {
                        key: "img3",
                        z: 2,
                        x: area.x + margin,
                        y: area.y + area.h - smallS - margin,
                        w: smallS,
                        h: smallS,
                        r: Math.round(RADIUS * 0.8),
                        border: 8,
                        shadow: true,
                    },
                    {
                        key: "img2",
                        z: 3,
                        x: area.x + area.w - largeS - margin,
                        y: area.y + area.h - largeS - margin,
                        w: largeS,
                        h: largeS,
                        r: Math.round(RADIUS * 0.85),
                        border: 10,
                        shadow: true,
                    },
                ],
            };
        }

        // 3 kuvaa, asettelu 3: iso vasemmalla + 2 ruutua oikealla päällekkäin
        if (layoutId === "grid-3c") {
            const leftW = Math.round(area.w * 0.58);
            const rightW = area.w - leftW - GAP;
            const rightH1 = Math.round((area.h - GAP) / 2);
            const rightH2 = area.h - GAP - rightH1;

            return {
                slots: [
                    { key: "main", z: 1, x: area.x, y: area.y, w: leftW, h: area.h, r: RADIUS, ...mainImageProps },
                    {
                        key: "img2",
                        z: 1,
                        x: area.x + leftW + GAP,
                        y: area.y,
                        w: rightW,
                        h: rightH1,
                        r: RADIUS,
                        border: 8,
                        shadow: true,
                    },
                    {
                        key: "img3",
                        z: 1,
                        x: area.x + leftW + GAP,
                        y: area.y + rightH1 + GAP,
                        w: rightW,
                        h: rightH2,
                        r: RADIUS,
                        border: 8,
                        shadow: true,
                    },
                ],
            };
        }

        // fallback
        return {
            slots: [
                { key: "main", z: 1, x: area.x, y: area.y, w: area.w, h: area.h, r: RADIUS, ...mainImageProps },
            ],
        };
    }

    async function generateGridBitmap(layoutId, images) {
        const canvas = document.createElement("canvas");
        canvas.width = CANVAS_W;
        canvas.height = CANVAS_H;

        const ctx = canvas.getContext("2d");
        ctx.clearRect(0, 0, CANVAS_W, CANVAS_H);

        // Pyöristä koko bitmapin ulkoreunat
        const outerRadius = 24;
        ctx.beginPath();
        ctx.moveTo(outerRadius, 0);
        ctx.arcTo(CANVAS_W, 0, CANVAS_W, CANVAS_H, outerRadius);
        ctx.arcTo(CANVAS_W, CANVAS_H, 0, CANVAS_H, outerRadius);
        ctx.arcTo(0, CANVAS_H, 0, 0, outerRadius);
        ctx.arcTo(0, 0, CANVAS_W, 0, outerRadius);
        ctx.closePath();
        ctx.clip();

        const layout = getLayout(layoutId);

        const map = {
            main: images[0],
            img2: images[1],
            img3: images[2],
        };

        const slots = [...layout.slots].sort((a, b) => (a.z || 1) - (b.z || 1));
        for (const slot of slots) {
            const img = map[slot.key];
            if (!img) continue;
            drawSlot(ctx, img, slot);
        }

        try {
            return canvas.toDataURL("image/png");
        } catch (e) {
            console.warn('[Grid] canvas.toDataURL failed (possibly tainted canvas):', e);
            return null;
        }
    }

    async function loadImage(src) {
        return new Promise((resolve) => {
            const img = new Image();
            img.crossOrigin = "anonymous";

            img.onload = () => resolve(img);
            img.onerror = () => {
                console.warn("[Grid] Image failed to load, using 1x1 fallback:", src);

                const fallback = new Image();
                fallback.crossOrigin = "anonymous";
                fallback.onload = () => resolve(fallback);
                fallback.onerror = () => resolve(fallback);
                fallback.src =
                    "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMB/6XKq4cAAAAASUVORK5CYII=";
            };

            img.src = src;
        });
    }

    function getEditedImageForSlot(slot) {
        const editedInput = document.getElementById(`sf-image${slot}-edited-data`);
        if (editedInput && editedInput.value && editedInput.value.startsWith('data:')) {
            return editedInput.value;
        }
        return null;
    }

    function setSelectedLayout(layoutId) {
        const input = el("sf-grid-layout");
        if (input) input.value = layoutId;

        document.querySelectorAll(".sf-grid-card").forEach((btn) => {
            btn.classList.toggle("active", btn.dataset.layout === layoutId);
        });
    }

async function renderGridOptions(selectedCount, imageUrls, initOptions = {}) {
    const forceRegenerate = !!initOptions.forceRegenerate;
    const wrap = el("sfGridPicker");
    if (!wrap) return;

    const options = getOptionsByCount(selectedCount);
        wrap.innerHTML = "";
        wrap.dataset.count = String(selectedCount);

        const imgs = [];
        for (let i = 0; i < imageUrls.length; i++) {
            const item = imageUrls[i];
            const url = typeof item === 'string' ? item : item?.url;
            const slot = typeof item === 'string' ? i + 1 : item?.originalSlot;

            if (!url || !slot) {
                continue;
            }

            const editedData = getEditedImageForSlot(slot);
            const srcToUse = editedData || url;

            imgs.push(await loadImage(srcToUse));
        }

        for (const opt of options) {
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "sf-grid-card";
            btn.dataset.layout = opt.id;

            const previewWrap = document.createElement("div");
            previewWrap.className = "sf-grid-card-preview";

            const thumb = document.createElement("img");
            thumb.className = "sf-grid-card-thumb";
            thumb.alt = opt.label;

            const dataUrl = await generateGridBitmap(opt.id, imgs);
            thumb.src = dataUrl;

            const badge = document.createElement("span");
            badge.className = "sf-grid-card-badge";
            badge.textContent = t("GRID_SELECTED", "Selected");

            previewWrap.appendChild(thumb);
            previewWrap.appendChild(badge);

            const meta = document.createElement("div");
            meta.className = "sf-grid-card-meta";

            const label = document.createElement("div");
            label.className = "sf-grid-card-label";
            label.textContent = opt.label;

            const hint = document.createElement("div");
            hint.className = "sf-grid-card-hint";
            hint.textContent = t("GRID_SELECT_HINT", "Click to select this layout");

            meta.appendChild(label);
            meta.appendChild(hint);

            btn.appendChild(previewWrap);
            btn.appendChild(meta);

            btn.addEventListener("click", async () => {
                setSelectedLayout(opt.id);

                const finalDataUrl = await generateGridBitmap(opt.id, imgs);
                const out = el("sf-grid-bitmap");
                if (out) {
                    // Try to upload to server; fall back to base64 if upload fails
                    const tempFilename = await uploadTempGrid(finalDataUrl);
                    out.value = tempFilename || finalDataUrl;
                    // Keep base64 in a data attribute so preview-server.js can use it
                    if (tempFilename) {
                        out.dataset.gridBitmapBase64 = finalDataUrl;
                    } else {
                        delete out.dataset.gridBitmapBase64;
                    }

                    out.dispatchEvent(new Event("input", { bubbles: true }));
                    out.dispatchEvent(new Event("change", { bubbles: true }));
                }
            });

            wrap.appendChild(btn);
        }

        const input = el("sf-grid-layout");
        const current = (input && input.value) ? input.value : "";

        // Check if current selection is valid for this image count
        const validOptions = options.map(o => o.id);
        const isCurrentValid = current && validOptions.includes(current);

        // Auto-select first option if no valid selection exists
        const chosen = isCurrentValid ? current : (options[0] ? options[0].id : "");

        if (chosen) {
            setSelectedLayout(chosen);

            // Only regenerate the bitmap if we have real images.
            // If all images resolved to 1x1 fallbacks (e.g. due to load failures or
            // a tainted canvas from cross-origin caching), preserve the existing value
            // so a valid stored bitmap is not overwritten with a blank placeholder.
            const hasRealImages = imgs.some(img => img && img.naturalWidth > 1 && img.naturalHeight > 1);
            const out = el("sf-grid-bitmap");
            const existingBitmapValue = out ? (out.value || '').trim() : '';

            // If the existing value is a valid persisted filename (not empty, not a base64
            // data URL, and not a temporary upload), preserve it on initial page load.
            // The bitmap will only be regenerated when the user explicitly clicks a layout card.
            const isPersistedFilename = existingBitmapValue
                && !existingBitmapValue.startsWith('data:image')
                && !existingBitmapValue.startsWith('temp_grid_');
            if (isPersistedFilename && !forceRegenerate) {
                console.log('[Grid] Preserving existing persisted grid bitmap filename:', existingBitmapValue);
                return;
            }

            if (!hasRealImages && existingBitmapValue) {
                console.log('[Grid] Preserving existing grid bitmap – no real images available');
                return;
            }

            const finalDataUrl = await generateGridBitmap(chosen, imgs);
            if (out && finalDataUrl !== null) {
                // Try to upload to server; fall back to base64 if upload fails
                const tempFilename = await uploadTempGrid(finalDataUrl);
                out.value = tempFilename || finalDataUrl;
                // Keep base64 in a data attribute so preview-server.js can use it
                if (tempFilename) {
                    out.dataset.gridBitmapBase64 = finalDataUrl;
                } else {
                    delete out.dataset.gridBitmapBase64;
                }

                // TÄRKEÄ: laukaise eventit, jotta ServerPreview huomaa muutoksen heti
                out.dispatchEvent(new Event('input', { bubbles: true }));
                out.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }

    function getSelectedMainImageSlot() {
        const input = el('sf-main-image-slot');
        const value = Number(input?.value || 1);

        return [1, 2, 3].includes(value) ? value : 1;
    }

    function orderImagesBySelectedMainSlot(imageUrls) {
        const urls = Array.isArray(imageUrls) ? imageUrls : [];
        const selectedSlot = getSelectedMainImageSlot();

        const imageItems = urls
            .map((item, index) => {
                if (typeof item === 'string') {
                    return {
                        url: item,
                        originalSlot: index + 1
                    };
                }

                return {
                    url: item?.url || '',
                    originalSlot: Number(item?.originalSlot || index + 1)
                };
            })
            .filter(item => !!item.url);

        if (selectedSlot === 1) {
            return imageItems;
        }

        const selectedItem = imageItems.find(item => item.originalSlot === selectedSlot);

        if (!selectedItem) {
            return imageItems;
        }

        return [
            selectedItem,
            ...imageItems.filter(item => item.originalSlot !== selectedSlot)
        ];
    }

    function isGridPlaceholderImage(src) {
        if (!src) return true;

        const s = String(src).toLowerCase();

        if (s.includes('camera-placeholder')) return true;
        if (s.includes('placeholder')) return true;
        if (s.includes('no-image')) return true;
        if (s === '' || s === 'about:blank') return true;
        if (s.startsWith('data:') && s.length < 100) return true;

        return false;
    }

    function getCurrentGridImageItems() {
        const items = [];

        const imageElements = [
            el('sfImageThumb1') || el('sf-upload-preview1'),
            el('sfImageThumb2') || el('sf-upload-preview2'),
            el('sfImageThumb3') || el('sf-upload-preview3')
        ];

        imageElements.forEach((img, index) => {
            const slot = index + 1;
            const existingField = el(`sf-existing-image-${slot}`);
            const editedField = el(`sf-image${slot}-edited-data`);

            if (editedField && editedField.value && editedField.value.startsWith('data:')) {
                items.push({
                    url: editedField.value,
                    originalSlot: slot
                });
                return;
            }

            if (img && img.src && !isGridPlaceholderImage(img.src)) {
                items.push({
                    url: img.src,
                    originalSlot: slot
                });
                return;
            }

            if (existingField && existingField.value && existingField.value.trim() !== '') {
                items.push({
                    url: existingField.value.trim(),
                    originalSlot: slot
                });
            }
        });

        return items;
    }

    function getGridImageSignature(imageItems) {
        return (Array.isArray(imageItems) ? imageItems : [])
            .map((item, index) => {
                if (typeof item === 'string') {
                    return `${index + 1}:${item.trim()}`;
                }

                return `${Number(item?.originalSlot || index + 1)}:${String(item?.url || '').trim()}`;
            })
            .filter(value => value !== '')
            .join('|');
    }

    window.SF_GRID_STEP_INIT = async function (selectedCount, imageUrls, options = {}) {
        try {
            const normalizedItems = Array.isArray(imageUrls) ? imageUrls.filter(Boolean) : [];
            const orderedImageItems = orderImagesBySelectedMainSlot(normalizedItems);
            const currentSignature = getGridImageSignature(orderedImageItems);
            const out = el('sf-grid-bitmap');
            const previousSignature = out ? (out.dataset.imageSignature || '') : '';

            const shouldForceRegenerate =
                !!options.forceRegenerate ||
                (previousSignature !== '' && previousSignature !== currentSignature);

            await renderGridOptions(selectedCount, orderedImageItems, {
                ...options,
                forceRegenerate: shouldForceRegenerate
            });

            if (out) {
                out.dataset.imageSignature = currentSignature;
            }
        } catch (e) {
            console.error('Grid step init failed:', e);
        }
    };

function reRenderGridForImageChange() {
    const imageItems = getCurrentGridImageItems();
    const count = imageItems.length || 1;

    const out = el('sf-grid-bitmap');
    if (out) {
        out.value = '';
        out.dataset.imageSignature = '';
        delete out.dataset.gridBitmapBase64;

        out.dispatchEvent(new Event('input', { bubbles: true }));
        out.dispatchEvent(new Event('change', { bubbles: true }));
    }

    window.SF_GRID_NEEDS_REGENERATE = true;

    const gridPicker = el('sfGridPicker');
    if (!gridPicker || !gridPicker.offsetParent) {
        return;
    }

    window.SF_GRID_NEEDS_REGENERATE = false;
    window.SF_GRID_STEP_INIT(count, imageItems, { forceRegenerate: true });
}

    document.addEventListener('sf:image-selected', reRenderGridForImageChange);
    document.addEventListener('sf:image-removed', reRenderGridForImageChange);
    document.addEventListener('sf:main-image-changed', reRenderGridForImageChange);
    document.addEventListener('sf:image-edited', reRenderGridForImageChange);
})();