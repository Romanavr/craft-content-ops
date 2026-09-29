<?php

namespace romanavr\contentops\records;

use craft\db\ActiveRecord;
use romanavr\contentops\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * Change record: one target (field or attribute) of one element in one site.
 *
 * @property int $id
 * @property int $changesetId
 * @property int $elementId
 * @property int $siteId
 * @property string $target
 * @property string|null $oldValue JSON-encoded serialized value
 * @property string|null $newValue JSON-encoded serialized value
 * @property string $status
 * @property string|null $error
 * @property string|null $elementDateUpdated
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 * @property-read Changeset $changeset
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Change extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::CHANGES;
    }

    /**
     * Returns the change's changeset.
     *
     * @return ActiveQueryInterface
     */
    public function getChangeset(): ActiveQueryInterface
    {
        return $this->hasOne(Changeset::class, ['id' => 'changesetId']);
    }
}
