window.SFImageEditor = (() => {
    let img = null;

    // Image transform (pan/zoom)
    let transform = { x: 0, y: 0, scale: 1, rotation: 0 };
    let draggingImage = false;
    let dragStart = null;
    let pendingPan = false;

    // Annotation tool + annotations
    let currentTool = null; // 'arrow','circle','crash','warning','injury','cross' or null
    let annotations = [];   // {id,type:'icon'|'text', tool, x,y, size, text?}

    // Dragging annotation
    let draggingAnnoId = null;
    let draggingAnnoOffset = null;

    let selectedAnnoId = null;
    let didDrag = false;
    let downPos = null;
    let lastPointer = { x: 0, y: 0 };

    // Touch handling
    let touchStartDist = 0;
    let touchStartScale = 1;
    let touchStartTransform = null;

    let _eventsBound = false;

    // UUSI: raf-throttle state emit (toolbar seuraa dragissa)
    let _stateRaf = null;

    function _emitState() {
        const selected = selectedAnnoId
            ? (annotations.find(a => a && a.id === selectedAnnoId) || null)
            : null;

        document.dispatchEvent(new CustomEvent('sf:editor-state', {
            detail: {
                // aktiivinen "placement tool" (kun käyttäjä aikoo lisätä uuden)
                tool: currentTool,

                // valittu olemassa oleva merkintä (kun käyttäjä klikkaa merkintää kuvasta)
                selectedId: selected ? selected.id : null,
                selectedType: selected ? selected.type : null,

                // sijainti (canvas coords) → toolbar voidaan ankkuroida merkintään
                selectedX: selected ? Number(selected.x || 0) : null,
                selectedY: selected ? Number(selected.y || 0) : null,

                // icon-only
                selectedTool: (selected && selected.type === 'icon') ? (selected.tool || null) : null,
                selectedRot: (selected && selected.type === 'icon') ? Number(selected.rot || 0) : null,

                // icon + blur
                selectedSize: (selected && (selected.type === 'icon' || selected.type === 'blur'))
                    ? Number(selected.size || (selected.type === 'icon' ? 72 : 300))
                    : null,

                // text-only
                selectedText: (selected && selected.type === 'text') ? String(selected.text || '') : null,
                selectedTextSize: (selected && selected.type === 'text') ? Number(selected.size || 32) : null
            }
        }));
    }

    // UUSI: päivitä UI (toolbar) myös dragin aikana, mutta throttlattuna (1 / frame)
    function _emitStateThrottled() {
        if (_stateRaf) return;
        _stateRaf = requestAnimationFrame(() => {
            _stateRaf = null;
            _emitState();
        });
    }

    function _setSelected(idOrNull) {
        selectedAnnoId = idOrNull || null;
        _emitState();
        draw();
    }

    function changeSelectedSize(delta) {
        if (!selectedAnnoId) return;
        const a = annotations.find(v => v && v.id === selectedAnnoId);
        if (!a) return;

        // ICON
        if (a.type === 'icon') {
            const min = 20;
            const max = 500;

            const cur = Number(a.size || 72);
            const next = Math.max(min, Math.min(max, cur + Number(delta || 0)));
            if (next === cur) return;

            a.size = next;
            _emitState();
            draw();
            return;
        }

        // TEXT
        if (a.type === 'text') {
            const min = 12;
            const max = 200;

            const cur = Number(a.size || 32);
            const next = Math.max(min, Math.min(max, cur + Number(delta || 0)));
            if (next === cur) return;

            a.size = next;
            _emitState();
            draw();
            return;
        }

        // BLUR
        if (a.type === 'blur') {
            const min = 20;
            const max = 500;

            const cur = Number(a.size || 300);
            const next = Math.max(min, Math.min(max, cur + Number(delta || 0)));
            if (next === cur) return;

            a.size = next;
            _emitState();
            draw();
            return;
        }
    }

    const iconFiles = {
        arrow: 'arrow-red.png',
        circle: 'circle-red.png',
        crash: 'crash.png',
        warning: 'warning.png',
        injury: 'injury.png',
        cross: 'cross-red.png'
    };

    const iconCache = {}; // tool -> Image()

    function _getCanvas() {
        return document.getElementById('sf-edit-img-canvas');
    }

    const CANVAS_W = 1920;
    const CANVAS_H = 1080;

    function _resizeCanvasToDisplay() {
        const canvas = _getCanvas();
        if (!canvas) return;

        if (canvas.width !== CANVAS_W) canvas.width = CANVAS_W;
        if (canvas.height !== CANVAS_H) canvas.height = CANVAS_H;
    }

    function _eventToCanvasXY(e) {
        const canvas = _getCanvas();
        const rect = canvas.getBoundingClientRect();

        const sx = canvas.width / rect.width;
        const sy = canvas.height / rect.height;

        return {
            x: (e.clientX - rect.left) * sx,
            y: (e.clientY - rect.top) * sy
        };
    }

    function _baseUrl() {
        return (window.SF_BASE_URL || '').replace(/\/$/, '');
    }

    function _iconUrl(tool) {
        // Assumption: same icon files as preview annotation system
        return `${_baseUrl()}/assets/img/annotations/${iconFiles[tool]}`;
    }

    function _ensureIcon(tool, cb) {
        if (!iconFiles[tool]) return cb(null);

        if (iconCache[tool] && iconCache[tool].complete) return cb(iconCache[tool]);

        const im = iconCache[tool] || new Image();
        iconCache[tool] = im;

        im.onload = () => cb(im);
        im.onerror = () => cb(null);

        if (!im.src) im.src = _iconUrl(tool);
        else if (im.complete) cb(im);
    }

    function setup(src, initialState = null) {
        const canvas = _getCanvas();
        _resizeCanvasToDisplay();

        img = new window.Image();
        img.onload = () => {
            // Default fit+center if no saved transform
            const hasSaved =
                initialState &&
                typeof initialState === 'object' &&
                initialState.transform &&
                typeof initialState.transform === 'object' &&
                typeof initialState.transform.scale !== 'undefined';

            if (!hasSaved && canvas) {
                const scaleX = canvas.width / img.width;
                const scaleY = canvas.height / img.height;

                // COVER: täyttää alueen oletuksena
                const scale = Math.max(scaleX, scaleY);

                transform = {
                    scale: scale,
                    x: (canvas.width - img.width * scale) / 2,
                    y: (canvas.height - img.height * scale) / 2,
                    rotation: 0
                };
            }
            // Oletustyökalu: teksti
            currentTool = 'text';
            _emitState();
            draw();
        };
        img.src = src;

        // Load state
        if (initialState && typeof initialState === 'object') {
            if (initialState.transform && typeof initialState.transform === 'object') {
                transform = {
                    x: Number(initialState.transform.x ?? transform.x ?? 0),
                    y: Number(initialState.transform.y ?? transform.y ?? 0),
                    scale: Number(initialState.transform.scale ?? transform.scale ?? 1),
                    rotation: Number(initialState.transform.rotation ?? transform.rotation ?? 0)
                };
            }

            if (Array.isArray(initialState.annotations)) {
                annotations = initialState.annotations;
            } else {
                annotations = [];
            }
        } else {
            annotations = [];
        }

        // Warm icon cache for smoother first placement
        Object.keys(iconFiles).forEach(tool => _ensureIcon(tool, () => { }));

        draw();
    }
    function drawSafeZone(ctx, canvas) {
        const cw = canvas.width;   // 1920
        const ch = canvas.height;  // 1080

        // === 1:1 Square safe zone (centered) ===
        const squareSize = Math.min(cw, ch); // 1080
        const squareX = (cw - squareSize) / 2; // 420
        const squareY = (ch - squareSize) / 2; // 0

        ctx.save();

        // --- Light dimming outside the 1:1 area ---
        ctx.fillStyle = 'rgba(0, 0, 0, 0.25)';

        // Left strip
        if (squareX > 0) {
            ctx.fillRect(0, 0, squareX, ch);
        }
        // Right strip
        if (squareX + squareSize < cw) {
            ctx.fillRect(squareX + squareSize, 0, cw - squareX - squareSize, ch);
        }
        // Top strip (if square doesn't start at top)
        if (squareY > 0) {
            ctx.fillRect(squareX, 0, squareSize, squareY);
        }
        // Bottom strip (if square doesn't end at bottom)
        if (squareY + squareSize < ch) {
            ctx.fillRect(squareX, squareY + squareSize, squareSize, ch - squareY - squareSize);
        }

        // --- Corner marks (L-shaped) for 1:1 area ---
        const cornerLen = 30;
        const cornerWidth = 3;
        ctx.fillStyle = 'rgba(255, 255, 255, 0.95)';

        // Top-left corner
        ctx.fillRect(squareX, squareY, cornerLen, cornerWidth);
        ctx.fillRect(squareX, squareY, cornerWidth, cornerLen);

        // Top-right corner
        ctx.fillRect(squareX + squareSize - cornerLen, squareY, cornerLen, cornerWidth);
        ctx.fillRect(squareX + squareSize - cornerWidth, squareY, cornerWidth, cornerLen);

        // Bottom-left corner
        ctx.fillRect(squareX, squareY + squareSize - cornerWidth, cornerLen, cornerWidth);
        ctx.fillRect(squareX, squareY + squareSize - cornerLen, cornerWidth, cornerLen);

        // Bottom-right corner
        ctx.fillRect(squareX + squareSize - cornerLen, squareY + squareSize - cornerWidth, cornerLen, cornerWidth);
        ctx.fillRect(squareX + squareSize - cornerWidth, squareY + squareSize - cornerLen, cornerWidth, cornerLen);

        // --- Dual-stroke dashed line for 1:1 border ---
        // 1) Dark shadow stroke first (provides contrast on light images)
        ctx.shadowColor = 'transparent';
        ctx.shadowBlur = 0;
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.5)';
        ctx.lineWidth = 3;
        ctx.setLineDash([10, 6]);
        ctx.strokeRect(squareX, squareY, squareSize, squareSize);

        // 2) White stroke on top (provides contrast on dark images)
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.9)';
        ctx.lineWidth = 1.5;
        ctx.setLineDash([10, 6]);
        ctx.strokeRect(squareX, squareY, squareSize, squareSize);

        // --- 1:1 Label pill (top-center of square) ---
        const labelText = '1:1';
        const labelFontSize = 18;
        ctx.font = `600 ${labelFontSize}px system-ui, -apple-system, sans-serif`;
        const labelMetrics = ctx.measureText(labelText);
        const labelPadX = 12;
        const labelPadY = 6;
        const labelW = labelMetrics.width + labelPadX * 2;
        const labelH = labelFontSize + labelPadY * 2;
        const labelX = squareX + (squareSize - labelW) / 2;
        const labelY = squareY + 12;

        // Pill background
        ctx.fillStyle = 'rgba(0, 0, 0, 0.4)';
        ctx.beginPath();
        const pillR = labelH / 2;
        if (typeof ctx.roundRect === 'function') {
            ctx.roundRect(labelX, labelY, labelW, labelH, pillR);
        } else {
            ctx.moveTo(labelX + pillR, labelY);
            ctx.arcTo(labelX + labelW, labelY, labelX + labelW, labelY + labelH, pillR);
            ctx.arcTo(labelX + labelW, labelY + labelH, labelX, labelY + labelH, pillR);
            ctx.arcTo(labelX, labelY + labelH, labelX, labelY, pillR);
            ctx.arcTo(labelX, labelY, labelX + labelW, labelY, pillR);
            ctx.closePath();
        }
        ctx.fill();
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.5)';
        ctx.lineWidth = 1;
        ctx.setLineDash([]);
        ctx.stroke();

        // Pill text
        ctx.fillStyle = 'rgba(255, 255, 255, 0.95)';
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'center';
        ctx.fillText(labelText, labelX + labelW / 2, labelY + labelH / 2);

        // === 16:9 Landscape label (top-left of canvas) ===
        const landscapeText = '16:9';
        const lFontSize = 16;
        ctx.font = `600 ${lFontSize}px system-ui, -apple-system, sans-serif`;
        const lMetrics = ctx.measureText(landscapeText);
        const lPadX = 10;
        const lPadY = 5;
        const lW = lMetrics.width + lPadX * 2;
        const lH = lFontSize + lPadY * 2;
        const lX = 12;
        const lY = 12;

        // Landscape pill background
        ctx.fillStyle = 'rgba(16, 185, 129, 0.3)';
        ctx.beginPath();
        const lPillR = lH / 2;
        if (typeof ctx.roundRect === 'function') {
            ctx.roundRect(lX, lY, lW, lH, lPillR);
        } else {
            ctx.moveTo(lX + lPillR, lY);
            ctx.arcTo(lX + lW, lY, lX + lW, lY + lH, lPillR);
            ctx.arcTo(lX + lW, lY + lH, lX, lY + lH, lPillR);
            ctx.arcTo(lX, lY + lH, lX, lY, lPillR);
            ctx.arcTo(lX, lY, lX + lW, lY, lPillR);
            ctx.closePath();
        }
        ctx.fill();
        ctx.strokeStyle = 'rgba(16, 185, 129, 0.5)';
        ctx.lineWidth = 1;
        ctx.setLineDash([]);
        ctx.stroke();

        // Landscape pill text
        ctx.fillStyle = 'rgba(16, 185, 129, 0.9)';
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'center';
        ctx.fillText(landscapeText, lX + lW / 2, lY + lH / 2);

        // === 16:9 border (full canvas edge) ===
        ctx.strokeStyle = 'rgba(16, 185, 129, 0.4)';
        ctx.lineWidth = 2;
        ctx.setLineDash([8, 8]);
        ctx.strokeRect(2, 2, cw - 4, ch - 4);

        // Reset
        ctx.setLineDash([]);
        ctx.textAlign = 'start';
        ctx.textBaseline = 'alphabetic';

        ctx.restore();
    }

    function draw() {
        const canvas = _getCanvas();
        if (!canvas) return;
        const ctx = canvas.getContext('2d');

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        // background
        ctx.fillStyle = '#fafafa';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // image
        if (img) {
            drawImageWithTransform(ctx);
        }

        // TURVAVIIVAT - näytä näkyvä alue esikatselussa
        drawSafeZone(ctx, canvas);

        // annotations (render in canvas coords)
        if (annotations && annotations.length) {
            annotations.forEach(a => {
                if (!a) return;

                // --- TEXT ---
                if (a.type === 'text') {
                    const fontSize = Number(a.size || 28);

                    ctx.save();

                    const text = String(a.text || '').replace(/\r\n/g, '\n');
                    if (!text.trim()) { ctx.restore(); return; }

                    const x = Number(a.x ?? 0);
                    const y = Number(a.y ?? 0);

                    // Draw text background for better visibility
                    ctx.font = `bold ${fontSize}px "Open Sans", Arial, sans-serif`;
                    ctx.textBaseline = 'top';

                    // Split text into lines
                    const lines = text.split('\n');
                    const lineHeight = fontSize * 1.3;  // Line spacing

                    // Calculate the width of the longest line
                    let maxWidth = 0;
                    lines.forEach(line => {
                        const w = ctx.measureText(line).width;
                        if (w > maxWidth) maxWidth = w;
                    });

                    const padding = 12;
                    const totalHeight = (lines.length - 1) * lineHeight + fontSize;

                    // Improved semi-transparent background with rounded corners
                    ctx.fillStyle = 'rgba(0, 0, 0, 0.75)';
                    const bgX = x - padding;
                    const bgY = y - padding;
                    const bgW = maxWidth + padding * 2;
                    const bgH = totalHeight + padding * 2;
                    const radius = 8;

                    ctx.beginPath();
                    // Use roundRect if available, otherwise fallback to manual rounded rectangle
                    if (typeof ctx.roundRect === 'function') {
                        ctx.roundRect(bgX, bgY, bgW, bgH, radius);
                    } else {
                        // Manual rounded rectangle fallback for older browsers
                        const r = Math.min(radius, Math.min(bgW, bgH) / 2);
                        ctx.moveTo(bgX + r, bgY);
                        ctx.arcTo(bgX + bgW, bgY, bgX + bgW, bgY + bgH, r);
                        ctx.arcTo(bgX + bgW, bgY + bgH, bgX, bgY + bgH, r);
                        ctx.arcTo(bgX, bgY + bgH, bgX, bgY, r);
                        ctx.arcTo(bgX, bgY, bgX + bgW, bgY, r);
                        ctx.closePath();
                    }
                    ctx.fill();

                    // White text with enhanced shadow for better readability
                    ctx.fillStyle = '#ffffff';
                    ctx.shadowColor = 'rgba(0, 0, 0, 0.5)';
                    ctx.shadowBlur = 2;
                    ctx.shadowOffsetX = 0;
                    ctx.shadowOffsetY = 0;

                    // Draw each line of text separately
                    lines.forEach((line, index) => {
                        ctx.fillText(line, x, y + (index * lineHeight));
                    });

                    // Draw selection highlight if this annotation is selected
                    if (a.id === selectedAnnoId) {
                        ctx.shadowColor = 'transparent';
                        ctx.shadowBlur = 0;
                        ctx.shadowOffsetX = 0;
                        ctx.shadowOffsetY = 0;
                        ctx.strokeStyle = '#3b82f6';
                        ctx.lineWidth = 2;
                        ctx.setLineDash([5, 5]);
                        ctx.strokeRect(bgX - 4, bgY - 4, bgW + 8, bgH + 8);
                        ctx.setLineDash([]);
                    }

                    ctx.restore();
                    return;
                }

                // --- ICON ---
                if (a.type === 'icon') {
                    const tool = a.tool;
                    const size = Number(a.size || 140);
                    const x = Number(a.x || 0);
                    const y = Number(a.y || 0);
                    const rot = Number(a.rot || 0);

                    _ensureIcon(tool, (im) => {
                        if (!im) return;

                        ctx.save();
                        ctx.translate(x, y);
                        if (rot) ctx.rotate((rot * Math.PI) / 180);

                        ctx.drawImage(im, -size / 2, -size / 2, size, size);

                        // Draw selection highlight if this annotation is selected
                        if (a.id === selectedAnnoId) {
                            ctx.strokeStyle = '#3b82f6';
                            ctx.lineWidth = 2;
                            ctx.setLineDash([5, 5]);
                            ctx.strokeRect(-size / 2 - 4, -size / 2 - 4, size + 8, size + 8);
                            ctx.setLineDash([]);
                        }

                        ctx.restore();
                    });

                    return;
                }

                // --- BLUR ---
                if (a.type === 'blur') {
                    const size = Number(a.size || 300);
                    const ax = Number(a.x || 0);
                    const ay = Number(a.y || 0);

                    ctx.save();

                    // Selection highlight (drawn before clip so it appears outside the circle)
                    if (a.id === selectedAnnoId) {
                        ctx.strokeStyle = '#3b82f6';
                        ctx.lineWidth = 2;
                        ctx.setLineDash([5, 5]);
                        ctx.strokeRect(ax - size / 2 - 4, ay - size / 2 - 4, size + 8, size + 8);
                        ctx.setLineDash([]);
                    }

                    // Clip to circle
                    ctx.beginPath();
                    ctx.arc(ax, ay, size / 2, 0, Math.PI * 2);
                    ctx.clip();

                    // Draw blurred version of the background image inside the circle
                    ctx.filter = 'blur(40px)';
                    drawImageWithTransform(ctx);

                    ctx.restore();
                    return;
                }
            });
        }
    }
    function drawImageWithTransform(ctx) {
        if (!img) return;

        const rotation = Number(transform.rotation || 0);

        ctx.save();
        ctx.translate(transform.x, transform.y);
        ctx.scale(transform.scale, transform.scale);

        if (rotation !== 0) {
            ctx.translate(img.width / 2, img.height / 2);
            ctx.rotate((rotation * Math.PI) / 180);
            ctx.translate(-img.width / 2, -img.height / 2);
        }

        ctx.drawImage(img, 0, 0);
        ctx.restore();
    }
    function _hitTestAnnotation(x, y) {
        // Iterate from topmost
        for (let i = annotations.length - 1; i >= 0; i--) {
            const a = annotations[i];
            if (!a) continue;

            if (a.type === 'icon') {
                const size = Number(a.size || 64);
                const ax = Number(a.x || 0);
                const ay = Number(a.y || 0);
                const left = ax - size / 2;
                const top = ay - size / 2;
                if (x >= left && x <= left + size && y >= top && y <= top + size) {
                    return a;
                }
            }

            if (a.type === 'blur') {
                const size = Number(a.size || 300);
                const ax = Number(a.x || 0);
                const ay = Number(a.y || 0);
                const left = ax - size / 2;
                const top = ay - size / 2;
                if (x >= left && x <= left + size && y >= top && y <= top + size) {
                    return a;
                }
            }

            if (a.type === 'text') {
                // Text bbox (approx) – matchaa paremmin editorin fonttikokoa ja taustalaatikkoa
                const text = String(a.text || '').replace(/\r\n/g, '\n');

                // Jos ihan oikeasti tyhjä (pelkkää whitespacea), ei piirretä mitään
                if (!text.trim()) continue;

                const ax = Number(a.x || 0);
                const ay = Number(a.y || 0);
                const fontSize = Number(a.size || 28);
                const lineHeight = fontSize * 1.3;
                const lines = text.split('\n');

                // Calculate max line length (approximate, 0.6 is empirical estimate of char width to font size ratio)
                const maxLen = Math.max(...lines.map(l => (l || '').length), 1);
                const padding = 12;

                const w = Math.min(980, Math.max(140, maxLen * (fontSize * 0.6)));
                const h = lines.length * lineHeight;

                // Hit test matches the background box: (x - padding, y - padding) to (x + w + padding, y + h + padding)
                if (x >= ax - padding && x <= ax + w + padding && y >= ay - padding && y <= ay + h + padding) {
                    return a;
                }
            }
        }
        return null;
    }

    function _getTouchPoint(touch) {
        return _eventToCanvasXY({
            clientX: touch.clientX,
            clientY: touch.clientY
        });
    }

    function _getTouchDistance(touch1, touch2) {
        return Math.hypot(
            touch2.clientX - touch1.clientX,
            touch2.clientY - touch1.clientY
        );
    }

    function _getTouchCenter(touch1, touch2) {
        return {
            x: (touch1.clientX + touch2.clientX) / 2,
            y: (touch1.clientY + touch2.clientY) / 2
        };
    }

    function initCanvasEvents() {
        if (_eventsBound) return;
        _eventsBound = true;

        const canvas = _getCanvas();
        if (!canvas) return;

        // Set default cursor style
        canvas.style.cursor = 'crosshair';

        _resizeCanvasToDisplay();
        window.addEventListener('resize', () => {
            _resizeCanvasToDisplay();
            draw();
        });

        canvas.addEventListener('mousedown', (e) => {
            const p = _eventToCanvasXY(e);
            const x = p.x;
            const y = p.y;

            didDrag = false;
            downPos = { x, y };
            lastPointer = { x, y };

            const hit = _hitTestAnnotation(x, y);
            if (hit) {
                _setSelected(hit.id);
                draggingAnnoId = hit.id;
                draggingAnnoOffset = { dx: x - hit.x, dy: y - hit.y };
                return;
            }

            // Clicked background
            _setSelected(null);

            pendingPan = true;
            dragStart = { x: x - transform.x, y: y - transform.y };
        });

        canvas.addEventListener('mouseup', () => {
            pendingPan = false;
            draggingImage = false;
            draggingAnnoId = null;
            draggingAnnoOffset = null;
            // Reset cursor after drag
            canvas.style.cursor = 'crosshair';
        });

        canvas.addEventListener('mouseout', () => {
            pendingPan = false;
            draggingImage = false;
            draggingAnnoId = null;
            draggingAnnoOffset = null;
            // Reset cursor when leaving canvas
            canvas.style.cursor = 'crosshair';
        });

        canvas.addEventListener('mousemove', (e) => {
            const p = _eventToCanvasXY(e);
            const x = p.x;
            const y = p.y;
            lastPointer = { x, y };

            if (downPos && (Math.abs(x - downPos.x) > 2 || Math.abs(y - downPos.y) > 2)) {
                didDrag = true;
            }

            // Start panning only after user actually drags on background
            if (pendingPan && didDrag) {
                pendingPan = false;
                draggingImage = true;

                // ÄLÄ nollaa placement-toolia panoroinnissa.
                // Käyttäjän pitää voida jatkaa samalla työkalulla heti pan/drag jälkeen.
                _emitState();
            }

            if (draggingAnnoId && draggingAnnoOffset) {
                // Change cursor to grabbing while dragging
                canvas.style.cursor = 'grabbing';

                const a = annotations.find(v => v && v.id === draggingAnnoId);
                if (a) {
                    a.x = Number(x - draggingAnnoOffset.dx);
                    a.y = Number(y - draggingAnnoOffset.dy);
                    draw();

                    // UUSI: toolbar seuraa mukana (päivittyy 1/frame)
                    _emitStateThrottled();
                }
                return;
            }

            if (draggingImage) {
                transform.x = x - dragStart.x;
                transform.y = y - dragStart.y;
                draw();
            }

            // Update cursor based on what's under the mouse (when not dragging)
            if (!draggingAnnoId && !draggingImage) {
                const hit = _hitTestAnnotation(x, y);
                canvas.style.cursor = hit ? 'move' : 'crosshair';
            }
        });

        canvas.addEventListener('click', (e) => {
            // If user dragged, do NOT place a new annotation
            if (didDrag) {
                didDrag = false;
                return;
            }

            const p = _eventToCanvasXY(e);
            const x = p.x;
            const y = p.y;

            // If click hits existing annotation, just select (no new)
            const hit = _hitTestAnnotation(x, y);
            if (hit) {
                _setSelected(hit.id);
                return;
            }

            if (!currentTool) return;

            // ✅ TEXT tool ei lisää "icon text" -merkintää (joka aiheuttaa tyhjän kehikon)
            if (currentTool === 'text') {
                lastPointer = { x, y };
                document.dispatchEvent(new CustomEvent('sf:editor-request-text', { detail: { x, y } }));
                return;
            }

            if (currentTool === 'blur') {
                addBlur(x, y);
                return;
            }

            addIcon(currentTool, x, y);
        }, { passive: true });

        function _zoomAt(cx, cy, delta) {
            const oldScale = transform.scale;
            const newScale = Math.max(0.1, Math.min(10, oldScale + delta));
            if (newScale === oldScale) return;

            // Keep point (cx,cy) stable while zooming
            transform.x = cx - (cx - transform.x) * (newScale / oldScale);
            transform.y = cy - (cy - transform.y) * (newScale / oldScale);
            transform.scale = newScale;
            draw();
        }

        canvas.addEventListener('wheel', (e) => {
            e.preventDefault();

            // Zoomaa osoittimen kohdalta
            const p = _eventToCanvasXY(e);
            const scaleStep = (e.deltaY < 0) ? 0.05 : -0.05;

            _zoomAt(p.x, p.y, scaleStep);
        }, { passive: false });

        // ===== TOUCH EVENTS (Mobile support) =====

        function _resetTouchState() {
            pendingPan = false;
            draggingImage = false;
            draggingAnnoId = null;
            draggingAnnoOffset = null;
            // Issue 3: Reset dragStart to ensure pan works after pinch
            dragStart = null;
            didDrag = false;
            downPos = null;
            touchStartDist = 0;
            touchStartScale = 1;
            touchStartTransform = null;
        }

        canvas.addEventListener('touchstart', (e) => {
            e.preventDefault();

            if (e.touches.length === 1) {
                // Single finger - pan or drag annotation
                const touch = e.touches[0];
                const p = _getTouchPoint(touch);
                const x = p.x;
                const y = p.y;

                didDrag = false;
                downPos = { x, y };
                lastPointer = { x, y };

                // Check if touching annotation
                const hit = _hitTestAnnotation(x, y);
                if (hit) {
                    _setSelected(hit.id);
                    draggingAnnoId = hit.id;
                    draggingAnnoOffset = { dx: x - hit.x, dy: y - hit.y };
                    return;
                }

                // Touching background - prepare to pan
                _setSelected(null);
                pendingPan = true;
                dragStart = { x: x - transform.x, y: y - transform.y };

            } else if (e.touches.length === 2) {
                // Two fingers - pinch zoom
                const t1 = e.touches[0];
                const t2 = e.touches[1];

                // CRITICAL: Store current transform BEFORE calculating distance
                touchStartTransform = {
                    x: transform.x,
                    y: transform.y,
                    scale: transform.scale
                };
                touchStartDist = _getTouchDistance(t1, t2);
                touchStartScale = transform.scale;

                // Cancel any pending pan
                pendingPan = false;
                draggingImage = false;
                draggingAnnoId = null;
            }
        }, { passive: false });

        canvas.addEventListener('touchmove', (e) => {
            e.preventDefault();

            if (e.touches.length === 1) {
                const touch = e.touches[0];
                const p = _getTouchPoint(touch);
                const x = p.x;
                const y = p.y;

                lastPointer = { x, y };

                if (downPos && (Math.abs(x - downPos.x) > 2 || Math.abs(y - downPos.y) > 2)) {
                    didDrag = true;
                }

                // Dragging annotation
                if (draggingAnnoId && draggingAnnoOffset) {
                    const a = annotations.find(v => v && v.id === draggingAnnoId);
                    if (a) {
                        a.x = Number(x - draggingAnnoOffset.dx);
                        a.y = Number(y - draggingAnnoOffset.dy);
                        draw();
                        _emitStateThrottled();
                    }
                    return;
                }

                // Start panning after drag threshold
                if (pendingPan && didDrag) {
                    pendingPan = false;
                    draggingImage = true;
                    _emitState();
                }

                // Pan image
                if (draggingImage) {
                    transform.x = x - dragStart.x;
                    transform.y = y - dragStart.y;
                    draw();
                }

            } else if (e.touches.length === 2) {
                // Pinch zoom
                const t1 = e.touches[0];
                const t2 = e.touches[1];
                const currentDist = _getTouchDistance(t1, t2);

                if (touchStartDist < 10 || currentDist < 10) return;

                // Get center point of the two fingers in SCREEN coordinates
                const centerX = (t1.clientX + t2.clientX) / 2;
                const centerY = (t1.clientY + t2.clientY) / 2;

                // Convert to canvas coordinates
                const canvas = _getCanvas();
                if (!canvas) return;
                const rect = canvas.getBoundingClientRect();
                const canvasCenterX = centerX - rect.left;
                const canvasCenterY = centerY - rect.top;

                // Calculate new scale
                const scaleFactor = currentDist / touchStartDist;
                const newScale = Math.max(0.1, Math.min(10, touchStartScale * scaleFactor));

                // Zoom around pinch center - adjust transform so center point stays fixed
                const scaleRatio = newScale / touchStartTransform.scale;

                transform.scale = newScale;
                transform.x = canvasCenterX - (canvasCenterX - touchStartTransform.x) * scaleRatio;
                transform.y = canvasCenterY - (canvasCenterY - touchStartTransform.y) * scaleRatio;

                draw();
            }
        }, { passive: false });

        canvas.addEventListener('touchend', (e) => {
            e.preventDefault();

            if (e.touches.length === 0) {
                // All fingers lifted

                // Check if this was a TAP (not a drag) for adding annotation
                if (!didDrag && downPos && currentTool) {
                    const tapX = downPos.x;
                    const tapY = downPos.y;

                    // Check if tapping on existing annotation
                    const hit = _hitTestAnnotation(tapX, tapY);
                    if (hit) {
                        _setSelected(hit.id);
                    } else {
                        // Add new annotation at tap location
                        if (currentTool === 'text') {
                            lastPointer = { x: tapX, y: tapY };
                            document.dispatchEvent(new CustomEvent('sf:editor-request-text', { detail: { x: tapX, y: tapY } }));
                        } else if (currentTool === 'blur') {
                            addBlur(tapX, tapY);
                        } else {
                            addIcon(currentTool, tapX, tapY);
                        }
                    }
                }

                // Reset all state
                _resetTouchState();
                didDrag = false;
                downPos = null;
            } else if (e.touches.length === 1) {
                // One finger still down - switch back to pan mode
                // Issue 3: Properly re-enable pan after pinch zoom ends
                const touch = e.touches[0];
                const p = _getTouchPoint(touch);

                // Directly enable pan mode without requiring additional movement
                downPos = { x: p.x, y: p.y };
                dragStart = { x: p.x - transform.x, y: p.y - transform.y };
                draggingImage = true;  // Enable pan immediately
                pendingPan = false;
                didDrag = false;

                // Clear annotation dragging
                draggingAnnoId = null;
                draggingAnnoOffset = null;

                // Reset pinch state
                touchStartDist = 0;
                touchStartScale = 1;
                touchStartTransform = null;
            }
        }, { passive: false });

        canvas.addEventListener('touchcancel', () => {
            _resetTouchState();
        });
    }

    function setTool(tool) {
        currentTool = tool || null;

        // Kun valitaan placement tool, poistetaan valinta olemassa olevasta merkinnästä
        // (ettei UI jää "valittu merkintä" -tilaan).
        if (currentTool) {
            selectedAnnoId = null;
        }

        _emitState();
        draw();
    }

    function addIcon(tool, x, y) {
        if (!tool) return;
        const id = 'a' + Math.random().toString(16).slice(2);

        annotations.push({
            id,
            type: 'icon',
            tool,
            x: Number(x),
            y: Number(y),
            size: 140
        });

        // Valitse juuri lisätty -> nyt Rotate/Delete/Size/Text voidaan aktivoida
        _setSelected(id);
    }

    function addBlur(x, y) {
        const id = 'a' + Math.random().toString(16).slice(2);

        annotations.push({
            id,
            type: 'blur',
            x: Number(x),
            y: Number(y),
            size: 300
        });

        _setSelected(id);
    }

    function addLabelAt(x, y, text) {
        // Legacy API: ohjataan nykyiseen toteutukseen
        const t = String(text || '').trim();
        if (!t) return;

        // aseta osoitin ja lisää kuten addTextAt
        lastPointer = { x: Number(x), y: Number(y) };
        addTextAt(t);
    }

    function addLabel() {
        // Legacy API: ei käytössä enää
        return;
    }

    function zoom(delta) {
        const canvas = _getCanvas();
        if (!canvas) return;

        const cx = canvas.width / 2;
        const cy = canvas.height / 2;

        const oldScale = transform.scale;
        const newScale = Math.max(0.1, oldScale + delta);
        if (newScale === oldScale) return;

        transform.x = cx - (cx - transform.x) * (newScale / oldScale);
        transform.y = cy - (cy - transform.y) * (newScale / oldScale);
        transform.scale = newScale;

        draw();
    }

    function nudge(dx, dy) {
        transform.x += dx;
        transform.y += dy;
        draw();
    }
    function normalizeRotation(deg) {
        let value = Number(deg || 0) % 360;
        if (value < 0) value += 360;
        return value;
    }

    function rotateImage(delta) {
        transform.rotation = normalizeRotation(Number(transform.rotation || 0) + Number(delta || 0));
        draw();
    }

    function rotateImageLeft() {
        rotateImage(-90);
    }

    function rotateImageRight() {
        rotateImage(90);
    }
    function resetFit() {
        const canvas = _getCanvas();
        if (!canvas || !img) return;

        const scaleX = canvas.width / img.width;
        const scaleY = canvas.height / img.height;

        const scale = Math.max(scaleX, scaleY);

        transform = {
            scale: scale,
            x: (canvas.width - img.width * scale) / 2,
            y: (canvas.height - img.height * scale) / 2,
            rotation: 0
        };
        draw();
    }

    function getState() {
        return {
            transform: {
                x: Number(transform.x || 0),
                y: Number(transform.y || 0),
                scale: Number(transform.scale || 1),
                rotation: Number(transform.rotation || 0)
            },
            annotations: Array.isArray(annotations) ? annotations : []
        };
    }

    function setState(stateObj) {
        if (!stateObj || typeof stateObj !== 'object') return;

        if (stateObj.transform && typeof stateObj.transform === 'object') {
            transform = {
                x: Number(stateObj.transform.x ?? transform.x),
                y: Number(stateObj.transform.y ?? transform.y),
                scale: Number(stateObj.transform.scale ?? transform.scale),
                rotation: Number(stateObj.transform.rotation ?? transform.rotation ?? 0)
            };
        }
        if (Array.isArray(stateObj.annotations)) {
            annotations = stateObj.annotations;
        }
        draw();
    }
    function deleteSelected() {
        if (!selectedAnnoId) return;
        annotations = annotations.filter(a => a && a.id !== selectedAnnoId);
        selectedAnnoId = null;
        _emitState();
        draw();
    }

    function rotateSelected() {
        if (!selectedAnnoId) return;
        const a = annotations.find(v => v && v.id === selectedAnnoId);
        if (!a || a.type !== 'icon') return;

        a.rot = Number(a.rot || 0) + 45;
        if (a.rot >= 360) a.rot = a.rot - 360;

        _emitState();
        draw();
    }

    function hasSelectedText() {
        if (!selectedAnnoId) return false;
        const a = annotations.find(v => v && v.id === selectedAnnoId);
        return !!(a && a.type === 'text');
    }

    function getSelectedText() {
        if (!selectedAnnoId) return '';
        const a = annotations.find(v => v && v.id === selectedAnnoId);
        if (!a || a.type !== 'text') return '';
        return String(a.text || '');
    }

    function updateSelectedText(newText) {
        if (!selectedAnnoId) return;
        const a = annotations.find(v => v && v.id === selectedAnnoId);
        if (!a || a.type !== 'text') return;

        a.text = String(newText || '');
        _emitState();
        draw();
    }

    function addTextAt(x, y, text = '') {
        // Yhteensopivuus:  jos kutsutaan addTextAt("joku teksti")
        if (typeof x === 'string' && typeof y === 'undefined') {
            const t = x;
            return addTextAt(lastPointer.x, lastPointer.y, t);
        }

        const canvas = _getCanvas();
        const cw = canvas ? canvas.width : CANVAS_W;
        const ch = canvas ? canvas.height : CANVAS_H;

        const t = String(text || '').replace(/\r\n/g, '\n');

        // Arvioidaan tekstilaatikon koko (sama maxWidth kuin draw():ssa)
        const lines = t.split('\n');
        const maxLen = Math.max(...lines.map(l => (l || '').length), 1);

        const maxWidth = 980;
        const approxTextW = Math.min(maxWidth, Math.max(140, maxLen * 16));
        const approxTextH = Math.max(44, lines.length * 40);

        // paddingit (hitTestissä käytetyt)
        const boxW = approxTextW + 24;
        const boxH = approxTextH + 16;

        const pad = 12;

        let px = Number(x);
        let py = Number(y);

        if (!Number.isFinite(px)) px = cw / 2;
        if (!Number.isFinite(py)) py = ch / 2;

        // Clamp: pidä laatikko varmasti canvasin sisällä
        px = Math.max(pad, Math.min(cw - boxW - pad, px));
        py = Math.max(pad, Math.min(ch - boxH - pad, py));

        // Päivitä myös lastPointer, jotta seuraava toiminto ei hyppää reunaan
        lastPointer = { x: px, y: py };

        const id = 'a' + Math.random().toString(16).slice(2);

        annotations.push({
            id,
            type: 'text',
            x: px,
            y: py,
            text: t,
            size: 32
        });

        selectedAnnoId = id;
        _emitState();
        draw();
    }

    function save() {
        // Palauttaa datan:  transform + annotations + dataURL (merkinnät kiinni)
        try {
            const canvas = _getCanvas();
            if (!canvas) {
                return { dataURL: "", transform: { ...transform }, annotations: Array.isArray(annotations) ? annotations : [] };
            }
            // Luo export-canvas ja kutsu drawForExport
            const exportCanvas = document.createElement('canvas');
            exportCanvas.width = canvas.width;
            exportCanvas.height = canvas.height;
            const exportCtx = exportCanvas.getContext('2d');
            drawForExport(exportCtx, exportCanvas);
            const dataUrl = exportCanvas.toDataURL('image/png');
            return { dataURL: dataUrl, transform: { ...transform }, annotations: Array.isArray(annotations) ? annotations : [] };
        } catch (e) {
            console.error("SFImageEditor.save failed:", e);
            return { dataURL: "", transform: { ...transform }, annotations: Array.isArray(annotations) ? annotations : [] };
        }
    }

    // Shared helper: draw annotations onto any 2D context
    /**
     * @param {CanvasRenderingContext2D} ctx
     * @param {Array} anns
     * @param {function(CanvasRenderingContext2D):void} [drawBgFn] - Optional callback that
     *   re-draws the background image onto the given context. Used by blur annotations to
     *   render the blurred background inside the clip region. If omitted, falls back to the
     *   module-level drawImageWithTransform (correct for live draw and drawForExport).
     */
    function _drawAnnotationsOnCtx(ctx, anns, drawBgFn) {
        if (!anns || !anns.length) return;

        anns.forEach(a => {
            if (!a) return;

            // --- TEXT ---
            if (a.type === 'text') {
                ctx.save();

                const text = String(a.text || '').replace(/\r\n/g, '\n');
                if (!text.trim()) { ctx.restore(); return; }

                const x = Number(a.x ?? 0);
                const y = Number(a.y ?? 0);

                const fontSize = Number(a.size || 32);
                const fontFamily = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
                ctx.font = `700 ${fontSize}px ${fontFamily}`;
                ctx.textBaseline = 'top';

                const padX = 12;
                const padY = 12;
                const radius = 12;
                const maxWidth = 980;

                const rawLines = text.split('\n');
                const lines = [];
                rawLines.forEach((ln) => {
                    const words = String(ln).split(/\s+/).filter(Boolean);
                    if (!words.length) { lines.push(''); return; }
                    let line = words[0];
                    for (let i = 1; i < words.length; i++) {
                        const test = line + ' ' + words[i];
                        const w = ctx.measureText(test).width;
                        if (w > maxWidth && line.length) {
                            lines.push(line);
                            line = words[i];
                        } else {
                            line = test;
                        }
                    }
                    lines.push(line);
                });

                const lineH = Math.round(fontSize * 1.25);
                const textW = Math.max(0, ...lines.map(l => ctx.measureText(l).width));
                const textH = Math.max(1, (lines.length - 1) * lineH + Math.round(fontSize));
                const boxW = Math.min(maxWidth, textW) + padX * 2;
                const boxH = textH + padY * 2;
                const bx = x;
                const by = y;

                // Improved background style - less transparent, more polished
                ctx.fillStyle = 'rgba(0, 0, 0, 0.65)';
                ctx.strokeStyle = 'rgba(255, 255, 255, 0.2)';
                ctx.lineWidth = 1;

                const rr = (r, w, h) => Math.max(0, Math.min(r, Math.min(w, h) / 2));
                const r = rr(radius, boxW, boxH);

                ctx.beginPath();
                ctx.moveTo(bx + r, by);
                ctx.arcTo(bx + boxW, by, bx + boxW, by + boxH, r);
                ctx.arcTo(bx + boxW, by + boxH, bx, by + boxH, r);
                ctx.arcTo(bx, by + boxH, bx, by, r);
                ctx.arcTo(bx, by, bx + boxW, by, r);
                ctx.closePath();
                ctx.fill();
                ctx.stroke();

                // White text with shadow for better readability
                ctx.fillStyle = '#ffffff';
                ctx.shadowColor = 'rgba(0, 0, 0, 0.7)';
                ctx.shadowBlur = 3;
                ctx.shadowOffsetX = 1;
                ctx.shadowOffsetY = 1;

                let ty = by + padY;
                lines.forEach((l) => {
                    ctx.fillText(l, bx + padX, ty);
                    ty += lineH;
                });

                ctx.restore();
                return;
            }

            // --- ICON ---
            if (a.type === 'icon') {
                const tool = a.tool;
                const size = Number(a.size || 140);
                const ax = Number(a.x || 0);
                const ay = Number(a.y || 0);
                const rot = Number(a.rot || 0);

                const im = iconCache[tool];
                if (im && im.complete) {
                    ctx.save();
                    ctx.translate(ax, ay);
                    if (rot) ctx.rotate((rot * Math.PI) / 180);
                    ctx.drawImage(im, -size / 2, -size / 2, size, size);
                    ctx.restore();
                }
            }

            // --- BLUR ---
            if (a.type === 'blur') {
                const size = Number(a.size || 300);
                const ax = Number(a.x || 0);
                const ay = Number(a.y || 0);

                ctx.save();

                // Clip to circle
                ctx.beginPath();
                ctx.arc(ax, ay, size / 2, 0, Math.PI * 2);
                ctx.clip();

                // Draw blurred version of the background image inside the circle
                ctx.filter = 'blur(40px)';
                if (typeof drawBgFn === 'function') {
                    drawBgFn(ctx);
                } else {
                    drawImageWithTransform(ctx);
                }

                ctx.restore();
            }
        });
    }

    function drawForExport(exportCtx, exportCanvas) {
        // Käytetään samaa logiikkaa kuin draw(), mutta EI piirretä turvaviivoja
        if (!exportCanvas || !exportCtx) return;

        exportCtx.clearRect(0, 0, exportCanvas.width, exportCanvas.height);

        // background
        exportCtx.fillStyle = '#fafafa';
        exportCtx.fillRect(0, 0, exportCanvas.width, exportCanvas.height);

        // image - käytä moduulin img ja transform
        if (img && img.complete) {
            const rotation = Number(transform.rotation || 0);

            exportCtx.save();
            exportCtx.translate(transform.x, transform.y);
            exportCtx.scale(transform.scale, transform.scale);

            if (rotation !== 0) {
                exportCtx.translate(img.width / 2, img.height / 2);
                exportCtx.rotate((rotation * Math.PI) / 180);
                exportCtx.translate(-img.width / 2, -img.height / 2);
            }

            exportCtx.drawImage(img, 0, 0);
            exportCtx.restore();
        }

        // annotations (EI turvaviivoja)
        _drawAnnotationsOnCtx(exportCtx, annotations);
    }

    /**
     * Renders an image with transform and annotations to a data URL using an
     * offscreen canvas. Does not affect the visible editor state.
     *
     * @param {string} src - Image URL to render
     * @param {object|null} transformData - {x, y, scale, rotation}
     * @param {Array} annotationsArr - Array of annotation objects
     * @returns {Promise<string>} Resolves to a PNG data URL, or '' on failure
     */
    function renderToDataURL(src, transformData, annotationsArr) {
        return new Promise((resolve) => {
            if (!src || typeof src !== 'string') { resolve(''); return; }

            // Reject javascript: URIs to prevent script injection via image URL
            if (/^javascript:/i.test(src.trim())) { resolve(''); return; }

            const anns = Array.isArray(annotationsArr) ? annotationsArr.filter(Boolean) : [];

            // Pre-load any icons referenced in annotations before drawing
            const iconTools = [...new Set(
                anns.filter(a => a.type === 'icon' && iconFiles[a.tool]).map(a => a.tool)
            )];
            const iconPromises = iconTools.map(tool =>
                new Promise(res => _ensureIcon(tool, () => res()))
            );

            const image = new Image();
            image.onload = () => {
                Promise.all(iconPromises).then(() => {
                    const oc = document.createElement('canvas');
                    oc.width = CANVAS_W;
                    oc.height = CANVAS_H;
                    const ctx = oc.getContext('2d');

                    const t = {
                        x: Number(transformData?.x ?? 0),
                        y: Number(transformData?.y ?? 0),
                        scale: Number(transformData?.scale ?? 1),
                        rotation: Number(transformData?.rotation ?? 0)
                    };

                    ctx.fillStyle = '#fafafa';
                    ctx.fillRect(0, 0, CANVAS_W, CANVAS_H);

                    // Draw image with saved transform
                    ctx.save();
                    ctx.translate(t.x, t.y);
                    ctx.scale(t.scale, t.scale);
                    if (t.rotation !== 0) {
                        ctx.translate(image.width / 2, image.height / 2);
                        ctx.rotate((t.rotation * Math.PI) / 180);
                        ctx.translate(-image.width / 2, -image.height / 2);
                    }
                    ctx.drawImage(image, 0, 0);
                    ctx.restore();

                    // Draw annotations using shared helper
                    // Pass a drawBgFn so blur annotations re-render the correct image/transform
                    _drawAnnotationsOnCtx(ctx, anns, (c) => {
                        c.save();
                        c.translate(t.x, t.y);
                        c.scale(t.scale, t.scale);
                        if (t.rotation !== 0) {
                            c.translate(image.width / 2, image.height / 2);
                            c.rotate((t.rotation * Math.PI) / 180);
                            c.translate(-image.width / 2, -image.height / 2);
                        }
                        c.drawImage(image, 0, 0);
                        c.restore();
                    });

                    try {
                        resolve(oc.toDataURL('image/png'));
                    } catch (e) {
                        resolve('');
                    }
                });
            };
            image.onerror = () => resolve('');
            image.src = src;
        });
    }

    function getAllAnnotations() {
        // Return all annotations from all slots (for inline save handler)
        // This is used by the inline save handler to sync annotations to the hidden input

        // Start with stored annotations from hidden input
        const storeEl = document.getElementById('sf-edit-annotations-data');
        let allStored = {};

        if (storeEl && storeEl.value) {
            try {
                const parsed = JSON.parse(storeEl.value);
                if (parsed && typeof parsed === 'object') {
                    allStored = parsed;
                }
            } catch (e) {
                console.error('getAllAnnotations: Failed to parse stored annotations', e);
            }
        }

        // If editor is currently open, merge current slot's annotations
        if (img && annotations && annotations.length > 0) {
            const currentSlot = img.dataset?.slot || 1;
            const key = `image${currentSlot}`;
            // Update the current slot with latest editor state, filtering out null entries
            allStored[key] = annotations.filter(a => a);
        }

        return allStored;
    }

    return {
        setup, draw, initCanvasEvents,
        setTool, addIcon, addBlur, addLabel, addLabelAt,
        addTextAt,
        hasSelectedText, getSelectedText, updateSelectedText,
        deleteSelected, rotateSelected,
        changeSelectedSize,
        zoom, nudge, resetFit,
        rotateImage, rotateImageLeft, rotateImageRight,
        getState, setState, save,
        drawForExport, renderToDataURL,
        getAllAnnotations
    };
})();