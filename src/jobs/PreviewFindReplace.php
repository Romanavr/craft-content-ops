<?php

namespace romanavr\contentops\jobs;

use Craft;
use craft\queue\BaseJob;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetStatus;
use romanavr\contentops\models\FindReplaceScope;
use romanavr\contentops\models\MatchSpec;
use Throwable;

/**
 * Computes a Find & Replace preview in the background (site-wide searches can take a while).
 *
 * @author Romanavr
 * @since 1.0.0
 */
class PreviewFindReplace extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The changeset (status `previewing`) to fill in.
     */
    public int $changesetId;

    /**
     * @var array<string, mixed> {@see MatchSpec} attributes
     */
    public array $spec = [];

    /**
     * @var array<string, mixed> {@see FindReplaceScope} attributes
     */
    public array $scope = [];

    /**
     * @var int|null
     */
    public ?int $userId = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = ContentOps::getInstance();
        $user = $this->userId ? Craft::$app->getUsers()->getUserById($this->userId) : null;

        try {
            $plugin->getFindReplace()->preview(new MatchSpec($this->spec), new FindReplaceScope($this->scope), $user, $this->changesetId);
        } catch (Throwable $e) {
            $plugin->getChangesets()->setStatus($this->changesetId, ChangesetStatus::Failed, ['error' => $e->getMessage()]);
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return "Finding matches for Content Ops changeset #$this->changesetId";
    }
}
