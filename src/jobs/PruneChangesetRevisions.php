<?php

namespace romanavr\contentops\jobs;

use craft\queue\BaseJob;
use craft\queue\jobs\PruneRevisions;

/**
 * Prunes excess revisions for the elements a changeset batch saved, in one job instead of one job per element.
 * Each element is pruned by Craft's own {@see PruneRevisions} job logic.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class PruneChangesetRevisions extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int
     */
    public int $changesetId;

    /**
     * @var array<int, array<string, mixed>> {@see PruneRevisions} configs
     */
    public array $jobs = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $total = count($this->jobs);

        foreach (array_values($this->jobs) as $i => $config) {
            $this->setProgress($queue, ($i + 1) / $total);
            (new PruneRevisions($config))->execute($queue);
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return "Pruning revisions for Content Ops changeset #$this->changesetId";
    }
}
