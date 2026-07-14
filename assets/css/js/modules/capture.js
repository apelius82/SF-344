import { getters } from './state.js';
const { getEl, qs } = getters;

// Delay to allow DOM to update after visibility changes (milliseconds)
const DOM_UPDATE_DELAY = 50;

// Wait times for DOM updates after visibility changes
const GREEN_TYPE_DOM_WAIT = 250; // Green type has more complex DOM
const GREEN_TYPE_EDIT_DOM_WAIT = 400; // Green type in edit mode needs more time
const EDIT_MODE_DOM_INIT_WAIT = 200; // Initial wait for edit mode DOM initialization
const PREVIEW_UPDATE_WAIT = 100; // Wait for PreviewTutkinta update completion
const STANDARD_DOM_WAIT = 100;   // Standard types (red/yellow)

// Validation threshold for captured image size
const MIN_VALID_DATAURL_LENGTH = 10000; // Minimum reasonable size for 1920x1080 JPEG

async function waitFonts() {
    return document.fonts?.ready ?? Promise.resolve();
}

// Sovella transform: käytä _applyTransformToImg jos saatavilla
function applyTransformHelper(currentType, slot, img, st) {
    if (!img || !st) return;

    const isGreen = currentType === 'green';
    const host = isGreen ? window.PreviewTutkinta : window.Preview;
    const helper = host?._applyTransformToImg;

    if (helper) {
        helper.call(host, slot, img);
        return;
    }

    img.style.transformOrigin = 'center center';
    img.style.transform = `translate(-50%, -50%) translate(${st.x}px, ${st.y}px) scale(${st.scale})`;
}

function readTransform(currentType, slot) {
    const liveState =
        currentType === 'green'
            ? window.PreviewTutkinta?.state?.[slot]
            : window.Preview?.state?.[slot];

    if (liveState && typeof liveState.scale === 'number') return liveState;

    const el =
        document.getElementById(`sf-image${slot}-transform`) ||
        document.getElementById(`sf-image${slot}-transform-green`);

    if (!el || !el.value) return null;

    try {
        const parsed = JSON.parse(el.value);
        return typeof parsed.scale === 'number' ? parsed : null;
    } catch {
        return null;
    }
}

async function captureCard(previewCard, currentType) {
    const clone = previewCard.cloneNode(true);
    document.body.appendChild(clone);

    // CRITICAL: Reset transform for capture
    // Card is 960x540 in preview, captured at 1920x1080 via html2canvas scale
    clone.classList.add('sf-capture-mode');
    clone.style.setProperty('--card-scale', '1');

    clone
        .querySelectorAll('.sf-preview-image-frame img, .sf-preview-thumb-frame img')
        .forEach((img) => {
            const src = (img.src || '').toLowerCase();
            if (src.includes('placeholder') || src.includes('camera')) {
                img.style.setProperty('display', 'none', 'important');
            }
        });

    clone.querySelectorAll('.sf-annotation-controls').forEach((ctrl) => {
        ctrl.style.setProperty('display', 'none', 'important');
    });

    [1, 2, 3].forEach((slot) => {
        const st = readTransform(currentType, slot);
        const imgId = currentType === 'green' ? `sfPreviewImg${slot}Green` : `sfPreviewImg${slot}`;
        const img = clone.querySelector(`#${imgId}`);
        if (st && img) applyTransformHelper(currentType, slot, img, st);
    });

    // FIXED: Force clone to exact 1920x1080 dimensions regardless of device
    // This ensures consistent output across mobile, tablet, and desktop
    clone.style.cssText = `
        position: fixed !important;
        left: -99999px !important;
        top: 0 !important;
        width: 1920px !important;
        height: 1080px !important;
        padding-bottom: 0 !important;
        transform: none !important;
        z-index: -1 !important;
        display: block !important;
    `;

    await new Promise((res) => setTimeout(res, 50));

    // FIXED: Use scale: 1 with explicit dimensions for consistent output
    const canvas = await html2canvas(clone, {
        scale: 1,
        width: 1920,
        height: 1080,
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#ffffff',
        logging: false,
        imageTimeout: 15000,
    });

    clone.remove();
    return canvas.toDataURL('image/jpeg', 0.92);
}

