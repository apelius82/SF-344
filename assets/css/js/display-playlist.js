/**
 * SafetyFlash - Display Playlist Management
 * 
 * JavaScript-logiikka infonäyttö-playlist-toiminnoille:
 * - TTL-chip valitsin
 * - Esikatselupäivämäärä
 * - Poista/Palauta-painikkeet
 * 
 * @package SafetyFlash
 * @subpackage JavaScript
 * @created 2026-02-19
 */

(function () {
    'use strict';

    /**
     * Alusta TTL-chip valitsin (julkaisumodaalissa)
     */
    function initTtlChips() {
        // Support multiple TTL sections on the same page (e.g. different modals)
        document.querySelectorAll('.sf-publish-ttl-section').forEach(function (container) {
            const chips = container.querySelectorAll('.sf-ttl-chip');
            const preview = container.querySelector('.sf-ttl-preview');
            const previewDate = container.querySelector('.sf-ttl-preview-date');

            if (!chips.length) {
                return;
            }

            // Päivitä esikatselu
            function updatePreview() {
                const selectedRadio = container.querySelector('.sf-ttl-radio:checked');
                if (!selectedRadio) {
                    return;
                }

                const days = parseInt(selectedRadio.value, 10);

                if (!preview) {
                    return;
                }

                if (days === 0) {
                    // Ei aikarajaa
                    preview.classList.add('sf-ttl-preview-hidden');
                    return;
                }

                preview.classList.remove('sf-ttl-preview-hidden');

                // Laske vanhenemispäivä
                const expiryDate = new Date();
                expiryDate.setDate(expiryDate.getDate() + days);

                // Suomalainen päivämääräformaatti
                const formatted = expiryDate.toLocaleDateString('fi-FI', {
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });

                if (previewDate) {
                    previewDate.textContent = formatted;
                }
            }

            // Käsittele chip-klikkaukset
            chips.forEach(chip => {
                chip.addEventListener('click', function () {
                    // Poista selected-luokka kaikilta
                    chips.forEach(c => c.classList.remove('sf-ttl-chip-selected'));

                    // Lisää selected-luokka klikatulle
                    this.classList.add('sf-ttl-chip-selected');

                    // Valitse radio
                    const radio = this.querySelector('.sf-ttl-radio');
                    if (radio) {
                        radio.checked = true;
                    }

                    // Päivitä esikatselu
                    updatePreview();
                });
            });

            // Alusta esikatselu
            updatePreview();
        });
    }

    /**
     * Alusta display chip valitsimet (julkaisumodaalissa ja display targets -modaalissa)
     */
    function initDisplayChips() {
        document.querySelectorAll('.sf-display-target-selector').forEach(function (container) {
            initSingleDisplaySelector(container);
        });
    }

    /**
     * Alusta yksittäinen näyttövalitsin-kontti (maa/kielisiruilla + haulla)
     */
    function initSingleDisplaySelector(container) {
        // #displayTargetsModal handles its own selector via display-targets-modal.js
        if (container.closest('#displayTargetsModal')) return;
        var langChips = container.querySelectorAll('.sf-dt-lang-chip');
        var searchInput = container.querySelector('.sf-dt-search-input');
        var searchResults = container.querySelector('.sf-dt-search-results');

        if (!langChips.length && !searchInput) return;

        // Kache: kielikoodi → vastaavat checkboxit
        var cbByLang = {};
        container.querySelectorAll('.dt-display-chip-cb').forEach(function (cb) {
            var lang = cb.getAttribute('data-lang') || '';
            if (!cbByLang[lang]) cbByLang[lang] = [];
            cbByLang[lang].push(cb);
        });

        function updateSelectionDisplay() {
            var display = container.querySelector('.sf-dt-selection-display');
            var tags = container.querySelector('.sf-dt-selection-tags');
            if (!display || !tags) return;

            tags.innerHTML = '';
            var checked = container.querySelectorAll('.dt-display-chip-cb:checked');

            checked.forEach(function (cb) {
                var label = cb.getAttribute('data-label') || cb.value;
                var tag = document.createElement('span');
                tag.className = 'sf-dt-sel-tag';
                var text = document.createTextNode(label + ' ');
                var removeBtn = document.createElement('span');
                removeBtn.className = 'sf-dt-sel-tag-remove';
                removeBtn.textContent = '×';
                removeBtn.addEventListener('click', function () {
                    cb.checked = false;
                    updateSelectionDisplay();
                    updateLangChipStates();
                });
                tag.appendChild(text);
                tag.appendChild(removeBtn);
                tags.appendChild(tag);
            });

            display.classList.toggle('hidden', checked.length === 0);
        }

        function getSpecialChipCheckboxes(selectType) {
            if (selectType === 'all') {
                return Array.from(container.querySelectorAll('.dt-display-chip-cb'));
            }
            if (selectType === 'display-only') {
                return Array.from(container.querySelectorAll('.dt-display-chip-cb[data-display-only="1"]'));
            }
            if (selectType === 'uncategorized') {
                return Array.from(container.querySelectorAll('.dt-display-chip-cb[data-uncategorized="1"]'));
            }
            if (selectType === 'default') {
                return Array.from(container.querySelectorAll('.dt-display-chip-cb[data-default-display="1"]'));
            }
            return Array.from(container.querySelectorAll('.dt-display-chip-cb[data-type="' + selectType + '"]'));
        }

        function updateLangChipStates() {
            langChips.forEach(function (chip) {
                var lang = chip.getAttribute('data-lang');
                var cbs = cbByLang[lang] || [];
                var checkedCount = 0;
                cbs.forEach(function (cb) { if (cb.checked) checkedCount++; });
                chip.classList.toggle('sf-dt-lang-chip-active', cbs.length > 0 && checkedCount === cbs.length);
            });

            // Update special chip active states
            container.querySelectorAll('.sf-dt-special-chip').forEach(function (chip) {
                var selectType = chip.getAttribute('data-select');
                var cbs = getSpecialChipCheckboxes(selectType);
                var checkedCount = cbs.filter(function (cb) { return cb.checked; }).length;
                chip.classList.toggle('sf-dt-lang-chip-active', cbs.length > 0 && checkedCount === cbs.length);
            });
        }

        // Alusta tilat
        updateSelectionDisplay();
        updateLangChipStates();

        // Maa/kielisirujen klikkaukset
        langChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                var lang = this.getAttribute('data-lang');
                var isActive = this.classList.contains('sf-dt-lang-chip-active');
                (cbByLang[lang] || []).forEach(function (cb) {
                    cb.checked = !isActive;
                });
                updateLangChipStates();
                updateSelectionDisplay();
            });
        });

        // Erikoischippien klikkaukset (Kaikki näytöt / Tunnelityömaat / Avolouhokset)
        container.querySelectorAll('.sf-dt-special-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var selectType = this.getAttribute('data-select');
                var isActive = this.classList.contains('sf-dt-lang-chip-active');
                var cbs = getSpecialChipCheckboxes(selectType);
                cbs.forEach(function (cb) { cb.checked = !isActive; });
                updateLangChipStates();
                updateSelectionDisplay();
            });
        });

        // Tyhjennä kaikki -nappi
        var clearAllBtn = container.querySelector('.sf-dt-clear-all-btn');
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', function () {
                container.querySelectorAll('.dt-display-chip-cb').forEach(function (cb) {
                    cb.checked = false;
                });
                updateSelectionDisplay();
                updateLangChipStates();
            });
        }

        // Hakukenttä
        if (searchInput && searchResults) {
            searchInput.addEventListener('input', function () {
                var term = this.value.toLowerCase().trim();
                var items = container.querySelectorAll('.sf-dt-result-item');
                var hasVisible = false;

                if (term.length === 0) {
                    items.forEach(function (item) { item.classList.add('hidden'); });
                    searchResults.classList.add('hidden');
                    return;
                }

                searchResults.classList.remove('hidden');
                items.forEach(function (item) {
                    var search = item.getAttribute('data-search') || '';
                    var visible = search.includes(term);
                    item.classList.toggle('hidden', !visible);
                    if (visible) hasVisible = true;
                });

                if (!hasVisible) {
                    searchResults.classList.add('hidden');
                }
            });

            // Tyhjennä hakukenttä kun valitaan tulos
            searchResults.addEventListener('click', function (e) {
                var resultItem = e.target.closest('.sf-dt-result-item');
                if (resultItem) {
                    searchInput.value = '';
                    container.querySelectorAll('.sf-dt-result-item').forEach(function (item) {
                        item.classList.add('hidden');
                    });
                    searchResults.classList.add('hidden');
                    searchInput.focus();
                }
            });
        }

        // Checkboxien muutokset (tapahtumadelegointi)
        container.addEventListener('change', function (e) {
            if (e.target.classList.contains('dt-display-chip-cb')) {
                updateSelectionDisplay();
                updateLangChipStates();
            }
        });
    }

    function formatPlaylistDate(value) {
        if (!value) {
            return '—';
        }

        const normalizedValue = String(value).replace(' ', 'T');
        const date = new Date(normalizedValue);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');

        return day + '.' + month + '.' + year + ' ' + hours + ':' + minutes;
    }
	
    function setPlaylistLoading(modal, isLoading) {
        if (!modal) return;

        const frame = modal.querySelector('.sf-playlist-preview-frame');
        const loader = modal.querySelector('.sf-playlist-loader');

        if (frame) {
            frame.classList.toggle('is-loading', !!isLoading);
        }

        if (loader) {
            loader.classList.toggle('hidden', !isLoading);
        }
    }

    function resetPlaylistControls(modal) {
        if (!modal) return;

        const counter = modal.querySelector('#sfPlaylistCounter');
        const pauseButton = modal.querySelector('#btnPlaylistPause');

        if (counter) {
            counter.textContent = '– / –';
        }

        if (pauseButton) {
            pauseButton.setAttribute('aria-pressed', 'false');
            pauseButton.textContent = '\u23F8';

            const labelPause = pauseButton.getAttribute('data-label-pause') || 'Pysäytä';
            pauseButton.title = labelPause;
            pauseButton.setAttribute('aria-label', labelPause);
        }
    }

    /**
     * Alusta ajolista-modaalin navigointipainikkeet (prev/next/pause/resume)
     * Tukee sekä view-sivun modaalin (modalKatsoAjolista) että playlist managerin modaalin (modalPlaylistPreview).
     */
    function initPlaylistNavigation() {
        const modalIds = ['modalKatsoAjolista', 'modalPlaylistPreview'];

        // Kerätään kaikki löydetyt nav-kohteet yhteen (viestikuuntelijaa varten)
        window._sfPlaylistNavTargets = window._sfPlaylistNavTargets || [];

        modalIds.forEach(function (modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;

            const iframe = modal.querySelector('.sf-pm-preview-iframe');
            if (!iframe) return;

            if (!iframe._sfPlaylistLoaderAttached) {
                setPlaylistLoading(modal, true);

                iframe.addEventListener('load', function () {
                    setPlaylistLoading(modal, false);
                });

                iframe._sfPlaylistLoaderAttached = true;
            }

            // Etsi napit ja counter ensisijaisesti modaalin sisältä (turvallisempi kuin document.getElementById)
            const btnPrev = modal.querySelector('#btnPlaylistPrev');
            const btnNext = modal.querySelector('#btnPlaylistNext');
            const btnPause = modal.querySelector('#btnPlaylistPause');
            const counter = modal.querySelector('#sfPlaylistCounter');

            function sendToIframe(action) {
                if (iframe.contentWindow) {
                    iframe.contentWindow.postMessage({ action: action }, window.location.origin);
                }
            }

            if (btnPrev && !btnPrev._sfAttached) {
                btnPrev.addEventListener('click', function () { sendToIframe('prev'); });
                btnPrev._sfAttached = true;
            }

            if (btnNext && !btnNext._sfAttached) {
                btnNext.addEventListener('click', function () { sendToIframe('next'); });
                btnNext._sfAttached = true;
            }

            if (btnPause && !btnPause._sfAttached) {
                btnPause.addEventListener('click', function () {
                    const isPaused = btnPause.getAttribute('aria-pressed') === 'true';
                    sendToIframe(isPaused ? 'resume' : 'pause');
                });
                btnPause._sfAttached = true;
            }

            // Talleta targetit yhteistä message-listeneriä varten
            window._sfPlaylistNavTargets.push({
                counter: counter || null,
                btnPause: btnPause || null
            });
        });

        // Kiinnitä message-listener vain kerran
        if (window._sfPlaylistNavListenerAttached) return;
        window._sfPlaylistNavListenerAttached = true;

        window.addEventListener('message', function (event) {
            const data = event.data;
            if (!data || typeof data !== 'object') return;

            const targets = window._sfPlaylistNavTargets || [];

            if (data.type === 'sf-playlist-slide') {
                targets.forEach(function (t) {
                    if (t.counter && typeof data.current === 'number' && typeof data.total === 'number') {
                        t.counter.textContent = (data.current + 1) + ' / ' + data.total;
                    }
                });

                const metaDuration = document.getElementById('sfPlaylistMetaDuration');
                const metaExpires = document.getElementById('sfPlaylistMetaExpires');

                if (metaDuration && typeof data.duration_seconds === 'number') {
                    metaDuration.textContent = data.duration_seconds + ' s';
                }

                if (metaExpires) {
                    metaExpires.textContent = formatPlaylistDate(data.expires_at);
                }
            } else if (data.type === 'sf-playlist-state') {
                targets.forEach(function (t) {
                    const btnPause = t.btnPause;
                    if (!btnPause) return;

                    const isPaused = !!data.paused;
                    btnPause.setAttribute('aria-pressed', isPaused ? 'true' : 'false');

                    if (isPaused) {
                        btnPause.textContent = '\u25B6';
                        const labelResume = btnPause.getAttribute('data-label-resume') || 'Jatka';
                        btnPause.title = labelResume;
                        btnPause.setAttribute('aria-label', labelResume);
                    } else {
                        btnPause.textContent = '\u23F8';
                        const labelPause = btnPause.getAttribute('data-label-pause') || 'Pysäytä';
                        btnPause.title = labelPause;
                        btnPause.setAttribute('aria-label', labelPause);
                    }
                });
            }
        });
    }
