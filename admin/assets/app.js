/* =============================================================================
   Andijon AI Talents — admin panel behaviour
   -----------------------------------------------------------------------------
   Vanilla ES2017, no dependency, no inline handler: the panel runs under
   `Content-Security-Policy: script-src 'self'`, so every listener is attached
   here and every hook is a data-* attribute placed in the templates.

   Modules
     01. Small helpers ($, $$, on, ready, debounce)
     02. Translations (meta[name="panel-i18n"]) and CSRF (meta[name="csrf-token"])
     03. Toasts
     04. Sidebar drawer
     05. Modal
     06. Confirm dialogs
     07. Bulk selection
     08. Broadcast wizard (counter, live audience, batched sender)
     09. Copy to clipboard
     10. Filter auto-submit and debounced live search
     11. Query-string synchronisation for sort links and pagination
     12. Unsaved-changes guard
   ========================================================================== */

(function () {
    'use strict';

    /* =========================================================================
       01. HELPERS
       ====================================================================== */

    /** First match, or null. Never throws on an invalid selector. */
    function $(selector, scope) {
        try {
            return (scope || document).querySelector(selector);
        } catch (error) {
            return null;
        }
    }

    /** All matches as a real array (empty when the selector is unusable). */
    function $$(selector, scope) {
        try {
            return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
        } catch (error) {
            return [];
        }
    }

    /**
     * `Element.closest()` that tolerates any event target — a text node, the
     * document itself or an SVG element inside a button.
     */
    function closest(target, selector) {
        let node = target;

        while (node && node.nodeType !== 1) {
            node = node.parentNode;
        }

        return node && typeof node.closest === 'function' ? node.closest(selector) : null;
    }

    /** Guarded addEventListener. */
    function on(target, type, handler, options) {
        if (target && typeof target.addEventListener === 'function') {
            target.addEventListener(type, handler, options || false);
        }
    }

    /** Run a callback once the DOM is usable. */
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    /** Classic trailing debounce. */
    function debounce(fn, wait) {
        let timer = null;

        return function () {
            const context = this;
            const args = arguments;

            if (timer) {
                window.clearTimeout(timer);
            }

            timer = window.setTimeout(function () {
                timer = null;
                fn.apply(context, args);
            }, wait);
        };
    }

    /** Read a data-* attribute with a default. */
    function data(element, name, fallback) {
        if (!element) {
            return fallback;
        }

        const value = element.getAttribute('data-' + name);

        return value === null || value === '' ? fallback : value;
    }

    /** Integer version of data(). */
    function dataInt(element, name, fallback) {
        const parsed = parseInt(data(element, name, ''), 10);

        return isNaN(parsed) ? fallback : parsed;
    }

    /** Format an integer with thin spaces so 12345 reads as 12 345. */
    function number(value) {
        const n = Number(value);

        if (!isFinite(n)) {
            return '0';
        }

        return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }


    /* =========================================================================
       02. TRANSLATIONS AND CSRF
       -------------------------------------------------------------------------
       The layout prints the strings this file needs as JSON inside
       <meta name="panel-i18n" content='{"panel.copied":"Nusxalandi", …}'>.
       A missing key falls back to the caller's own text, so a forgotten meta tag
       degrades the wording but never the behaviour.
       ====================================================================== */

    const i18n = (function () {
        const meta = $('meta[name="panel-i18n"]');

        if (!meta) {
            return {};
        }

        try {
            const parsed = JSON.parse(meta.getAttribute('content') || '{}');

            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }());

    /** Translate `key`, substituting `:name` placeholders. */
    function t(key, fallback, params) {
        let text = Object.prototype.hasOwnProperty.call(i18n, key) ? String(i18n[key]) : String(fallback || '');

        if (params) {
            Object.keys(params).forEach(function (name) {
                text = text.split(':' + name).join(String(params[name]));
            });
        }

        return text;
    }

    /** The CSRF token printed by the layout. */
    function csrfToken() {
        const meta = $('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') || '' : '';
    }

    /**
     * POST a form-encoded body and resolve with the decoded JSON envelope.
     *
     * Every failure mode ends up as a rejected promise carrying a readable
     * message: a network error, an HTTP status, an HTML error page returned
     * instead of JSON, or `{"ok": false, "error": "…"}` from the controller.
     */
    async function postJson(url, fields) {
        const body = new URLSearchParams();

        Object.keys(fields || {}).forEach(function (name) {
            const value = fields[name];

            if (value !== null && value !== undefined) {
                body.append(name, String(value));
            }
        });

        body.append('_token', csrfToken());

        let response;

        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken(),
                    Accept: 'application/json'
                },
                body: body.toString()
            });
        } catch (error) {
            throw new Error(t('error.generic', 'Network error'));
        }

        const raw = await response.text();
        let payload = null;

        try {
            payload = JSON.parse(raw);
        } catch (error) {
            payload = null;
        }

        if (!payload || typeof payload !== 'object') {
            throw new Error(t('error.generic', 'Unexpected server response (HTTP ' + response.status + ')'));
        }

        if (response.ok !== true || payload.ok === false) {
            throw new Error(String(payload.error || t('error.generic', 'Request failed')));
        }

        return payload;
    }


    /* =========================================================================
       03. TOASTS
       Server flash messages are rendered as `.toast` nodes; this module reveals
       them, hides them again after five seconds and creates new ones on demand.
       ====================================================================== */

    /** The (lazily created) fixed container in the corner of the screen. */
    function toastHost() {
        let host = $('.toasts');

        if (!host) {
            host = document.createElement('div');
            host.className = 'toasts';
            host.setAttribute('aria-live', 'polite');
            host.setAttribute('aria-atomic', 'true');
            document.body.appendChild(host);
        }

        return host;
    }

    /** Fade a toast out and drop it from the DOM. */
    function dismissToast(toast) {
        if (!toast || toast.classList.contains('is-leaving')) {
            return;
        }

        toast.classList.add('is-leaving');
        toast.classList.remove('is-visible');

        window.setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 260);
    }

    /** Reveal a toast already present in the markup and arm its timer. */
    function activateToast(toast, delay) {
        if (!toast || toast.dataset.toastReady === '1') {
            return;
        }

        toast.dataset.toastReady = '1';

        window.setTimeout(function () {
            toast.classList.add('is-visible');
        }, delay || 20);

        // data-sticky keeps a toast on screen until it is clicked away.
        if (toast.hasAttribute('data-sticky')) {
            return;
        }

        const timeout = dataInt(toast, 'timeout', 5000);

        window.setTimeout(function () {
            dismissToast(toast);
        }, timeout + (delay || 0));
    }

    /** Create and show a toast from JavaScript. */
    function toast(message, type) {
        if (!message) {
            return null;
        }

        const node = document.createElement('div');
        node.className = 'toast toast--' + (type || 'info');
        node.setAttribute('role', 'status');

        const text = document.createElement('span');
        text.className = 'toast__text';
        text.textContent = String(message);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast__close';
        close.setAttribute('data-dismiss', 'toast');
        close.setAttribute('aria-label', t('common.close', 'Close'));
        close.textContent = '×';

        node.appendChild(text);
        node.appendChild(close);
        toastHost().appendChild(node);
        activateToast(node, 20);

        return node;
    }

    function initToasts() {
        $$('.toast').forEach(function (node, index) {
            activateToast(node, 60 * index);
        });

        // One delegated listener covers the close button of every toast,
        // including the ones created later by toast().
        on(document, 'click', function (event) {
            const button = closest(event.target, '[data-dismiss="toast"]');

            if (button) {
                event.preventDefault();
                dismissToast(closest(button, '.toast'));
            }
        });
    }


    /* =========================================================================
       04. SIDEBAR DRAWER
       Below 900px the sidebar is off-canvas; `.is-open` on `.app` reveals it.
       ====================================================================== */

    function initSidebar() {
        const app = $('.app');

        if (!app) {
            return;
        }

        const toggles = $$('[data-sidebar-toggle], [data-toggle="sidebar"]');

        function setOpen(open) {
            app.classList.toggle('is-open', open);

            // Freeze the page behind the drawer (see .has-drawer in app.css).
            if (document.body) {
                document.body.classList.toggle('has-drawer', open && window.innerWidth <= 900);
            }

            toggles.forEach(function (button) {
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            });

            const sidebar = $('.sidebar', app);

            if (sidebar) {
                sidebar.setAttribute('aria-hidden', open || window.innerWidth > 900 ? 'false' : 'true');
            }
        }

        toggles.forEach(function (button) {
            button.setAttribute('aria-expanded', 'false');

            on(button, 'click', function (event) {
                event.preventDefault();
                setOpen(!app.classList.contains('is-open'));
            });
        });

        // Backdrop, explicit close buttons and navigating away all close it.
        on(document, 'click', function (event) {
            if (closest(event.target, '.app__backdrop') || closest(event.target, '[data-close="sidebar"]')) {
                event.preventDefault();
                setOpen(false);

                return;
            }

            if (window.innerWidth <= 900 && closest(event.target, '.sidebar .nav__item')) {
                setOpen(false);
            }
        });

        on(document, 'keydown', function (event) {
            if (event.key === 'Escape' && app.classList.contains('is-open')) {
                setOpen(false);
            }
        });

        // Growing past the breakpoint must not leave the page in drawer mode.
        on(window, 'resize', debounce(function () {
            if (window.innerWidth > 900) {
                setOpen(false);
            }
        }, 150));
    }


    /* =========================================================================
       05. MODAL
       `[data-modal-open="#id"]` opens, `[data-close="modal"]`, a click on the
       backdrop and Escape all close it.
       ====================================================================== */

    function openModal(modal) {
        if (!modal) {
            return;
        }

        modal.hidden = false;
        modal.classList.add('is-open');

        const focusable = $('[autofocus], input, select, textarea, button', modal);

        if (focusable && typeof focusable.focus === 'function') {
            focusable.focus();
        }
    }

    function closeModal(modal) {
        if (!modal) {
            return;
        }

        modal.hidden = true;
        modal.classList.remove('is-open');
    }

    function initModals() {
        on(document, 'click', function (event) {
            const opener = closest(event.target, '[data-modal-open]');

            if (opener) {
                event.preventDefault();
                openModal($(opener.getAttribute('data-modal-open')));

                return;
            }

            const closer = closest(event.target, '[data-close="modal"]');

            if (closer) {
                event.preventDefault();
                closeModal(closest(closer, '.modal'));

                return;
            }

            // A click on the dialog's own padding (the backdrop) closes it too.
            if (event.target && event.target.classList && event.target.classList.contains('modal')) {
                closeModal(event.target);
            }
        });

        on(document, 'keydown', function (event) {
            if (event.key === 'Escape') {
                $$('.modal.is-open').forEach(closeModal);
            }
        });
    }


    /* =========================================================================
       06. CONFIRM DIALOGS
       `data-confirm="Message"` on a link, a button or a whole form.
       ====================================================================== */

    function initConfirm() {
        // Links and buttons.
        on(document, 'click', function (event) {
            const trigger = closest(event.target, '[data-confirm]');

            if (!trigger || trigger.tagName === 'FORM') {
                return;
            }

            // A submit button inside a form that carries its own data-confirm
            // is handled by the submit listener below — never ask twice.
            if (trigger.type === 'submit' && closest(trigger, 'form[data-confirm]')) {
                return;
            }

            const message = trigger.getAttribute('data-confirm');

            if (message && !window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            }
        });

        // Forms: catch the submit itself, so keyboard submissions are covered.
        on(document, 'submit', function (event) {
            const form = event.target;

            if (!form || typeof form.matches !== 'function' || !form.matches('form[data-confirm]')) {
                return;
            }

            const message = form.getAttribute('data-confirm');

            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        }, true);
    }


    /* =========================================================================
       07. BULK SELECTION
       -------------------------------------------------------------------------
       Markup contract:
         <form data-bulk-form method="post" action="index.php?p=…&a=bulk">
           <input type="hidden" name="bulk" data-bulk-op value="">
           <span data-bulk-ids hidden></span>
           …<input type="checkbox" data-check-all>…
           …<input type="checkbox" data-check-item name="ids[]" value="7">…
           <div class="bulk-bar" data-bulk-bar>
             <strong data-bulk-count>0</strong>
             <button type="submit" data-bulk-action="approve">…</button>
           </div>
         </form>
       ====================================================================== */

    function initBulk() {
        // The checkboxes are looked up document-wide on purpose: a table does
        // not have to live inside the form that carries the bulk action.
        const items = $$('[data-check-item]');

        if (items.length === 0) {
            return;
        }

        const master = $('[data-check-all]');
        const bar = $('[data-bulk-bar]');
        const counter = $('[data-bulk-count]');
        const form = $('[data-bulk-form]');
        let lastIndex = -1;

        function selected() {
            return items.filter(function (item) {
                return item.checked;
            });
        }

        /** Reflect the current selection in the bar, the master box and the rows. */
        function sync() {
            const checked = selected();

            items.forEach(function (item) {
                const row = closest(item, 'tr');

                if (row) {
                    row.classList.toggle('is-selected', item.checked);
                }
            });

            if (master) {
                master.checked = checked.length > 0 && checked.length === items.length;
                master.indeterminate = checked.length > 0 && checked.length < items.length;
            }

            if (counter) {
                counter.textContent = String(checked.length);
            }

            if (bar) {
                bar.classList.toggle('is-visible', checked.length > 0);
                bar.setAttribute('aria-hidden', checked.length > 0 ? 'false' : 'true');
            }
        }

        if (master) {
            on(master, 'change', function () {
                items.forEach(function (item) {
                    if (!item.disabled) {
                        item.checked = master.checked;
                    }
                });

                sync();
            });
        }

        items.forEach(function (item, index) {
            on(item, 'click', function (event) {
                // Shift-click selects (or clears) the whole range in between.
                if (event.shiftKey && lastIndex > -1) {
                    const from = Math.min(lastIndex, index);
                    const to = Math.max(lastIndex, index);

                    for (let i = from; i <= to; i++) {
                        if (!items[i].disabled) {
                            items[i].checked = item.checked;
                        }
                    }
                }

                lastIndex = index;
                sync();
            });

            on(item, 'change', sync);
        });

        // The action buttons write the operation into the hidden field, mirror
        // the checked ids into the form and let the browser submit normally.
        $$('[data-bulk-action]').forEach(function (button) {
            on(button, 'click', function (event) {
                const checked = selected();

                if (checked.length === 0) {
                    event.preventDefault();
                    toast(t('panel.bulk_none_selected', 'Nothing selected.'), 'warning');

                    return;
                }

                const operation = button.getAttribute('data-bulk-action') || '';
                const target = form || closest(button, 'form');

                if (!target) {
                    return;
                }

                const field = $('[data-bulk-op]', target);

                if (field) {
                    field.value = operation;
                }

                writeIds(target, checked);
            });
        });

        /**
         * Publish the selected ids to the form.
         *
         * `[data-bulk-ids]` may be an <input>, which then receives a comma
         * separated list, or any container element, which receives one hidden
         * `ids[]` input per selected row. Checkboxes that already live inside
         * the form submit themselves, so they are skipped.
         */
        function writeIds(target, checked) {
            const holder = $('[data-bulk-ids]', target) || $('[data-bulk-ids]');

            if (!holder) {
                return;
            }

            const ids = checked.map(function (item) {
                return item.value;
            });

            if (holder.tagName === 'INPUT') {
                holder.value = ids.join(',');

                return;
            }

            holder.innerHTML = '';

            checked.forEach(function (item) {
                if (item.name !== '' && target.contains(item)) {
                    return; // the checkbox itself is already submitted by the form
                }

                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'ids[]';
                hidden.value = item.value;
                holder.appendChild(hidden);
            });
        }

        sync();
    }


    /* =========================================================================
       08. BROADCAST WIZARD
       -------------------------------------------------------------------------
       Markup contract — a single element carries the endpoints:
         <div data-broadcast
              data-count-url="index.php?p=broadcast&a=count"
              data-start-url="index.php?p=broadcast&a=start"
              data-run-url="index.php?p=broadcast&a=run"
              data-cancel-url="index.php?p=broadcast&a=cancel"
              data-resume-url="index.php?p=broadcast&a=resume"
              data-batch="20" data-id="0">
       Inside it: [data-broadcast-form], [data-broadcast-text] (data-max="4000"),
       [data-char-count], [data-recipients], [data-broadcast-start],
       [data-broadcast-pause], [data-broadcast-resume], .progress__bar,
       [data-stat-total|sent|failed|remaining|percent], [data-broadcast-report].
       ====================================================================== */

    function initBroadcast() {
        const root = $('[data-broadcast]');

        if (!root) {
            return;
        }

        const form = $('[data-broadcast-form]', root) || closest(root, 'form');
        const textarea = $('[data-broadcast-text]', root);
        const counter = $('[data-char-count]', root);
        const recipients = $('[data-recipients]', root);
        const startButton = $('[data-broadcast-start]', root);
        const pauseButton = $('[data-broadcast-pause]', root);
        const resumeButton = $('[data-broadcast-resume]', root);
        const bar = $('.progress__bar', root) || $('[data-progress-bar]', root);
        const progress = $('.progress', root);
        const report = $('[data-broadcast-report]', root);

        const endpoints = {
            count: data(root, 'count-url', 'index.php?p=broadcast&a=count'),
            start: data(root, 'start-url', 'index.php?p=broadcast&a=start'),
            run: data(root, 'run-url', 'index.php?p=broadcast&a=run'),
            cancel: data(root, 'cancel-url', 'index.php?p=broadcast&a=cancel'),
            resume: data(root, 'resume-url', 'index.php?p=broadcast&a=resume')
        };

        const batch = dataInt(root, 'batch', 20);
        const maxChars = textarea ? dataInt(textarea, 'max', 4000) : 4000;

        const initialStatus = data(root, 'status', '');

        let campaignId = dataInt(root, 'id', 0);
        let running = false;
        let paused = campaignId > 0 && initialStatus === 'paused';
        let audience = 0;

        /* ---- character counter ------------------------------------------ */

        function updateCounter() {
            if (!counter || !textarea) {
                return;
            }

            const used = textarea.value.length;

            counter.textContent = t(
                'panel.broadcast_chars',
                data(counter, 'template', ':count / :max'),
                { count: number(used), max: number(maxChars) }
            );

            counter.classList.toggle('is-over', used > maxChars);
        }

        // The preview mirrors the composer as it is typed. textContent, never
        // innerHTML: the raw markup is what the operator needs to proof-read,
        // and it keeps untrusted text out of the DOM parser.
        const preview = $('[data-broadcast-preview]', root);

        function updatePreview() {
            if (preview && textarea) {
                preview.textContent = textarea.value;
            }
        }

        if (textarea) {
            on(textarea, 'input', function () {
                updateCounter();
                updatePreview();
            });
            updateCounter();
            updatePreview();
        }

        /* ---- live recipient count --------------------------------------- */

        /** The audience selectors of the composer, as a plain object. */
        function filters() {
            const values = { audience: 'all', status: '', district: '', direction: '' };

            if (!form) {
                return values;
            }

            Object.keys(values).forEach(function (name) {
                const field = form.querySelector('[name="' + name + '"]:checked')
                    || form.querySelector('select[name="' + name + '"], input[name="' + name + '"]');

                if (field && typeof field.value === 'string') {
                    values[name] = field.value;
                }
            });

            return values;
        }

        /** Ask the server how many people the current filter reaches. */
        function fetchAudience() {
            postJson(endpoints.count, filters()).then(function (payload) {
                audience = parseInt(payload.count, 10) || 0;

                if (recipients) {
                    recipients.textContent = payload.label
                        || t('panel.broadcast_recipients', 'Recipients: :count', { count: number(audience) });
                }
            }).catch(function (error) {
                if (recipients) {
                    recipients.textContent = error.message;
                }
            });
        }

        // Typing or switching audience re-counts, but not on every keystroke.
        const refreshAudience = debounce(fetchAudience, 300);

        if (form) {
            $$('select[name], input[name="audience"]', form).forEach(function (field) {
                on(field, 'change', refreshAudience);
            });

            fetchAudience();
        }

        /* ---- progress rendering ------------------------------------------ */

        function paint(state) {
            const total = parseInt(state.total, 10) || 0;
            const sent = parseInt(state.sent, 10) || 0;
            const failed = parseInt(state.failed, 10) || 0;
            const remaining = parseInt(state.remaining, 10) || 0;
            const percent = Math.max(0, Math.min(100, parseFloat(state.percent) || 0));

            if (bar) {
                bar.style.width = percent.toFixed(1) + '%';
                bar.setAttribute('aria-valuenow', String(Math.round(percent)));
            }

            if (progress) {
                progress.setAttribute('role', 'progressbar');
                progress.setAttribute('aria-valuemin', '0');
                progress.setAttribute('aria-valuemax', '100');
                progress.classList.toggle('is-running', running && !paused);
            }

            const stats = {
                total: number(total),
                sent: number(sent),
                failed: number(failed),
                remaining: number(remaining),
                percent: percent.toFixed(1) + '%'
            };

            Object.keys(stats).forEach(function (name) {
                const node = $('[data-stat-' + name + ']', root);

                if (node) {
                    node.textContent = stats[name];
                }
            });
        }

        function finish(state) {
            running = false;
            paused = false;
            toggleButtons();

            if (report) {
                report.hidden = false;
                report.textContent = t(
                    'panel.broadcast_report',
                    'Done: :sent delivered, :failed failed, :total total',
                    {
                        sent: number(state.sent || 0),
                        failed: number(state.failed || 0),
                        total: number(state.total || 0)
                    }
                );
            }

            toast(t('panel.broadcast_done', 'Finished'), 'success');
        }

        function toggleButtons() {
            if (startButton) {
                startButton.disabled = running;
            }

            if (pauseButton) {
                pauseButton.hidden = !(running && !paused);
            }

            if (resumeButton) {
                resumeButton.hidden = !(paused && campaignId > 0);
            }
        }

        /* ---- the batched sender ------------------------------------------ */

        async function loop() {
            while (running && !paused) {
                let state;

                try {
                    state = await postJson(endpoints.run, { id: campaignId, batch: batch });
                } catch (error) {
                    running = false;
                    toggleButtons();
                    toast(error.message, 'error');

                    return;
                }

                paint(state);

                if (state.done === true || state.remaining <= 0) {
                    finish(state);

                    return;
                }

                if (state.status === 'paused') {
                    paused = true;
                    toggleButtons();

                    return;
                }

                // Breathe between batches: the API is rate limited anyway.
                await new Promise(function (resolve) {
                    window.setTimeout(resolve, 350);
                });
            }
        }

        if (startButton) {
            on(startButton, 'click', async function (event) {
                event.preventDefault();

                if (running) {
                    return;
                }

                const text = textarea ? textarea.value.trim() : '';

                if (text === '') {
                    toast(t('panel.broadcast_empty_text', 'Please write a message.'), 'error');

                    return;
                }

                const question = t('panel.broadcast_confirm_start', 'Send to :count recipients?', {
                    count: number(audience)
                });

                if (!window.confirm(question)) {
                    return;
                }

                startButton.disabled = true;

                try {
                    const fields = filters();
                    fields.text = text;

                    const state = await postJson(endpoints.start, fields);

                    campaignId = parseInt(state.id, 10) || 0;
                    running = true;
                    paused = false;

                    if (report) {
                        report.hidden = true;
                    }

                    toggleButtons();
                    paint(state);
                    loop();
                } catch (error) {
                    startButton.disabled = false;
                    toast(error.message, 'error');
                }
            });
        }

        if (pauseButton) {
            on(pauseButton, 'click', async function (event) {
                event.preventDefault();
                paused = true;
                toggleButtons();

                try {
                    paint(await postJson(endpoints.cancel, { id: campaignId }));
                    toast(t('panel.broadcast_paused', 'Paused'), 'info');
                } catch (error) {
                    toast(error.message, 'error');
                }
            });
        }

        if (resumeButton) {
            on(resumeButton, 'click', async function (event) {
                event.preventDefault();

                if (campaignId <= 0) {
                    return;
                }

                try {
                    paint(await postJson(endpoints.resume, { id: campaignId }));
                    paused = false;
                    running = true;
                    toggleButtons();
                    loop();
                } catch (error) {
                    toast(error.message, 'error');
                }
            });
        }

        toggleButtons();

        // Paint whatever the server already rendered, so a paused, finished or
        // cancelled campaign shows its real progress instead of an empty bar.
        if (campaignId > 0) {
            paused = initialStatus === 'paused';
            paint({
                total: dataInt(root, 'total', 0),
                sent: dataInt(root, 'sent', 0),
                failed: dataInt(root, 'failed', 0),
                remaining: dataInt(root, 'remaining', 0),
                percent: parseFloat(data(root, 'percent', '0')) || 0
            });
        }

        // A campaign that was still running when the page loaded keeps going.
        if (campaignId > 0 && initialStatus === 'running') {
            running = true;
            paused = false;
            toggleButtons();
            loop();
        }
    }


    /* =========================================================================
       09. COPY TO CLIPBOARD
       `data-copy="text"` or `data-copy` + `data-copy-target="#selector"`.
       ====================================================================== */

    function copyText(text) {
        if (!text) {
            return Promise.resolve(false);
        }

        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () {
                return true;
            }).catch(function () {
                return legacyCopy(text);
            });
        }

        return Promise.resolve(legacyCopy(text));
    }

    /** Fallback for plain HTTP deployments, where the async API is unavailable. */
    function legacyCopy(text) {
        const helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', 'readonly');
        helper.style.position = 'fixed';
        helper.style.top = '-1000px';
        helper.style.opacity = '0';
        document.body.appendChild(helper);
        helper.select();

        let done = false;

        try {
            done = document.execCommand('copy');
        } catch (error) {
            done = false;
        }

        document.body.removeChild(helper);

        return done;
    }

    function initCopy() {
        on(document, 'click', function (event) {
            const trigger = closest(event.target, '[data-copy]');

            if (!trigger) {
                return;
            }

            event.preventDefault();

            let text = trigger.getAttribute('data-copy') || '';

            if (text === '') {
                const source = $(trigger.getAttribute('data-copy-target') || '');

                if (source) {
                    text = typeof source.value === 'string' && source.value !== ''
                        ? source.value
                        : (source.textContent || '');
                }
            }

            copyText(text.trim()).then(function (ok) {
                toast(
                    ok ? t('panel.copied', 'Copied') : t('panel.flash_error', 'Could not copy'),
                    ok ? 'success' : 'error'
                );
            });
        });
    }


    /* =========================================================================
       10. FILTER AUTO-SUBMIT AND LIVE SEARCH
       ====================================================================== */

    /** Send the form back to page 1 — a new filter invalidates the old offset. */
    function resetPage(form) {
        const page = form.querySelector('[name="page"]');

        if (page) {
            page.value = '1';
        }
    }

    function submitForm(form) {
        if (!form) {
            return;
        }

        resetPage(form);

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function initAutoSubmit() {
        $$('[data-auto-submit]').forEach(function (field) {
            const form = field.form || closest(field, 'form');

            if (!form) {
                return;
            }

            on(field, 'change', function () {
                submitForm(form);
            });
        });
    }

    function initLiveSearch() {
        $$('[data-live-search]').forEach(function (field) {
            const form = field.form || closest(field, 'form');

            if (!form) {
                return;
            }

            const minimum = dataInt(field, 'min-length', 2);
            const delay = dataInt(field, 'delay', 450);
            let previous = field.value;

            const run = debounce(function () {
                const value = field.value.trim();

                if (value === previous) {
                    return;
                }

                if (value !== '' && value.length < minimum) {
                    return;
                }

                previous = value;
                submitForm(form);
            }, delay);

            on(field, 'input', run);

            // Enter submits immediately instead of waiting for the timer.
            on(field, 'keydown', function (event) {
                if (event.key === 'Enter') {
                    previous = field.value.trim();
                }
            });
        });
    }


    /* =========================================================================
       11. QUERY-STRING SYNCHRONISATION
       -------------------------------------------------------------------------
       `<a data-sync-query data-sync-drop="page" href="index.php?p=…&sort=id">`
       keeps the filters of the current URL while overriding only the parameters
       the link itself carries — so sorting a filtered list stays filtered.
       ====================================================================== */

    function initQuerySync() {
        const links = $$('a[data-sync-query]');

        if (links.length === 0) {
            return;
        }

        const current = new URLSearchParams(window.location.search);

        links.forEach(function (link) {
            const href = link.getAttribute('href') || '';
            const mark = href.indexOf('?');
            const base = mark === -1 ? href : href.slice(0, mark);
            const own = new URLSearchParams(mark === -1 ? '' : href.slice(mark + 1));
            const merged = new URLSearchParams();

            current.forEach(function (value, key) {
                merged.set(key, value);
            });

            own.forEach(function (value, key) {
                merged.set(key, value);
            });

            data(link, 'sync-drop', '').split(/[\s,]+/).forEach(function (key) {
                if (key !== '') {
                    merged.delete(key);
                }
            });

            const query = merged.toString();

            link.setAttribute('href', (base || 'index.php') + (query === '' ? '' : '?' + query));
        });
    }


    /* =========================================================================
       12. UNSAVED-CHANGES GUARD
       `<form data-guard>` warns before the tab is closed with a dirty form.
       ====================================================================== */

    function initGuard() {
        const forms = $$('form[data-guard]');

        if (forms.length === 0) {
            return;
        }

        let dirty = false;

        forms.forEach(function (form) {
            on(form, 'input', function () {
                dirty = true;
            });

            on(form, 'submit', function () {
                dirty = false;
            });
        });

        on(window, 'beforeunload', function (event) {
            if (!dirty) {
                return undefined;
            }

            event.preventDefault();
            event.returnValue = t('panel.confirm_leave', '');

            return event.returnValue;
        });
    }


    /* =========================================================================
       BOOT
       Each module is isolated: a failure in one must not silence the others.
       ====================================================================== */

    ready(function () {
        // Belt and braces: if the file is ever included twice, only boot once —
        // duplicated delegated listeners would confirm dialogs twice and toggle
        // the drawer straight back closed.
        if (window.aiTalentsPanelBooted === true) {
            return;
        }

        window.aiTalentsPanelBooted = true;

        const modules = [
            initToasts,
            initSidebar,
            initModals,
            initConfirm,
            initBulk,
            initBroadcast,
            initCopy,
            initAutoSubmit,
            initLiveSearch,
            initQuerySync,
            initGuard
        ];

        modules.forEach(function (module) {
            try {
                module();
            } catch (error) {
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error('[panel] module failed:', error);
                }
            }
        });
    });
}());
