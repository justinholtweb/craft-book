<?php

namespace justinholtweb\book\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $handle
 * @property string|null $assetId
 * @property string|null $url
 * @property string $format
 * @property string $viewer
 * @property string|null $filename
 * @property string|null $config
 */
class DocumentRecord extends ActiveRecord
{
    public const TABLE = '{{%book_documents}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
