(function () {
    "use strict";

    const modalBottomSheetControllers = new WeakMap();

    function getOpenModals() {
        return Array.from(document.querySelectorAll(".sf-modal:not(.hidden), .sf-library-modal:not(.hidden)"));
    }

    function ensureBottomSheetHandle(modal) {
        if (!modal) return;

        const modalContent = modal.querySelector(".sf-modal-content, .sf-library-modal-content");
        if (!modalContent) return;

        if (!modalContent.querySelector("[data-sf-bottom-sheet-handle]")) {
            const handle = document.createElement("div");
            handle.className = "sf-bottom-sheet-handle";
            handle.setAttribute("data-sf-bottom-sheet-handle", "true");
            handle.setAttribute("aria-hidden", "true");
            modalContent.insertBefore(handle, modalContent.firstChild);
        }

        modalContent.classList.add("sf-mobile-sheet-content");
    }

    function getBottomSheetController(modal) {
        if (!modal || !window.SFBottomSheetController) {
            return null;
        }

        if (modalBottomSheetControllers.has(modal)) {
            return modalBottomSheetControllers.get(modal);
        }

        const modalContent = modal.querySelector(".sf-modal-content, .sf-library-modal-content");
        if (!modalContent) {
            return null;
        }

        ensureBottomSheetHandle(modal);

        const controller = new window.SFBottomSheetController(modal, {
            isVisible: function () {
                return !modal.classList.contains("hidden");
            },
            onDismiss: function () {
                hideModalImmediately(modal);
            }
        });

        if (!controller || !controller.content) {
            return null;
        }

        modalBottomSheetControllers.set(modal, controller);
        return controller;
    }

    function hideModalImmediately(modal) {
        if (!modal) return;

        const modalContent = modal.querySelector(".sf-modal-content, .sf-library-modal-content");

        modal.classList.add("hidden");
        modal.classList.remove("open");
        modal.style.transition = "";
        modal.style.opacity = "";

        if (modalContent) {
            modalContent.style.transition = "";
            modalContent.style.transform = "";
            modalContent.style.opacity = "";
        }

        document.body.style.overflow = "";

        if (getOpenModals().length === 0) {
            document.body.classList.remove("sf-modal-open");
        }
    }

    function openModal(modal) {
        if (!modal) return;

        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }

        modal.style.opacity = "";
        modal.style.transition = "";
        modal.classList.remove("hidden");
        document.body.classList.add("sf-modal-open");

        const controller = getBottomSheetController(modal);
        if (controller) {
            controller.open();
        }

        const focusable = modal.querySelector("button, [href], input, select, textarea, [tabindex]:not([tabindex='-1'])");
        if (focusable) {
            focusable.focus({ preventScroll: true });
        }
    }

    function closeModal(modal) {
        if (!modal) return;

        const controller = modalBottomSheetControllers.get(modal);

        if (controller && controller.isCurrentlyOpen()) {
            controller.close("programmatic");
            return;
        }

        hideModalImmediately(modal);
    }

    window.sfOpenModal = openModal;
    window.sfCloseModal = closeModal;

    document.addEventListener("click", function (event) {
        const opener = event.target.closest("[data-modal-open]");

        if (opener) {
            const rawSelector = opener.getAttribute("data-modal-open");
            let modal = null;

            if (rawSelector) {
                if (rawSelector.charAt(0) === "#") {
                    modal = document.querySelector(rawSelector);
                } else {
                    modal = document.getElementById(rawSelector);
                }
            }

            if (modal) {
                event.preventDefault();
                openModal(modal);
                return;
            }
        }

        const closer = event.target.closest("[data-modal-close]");

        if (closer) {
            const modal = closer.closest(".sf-modal, .sf-library-modal");

            if (modal) {
                event.preventDefault();
                closeModal(modal);
                return;
            }
        }

        const overlay = event.target.classList && (
            event.target.classList.contains("sf-modal") ||
            event.target.classList.contains("sf-library-modal")
        )
            ? event.target
            : null;

        if (overlay) {
            closeModal(overlay);
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") return;

        const openModals = getOpenModals();

        if (openModals.length > 0) {
            closeModal(openModals[openModals.length - 1]);
        }
    });
})();