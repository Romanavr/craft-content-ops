<?php

namespace romanavr\contentops\records;

use craft\db\ActiveRecord;
use romanavr\contentops\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * Changeset record.
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property int|null $userId
 * @property string $selection
 * @property string $operations
 * @property string|null $options
 * @property string|null $counts JSON: counts keyed by change status, plus `total` and `unchanged`
 * @property string|null $error
 * @property string|null $dateApplied
 * @property string|null $dateUndone
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 * @property-read Change[] $changes
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Changeset extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::CHANGESETS;
    }

    /**
     * Returns the changeset's changes.
     *
     * @return ActiveQueryInterface
     */
    public function getChanges(): ActiveQueryInterface
    {
        return $this->hasMany(Change::class, ['changesetId' => 'id']);
    }
}
