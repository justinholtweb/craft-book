/**
 * Book — the front-end runtime.
 *
 * Four jobs, and a document that needs none of them works perfectly without this file loading at
 * all:
 *
 *   1. click-to-load, which is the only honest way to embed a viewer that phones a third party
 *   2. a load timeout, because a viewer that cannot reach the file answers 200 with an apology
 *      page and a blocked frame says nothing at all — either way the reader sees a blank box
 *   3. fullscreen
 *   4. print
 *
 * No dependencies, no build step, and everything is idempotent: `Book.scan()` can be called
 * again after new markup arrives without doubling anything up.
 */
(function () {
    'use strict';

    var READY = 'data-book-ready';

    function parse(element) {
        try {
            return JSON.parse(element.getAttribute('data-book') || '{}');
        } catch (error) {
            return {};
        }
    }

    function remembered(key) {
        if (!key) return false;

        try {
            return window.localStorage.getItem(key) === '1';
        } catch (error) {
            // Private mode, or storage disabled. Asking every time is the safe failure.
            return false;
        }
    }

    function remember(key) {
        if (!key) return;

        try {
            window.localStorage.setItem(key, '1');
        } catch (error) {
            /* Nothing to do — the reader is asked again next time. */
        }
    }

    /* Click-to-load ---------------------------------------------------------- */

    function load(root) {
        var template = root.querySelector('[data-book-template]');
        var consent = root.querySelector('[data-book-consent]');

        if (!template) return null;

        var frame = template.content.firstElementChild.cloneNode(true);

        template.parentNode.insertBefore(frame, template);
        template.remove();

        if (consent) consent.remove();

        watch(root, frame, parse(root));

        return frame;
    }

    function setUpConsent(root, config) {
        var button = root.querySelector('[data-book-consent]');

        if (!button) return;

        var key = config.consent && config.consent.remember ? config.consent.key : null;

        if (remembered(key)) {
            load(root);
            return;
        }

        button.addEventListener('click', function () {
            if (key) remember(key);
            var frame = load(root);
            if (frame) frame.focus();
        });
    }

    /* Load timeout ----------------------------------------------------------- */

    function watch(root, frame, config) {
        var loader = root.querySelector('[data-book-loader]');
        var fallback = root.querySelector('[data-book-fallback]');
        var timer = null;

        function settle() {
            if (timer) window.clearTimeout(timer);
            if (loader) loader.remove();
        }

        frame.addEventListener('load', settle);

        // A cross-origin frame will not tell us whether it rendered a document or an apology, so
        // this is a deadline, not a health check: past it, show the file itself.
        if (config.timeout > 0 && fallback) {
            timer = window.setTimeout(function () {
                if (loader) loader.remove();
                fallback.hidden = false;
            }, config.timeout);
        }

        // A frame that finished loading before this script ran will never fire `load` again.
        // Only a same-origin frame can be asked; a cross-origin one throws, and its timeout is
        // still ticking, which is the outcome we want anyway.
        try {
            if (frame.contentDocument && frame.contentDocument.readyState === 'complete') settle();
        } catch (error) {
            /* Cross-origin. The `load` listener above is the only signal available. */
        }
    }

    /* Fullscreen ------------------------------------------------------------- */

    function setUpFullscreen(root) {
        var button = root.querySelector('[data-book-fullscreen]');
        var stage = root.querySelector('.book-stage');

        if (!button || !stage || !stage.requestFullscreen) {
            if (button) button.remove();
            return;
        }

        var enterLabel = button.textContent;
        var exitLabel = button.getAttribute('data-label-exit') || enterLabel;

        button.addEventListener('click', function () {
            if (document.fullscreenElement === stage) {
                document.exitFullscreen();
            } else {
                stage.requestFullscreen().catch(function () {
                    /* Refused — usually a permissions policy on a containing frame. */
                });
            }
        });

        document.addEventListener('fullscreenchange', function () {
            button.textContent = document.fullscreenElement === stage ? exitLabel : enterLabel;
        });
    }

    /* Print ------------------------------------------------------------------ */

    function setUpPrint(root) {
        var button = root.querySelector('[data-book-print]');

        if (!button) return;

        button.addEventListener('click', function () {
            var frame = root.querySelector('[data-book-frame]');

            // Printing a same-origin frame prints the document. A cross-origin one cannot be
            // reached at all, so the honest fallback is to print the page it is on.
            if (frame) {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                    return;
                } catch (error) {
                    /* Cross-origin. Fall through. */
                }
            }

            window.print();
        });
    }

    /* Wiring ----------------------------------------------------------------- */

    function enhance(root) {
        if (root.hasAttribute(READY)) return;

        root.setAttribute(READY, '');

        var config = parse(root);

        setUpFullscreen(root);
        setUpPrint(root);

        if (config.loading === 'click') {
            setUpConsent(root, config);
            return;
        }

        var frame = root.querySelector('[data-book-frame]');

        if (frame) watch(root, frame, config);
    }

    var Book = {
        scan: function (context) {
            var scope = context || document;
            var roots = scope.querySelectorAll('.book[data-book]');

            for (var i = 0; i < roots.length; i++) {
                enhance(roots[i]);
            }
        },

        /** Load a click-to-load document from script, for a “show everything” control. */
        load: function (root) {
            return load(root);
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            Book.scan();
        });
    } else {
        Book.scan();
    }

    window.Book = Book;
})();
