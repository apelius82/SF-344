// assets/js/modules/preview-update.js

import { state, getters } from './state.js';
import { checkAndShowSupervisorSection } from './supervisor-approval.js';

const previewTranslations = {
    fi: {
        titlePlaceholder: 'Otsikko...',
        descPlaceholder: 'Kuvaus...',
        sitePlaceholder: 'Työmaa:',
        whenPlaceholder: 'Milloin? '
    },
    sv: {
        titlePlaceholder: 'Rubrik...',
        descPlaceholder: 'Beskrivning...',
        sitePlaceholder: 'Arbetsplats:',
        whenPlaceholder: 'När?'
    },
    en: {
        titlePlaceholder: 'Title...',
        descPlaceholder: 'Description...',
        sitePlaceholder: 'Worksite:',
        whenPlaceholder: 'When?'
    },
    it: {
        titlePlaceholder: 'Titolo...',
        descPlaceholder: 'Descrizione...',
        sitePlaceholder: 'Cantiere:',
        whenPlaceholder: 'Quando?'
    },
    el: {
        titlePlaceholder: 'Τίτλος...',
        descPlaceholder: 'Περιγραφή...',
        sitePlaceholder: 'Εργοτάξιο:',
        whenPlaceholder: 'Πότε;'
    }
};

const { getEl, qs, qsa } = getters;

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

export function getPreviewText(key) {
    const lang = state.selectedLang || 'fi';
    return (previewTranslations[lang] && previewTranslations[lang][key])
        || previewTranslations.fi[key]
        || key;
}

export function updatePreviewLabels() {
    const siteLabel = getEl('sfPreviewSiteLabel');
    const whenLabel = getEl('sfPreviewDateLabel');
    if (siteLabel) siteLabel.textContent = getPreviewText('sitePlaceholder');
    if (whenLabel) whenLabel.textContent = getPreviewText('whenPlaceholder');
}

export function handleConditionalFields() {
    const checkedType = qs('input[name="type"]:checked')?.value || state.selectedType || '';
    const isInvestigation = checkedType === 'green';
    const showInjurySection = checkedType === 'red' || checkedType === 'yellow' || checkedType === 'green';

    const form = document.getElementById('sf-form');
    const isTranslationChild = form?.querySelector('input[name="is_translation_child"]')?.value === '1';

    const toggle = (id, show) => {
        const el = getEl(id);
        if (el) {
            el.classList.toggle('hidden', !show);
        }
    };

    const standaloneCheckbox = getEl('sf-standalone-investigation');
    const relatedFlashField = getEl('sf-related-flash')?.closest('.sf-field');
    const relatedFlashHelp = getEl('sf-related-flash-help');
    const incidentSection = getEl('sf-step2-incident');
    const worksiteSection = getEl('sf-step2-worksite');

    toggle('sfPreviewContainerRedYellow', !isInvestigation);
    toggle('sfPreviewContainerGreen', isInvestigation);

    if (!isInvestigation) {
        if (incidentSection) {
            incidentSection.classList.add('hidden');
            incidentSection.style.display = 'none';
        }

        if (relatedFlashField) {
            relatedFlashField.style.display = '';
        }

        if (relatedFlashHelp) {
            relatedFlashHelp.style.display = '';
        }

        if (worksiteSection) {
            worksiteSection.classList.remove('hidden');
            worksiteSection.style.display = '';
        }

        toggle('sf-investigation-extra', false);
        toggle('sf-original-flash-preview', false);
        toggle('sf-injury-section', showInjurySection);

        return;
    }

    if (isTranslationChild) {
        if (incidentSection) {
            incidentSection.classList.add('hidden');
            incidentSection.style.display = 'none';
        }

        if (relatedFlashField) {
            relatedFlashField.style.display = 'none';
        }

        if (relatedFlashHelp) {
            relatedFlashHelp.style.display = 'none';
        }

        if (worksiteSection) {
            worksiteSection.classList.remove('hidden');
            worksiteSection.style.display = '';
        }

        toggle('sf-investigation-extra', true);
        toggle('sf-original-flash-preview', false);
        toggle('sf-injury-section', showInjurySection);

        return;
    }

    if (incidentSection) {
        incidentSection.classList.remove('hidden');
        incidentSection.style.display = '';
    }

    const isStandalone = standaloneCheckbox?.checked || false;

    if (relatedFlashField) {
        relatedFlashField.style.display = isStandalone ? 'none' : '';
    }

    if (relatedFlashHelp) {
        relatedFlashHelp.style.display = isStandalone ? 'none' : '';
    }

    toggle('sf-investigation-extra', true);
    toggle('sf-injury-section', showInjurySection);

    toggle(
        'sf-original-flash-preview',
        !!getEl('sf-related-flash')?.value && !isStandalone
    );
}

