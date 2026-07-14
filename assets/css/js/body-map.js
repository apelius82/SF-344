/**
 * assets/js/body-map.js
 * Loukkaantuneiden kehonosien valinta — SVG ↔ dropdown-synkronointi
 */
(function () {
    'use strict';

    // i18n-merkkijonot laskurille ja poistonapille (PHP kirjoittaa window.BODY_MAP_I18N)
    var I18N = {
        countSingle: '1 kehonosa valittu',
        countPlural:  '{n} kehonosaa valittu',
        removeLabel:  'Poista',
    };
    if (typeof window.BODY_MAP_I18N === 'object' && window.BODY_MAP_I18N) {
        if (window.BODY_MAP_I18N.countSingle) { I18N.countSingle = window.BODY_MAP_I18N.countSingle; }
        if (window.BODY_MAP_I18N.countPlural)  { I18N.countPlural  = window.BODY_MAP_I18N.countPlural; }
        if (window.BODY_MAP_I18N.removeLabel)  { I18N.removeLabel  = window.BODY_MAP_I18N.removeLabel; }
    }

    // Käytössä olevat valinnat (canonical svg_id -joukko, vastaa <select>-optionin value-arvoa)
    const selected = new Set();

    // DOM-viitteet (alustetaan myöhemmin)
    let modal, select, saveBtn, countEl, tagsContainer, hiddenSelect;

    function init() {
        modal          = document.getElementById('sfBodyMapModal');
        select         = document.getElementById('sfBodyPartSelect');
        saveBtn        = document.getElementById('sfBodyMapSaveBtn');
        countEl        = document.getElementById('sfBodyMapSelectionCount');
        tagsContainer  = document.getElementById('sfInjuryTags');
        hiddenSelect   = document.getElementById('sfInjuredPartsHidden');

        if (!modal) return;

        // Lataa olemassa olevat valinnat (editointitila)
        loadFromHiddenSelect();

        // Renderöi tagit heti sivun latautuessa (editointitila)
        renderTags();

        // SVG-klikkaukset — kaikki .sf-bp[id]-elementit (etu- ja takapuoli)
        modal.querySelectorAll('.sf-bp[id]').forEach(function (part) {
            part.addEventListener('click', function () {
                togglePart(getCanonicalId(this.id));
            });
        });

        // Dropdown-muutos → SVG-synk
        if (select) {
            select.addEventListener('change', function () {
                syncFromSelect();
            });
        }

        // Tallenna-nappi
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                applySelections();
                closeSelf();
            });
        }

        // Avattaessa: päivitä tila
        var openBtn = document.getElementById('sfBodyMapOpenBtn');
        if (openBtn) {
            openBtn.addEventListener('click', function () {
                refreshModalState();
            });
        }
    }

    /**
     * Palauttaa kanonisen (dropdowniin tallennetun) tunnuksen SVG-elementin id:stä.
     * Takapuolen elementit päättyvät '-back'. Jos poistetulla päätteellä löytyy
     * dropdown-option (esim. bp-hand-left ← bp-hand-left-back), palautetaan
     * etupuolen tunnus. Muutoin palautetaan id sellaisenaan (esim. bp-upper-back).
     */
    function getCanonicalId(svgId) {
        if (svgId.endsWith('-back')) {
            var withoutBack = svgId.slice(0, -5);
            // Only query if the ID contains safe characters (alphanumeric and hyphens only)
            if (/^[\w-]+$/.test(withoutBack)) {
                var escaped = typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(withoutBack) : withoutBack;
                if (select && select.querySelector('option[value="' + escaped + '"]')) {
                    return withoutBack;
                }
            }
        }
        return svgId;
    }

    /** Lataa valinnat piilotetusta selectistä (editointitila) */
    function loadFromHiddenSelect() {
        if (!hiddenSelect) return;
        selected.clear();
        Array.from(hiddenSelect.options).forEach(function (opt) {
            if (opt.selected) selected.add(opt.value);
        });
        refreshAllSvg();
        refreshDropdown();
        updateCount();
    }

    /** Toggle yksittäinen kehonosa kanonisella tunnuksella */
    function togglePart(canonicalId) {
        if (selected.has(canonicalId)) {
            selected.delete(canonicalId);
        } else {
            selected.add(canonicalId);
        }
        refreshAllSvg();
        refreshDropdown();
        updateCount();
    }

    /**
     * Synkronoi SVG-elementtien .selected-luokka.
     * Sekä etupuolen elementit (id = canonicalId) että takapuolen elementit
     * (id = canonicalId + '-back') saavat selected-luokan, kun canonicalId on valittu.
     */
    function refreshAllSvg() {
        modal.querySelectorAll('.sf-bp[id]').forEach(function (el) {
            var canonical = getCanonicalId(el.id);
            el.classList.toggle('selected', selected.has(canonical));
        });
    }

    /** Synkronoi dropdown valituista osista */
    function refreshDropdown() {
        if (!select) return;
        Array.from(select.options).forEach(function (opt) {
            opt.selected = selected.has(opt.value);
        });
    }

    /** Synkronoi valitut osat dropdownista SVG:hen */
    function syncFromSelect() {
        selected.clear();
        Array.from(select.options).forEach(function (opt) {
            if (opt.selected) selected.add(opt.value);
        });
        refreshAllSvg();
        updateCount();
    }

    /** Päivitä valittujen lukumäärä */
    function updateCount() {
        if (!countEl) return;
        var n = selected.size;
        if (n === 0) {
            countEl.textContent = '';
        } else if (n === 1) {
            countEl.textContent = I18N.countSingle;
        } else {
            countEl.textContent = I18N.countPlural.replace('{n}', n);
        }
    }

    /**
     * Hae näyttönimi kanoniselle tunnukselle suoraan <select>-elementin
     * <option>-tekstisisällöstä (monikielisyys toimii automaattisesti).
     */
    function getLabel(canonicalId) {
        if (select && /^[\w-]+$/.test(canonicalId)) {
            var escaped = typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(canonicalId) : canonicalId;
            var opt = select.querySelector('option[value="' + escaped + '"]');
            if (opt) return opt.textContent.trim();
        }
        return canonicalId;
    }

    /** Tallenna valinnat piilotettuun selectiin ja renderöi tagit */
    function applySelections() {
        updateHiddenSelect();
        renderTags();
    }

    /** Kirjoita valinnat piilotettuun selectiin */
    function updateHiddenSelect() {
        if (!hiddenSelect) return;
        hiddenSelect.innerHTML = '';
        selected.forEach(function (canonicalId) {
            var opt = document.createElement('option');
            opt.value = canonicalId;
            opt.textContent = getLabel(canonicalId);
            opt.selected = true;
            hiddenSelect.appendChild(opt);
        });
    }

    /** Renderöi tagit Step 3:n alla */
    function renderTags() {
        if (!tagsContainer) return;
        tagsContainer.innerHTML = '';

        if (selected.size === 0) return;

        selected.forEach(function (canonicalId) {
            var tag = document.createElement('span');
            tag.className = 'sf-injury-tag';
            tag.dataset.svgId = canonicalId;

            var label = document.createTextNode(getLabel(canonicalId));
            tag.appendChild(label);

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'sf-injury-tag-remove';
            removeBtn.setAttribute('aria-label', I18N.removeLabel + ' ' + getLabel(canonicalId));
            removeBtn.innerHTML = '\u00D7'; // ×
            removeBtn.addEventListener('click', function () {
                selected.delete(canonicalId);
                refreshAllSvg();
                refreshDropdown();
                updateHiddenSelect();
                renderTags();
                updateCount();
            });

            tag.appendChild(removeBtn);
            tagsContainer.appendChild(tag);
        });
    }

    /** Päivitä modalin sisäinen tila ennen avaamista */
    function refreshModalState() {
        loadFromHiddenSelect();
    }

    /** Sulje modaali (hyödyntää globaalia modals.js) */
    function closeSelf() {
        if (modal) modal.classList.add('hidden');
        if (document.querySelectorAll('.sf-modal:not(.hidden), .sf-library-modal:not(.hidden)').length === 0) {
            document.body.classList.remove('sf-modal-open');
        }
    }

    // Alusta kun DOM on valmis
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Julkinen rajapinta
    window.BodyMap = { init: init, refresh: refreshModalState };
})();