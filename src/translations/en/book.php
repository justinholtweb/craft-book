<?php

/**
 * Book's own strings.
 *
 * Every user-facing string in the plugin goes through `Craft::t('book', …)`; this file is the
 * list, so a translator has one place to work from and a typo in a key is visible here.
 */

return [
    'Book' => 'Book',
    'Document' => 'Document',
    'document' => 'document',
    'Documents' => 'Documents',
    'documents' => 'documents',
    'New document' => 'New document',
    'Untitled document' => 'Untitled document',
    'Document not found.' => 'Document not found.',
    'Document saved.' => 'Document saved.',
    'Document deleted.' => 'Document deleted.',
    'Couldn’t save document.' => 'Couldn’t save document.',
    'Couldn’t delete document.' => 'Couldn’t delete document.',
    'Couldn’t create document.' => 'Couldn’t create document.',
    'File not found.' => 'File not found.',
    'This link is no longer valid.' => 'This link is no longer valid.',
    'This file is not available at this address.' => 'This file is not available at this address.',
    'That file doesn’t exist, or you don’t have access to its volume.' => 'That file doesn’t exist, or you don’t have access to its volume.',
    'Link secret' => 'Link secret',
    'Mixed into every file link Book signs. Links to private files don’t expire unless URLs are signed, so change this to revoke every link Book has handed out — a forwarded one included. Use an environment variable, such as `$BOOK_LINK_SECRET`, so it can be changed without a deploy. Leave it empty to keep existing links working.' => 'Mixed into every file link Book signs. Links to private files don’t expire unless URLs are signed, so change this to revoke every link Book has handed out — a forwarded one included. Use an environment variable, such as `$BOOK_LINK_SECRET`, so it can be changed without a deploy. Leave it empty to keep existing links working.',
];
