<?php

namespace romanavr\contentops\models;

use craft\base\FieldInterface;
use craft\base\Model;

/**
 * A resolved operation target on a specific element: either a custom field (from the element's layout) or a native attribute.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Target extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The handle as given in the operation.
     */
    public string $handle = '';

    /**
     * @var FieldInterface|null The field, if the target is a custom field.
     */
    public ?FieldInterface $field = null;

    /**
     * @var string|null The attribute name, if the target is a native attribute.
     */
    public ?string $attribute = null;
}
