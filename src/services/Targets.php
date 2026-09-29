<?php

namespace romanavr\contentops\services;

use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use romanavr\contentops\models\Target;
use yii\base\Component;

/**
 * Reads and writes operation targets (custom fields and native attributes) as serialized values.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Targets extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[] Native attributes that can be targeted.
     */
    public const ATTRIBUTES = ['title', 'slug'];

    // Public Methods
    // =========================================================================

    /**
     * Resolves a target handle on an element, or returns `null` if the element doesn't have it.
     *
     * @param ElementInterface $element
     * @param string $handle
     * @return Target|null
     */
    public function resolve(ElementInterface $element, string $handle): ?Target
    {
        if (in_array($handle, self::ATTRIBUTES, true)) {
            if ($handle === 'title' && !$element::hasTitles()) {
                return null;
            }

            return new Target(['handle' => $handle, 'attribute' => $handle]);
        }

        $field = $element->getFieldLayout()?->getFieldByHandle($handle);

        return $field ? new Target(['handle' => $handle, 'field' => $field]) : null;
    }

    /**
     * Returns the target's current serialized value.
     *
     * @param ElementInterface $element
     * @param Target $target
     * @return mixed
     */
    public function read(ElementInterface $element, Target $target): mixed
    {
        if ($target->attribute !== null) {
            return $element->{$target->attribute};
        }

        return $target->field->serializeValue($element->getFieldValue($target->field->handle), $element);
    }

    /**
     * Sets the target to a serialized value (the element isn't saved).
     *
     * @param ElementInterface $element
     * @param Target $target
     * @param mixed $value
     */
    public function write(ElementInterface $element, Target $target, mixed $value): void
    {
        if ($target->attribute !== null) {
            $element->{$target->attribute} = $value;
            return;
        }

        $element->setFieldValue($target->field->handle, $value);
    }

    /**
     * Returns the target's translation key. Sites that share a key share the value, so a changeset
     * must only change it once per element (otherwise e.g. an append would be applied once per site).
     *
     * @param ElementInterface $element
     * @param Target $target
     * @return string
     */
    public function translationKey(ElementInterface $element, Target $target): string
    {
        if ($target->field !== null) {
            return $target->field->getTranslationKey($element);
        }

        if ($target->attribute === 'title' && $element instanceof Entry) {
            $entryType = $element->getType();

            return ElementHelper::translationKey($element, $entryType->titleTranslationMethod, $entryType->titleTranslationKeyFormat);
        }

        // Slugs, and titles of other element types, are stored per site.
        return (string)$element->siteId;
    }
}
