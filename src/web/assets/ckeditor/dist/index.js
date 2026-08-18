/**
 * Book — the CKEditor plugin.
 *
 * What it stores is a plain block:
 *
 *     <div class="book-document" data-book-handle="annual-report">{book:annual-report:render}</div>
 *
 * That is the whole integration. The reference tag inside is what renders the document, and
 * Craft parses reference tags over every rich-text value on its own — so this file only helps an
 * author write that tag, and shows something better than raw braces while they edit. If the
 * plugin is missing, or the content moves to a Redactor field, or the editor is swapped out
 * entirely, the document still renders.
 *
 * It also handles the thing authors actually do, which is paste a link to a file: a URL ending
 * in `.pdf` dropped on its own line becomes a document without anyone opening a dialog.
 */

import { ButtonView, Command, Plugin, Widget, toWidget } from 'ckeditor5';

const ICON = `<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
    <path d="M4 2.5h7.2L16 7.2V17a.5.5 0 0 1-.5.5h-11A.5.5 0 0 1 4 17V3a.5.5 0 0 1 .5-.5zm6.8 1.6v3.1h3.1l-3.1-3.1zM6.5 10h7v1.3h-7V10zm0 3h7v1.3h-7V13zm0-6h3v1.3h-3V7z"/>
</svg>`;

/**
 * A URL that ends in something Book would recognise as a document.
 *
 * Deliberately narrow: pasting a link to a web page should stay a link, and only a URL that is
 * obviously a file is worth turning into an embed without asking.
 */
const FILE_PATTERN = /^https?:\/\/\S+\.(pdf|docx?|xlsx?|pptx?|ppsx?|odt|ods|odp|rtf|csv|tsv|md|markdown|txt|json|xml|epub)(\?\S*)?$/i;

class InsertBookDocumentCommand extends Command {
    refresh() {
        const model = this.editor.model;

        this.isEnabled = model.schema.findAllowedParent(
            model.document.selection.getFirstPosition(),
            'bookDocument',
        ) !== null;
    }

    execute(options) {
        const editor = this.editor;

        editor.model.change((writer) => {
            const node = writer.createElement('bookDocument', {
                handle: options.handle,
                label: options.label || options.handle,
                options: options.options || '',
            });

            editor.model.insertObject(node, null, null, { setSelection: 'after' });
        });
    }
}

class BookDocumentEditing extends Plugin {
    static get requires() {
        return [Widget];
    }

    static get pluginName() {
        return 'BookDocumentEditing';
    }

    init() {
        const editor = this.editor;

        editor.model.schema.register('bookDocument', {
            inheritAllFrom: '$blockObject',
            allowAttributes: ['handle', 'label', 'options'],
        });

        editor.conversion.for('upcast').elementToElement({
            view: {
                name: 'div',
                classes: 'book-document',
            },
            model: (viewElement, { writer }) => writer.createElement('bookDocument', {
                handle: viewElement.getAttribute('data-book-handle') || '',
                label: viewElement.getAttribute('data-book-label')
                    || viewElement.getAttribute('data-book-handle')
                    || '',
                options: viewElement.getAttribute('data-book-options') || '',
            }),
        });

        editor.conversion.for('dataDowncast').elementToElement({
            model: 'bookDocument',
            view: (modelElement, { writer }) => {
                const handle = modelElement.getAttribute('handle') || '';
                const label = modelElement.getAttribute('label') || '';
                const options = modelElement.getAttribute('options') || '';
                const attributes = { class: 'book-document', 'data-book-handle': handle };

                if (label) attributes['data-book-label'] = label;
                if (options) attributes['data-book-options'] = options;

                // A raw element, so the reference tag is written as plain text rather than as
                // model content CKEditor would then try to own.
                return writer.createRawElement('div', attributes, (domElement) => {
                    domElement.textContent = options
                        ? `{book:${handle}:render(${options})}`
                        : `{book:${handle}:render}`;
                });
            },
        });

        editor.conversion.for('editingDowncast').elementToElement({
            model: 'bookDocument',
            view: (modelElement, { writer }) => {
                const handle = modelElement.getAttribute('handle') || '';
                const label = modelElement.getAttribute('label') || handle;
                const options = modelElement.getAttribute('options') || '';

                const container = writer.createContainerElement('div', { class: 'book-document' }, [
                    writer.createRawElement('div', { class: 'book-document-card' }, (domElement) => {
                        domElement.textContent = options
                            ? `${label} (${handle} · ${options})`
                            : `${label} (${handle})`;
                    }),
                ]);

                return toWidget(container, writer, { label: `Book document: ${label}` });
            },
        });

        editor.commands.add('insertBookDocument', new InsertBookDocumentCommand(editor));
    }
}

/**
 * Paste a link to a file, get a document.
 *
 * Only when the pasted text is a bare file URL and nothing else — pasting a paragraph that
 * happens to contain a link should stay a link, and pasting into the middle of a sentence should
 * stay text.
 */
class BookDocumentAutoPaste extends Plugin {
    static get pluginName() {
        return 'BookDocumentAutoPaste';
    }

    init() {
        const editor = this.editor;

        editor.plugins.get('ClipboardPipeline').on('inputTransformation', (event, data) => {
            const text = (data.dataTransfer.getData('text/plain') || '').trim();

            if (!FILE_PATTERN.test(text) || !editor.commands.get('insertBookDocument').isEnabled) return;

            // Only on an empty line: replacing a selection with a document is not what anyone
            // meant by pasting over it.
            const selection = editor.model.document.selection;
            const block = selection.getFirstPosition().parent;

            if (!selection.isCollapsed || (block.childCount || 0) > 0) return;

            event.stop();

            Craft.sendActionRequest('POST', 'book/documents/quick-create', { data: { url: text } })
                .then((response) => {
                    editor.execute('insertBookDocument', {
                        handle: response.data.handle,
                        label: response.data.title,
                    });
                })
                .catch(() => {
                    // Fall back to what a paste normally does, so the URL is never simply lost.
                    editor.model.change((writer) => {
                        editor.model.insertContent(writer.createText(text));
                    });
                });
        }, { priority: 'high' });
    }
}

export class BookDocument extends Plugin {
    static get requires() {
        return [BookDocumentEditing, BookDocumentAutoPaste];
    }

    static get pluginName() {
        return 'BookDocument';
    }

    init() {
        const editor = this.editor;

        editor.ui.componentFactory.add('bookDocument', (locale) => {
            const button = new ButtonView(locale);
            const command = editor.commands.get('insertBookDocument');

            button.set({
                label: Craft.t('book', 'Document'),
                icon: ICON,
                tooltip: true,
            });

            button.bind('isEnabled').to(command, 'isEnabled');

            button.on('execute', () => {
                window.BookPicker.open((document_) => {
                    editor.execute('insertBookDocument', {
                        handle: document_.handle,
                        label: document_.title,
                    });
                    editor.editing.view.focus();
                });
            });

            return button;
        });
    }
}

export default BookDocument;
