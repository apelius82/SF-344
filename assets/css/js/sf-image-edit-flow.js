/**
 * assets/js/sf-image-edit-flow.js
 * - Opens the image edit step immediately after selecting an image (upload or library)
 * - Saves edited bitmap (dataURL) + metadata into hidden fields
 */
(function () {
    'use strict';

    // Configuration constants
    const IMAGE_LOAD_TIMEOUT_MS = 30000; // 30 seconds

    // === Inline annotation toolbar (canvas overlay) ===
    function ensureAnnoToolbar() {
        // wrap voi puuttua serverin näkymässä -> toolbar tehdään silti
        const wrap = document.getElementById('sf-edit-img-canvas-wrap') || null;

        let tb = document.getElementById('sfAnnoToolbar');
        if (tb) return tb;

        // styles once
        if (!document.getElementById('sfAnnoToolbarStyles')) {
            const st = document.createElement('style');
            st.id = 'sfAnnoToolbarStyles';
            st.textContent = `
#sfAnnoToolbar{
  position:fixed;
  z-index:100005;
  display:flex;
  gap:6px;
  padding:8px 10px;
  background:rgba(15,23,42,.86);
  border:1px solid rgba(255,255,255,.14);
  border-radius:12px;
  box-shadow:0 10px 28px rgba(0,0,0,.22);
  transform: translate(-50%, -100%);
  backdrop-filter: blur(8px);
  pointer-events:auto;
}
#sfAnnoToolbar .sf-atb-btn{
  width:38px;height:38px;
  border-radius:10px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.07);
  color:#fff;
  font-size:17px;
  line-height:1;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  padding:0;
}
#sfAnnoToolbar .sf-atb-btn:active{transform: translateY(1px);}
#sfAnnoToolbar .sf-atb-btn[data-act="delete"]{
  background:rgba(239,68,68,.22);
  border-color:rgba(239,68,68,.35);
}
#sfAnnoToolbar .sf-atb-btn[data-act="delete"] img{
  width:20px;
  height:20px;
  display:block;
filter: brightness(0) invert(1);
}
#sfAnnoToolbar .sf-atb-btn.hidden{display:none;}
`;
            document.head.appendChild(st);
        }

        // (wrap ei ole pakollinen, mutta jos löytyy, varmistetaan relative)
        if (wrap) wrap.style.position = wrap.style.position || 'relative';

        tb = document.createElement('div');
        tb.id = 'sfAnnoToolbar';
        tb.className = 'hidden';
        const i18n = window.SF_I18N || {};
        tb.innerHTML = `
      <button type="button" class="sf-atb-btn" data-act="edit" title="${i18n.btn_edit || 'Edit'}">✎</button>
      <button type="button" class="sf-atb-btn" data-act="rotate" title="${i18n.anno_rotate || 'Rotate'}">⟲</button>
      <button type="button" class="sf-atb-btn" data-act="minus" title="${i18n.anno_size_down || 'Decrease size'}">−</button>
      <button type="button" class="sf-atb-btn" data-act="plus" title="${i18n.anno_size_up || 'Increase size'}">+</button>
      <button type="button" class="sf-atb-btn" data-act="delete" title="${i18n.btn_delete || 'Delete'}">
        <img src="assets/img/icons/delete_icon.svg" alt="${i18n.btn_delete || 'Delete'}" style="width:20px;height:20px;" />
      </button>
    `;
        document.body.appendChild(tb);

        tb.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-act]');
            if (!btn) return;

            const act = btn.dataset.act;

            if (act === 'rotate') { window.SFImageEditor?.rotateSelected?.(); return; }
            if (act === 'minus') { window.SFImageEditor?.changeSelectedSize?.(-6); return; }
            if (act === 'plus') { window.SFImageEditor?.changeSelectedSize?.(6); return; }
            if (act === 'delete') { window.SFImageEditor?.deleteSelected?.(); return; }

            if (act === 'edit') {
                const txt = window.SFImageEditor?.getSelectedText?.() || '';
                if (txt !== '' && typeof window.sfOpenTextModalAndSave === 'function') {
                    window.sfOpenTextModalAndSave(txt);
                }
                return;
            }
        });

        return tb;
    }
    function positionAnnoToolbar(detail) {
        const tb = ensureAnnoToolbar();
        if (!tb) return;

        const hasSel = !!(detail && detail.selectedId);
        if (!hasSel) {
            tb.classList.add('hidden');
            return;
        }

        const canvas = document.getElementById('sf-edit-img-canvas');
        if (!canvas) {
            tb.classList.add('hidden');
            return;
        }

        const x = Number(detail.selectedX);
        const y = Number(detail.selectedY);
        if (!Number.isFinite(x) || !Number.isFinite(y)) {
            tb.classList.add('hidden');
            return;
        }

        // Aseta toolbar näkyviin jotta voidaan mitata koko
        tb.classList.remove('hidden');

        // Canvas -> viewport koordinaatit (fixed positioning)
        const rect = canvas.getBoundingClientRect();
        const vx = rect.left + (x / canvas.width) * rect.width;
        const vy = rect.top + (y / canvas.height) * rect.height;

        // Lasketaan "kuinka paljon ylemmäs" toolbar siirretään, jotta se ei mene ikonille
        // Huomioi CSS-skaalaus: canvas sisäinen koko voi olla paljon suurempi kuin näytöllä
        const canvasScale = rect.height / canvas.height;
        const selSizePx = Number(detail.selectedSize || 140) * canvasScale;
        const iconHalf = Math.max(18, selSizePx / 2);
        const gap = 10;

        // Toolbarin target-piste = ikonin yläpuolella
        // (transform tekee -50%,-100% joten top asetetaan "ikonin yläreunaan + gap")
        let tx = vx;
        let ty = vy - iconHalf - gap;

        // Aseta ensin, mittaa, sitten clamp
        tb.style.left = `${tx}px`;
        tb.style.top = `${ty}px`;

        const r = tb.getBoundingClientRect();
        const pad = 10;

        // Clamp X
        if (r.left < pad) tx += (pad - r.left);
        if (r.right > window.innerWidth - pad) tx -= (r.right - (window.innerWidth - pad));

        // Clamp Y: jos ei mahdu ylös, laitetaan ikonista alle
        if (r.top < pad) {
            ty = vy + iconHalf + gap + r.height; // koska transform -100%
        }
        // jos alhaalta yli, nostetaan
        if (r.bottom > window.innerHeight - pad) {
            ty -= (r.bottom - (window.innerHeight - pad));
        }

        tb.style.left = `${tx}px`;
        tb.style.top = `${ty}px`;

        // Näytä "edit" vain tekstille
        const editBtn = tb.querySelector('[data-act="edit"]');
        if (editBtn) editBtn.classList.toggle('hidden', !(detail.selectedType === 'text'));

        // Näytä "rotate" vain ikoneille
        const rotBtn = tb.querySelector('[data-act="rotate"]');
        if (rotBtn) rotBtn.classList.toggle('hidden', !(detail.selectedType === 'icon'));
    }

    let activeSlot = 1;

    function getEl(id) { return document.getElementById(id); }

    function safeJsonParse(str, fallback) {
        try { return JSON.parse(str); } catch (e) { return fallback; }
    }

    function getAnnotationsStore() {
        const el = getEl('sf-edit-annotations-data');
        const raw = el ? (el.value || '') : '';
        const parsed = safeJsonParse(raw, {});
        return (parsed && typeof parsed === 'object') ? parsed : {};
    }

    function setAnnotationsStore(obj) {
        const el = getEl('sf-edit-annotations-data');
        if (!el) return;
        el.value = JSON.stringify(obj || {});
    }

    function getSlotTransform(slot) {
        const el = getEl(`sf-image${slot}-transform`);
        if (!el || !el.value) return null;

        const parsed = safeJsonParse(el.value, null);
        if (!parsed || typeof parsed !== 'object') return null;

        const x = Number(parsed.x ?? 0);
        const y = Number(parsed.y ?? 0);
        const scale = Number(parsed.scale ?? 0);
        const rotation = Number(parsed.rotation ?? 0);

        const hasScale = scale > 0 && scale !== 1;
        const hasOffset = x !== 0 || y !== 0;
        const hasRotation = rotation !== 0;

        if (!hasScale && !hasOffset && !hasRotation) return null;

        return {
            x: x,
            y: y,
            scale: scale > 0 ? scale : 1,
            rotation: rotation
        };
    }

    function setSlotTransform(slot, transform) {
        const el = getEl(`sf-image${slot}-transform`);
        if (!el) return;

        el.value = JSON.stringify(transform || {
            x: 0,
            y: 0,
            scale: 1,
            rotation: 0
        });
    }

    function getSlotAnnotations(slot) {
        const store = getAnnotationsStore();
        const key = `image${slot}`;
        const arr = store[key];
        return Array.isArray(arr) ? arr : [];
    }

    function setSlotAnnotations(slot, arr) {
        const store = getAnnotationsStore();
        const key = `image${slot}`;
        store[key] = Array.isArray(arr) ? arr : [];
        setAnnotationsStore(store);
    }

    function setSlotEditedDataUrl(slot, dataURL) {
        const el = getEl(`sf-image${slot}-edited-data`);
        if (!el) return;
        el.value = dataURL || '';
    }
    function getCurrentSlotSource(slot) {
        const thumb = getEl('sfImageThumb' + slot);
        if (thumb && thumb.src) return thumb.src;

        const prev = getEl('sfPreviewImg' + slot);
        if (prev && prev.src) return prev.src;

        return '';
    }
    function isDefaultTransform(obj) {
        if (!obj || typeof obj !== 'object') return true;
        const x = Number(obj.x || 0);
        const y = Number(obj.y || 0);
        const scale = Number(obj.scale || 1);
        const rotation = Number(obj.rotation || 0);
        return x === 0 && y === 0 && scale === 1 && rotation === 0;
    }

    function shouldShowEditedBadge(slot, hasRealImage) {
        const tEl = document.getElementById(`sf-image${slot}-transform`);
        let tObj = null;
        try { tObj = JSON.parse(tEl?.value || '{}'); } catch (e) { tObj = null; }

        const ann = getAnnotationsForSlot(slot);
        const hasTransformOrAnnotations = !isDefaultTransform(tObj) || (Array.isArray(ann) && ann.length > 0);

        // Badge näkyy VAIN jos kuva on olemassa JA sillä on muokkauksia
        return hasRealImage && hasTransformOrAnnotations;
    }

    function getAnnotationsForSlot(slot) {
        const storeEl = document.getElementById('sf-edit-annotations-data');
        if (!storeEl) return [];
        try {
            const obj = JSON.parse(storeEl.value || '{}');
            const arr = obj[`image${slot}`];
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            return [];
        }
    }

    function updateImageCardUI(slot) {
        slot = Number(slot) || 1;

        const card = document.querySelector(`.sf-image-upload-card[data-slot="${slot}"]`);
        const preview = document.getElementById(`sfImagePreview${slot}`);
        const thumb = document.getElementById(`sfImageThumb${slot}`);
        if (!card || !preview || !thumb) return;

        const hasRealImage = thumb.dataset.hasRealImage === '1' && thumb.src && thumb.src !== (thumb.dataset.placeholder || '');

        // has-image luokka sekä previewlle että kortille (CSS käyttää näitä)
        preview.classList.toggle('has-image', !!hasRealImage);
        card.classList.toggle('has-image', !!hasRealImage);

        // rasti
        const removeBtn = card.querySelector(`.sf-image-remove-btn[data-slot="${slot}"]`);
        if (removeBtn) removeBtn.classList.toggle('hidden', !hasRealImage);

        // Kuvapankki button: disable when image is present, enable when removed
        const libraryBtn = card.querySelector(`.sf-image-library-btn[data-slot="${slot}"]`);
        if (libraryBtn) {
            libraryBtn.disabled = hasRealImage;
        }

        // CTA label ("Lataa" → "Muokkaa")
        const cta = card.querySelector('.sf-image-upload-btn');
        const ctaText = cta?.querySelector('span');
        if (cta && ctaText) {
            const i18n = window.SF_I18N || {};
            if (hasRealImage) {
                cta.classList.add('sf-cta-edit');
                cta.dataset.mode = 'edit';
                ctaText.textContent = i18n.btn_edit || 'Edit';
            } else {
                cta.classList.remove('sf-cta-edit');
                cta.dataset.mode = 'upload';
                ctaText.textContent = i18n.btn_upload || 'Upload';
            }
        }

        // Badge: muokattu jos transform != default tai annotaatioita löytyy
        // MUTTA vain jos kuva on oikeasti olemassa
        const badge = document.getElementById(`sfImageEditedBadge${slot}`);
        const edited = shouldShowEditedBadge(slot, hasRealImage);

        if (badge) badge.classList.toggle('hidden', !edited);
    }
    // ✅ Tee kortin UI-päivitys käytettäväksi myös IIFE:n ulkopuolelta (poistologiikka)
    window.sfUpdateImageCardUI = updateImageCardUI;
    /**
     * CTA click:
     * - jos mode=edit -> avaa editorin (ei file picker)
     * - jos mode=upload -> normaali file input toimii (ei estoa)
     */
    document.addEventListener('click', (e) => {
        const cta = e.target.closest?.('.sf-image-upload-card .sf-image-upload-btn');
        if (!cta) return;

        const card = cta.closest('.sf-image-upload-card');
        const slot = Number(card?.dataset.slot || 0);
        if (!slot) return;

        if (cta.dataset.mode === 'edit') {
            e.preventDefault();
            e.stopPropagation();

            const src = getCurrentSlotSource(slot);
            if (src) openEditor(slot, src);
        }
    }, true);
    function openEditor(slot, src) {
        if (!src) return;

        activeSlot = Number(slot) || 1;

        const editWrap = document.getElementById('sfEditStep');
        if (editWrap) editWrap.classList.remove('hidden');

        // Show crop guide banner when editor opens
        const cropBanner = document.getElementById('sfCropGuide');
        if (cropBanner) cropBanner.classList.remove('hidden');

        requestAnimationFrame(() => {
            if (window.SFImageEditor && typeof window.SFImageEditor.initCanvasEvents === 'function') {
                window.SFImageEditor.initCanvasEvents();
            }

            const savedTransform = getSlotTransform(activeSlot);

            const initial = {
                // TÄRKEÄ: jos transformia ei ole tallennettu, älä pakota {x:0,y:0,scale:1}
                // jotta editorin default "fit+center" pääsee ajamaan.
                ...(savedTransform ? { transform: savedTransform } : {}),
                annotations: getSlotAnnotations(activeSlot) || []
            };

            // ✅ TÄRKEIN: lataa kuva ja state editoriin
            if (window.SFImageEditor && typeof window.SFImageEditor.setup === 'function') {
                window.SFImageEditor.setup(src, initial);
            }

            // Oletuksena teksti aktiiviseksi heti kun editori aukeaa
            requestAnimationFrame(() => {
                const txtBtn = document.querySelector('.sf-edit-anno-btn[data-sf-tool="text"]');
                const all = document.querySelectorAll('.sf-edit-anno-btn[data-sf-tool]');
                all.forEach(b => b.classList.remove('active'));
                if (txtBtn) txtBtn.classList.add('active');
                window.SFImageEditor?.setTool?.('text');
            });
        });

        const titleEl = document.querySelector('[data-sf-edit-title]');
        if (titleEl) {
            const i18n = window.SF_I18N || {};
            if (activeSlot === 1) {
                titleEl.textContent = i18n.IMAGE_EDIT_MAIN || 'Kuvan muokkaus: Pääkuva';
            } else {
                const prefix = i18n.IMAGE_EDIT_EXTRA_PREFIX || 'Kuvan muokkaus: Lisäkuva';
                titleEl.textContent = `${prefix} ${activeSlot - 1}`;
            }
        }
    }

    function bindButtons() {
        function openTextModalAndSave(initialText = '') {
            const modal = document.getElementById('sfTextModal');
            const input = document.getElementById('sfTextModalInput');
            const save = document.getElementById('sfTextModalSave');

            if (!modal || !input || !save) return;

            input.value = String(initialText || '');
            modal.classList.remove('hidden');
            document.body.classList.add('sf-modal-open');
            input.focus({ preventScroll: true });

            const onSave = () => {
                // Säilytä rivinvaihdot, mutta estä "tyhjä" (pelkät välilyönnit/rivinvaihdot) tallennus
                const val = String(input.value || '').replace(/\r\n/g, '\n');

                if (!val.trim()) {
                    modal.classList.add('hidden');
                    const tb = document.getElementById('sfAnnoToolbar');
                    if (tb) tb.classList.add('hidden');
                    document.body.classList.remove('sf-modal-open');
                    return;
                }

                // Jos editorissa on valittuna teksti -> päivitä se. Muuten lisää uusi.
                if (window.SFImageEditor?.updateSelectedText && window.SFImageEditor?.hasSelectedText?.()) {
                    window.SFImageEditor.updateSelectedText(val);
                } else {
                    window.SFImageEditor?.addTextAt?.(val);
                }

                modal.classList.add('hidden');
                document.body.classList.remove('sf-modal-open');
            };
            save.addEventListener('click', onSave, { once: true });
        }

        // ✅ Tee tekstimodaali käytettäväksi myös floating-toolbarista
        window.sfOpenTextModalAndSave = openTextModalAndSave;

        function applyEditorState(detail) {
            const toolsWrap = document.querySelector('.sf-edit-tools');
            if (toolsWrap && !document.getElementById('sfToolHint')) {
                const i18n = window.SF_I18N || {};
                const hint = document.createElement('div');
                hint.id = 'sfToolHint';
                hint.className = 'sf-tool-hint hidden';
                hint.textContent = i18n.anno_tap_to_add || 'Tap the image to add text';
                toolsWrap.prepend(hint);
            }
            const hasSelected = !!(detail && detail.selectedId);
            const selectedIsIcon = hasSelected && detail.selectedType === 'icon';

            const rotBtn = getEl('sf-edit-anno-rotate');
            const delBtn = getEl('sf-edit-anno-delete');
            const txtBtn = getEl('sf-edit-img-add-label');

            const sizeUp = getEl('sf-edit-anno-size-up');
            const sizeDown = getEl('sf-edit-anno-size-down');
            const hint = document.getElementById('sfToolHint');
            if (hint) {
                const isText = !!(detail && detail.tool === 'text');
                hint.classList.toggle('hidden', !isText);
            }

            // Muokkaustoiminnot aktivoituu vasta kun käyttäjällä on OIKEASTI valittu merkintä kuvasta
            // Muokkaustoiminnot aktivoituu vasta kun käyttäjällä on OIKEASTI valittu merkintä kuvasta
            if (rotBtn) rotBtn.disabled = !selectedIsIcon;
            if (delBtn) delBtn.disabled = !hasSelected;

            // Tekstiä saa lisätä aina (ei vaadi valintaa)
            if (txtBtn) txtBtn.disabled = false;

            if (sizeUp) sizeUp.disabled = !(hasSelected && (detail.selectedType === 'icon' || detail.selectedType === 'blur'));
            if (sizeDown) sizeDown.disabled = !(hasSelected && (detail.selectedType === 'icon' || detail.selectedType === 'blur'));

            const all = document.querySelectorAll('.sf-edit-anno-btn[data-sf-tool]');
            all.forEach(b => {
                b.classList.remove('active');      // placement tool (uuden lisääminen)
                b.classList.remove('is-selected'); // valittu olemassa oleva merkintä
            });

            // 1) Jos käyttäjä on valinnut placement-toolin -> active
            if (detail && detail.tool) {
                const btn = document.querySelector(`.sf-edit-anno-btn[data-sf-tool="${detail.tool}"]`);
                if (btn) btn.classList.add('active');
                return;
            }

            // 2) Muuten, jos käyttäjä on klikannut olemassa olevaa merkintää -> is-selected
            if (detail && detail.selectedTool) {
                const btn = document.querySelector(`.sf-edit-anno-btn[data-sf-tool="${detail.selectedTool}"]`);
                if (btn) btn.classList.add('is-selected');
            }
        }

        document.addEventListener('sf:editor-state', (e) => {
            const detail = e?.detail || null;
            applyEditorState(detail);
            positionAnnoToolbar(detail);
        });
        // Canvas text-click (TEXT tool): avaa modal suoraan
        document.addEventListener('sf:editor-request-text', (e) => {
            const modal = document.getElementById('sfTextModal');
            if (!modal) return;

            // Clear previous input
            const input = document.getElementById('sfTextModalInput');
            if (input) input.value = '';

            // Show modal (don't hide editor!)
            modal.classList.remove('hidden');

            // Focus input after modal opens
            requestAnimationFrame(() => {
                if (input) input.focus();
            });
        });
        const infoBtn = document.getElementById('sf-edit-crop-info-btn');
        if (infoBtn) {
            infoBtn.addEventListener('click', () => {
                const banner = document.getElementById('sfCropGuide');
                if (banner) banner.classList.toggle('hidden');
            });
        }
        const closeBtn = document.getElementById('sf-edit-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                const editWrap = document.getElementById('sfEditStep');
                if (editWrap) editWrap.classList.add('hidden');

                // ✅ Piilota floating toolbar kun editori suljetaan
                const tb = document.getElementById('sfAnnoToolbar');
                if (tb) tb.classList.add('hidden');

                // Issue 5: Also close text modal if it's open when edit modal is closed
                const textModal = document.getElementById('sfTextModal');
                if (textModal && !textModal.classList.contains('hidden')) {
                    textModal.classList.add('hidden');
                    document.body.classList.remove('sf-modal-open');
                }

                if (typeof window.SFShowStep === 'function') {
                    window.SFShowStep(4);
                }
            });
        }
        const b1 = getEl('sf-edit-image-go-1');
        const b2 = getEl('sf-edit-image-go-2');
        const b3 = getEl('sf-edit-image-go-3');

        if (b1) b1.addEventListener('click', () => openEditor(1, getCurrentSlotSource(1)));
        if (b2) b2.addEventListener('click', () => openEditor(2, getCurrentSlotSource(2)));
        if (b3) b3.addEventListener('click', () => openEditor(3, getCurrentSlotSource(3)));

        // Inline "Muokkaa" -nappi ei ole käytössä (CTA "Lataa/Muokkaa" hoitaa)
        const saveBtn = getEl('sf-edit-img-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => {
                if (!window.SFImageEditor || typeof window.SFImageEditor.save !== 'function') return;
                const out = window.SFImageEditor.save();
                if (!out) return;

                setSlotTransform(activeSlot, out.transform);
                setSlotAnnotations(activeSlot, out.annotations);

                // ✅ RENDERÖI CANVAS KUVAKSI MERKINTÖINEEN
                const canvas = document.getElementById('sf-edit-img-canvas');
                if (canvas) {
                    // Piirrä ilman turvaviivoja tallennusta varten
                    const ctx = canvas.getContext('2d');

                    // Luo uusi canvas ilman turvaviivoja
                    const exportCanvas = document.createElement('canvas');
                    exportCanvas.width = canvas.width;
                    exportCanvas.height = canvas.height;
                    const exportCtx = exportCanvas.getContext('2d');

                    // Kopioi sisältö (kuva + merkinnät, mutta ei turvaviivoja)
                    // Käytä SFImageEditor.drawForExport jos se on saatavilla
                    if (typeof window.SFImageEditor.drawForExport === 'function') {
                        window.SFImageEditor.drawForExport(exportCtx, exportCanvas);
                    } else {
                        // Fallback: kopioi nykyinen canvas (sisältää turvaviivat)
                        exportCtx.drawImage(canvas, 0, 0);
                    }

                    const dataUrl = exportCanvas.toDataURL('image/png');
                    setSlotEditedDataUrl(activeSlot, dataUrl);
                }

                // Pidä thumbissa alkuperäinen kuva (ei liimattuja merkintöjä)
                const thumb = document.getElementById('sfImageThumb' + activeSlot);
                if (thumb) {
                    thumb.dataset.hasRealImage = '1';
                    thumb.parentElement?.classList.add('has-image');
                    thumb.closest('.sf-image-upload-card')?.classList.add('has-image');
                    updateImageCardUI(activeSlot);
                }

                const prev = document.getElementById('sfPreviewImg' + activeSlot);
                if (prev) {
                    prev.dataset.hasRealImage = '1';
                }

                const prevGreen = document.getElementById('sfPreviewImg' + activeSlot + 'Green');
                if (prevGreen) {
                    prevGreen.dataset.hasRealImage = '1';
                }
                if (typeof window.sfToast === "function") {
                    const i18n = window.SF_I18N || {};
                    window.sfToast("success", i18n.IMAGE_SAVED || "Kuva tallennettu");
                }

                // Update edit button enabled/disabled state after saving
                updateEditButtonsDisabledState();
                // ✅ Päivitä korttien UI heti (CTA + rasti + badge)
                updateImageCardUI(1);
                updateImageCardUI(2);
                updateImageCardUI(3);

                const editWrap2 = document.getElementById('sfEditStep');
                if (editWrap2) editWrap2.classList.add('hidden');

                // ✅ Piilota floating toolbar kun poistutaan editorista (tallennus)
                const tb = document.getElementById('sfAnnoToolbar');
                if (tb) tb.classList.add('hidden');

                if (typeof window.SFShowStep === 'function') {
                    window.SFShowStep(4);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }

                window.Preview?.applyGridClass?.();
                window.PreviewTutkinta?.applyGridClass?.();
            });
        }

        // Icon-tool buttons (same set as preview)
        document.querySelectorAll('.sf-edit-anno-btn[data-sf-tool]').forEach(btn => {
            if (btn.dataset.sfBound === '1') return;
            btn.dataset.sfBound = '1';

            btn.addEventListener('click', () => {
                const tool = btn.dataset.sfTool || null;
                const all = document.querySelectorAll('.sf-edit-anno-btn[data-sf-tool]');

                // aina valitaan tämä tool (ei toggle pois)
                all.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                window.SFImageEditor?.setTool?.(tool);
            });
        });

        const addTextBtn = getEl('sf-edit-img-add-label');
        if (addTextBtn) {
            addTextBtn.addEventListener('click', () => {
                // jos teksti valittuna -> avaa modal teksti esitäytettynä
                const existing = window.SFImageEditor?.getSelectedText?.() || '';
                openTextModalAndSave(existing);
            });
        }

        const zoomIn = getEl('sf-edit-img-zoom-in');
        if (zoomIn) zoomIn.addEventListener('click', () => window.SFImageEditor?.zoom?.(0.05));

        const zoomOut = getEl('sf-edit-img-zoom-out');
        if (zoomOut) zoomOut.addEventListener('click', () => window.SFImageEditor?.zoom?.(-0.05));

        const ml = getEl('sf-edit-img-move-left');
        if (ml) ml.addEventListener('click', () => window.SFImageEditor?.nudge?.(-10, 0));

        const mr = getEl('sf-edit-img-move-right');
        if (mr) mr.addEventListener('click', () => window.SFImageEditor?.nudge?.(10, 0));

        const mu = getEl('sf-edit-img-move-up');
        if (mu) mu.addEventListener('click', () => window.SFImageEditor?.nudge?.(0, -10));

        const md = getEl('sf-edit-img-move-down');
        if (md) md.addEventListener('click', () => window.SFImageEditor?.nudge?.(0, 10));

        const rotateLeftBtn = getEl('sf-edit-img-rotate-left');
        if (rotateLeftBtn) rotateLeftBtn.addEventListener('click', () => window.SFImageEditor?.rotateImageLeft?.());

        const rotateRightBtn = getEl('sf-edit-img-rotate-right');
        if (rotateRightBtn) rotateRightBtn.addEventListener('click', () => window.SFImageEditor?.rotateImageRight?.());

        const resetBtn = getEl('sf-edit-img-reset');
        if (resetBtn) resetBtn.addEventListener('click', () => window.SFImageEditor?.resetFit?.());

        const delBtn = getEl('sf-edit-anno-delete');
        if (delBtn) delBtn.addEventListener('click', () => window.SFImageEditor?.deleteSelected?.());

        const rotBtn = getEl('sf-edit-anno-rotate');
        if (rotBtn) rotBtn.addEventListener('click', () => window.SFImageEditor?.rotateSelected?.());

        const sizeUp = getEl('sf-edit-anno-size-up');
        if (sizeUp) sizeUp.addEventListener('click', () => window.SFImageEditor?.changeSelectedSize?.(12));

        const sizeDown = getEl('sf-edit-anno-size-down');
        if (sizeDown) sizeDown.addEventListener('click', () => window.SFImageEditor?.changeSelectedSize?.(-12));

        const nextBtn = getEl('sf-edit-img-next');
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const wrap = document.getElementById('sfEditStep');
                if (wrap) wrap.classList.add('hidden');

                if (typeof window.SFShowStep === 'function') {
                    window.SFShowStep(5);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        }
    }

    document.addEventListener('sf:image-selected', async (e) => {
        const slot = e?.detail?.slot;
        const src = e?.detail?.src;
        if (!slot || !src) return;

        // Show loading indicator on the card
        const card = document.querySelector(`.sf-image-upload-card[data-slot="${slot}"]`);
        if (card) {
            card.classList.add('sf-image-loading');
        }

        try {
            // Wait for image to load before opening editor
            const img = new Image();
            img.src = src;

            await new Promise((resolve, reject) => {
                img.onload = resolve;
                img.onerror = reject;
                // Timeout after configured duration
                setTimeout(() => reject(new Error('Image load timeout')), IMAGE_LOAD_TIMEOUT_MS);
            });

            // Hide loading indicator
            if (card) {
                card.classList.remove('sf-image-loading');
            }

            // Now open the editor with the loaded image
            openEditor(slot, src);
        } catch (error) {
            console.error('Failed to load image:', error);
            // Hide loading indicator even on error
            if (card) {
                card.classList.remove('sf-image-loading');
            }
            // Still try to open editor (fallback to original behavior)
            openEditor(slot, src);
        }
    });

    function updateEditButtonsDisabledState() {
        const t1 = getEl('sfImageThumb1');
        const t2 = getEl('sfImageThumb2');
        const t3 = getEl('sfImageThumb3');

        const has1 = t1 && (t1.dataset.hasRealImage === '1');
        const has2 = t2 && (t2.dataset.hasRealImage === '1');
        const has3 = t3 && (t3.dataset.hasRealImage === '1');

        const b1 = getEl('sf-edit-image-go-1');
        const b2 = getEl('sf-edit-image-go-2');
        const b3 = getEl('sf-edit-image-go-3');

        if (b1) b1.disabled = !has1;
        if (b2) b2.disabled = !has2;
        if (b3) b3.disabled = !has3;
    }

    document.addEventListener('DOMContentLoaded', () => {
        bindButtons();

        // Mark existing server-loaded images as "real" so edit buttons enable
        [1, 2, 3].forEach(slot => {
            const thumb = document.getElementById('sfImageThumb' + slot);
            if (!thumb) return;

            const placeholder = thumb.dataset.placeholder || '';
            const src = thumb.getAttribute('src') || '';

            // ✅ If image is NOT a placeholder, mark it as real
            if (src && placeholder && src.indexOf(placeholder) === -1) {
                thumb.dataset.hasRealImage = '1';

                // Ensure has-image class on proper elements
                document.getElementById('sfImagePreview' + slot)?.classList.add('has-image');
                thumb.parentElement?.classList.add('has-image');
                thumb.closest('.sf-image-upload-card')?.classList.add('has-image');

                // ✅ Update card UI (CTA "Lataa" → "Muokkaa")
                updateImageCardUI(slot);
            } else if (src && !placeholder && src.indexOf('camera-placeholder.png') === -1) {
                thumb.dataset.hasRealImage = '1';

                // Ensure has-image class on proper elements
                document.getElementById('sfImagePreview' + slot)?.classList.add('has-image');
                thumb.parentElement?.classList.add('has-image');
                thumb.closest('.sf-image-upload-card')?.classList.add('has-image');

                // ✅ Update card UI (CTA "Lataa" → "Muokkaa")
                updateImageCardUI(slot);
            } else {
                thumb.dataset.hasRealImage = thumb.dataset.hasRealImage || '0';
            }
        });

        // Background-render existing images that have transforms or annotations but no
        // composited edited-data yet (e.g. after language-version creation or re-edit).
        // This ensures the grid bitmap and preview always reflect the saved state
        // even if the user never opens the image editor modal.
        (async () => {
            const existingImages = window.SF_EXISTING_IMAGES || {};
            for (const slot of [1, 2, 3]) {
                const editedEl = document.getElementById(`sf-image${slot}-edited-data`);
                if (!editedEl || editedEl.value) continue; // already has composited data

                const slotKey = `slot${slot}`;
                const imgData = existingImages[slotKey];
                if (!imgData || !imgData.url || !imgData.filename) continue; // no image

                // Parse saved transform
                const transformEl = document.getElementById(`sf-image${slot}-transform`);
                let transformData = null;
                try { transformData = safeJsonParse(transformEl?.value || 'null', null); } catch (e) { console.warn(`[SF] Could not parse transform for slot ${slot}:`, e); }

                const annotations = getSlotAnnotations(slot);

                // Only render if there is a non-default transform or at least one annotation
                const hasNonDefault = !isDefaultTransform(transformData);
                const hasAnnotations = annotations.length > 0;
                if (!hasNonDefault && !hasAnnotations) continue;

                if (window.SFImageEditor && typeof window.SFImageEditor.renderToDataURL === 'function') {
                    try {
                        const dataUrl = await window.SFImageEditor.renderToDataURL(imgData.url, transformData, annotations);
                        if (dataUrl) {
                            setSlotEditedDataUrl(slot, dataUrl);
                            updateImageCardUI(slot);
                        }
                    } catch (e) {
                        console.warn(`[SF] Background render failed for slot ${slot}:`, e);
                    }
                }
            }
        })();

        // Alku: ei valintaa -> muokkausnapit pois päältä (lähetetään editor-state eventinä)
        document.dispatchEvent(new CustomEvent('sf:editor-state', {
            detail: { tool: null, selectedId: null, selectedType: null }
        }));

        updateEditButtonsDisabledState();
        // ✅ Päivitä korttien UI heti (CTA + rasti + badge)
        updateImageCardUI(1);
        updateImageCardUI(2);
        updateImageCardUI(3);
        // Uploads.js / image-library.js hoitaa kuvan src:n (dataURL) + sf:image-selected eventin.
        // Ei käytetä blob URL:ia, jotta kuva ei "tyhjene" kun palaa takaisin muokkaukseen.
        [1, 2, 3].forEach(slot => {
            const input = document.getElementById(`sf-image${slot}`);
            if (!input) return;

            input.addEventListener('change', () => {
                updateEditButtonsDisabledState();
            });
        });
    });

    document.addEventListener('sf:image-selected', (e) => {
        updateEditButtonsDisabledState();
        const slot = Number(e?.detail?.slot || 0);
        if (slot) updateImageCardUI(slot);
    });

    document.addEventListener('sf:image-removed', (e) => {
        updateEditButtonsDisabledState();
        const slot = Number(e?.detail?.slot || e?.detail?.slot || 0);
        if (slot) updateImageCardUI(slot);
    });
})();
// --- STEP 1: Safetyflash type must be selected before Next ---
document.addEventListener('DOMContentLoaded', () => {
    const nextBtn = document.getElementById('sfNext1');
    if (!nextBtn) return;

    const radios = document.querySelectorAll('input[type="radio"][name="safetyflash_type"]');
    if (!radios.length) return;

    function updateNextState() {
        const selected = Array.from(radios).some(r => r.checked);
        nextBtn.disabled = !selected;
        nextBtn.setAttribute('aria-disabled', String(!selected));
    }

    // initial state
    updateNextState();

    radios.forEach(radio => {
        radio.addEventListener('change', updateNextState);
    });
});
let removeSlotPending = null;

