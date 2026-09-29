<?php

namespace romanavr\contentops\operators;

use craft\base\ElementInterface;
use craft\fields\BaseRelationField;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Target;
use yii\base\InvalidArgumentException;

/**
 * Relation operator: Entries, Categories, Assets, Tags, Users fields and the entry `authorIds` attribute.
 *
 * Undo is surgical: it takes out exactly what the changeset added and puts back what it removed,
 * keeping any relations people added or removed by hand in the meantime.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class RelationOperator extends BaseOperator
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'relation';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Relations';
    }

    /**
     * @inheritdoc
     */
    public function getOperations(): array
    {
        return [
            'replace' => 'Replace with',
            'add' => 'Add',
            'remove' => 'Remove',
            'clear' => 'Clear',
        ];
    }

    /**
     * @inheritdoc
     */
    public function supports(Target $target): bool
    {
        return $target->field instanceof BaseRelationField || $target->attribute === 'authorIds';
    }

    /**
     * @inheritdoc
     */
    public function validateOperation(Operation $operation): void
    {
        parent::validateOperation($operation);

        if ($operation->operation !== 'clear') {
            $this->_ids($operation);
        }
    }

    /**
     * @inheritdoc
     */
    public function apply(mixed $value, Operation $operation, ?ElementInterface $element = null): mixed
    {
        $current = self::_normalizeIds($value);

        $new = match ($operation->operation) {
            'replace' => $this->_ids($operation),
            'add' => array_values(array_unique([...$current, ...$this->_ids($operation)])),
            'remove' => array_values(array_diff($current, $this->_ids($operation))),
            'clear' => [],
            default => throw new InvalidArgumentException("Unknown operation \"$operation->operation\"."),
        };

        return $new === $current ? $value : $new;
    }

    /**
     * @inheritdoc
     */
    public function revert(mixed $current, mixed $old, mixed $new): mixed
    {
        $old = self::_normalizeIds($old);
        $new = self::_normalizeIds($new);
        $added = array_diff($new, $old);
        $removed = array_diff($old, $new);

        $restored = array_values(array_diff(self::_normalizeIds($current), $added));

        foreach ($removed as $id) {
            if (!in_array($id, $restored, true)) {
                $restored[] = $id;
            }
        }

        return $restored;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param mixed $value
     * @return int[]
     */
    private static function _normalizeIds(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('intval', $value)) : [];
    }

    /**
     * @param Operation $operation
     * @return int[]
     * @throws InvalidArgumentException
     */
    private function _ids(Operation $operation): array
    {
        $ids = $this->requireOption($operation, 'ids');

        if (!is_array($ids) || array_filter($ids, fn($id) => !is_numeric($id))) {
            throw new InvalidArgumentException('The "ids" option must be a list of element IDs.');
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
