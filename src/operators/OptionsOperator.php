<?php

namespace romanavr\contentops\operators;

use craft\base\ElementInterface;
use craft\fields\BaseOptionsField;
use craft\fields\Checkboxes;
use craft\fields\MultiSelect;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Target;
use yii\base\InvalidArgumentException;

/**
 * Options operator: Dropdown, Radio Buttons, Button Group (single value) and Checkboxes, Multi-select (multiple values).
 *
 * `set` takes `value` (single) or `values` (multi). `add`/`remove` take `values` and only apply to multi-option fields.
 * Option values are validated against the field when the changeset is previewed.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class OptionsOperator extends BaseOperator
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'options';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Options';
    }

    /**
     * Returns whether a field holds multiple values.
     *
     * @param BaseOptionsField $field
     * @return bool
     */
    public static function isMulti(BaseOptionsField $field): bool
    {
        return $field instanceof Checkboxes || $field instanceof MultiSelect;
    }

    /**
     * @inheritdoc
     */
    public function getOperations(): array
    {
        return [
            'set' => 'Set to',
            'add' => 'Add options',
            'remove' => 'Remove options',
            'clear' => 'Clear',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getInputs(string $operation, Target $target): array
    {
        if (!$target->field instanceof BaseOptionsField || $operation === 'clear') {
            return [];
        }

        $options = array_values(array_filter(array_map(
            fn(array $option) => isset($option['value']) ? ['label' => $option['label'], 'value' => (string)$option['value']] : null,
            $target->field->options,
        )));

        if ($operation === 'set' && !self::isMulti($target->field)) {
            return [['name' => 'value', 'type' => 'select', 'label' => 'Option', 'options' => $options]];
        }

        return [['name' => 'values', 'type' => 'checkboxes', 'label' => 'Options', 'options' => $options]];
    }

    /**
     * Only multi-option fields support adding and removing options.
     *
     * @param Target $target
     * @return array<string, string>
     */
    public function getOperationsForTarget(Target $target): array
    {
        $operations = $this->getOperations();

        if ($target->field instanceof BaseOptionsField && !self::isMulti($target->field)) {
            unset($operations['add'], $operations['remove']);
        }

        return $operations;
    }

    /**
     * @inheritdoc
     */
    public function supports(Target $target): bool
    {
        return $target->field instanceof BaseOptionsField;
    }

    /**
     * @inheritdoc
     */
    public function validateOperation(Operation $operation): void
    {
        parent::validateOperation($operation);

        match ($operation->operation) {
            'add', 'remove' => $this->_values($operation),
            'set' => array_key_exists('values', $operation->options) ? $this->_values($operation) : $this->requireOption($operation, 'value'),
            default => null,
        };
    }

    /**
     * @inheritdoc
     */
    public function apply(mixed $value, Operation $operation, ?ElementInterface $element = null): mixed
    {
        $field = $this->_field($operation, $element);
        $multi = $field !== null ? self::isMulti($field) : is_array($value);

        if ($field !== null) {
            $this->_assertValidOptions($field, $operation);
        }

        if (!$multi) {
            $new = match ($operation->operation) {
                'set' => (string)($operation->options['value'] ?? ($this->_values($operation)[0] ?? '')),
                'clear' => null,
                default => throw new InvalidArgumentException(sprintf('“%s” only works on fields with multiple options.', $operation->operation)),
            };

            return $new === $value || ($new === null && ($value === null || $value === '')) ? $value : $new;
        }

        $current = is_array($value) ? array_values(array_map('strval', $value)) : [];

        $new = match ($operation->operation) {
            'set' => array_key_exists('values', $operation->options) ? $this->_values($operation) : [(string)$operation->options['value']],
            'add' => array_values(array_unique([...$current, ...$this->_values($operation)])),
            'remove' => array_values(array_diff($current, $this->_values($operation))),
            'clear' => [],
            default => throw new InvalidArgumentException("Unknown operation \"$operation->operation\"."),
        };

        // Keep the field's option order, as Craft does when it serializes.
        if ($field !== null) {
            $order = array_flip(array_map(fn(array $option) => (string)($option['value'] ?? ''), $field->options));
            usort($new, fn($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
        }

        return $new === $current ? $value : $new;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param Operation $operation
     * @return string[]
     * @throws InvalidArgumentException
     */
    private function _values(Operation $operation): array
    {
        $values = $this->requireOption($operation, 'values');

        if (!is_array($values)) {
            throw new InvalidArgumentException('The "values" option must be a list.');
        }

        return array_values(array_map('strval', $values));
    }

    /**
     * @param Operation $operation
     * @param ElementInterface|null $element
     * @return BaseOptionsField|null
     */
    private function _field(Operation $operation, ?ElementInterface $element): ?BaseOptionsField
    {
        $field = $element?->getFieldLayout()?->getFieldByHandle($operation->target);

        return $field instanceof BaseOptionsField ? $field : null;
    }

    /**
     * @param BaseOptionsField $field
     * @param Operation $operation
     * @throws InvalidArgumentException
     */
    private function _assertValidOptions(BaseOptionsField $field, Operation $operation): void
    {
        $valid = array_filter(array_map(fn(array $option) => isset($option['value']) ? (string)$option['value'] : null, $field->options), fn($v) => $v !== null);
        $given = array_key_exists('values', $operation->options) ? $this->_values($operation) : (isset($operation->options['value']) ? [(string)$operation->options['value']] : []);
        $invalid = array_diff($given, $valid);

        if ($invalid) {
            throw new InvalidArgumentException(sprintf('“%s” isn’t an option of %s.', implode('”, “', $invalid), $field->name));
        }
    }
}