function sfOpenRemoveConfirm(slot) {
    removeSlotPending = Number(slot) || null;

    // ✅ Varmista ettei kuvan muokkausmodal aukea tämän alle (tai jää taustalle)
    document.getElementById('sfEditStep')?.classList.add('hidden');
    document.getElementById('sfAnnoToolbar')?.classList.add('hidden');

    const modal = document.getElementById('sfConfirmRemoveModal');
    const txt = document.getElementById('sfConfirmRemoveText');
    const yes = document.getElementById('sfConfirmRemoveYes');
    const no = document.getElementById('sfConfirmRemoveNo');
    const i18n = window.SF_I18N || {};

    if (txt) {
        txt.textContent =
            i18n.CONFIRM_REMOVE_IMAGE ||
            'Haluatko poistaa tämän kuvan? Kuva ja sen säädöt poistetaan.';
    }

    // nappitekstit i18n:stä (fallbackit mukana)
    if (yes) yes.textContent = i18n.BTN_DELETE || i18n.btn_delete || 'Poista';
    if (no) no.textContent = i18n.BTN_CANCEL || i18n.btn_cancel || 'Peruuta';

    modal?.classList.remove('hidden');
    document.body.classList.add('sf-modal-open');
}

function sfCloseRemoveConfirm() {
    document.getElementById('sfConfirmRemoveModal')?.classList.add('hidden');
    document.body.classList.remove('sf-modal-open');
    removeSlotPending = null;
}

