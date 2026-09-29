<?php

namespace romanavr\contentops\jobs;

use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetStatus;

/**
 * Undoes an applied changeset in batches of element/site pairs.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class UndoChangeset extends ApplyChangeset
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Restore old values even where they were edited after the changeset was applied
     */
    public bool $force = false;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function processItem(mixed $item): void
    {
        ContentOps::getInstance()->getApplier()->undoElement($this->getChangeset(), $item['elementId'], $item['siteId'], $this->force);
    }

    /**
     * @inheritdoc
     */
    protected function before(): void
    {
        ContentOps::getInstance()->getChangesets()->setStatus($this->changesetId, ChangesetStatus::Undoing);
    }

    /**
     * @inheritdoc
     */
    protected function after(): void
    {
        ContentOps::getInstance()->getChangesets()->finishUndo($this->changesetId);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return "Undoing Content Ops changeset #$this->changesetId";
    }
}
