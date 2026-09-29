<?php

namespace romanavr\contentops\services;

use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use DateTime;
use romanavr\contentops\models\Target;
use yii\base\Component;

/**
 * Reads and writes operation targets (custom fields and native attributes) as serialized values.
 *
 * Field values use `serializeValueForDb()`, i.e. exactly what Craft stores and later feeds back into
 * `normalizeValue()`, so a logged value can always be written back.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Targets extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[] Native attributes that can be targeted on any element.
     */
    public const ATTRIBUTES = ['title', 'slug', 'enabled', 'enabledForSite'];

    /**
     * @var string[] Native attributes that can be targeted on entries only.
     */
    public const ENTRY_ATTRIBUTES = ['postDate', 'expiryDate', 'authorIds'];

    /**
     * @var string[] Attributes whose value is shared by all sites (everything else is per site, unless it's a field).
     */
    public const GLOBAL_ATTRIBUTES = ['enabled', 'postDate', 'expiryDate', 'authorIds'];

    // Public Methods
    // =========================================================================

    /**
     * Returns whether a handle names a native attribute (on at least some element types).
     *
     * @param string $handle
     * @return bool
     */
    public static function isAttribute(string $handle): bool
    {
        return in_array($handle, self::ATTRIBUTES, true) || in_array($handle, self::ENTRY_ATTRIBUTES, true);
    }

    /**
     * Resolves a target handle on an element, or returns `null` if the element doesn't have it.
     *
     * @param ElementInterface $element
     * @param string $handle
     * @return Target|null
     */
    public function resolve(ElementInterface $element, string $handle): ?Target
    {
        if (self::isAttribute($handle)) {
            if ($handle === 'title' && !$element::hasTitles()) {
                return null;
            }

            if (in_array($handle, self::ENTRY_ATTRIBUTES, true) && !$element instanceof Entry) {
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
        if ($target->field !== null) {
            return $target->field->serializeValueForDb($element->getFieldValue($target->field->handle), $element);
        }

        return match ($target->attribute) {
            'enabled' => (bool)$element->enabled,
            'enabledForSite' => (bool)$element->getEnabledForSite(),
            'authorIds' => $element instanceof Entry ? array_map('intval', $element->getAuthorIds()) : [],
            default => $this->_serializeAttribute($element->{$target->attribute}),
        };
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
        if ($target->field !== null) {
            $element->setFieldValue($target->field->handle, $value);
            return;
        }

        match ($target->attribute) {
            'enabledForSite' => $element->setEnabledForSite((bool)$value),
            'enabled' => $element->enabled = (bool)$value,
            'authorIds' => $element instanceof Entry ? $element->setAuthorIds($value ?? []) : null,
            'postDate', 'expiryDate' => $this->_writeEntryDate($element, $target->attribute, $value),
            default => $element->{$target->attribute} = $value,
        };
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

        if (in_array($target->attribute, self::GLOBAL_ATTRIBUTES, true)) {
            return '*';
        }

        if ($target->attribute === 'title' && $element instanceof Entry) {
            $entryType = $element->getType();

            return ElementHelper::translationKey($element, $entryType->titleTranslationMethod, $entryType->titleTranslationKeyFormat);
        }

        // Slugs, per-site status, and titles of other element types are stored per site.
        return (string)$element->siteId;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param ElementInterface $element
     * @param string $attribute `postDate` or `expiryDate`
     * @param mixed $value UTC date string or null
     */
    private function _writeEntryDate(ElementInterface $element, string $attribute, mixed $value): void
    {
        if (!$element instanceof Entry) {
            return;
        }

        $element->$attribute = $value !== null ? DateTimeHelper::toDateTime($value) ?: null : null;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function _serializeAttribute(mixed $value): mixed
    {
        return $value instanceof DateTime ? Db::prepareDateForDb($value) : $value;
    }
}
