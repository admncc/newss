/**
 * Nachrichtenblatt Child — Frontend-Verbesserungen.
 *
 * Bewusst Vanilla-JS, keine Dependencies. Alles defer-loaded.
 *
 * Features:
 *   - Lesefortschrittsbalken (nur auf Single-Posts)
 *   - Dark-Mode-Toggle mit localStorage-Persistenz
 *   - Smooth-Scroll für In-Page-Anker (mit reduced-motion-Respekt)
 *   - Sticky-Header-Klasse beim Scrollen
 */

(function () {
    'use strict';

    var doc  = document;
    var html = doc.documentElement;
    var STORAGE_KEY = 'nbc-color-mode';
    var ctx = window.NBC || {};
    var i18n = ctx.i18n || {};

    /* ---------------------------------------------------------------
       Color-Mode (light/dark) — Reihenfolge der Prioritäten:
       1) localStorage
       2) data-attribute (vom Customizer im <head> gesetzt)
       3) prefers-color-scheme (CSS-Default)
    --------------------------------------------------------------- */
    function getStoredMode() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }
    function setStoredMode(mode) {
        try {
            if (mode === null) { localStorage.removeItem(STORAGE_KEY); }
            else { localStorage.setItem(STORAGE_KEY, mode); }
        } catch (e) {}
    }
    function applyMode(mode) {
        if (mode === 'light' || mode === 'dark') {
            html.setAttribute('data-nbc-color-mode', mode);
        } else {
            html.removeAttribute('data-nbc-color-mode');
        }
    }
    function currentEffectiveMode() {
        var attr = html.getAttribute('data-nbc-color-mode');
        if (attr === 'light' || attr === 'dark') { return attr; }
        var mql = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');
        return (mql && mql.matches) ? 'dark' : 'light';
    }

    var stored = getStoredMode();
    if (stored) { applyMode(stored); }

    /* ---------------------------------------------------------------
       Dark-Mode-Toggle-Button — fix unten rechts
    --------------------------------------------------------------- */
    function buildToggle() {
        var btn = doc.createElement('button');
        btn.className = 'nbc-mode-toggle';
        btn.type = 'button';
        btn.setAttribute('aria-live', 'polite');
        render(btn, currentEffectiveMode());

        btn.addEventListener('click', function () {
            var next = currentEffectiveMode() === 'dark' ? 'light' : 'dark';
            applyMode(next);
            setStoredMode(next);
            render(btn, next);
        });

        // Wenn das System-Theme wechselt UND Nutzer:in keine Wahl gespeichert hat,
        // Icon aktualisieren.
        if (window.matchMedia) {
            var mql = window.matchMedia('(prefers-color-scheme: dark)');
            if (mql.addEventListener) {
                mql.addEventListener('change', function () {
                    if (!getStoredMode()) { render(btn, currentEffectiveMode()); }
                });
            }
        }

        doc.body.appendChild(btn);
    }

    var SUN  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>';
    var MOON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';

    function render(btn, mode) {
        if (mode === 'dark') {
            btn.innerHTML = SUN;
            btn.setAttribute('aria-label', i18n.darkOff || 'Helles Design aktivieren');
            btn.title = btn.getAttribute('aria-label');
        } else {
            btn.innerHTML = MOON;
            btn.setAttribute('aria-label', i18n.darkOn || 'Dunkles Design aktivieren');
            btn.title = btn.getAttribute('aria-label');
        }
    }

    /* ---------------------------------------------------------------
       Lesefortschrittsbalken — nur auf Single-Posts
    --------------------------------------------------------------- */
    function initReadingProgress() {
        if (!ctx.isSingle) { return; }

        // Article-Container finden — Newspaper kennt mehrere Selektoren.
        var article = doc.querySelector('.td-post-content, .tdb_single_content, .entry-content, article');
        if (!article) { return; }

        var bar = doc.createElement('div');
        bar.className = 'nbc-progress';
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-label', 'Lesefortschritt');
        bar.setAttribute('aria-valuemin', '0');
        bar.setAttribute('aria-valuemax', '100');
        bar.setAttribute('aria-valuenow', '0');
        doc.body.appendChild(bar);

        var ticking = false;
        function update() {
            var rect = article.getBoundingClientRect();
            var viewport = window.innerHeight || html.clientHeight;
            var total = rect.height - viewport + rect.top + window.scrollY;
            var scrolled = window.scrollY - (rect.top + window.scrollY - 0);
            var pct = total > 0 ? Math.min(100, Math.max(0, (scrolled / total) * 100)) : 0;
            // articleStart relativ zum Dokument:
            var startY = rect.top + window.scrollY;
            var endY   = startY + rect.height - viewport;
            var p = endY > startY ? ((window.scrollY - startY) / (endY - startY)) * 100 : 0;
            p = Math.min(100, Math.max(0, p));
            bar.style.width = p.toFixed(1) + '%';
            bar.setAttribute('aria-valuenow', String(Math.round(p)));
            ticking = false;
        }
        function onScroll() {
            if (!ticking) {
                window.requestAnimationFrame(update);
                ticking = true;
            }
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
        update();
    }

    /* ---------------------------------------------------------------
       Smooth-Scroll für In-Page-Anker — respektiert reduced-motion
    --------------------------------------------------------------- */
    function initSmoothScroll() {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        doc.addEventListener('click', function (e) {
            var a = e.target && e.target.closest && e.target.closest('a[href^="#"]');
            if (!a) { return; }
            var id = a.getAttribute('href');
            if (id.length < 2) { return; }
            var target = doc.querySelector(id);
            if (!target) { return; }
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            // History sauber halten
            if (history.pushState) { history.pushState(null, '', id); }
        });
    }

    /* ---------------------------------------------------------------
       Sticky-Header-Schatten beim Scrollen
    --------------------------------------------------------------- */
    function initStickyShadow() {
        var header = doc.querySelector('.tdb-header-wrap, .td-header-wrap, header.site-header');
        if (!header) { return; }
        var update = function () {
            if (window.scrollY > 8) { header.classList.add('nbc-scrolled'); }
            else { header.classList.remove('nbc-scrolled'); }
        };
        window.addEventListener('scroll', update, { passive: true });
        update();
    }

    /* ---------------------------------------------------------------
       Boot
    --------------------------------------------------------------- */
    function ready(fn) {
        if (doc.readyState === 'loading') {
            doc.addEventListener('DOMContentLoaded', fn);
        } else { fn(); }
    }
    ready(function () {
        buildToggle();
        initReadingProgress();
        initSmoothScroll();
        initStickyShadow();
    });
})();
