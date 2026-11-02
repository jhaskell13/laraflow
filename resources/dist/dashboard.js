/*
 * The Laraflow dashboard's one script: progressive enhancement only.
 *
 * Two jobs. Refresh: on an interval the page asks for itself and swaps
 * in the new <main>, preserving which disclosures are open, pausing
 * while the tab is hidden or an operator is typing into a form.
 * Confirmation: a form marked data-lf-confirm asks before it posts.
 *
 * Without this file every page is a full server render and every
 * action is an ordinary form (docs/dashboard.md, "Works without
 * JavaScript"). Hand-written and committed; served by the package from
 * {path}/assets/dashboard.js, same-origin, nothing inline.
 */
(function () {
    'use strict';

    var body = document.body;
    var seconds = parseInt(body.getAttribute('data-lf-refresh') || '0', 10);
    var toggle = document.querySelector('[data-lf-refresh-toggle]');
    var STORAGE = 'laraflow.dashboard.refresh';
    var submitting = false;
    var timer = null;

    // ---- Confirmation -------------------------------------------------

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var message = form.getAttribute('data-lf-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }

        // A post in flight: do not swap the page out from under it.
        submitting = true;
    });

    // ---- Refresh ------------------------------------------------------

    if (!(seconds > 0)) {
        if (toggle) {
            toggle.hidden = true;
        }

        return;
    }

    function enabled() {
        try {
            return window.localStorage.getItem(STORAGE) !== 'off';
        } catch (e) {
            return true;
        }
    }

    function setEnabled(on) {
        try {
            window.localStorage.setItem(STORAGE, on ? 'on' : 'off');
        } catch (e) {
            // Storage unavailable: the choice lasts for this page only.
        }

        render();
        schedule();
    }

    function render() {
        if (!toggle) {
            return;
        }

        var on = enabled();

        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        toggle.classList.toggle('is-on', on);
        toggle.textContent = on ? 'Live · ' + seconds + 's' : 'Live · off';
        toggle.title = on
            ? 'Refreshing every ' + seconds + ' seconds. Click to pause.'
            : 'Not refreshing. Click to resume.';
    }

    /**
     * An operator mid-thought is not interrupted: a focused control, or
     * a form field holding something not yet sent, holds the refresh.
     */
    function busy() {
        if (submitting || document.hidden) {
            return true;
        }

        var active = document.activeElement;

        if (active && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName) && active.type !== 'checkbox') {
            return true;
        }

        var fields = document.querySelectorAll('.lf-action input[type="text"], .lf-action textarea');

        for (var i = 0; i < fields.length; i++) {
            if (fields[i].value !== fields[i].defaultValue) {
                return true;
            }
        }

        return false;
    }

    function openDisclosures() {
        var open = [];
        var details = document.querySelectorAll('main details[open] > summary');

        for (var i = 0; i < details.length; i++) {
            open.push(details[i].textContent.trim());
        }

        return open;
    }

    function restoreDisclosures(open) {
        var summaries = document.querySelectorAll('main details > summary');

        for (var i = 0; i < summaries.length; i++) {
            var wasOpen = open.indexOf(summaries[i].textContent.trim()) !== -1;

            summaries[i].parentElement.open = wasOpen;
        }
    }

    function refresh() {
        if (busy()) {
            schedule();
            return;
        }

        var open = openDisclosures();

        window.fetch(window.location.href, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'laraflow-dashboard' }
        }).then(function (response) {
            if (!response.ok) {
                // Denied, gone, or down: say nothing and try again
                // later rather than replacing the page with an error.
                throw new Error(String(response.status));
            }

            return response.text();
        }).then(function (html) {
            var next = new DOMParser().parseFromString(html, 'text/html');
            var main = next.querySelector('main.lf-main');
            var clock = next.querySelector('.lf-header__clock');
            var current = document.querySelector('main.lf-main');

            if (!main || !current || busy()) {
                return;
            }

            current.innerHTML = main.innerHTML;
            restoreDisclosures(open);

            var currentClock = document.querySelector('.lf-header__clock');

            if (clock && currentClock) {
                currentClock.textContent = clock.textContent;
                currentClock.setAttribute('datetime', clock.getAttribute('datetime') || '');
            }
        }).catch(function () {
            // Next tick tries again.
        }).then(schedule);
    }

    function schedule() {
        if (timer) {
            window.clearTimeout(timer);
            timer = null;
        }

        if (enabled()) {
            timer = window.setTimeout(refresh, seconds * 1000);
        }
    }

    if (toggle) {
        toggle.hidden = false;
        toggle.addEventListener('click', function () {
            setEnabled(!enabled());
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            schedule();
        }
    });

    render();
    schedule();
})();