function sfClearImageSlot(slot) {
    slot = Number(slot) || 0;
    if (!slot) return;

    // 1) tyhjennä annotations store (sf-edit-annotations-data pitää sisällään image1/image2/image3)
    const storeEl = document.getElementById('sf-edit-annotations-data');
    if (storeEl) {
        try {
            const obj = JSON.parse(storeEl.value || '{}');
            obj[`image${slot}`] = [];
            storeEl.value = JSON.stringify(obj);
        } catch (e) {
            // jos store on rikki, nollaa varovasti
            storeEl.value = JSON.stringify({ [`image${slot}`]: [] });
        }
    }

    // 2) tyhjennä hidden kentät
    const edited = document.getElementById(`sf-image${slot}-edited-data`);
    if (edited) edited.value = '';

    const transform = document.getElementById(`sf-image${slot}-transform`);
    if (transform) transform.value = JSON.stringify({ x: 0, y: 0, scale: 1, rotation: 0 });

	const existingField = document.getElementById(`sf-existing-image-${slot}`);
    if (existingField) existingField.value = '';

    // 3) tyhjennä file input
    const input = document.getElementById(`sf-image${slot}`);
    if (input) input.value = '';

    // 4) palauta placeholderit
    const thumb = document.getElementById('sfImageThumb' + slot);
    if (thumb) {
        thumb.src = thumb.dataset.placeholder || '';
        thumb.dataset.hasRealImage = '0';
        thumb.parentElement?.classList.remove('has-image');
        thumb.closest('.sf-image-upload-card')?.classList.remove('has-image');
        window.sfUpdateImageCardUI?.(slot);
    }

    const prev = document.getElementById('sfPreviewImg' + slot);
    if (prev) {
        prev.src = prev.dataset.placeholder || prev.src || '';
        prev.dataset.hasRealImage = '0';
    }

    const prevGreen = document.getElementById('sfPreviewImg' + slot + 'Green');
    if (prevGreen) {
        prevGreen.src = prevGreen.dataset.placeholder || prevGreen.src || '';
        prevGreen.dataset.hasRealImage = '0';
    }

    // ✅ 4.1) Piilota poistopainike (raksi) heti poiston jälkeen
    const removeBtn = document.querySelector(`.sf-image-remove-btn[data-slot="${slot}"]`);
    if (removeBtn) removeBtn.classList.add('hidden');

    // ✅ 4.2) Disabloi "Muokkaa" (inline edit) jos kuva poistettiin
    const editBtn = document.querySelector(`.sf-image-edit-inline-btn[data-slot="${slot}"]`);
    if (editBtn) editBtn.disabled = true;

    // 5) ilmoita muille (edit-nappien disable yms.)
    document.dispatchEvent(new CustomEvent('sf:image-removed', { detail: { slot } }));
}

