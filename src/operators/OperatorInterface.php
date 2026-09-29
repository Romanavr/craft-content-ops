<?php

namespace romanavr\contentops\operators;

use romanavr\contentops\errors\ConflictException;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Target;
use yii\base\InvalidArgumentException;

/**
 * An operator knows how to transform one family of values (text, numbers, relations…).
 *
 * Operators work on *serialized* values (what `FieldInterface::serializeValue()` returns), so they're pure,
 * easy to test, and their input/output is exactly what the changeset log stores.
 *
 * @author Romanavr
 * @since 1.0.0
 */
interface OperatorInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the operator's handle, used in {@see Operation::$operator}.
     *
     * @return string
     */
    public static function handle(): string;

    /**
     * Returns the operator's display name.
     *
     * @return string
     */
    public static function displayName(): string;

    /**
     * Returns the operations this operator supports, as `handle => label`.
     *
     * @return array<string, string>
     */
    public function getOperations(): array;

    /**
     * Returns whether the operator can edit the given target.
     *
     * @param Target $target
     * @return bool
     */
    public function supports(Target $target): bool;

    /**
     * Validates an operation's options before anything is previewed.
     *
     * @param Operation $operation
     * @throws InvalidArgumentException if the operation or its options are invalid
     */
    public function validateOperation(Operation $operation): void;

    /**
     * Returns the new serialized value.
     *
     * @param mixed $value The current serialized value
     * @param Operation $operation
     * @return mixed
     */
    public function apply(mixed $value, Operation $operation): mixed;

    /**
     * Returns the serialized value to restore on undo.
     *
     * @param mixed $current The value stored now
     * @param mixed $old The value before the changeset was applied
     * @param mixed $new The value the changeset wrote
     * @return mixed
     * @throws ConflictException if the value was changed since the changeset was applied
     */
    public function revert(mixed $current, mixed $old, mixed $new): mixed;
}
