<?php

namespace romanavr\contentops\db;

use craft\base\Batchable;
use craft\db\Query;

/**
 * Batches the distinct element/site pairs of a changeset, in a stable order.
 *
 * Deliberately not filtered by change status: statuses change while a job runs, and filtering on them
 * would shift the offsets `BaseBatchedJob` pages with, skipping items.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class ElementSiteBatcher implements Batchable
{
    // Public Methods
    // =========================================================================

    /**
     * @param int $changesetId
     */
    public function __construct(
        private readonly int $changesetId,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        return (int)$this->_query()->count();
    }

    /**
     * @inheritdoc
     * @return array<int, array{elementId: int, siteId: int}>
     */
    public function getSlice(int $offset, int $limit): iterable
    {
        return array_map(
            fn(array $row) => ['elementId' => (int)$row['elementId'], 'siteId' => (int)$row['siteId']],
            $this->_query()->offset($offset)->limit($limit)->all(),
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * @return Query
     */
    private function _query(): Query
    {
        return (new Query())
            ->select(['elementId', 'siteId'])
            ->distinct()
            ->from(Table::CHANGES)
            ->where(['changesetId' => $this->changesetId])
            ->orderBy(['elementId' => SORT_ASC, 'siteId' => SORT_ASC]);
    }
}
