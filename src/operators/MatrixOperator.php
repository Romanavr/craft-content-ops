<?php

namespace romanavr\contentops\operators;

use craft\base\ElementInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Target;
use yii\base\InvalidArgumentException;

/**
 * Matrix operator: adds and removes nested entries.
 *
 * Values are ordered lists of blocks (`id`, `type`, `enabled`, `title`, `slug`, `fields`), see `Targets`.
 * Undo is surgical: it removes only the blocks the changeset added and restores only the ones it removed,
 * keeping blocks people added, removed or edited by hand in the meantime.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class MatrixOperator extends BaseOperator
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'matrix';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Matrix';
    }

    /**
     * @inheritdoc
     */
    public function getOperations(): array
    {
        return [
            'add' => 'Add a nested entry',
            'remove' => 'Remove nested entries',
        ];
    }

    /**
     * @inheritdoc
     */
    public function supports(Target $target): bool
    {
        return $target->field instanceof Matrix;
    }

    /**
     * @inheritdoc
     */
    public function getInputs(string $operation, Target $target): array
    {
        if (!$target->field instanceof Matrix) {
            return [];
        }

        $types = [];
        $valueInputs = [];

        foreach ($target->field->getEntryTypes() as $entryType) {
            $types[] = ['label' => $entryType->name, 'value' => $entryType->handle];

            if ($operation !== 'add') {
                continue;
            }

            foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
                if ($field instanceof PlainText || $field instanceof CkeditorField) {
                    $valueInputs[] = [
                        'name' => "values.$field->handle",
                        'type' => $field instanceof CkeditorField ? 'textarea' : 'text',
                        'label' => $field->name,
                        'showWhen' => ['type' => $entryType->handle],
                    ];
                }
            }
        }

        $inputs = [['name' => 'type', 'type' => 'select', 'label' => 'Entry type', 'options' => $types]];

        if ($operation === 'add') {
            $inputs[] = ['name' => 'position', 'type' => 'select', 'label' => 'Position', 'options' => [
                ['label' => 'At the end', 'value' => 'end'],
                ['label' => 'At the start', 'value' => 'start'],
            ]];

            return [...$inputs, ...$valueInputs];
        }

        $inputs[] = ['name' => 'contains', 'type' => 'text', 'label' => 'Only if its text contains (leave blank for all)'];

        return $inputs;
    }

    /**
     * @inheritdoc
     */
    public function validateOperation(Operation $operation): void
    {
        parent::validateOperation($operation);

        $type = $this->requireOption($operation, 'type');

        if (!is_string($type) || $type === '') {
            throw new InvalidArgumentException('The "type" option must be an entry type handle.');
        }

        if ($operation->operation === 'add' && isset($operation->options['values']) && !is_array($operation->options['values'])) {
            throw new InvalidArgumentException('The "values" option must be a map of field handles to values.');
        }
    }

    /**
     * @inheritdoc
     */
    public function apply(mixed $value, Operation $operation, ?ElementInterface $element = null): mixed
    {
        $blocks = is_array($value) ? array_values($value) : [];
        $type = (string)$operation->options['type'];
        $field = $element?->getFieldLayout()?->getFieldByHandle($operation->target);

        if ($field instanceof Matrix && !in_array($type, array_map(fn($entryType) => $entryType->handle, $field->getEntryTypes()), true)) {
            throw new InvalidArgumentException("“{$type}” isn’t an entry type of {$field->name}.");
        }

        if ($operation->operation === 'add') {
            $block = [
                'id' => null,
                'type' => $type,
                'enabled' => true,
                'title' => null,
                'slug' => null,
                'fields' => array_filter((array)($operation->options['values'] ?? []), fn($v) => $v !== null && $v !== ''),
            ];

            return ($operation->options['position'] ?? 'end') === 'start' ? [$block, ...$blocks] : [...$blocks, $block];
        }

        if ($operation->operation === 'remove') {
            $contains = trim((string)($operation->options['contains'] ?? ''));
            $kept = array_values(array_filter($blocks, fn(array $block) => !(
                ($block['type'] ?? null) === $type &&
                ($contains === '' || mb_stripos(self::_text($block), $contains) !== false)
            )));

            return count($kept) === count($blocks) ? $value : $kept;
        }

        throw new InvalidArgumentException("Unknown operation \"$operation->operation\".");
    }

    /**
     * @inheritdoc
     */
    public function revert(mixed $current, mixed $old, mixed $new): mixed
    {
        $current = is_array($current) ? array_values($current) : [];
        $old = is_array($old) ? array_values($old) : [];
        $new = is_array($new) ? array_values($new) : [];

        $oldIds = array_column($old, 'id');
        $newIds = array_column($new, 'id');
        $added = array_diff($newIds, $oldIds);
        $removed = array_filter($old, fn(array $block) => !in_array($block['id'], $newIds, true));

        // Take out what the changeset added…
        $restored = array_values(array_filter($current, fn(array $block) => !in_array($block['id'] ?? null, $added, true)));

        // …and put back what it removed, next to the block that preceded it originally.
        foreach ($removed as $block) {
            $index = array_search($block['id'], $oldIds, true);
            $position = 0;

            for ($i = $index - 1; $i >= 0; $i--) {
                $previous = array_search($oldIds[$i], array_column($restored, 'id'), true);

                if ($previous !== false) {
                    $position = $previous + 1;
                    break;
                }
            }

            array_splice($restored, $position, 0, [$block]);
        }

        return $restored;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a block's searchable text (its title and text-like field values).
     *
     * @param array<string, mixed> $block
     * @return string
     */
    private static function _text(array $block): string
    {
        $parts = [(string)($block['title'] ?? '')];

        array_walk_recursive($block['fields'], function($value) use (&$parts) {
            if (is_string($value)) {
                $parts[] = strip_tags($value);
            }
        });

        return implode(' ', $parts);
    }
}
