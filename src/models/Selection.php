<?php

namespace romanavr\contentops\models;

use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\Entry;

/**
 * Which elements a changeset targets: an element type, element query criteria, and the sites to edit.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Selection extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var class-string<ElementInterface>
     */
    public string $elementType = Entry::class;

    /**
     * @var array<string, mixed> Element query criteria, e.g. `['section' => 'news', 'id' => [1, 2]]`.
     * `site`/`siteId` are ignored in favour of {@see $siteIds}.
     */
    public array $criteria = [];

    /**
     * @var int[]|null Sites to edit. `null` means the primary site.
     */
    public ?array $siteIds = null;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['elementType'], 'required'];
        $rules[] = [['elementType'], function(string $attribute) {
            if (!is_subclass_of($this->$attribute, ElementInterface::class)) {
                $this->addError($attribute, "{$this->$attribute} isn't an element type.");
            }
        }];

        return $rules;
    }
}
