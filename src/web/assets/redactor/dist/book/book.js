/**
 * Book — the Redactor plugin.
 *
 * Inserts exactly the same block the CKEditor plugin does:
 *
 *     <div class="book-document" data-book-handle="annual-report">{book:annual-report:render}</div>
 *
 * so content can move between a Redactor field and a CKEditor field with nothing to migrate, and
 * either way it is Craft's own reference-tag parsing that renders the document.
 */
(function ($R) {
    'use strict';

    $R.add('plugin', 'book', {
        translations: {
            en: {
                book: {
                    title: 'Insert a document',
                },
            },
        },

        init: function (app) {
            this.app = app;
            this.toolbar = app.toolbar;
            this.insertion = app.insertion;
            this.lang = app.lang;
        },

        start: function () {
            var button = this.toolbar.addButton('book', {
                title: this.lang.get('book.title'),
                api: 'plugin.book.open',
            });

            button.setIcon('<i class="re-icon-file"></i>');
        },

        open: function () {
            var insertion = this.insertion;

            window.BookPicker.open(function (document_) {
                insertion.insertHtml(window.BookPicker.documentHtml(document_));
            });
        },
    });
})(Redactor);
