<?php

namespace justinholtweb\book\migrations;

use craft\db\Migration;
use craft\db\Table;
use justinholtweb\book\elements\Document;
use justinholtweb\book\records\DocumentRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(DocumentRecord::TABLE)) {
            return true;
        }

        $this->createTable(DocumentRecord::TABLE, [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string(64)->notNull(),

            // Exactly one of these is set. A document is an asset or a URL, never both, and the
            // element's validation is what enforces it — a check constraint here would only
            // move the error somewhere an author cannot read it.
            'assetId' => $this->integer(),
            'url' => $this->text(),

            'format' => $this->string(32)->notNull()->defaultValue('other'),
            'viewer' => $this->string(16)->notNull()->defaultValue('auto'),

            // Denormalised so the index can show what a document is without loading the asset.
            'filename' => $this->string(255),

            'config' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, DocumentRecord::TABLE, ['handle'], true);
        $this->createIndex(null, DocumentRecord::TABLE, ['format'], false);
        $this->createIndex(null, DocumentRecord::TABLE, ['viewer'], false);
        $this->createIndex(null, DocumentRecord::TABLE, ['assetId'], false);

        // CASCADE, so deleting the element takes the row with it — the element is the record's
        // reason to exist, not the other way round.
        $this->addForeignKey(null, DocumentRecord::TABLE, ['id'], Table::ELEMENTS, ['id'], 'CASCADE', null);

        // SET NULL, not CASCADE: deleting the asset should leave the document standing with a
        // visible “the file is gone” state, rather than silently deleting content that pages
        // still reference by handle.
        $this->addForeignKey(null, DocumentRecord::TABLE, ['assetId'], Table::ELEMENTS, ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->delete(Table::ELEMENTS, ['type' => Document::class]);
        $this->dropTableIfExists(DocumentRecord::TABLE);

        return true;
    }
}