export async function capturePreviewCard1() {
    if (!window.html2canvas) {
        console.error('[capture.js] html2canvas not loaded');
        return false;
    }
    await waitFonts();

    // FIX: Wait for DOM in edit mode first
    const isEditMode = !!document.querySelector('input[name="id"]')?.value;
    if (isEditMode) {
        console.log('[capture.js] Edit mode detected, waiting for DOM...');
        await new Promise(res => setTimeout(res, EDIT_MODE_DOM_INIT_WAIT));
    }

    const hiddenPreviewInput =
        getEl('sf-preview-image-data') ??
        getEl('sf-form')?.querySelector('input[name="preview_image_data"]');

    if (!hiddenPreviewInput) {
        console.error('[capture.js] Card 1: Hidden input not found');
        return false;
    }

    const currentType = qs('input[name="type"]:checked')?.value;
    console.log('[capture.js] Capturing Card 1 for type:', currentType);

    // Get containers
    const containerRY = getEl('sfPreviewContainerRedYellow');
    const containerG = getEl('sfPreviewContainerGreen');

    // Save original states for restoration BEFORE any modifications
    const ryWasHidden = containerRY?.classList?.contains('hidden');
    const gWasHidden = containerG?.classList?.contains('hidden');
    const ryOriginalStyle = containerRY?.style?.display;
    const gOriginalStyle = containerG?.style?.display;

    // Enhanced debug logging for green type
    if (currentType === 'green') {
        console.log('[capture.js] Green type capture - containerG exists:', !!containerG);
        console.log('[capture.js] Green type capture - containerG hidden:', containerG?.classList?.contains('hidden'));

        if (!containerG) {
            console.error('[capture.js] CRITICAL: sfPreviewContainerGreen not found in DOM!');
            // Try to continue anyway - maybe preview card can be found directly
        }

        // FIX: Ensure green container visibility in edit mode
        if (containerG) {
            // Force visibility BEFORE anything else
            containerG.classList.remove('hidden');
            containerG.style.cssText = 'display: block !important; visibility: visible !important; opacity: 1 !important;';

            // Update PreviewTutkinta content
            if (window.PreviewTutkinta?.updatePreviewContent) {
                window.PreviewTutkinta.updatePreviewContent();
            }

            // Force reflow
            containerG.offsetHeight;
        }
    }

    // CRITICAL: Force visibility with inline style to override !important CSS
    if (currentType === 'green') {
        if (containerRY) {
            containerRY.classList.add('hidden');
            containerRY.style.display = 'none';
        }
        // containerG visibility already forced above in green type detection
    } else {
        if (containerRY) {
            containerRY.classList.remove('hidden');
            containerRY.style.setProperty('display', 'block', 'important');
        }
        if (containerG) {
            containerG.classList.add('hidden');
            containerG.style.display = 'none';
        }
    }

    // Force reflow and wait for DOM
    // Longer wait time for edit mode
    document.body.offsetHeight;
    const waitTime = currentType === 'green'
        ? (isEditMode ? GREEN_TYPE_EDIT_DOM_WAIT : GREEN_TYPE_DOM_WAIT)
        : STANDARD_DOM_WAIT;
    await new Promise(res => setTimeout(res, waitTime));

    const previewCard = getEl(currentType === 'green' ? 'sfPreviewCardGreen' : 'sfPreviewCard');
    if (!previewCard) {
        console.error('[capture.js] Card 1: Preview card not found for type:', currentType);
        console.error('[capture.js] Tried to find:', currentType === 'green' ? 'sfPreviewCardGreen' : 'sfPreviewCard');
        return false;
    }

    // Ensure preview card is visible
    console.log('[capture.js] Preview card found, dimensions:', previewCard.offsetWidth, 'x', previewCard.offsetHeight);
    if (previewCard.offsetWidth === 0 || previewCard.offsetHeight === 0) {
        console.warn('[capture.js] Preview card has zero dimensions - forcing visibility');
        previewCard.style.cssText = 'display: block !important; visibility: visible !important; width: 1920px; height: 1080px;';
        await new Promise(res => setTimeout(res, 100));
    }

    if (currentType === 'green') {
        window.PreviewTutkinta?.applyGridClass?.();
    } else {
        window.Preview?.applyGridClass?.();
    }

    window.Annotations?.hideForCapture?.();
    previewCard.querySelectorAll('.sf-active').forEach((el) => el.classList.remove('sf-active'));

    try {
        const dataUrl = await captureCard(previewCard, currentType);
        hiddenPreviewInput.value = dataUrl;
        console.log('[capture.js] Card 1 captured successfully, dataUrl length:', dataUrl?.length);

        // Validate that dataUrl is valid and has reasonable size
        if (!dataUrl || dataUrl.length < MIN_VALID_DATAURL_LENGTH) {
            console.error('[capture.js] Card 1: Capture resulted in invalid/small dataUrl, length:', dataUrl?.length);
            return false;
        }
        return true;
    } catch (err) {
        console.error('[capture.js] Card 1 html2canvas error:', err);
        console.error('[capture.js] Error details:', err.message, err.stack);
        return false;
    } finally {
        window.Annotations?.showAfterCapture?.();

        // Restore original visibility states
        if (containerRY) {
            if (ryWasHidden) {
                containerRY.classList.add('hidden');
            } else {
                containerRY.classList.remove('hidden');
            }
            if (ryOriginalStyle) {
                containerRY.style.display = ryOriginalStyle;
            } else {
                containerRY.style.removeProperty('display');
            }
        }
        if (containerG) {
            if (gWasHidden) {
                containerG.classList.add('hidden');
            } else {
                containerG.classList.remove('hidden');
            }
            if (gOriginalStyle) {
                containerG.style.display = gOriginalStyle;
            } else {
                containerG.style.removeProperty('display');
            }
            // Clean up other inline styles
            containerG.style.removeProperty('visibility');
            containerG.style.removeProperty('opacity');
        }
    }
}

