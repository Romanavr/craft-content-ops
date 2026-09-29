<?php

namespace romanavr\contentops\operators;

use craft\base\ElementInterface;
use romanavr\contentops\errors\ConflictException;
use romanavr\contentops\helpers\Values;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Target;
use yii\base\InvalidArgumentException;

/**
 * Base operator: operation validation and whole-value undo.
 *
 * @author Romanavr
 * @since 1.0.0
 */
abstract class BaseOperator implements OperatorInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function validateOperation(Operation $operation): void
    {
        if (!isset($this->getOperations()[$operation->operation])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown operation "%s" for the %s operator. Valid: %s.',
                $operation->operation,
                static::handle(),
                implode(', ', array_keys($this->getOperations())),
            ));
        }
    }

    /**
     * Returns the operations available for a specific target (defaults to all of them).
     *
     * @param Target $target
     * @return array<string, string>
     */
    public function getOperationsForTarget(Target $target): array
    {
        return $this->getOperations();
    }

    /**
     * @inheritdoc
     */
    public function mightChange(ElementInterface $element, Target $target, Operation $operation): bool
    {
        return true;
    }

    /**
     * Restores the old value if the current value is still exactly what the changeset wrote.
     * Operators whose changes can be undone surgically (e.g. relation add/remove) should override this.
     *
     * @inheritdoc
     */
    public function revert(mixed $current, mixed $old, mixed $new): mixed
    {
        if (!Values::equal($current, $new)) {
            throw new ConflictException('The value was changed after the changeset was applied.');
        }

        return $old;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns a required option, or throws.
     *
     * @param Operation $operation
     * @param string $name
     * @return mixed
     * @throws InvalidArgumentException
     */
    protected function requireOption(Operation $operation, string $name): mixed
    {
        if (!array_key_exists($name, $operation->options)) {
            throw new InvalidArgumentException(sprintf('The "%s" operation requires a "%s" option.', $operation->operation, $name));
        }

        return $operation->options[$name];
    }
}
