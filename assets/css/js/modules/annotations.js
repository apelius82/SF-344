// assets/js/modules/annotations.js
// Yhdistetty annotaatiojärjestelmä (toolbar + merkinnät + drag + mobile)

'use strict';

const Annotations = {
    annotations: [],
    baseUrl: '',
    currentTool: null,
    activeAnnotationId: null,

    // Raahaus-tila
    dragging: null,
    _justDragged: false,

    iconFiles: {
        arrow: 'arrow-red.png',
        circle: 'circle-red.png',
        crash: 'crash.png',
        warning: 'warning.png',
        injury: 'injury.png',
        cross: 'cross-red.png'
    },

    init: function () {
        // Oletustyökalu: teksti
        this.currentTool = 'text';
        this.activeAnnotationId = null;
        this._updateToolbarUI();
        // Etsi NÄKYVÄ kortti (ei display:none)
        let card = document.getElementById('sfPreviewCard');
        let cardGreen = document.getElementById('sfPreviewCardGreen');

        // Valitse se kortti joka on näkyvissä
        if (cardGreen && cardGreen.offsetParent !== null) {
            card = cardGreen;
        } else if (card && card.offsetParent !== null) {
            // card on jo oikein
        } else if (cardGreen) {
            card = cardGreen;
        }
        // Jos kumpaakaan ei löydy
        if (!card && !cardGreen) {
            console.warn('Annotations.init: ei sfPreviewCard/sfPreviewCardGreen elementtiä');
            return;
        }

        // Jos card on edelleen null, käytä cardGreeniä
        if (!card) card = cardGreen;

        // baseUrl kortin data-attribuutista TAI varalla globaalista SF_BASE_URL:ista
        let base = '';
        if (card && card.dataset && card.dataset.baseUrl) {
            base = card.dataset.baseUrl;
        } else if (typeof SF_BASE_URL !== 'undefined') {
            base = SF_BASE_URL;
        }
        this.baseUrl = (base || '').replace(/\/$/, '');

        console.log('Annotations.init - card:', card ? card.id : 'null', 'baseUrl:', this.baseUrl);

        // Bindaa KAIKKI annotaatio-painikkeet koko dokumentista
        this._bindToolbar(document);

        // Bindaa klikkaukset kaikkiin frameihin
        this._bindFrames();

        // Globaalit drag- ja touch-kuuntelijat (vain kerran)
        if (!this._globalDragBound) {
            this._bindGlobalDrag();
            this._globalDragBound = true;
        }

        // Lataa olemassa olevat merkinnät
        this._loadAnnotations();

        console.log('Annotations initialized successfully');
    },

    _bindToolbar: function (container) {
        const self = this;
        // KORJATTU: Etsitään kaikki annotaatio-painikkeet
        const buttons = container.querySelectorAll('.sf-annotation-btn, .sf-anno-btn');

        console.log('Annotations._bindToolbar: found', buttons.length, 'buttons');

        buttons.forEach(btn => {
            // KORJAUS: Estä tupla-bindaus
            if (btn.dataset.annotationBound === '1') return;
            btn.dataset.annotationBound = '1';

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const icon = this.dataset.icon;
                if (!icon) return;

                const isActive = this.classList.contains('sf-annotation-btn-active') ||
                    this.classList.contains('active');

                // Hae KAIKKI annotaatio-napit uudelleen (molemmat versiot)
                const allButtons = document.querySelectorAll('.sf-annotation-btn, .sf-anno-btn');

                if (isActive) {
                    self.currentTool = null;
                    allButtons.forEach(b => b.classList.remove('sf-annotation-btn-active', 'active'));
                } else {
                    self.currentTool = icon;
                    allButtons.forEach(b => b.classList.remove('sf-annotation-btn-active', 'active'));
                    this.classList.add('sf-annotation-btn-active', 'active');
                }

                console.log('Annotation tool selected:', self.currentTool);
            });
        });

        // Tyhjennysnappi - etsi kaikki
        const clearBtns = container.querySelectorAll('[data-clear-annotations]');
        clearBtns.forEach(clearBtn => {
            // KORJAUS: Estä tupla-bindaus
            if (clearBtn.dataset.clearBound === '1') return;
            clearBtn.dataset.clearBound = '1';

            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (confirm('Poistetaanko kaikki merkinnät? ')) {
                    Annotations._clearAll();
                }
            });
        });
    },

    _bindFrames: function () {
        const self = this;

        const selector = [
            '.sf-preview-image-frame',
            '.sf-preview-thumb-frame',
            '#sfFrame1',
            '#sfFrame2',
            '#sfFrame3',
            '#sfFrame1Green',
            '#sfFrame2Green',
            '#sfFrame3Green'
        ].join(', ');

        const frames = document.querySelectorAll(selector);

        console.log('Annotations._bindFrames: found', frames.length, 'frames');

        if (!frames.length) {
            console.warn('Annotations: ei löydetty yhtään framea');
            return;
        }

        frames.forEach(frame => {
            // KORJAUS: Estä tupla-bindaus
            if (frame.dataset.annotationFrameBound === '1') return;
            frame.dataset.annotationFrameBound = '1';

            frame.addEventListener('click', function (e) {
                if (self._justDragged) {
                    self._justDragged = false;
                    return;
                }
                if (e.target.closest('.sf-annotation')) return;
                self._onFrameClick(this, e);
            });

            frame.addEventListener('touchend', function (e) {
                if (self._justDragged) {
                    self._justDragged = false;
                    return;
                }
                if (e.target.closest('.sf-annotation')) return;
                // Issue 4: Ensure touchend is properly handled for annotation placement
                if (e.changedTouches && e.changedTouches.length === 1) {
                    e.preventDefault(); // Prevent double-tap zoom
                    self._onFrameClick(this, e);
                }
            }, { passive: false }); // Issue 4: Non-passive to allow preventDefault
        });
    },

    _bindGlobalDrag: function () {
        const self = this;

        document.addEventListener('mousemove', function (e) {
            if (self.dragging) {
                e.preventDefault();
                self._onDragMove(e.clientX, e.clientY);
            }
        });

        document.addEventListener('mouseup', function () {
            if (self.dragging) {
                self._onDragEnd();
            }
        });

        document.addEventListener('touchmove', function (e) {
            if (self.dragging && e.touches.length === 1) {
                e.preventDefault();
                self._onDragMove(e.touches[0].clientX, e.touches[0].clientY);
            }
        }, { passive: false });

        document.addEventListener('touchend', function () {
            if (self.dragging) {
                self._onDragEnd();
            }
        });

        document.addEventListener('touchcancel', function () {
            if (self.dragging) {
                self._onDragEnd();
            }
        });
    },

    _onFrameClick: function (frame, event) {
        if (!this.currentTool) {
            console.log('No annotation tool selected');
            return;
        }

        const rect = frame.getBoundingClientRect();
        let clientX, clientY;

        if (event.changedTouches && event.changedTouches.length) {
            clientX = event.changedTouches[0].clientX;
            clientY = event.changedTouches[0].clientY;
        } else {
            clientX = event.clientX;
            clientY = event.clientY;
        }

        const x = ((clientX - rect.left) / rect.width) * 100;
        const y = ((clientY - rect.top) / rect.height) * 100;

        this._addAnnotationAt(frame, x, y, this.currentTool);
    },

    _addAnnotationAt: function (frame, x, y, iconType) {
        const id = 'ann_' + Date.now() + '_' + Math.random().toString(16).slice(2);

        const clampedX = Math.max(5, Math.min(95, x));
        const clampedY = Math.max(5, Math.min(95, y));

        const rect = frame.getBoundingClientRect();
        const baseWidth = rect.width || 800;
        const defaultSizePx = 48;
        const defaultSizePercent = (defaultSizePx / baseWidth) * 100;

        const ann = {
            id,
            frameId: frame.id,
            icon: iconType,
            x: clampedX,
            y: clampedY,
            rotation: 0,
            size: defaultSizePercent
        };

        this.annotations.push(ann);
        this._renderAnnotation(frame, ann);
        this._saveAnnotations();

        // Poista työkalu käytöstä lisäyksen jälkeen
        this.currentTool = null;
        document.querySelectorAll('.sf-annotation-btn, .sf-anno-btn').forEach(b => {
            b.classList.remove('sf-annotation-btn-active', 'active');
        });

        console.log('Annotation added:', ann);
    },

    _renderAnnotation: function (frame, ann) {
        const self = this;

        // KORJAUS: Tarkista onko jo renderöity
        if (document.getElementById(ann.id)) {
            console.log('Annotation already rendered:', ann.id);
            return;
        }

        const el = document.createElement('div');
        el.className = 'sf-annotation';
        el.id = ann.id;
        el.dataset.annotationId = ann.id;
        el.style.left = ann.x + '%';
        el.style.top = ann.y + '%';

        const frameRect = frame.getBoundingClientRect();

        let sizePercent = ann.size;
        if (sizePercent > 100) {
            const asPx = sizePercent;
            const width = frameRect.width || 800;
            sizePercent = (asPx / width) * 100;
            ann.size = sizePercent;
        }

        const sizePx = (frameRect.width || 800) * (sizePercent / 100);

        el.style.width = sizePx + 'px';
        el.style.height = sizePx + 'px';
        el.style.marginLeft = -(sizePx / 2) + 'px';
        el.style.marginTop = -(sizePx / 2) + 'px';
        el.style.cursor = 'grab';
        el.style.touchAction = 'none';

        const iconFile = this.iconFiles[ann.icon] || (ann.icon + '.png');

        el.innerHTML = `
            <div class="sf-annotation-image" style="transform: rotate(${ann.rotation || 0}deg);">
                <img src="${this.baseUrl}/assets/img/annotations/${iconFile}" alt="${ann.icon}" draggable="false">
            </div>
            <div class="sf-annotation-controls">
                <button type="button" class="sf-annotation-rotate" title="Kierrä 45°">↻</button>
                <button type="button" class="sf-annotation-delete" title="Poista">×</button>
            </div>
        `;

        el.addEventListener('mousedown', function (e) {
            if (e.target.closest('.sf-annotation-controls')) return;
            e.preventDefault();
            e.stopPropagation();
            self._onDragStart(ann.id, e.clientX, e.clientY, frame);
        });

        el.addEventListener('touchstart', function (e) {
            if (e.target.closest('.sf-annotation-controls')) return;
            if (e.touches.length !== 1) return;
            e.preventDefault();
            e.stopPropagation();
            self._onDragStart(ann.id, e.touches[0].clientX, e.touches[0].clientY, frame);
        }, { passive: false });

        const rotateBtn = el.querySelector('.sf-annotation-rotate');
        if (rotateBtn) {
            rotateBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self._rotateAnnotation(ann.id, 45);
            });
        }

        const deleteBtn = el.querySelector('.sf-annotation-delete');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self._removeAnnotation(ann.id);
            });
        }

        frame.appendChild(el);
    },

    _onDragStart: function (id, clientX, clientY, frame) {
        const ann = this.annotations.find(a => a.id === id);
        if (!ann) return;

        const el = document.getElementById(id);
        if (el) {
            el.style.cursor = 'grabbing';
            el.classList.add('sf-annotation-active');
        }

        this.dragging = {
            id: id,
            startX: clientX,
            startY: clientY,
            origX: ann.x,
            origY: ann.y,
            frame: frame
        };

        this.activeAnnotationId = id;
    },

    _onDragMove: function (clientX, clientY) {
        if (!this.dragging) return;

        const frame = this.dragging.frame;
        const rect = frame.getBoundingClientRect();

        const deltaX = clientX - this.dragging.startX;
        const deltaY = clientY - this.dragging.startY;

        const deltaXPercent = (deltaX / rect.width) * 100;
        const deltaYPercent = (deltaY / rect.height) * 100;

        const newX = Math.max(5, Math.min(95, this.dragging.origX + deltaXPercent));
        const newY = Math.max(5, Math.min(95, this.dragging.origY + deltaYPercent));

        const el = document.getElementById(this.dragging.id);
        if (el) {
            el.style.left = newX + '%';
            el.style.top = newY + '%';
        }

        const ann = this.annotations.find(a => a.id === this.dragging.id);
        if (ann) {
            ann.x = newX;
            ann.y = newY;
        }
    },

    _onDragEnd: function () {
        if (!this.dragging) return;

        const el = document.getElementById(this.dragging.id);
        if (el) {
            el.style.cursor = 'grab';
            el.classList.remove('sf-annotation-active');
        }

        this._justDragged = true;
        setTimeout(() => {
            this._justDragged = false;
        }, 100);

        this._saveAnnotations();
        this.dragging = null;
    },

    _rotateAnnotation: function (id, degrees) {
        const ann = this.annotations.find(a => a.id === id);
        if (!ann) return;

        ann.rotation = ((ann.rotation || 0) + degrees) % 360;

        const el = document.getElementById(id);
        if (el) {
            const imgWrapper = el.querySelector('.sf-annotation-image');
            if (imgWrapper) {
                imgWrapper.style.transform = `rotate(${ann.rotation}deg)`;
            }
        }

        this._saveAnnotations();
    },

    _removeAnnotation: function (id) {
        const idx = this.annotations.findIndex(a => a.id === id);
        if (idx !== -1) {
            this.annotations.splice(idx, 1);
        }

        const el = document.getElementById(id);
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }

        this._saveAnnotations();

        if (this.activeAnnotationId === id) {
            this.activeAnnotationId = null;
        }
    },

    _clearAll: function () {
        this.annotations = [];
        document.querySelectorAll('.sf-annotation').forEach(el => {
            if (el.parentNode) el.parentNode.removeChild(el);
        });

        this.dragging = null;
        this.activeAnnotationId = null;
        this._saveAnnotations();
    },

    _saveAnnotations: function () {
        const input =
            document.getElementById('sf-annotations-data') ||
            document.getElementById('sf-annotations-data-green');

        if (input) {
            input.value = JSON.stringify(this.annotations);
        }
    },

    _loadAnnotations: function () {
        const input =
            document.getElementById('sf-annotations-data') ||
            document.getElementById('sf-annotations-data-green');

        if (!input || !input.value) return;

        try {
            const loaded = JSON.parse(input.value);

            loaded.forEach(ann => {
                // Tarkista onko jo muistissa JA renderöity
                const existsInMemory = this.annotations.find(a => a.id === ann.id);
                const existsInDom = document.getElementById(ann.id);

                if (!existsInMemory && !existsInDom) {
                    this.annotations.push(ann);
                    const frame = document.getElementById(ann.frameId);
                    if (frame) {
                        this._renderAnnotation(frame, ann);
                    }
                }
            });
        } catch (e) {
            console.warn('Failed to load annotations:', e);
        }
    },

    hideForCapture: function () {
        document.querySelectorAll('.sf-annotation-controls').forEach(el => {
            el.style.display = 'none';
        });
        document.querySelectorAll('.sf-annotation').forEach(el => {
            el.classList.remove('sf-annotation-active');
        });
    },

    showAfterCapture: function () {
        document.querySelectorAll('.sf-annotation-controls').forEach(el => {
            el.style.display = '';
        });
    }
};

// ES-moduuli-export init-funktiolle
export function initAnnotations() {
    // KORJATTU: Etsitään OIKEAT ID:t jotka preview_tools.php luo
    const hasAnnotationPanel =
        document.getElementById('sfAnnotationsPanel') ||       // RED/YELLOW
        document.getElementById('sfAnnotationsPanelGreen') ||  // GREEN
        document.querySelector('.sf-annotations-compact');     // Fallback

    if (hasAnnotationPanel) {
        Annotations.init();
    } else {
        console.warn('initAnnotations: No annotation panel found');
    }
}

if (typeof window !== 'undefined') {
    window.Annotations = Annotations;
}

export default Annotations;