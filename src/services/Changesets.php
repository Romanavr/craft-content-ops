<?php

namespace romanavr\contentops\services;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\Queue;
use DateTime;
use romanavr\contentops\ContentOps;
use romanavr\contentops\db\ElementSiteBatcher;
use romanavr\contentops\db\Table;
use romanavr\contentops\enums\ChangesetStatus;
use romanavr\contentops\enums\ChangeStatus;
use romanavr\contentops\jobs\ApplyChangeset;
use romanavr\contentops\jobs\UndoChangeset;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\records\Change;
use romanavr\contentops\records\Changeset as ChangesetRecord;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Changeset history, apply/undo orchestration, and counts.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Changesets extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns a changeset by its ID.
     *
     * @param int $id
     * @return Changeset|null
     */
    public function getChangesetById(int $id): ?Changeset
    {
        $record = ChangesetRecord::findOne($id);

        return $record ? Changeset::fromRecord($record) : null;
    }

    /**
     * Returns the most recent changesets.
     *
     * @param int $limit
     * @return Changeset[]
     */
    public function getRecentChangesets(int $limit = 20): array
    {
        /** @var ChangesetRecord[] $records */
        $records = ChangesetRecord::find()->orderBy(['id' => SORT_DESC])->limit($limit)->all();

        return array_map(fn(ChangesetRecord $record) => Changeset::fromRecord($record), $records);
    }

    /**
     * Returns a page of changesets, newest first.
     *
     * @param int|null $userId Only this user's changesets, or `null` for everyone's
     * @param int $limit
     * @param int $offset
     * @return array{0: Changeset[], 1: int} The changesets and the total count
     */
    public function getChangesetsPage(?int $userId, int $limit, int $offset): array
    {
        $query = ChangesetRecord::find()->orderBy(['id' => SORT_DESC]);

        if ($userId !== null) {
            $query->where(['userId' => $userId]);
        }

        $total = (int)(clone $query)->count();
        /** @var ChangesetRecord[] $records */
        $records = $query->limit($limit)->offset($offset)->all();

        return [array_map(fn(ChangesetRecord $record) => Changeset::fromRecord($record), $records), $total];
    }

    /**
     * Returns whether a user can see a changeset: their own, or anyone's with the “View history” permission.
     *
     * @param Changeset $changeset
     * @param User $user
     * @return bool
     */
    public function canView(Changeset $changeset, User $user): bool
    {
        return $changeset->userId === $user->id || $user->can('contentOps:viewHistory');
    }

    /**
     * Returns whether a user can undo a changeset.
     *
     * @param Changeset $changeset
     * @param User $user
     * @return bool
     */
    public function canUndo(Changeset $changeset, User $user): bool
    {
        return $user->can('contentOps:undo')
            && $this->canView($changeset, $user)
            && in_array($changeset->status, [ChangesetStatus::Applied, ChangesetStatus::PartiallyUndone], true)
            && ($changeset->getCount(ChangeStatus::Applied->value) + $changeset->getCount(ChangeStatus::UndoConflict->value)) > 0;
    }

    /**
     * Returns whether a user can apply a previewed changeset (only their own).
     *
     * @param Changeset $changeset
     * @param User $user
     * @return bool
     */
    public function canApply(Changeset $changeset, User $user): bool
    {
        return $changeset->status === ChangesetStatus::Previewed
            && ($changeset->userId === $user->id || $user->admin)
            && $user->can('contentOps:bulkEdit');
    }

    /**
     * Deletes old changesets: finished ones after the retention period, unapplied previews after a day.
     *
     * @return int Number of changesets deleted
     */
    public function purgeOld(): int
    {
        $settings = ContentOps::getInstance()->getSettings();
        $db = Craft::$app->getDb();

        $deleted = $db->createCommand()->delete(Table::CHANGESETS, [
            'and',
            ['status' => ChangesetStatus::Previewed->value],
            ['<', 'dateCreated', Db::prepareDateForDb(new DateTime('-1 day'))],
        ])->execute();

        if ($settings->historyRetentionDays) {
            $deleted += $db->createCommand()->delete(Table::CHANGESETS, [
                'and',
                ['status' => [ChangesetStatus::Applied->value, ChangesetStatus::Failed->value, ChangesetStatus::Undone->value, ChangesetStatus::PartiallyUndone->value]],
                ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-{$settings->historyRetentionDays} days"))],
            ])->execute();
        }

        return $deleted;
    }

    /**
     * Returns a changeset's change rows.
     *
     * @param int $changesetId
     * @param ChangeStatus|null $status
     * @param int|null $limit
     * @param int $offset
     * @return Change[]
     */
    public function getChanges(int $changesetId, ?ChangeStatus $status = null, ?int $limit = null, int $offset = 0): array
    {
        $query = Change::find()
            ->where(['changesetId' => $changesetId])
            ->orderBy(['elementId' => SORT_ASC, 'siteId' => SORT_ASC, 'id' => SORT_ASC])
            ->offset($offset)
            ->limit($limit);

        if ($status !== null) {
            $query->andWhere(['status' => $status->value]);
        }

        /** @var Change[] */
        return $query->all();
    }

    /**
     * Recomputes a changeset's per-status counts from its change rows.
     *
     * @param int $changesetId
     * @param array<string, int> $extra Counts that aren't derived from rows (`total`, `unchanged`); omitted keys are kept
     * @throws \yii\db\Exception
     */
    public function refreshCounts(int $changesetId, array $extra = []): void
    {
        $record = $this->_getRecord($changesetId);
        $counts = Json::decode($record->counts ?? '{}') ?: [];

        foreach (ChangeStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $rows = (new Query())
            ->select(['status', 'count' => 'COUNT(*)'])
            ->from(Table::CHANGES)
            ->where(['changesetId' => $changesetId])
            ->groupBy(['status'])
            ->all();

        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['count'];
        }

        $record->counts = Json::encode(array_merge($counts, $extra));
        $record->save(false);
    }

    /**
     * Pushes a job that applies a previewed changeset.
     *
     * @param int $changesetId
     * @throws InvalidArgumentException if the changeset can't be applied
     * @throws \yii\db\Exception
     */
    public function queueApply(int $changesetId): void
    {
        $this->_requireStatus($changesetId, [ChangesetStatus::Previewed]);
        $this->setStatus($changesetId, ChangesetStatus::Queued);
        Queue::push(new ApplyChangeset([
            'changesetId' => $changesetId,
            'batchSize' => ContentOps::getInstance()->getSettings()->batchSize,
        ]));
    }

    /**
     * Pushes a job that undoes an applied changeset.
     *
     * @param int $changesetId
     * @param bool $force
     * @throws InvalidArgumentException if the changeset can't be undone
     * @throws \yii\db\Exception
     */
    public function queueUndo(int $changesetId, bool $force = false): void
    {
        $this->_requireStatus($changesetId, [ChangesetStatus::Applied, ChangesetStatus::Failed, ChangesetStatus::PartiallyUndone]);
        $this->setStatus($changesetId, ChangesetStatus::Queued);
        Queue::push(new UndoChangeset([
            'changesetId' => $changesetId,
            'force' => $force,
            'batchSize' => ContentOps::getInstance()->getSettings()->batchSize,
        ]));
    }

    /**
     * Applies a previewed changeset synchronously (console).
     *
     * @param int $changesetId
     * @param callable|null $onProgress `fn(int $done, int $total)`
     * @throws InvalidArgumentException if the changeset can't be applied
     * @throws \yii\db\Exception
     */
    public function applyNow(int $changesetId, ?callable $onProgress = null): void
    {
        $this->_requireStatus($changesetId, [ChangesetStatus::Previewed, ChangesetStatus::Queued]);
        $this->startApply($changesetId);
        $changeset = $this->getChangesetById($changesetId);
        $applier = ContentOps::getInstance()->getApplier();

        $this->_eachElementSite($changesetId, function(int $elementId, int $siteId) use ($applier, $changeset) {
            $applier->applyElement($changeset, $elementId, $siteId);
        }, $onProgress);

        $this->finishApply($changesetId);
    }

    /**
     * Undoes an applied changeset synchronously (console).
     *
     * @param int $changesetId
     * @param bool $force
     * @param callable|null $onProgress `fn(int $done, int $total)`
     * @throws InvalidArgumentException if the changeset can't be undone
     * @throws \yii\db\Exception
     */
    public function undoNow(int $changesetId, bool $force = false, ?callable $onProgress = null): void
    {
        $this->_requireStatus($changesetId, [ChangesetStatus::Applied, ChangesetStatus::Failed, ChangesetStatus::PartiallyUndone, ChangesetStatus::Queued]);
        $this->setStatus($changesetId, ChangesetStatus::Undoing);
        $changeset = $this->getChangesetById($changesetId);
        $applier = ContentOps::getInstance()->getApplier();

        $this->_eachElementSite($changesetId, function(int $elementId, int $siteId) use ($applier, $changeset, $force) {
            $applier->undoElement($changeset, $elementId, $siteId, $force);
        }, $onProgress);

        $this->finishUndo($changesetId);
    }

    /**
     * Marks a changeset as running.
     *
     * @param int $changesetId
     * @throws \yii\db\Exception
     */
    public function startApply(int $changesetId): void
    {
        $this->setStatus($changesetId, ChangesetStatus::Running);
    }

    /**
     * Updates counts and sets the final status after applying.
     *
     * @param int $changesetId
     * @throws \yii\db\Exception
     */
    public function finishApply(int $changesetId): void
    {
        $this->refreshCounts($changesetId);
        $changeset = $this->getChangesetById($changesetId);
        $failedEverything = $changeset->getCount(ChangeStatus::Applied->value) === 0 && $changeset->getCount(ChangeStatus::Failed->value) > 0;

        $this->setStatus($changesetId, $failedEverything ? ChangesetStatus::Failed : ChangesetStatus::Applied, ['dateApplied' => new DateTime()]);
    }

    /**
     * Updates counts and sets the final status after undoing.
     *
     * @param int $changesetId
     * @throws \yii\db\Exception
     */
    public function finishUndo(int $changesetId): void
    {
        $this->refreshCounts($changesetId);
        $changeset = $this->getChangesetById($changesetId);
        $remaining = $changeset->getCount(ChangeStatus::Applied->value) + $changeset->getCount(ChangeStatus::UndoConflict->value);

        $this->setStatus($changesetId, $remaining > 0 ? ChangesetStatus::PartiallyUndone : ChangesetStatus::Undone, ['dateUndone' => new DateTime()]);
    }

    /**
     * Merges values into a changeset's options.
     *
     * @param int $changesetId
     * @param array<string, mixed> $options
     * @throws \yii\db\Exception
     */
    public function setOptions(int $changesetId, array $options): void
    {
        $record = $this->_getRecord($changesetId);
        $record->options = Json::encode(array_merge(Json::decode($record->options ?? '{}') ?: [], $options));
        $record->save(false);
    }

    /**
     * Sets a changeset's status.
     *
     * @param int $changesetId
     * @param ChangesetStatus $status
     * @param array<string, mixed> $attributes Other attributes to set (`dateApplied`, `dateUndone`, `error`)
     * @throws \yii\db\Exception
     */
    public function setStatus(int $changesetId, ChangesetStatus $status, array $attributes = []): void
    {
        $record = $this->_getRecord($changesetId);
        $record->status = $status->value;

        foreach ($attributes as $name => $value) {
            $record->$name = $value instanceof DateTime ? Db::prepareDateForDb($value) : $value;
        }

        $record->save(false);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param int $changesetId
     * @return ChangesetRecord
     * @throws InvalidArgumentException
     */
    private function _getRecord(int $changesetId): ChangesetRecord
    {
        $record = ChangesetRecord::findOne($changesetId);

        if ($record === null) {
            throw new InvalidArgumentException("Changeset $changesetId doesn’t exist.");
        }

        return $record;
    }

    /**
     * @param int $changesetId
     * @param ChangesetStatus[] $allowed
     * @throws InvalidArgumentException
     */
    private function _requireStatus(int $changesetId, array $allowed): void
    {
        $status = ChangesetStatus::from($this->_getRecord($changesetId)->status);

        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'Changeset %s is %s; expected %s.',
                $changesetId,
                $status->value,
                implode(' or ', array_map(fn(ChangesetStatus $s) => $s->value, $allowed)),
            ));
        }
    }

    /**
     * @param int $changesetId
     * @param callable $callback `fn(int $elementId, int $siteId)`
     * @param callable|null $onProgress `fn(int $done, int $total)`
     */
    private function _eachElementSite(int $changesetId, callable $callback, ?callable $onProgress): void
    {
        $batcher = new ElementSiteBatcher($changesetId);
        $total = $batcher->count();
        $batchSize = ContentOps::getInstance()->getSettings()->batchSize;
        $done = 0;

        for ($offset = 0; $offset < $total; $offset += $batchSize) {
            foreach ($batcher->getSlice($offset, $batchSize) as $item) {
                $callback($item['elementId'], $item['siteId']);
                $done++;

                if ($onProgress !== null) {
                    $onProgress($done, $total);
                }
            }

            ContentOps::getInstance()->getApplier()->flushDeferredPruning($changesetId);
            gc_collect_cycles();
        }
    }
}
