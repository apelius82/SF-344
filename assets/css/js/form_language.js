// assets/js/form_language.js
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.sf-form');
    if (!form) return;

    const previewElement = document.getElementById('sf-preview-render');
    const previewInput = document.getElementById('preview_image');

    if (!previewElement || !previewInput || typeof html2canvas === 'undefined') {
        // Jos jotain puuttuu, mennään vain normaalisti eteenpäin
        return;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        html2canvas(previewElement, {
            scale: 2,
            backgroundColor: '#ffffff',
            useCORS: true
        }).then(function (canvas) {
            previewInput.value = canvas.toDataURL('image/jpeg', 0.9);
            form.submit();
        }).catch(function () {
            // Jos renderöinti epäonnistuu, lähetetään silti ilman kuvaa
            form.submit();
        });
    });
});