function initViewPlaylistTargetButtons() {
    document.addEventListener('click', function (event) {
        const button = event.target.closest('.sf-playlist-target-btn');
        if (!button) return;

        const playlistUrl = button.getAttribute('data-playlist-url') || '';
        const playlistLabel = button.getAttribute('data-playlist-label') || '';
        const modal = document.getElementById('modalKatsoAjolista');

        if (!modal || !playlistUrl) return;

        const iframe = modal.querySelector('.sf-pm-preview-iframe');
        const label = modal.querySelector('#modalKatsoAjolistaLabel');
        const counter = modal.querySelector('#sfPlaylistCounter');
        const pauseButton = modal.querySelector('#btnPlaylistPause');

        if (iframe && iframe.getAttribute('src') !== playlistUrl) {
            setPlaylistLoading(modal, true);
            resetPlaylistControls(modal);

            iframe.setAttribute('src', playlistUrl);
            iframe.setAttribute('title', playlistLabel || 'Ajolista');
        }

        if (label) {
            label.textContent = playlistLabel ? ' — ' + playlistLabel : '';
        }
    });
}
	
    /**
     * Alusta playlist-painikkeet (view-sivulla)
     */
    function initPlaylistButtons() {
        const btnRemove = document.getElementById('btnRemoveFromPlaylist');
        const btnRestore = document.getElementById('btnRestoreToPlaylist');
        const btnConfirm = document.getElementById('btnConfirmRemoveFromPlaylist');

        if (btnRemove) {
            btnRemove.addEventListener('click', handleRemoveFromPlaylist);
        }

        if (btnRestore) {
            btnRestore.addEventListener('click', handleRestoreToPlaylist);
        }

        if (btnConfirm) {
            btnConfirm.addEventListener('click', handleConfirmRemoveFromPlaylist);
        }
    }

    /**
     * Poista flash playlistasta — avaa vahvistusmodaali
     */
    function handleRemoveFromPlaylist(event) {
        const modal = document.getElementById('modalRemoveFromPlaylist');
        if (modal) {
            modal.classList.remove('hidden');
            document.body.classList.add('sf-modal-open');
        }
    }

    /**
     * Vahvista poisto playlistasta
     */
    function handleConfirmRemoveFromPlaylist(event) {
        const btn = event.target;
        const flashId = btn.getAttribute('data-flash-id');

        if (!flashId) {
            console.error('Flash ID not found');
            return;
        }

        // Sulje modaali
        const modal = document.getElementById('modalRemoveFromPlaylist');
        if (modal) {
            modal.classList.add('hidden');
            if (document.querySelectorAll('.sf-modal:not(.hidden), .sf-library-modal:not(.hidden)').length === 0) {
                document.body.classList.remove('sf-modal-open');
            }
        }

        btn.disabled = true;

        // Lähetä API-pyyntö
        sendPlaylistAction(flashId, 'remove')
            .then(response => {
                if (response.ok) {
                    // Lataa sivu uudelleen näyttääksesi päivitetyn statuksen
                    window.location.reload();
                } else {
                    alert(response.message || 'Virhe poistossa');
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Remove error:', error);
                alert('Verkkovirhe. Yritä uudelleen.');
                btn.disabled = false;
            });
    }

    /**
     * Palauta flash playlistaan
     */
    function handleRestoreToPlaylist(event) {
        const btn = event.target;
        const flashId = btn.getAttribute('data-flash-id');

        if (!flashId) {
            console.error('Flash ID not found');
            return;
        }

        btn.disabled = true;

        // Lähetä API-pyyntö
        sendPlaylistAction(flashId, 'restore')
            .then(response => {
                if (response.ok) {
                    // Lataa sivu uudelleen näyttääksesi päivitetyn statuksen
                    window.location.reload();
                } else {
                    alert(response.message || 'Virhe palautuksessa');
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Restore error:', error);
                alert('Verkkovirhe. Yritä uudelleen.');
                btn.disabled = false;
            });
    }

    /**
     * Lähetä playlist-toiminto API:lle
     */
    function sendPlaylistAction(flashId, action) {
        const baseUrl = window.SF_BASE_URL || '';
        const csrfToken = window.SF_CSRF_TOKEN || document.querySelector('[name="csrf_token"]')?.value || '';

        return fetch(baseUrl + '/app/api/display_playlist_manage.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                flash_id: parseInt(flashId, 10),
                action: action,
                csrf_token: csrfToken
            })
        })
            .then(response => response.json());
    }

    /**
     * DOMContentLoaded - Alusta kaikki
     */
