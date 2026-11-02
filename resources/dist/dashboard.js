/*
 * The Laraflow dashboard's one script: progressive enhancement only.
 *
 * Three jobs, all built on one primitive — ask for a URL and swap in
 * its <main>, never navigating:
 *
 *   Live filters.  Changing a filter loads the filtered page in place
 *                  and pushes it onto history. A navigation would take
 *                  the field out from under the operator mid-word;
 *                  swapping keeps focus and caret exactly where they
 *                  were, which is the only reason live filtering is an
 *                  improvement rather than an irritation.
 *   Refresh.       On an interval the page asks for itself again, so a
 *                  list stays current while an operator watches it.
 *   Confirmation.  A form marked data-lf-confirm asks before it posts.
 *
 * Without this file every page is a full server render, every filter is
 * a GET form with a button, and every action is an ordinary form
 * (docs/dashboard.md, "Works without JavaScript"). Hand-written and
 * committed; served by the package from {path}/assets/dashboard.js,
 * same-origin, nothing inline.
 */
(function () {
    'use strict';

    var body = document.body;
    var seconds = parseInt(body.getAttribute('data-lf-refresh') || '0', 10);
    var STORAGE = 'laraflow.dashboard.refresh';
    var DEBOUNCE = 350;

    var submitting = false;
    var inFlight = null;
    var refreshTimer = null;
    var filterTimer = null;

    body.classList.add('lf-js');

    function main() {
        return document.querySelector('main.lf-main');
    }

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
        if (form.method && form.method.toLowerCase() === 'post') {
            submitting = true;
        }
    });

    // ---- The primitive: load a URL into <main> --------------------------

    /**
     * What the operator is in the middle of, so it can be put back
     * after the swap: the field, where the caret sits, and how far the
     * page is scrolled.
     */
    function captureFocus() {
        var active = document.activeElement;

        if (!active || !active.name || !main() || !main().contains(active)) {
            return null;
        }

        var state = { name: active.name, value: active.value, start: null, end: null };

        try {
            state.start = active.selectionStart;
            state.end = active.selectionEnd;
        } catch (e) {
            // Checkboxes and the like have no selection; focus is enough.
        }

        return state;
    }

    function restoreFocus(state) {
        if (!state) {
            return;
        }

        var field = document.querySelector('main.lf-main [name="' + state.name.replace(/"/g, '\\"') + '"]');

        if (!field) {
            return;
        }

        // The server echoed what was typed; if the operator has typed
        // further since the request went out, theirs wins.
        if (state.value !== undefined && field.value !== state.value) {
            field.value = state.value;
        }

        field.focus();

        if (state.start !== null && state.end !== null) {
            try {
                field.setSelectionRange(state.start, state.end);
            } catch (e) {
                // Not a text field after all.
            }
        }
    }

    function openDisclosures() {
        var open = [];
        var summaries = document.querySelectorAll('main details[open] > summary');

        for (var i = 0; i < summaries.length; i++) {
            open.push(summaries[i].textContent.trim());
        }

        return open;
    }

    function restoreDisclosures(open) {
        var summaries = document.querySelectorAll('main details > summary');

        for (var i = 0; i < summaries.length; i++) {
            summaries[i].parentElement.open = open.indexOf(summaries[i].textContent.trim()) !== -1;
        }
    }

    /**
     * @param {string} url
     * @param {{push: boolean, keepFocus: boolean}} options
     */
    function load(url, options) {
        var current = main();

        if (!current) {
            return Promise.resolve();
        }

        var focus = options.keepFocus ? captureFocus() : null;
        var open = openDisclosures();
        var scroll = window.scrollY;

        body.classList.add('is-loading');

        var request = window.fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'laraflow-dashboard' }
        }).then(function (response) {
            if (!response.ok) {
                // Denied, gone, or down: say nothing and leave the page
                // standing rather than replacing it with an error.
                throw new Error(String(response.status));
            }

            return response.text();
        }).then(function (html) {
            if (inFlight !== request) {
                // A later request overtook this one; its answer wins.
                return;
            }

            var next = new DOMParser().parseFromString(html, 'text/html');
            var fresh = next.querySelector('main.lf-main');
            var still = main();

            if (!fresh || !still) {
                return;
            }

            still.innerHTML = fresh.innerHTML;
            restoreDisclosures(open);
            restoreFocus(focus);
            window.scrollTo(0, scroll);

            var clock = next.querySelector('.lf-header__clock');
            var currentClock = document.querySelector('.lf-header__clock');

            if (clock && currentClock) {
                currentClock.textContent = clock.textContent;
                currentClock.setAttribute('datetime', clock.getAttribute('datetime') || '');
            }

            if (options.push && url !== window.location.href) {
                window.history.pushState({ laraflow: true }, '', url);
            }
        }).catch(function () {
            // Next tick, or the next keystroke, tries again.
        }).then(function () {
            if (inFlight === request) {
                inFlight = null;
                body.classList.remove('is-loading');
            }

            scheduleRefresh();
        });

        inFlight = request;

        return request;
    }

    // ---- Live filters ---------------------------------------------------

    /**
     * The URL a filter form describes: its own action plus whatever is
     * filled in. Blank fields are left out so the address stays the one
     * an operator could have typed, and the cursor is dropped because a
     * new filter is a new set — page one of it.
     */
    function filterUrl(form) {
        var url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        var data = new FormData(form);
        var params = new URLSearchParams();

        data.forEach(function (value, key) {
            if (typeof value === 'string' && value.trim() !== '') {
                params.append(key, value);
            }
        });

        url.search = params.toString();

        return url.toString();
    }

    function onFilterChanged(form, immediate) {
        if (filterTimer) {
            window.clearTimeout(filterTimer);
        }

        filterTimer = window.setTimeout(function () {
            filterTimer = null;
            load(filterUrl(form), { push: true, keepFocus: true });
        }, immediate ? 0 : DEBOUNCE);
    }

    // Delegated, so the handlers survive every swap of <main>.
    document.addEventListener('input', function (event) {
        var form = event.target.form;

        if (form && form.classList.contains('lf-filters')) {
            onFilterChanged(form, false);
        }
    });

    document.addEventListener('change', function (event) {
        var form = event.target.form;

        if (form && form.classList.contains('lf-filters')) {
            // A checkbox is a decision, not a word in progress.
            onFilterChanged(form, event.target.type === 'checkbox');
        }
    });

    // The button is the no-script path; with script the form is live,
    // so submitting it must not navigate.
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (form instanceof HTMLFormElement && form.classList.contains('lf-filters')) {
            event.preventDefault();
            onFilterChanged(form, true);
        }
    });

    // Paging and clearing are links; following them in place keeps the
    // operator's scroll position and their open disclosures.
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        var link = event.target.closest('a[data-lf-page]');

        if (!link || !main() || !main().contains(link)) {
            return;
        }

        event.preventDefault();
        load(link.href, { push: true, keepFocus: false });
    });

    window.addEventListener('popstate', function () {
        load(window.location.href, { push: false, keepFocus: false });
    });

    // ---- Refresh ----------------------------------------------------------

    var toggle = document.querySelector('[data-lf-refresh-toggle]');

    function refreshEnabled() {
        if (!(seconds > 0)) {
            return false;
        }

        try {
            return window.localStorage.getItem(STORAGE) !== 'off';
        } catch (e) {
            return true;
        }
    }

    function renderToggle() {
        if (!toggle) {
            return;
        }

        var on = refreshEnabled();

        toggle.hidden = false;
        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        toggle.classList.toggle('is-on', on);
        toggle.textContent = on ? 'Live · ' + seconds + 's' : 'Live · off';
        toggle.title = on
            ? 'Refreshing every ' + seconds + ' seconds. Click to pause.'
            : 'Not refreshing. Click to resume.';
    }

    /**
     * An operator mid-thought is not interrupted: a focused control, a
     * form holding something not yet sent, or a request already in
     * flight holds the refresh.
     */
    function busy() {
        if (submitting || inFlight || filterTimer || document.hidden) {
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

    function scheduleRefresh() {
        if (refreshTimer) {
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
        }

        if (!refreshEnabled()) {
            return;
        }

        refreshTimer = window.setTimeout(function () {
            if (busy()) {
                scheduleRefresh();
                return;
            }

            load(window.location.href, { push: false, keepFocus: true });
        }, seconds * 1000);
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            try {
                window.localStorage.setItem(STORAGE, refreshEnabled() ? 'off' : 'on');
            } catch (e) {
                // Storage unavailable: the choice lasts for this page.
            }

            renderToggle();
            scheduleRefresh();
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            scheduleRefresh();
        }
    });

    renderToggle();
    scheduleRefresh();
})();
