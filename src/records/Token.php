<?php

namespace romanavr\contentops\records;

use craft\db\ActiveRecord;
use romanavr\contentops\db\Table;

/**
 * MCP access token record.
 *
 * @property int $id
 * @property string $name
 * @property int $userId
 * @property string $tokenHash SHA-256 of the token
 * @property string $tokenPrefix First characters, for recognising a token in the list
 * @property string $mode `readonly`, `propose` or `full`
 * @property string|null $dateLastUsed
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Token extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::TOKENS;
    }
}
