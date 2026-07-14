// assets/js/pjax.js
// Kevyt “smooth” navigaatio + progress bar + CSS-linkkien synkkaus
// Lähettää aina sf:page:loaded eventin, jotta sivukohtaiset initit voidaan ajaa uudelleen.

(function () {
    "use strict";

    const containerSelector = ".sf-container";
    const progressId = "sfProgressBar";

    function sameOrigin(url) {
        try {
            const u = new URL(url, window.location.href);
            return u.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function isPjaxableLink(a) {
        if (!a) return false;
        const href = a.getAttribute("href");
        if (!href) return false;
        if (href.startsWith("#")) return false;
        if (a.getAttribute("target")) return false;
        if (a.hasAttribute("download")) return false;
        if (a.getAttribute("rel") === "external") return false;
        if (!sameOrigin(href)) return false;

        // ÄLÄ PJAX-kaappaa appin sivuja (index.php?page=...), koska sivukohtaiset skriptit
        // (list-filters.js, list-views.js jne.) eivät lataudu/initialisoidu PJAX-innerHTML-vaihdossa.
        try {
            const u = new URL(href, window.location.href);
            if (u.searchParams.has("page")) return false;
        } catch (e) {
            return false;
        }

        // älä kaappaa “logout tms” jos haluat – lisää ehtoja tarvittaessa
        return true;
    }

    function ensureProgressBar() {
        let bar = document.getElementById(progressId);
        if (bar) return bar;

        bar = document.createElement("div");
        bar.id = progressId;
        bar.style.position = "fixed";
        bar.style.top = "0";
        bar.style.left = "0";
        bar.style.height = "3px";
        bar.style.width = "0%";
        bar.style.zIndex = "999999";
        bar.style.background = "#FEE000";
        bar.style.boxShadow = "0 2px 8px rgba(0,0,0,.18)";
        bar.style.transition = "width 260ms ease, opacity 260ms ease";
        bar.style.opacity = "0";
        document.body.appendChild(bar);
        return bar;
    }

    function progressStart() {
        const bar = ensureProgressBar();
        bar.style.opacity = "1";
        bar.style.width = "12%";
        // pieni “liike” ettei jäätynyt fiilis
        setTimeout(() => (bar.style.width = "45%"), 180);
        setTimeout(() => (bar.style.width = "72%"), 520);
    }

    function progressDone() {
        const bar = ensureProgressBar();
        bar.style.width = "100%";
        setTimeout(() => {
            bar.style.opacity = "0";
            bar.style.width = "0%";
        }, 260);
    }

    function syncStyles(newDoc) {
        const newLinks = Array.from(newDoc.querySelectorAll('link[rel="stylesheet"][href]'));
        const curLinks = Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'));
        const curHrefs = new Set(curLinks.map((l) => l.getAttribute("href")));

        // Lisää puuttuvat CSS:t (EI poisteta vanhoja: turvallinen ja estää “rikkinäisen sivun”)
        newLinks.forEach((l) => {
            const href = l.getAttribute("href");
            if (!href || curHrefs.has(href)) return;
            const clone = l.cloneNode(true);
            document.head.appendChild(clone);
        });
    }

    function updateBodyAttrs(newDoc) {
        const newBody = newDoc.body;
        if (!newBody) return;

        // Päivitä data-page ja class
        const newPage = newBody.getAttribute("data-page");
        if (newPage) document.body.setAttribute("data-page", newPage);

        // korvaa body class kokonaan (tärkeä form-page yms.)
        document.body.className = newBody.className || "";
    }

    function dispatchPageLoaded(url) {
        const page = document.body.getAttribute("data-page") || "";
        const ev = new CustomEvent("sf:page:loaded", {
            detail: { url, page },
        });
        document.dispatchEvent(ev);
    }

    async function loadUrl(url, { push = true } = {}) {
        const container = document.querySelector(containerSelector);
        if (!container) return;

        document.dispatchEvent(new CustomEvent("sf:page:before", { detail: { url } }));
        progressStart();

        const res = await fetch(url, {
            headers: { "X-Requested-With": "sf-pjax" },
            credentials: "same-origin",
        });

        // Jos palvelin ohjaa (esim. login), seuraa sitä “normaalisti”
        if (res.redirected) {
            window.location.href = res.url;
            return;
        }

        const html = await res.text();
        const parser = new DOMParser();
        const newDoc = parser.parseFromString(html, "text/html");

        // Päivitä title
        const t = newDoc.querySelector("title");
        if (t) document.title = t.textContent || document.title;

        // CSS-linkit mukaan (tärkein syy miksi “sivut on rikki”)
        syncStyles(newDoc);

        // Vaihda containerin sisältö
        const newContainer = newDoc.querySelector(containerSelector);
        if (!newContainer) {
            // fallback: jos ei löydy, tee normaali navigaatio
            window.location.href = url;
            return;
        }

        // Fade-out / fade-in (hyvin kevyt)
        container.style.opacity = "0";
        await new Promise((r) => setTimeout(r, 140));
        container.innerHTML = newContainer.innerHTML;
        container.style.opacity = "1";

        updateBodyAttrs(newDoc);

        if (push) {
            history.pushState({ url }, "", url);
        }

        // scroll top
        window.scrollTo({ top: 0, behavior: "instant" });

        progressDone();
        dispatchPageLoaded(url);
    }

    // Klikkikaappaus
    document.addEventListener("click", (e) => {
        const a = e.target.closest("a");
        if (!isPjaxableLink(a)) return;

        const href = a.getAttribute("href");
        if (!href) return;

        // anna ctrl/cmd click tehdä normaali avaus
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        e.preventDefault();
        loadUrl(href, { push: true }).catch((err) => {
            console.error("PJAX load failed:", err);
            window.location.href = href;
        });
    });

    // Back/Forward
    window.addEventListener("popstate", (e) => {
        const url = (e.state && e.state.url) || window.location.href;
        loadUrl(url, { push: false }).catch((err) => {
            console.error("PJAX popstate failed:", err);
            window.location.href = url;
        });
    });

    // Ensimmäinen state talteen
    history.replaceState({ url: window.location.href }, "", window.location.href);

    // BFCache (iOS/Safari) varmistus: jos sivu palaa cachella, initit uusiksi
    window.addEventListener("pageshow", (e) => {
        if (e.persisted) dispatchPageLoaded(window.location.href);
    });
})();