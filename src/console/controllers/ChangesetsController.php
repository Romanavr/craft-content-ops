<?php

namespace romanavr\contentops\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetType;
use romanavr\contentops\enums\ChangeStatus;
use romanavr\contentops\helpers\Values;
use romanavr\contentops\models\Changeset;
use yii\base\InvalidArgumentException;
use yii\console\ExitCode;

/**
 * Lists, inspects, applies and undoes changesets.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class ChangesetsController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Push a queue job instead of running synchronously.
     */
    public bool $queue = false;

    /**
     * @var bool Undo: restore old values even where they were edited after the changeset was applied.
     */
    public bool $force = false;

    /**
     * @var int Max rows to show.
     */
    public int $limit = 50;

    /**
     * @var string|null View: only show changes with this status.
     */
    public ?string $status = null;

    /**
     * @inheritdoc
     */
    public $defaultAction = 'list';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'apply' => [...$options, 'queue'],
            'undo' => [...$options, 'queue', 'force'],
            'view' => [...$options, 'limit', 'status'],
            'list' => [...$options, 'limit'],
            default => $options,
        };
    }

    /**
     * Lists recent changesets.
     *
     * @return int
     */
    public function actionList(): int
    {
        $rows = [];

        foreach (ContentOps::getInstance()->getChangesets()->getRecentChangesets($this->limit) as $changeset) {
            $rows[] = [
                $changeset->id,
                $changeset->type->value,
                $changeset->status->value,
                self::describeOperations($changeset),
                self::describeCounts($changeset),
                $changeset->dateCreated?->format('Y-m-d H:i'),
            ];
        }

        if (empty($rows)) {
            $this->stdout("No changesets yet.\n");
            return ExitCode::OK;
        }

        $this->table(['ID', 'Type', 'Status', 'Operations', 'Counts', 'Created'], $rows);

        return ExitCode::OK;
    }

    /**
     * Shows a changeset and its changes.
     *
     * @param int $id
     * @return int
     */
    public function actionView(int $id): int
    {
        $changeset = ContentOps::getInstance()->getChangesets()->getChangesetById($id);

        if ($changeset === null) {
            $this->stderr("Changeset $id doesn’t exist.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        self::printChangeset($this, $changeset, $this->limit, $this->status ? ChangeStatus::from($this->status) : null);

        return ExitCode::OK;
    }

    /**
     * Applies a previewed changeset.
     *
     * @param int $id
     * @return int
     */
    public function actionApply(int $id): int
    {
        $changesets = ContentOps::getInstance()->getChangesets();

        try {
            if ($this->queue) {
                $changesets->queueApply($id);
                $this->stdout("Queued changeset #$id.\n", Console::FG_GREEN);
                return ExitCode::OK;
            }

            $changesets->applyNow($id, fn(int $done, int $total) => Console::updateProgress($done, $total));
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        Console::endProgress();
        $changeset = $changesets->getChangesetById($id);
        $this->stdout("Changeset #$id: {$changeset->status->value} — " . self::describeCounts($changeset) . "\n", Console::FG_GREEN);
        $this->stdout("Undo with: craft content-ops/changesets/undo $id\n");

        return ExitCode::OK;
    }

    /**
     * Undoes an applied changeset.
     *
     * @param int $id
     * @return int
     */
    public function actionUndo(int $id): int
    {
        $changesets = ContentOps::getInstance()->getChangesets();

        try {
            if ($this->queue) {
                $changesets->queueUndo($id, $this->force);
                $this->stdout("Queued undo of changeset #$id.\n", Console::FG_GREEN);
                return ExitCode::OK;
            }

            $changesets->undoNow($id, $this->force, fn(int $done, int $total) => Console::updateProgress($done, $total));
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        Console::endProgress();
        $changeset = $changesets->getChangesetById($id);
        $this->stdout("Changeset #$id: {$changeset->status->value} — " . self::describeCounts($changeset) . "\n", Console::FG_GREEN);

        if ($changeset->getCount(ChangeStatus::UndoConflict->value) > 0) {
            $this->stdout("Some values were edited after the changeset ran and were left alone. Overwrite them with --force.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Prints a changeset summary and a table of its changes.
     *
     * @param Controller $controller
     * @param Changeset $changeset
     * @param int $limit
     * @param ChangeStatus|null $status
     */
    public static function printChangeset(Controller $controller, Changeset $changeset, int $limit, ?ChangeStatus $status = null): void
    {
        $controller->stdout("Changeset #$changeset->id ({$changeset->status->value})\n", Console::BOLD);
        $controller->stdout('Operations: ' . self::describeOperations($changeset) . "\n");
        $controller->stdout('Counts:     ' . self::describeCounts($changeset) . "\n\n");

        $changes = ContentOps::getInstance()->getChangesets()->getChanges($changeset->id, $status, $limit);

        if (empty($changes)) {
            $controller->stdout("No changes to show.\n");
            return;
        }

        $rows = array_map(fn($change) => [
            $change->elementId,
            $change->siteId,
            $change->target,
            self::_truncate(Values::decode($change->oldValue)),
            self::_truncate(Values::decode($change->newValue)),
            $change->status . ($change->error ? ": $change->error" : ''),
        ], $changes);

        $controller->table(['Element', 'Site', 'Target', 'Before', 'After', 'Status'], $rows);
    }

    /**
     * @param Changeset $changeset
     * @return string
     */
    public static function describeOperations(Changeset $changeset): string
    {
        if ($changeset->type === ChangesetType::FindReplace && $changeset->operations) {
            $options = $changeset->operations[0]->options;

            return sprintf('find “%s” → “%s”%s in %d fields', $options['find'] ?? '', $options['replace'] ?? '', !empty($options['regex']) ? ' (regex)' : '', count($changeset->operations));
        }

        return implode('; ', array_map(
            fn($operation) => "$operation->target: $operation->operation" . ($operation->options ? ' ' . json_encode($operation->options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''),
            $changeset->operations,
        ));
    }

    /**
     * @param Changeset $changeset
     * @return string
     */
    public static function describeCounts(Changeset $changeset): string
    {
        $parts = [];

        foreach ($changeset->counts as $key => $count) {
            if ($count > 0) {
                $parts[] = "$key: $count";
            }
        }

        return implode(', ', $parts) ?: 'nothing';
    }

    // Private Methods
    // =========================================================================

    /**
     * @param mixed $value
     * @return string
     */
    private static function _truncate(mixed $value): string
    {
        $string = is_string($value) ? $value : (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $string = preg_replace('/\s+/', ' ', $string);

        return mb_strlen($string) > 40 ? mb_substr($string, 0, 39) . '…' : $string;
    }
}
