<?php

namespace romanavr\contentops\jobs;

use craft\base\Batchable;
use craft\queue\BaseBatchedJob;
use romanavr\contentops\ContentOps;
use romanavr\contentops\db\ElementSiteBatcher;
use romanavr\contentops\models\Changeset;

/**
 * Applies a previewed changeset in batches of element/site pairs.
 *
 * @property-read Changeset $changeset
 *
 * @author Romanavr
 * @since 1.0.0
 */
class ApplyChangeset extends BaseBatchedJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int
     */
    public int $changesetId;

    // Private Properties
    // =========================================================================

    /**
     * @var Changeset|null
     */
    private ?Changeset $_changeset = null;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        return new ElementSiteBatcher($this->changesetId);
    }

    /**
     * @inheritdoc
     */
    protected function processItem(mixed $item): void
    {
        ContentOps::getInstance()->getApplier()->applyElement($this->getChangeset(), $item['elementId'], $item['siteId']);
    }

    /**
     * @inheritdoc
     */
    protected function before(): void
    {
        ContentOps::getInstance()->getChangesets()->startApply($this->changesetId);
    }

    /**
     * @inheritdoc
     */
    protected function after(): void
    {
        ContentOps::getInstance()->getChangesets()->finishApply($this->changesetId);
    }

    /**
     * @inheritdoc
     */
    protected function afterBatch(): void
    {
        ContentOps::getInstance()->getApplier()->flushDeferredPruning($this->changesetId);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return "Applying Content Ops changeset #$this->changesetId";
    }

    /**
     * Returns the changeset (loaded once per batch).
     *
     * @return Changeset
     */
    protected function getChangeset(): Changeset
    {
        return $this->_changeset ??= ContentOps::getInstance()->getChangesets()->getChangesetById($this->changesetId);
    }
}
