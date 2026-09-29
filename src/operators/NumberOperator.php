<?php

namespace romanavr\contentops\operators;

use craft\base\ElementInterface;
use craft\fields\Number;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Target;
use yii\base\InvalidArgumentException;

/**
 * Number operator. Empty values stay empty when increasing/decreasing.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class NumberOperator extends BaseOperator
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'number';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Number';
    }

    /**
     * @inheritdoc
     */
    public function getOperations(): array
    {
        return [
            'set' => 'Set to',
            'clear' => 'Clear',
            'increase' => 'Increase by',
            'decrease' => 'Decrease by',
            'increasePercent' => 'Increase by %',
            'decreasePercent' => 'Decrease by %',
        ];
    }

    /**
     * @inheritdoc
     */
    public function supports(Target $target): bool
    {
        return $target->field instanceof Number;
    }

    /**
     * @inheritdoc
     */
    public function validateOperation(Operation $operation): void
    {
        parent::validateOperation($operation);

        if ($operation->operation === 'clear') {
            return;
        }

        if (!is_numeric($this->requireOption($operation, 'value'))) {
            throw new InvalidArgumentException('The "value" option must be a number.');
        }
    }

    /**
     * @inheritdoc
     */
    public function apply(mixed $value, Operation $operation, ?ElementInterface $element = null): mixed
    {
        $by = (float)($operation->options['value'] ?? 0);

        if ($operation->operation === 'set') {
            return $this->_normalize($by, $value);
        }

        if ($operation->operation === 'clear' || $value === null || $value === '') {
            return $operation->operation === 'clear' ? null : $value;
        }

        $current = (float)$value;

        $new = match ($operation->operation) {
            'increase' => $current + $by,
            'decrease' => $current - $by,
            'increasePercent' => $current * (1 + $by / 100),
            'decreasePercent' => $current * (1 - $by / 100),
            default => throw new InvalidArgumentException("Unknown operation \"$operation->operation\"."),
        };

        return $this->_normalize($new, $value);
    }

    // Private Methods
    // =========================================================================

    /**
     * Rounds away float noise and keeps ints as ints, so `10 + 5` is stored as `15`, not `15.0`.
     *
     * @param float $number
     * @param mixed $original
     * @return int|float|string
     */
    private function _normalize(float $number, mixed $original): int|float|string
    {
        $number = round($number, 10);

        if (floor($number) === $number && abs($number) < PHP_INT_MAX) {
            $number = (int)$number;
        }

        // Keep the stored type (Craft may store numbers as strings) when the value is unchanged.
        return is_numeric($original) && (float)$original === (float)$number ? $original : $number;
    }
}
