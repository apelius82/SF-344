// assets/js/settings.js
console.log("settings.js loaded");

(function () {
    let controller = null;

    function isSettingsPage() {
        return (
            document.body?.dataset?.page === "settings" ||
            !!document.querySelector(".sf-settings-page")
        );
    }

    function getTabFromUrl(url) {
        try {
            const u = new URL(url, window.location.origin);
            return u.searchParams.get("tab") || "users";
        } catch {
            return "users";
        }
    }

    function setActiveTab(tabsEl, tabName) {
        if (!tabsEl) return;
        tabsEl.querySelectorAll(".sf-tab").forEach((a) => {
            const href = a.getAttribute("href") || "";
            const isActive = href.includes("tab=" + encodeURIComponent(tabName));
            a.classList.toggle("active", isActive);
        });
    }

    function forceClearLoadingState() {
        const pageRoot = document.querySelector(".sf-settings-page");
        const contentEl = pageRoot?.querySelector(".sf-tabs-content");
        if (contentEl) contentEl.classList.remove("sf-tab-loading");
        document.body.classList.remove("sf-loading", "sf-loading-long");
    }
    function sfToast(text, type = "success") {
        if (!text) return;

        let box = document.getElementById("sf-toast-box");
        if (!box) {
            box = document.createElement("div");
            box.id = "sf-toast-box";
            box.style.position = "fixed";
            box.style.left = "50%";
            box.style.bottom = "18px";
            box.style.transform = "translateX(-50%)";
            box.style.zIndex = "99999";
            box.style.display = "flex";
            box.style.flexDirection = "column";
            box.style.gap = "10px";
            box.style.maxWidth = "92vw";
            document.body.appendChild(box);
        }

        const t = document.createElement("div");
        t.textContent = text;
        t.style.padding = "12px 14px";
        t.style.borderRadius = "12px";
        t.style.boxShadow = "0 12px 30px rgba(0,0,0,0.16)";
        t.style.background = (type === "error") ? "#111827" : "#111827";
        t.style.color = "#fff";
        t.style.fontSize = "14px";
        t.style.lineHeight = "1.35";
        t.style.border = (type === "error") ? "2px solid rgba(239, 68, 68, 0.8)" : "2px solid rgba(254, 224, 0, 0.9)";
        t.style.wordBreak = "break-word";

        box.appendChild(t);

        setTimeout(() => {
            t.style.opacity = "0";
            t.style.transition = "opacity 220ms ease";
        }, 2600);

        setTimeout(() => t.remove(), 3000);
    }
    function showToast(message, type = "success") {
        // Always prefer the global toast helper from header.php
        if (typeof window.sfToast === "function") {
            window.sfToast(type, message);
            return;
        }

        // Fallback: use local minimal toast (no translations here)
        const fallbackMessage = message || (
            type === "error"
                ? (window.SF_I18N?.error || "Error")
                : (window.SF_I18N?.success || "OK")
        );
        sfToast(fallbackMessage, type);
    }


    // Helper: escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }

    function noticeToMessage(code) {
        if (!code) return "";
        const dict = (window.SF_NOTICE_MESSAGES && typeof window.SF_NOTICE_MESSAGES === "object")
            ? window.SF_NOTICE_MESSAGES
            : {};
        return dict[code] || "";
    }

    function replaceUrlPreserveTab(url) {
        // Keep current tab in URL (so refresh/back works nicely)
        try {
            const u = new URL(url, window.location.origin);
            const current = new URL(window.location.href);
            if (!u.searchParams.get("tab") && current.searchParams.get("tab")) {
                u.searchParams.set("tab", current.searchParams.get("tab"));
            }
            history.replaceState({ sfSettings: true }, "", u.toString());
            return u.toString();
        } catch {
            return url;
        }
    }

    async function loadSettingsTab(url, { pushState = true } = {}) {
        const pageRoot = document.querySelector(".sf-settings-page");
        if (!pageRoot) return;

        const tabsEl = pageRoot.querySelector(".sf-tabs");
        const contentEl = pageRoot.querySelector(".sf-tabs-content");
        if (!tabsEl || !contentEl) return;

        if (controller) controller.abort();
        controller = new AbortController();

        const tabName = getTabFromUrl(url);
        setActiveTab(tabsEl, tabName);
        contentEl.classList.add("sf-tab-loading");

        try {
            const res = await fetch(url, {
                method: "GET",
                credentials: "same-origin",
                signal: controller.signal,
                headers: {
                    "X-Requested-With": "fetch",
                    Accept: "text/html",
                },
            });

            if (!res.ok) throw new Error("HTTP " + res.status);
            const html = await res.text();

            const doc = new DOMParser().parseFromString(html, "text/html");
            const newContent = doc.querySelector(".sf-settings-page .sf-tabs-content");

            if (!newContent) {
                window.location.href = url;
                return;
            }

            contentEl.innerHTML = newContent.innerHTML;

            // Suorita uuden sisällön inline-scriptit
            contentEl.querySelectorAll("script").forEach(function (oldScript) {
                var newScript = document.createElement("script");
                if (oldScript.src) {
                    newScript.src = oldScript.src;
                } else {
                    newScript.textContent = oldScript.textContent;
                }
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });

            if (pushState) {
                history.pushState({ sfSettings: true }, "", url);
            }

            window.dispatchEvent(
                new CustomEvent("sf:content:updated", {
                    detail: { page: "settings", tab: tabName, root: pageRoot },
                })
            );
        } catch (err) {
            if (err && err.name === "AbortError") return;
            console.error("Settings tab load failed:", err);
            window.location.href = url;
        } finally {
            contentEl.classList.remove("sf-tab-loading");
            forceClearLoadingState();
        }
    }

    // Detect whether we should AJAX-handle a form
    function shouldAjaxHandleForm(form) {
        if (!(form instanceof HTMLFormElement)) return false;

        // allow opt-out
        if (form.getAttribute("data-sf-ajax") === "0") return false;

        // must have action and be same-origin
        // HUOM: Käytä getAttribute() koska input name="action" ylikirjoittaa form.action propertyn
        const action = form.getAttribute("action") || "";
        if (!action) return false;

        let u;
        try {
            u = new URL(action, window.location.origin);
        } catch {
            return false;
        }
        // Allow action URLs that might contain a different host (e.g. www vs non-www).
        // We'll force same-origin when actually sending the request.

        // only handle POST-like actions inside settings (actions endpoints)
        // this prevents breaking unrelated forms
        // HUOM: Käytä getAttribute() koska input name="method" ylikirjoittaisi form.method propertyn
        const formMethod = form.getAttribute("method") || "POST";
        const isPostish = formMethod.toUpperCase() !== "GET";
        const isActionEndpoint =
            u.pathname.includes("/app/actions/") || u.pathname.includes("/app/api/");

        return isPostish && isActionEndpoint;
    }
    // ===== Kuvapankin kategoriavaihto ilman "hyppyä ylös" =====
    (function () {
        document.addEventListener("click", function (e) {
            const a = e.target.closest(".sf-library-filter-btn");
            if (!a) return;

            // Vain settings-sivulla
            const settingsPage = document.querySelector(".sf-settings-page");
            if (!settingsPage) return;

            // Jos ctrl/cmd click tms → anna avata uuteen tabiin normaalisti
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

            const href = a.getAttribute("href") || "";
            if (!href) return;

            e.preventDefault();

            const keepScrollY = window.scrollY;

            // Käytä samaa tab-loaderia kuin muussa navigoinnissa (yhtenäinen logiikka + scriptien re-run)
            loadSettingsTab(href, { pushState: true })
                .then(() => {
                    // Palauta scroll täsmälleen samaan kohtaan (ei hyppää ylös)
                    window.scrollTo(0, keepScrollY);
                })
                .catch(() => {
                    // Fallback: normaali navigointi
                    window.location.href = href;
                });
        });
    })();
    // AJAX-submit settingsin sisällä (worksites, users, image library, etc.)
    async function handleSettingsAjaxSubmit(form) {
        const pageRoot = document.querySelector(".sf-settings-page");
        if (!pageRoot) return;

        const contentEl = pageRoot.querySelector(".sf-tabs-content");
        if (contentEl) contentEl.classList.add("sf-tab-loading");

        try {

            // Force same-origin request even if the form action contains an absolute base URL
            // (prevents session cookies from being dropped when base_url differs e.g. www vs non-www).
            const formAction = form.getAttribute("action") || "";
            const formMethod = form.getAttribute("method") || "POST";

            // Force same-origin even if formAction contains a different host (www vs non-www)
            // Resolve relative paths against the CURRENT PAGE URL so subfolder installs work
            // (e.g. https://domain.fi/safetyflash-system/...)
            const u = new URL(formAction, window.location.href);
            const actionUrl = window.location.origin + u.pathname + u.search;

            const res = await fetch(actionUrl, {
                method: formMethod.toUpperCase(),
                credentials: "include", // make absolutely sure cookies are sent
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json,text/html,*/*",
                },
                body: new FormData(form),
                redirect: "follow",
            });

            const ct = (res.headers.get("content-type") || "").toLowerCase();
            let notice = "";
            let message = "";
            let type = "success";

            if (!res.ok) {
                if (ct.includes("application/json")) {
                    const data = await res.json().catch(() => null);
                    message = (data && (data.error || data.message)) || "Toiminto epäonnistui.";
                } else {
                    message = "Toiminto epäonnistui.";
                }
                type = "error";
                showToast(message, type);
                await loadSettingsTab(window.location.href, { pushState: false });
                return;
            }

            // Success path:
            if (ct.includes("application/json")) {
                const data = await res.json().catch(() => null);

                if (data && data.ok === false) {
                    message = data.error || "Toiminto epäonnistui.";
                    type = "error";
                    showToast(message, type);
                    await loadSettingsTab(window.location.href, { pushState: false });
                    return;
                }

                notice = (data && data.notice) || "";
                message = (data && data.message) || noticeToMessage(notice) || "";
            } else {
                // HTML/redirect response:
                // Let the browser navigate so the global header notice system handles translations.
                window.location.href = res.url;
                return;
            }

            // Näytä aina joku onnistumisviesti, vaikka backend ei palauttaisi notice/message-kenttiä
            showToast(message || "Tallennettu.", "success");
            const urlWithTab = replaceUrlPreserveTab(window.location.href);
            await loadSettingsTab(urlWithTab, { pushState: false });
        } catch (e) {
            console.error("Settings AJAX submit failed:", e);
            // fallback: allow normal submit to go through
            // HUOM: Käytä HTMLFormElement.prototype.submit. call() koska input name="submit" 
            // ylikirjoittaisi form.submit() metodin
            try {
                HTMLFormElement.prototype.submit.call(form);
            } catch { }
        } finally {
            if (contentEl) contentEl.classList.remove("sf-tab-loading");
            forceClearLoadingState();
        }
    }

    function bindSettingsOnce() {
        if (!isSettingsPage()) return;

        const pageRoot = document.querySelector(".sf-settings-page");
        if (!pageRoot) return;

        // bind once
        if (pageRoot.dataset.sfSettingsBound === "1") {
            forceClearLoadingState();
            return;
        }
        pageRoot.dataset.sfSettingsBound = "1";

        const tabsEl = pageRoot.querySelector(".sf-tabs");
        if (tabsEl) {
            tabsEl.addEventListener("click", (e) => {
                const a = e.target.closest("a.sf-tab");
                if (!a) return;

                const href = a.getAttribute("href");
                if (!href) return;

                const u = new URL(href, window.location.origin);
                if (u.origin !== window.location.origin) return;

                e.preventDefault();
                loadSettingsTab(u.toString(), { pushState: true });
            });
        }

        // Subtab clicks (inside .sf-tabs-content — use delegated handler on pageRoot)
        pageRoot.addEventListener("click", (e) => {
            const a = e.target.closest("a.sf-subtab");
            if (!a) return;

            const href = a.getAttribute("href");
            if (!href) return;

            const u = new URL(href, window.location.origin);
            if (u.origin !== window.location.origin) return;

            e.preventDefault();
            loadSettingsTab(u.toString(), { pushState: true });
        });

        // Delegoitu submit: toimii vaikka .sf-tabs-content vaihtuu innerHTML:llä
        pageRoot.addEventListener(
            "submit",
            (e) => {
                const form = e.target.closest("form");
                if (!form) return;

                if (!shouldAjaxHandleForm(form)) return;

                e.preventDefault();
                handleSettingsAjaxSubmit(form);
            },
            true
        );

        window.addEventListener("popstate", () => {
            if (!isSettingsPage()) return;
            loadSettingsTab(window.location.href, { pushState: false });
        });

        forceClearLoadingState();
    }

    document.addEventListener("DOMContentLoaded", bindSettingsOnce);
    window.addEventListener("sf:pagechange", bindSettingsOnce);

    window.addEventListener("pageshow", () => {
        if (!isSettingsPage()) return;
        forceClearLoadingState();
        bindSettingsOnce();
    });
})();