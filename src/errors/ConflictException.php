<?php

namespace romanavr\contentops\errors;

use yii\base\Exception;

/**
 * Thrown when a stored value no longer matches what a changeset expects.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class ConflictException extends Exception
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Conflict';
    }
}