export async function capturePreviewCard2() {
    if (!window.html2canvas) return false;
    await waitFonts();

    const hiddenPreviewInput2 =
        getEl('sf-preview-image-data-2') ??
        getEl('sf-form')?.querySelector('input[name="preview_image_data_2"]');

    if (!hiddenPreviewInput2) {
        console.error('[capture.js] Card 2: Hidden input not found');
        return false;
    }

    const previewCard2 = getEl('sfPreviewCard2Green');
    if (!previewCard2) {
        console.error('[capture.js] Card 2: Preview card not found');
        return false;
    }

    // Käytä PreviewTutkinta-luokan logiikkaa kahden dian tarpeellisuuden määrittämiseen
    // Tämä varmistaa että capture ja preview käyttävät SAMAA logiikkaa
    const title = getEl('sf-short-text')?.value || '';
    const desc = getEl('sf-description')?.value || '';
    const rootCauses = getEl('sf-root-causes')?.value || '';
    const actions = getEl('sf-actions')?.value || '';

    // Tarkista tarvitaanko toinen dia
    // Käytä PreviewTutkinta._shouldUseTwoSlides() jos saatavilla
    let needsTwoSlides = false;

    if (window.PreviewTutkinta?._shouldUseTwoSlides) {
        needsTwoSlides = window.PreviewTutkinta._shouldUseTwoSlides(title, desc, rootCauses, actions);
    } else {
        // Fallback: sama logiikka kuin preview-tutkinta.js
        const hasRootCauses = rootCauses.trim().length > 0;
        const hasActions = actions.trim().length > 0;

        if (!hasRootCauses && !hasActions) {
            needsTwoSlides = false;
        } else {
            // Laske tekstin pituudet
            const SINGLE_SLIDE_TOTAL_LIMIT = 900;
            const ROOT_CAUSES_SINGLE_LIMIT = 500;
            const ACTIONS_SINGLE_LIMIT = 500;
            const DESC_SINGLE_LIMIT = 400;
            const ROOT_CAUSES_ACTIONS_COMBINED_LIMIT = 800;
            const MAX_COLUMN_LINES = 14;
            const CHARS_PER_LINE = 45;

            const calcLen = (text) => {
                if (!text) return 0;
                // Use Array.from to count actual characters, not UTF-16 code units
                return Array.from(text).length;
            };

            const estimateLines = (text) => {
                if (!text) return 0;
                let lines = 0;
                const paragraphs = text.split('\n');
                for (const p of paragraphs) {
                    const trimmed = p.trim();
                    if (trimmed === '') continue;
                    lines += Math.max(1, Math.ceil(calcLen(trimmed) / CHARS_PER_LINE));
                }
                return lines;
            };

            const titleLen = calcLen(title);
            const descLen = calcLen(desc);
            const rootLen = calcLen(rootCauses);
            const actionsLen = calcLen(actions);
            const totalLen = titleLen + descLen + rootLen + actionsLen;
            const rootActionsLen = rootLen + actionsLen;

            // Priority 1: Check total content length
            if (totalLen > SINGLE_SLIDE_TOTAL_LIMIT) {
                needsTwoSlides = true;
                // Priority 2: Check description length
            } else if (descLen > DESC_SINGLE_LIMIT) {
                needsTwoSlides = true;
                // Priority 3: Check root causes + actions combined
            } else if (rootActionsLen > ROOT_CAUSES_ACTIONS_COMBINED_LIMIT) {
                needsTwoSlides = true;
                // Priority 4: Check individual fields
            } else if (rootLen > ROOT_CAUSES_SINGLE_LIMIT) {
                needsTwoSlides = true;
            } else if (actionsLen > ACTIONS_SINGLE_LIMIT) {
                needsTwoSlides = true;
            } else {
                // Priority 5: Line-based calculation for column layout accuracy
                const rootCausesLines = estimateLines(rootCauses);
                const actionsLines = estimateLines(actions);
                const maxColumnLines = Math.max(rootCausesLines, actionsLines);

                if (maxColumnLines > MAX_COLUMN_LINES) {
                    needsTwoSlides = true;
                } else {
                    needsTwoSlides = false;
                }
            }
        }
    }

    // Debug logging to show decision
    console.log('[capture.js] Card 2: needsTwoSlides =', needsTwoSlides);

    // Jos ei tarvita toista diaa, tyhjennä hidden input ja lopeta
    if (!needsTwoSlides) {
        hiddenPreviewInput2.value = '';
        console.log('[capture.js] Card 2: Skipping - not needed');
        return true;
    }

    console.log('[capture.js] Capturing Card 2');

    // Ensure Card 2 is visible for capture
    const originalDisplay = previewCard2.style.display;
    previewCard2.style.display = 'block';

    // Small delay to allow DOM to update
    await new Promise(res => setTimeout(res, DOM_UPDATE_DELAY));

    window.PreviewTutkinta?.applyGridClass?.();

    try {
        const dataUrl = await captureCard(previewCard2, 'green');
        hiddenPreviewInput2.value = dataUrl;
        console.log('[capture.js] Card 2: Capture successful');
        return true;
    } catch (err) {
        console.error('[capture.js] Card 2: html2canvas error:', err);
        return false;
    } finally {
        // Restore original display
        previewCard2.style.display = originalDisplay;
        window.Annotations?.showAfterCapture?.();
    }
}

export async function captureAllPreviews() {
    const currentType = qs('input[name="type"]:checked')?.value;
    console.log('[capture.js] captureAllPreviews starting, type:', currentType);

    // Note: PreviewTutkinta.updatePreviewContent() is already called in capturePreviewCard1()
    // for green type, so we don't need to call it here to avoid duplication

    const card1Success = await capturePreviewCard1();
    console.log('[capture.js] Card 1 capture result:', card1Success);

    if (currentType === 'green') {
        const card2Success = await capturePreviewCard2();
        console.log('[capture.js] Card 2 capture result:', card2Success);
    }

    return card1Success;
}

// Aseta funktiot globaaliin scopeen, jotta inline-skriptit voivat käyttää niitä
window.captureAllPreviews = captureAllPreviews;
window.capturePreviewCard1 = capturePreviewCard1;
window.capturePreviewCard2 = capturePreviewCard2;