/**
 * ✅ Delegoitu bindaus (toimii varmasti vaikka DOM / modaalit renderöityvät eri järjestyksessä)
 * + Capture-vaiheessa stopPropagation, jotta klikki ei koskaan avaa editoria kortin/kuvan kautta.
 */
document.addEventListener('pointerdown', (e) => {
    const removeBtn = e.target.closest?.('.sf-image-remove-btn[data-slot]');
    if (!removeBtn) return;
    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
}, true);

document.addEventListener('click', (e) => {
    const removeBtn = e.target.closest?.('.sf-image-remove-btn[data-slot]');
    if (!removeBtn) return;

    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();

    const slot = Number(removeBtn.dataset.slot) || 0;
    if (!slot) return;

    sfOpenRemoveConfirm(slot);
}, true);

document.addEventListener('click', (e) => {
    const yes = e.target.closest?.('#sfConfirmRemoveYes');
    if (!yes) return;

    e.preventDefault();
    e.stopPropagation();

    if (!removeSlotPending) return;
    sfClearImageSlot(removeSlotPending);
    sfCloseRemoveConfirm();
}, true);

document.addEventListener('click', (e) => {
    const no = e.target.closest?.('#sfConfirmRemoveNo');
    if (!no) return;

    e.preventDefault();
    e.stopPropagation();
    sfCloseRemoveConfirm();
}, true);