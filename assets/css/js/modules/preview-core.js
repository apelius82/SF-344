// assets/js/modules/preview-core.js
// Korjattu: eventit delegoidaan documentiin -> toimii PJAX/smooth navigoinnilla
// eikä “kuole” kun DOM vaihdetaan.

"use strict";

export class PreviewCore {
    constructor(config = {}) {
        this.config = {
            idSuffix: config.idSuffix || "",
            cardId: config.cardId || "sfPreviewCard",
            gridSelectorId: config.gridSelectorId || "sfGridSelector",
            sliderXId: config.sliderXId || "sfPreviewSliderX",
            sliderYId: config.sliderYId || "sfPreviewSliderY",
            sliderZoomId: config.sliderZoomId || "sfPreviewSliderZoom",
            slidersPanelId: config.slidersPanelId || "sfSlidersPanel",
            annotationsPanelId: config.annotationsPanelId || "sfAnnotationsPanel",
            ...config,
        };

        const suffix = this.config.idSuffix;

        this.ids = {
            card: this.config.cardId + suffix,
            gridSelector: this.config.gridSelectorId + suffix,
            sliderX: this.config.sliderXId + suffix,
            sliderY: this.config.sliderYId + suffix,
            sliderZoom: this.config.sliderZoomId + suffix,
            slidersPanel: this.config.slidersPanelId + suffix,
            annotationsPanel: this.config.annotationsPanelId + suffix,
        };

        this.frames = {
            1: "sfFrame1" + suffix,
            2: "sfFrame2" + suffix,
            3: "sfFrame3" + suffix,
        };

        this.imgs = {
            1: "sfPreviewImg1" + suffix,
            2: "sfPreviewImg2" + suffix,
            3: "sfPreviewImg3" + suffix,
        };

        this.transformInputs = {
            1: "sf-image1-transform",
            2: "sf-image2-transform",
            3: "sf-image3-transform",
        };

        this.state = {
            1: { x: 0, y: 0, scale: 1 },
            2: { x: 0, y: 0, scale: 1 },
            3: { x: 0, y: 0, scale: 1 },
        };

        this.activeSlot = 1;

        this.initialized = false;
        this._delegatesBound = false;
    }

    // ===== INIT =====

    init() {
        const card = document.getElementById(this.ids.card);
        if (!card) return this; // ei olla preview-sivulla / elementit puuttuu

        // Delegoidut eventit sidotaan vain kerran (kestää DOM-vaihdot)
        if (!this._delegatesBound) {
            this._bindDelegates();
            this._delegatesBound = true;
        }

        // Päivitä kuvat aina initissä (uusi DOM)
        this._initImages();

        this.setActiveSlot(1);
        this.applyGridClass();

        this.initialized = true;
        return this;
    }

    reinit() {
        // tässä mallissa init on turvallinen kutsua uudelleen
        return this.init();
    }

    // ===== EVENTS (delegointi) =====

    _bindDelegates() {
        // Sliderit: input-eventti
        document.addEventListener("input", (e) => {
            const id = e.target && e.target.id;
            if (!id) return;

            if (
                id === this.ids.sliderX ||
                id === this.ids.sliderY ||
                id === this.ids.sliderZoom
            ) {
                this._onSliderChange();
            }
        });

        // Klikit: kuvaframe + grid-napit
        document.addEventListener("click", (e) => {
            const t = e.target;

            // 1) frame click
            const frameEl = t && t.closest && t.closest(`#${this.frames[1]}, #${this.frames[2]}, #${this.frames[3]}`);
            if (frameEl) {
                const id = frameEl.id;
                const slot = id === this.frames[1] ? 1 : id === this.frames[2] ? 2 : 3;
                this.setActiveSlot(slot);
                return;
            }

            // 2) grid button click (vain oikean selectorin sisällä)
            const gridBtn = t && t.closest && t.closest(".sf-grid-btn");
            if (gridBtn) {
                const selector = gridBtn.closest(`#${this.ids.gridSelector}`);
                if (!selector) return;

                const forCount = gridBtn.getAttribute("data-for");
                selector.querySelectorAll(".sf-grid-btn").forEach((b) => {
                    if (b.getAttribute("data-for") === forCount) b.classList.remove("active");
                });
                gridBtn.classList.add("active");

                const grid = gridBtn.getAttribute("data-grid");
                this.applyGridClass(grid);
            }
        });
    }

