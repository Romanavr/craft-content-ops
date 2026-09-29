<?php

namespace romanavr\contentops\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use DateTime;
use romanavr\contentops\ContentOps;
use romanavr\contentops\models\Target;
use romanavr\contentops\operators\OperatorInterface;
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

    /**
     * @var array<string, string> Attribute labels.
     */
    public const ATTRIBUTE_LABELS = [
        'title' => 'Title',
        'slug' => 'Slug',
        'enabled' => 'Enabled',
        'enabledForSite' => 'Enabled for site',
        'postDate' => 'Post Date',
        'expiryDate' => 'Expiry Date',
        'authorIds' => 'Authors',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Describes what can be edited on a set of elements: every field on their layouts (with how many of the
     * elements have it) and the native attributes, each with its operator, operations and inputs.
     *
     * @param class-string<ElementInterface> $elementType
     * @param int[] $elementIds
     * @return array<int, array<string, mixed>>
     */
    public function describeTargets(string $elementType, array $elementIds): array
    {
        $total = count($elementIds);
        $targets = [];

        foreach ($this->_attributesFor($elementType) as $attribute) {
            $targets[] = $this->_describe(new Target(['handle' => $attribute, 'attribute' => $attribute]), self::ATTRIBUTE_LABELS[$attribute], 'Attributes', $total, $total);
        }

        $layoutCounts = $total === 0 ? [] : (new Query())
            ->select(['fieldLayoutId', 'count' => 'COUNT(*)'])
            ->from(CraftTable::ELEMENTS)
            ->where(['id' => $elementIds])
            ->andWhere(['not', ['fieldLayoutId' => null]])
            ->groupBy(['fieldLayoutId'])
            ->pairs();

        $fields = [];
        $fieldsService = Craft::$app->getFields();

        foreach ($layoutCounts as $layoutId => $count) {
            foreach ($fieldsService->getLayoutById((int)$layoutId)?->getCustomFields() ?? [] as $field) {
                $fields[$field->handle] ??= ['field' => $field, 'count' => 0];
                $fields[$field->handle]['count'] += (int)$count;
            }
        }

        ksort($fields);

        foreach ($fields as $handle => ['field' => $field, 'count' => $count]) {
            $targets[] = $this->_describe(new Target(['handle' => $handle, 'field' => $field]), $field->name, 'Fields', $count, $total);
        }

        return array_values(array_filter($targets));
    }

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
     * @param class-string<ElementInterface> $elementType
     * @return string[]
     */
    private function _attributesFor(string $elementType): array
    {
        $attributes = $elementType::hasTitles() ? self::ATTRIBUTES : array_diff(self::ATTRIBUTES, ['title']);

        if ($elementType === Entry::class || is_subclass_of($elementType, Entry::class)) {
            $attributes = [...$attributes, ...self::ENTRY_ATTRIBUTES];
        }

        return array_values($attributes);
    }

    /**
     * @param Target $target
     * @param string $label
     * @param string $group
     * @param int $count
     * @param int $total
     * @return array<string, mixed>|null `null` if no operator supports the target
     */
    private function _describe(Target $target, string $label, string $group, int $count, int $total): ?array
    {
        /** @var OperatorInterface|null $operator */
        $operator = ContentOps::getInstance()->getOperators()->getOperatorsForTarget($target)[0] ?? null;

        if ($operator === null) {
            return null;
        }

        $operations = [];

        foreach ($operator->getOperationsForTarget($target) as $handle => $operationLabel) {
            $operations[] = [
                'handle' => $handle,
                'label' => $operationLabel,
                'inputs' => $operator->getInputs($handle, $target),
            ];
        }

        return [
            'handle' => $target->handle,
            'label' => $label,
            'group' => $group,
            'type' => $target->field ? $target->field::displayName() : null,
            'count' => $count,
            'total' => $total,
            'operator' => $operator::handle(),
            'operations' => $operations,
        ];
    }

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