export function updatePreview() {
    const card = getEl('sfPreviewCard');
    const cardGreen = getEl('sfPreviewCardGreen');
    const currentType = qs('input[name="type"]:checked')?.value || state.selectedType;
    const currentLang = qs('input[name="lang"]:checked')?.value || state.selectedLang || 'fi';
    const base = (card?.dataset.baseUrl || cardGreen?.dataset.baseUrl || '');

    if (!currentType) return;

    if (card) {
        card.dataset.type = currentType;
        card.dataset.lang = currentLang;
    }

    const bgImg = getEl('sfPreviewBg');
    if (bgImg && currentType !== 'green') {
        const bgUrl = `${base}/assets/img/templates/SF_bg_${currentType}_${currentLang}.jpg`;
        bgImg.src = bgUrl;
    }

    // Otsikko
    const titleEl = getEl('sfPreviewTitle');
    if (titleEl) {
        titleEl.textContent = getEl('sf-short-text')?.value || getPreviewText('titlePlaceholder');
    }

    // Kuvaus
    const descEl = getEl('sfPreviewDesc');
    if (descEl) {
        const descText = getEl('sf-description')?.value || '';
        if (descText) {
            descEl.innerHTML = escapeHtml(descText).replace(/\n/g, '<br>');
        } else {
            descEl.textContent = getPreviewText('descPlaceholder');
        }
    }

    // Työmaa (worksite + detail)
    const worksite = getEl('sf-worksite')?.value || '';
    const detail = getEl('sf-site-detail')?.value || '';
    const siteText = [worksite, detail].filter(Boolean).join(' – ');
    const previewSiteEl = getEl('sfPreviewSite');
    if (previewSiteEl) previewSiteEl.textContent = siteText || '–';

    // Päivämäärä (ensisijaisesti sf-date, fallback sf-occurred_at)
    const dateRaw = getEl('sf-date')?.value || getEl('sf-occurred_at')?.value || '';
    const dt = dateRaw ? new Date(dateRaw) : null;
    const dateFmt = dt
        ? dt.toLocaleString(
            currentLang === 'fi' ? 'fi-FI' : (currentLang === 'sv' ? 'sv-SE' : 'en-GB'),
            {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }
        )
        : '–';

    const previewDateEl = getEl('sfPreviewDate');
    if (previewDateEl) previewDateEl.textContent = dateFmt;

    // ===== GRID BITMAP (lopullinen kuvakollaasi) =====
    const gridVal = (getEl('sf-grid-bitmap')?.value || '').trim();

    const gridSrc = (() => {
        if (!gridVal) return '';
        if (gridVal.startsWith('data:image/')) return gridVal;
        return `${base}/uploads/grids/${gridVal}`;
    })();

    const imgRY = getEl('sfGridBitmapImg');
    if (imgRY && gridSrc) imgRY.src = gridSrc;

    const imgG = getEl('sfGridBitmapImgGreen');
    if (imgG && gridSrc) imgG.src = gridSrc;

    updatePreviewLabels();

    if (currentType === 'green' && window.PreviewTutkinta?.updatePreviewContent) {
        window.PreviewTutkinta.updatePreviewContent();
    }
}