    // ===== IMAGES =====

    _initImages() {
        [1, 2, 3].forEach((slot) => {
            const stored = this._readStoredTransform(slot);
            if (stored) {
                this.state[slot] = {
                    x: stored.x || 0,
                    y: stored.y || 0,
                    scale: stored.scale || 1,
                };
            }

            const img = document.getElementById(this.imgs[slot]);
            if (!img) return;

            if (img.complete && img.naturalWidth > 0) {
                if (!stored) this._autoFit(slot);
                this._applyTransform(slot);
            } else {
                img.onload = () => {
                    if (!this._readStoredTransform(slot)) this._autoFit(slot);
                    this._applyTransform(slot);
                    this._saveTransform(slot);
                };
            }
        });
    }

    _hasRealImage(slot) {
        const img = document.getElementById(this.imgs[slot]);
        if (!img || !img.src) return false;

        if (img.dataset?.hasRealImage === "0") return false;
        if (img.dataset?.hasRealImage === "1") return true;

        const src = img.src.toLowerCase();
        if (src.includes("camera")) return false;
        if (src.includes("placeholder")) return false;
        if (src.includes("no-image")) return false;
        if (src === "" || src === "about:blank") return false;

        return true;
    }

    // ===== SLIDERS =====

    _onSliderChange() {
        const st = this.state[this.activeSlot];
        if (!st) return;

        const sliderX = document.getElementById(this.ids.sliderX);
        const sliderY = document.getElementById(this.ids.sliderY);
        const sliderZoom = document.getElementById(this.ids.sliderZoom);

        if (sliderX) st.x = Number(sliderX.value);
        if (sliderY) st.y = Number(sliderY.value);

        if (sliderZoom) {
            const baseScale = this._getBaseScale(this.activeSlot);
            st.scale = baseScale * (Number(sliderZoom.value) / 100);
        }

        this._applyTransform(this.activeSlot);
        this._saveTransform(this.activeSlot);
    }

    _syncSlidersToState() {
        const st = this.state[this.activeSlot];
        if (!st) return;

        const sliderX = document.getElementById(this.ids.sliderX);
        const sliderY = document.getElementById(this.ids.sliderY);
        const sliderZoom = document.getElementById(this.ids.sliderZoom);

        if (sliderX) sliderX.value = String(Math.round(st.x));
        if (sliderY) sliderY.value = String(Math.round(st.y));
        if (sliderZoom) {
            const baseScale = this._getBaseScale(this.activeSlot);
            const percent =
                baseScale > 0 ? Math.round((st.scale / baseScale) * 100) : 100;
            sliderZoom.value = String(percent);
        }
    }

    // ===== TRANSFORMS =====

    _getBaseScale(slot) {
        const frame = document.getElementById(this.frames[slot]);
        const img = document.getElementById(this.imgs[slot]);
        if (!frame || !img || !img.naturalWidth || !img.naturalHeight) return 1;

        const fw = frame.offsetWidth;
        const fh = frame.offsetHeight;
        if (fw === 0 || fh === 0) return 1;

        return Math.max(fw / img.naturalWidth, fh / img.naturalHeight);
    }

    _autoFit(slot) {
        const frame = document.getElementById(this.frames[slot]);
        const img = document.getElementById(this.imgs[slot]);
        if (!frame || !img || !img.naturalWidth || !img.naturalHeight) return;

        const fw = frame.offsetWidth;
        const fh = frame.offsetHeight;
        if (fw === 0 || fh === 0) return;

        const scale = Math.max(fw / img.naturalWidth, fh / img.naturalHeight);
        this.state[slot] = { x: 0, y: 0, scale: Math.max(0.1, scale) };
    }

    _applyTransform(slot) {
        const img = document.getElementById(this.imgs[slot]);
        const st = this.state[slot];
        if (!img || !st) return;

        img.style.position = "absolute";
        img.style.top = "50%";
        img.style.left = "50%";
        img.style.width = "auto";
        img.style.height = "auto";
        img.style.maxWidth = "none";
        img.style.maxHeight = "none";
        img.style.transformOrigin = "center center";
        img.style.transform = `translate(-50%, -50%) translate(${st.x}px, ${st.y}px) scale(${st.scale})`;
    }

