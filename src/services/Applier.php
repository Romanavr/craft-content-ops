<?php

namespace romanavr\contentops\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\queue\jobs\PruneRevisions;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangeStatus;
use romanavr\contentops\errors\ConflictException;
use romanavr\contentops\helpers\Values;
use romanavr\contentops\jobs\PruneChangesetRevisions;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\models\Target;
use romanavr\contentops\operators\OperatorInterface;
use romanavr\contentops\records\Change;
use Throwable;
use yii\base\Component;
use yii\base\Event;
use yii\queue\PushEvent;
use yii\queue\Queue as YiiQueue;

/**
 * Writes previewed changes to elements, and reverts them. Works one element/site at a time,
 * so jobs and console commands can batch however they like.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Applier extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * @var array<string, array<string, mixed>> Craft prune-revision jobs held back while saving, keyed by element/site
     */
    private array $_deferredPruning = [];

    // Public Methods
    // =========================================================================

    /**
     * Applies the pending changes of one element in one site.
     *
     * A change is only written if the stored value still equals the previewed old value; otherwise
     * it's marked as a conflict. All written changes are saved in a single element save.
     *
     * @param Changeset $changeset
     * @param int $elementId
     * @param int $siteId
     * @throws \yii\db\Exception
     */
    public function applyElement(Changeset $changeset, int $elementId, int $siteId): void
    {
        $this->_process($changeset, $elementId, $siteId, ChangeStatus::Pending, function(ElementInterface $element, Target $target, Change $change): bool {
            $current = Values::encode(ContentOps::getInstance()->getTargets()->read($element, $target));

            if ($current !== $change->oldValue) {
                $this->_mark($change, ChangeStatus::Conflict, 'The value changed after the preview.');
                return false;
            }

            ContentOps::getInstance()->getTargets()->write($element, $target, Values::decode($change->newValue));
            return true;
        }, ChangeStatus::Applied, "Content Ops changeset #{$changeset->id}");
    }

    /**
     * Reverts the applied changes of one element in one site.
     *
     * @param Changeset $changeset
     * @param int $elementId
     * @param int $siteId
     * @param bool $force Restore old values even if they were changed after the changeset was applied
     * @throws \yii\db\Exception
     */
    public function undoElement(Changeset $changeset, int $elementId, int $siteId, bool $force = false): void
    {
        $fromStatuses = $force ? [ChangeStatus::Applied, ChangeStatus::UndoConflict] : [ChangeStatus::Applied];

        $this->_process($changeset, $elementId, $siteId, $fromStatuses, function(ElementInterface $element, Target $target, Change $change) use ($changeset, $force): bool {
            $targets = ContentOps::getInstance()->getTargets();
            $old = Values::decode($change->oldValue);

            if (!$force) {
                try {
                    $old = $this->_operatorFor($changeset, $change->target)->revert(
                        $targets->read($element, $target),
                        $old,
                        Values::decode($change->newValue),
                    );
                } catch (ConflictException $e) {
                    $this->_mark($change, ChangeStatus::UndoConflict, $e->getMessage());
                    return false;
                }
            }

            $targets->write($element, $target, $old);
            return true;
        }, ChangeStatus::Undone, "Undo of Content Ops changeset #{$changeset->id}");
    }

    /**
     * Pushes one job that prunes revisions for everything saved since the last flush.
     *
     * Craft queues a separate “Pruning extra revisions” job after every save that creates a revision.
     * For a bulk edit that would mean thousands of jobs, so the applier holds them back and batches them here.
     *
     * @param int $changesetId
     */
    public function flushDeferredPruning(int $changesetId): void
    {
        if (empty($this->_deferredPruning)) {
            return;
        }

        Queue::push(new PruneChangesetRevisions([
            'changesetId' => $changesetId,
            'jobs' => array_values($this->_deferredPruning),
        ]));
        $this->_deferredPruning = [];
    }

    // Private Methods
    // =========================================================================

    /**
     * Saves an element, holding back the per-save prune-revisions job Craft would queue.
     *
     * @param ElementInterface $element
     * @return bool
     * @throws Throwable
     */
    private function _saveElement(ElementInterface $element): bool
    {
        $handler = function(PushEvent $event) {
            if ($event->job instanceof PruneRevisions) {
                $job = $event->job;
                $this->_deferredPruning["$job->canonicalId:$job->siteId"] = [
                    'elementType' => $job->elementType,
                    'canonicalId' => $job->canonicalId,
                    'siteId' => $job->siteId,
                    'maxRevisions' => $job->maxRevisions,
                ];
                $event->handled = true;
            }
        };

        Event::on(YiiQueue::class, YiiQueue::EVENT_BEFORE_PUSH, $handler);

        try {
            return Craft::$app->getElements()->saveElement($element);
        } finally {
            Event::off(YiiQueue::class, YiiQueue::EVENT_BEFORE_PUSH, $handler);
        }
    }

    /**
     * Loads the element, runs `$write` for each matching change, and saves the element once.
     *
     * @param Changeset $changeset
     * @param int $elementId
     * @param int $siteId
     * @param ChangeStatus|ChangeStatus[] $fromStatus Which changes to process
     * @param callable $write `fn(ElementInterface, Target, Change): bool` — returns whether it changed the element
     * @param ChangeStatus $successStatus
     * @param string $revisionNotes
     * @throws \yii\db\Exception
     */
    private function _process(
        Changeset $changeset,
        int $elementId,
        int $siteId,
        ChangeStatus|array $fromStatus,
        callable $write,
        ChangeStatus $successStatus,
        string $revisionNotes,
    ): void {
        $statuses = array_map(fn(ChangeStatus $status) => $status->value, is_array($fromStatus) ? $fromStatus : [$fromStatus]);

        /** @var Change[] $changes */
        $changes = Change::find()
            ->where([
                'changesetId' => $changeset->id,
                'elementId' => $elementId,
                'siteId' => $siteId,
                'status' => $statuses,
            ])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        if (empty($changes)) {
            return;
        }

        /** @var class-string<ElementInterface> $elementType */
        $elementType = $changeset->selection->elementType;
        $element = $elementType::find()->id($elementId)->siteId($siteId)->status(null)->one();

        if ($element === null) {
            $this->_markAll($changes, ChangeStatus::Skipped, 'The element no longer exists in this site.');
            return;
        }

        if ($changeset->userId !== null) {
            $user = Craft::$app->getUsers()->getUserById($changeset->userId);

            if ($user === null || !Craft::$app->getElements()->canSave($element, $user)) {
                $this->_markAll($changes, ChangeStatus::Skipped, 'The user doesn’t have permission to save this element.');
                return;
            }
        }

        $targets = ContentOps::getInstance()->getTargets();
        $written = [];

        foreach ($changes as $change) {
            $target = $targets->resolve($element, $change->target);

            if ($target === null) {
                $this->_mark($change, ChangeStatus::Skipped, "“{$change->target}” is no longer in this element’s field layout.");
                continue;
            }

            try {
                if ($write($element, $target, $change)) {
                    $written[] = $change;
                }
            } catch (Throwable $e) {
                $this->_mark($change, ChangeStatus::Failed, $e->getMessage());
            }
        }

        if (empty($written)) {
            return;
        }

        if ($element instanceof Entry) {
            $element->revisionNotes = $revisionNotes;
            $element->revisionCreatorId = $changeset->userId;
        }

        // Saving without `resaving` makes Craft create a revision (for sections with versioning).
        $element->resaving = !($changeset->options['createRevisions'] ?? true);

        try {
            $saved = $this->_saveElement($element);
        } catch (Throwable $e) {
            $this->_markAll($written, ChangeStatus::Failed, $e->getMessage());
            return;
        }

        if (!$saved) {
            $this->_markAll($written, ChangeStatus::Failed, implode(' ', $element->getFirstErrors()) ?: 'The element couldn’t be saved.');
            return;
        }

        // Log what was actually stored (e.g. new nested entries only get their IDs on save; undo needs them).
        if ($successStatus === ChangeStatus::Applied) {
            $targets = ContentOps::getInstance()->getTargets();

            foreach ($written as $change) {
                $target = $targets->resolve($element, $change->target);

                if ($target !== null) {
                    $change->newValue = Values::encode($targets->read($element, $target));
                }
            }
        }

        $this->_markAll($written, $successStatus, null, Db::prepareDateForDb($element->dateUpdated));
    }

    /**
     * Returns the operator whose `revert()` should undo a target's change.
     *
     * @param Changeset $changeset
     * @param string $target
     * @return OperatorInterface
     */
    private function _operatorFor(Changeset $changeset, string $target): OperatorInterface
    {
        $operations = $changeset->getOperationsForTarget($target);

        // When several operations touched the same target, the first operator's whole-value revert applies.
        return ContentOps::getInstance()->getOperators()->getOperator($operations[0]->operator);
    }

    /**
     * @param Change $change
     * @param ChangeStatus $status
     * @param string|null $error
     * @param string|null $elementDateUpdated
     * @throws \yii\db\Exception
     */
    private function _mark(Change $change, ChangeStatus $status, ?string $error = null, ?string $elementDateUpdated = null): void
    {
        $change->status = $status->value;
        $change->error = $error;

        if ($elementDateUpdated !== null) {
            $change->elementDateUpdated = $elementDateUpdated;
        }

        $change->save(false);
    }

    /**
     * @param Change[] $changes
     * @param ChangeStatus $status
     * @param string|null $error
     * @param string|null $elementDateUpdated
     * @throws \yii\db\Exception
     */
    private function _markAll(array $changes, ChangeStatus $status, ?string $error = null, ?string $elementDateUpdated = null): void
    {
        foreach ($changes as $change) {
            $this->_mark($change, $status, $error, $elementDateUpdated);
        }
    }
}