function initViewPlaylistTargetSelect() {
    document.addEventListener('change', function (event) {
        const select = event.target.closest('#sfPlaylistTargetSelect');
        if (!select) return;

        const selectedOption = select.options[select.selectedIndex];
        const playlistUrl = select.value || '';
        const playlistLabel = selectedOption ? selectedOption.getAttribute('data-label') || selectedOption.textContent.trim() : '';
        const modal = document.getElementById('modalKatsoAjolista');

        if (!modal || !playlistUrl) return;

        const iframe = modal.querySelector('.sf-pm-preview-iframe');
        const label = modal.querySelector('#modalKatsoAjolistaLabel');
        const counter = modal.querySelector('#sfPlaylistCounter');
        const pauseButton = modal.querySelector('#btnPlaylistPause');

        if (iframe && iframe.getAttribute('src') !== playlistUrl) {
            setPlaylistLoading(modal, true);
            resetPlaylistControls(modal);

            iframe.setAttribute('src', playlistUrl);
            iframe.setAttribute('title', playlistLabel || 'Ajolista');
        }

        if (label) {
            label.textContent = playlistLabel ? ' — ' + playlistLabel : '';
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    initTtlChips();
    initDisplayChips();
    initPlaylistButtons();
    initPlaylistNavigation();
    initViewPlaylistTargetButtons();
    initViewPlaylistTargetSelect();
});

})();