// Keskitetty preview-alustus - TÄSSÄ eikä bootstrap.js:ssä
function initializePreview(type) {
    if (type === 'green') {
        if (window.PreviewTutkinta) {
            window.PreviewTutkinta.reinit();
        }
    } else {
        if (window.Preview) {
            window.Preview.reinit();
        }
    }

    // Alusta annotaatiot aina kun preview alustetaan
    if (window.Annotations?.init) {
        window.Annotations.init();
    }
}

export async function updateUIForStep(stepNumber) {
    // Note: Progress bar and step indicators are now managed by navigation.js
    // This function handles step-specific UI updates like grid initialization and preview display

    const gridSelector = getEl('sfGridSelector');
    if (gridSelector) {
        gridSelector.style.display =
            (stepNumber === state.maxSteps) ? 'block' : 'none';
    }

    // ===== GRID-VALINNAT (VAIHE 5 + ESIKATSELU) =====
    if ((stepNumber === 5 || stepNumber === state.maxSteps) && typeof window.SF_GRID_STEP_INIT === 'function') {
        const isPlaceholder = (src) => {
            if (!src) return true;

            const s = String(src).toLowerCase();

            if (s.includes('camera-placeholder')) return true;
            if (s.includes('placeholder')) return true;
            if (s.includes('no-image')) return true;
            if (s === '' || s === 'about:blank') return true;
            if (s.startsWith('data:') && s.length < 100) return true;

            return false;
        };

        const imageItems = [];
        const imageElements = [
            getEl('sfImageThumb1') || getEl('sf-upload-preview1'),
            getEl('sfImageThumb2') || getEl('sf-upload-preview2'),
            getEl('sfImageThumb3') || getEl('sf-upload-preview3')
        ];

        imageElements.forEach((imageElement, index) => {
            const slot = index + 1;
            const existingField = getEl(`sf-existing-image-${slot}`);
            const editedField = getEl(`sf-image${slot}-edited-data`);

            if (editedField && editedField.value && editedField.value.startsWith('data:')) {
                imageItems.push({
                    url: editedField.value,
                    originalSlot: slot
                });
                return;
            }

            if (imageElement && imageElement.src && !isPlaceholder(imageElement.src)) {
                imageItems.push({
                    url: imageElement.src,
                    originalSlot: slot
                });
                return;
            }

            if (existingField && existingField.value && existingField.value.trim() !== '') {
                imageItems.push({
                    url: existingField.value.trim(),
                    originalSlot: slot
                });
            }
        });

const count = imageItems.length || 1;
const forceRegenerate = window.SF_GRID_NEEDS_REGENERATE === true;

if (typeof window.SF_GRID_STEP_INIT === 'function') {
    await window.SF_GRID_STEP_INIT(count, imageItems, {
        forceRegenerate: forceRegenerate
    });
}

window.SF_GRID_NEEDS_REGENERATE = false;
    }

    if (stepNumber === state.maxSteps) {
        const currentType = qs('input[name="type"]:checked')?.value;

        // Näytä oikea container
        const containerRY = getEl('sfPreviewContainerRedYellow');
        const containerG = getEl('sfPreviewContainerGreen');

        if (currentType === 'green') {
            if (containerRY) containerRY.classList.add('hidden');
            if (containerG) containerG.classList.remove('hidden');
        } else {
            if (containerRY) containerRY.classList.remove('hidden');
            if (containerG) containerG.classList.add('hidden');
        }

        updatePreview();

        // Alusta preview — wait longer to allow async grid bitmap upload from step 5 to complete
        setTimeout(() => {
            initializePreview(currentType);
        }, 300);

        // Näytä supervisor-osio KAIKILLE tyypeille kun tullaan vaiheeseen 6
        setTimeout(() => {
            checkAndShowSupervisorSection();
        }, 150);
    }
}