    _readStoredTransform(slot) {
        const el = document.getElementById(this.transformInputs[slot]);
        if (!el || !el.value) return null;
        try {
            const p = JSON.parse(el.value);
            return p && typeof p.scale === "number" ? p : null;
        } catch (e) {
            return null;
        }
    }

    _saveTransform(slot) {
        const el = document.getElementById(this.transformInputs[slot]);
        if (el) el.value = JSON.stringify(this.state[slot]);
    }

    // ===== ACTIVE SLOT =====

    setActiveSlot(slot) {
        this.activeSlot = slot;

        [1, 2, 3].forEach((s) => {
            const frame = document.getElementById(this.frames[s]);
            if (frame) frame.classList.toggle("sf-active", s === slot);
        });

        this._syncSlidersToState();
    }

    // ===== GRID =====

    applyGridClass(forceStyle) {
        const card = document.getElementById(this.ids.card);
        if (!card) return;

        const count = [1, 2, 3].filter((s) => this._hasRealImage(s)).length;

        card.classList.remove(
            "grid-main-only",
            "grid-2-stacked",
            "grid-2-overlay",
            "grid-3-main-top",
            "grid-3-overlay"
        );

        const thumbsRow = card.querySelector(".sf-preview-thumbs-row");
        const frame2 = document.getElementById(this.frames[2]);
        const frame3 = document.getElementById(this.frames[3]);

        if (count <= 1) {
            card.classList.add("grid-main-only");
            if (thumbsRow) thumbsRow.style.display = "none";
            if (frame2) frame2.style.display = "none";
            if (frame3) frame3.style.display = "none";

            // Issue 1: Reset sf-grid-layout hidden field to grid-1 when count <= 1
            const gridLayoutInput = document.getElementById("sf-grid-layout");
            if (gridLayoutInput) {
                gridLayoutInput.value = "grid-1";
            }
        } else {
            if (thumbsRow) thumbsRow.style.display = "";
            if (frame2) frame2.style.display = this._hasRealImage(2) ? "" : "none";
            if (frame3) frame3.style.display = this._hasRealImage(3) ? "" : "none";

            const selector = document.getElementById(this.ids.gridSelector);
            const activeBtn = selector?.querySelector(
                `.sf-grid-btn.active[data-for="${count}"]`
            );
            const gridValue =
                forceStyle ||
                activeBtn?.getAttribute("data-grid") ||
                (count === 2 ? "grid-2-stacked" : "grid-3-main-top");

            card.classList.add(gridValue);
        }

        this._updateGridOptions(count);

        setTimeout(() => {
            [1, 2, 3].forEach((slot) => {
                if (this._hasRealImage(slot)) {
                    this._autoFit(slot);
                    this._applyTransform(slot);
                }
            });
            this._syncSlidersToState();
        }, 50);
    }

    _updateGridOptions(count) {
        const container = document.getElementById(this.ids.gridSelector);
        if (!container) return;

        if (count <= 1) {
            container.style.display = "none";
            return;
        }

        container.style.display = "";
        const btns = container.querySelectorAll(".sf-grid-btn");
        btns.forEach((btn) => {
            btn.style.display =
                btn.getAttribute("data-for") === String(count) ? "" : "none";
        });

        const visible = Array.from(btns).filter((b) => b.style.display !== "none");
        if (visible.length > 0 && !visible.some((b) => b.classList.contains("active"))) {
            visible[0].classList.add("active");
        }
    }

    // ===== API =====

    getTransformData() {
        return {
            1: { ...this.state[1], hasImage: this._hasRealImage(1) },
            2: { ...this.state[2], hasImage: this._hasRealImage(2) },
            3: { ...this.state[3], hasImage: this._hasRealImage(3) },
        };
    }

    getAllTransforms() {
        return JSON.stringify({
            image1: this.state[1],
            image2: this.state[2],
            image3: this.state[3],
        });
    }
}

// Oletus-instanssi red/yellow-tiedotteelle
export const Preview = new PreviewCore({ idSuffix: "" });

// Globaali yhteensopivuus
if (typeof window !== "undefined") {
    window.Preview = Preview;
    window.PreviewCore = PreviewCore;
}

export default PreviewCore;

// (poistettu) Testi-SFEditImage: käytetään tuotantoeditoria assets/js/SFEditImage.js (window.SFImageEditor)