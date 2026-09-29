<?php

namespace romanavr\contentops\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\Db;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangeStatus;
use romanavr\contentops\errors\ConflictException;
use romanavr\contentops\helpers\Values;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\models\Target;
use romanavr\contentops\operators\OperatorInterface;
use romanavr\contentops\records\Change;
use Throwable;
use yii\base\Component;

/**
 * Writes previewed changes to elements, and reverts them. Works one element/site at a time,
 * so jobs and console commands can batch however they like.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Applier extends Component
{
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

    // Private Methods
    // =========================================================================

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
            $saved = Craft::$app->getElements()->saveElement($element);
        } catch (Throwable $e) {
            $this->_markAll($written, ChangeStatus::Failed, $e->getMessage());
            return;
        }

        if (!$saved) {
            $this->_markAll($written, ChangeStatus::Failed, implode(' ', $element->getFirstErrors()) ?: 'The element couldn’t be saved.');
            return;
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
