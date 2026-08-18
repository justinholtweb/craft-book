/**
 * Book — the control panel editor.
 *
 * One job: keep the “what will happen” panel honest while the author is still looking at the
 * form. Which viewer will run, why it might not be the one they picked, and what that costs.
 *
 * The same script serves the document edit screen and the Document field, because the question
 * is the same in both and a second implementation would drift from the first.
 */
(function () {
    'use strict';

    function debounce(fn, wait) {
        var timer = null;

        return function () {
            var args = arguments;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fn.apply(null, args);
            }, wait);
        };
    }

    function collectOptions(root) {
        var options = {};
        var inputs = root.querySelectorAll('[name*="[options]["], [name^="options["]');

        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            var match = input.name.match(/\[options\]\[([^\]]+)\]|^options\[([^\]]+)\]/);

            if (!match) continue;

            var key = match[1] || match[2];

            if (input.type === 'checkbox' || input.classList.contains('lightswitch-input')) {
                options[key] = input.checked ? '1' : '';
            } else {
                // A lightswitch posts a hidden `0` before its real input, and the real one wins
                // because it comes second — same order the server sees.
                options[key] = input.value;
            }
        }

        return options;
    }

    function fileParams(root) {
        var url = root.querySelector('input[type="url"]');
        var selected = root.querySelectorAll('.elementselect .element');
        var assetId = null;

        if (selected.length) {
            assetId = selected[0].getAttribute('data-id');
        }

        return {
            assetId: assetId,
            url: assetId ? '' : (url ? url.value.trim() : ''),
        };
    }

    function renderVerdict(panel, data) {
        panel.classList.remove('hidden');

        var format = panel.querySelector('.book-verdict__format');
        var viewer = panel.querySelector('.book-verdict__viewer');
        var note = panel.querySelector('.book-verdict__note');
        var warnings = panel.querySelector('.book-verdict__warnings');

        if (format) format.textContent = data.format.name;
        if (viewer) viewer.textContent = data.viewer.name || data.viewer.handle;
        if (note) note.textContent = data.viewer.note || '';

        if (warnings) {
            warnings.innerHTML = '';

            (data.warnings || []).forEach(function (warning) {
                var p = document.createElement('p');
                p.className = 'warning';
                p.textContent = warning;
                warnings.appendChild(p);
            });
        }

        panel.classList.toggle('book-verdict--downgraded', !!data.downgraded);
    }

    function setUp(root) {
        var url = root.getAttribute('data-resolve-url');
        var panel = root.querySelector('#book-verdict') || root.querySelector('[data-book-verdict]');

        if (!url || !panel) return;

        var resolve = debounce(function () {
            var file = fileParams(root);

            if (!file.assetId && !file.url) {
                panel.classList.add('hidden');
                return;
            }

            Craft.sendActionRequest('POST', url, {
                data: {
                    assetId: file.assetId,
                    url: file.url,
                    options: collectOptions(root),
                },
            })
                .then(function (response) {
                    if (response.data && response.data.ok) renderVerdict(panel, response.data);
                })
                .catch(function () {
                    // A failed lookup should never block the form. The server will still be
                    // asked when it saves, and that answer is the one that counts.
                    panel.classList.add('hidden');
                });
        }, 300);

        root.addEventListener('change', resolve);
        root.addEventListener('input', function (event) {
            if (event.target && event.target.type === 'url') resolve();
        });

        // Craft's element select does not fire `change` on the container, so the only reliable
        // signal is the DOM changing underneath it.
        var selects = root.querySelectorAll('.elementselect');

        for (var i = 0; i < selects.length; i++) {
            new window.MutationObserver(resolve).observe(selects[i], { childList: true, subtree: true });
        }

        resolve();
    }

    function init() {
        var roots = document.querySelectorAll('#book-edit, .book-field');

        for (var i = 0; i < roots.length; i++) {
            setUp(roots[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Fields arrive after the page does — inside a Matrix block, or a slideout.
    if (window.Garnish) {
        Garnish.$doc.on('ajaxComplete', function () {
            window.setTimeout(init, 50);
        });
    }
})();
