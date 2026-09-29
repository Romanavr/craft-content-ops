<?php

namespace romanavr\contentops\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use romanavr\contentops\ContentOps;
use romanavr\contentops\db\Table;
use romanavr\contentops\enums\ChangesetStatus;
use romanavr\contentops\enums\ChangesetType;
use romanavr\contentops\enums\ChangeStatus;
use romanavr\contentops\helpers\Values;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;
use romanavr\contentops\records\Changeset as ChangesetRecord;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Dry run: resolves a selection, computes every old → new value without writing to elements, and freezes
 * the result as `pending` change rows. Apply later writes exactly those rows.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Previewer extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Change rows buffered before a batch insert.
     */
    public const INSERT_BATCH_SIZE = 500;

    // Private Properties
    // =========================================================================

    /**
     * @var array<int, array<int, mixed>> Buffered change rows
     */
    private array $_rows = [];

    // Public Methods
    // =========================================================================

    /**
     * Previews operations on a selection and records the result as a new changeset.
     *
     * @param Selection $selection
     * @param Operation[] $operations
     * @param User|null $user The user making the change; `null` skips permission checks (console).
     * @param ChangesetType $type
     * @param callable|null $onProgress Called as `fn(int $examined)` periodically
     * @param bool $ignoreMissingTargets Don't record elements that lack a target (Find & Replace searches many fields)
     * @param int|null $changesetId Fill in this existing (previewing) changeset instead of creating a new one
     * @return Changeset
     * @throws InvalidArgumentException if the selection or an operation is invalid
     * @throws \yii\db\Exception
     */
    public function preview(
        Selection $selection,
        array $operations,
        ?User $user = null,
        ChangesetType $type = ChangesetType::BulkEdit,
        ?callable $onProgress = null,
        bool $ignoreMissingTargets = false,
        ?int $changesetId = null,
    ): Changeset {
        $this->_validate($selection, $operations);

        $plugin = ContentOps::getInstance();
        $record = $changesetId !== null ? ChangesetRecord::findOne($changesetId) : null;
        $record ??= new ChangesetRecord();
        $record->type = $type->value;
        // A background preview stays “previewing” until every row is written, so pollers never see a half-built changeset.
        $record->status = ($changesetId !== null ? ChangesetStatus::Previewing : ChangesetStatus::Previewed)->value;
        $record->userId = $user?->id;
        $record->selection = Json::encode($selection->toArray());
        $record->operations = Json::encode(array_map(fn(Operation $operation) => $operation->toArray(), $operations));
        $record->options = Json::encode(array_merge(
            Json::decode($record->options ?? '{}') ?: [],
            ['createRevisions' => $plugin->getSettings()->createRevisions],
        ));
        $record->save(false);

        $opsByTarget = [];
        foreach ($operations as $operation) {
            $opsByTarget[$operation->target][] = $operation;
        }

        $targets = $plugin->getTargets();
        $total = 0;
        $unchanged = 0;
        $currentElementId = null;
        $seenKeys = [];

        foreach ($plugin->getSelections()->createQuery($selection)->each() as $element) {
            /** @var ElementInterface $element */
            $total++;

            if ($element->id !== $currentElementId) {
                $currentElementId = $element->id;
                $seenKeys = [];
            }

            foreach ($opsByTarget as $handle => $targetOps) {
                // A Matrix path (field.entryType.innerField) edits the owner's nested entries; anything else edits the element itself.
                $subjects = Targets::isNestedPath($handle) ? $targets->nestedElements($element, $handle) : [$element];

                if ($subjects === null) {
                    if ($ignoreMissingTargets) {
                        continue;
                    }

                    $this->_addRow($record->id, $element, $handle, ChangeStatus::Skipped, error: "“{$this->_rootHandle($handle)}” isn’t in this element’s field layout.");
                    continue;
                }

                foreach ($subjects as $subject) {
                    $unchanged += $this->_previewSubject($record->id, $subject, $handle, $targetOps, $user, $seenKeys, $ignoreMissingTargets);
                }
            }

            if (count($this->_rows) >= self::INSERT_BATCH_SIZE) {
                $this->_flushRows();
            }

            if ($onProgress !== null && $total % 100 === 0) {
                $onProgress($total);
            }
        }

        $this->_flushRows();

        // With $changesetId the caller finishes the changeset (and marks it previewed) itself.
        $changesets = $plugin->getChangesets();
        $changesets->refreshCounts($record->id, [
            'total' => $total,
            'unchanged' => $unchanged,
            'withDrafts' => $this->_countElementsWithDrafts($record->id),
        ]);

        return $changesets->getChangesetById($record->id);
    }

    // Private Methods
    // =========================================================================

    /**
     * Previews one target on one element (an owner or a nested entry) and buffers the resulting row.
     *
     * @param int $changesetId
     * @param ElementInterface $element
     * @param string $handle
     * @param Operation[] $targetOps
     * @param User|null $user
     * @param array<string, bool> $seenKeys Translation keys already handled, shared across the element's sites
     * @param bool $ignoreMissingTargets
     * @return int 1 if the value is unchanged, otherwise 0
     */
    private function _previewSubject(int $changesetId, ElementInterface $element, string $handle, array $targetOps, ?User $user, array &$seenKeys, bool $ignoreMissingTargets = false): int
    {
        $plugin = ContentOps::getInstance();
        $targets = $plugin->getTargets();
        $operators = $plugin->getOperators();

        if ($user !== null && !Craft::$app->getElements()->canSave($element, $user)) {
            $this->_addRow($changesetId, $element, $handle, ChangeStatus::Skipped, error: 'You don’t have permission to save this element.');
            return 0;
        }

        $target = $targets->resolve($element, $handle);

        if ($target === null && $ignoreMissingTargets) {
            return 0;
        }

        if ($target === null) {
            $this->_addRow($changesetId, $element, $handle, ChangeStatus::Skipped, error: "“{$handle}” isn’t in this element’s field layout.");
            return 0;
        }

        $unsupported = array_filter($targetOps, fn(Operation $operation) => !$operators->getOperator($operation->operator)->supports($target));

        if ($unsupported) {
            $operation = reset($unsupported);
            $this->_addRow($changesetId, $element, $handle, ChangeStatus::Skipped, error: sprintf(
                'The %s operator can’t edit %s.',
                $operators->getOperator($operation->operator)::displayName(),
                $target->field ? $target->field::displayName() . ' fields' : "the “{$handle}” attribute",
            ));
            return 0;
        }

        // Values shared between sites are only changed once per element.
        $key = "$element->id|$handle|" . $targets->translationKey($element, $target);

        if (isset($seenKeys[$key])) {
            return 0;
        }

        $seenKeys[$key] = true;

        $mightChange = false;

        foreach ($targetOps as $operation) {
            if ($operators->getOperator($operation->operator)->mightChange($element, $target, $operation)) {
                $mightChange = true;
                break;
            }
        }

        if (!$mightChange) {
            return 1;
        }

        try {
            $old = $targets->read($element, $target);
            $new = $old;

            foreach ($targetOps as $operation) {
                $new = $operators->getOperator($operation->operator)->apply($new, $operation, $element);
            }

            if (Values::equal($old, $new)) {
                return 1;
            }

            $this->_addRow($changesetId, $element, $handle, ChangeStatus::Pending, Values::encode($old), Values::encode($new));
        } catch (Throwable $e) {
            $this->_addRow($changesetId, $element, $handle, ChangeStatus::Failed, error: $e->getMessage());
        }

        return 0;
    }

    /**
     * @param string $handle
     * @return string
     */
    private function _rootHandle(string $handle): string
    {
        return explode(Targets::PATH_SEPARATOR, $handle)[0];
    }

    /**
     * Counts elements about to change that have drafts. Craft marks such drafts as outdated after the save.
     *
     * @param int $changesetId
     * @return int
     */
    private function _countElementsWithDrafts(int $changesetId): int
    {
        return (int)(new Query())
            ->from(['e' => CraftTable::ELEMENTS])
            ->where(['not', ['e.draftId' => null]])
            ->andWhere(['e.dateDeleted' => null])
            ->andWhere(['e.canonicalId' => (new Query())
                ->select(['elementId'])
                ->from(Table::CHANGES)
                ->where(['changesetId' => $changesetId, 'status' => ChangeStatus::Pending->value]),
            ])
            ->count('DISTINCT [[e.canonicalId]]');
    }

    /**
     * @param Selection $selection
     * @param Operation[] $operations
     * @throws InvalidArgumentException
     */
    private function _validate(Selection $selection, array $operations): void
    {
        if (!$selection->validate()) {
            throw new InvalidArgumentException(implode(' ', $selection->getFirstErrors()));
        }

        if (empty($operations)) {
            throw new InvalidArgumentException('At least one operation is required.');
        }

        $operators = ContentOps::getInstance()->getOperators();

        foreach ($operations as $operation) {
            if (!$operation->validate()) {
                throw new InvalidArgumentException(implode(' ', $operation->getFirstErrors()));
            }

            $operators->getOperator($operation->operator)->validateOperation($operation);
        }
    }

    /**
     * @param int $changesetId
     * @param ElementInterface $element
     * @param string $target
     * @param ChangeStatus $status
     * @param string|null $oldValue
     * @param string|null $newValue
     * @param string|null $error
     */
    private function _addRow(
        int $changesetId,
        ElementInterface $element,
        string $target,
        ChangeStatus $status,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $error = null,
    ): void {
        $this->_rows[] = [$changesetId, $element->id, $element->siteId, $target, $oldValue, $newValue, $status->value, $error];
    }

    /**
     * @throws \yii\db\Exception
     */
    private function _flushRows(): void
    {
        if (empty($this->_rows)) {
            return;
        }

        Db::batchInsert(Table::CHANGES, ['changesetId', 'elementId', 'siteId', 'target', 'oldValue', 'newValue', 'status', 'error'], $this->_rows);
        $this->_rows = [];
    }
}
