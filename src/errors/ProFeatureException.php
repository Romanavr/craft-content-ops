<?php

namespace romanavr\contentops\errors;

use yii\base\InvalidArgumentException;

/**
 * Thrown when a Pro-only feature is used in the Lite edition.
 *
 * Extends InvalidArgumentException so callers that already report invalid input (CP, console, MCP) show it as-is.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class ProFeatureException extends InvalidArgumentException
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Pro feature';
    }
}
