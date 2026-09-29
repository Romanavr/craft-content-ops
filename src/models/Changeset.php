<?php

namespace romanavr\contentops\models;

use craft\base\Model;
use craft\helpers\Json;
use DateTime;
use romanavr\contentops\enums\ChangesetStatus;
use romanavr\contentops\enums\ChangesetType;
use romanavr\contentops\records\Changeset as ChangesetRecord;

/**
 * A recorded run of the pipeline: selection + operations, and counts of what happened.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Changeset extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var int|null
     */
    public ?int $id = null;

    /**
     * @var ChangesetType
     */
    public ChangesetType $type = ChangesetType::BulkEdit;

    /**
     * @var ChangesetStatus
     */
    public ChangesetStatus $status = ChangesetStatus::Previewed;

    /**
     * @var int|null The user who created it, or `null` for console/system runs.
     */
    public ?int $userId = null;

    /**
     * @var Selection
     */
    public Selection $selection;

    /**
     * @var Operation[]
     */
    public array $operations = [];

    /**
     * @var array<string, mixed> Run options (e.g. `createRevisions`).
     */
    public array $options = [];

    /**
     * @var array<string, int> `total` (element/site pairs examined), `unchanged`, and one count per {@see \romanavr\contentops\enums\ChangeStatus} value.
     */
    public array $counts = [];

    /**
     * @var string|null
     */
    public ?string $error = null;

    /**
     * @var DateTime|null
     */
    public ?DateTime $dateApplied = null;

    /**
     * @var DateTime|null
     */
    public ?DateTime $dateUndone = null;

    /**
     * @var DateTime|null
     */
    public ?DateTime $dateCreated = null;

    // Public Methods
    // =========================================================================

    /**
     * Creates a model from a record.
     *
     * @param ChangesetRecord $record
     * @return self
     */
    public static function fromRecord(ChangesetRecord $record): self
    {
        return new self([
            'id' => $record->id,
            'type' => ChangesetType::from($record->type),
            'status' => ChangesetStatus::from($record->status),
            'userId' => $record->userId,
            'selection' => new Selection(Json::decode($record->selection)),
            'operations' => array_map(fn(array $config) => new Operation($config), Json::decode($record->operations)),
            'options' => Json::decode($record->options ?? '{}') ?: [],
            'counts' => Json::decode($record->counts ?? '{}') ?: [],
            'error' => $record->error,
            'dateApplied' => $record->dateApplied ? new DateTime($record->dateApplied) : null,
            'dateUndone' => $record->dateUndone ? new DateTime($record->dateUndone) : null,
            'dateCreated' => new DateTime($record->dateCreated),
        ]);
    }

    /**
     * Returns a count, or 0.
     *
     * @param string $key
     * @return int
     */
    public function getCount(string $key): int
    {
        return (int)($this->counts[$key] ?? 0);
    }

    /**
     * Returns the operations that target the given field handle or attribute, in order.
     *
     * @param string $target
     * @return Operation[]
     */
    public function getOperationsForTarget(string $target): array
    {
        return array_values(array_filter($this->operations, fn(Operation $operation) => $operation->target === $target));
    }
}
