<?php

namespace romanavr\contentops\models;

use craft\base\Model;

/**
 * One change to make: apply `operation` of `operator` to `target` (a field handle or a native attribute like `title`).
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Operation extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string Field handle, or a native attribute name (`title`, `slug`).
     */
    public string $target = '';

    /**
     * @var string Operator handle, e.g. `text`.
     */
    public string $operator = '';

    /**
     * @var string Operation handle within the operator, e.g. `set`, `append`, `replace`.
     */
    public string $operation = '';

    /**
     * @var array<string, mixed> Operation options, e.g. `['value' => 'x']` or `['find' => 'a', 'replace' => 'b']`.
     */
    public array $options = [];

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['target', 'operator', 'operation'], 'required'];

        return $rules;
    }
}
