/**
 * Book — the document picker.
 *
 * `BookPicker.open(function (document) { … })` puts a modal on screen and hands back the chosen
 * document. Both the CKEditor plugin and the Redactor plugin call this, so the choosing
 * experience is identical wherever an author inserts a document.
 *
 * Three ways in, because authors arrive with one of three things:
 *
 *   - a file already in the site, chosen from Craft's own asset selector
 *   - a URL they just copied
 *   - a memory of one they made earlier, which is in the list
 *
 * All three come out the same way: a reference tag. There is one rendering path in Book and this
 * does not add a second.
 */
(function () {
    'use strict';

    function el(tag, attrs, children) {
        var node = document.createElement(tag);

        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') {
                node.textContent = attrs[key];
            } else if (key === 'class') {
                node.className = attrs[key];
            } else if (attrs[key] !== null && attrs[key] !== false && attrs[key] !== undefined) {
                node.setAttribute(key, attrs[key]);
            }
        });

        (children || []).forEach(function (child) {
            if (child) node.appendChild(child);
        });

        return node;
    }

    function t(message, params) {
        return Craft.t('book', message, params || {});
    }

    var BookPicker = {
        /**
         * The block both editors insert. Identical markup either way, so content can move
         * between a Redactor field and a CKEditor field with nothing to migrate.
         */
        documentHtml: function (document_) {
            var div = el('div', {
                class: 'book-document',
                'data-book-handle': document_.handle,
                'data-book-label': document_.title || document_.handle,
            });

            div.textContent = '{book:' + document_.handle + ':render}';

            return div.outerHTML;
        },

        open: function (onSelect) {
            var body = el('div', { class: 'book-picker' });
            var modal = null;

            var status = el('p', { class: 'book-picker__status' });
            var results = el('div', { class: 'book-picker__results' });

            function fail(message) {
                status.textContent = message;
                status.className = 'book-picker__status book-picker__status--error';
            }

            function busy(message) {
                status.textContent = message;
                status.className = 'book-picker__status';
            }

            function choose(document_) {
                if (modal) modal.hide();
                onSelect(document_);
            }

            function create(data, failure) {
                busy(t('Adding it to the library…'));

                Craft.sendActionRequest('POST', 'book/documents/quick-create', { data: data })
                    .then(function (response) {
                        choose(response.data);
                    })
                    .catch(function (error) {
                        fail(
                            (error.response && error.response.data && error.response.data.message)
                            || failure
                        );
                    });
            }

            /* A file in this site ------------------------------------------- */

            var fileButton = el('button', { type: 'button', class: 'btn add icon', text: t('Choose a file') });

            fileButton.addEventListener('click', function () {
                Craft.createElementSelectorModal('craft\\elements\\Asset', {
                    storageKey: 'BookPicker.asset',
                    multiSelect: false,
                    onSelect: function (elements) {
                        if (!elements.length) return;

                        create({ assetId: elements[0].id }, t('That file could not be added.'));
                    },
                });
            });

            /* A URL ---------------------------------------------------------- */

            var urlInput = el('input', {
                type: 'url',
                class: 'text fullwidth',
                placeholder: 'https://…/report.pdf',
                'aria-label': t('Paste a URL'),
            });

            var urlButton = el('button', { type: 'button', class: 'btn submit', text: t('Add') });

            function addUrl() {
                var url = urlInput.value.trim();

                if (!url) {
                    urlInput.focus();
                    return;
                }

                urlButton.classList.add('loading');

                Craft.sendActionRequest('POST', 'book/documents/quick-create', { data: { url: url } })
                    .then(function (response) {
                        urlButton.classList.remove('loading');
                        choose(response.data);
                    })
                    .catch(function (error) {
                        urlButton.classList.remove('loading');
                        fail(
                            (error.response && error.response.data && error.response.data.message)
                            || t('That URL could not be added.')
                        );
                    });
            }

            urlButton.addEventListener('click', addUrl);
            urlInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    addUrl();
                }
            });

            /* The library ---------------------------------------------------- */

            function renderList(documents) {
                results.innerHTML = '';

                if (!documents.length) {
                    results.appendChild(el('p', {
                        class: 'book-picker__empty',
                        text: t('No documents in the library yet. Choose a file or paste a URL above.'),
                    }));

                    return;
                }

                documents.forEach(function (document_) {
                    var row = el('button', { type: 'button', class: 'book-picker__row' }, [
                        el('span', { class: 'book-picker__title', text: document_.title }),
                        el('span', { class: 'book-picker__meta', text: document_.format }),
                        el('code', { class: 'book-picker__handle', text: document_.handle }),
                    ]);

                    if (!document_.enabled) {
                        row.classList.add('book-picker__row--disabled');
                        row.appendChild(el('span', { class: 'book-picker__badge', text: t('Disabled') }));
                    }

                    row.addEventListener('click', function () {
                        choose(document_);
                    });

                    results.appendChild(row);
                });
            }

            function load(search) {
                busy(t('Loading…'));

                Craft.sendActionRequest('GET', 'book/documents/list', { params: { search: search || '' } })
                    .then(function (response) {
                        status.textContent = '';
                        renderList(response.data.documents || []);
                    })
                    .catch(function () {
                        fail(t('Could not load the document library.'));
                    });
            }

            var search = el('input', { type: 'text', class: 'text fullwidth', placeholder: t('Search documents') });
            var searchTimer = null;

            search.addEventListener('input', function () {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function () {
                    load(search.value);
                }, 250);
            });

            body.appendChild(el('div', { class: 'book-picker__section' }, [
                el('h2', { text: t('Add a document') }),
                el('div', { class: 'book-picker__add' }, [fileButton]),
                el('div', { class: 'book-picker__url' }, [urlInput, urlButton]),
                el('p', {
                    class: 'book-picker__hint',
                    text: t('PDFs, Word, Excel, PowerPoint, OpenDocument, CSV, Markdown, images and more. Book picks a viewer that works for the format.'),
                }),
            ]));

            body.appendChild(el('div', { class: 'book-picker__section' }, [
                el('h2', { text: t('Or choose one you have already added') }),
                search,
                results,
            ]));

            body.appendChild(status);

            modal = new Garnish.Modal(el('div', { class: 'modal elementselectormodal book-picker-modal' }, [
                el('div', { class: 'body' }, [body]),
                el('div', { class: 'footer' }, [
                    el('div', { class: 'buttons right' }, [
                        (function () {
                            var cancel = el('button', { type: 'button', class: 'btn', text: t('Cancel') });
                            cancel.addEventListener('click', function () {
                                modal.hide();
                            });
                            return cancel;
                        })(),
                    ]),
                ]),
            ]), {
                onHide: function () {
                    window.setTimeout(function () {
                        modal.$container.remove();
                    }, 100);
                },
            });

            load('');
        },
    };

    window.BookPicker = BookPicker;
})();
