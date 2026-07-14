// assets/js/modules/preview-init.js
// Keskitetty preview-alustus - vältetään syklinen riippuvuus

'use strict';

/**
 * Alustaa oikean preview-objektin tyypin mukaan
 * @param {string} type - 'red', 'yellow' tai 'green'
 */
export function initializePreview(type) {
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