(function () {
    var BASE_WIDTH = 720;
    var BASE_HEIGHT = 405;

    function scaleAllCards() {
        var wrappers = document.querySelectorAll('.sf-preview-wrapper');
        wrappers.forEach(function (wrapper) {
            scaleCard(wrapper);
        });
    }

    function scaleCard(wrapper) {
        var card = wrapper.querySelector('.sf-preview-card');
        if (!card) return;

        var wrapperWidth = wrapper.offsetWidth;
        if (wrapperWidth === 0) return;

        var scale = wrapperWidth / BASE_WIDTH;
        if (scale > 1) scale = 1;

        card.style.transform = 'scale(' + scale + ')';
        card.style.transformOrigin = 'top left';

        var scaledHeight = BASE_HEIGHT * scale;
        wrapper.style.height = scaledHeight + 'px';
    }

    function init() {
        scaleAllCards();

        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(scaleAllCards, 100);
        });

        window.addEventListener('orientationchange', function () {
            setTimeout(scaleAllCards, 200);
        });

        var observer = new MutationObserver(function () {
            setTimeout(scaleAllCards, 50);
        });

        document.querySelectorAll('.sf-preview-container').forEach(function (container) {
            observer.observe(container, {
                attributes: true,
                childList: true,
                subtree: true
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.PreviewScaler = {
        refresh: scaleAllCards
    };
})();