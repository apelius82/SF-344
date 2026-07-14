function getElement(id) {
    return document.getElementById(id);
}

function hasRealImage(slot) {
    const thumb = getElement(`sfImageThumb${slot}`);

    if (!thumb || !thumb.src) {
        return false;
    }

    const src = String(thumb.src).toLowerCase();

    return !src.includes('camera-placeholder')
        && !src.includes('placeholder')
        && src !== 'about:blank';
}

function getMainSlotInput() {
    let input = getElement('sf-main-image-slot');

    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.id = 'sf-main-image-slot';
        input.name = 'main_image_slot';
        input.value = '1';
        getElement('sf-form')?.appendChild(input);
    }

    return input;
}

function getFirstAvailableImageSlot() {
    return [1, 2, 3].find(slot => hasRealImage(slot)) || 1;
}

function getSelectedMainSlot() {
    const input = getMainSlotInput();
    const value = Number(input.value || 1);

    return [1, 2, 3].includes(value) ? value : 1;
}

function normalizeSelectedMainSlot() {
    const input = getMainSlotInput();
    const selectedSlot = getSelectedMainSlot();

    if (hasRealImage(selectedSlot)) {
        return selectedSlot;
    }

    const fallbackSlot = getFirstAvailableImageSlot();
    input.value = String(fallbackSlot);

    return fallbackSlot;
}

function setSelectedMainSlot(slot) {
    if (![1, 2, 3].includes(slot)) {
        return;
    }

    if (!hasRealImage(slot)) {
        return;
    }

    const input = getMainSlotInput();
    input.value = String(slot);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));

    updateCardStates();

    document.dispatchEvent(new CustomEvent('sf:main-image-changed', {
        detail: {
            slot: slot
        }
    }));

    document.dispatchEvent(new CustomEvent('sf:image-selected', {
        detail: {
            slot: slot,
            mainImageSlot: slot,
            mainImageChanged: true
        }
    }));

    window.Preview?.applyGridClass?.();
    window.PreviewTutkinta?.applyGridClass?.();
}

function updateCardStates() {
    const selectedMainSlot = normalizeSelectedMainSlot();

    [1, 2, 3].forEach(slot => {
        const card = document.querySelector(`.sf-image-upload-card[data-slot="${slot}"]`);
        const button = document.querySelector(`.sf-image-main-switch-btn[data-main-image-switch-slot="${slot}"]`);
        const removeButton = document.querySelector(`.sf-image-remove-btn[data-slot="${slot}"]`);

        const slotHasImage = hasRealImage(slot);
        const isMain = slotHasImage && slot === selectedMainSlot;

        if (card) {
            card.classList.toggle('has-image', slotHasImage);
            card.classList.toggle('is-main-image', isMain);
        }

        if (button) {
            button.classList.toggle('is-active', isMain);
            button.disabled = !slotHasImage;
            button.setAttribute('aria-pressed', isMain ? 'true' : 'false');
        }

        if (removeButton) {
            removeButton.classList.toggle('hidden', !slotHasImage);
        }
    });
}

function bindButton(button) {
    if (!button || button.dataset.sfMainImageButtonBound === '1') {
        return;
    }

    button.dataset.sfMainImageButtonBound = '1';

    button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        const targetSlot = Number(button.dataset.mainImageSwitchSlot || 0);
        setSelectedMainSlot(targetSlot);
    });
}

export function bindMainImageSwitcher() {
    getMainSlotInput();
    updateCardStates();

    document.querySelectorAll('[data-main-image-switch-slot]').forEach(button => {
        bindButton(button);
    });

    if (document.body.dataset.sfMainImageStateEventsBound === '1') {
        return;
    }

    document.body.dataset.sfMainImageStateEventsBound = '1';

    document.addEventListener('sf:image-selected', function () {
        window.requestAnimationFrame(updateCardStates);
    });

    document.addEventListener('sf:image-removed', function () {
        window.requestAnimationFrame(updateCardStates);
    });
}