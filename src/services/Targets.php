<?php

namespace romanavr\contentops\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\fields\Matrix;
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
     * @var string Separates the parts of a nested target path: `matrixField.entryType.innerField`.
     */
    public const PATH_SEPARATOR = '.';

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

    // Private Properties
    // =========================================================================

    /**
     * @var array{key: string, entries: Entry[]}|null Nested entries of the last owner/site/Matrix field looked up
     * (a preview asks for them once per nested target; this avoids re-querying for each)
     */
    private ?array $_nestedCache = null;

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

        // Fields inside Matrix nested entries, as matrixField.entryType.innerField
        foreach ($fields as $handle => ['field' => $field, 'count' => $count]) {
            if (!$field instanceof Matrix) {
                continue;
            }

            foreach ($field->getEntryTypes() as $entryType) {
                foreach ($entryType->getFieldLayout()->getCustomFields() as $innerField) {
                    $path = implode(self::PATH_SEPARATOR, [$handle, $entryType->handle, $innerField->handle]);
                    $label = implode(' › ', [$field->name, $entryType->name, $innerField->name]);
                    $targets[] = $this->_describe(new Target(['handle' => $path, 'field' => $innerField]), $label, "Matrix: $field->name", $count, $total);
                }
            }
        }

        return array_values(array_filter($targets));
    }

    /**
     * Returns whether a target handle is a nested path (`matrixField.entryType.innerField`).
     *
     * @param string $handle
     * @return bool
     */
    public static function isNestedPath(string $handle): bool
    {
        return substr_count($handle, self::PATH_SEPARATOR) === 2;
    }

    /**
     * Returns the owner's nested entries a path points at (all entries of the path's type in its Matrix field),
     * in the owner's site. Returns `null` if the owner doesn't have that Matrix field.
     *
     * @param ElementInterface $owner
     * @param string $path
     * @return Entry[]|null
     */
    public function nestedElements(ElementInterface $owner, string $path): ?array
    {
        [$matrixHandle, $typeHandle] = explode(self::PATH_SEPARATOR, $path);
        $field = $owner->getFieldLayout()?->getFieldByHandle($matrixHandle);

        if (!$field instanceof Matrix) {
            return null;
        }

        $key = "$owner->id:$owner->siteId:$matrixHandle";

        if ($this->_nestedCache === null || $this->_nestedCache['key'] !== $key) {
            /** @var EntryQuery $query */
            $query = $owner->getFieldValue($matrixHandle);
            $this->_nestedCache = ['key' => $key, 'entries' => (clone $query)->status(null)->all()];
        }

        return array_values(array_filter(
            $this->_nestedCache['entries'],
            fn(Entry $entry) => $entry->getType()->handle === $typeHandle,
        ));
    }

    /**
     * Returns a readable label for a target handle (`Content Blocks › Text Block › Heading` for nested paths).
     *
     * @param string $handle
     * @return string
     */
    public function label(string $handle): string
    {
        if (isset(self::ATTRIBUTE_LABELS[$handle])) {
            return self::ATTRIBUTE_LABELS[$handle];
        }

        $fields = Craft::$app->getFields();

        if (!self::isNestedPath($handle)) {
            return $fields->getFieldByHandle($handle)->name ?? $handle;
        }

        [$matrixHandle, $typeHandle, $innerHandle] = explode(self::PATH_SEPARATOR, $handle);

        return implode(' › ', [
            $fields->getFieldByHandle($matrixHandle)->name ?? $matrixHandle,
            Craft::$app->getEntries()->getEntryTypeByHandle($typeHandle)->name ?? $typeHandle,
            $fields->getFieldByHandle($innerHandle)->name ?? $innerHandle,
        ]);
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
        if (self::isNestedPath($handle)) {
            return $this->_resolveNested($element, $handle);
        }

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
        if ($target->field instanceof Matrix) {
            return $this->_readMatrix($element, $target->field);
        }

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
        if ($target->field instanceof Matrix) {
            $this->_writeMatrix($element, $target->field, is_array($value) ? $value : []);
            return;
        }

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
        if ($target->field instanceof Matrix) {
            return $this->_matrixTranslationKey($element, $target->field);
        }

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
     * Matrix fields share their list of nested entries between sites according to their *propagation* method
     * (not the translation method), so a structural change saved in one site already reaches the others.
     *
     * @param ElementInterface $element
     * @param Matrix $field
     * @return string
     */
    private function _matrixTranslationKey(ElementInterface $element, Matrix $field): string
    {
        $method = match ($field->propagationMethod) {
            PropagationMethod::All => Field::TRANSLATION_METHOD_NONE,
            PropagationMethod::None => Field::TRANSLATION_METHOD_SITE,
            PropagationMethod::SiteGroup => Field::TRANSLATION_METHOD_SITE_GROUP,
            PropagationMethod::Language => Field::TRANSLATION_METHOD_LANGUAGE,
            PropagationMethod::Custom => Field::TRANSLATION_METHOD_CUSTOM,
        };

        return ElementHelper::translationKey($element, $method, $field->propagationKeyFormat);
    }

    /**
     * Reads a Matrix field as an ordered list of its nested entries (order matters, so it's a list, not an ID map).
     *
     * @param ElementInterface $owner
     * @param Matrix $field
     * @return array<int, array<string, mixed>>
     */
    private function _readMatrix(ElementInterface $owner, Matrix $field): array
    {
        /** @var EntryQuery|\craft\elements\ElementCollection $value */
        $value = $owner->getFieldValue($field->handle);
        /** @var Entry[] $entries */
        $entries = $value instanceof EntryQuery ? (clone $value)->status(null)->all() : $value->all();

        return array_map(fn(Entry $entry) => [
            'id' => $entry->id,
            'type' => $entry->getType()->handle,
            'enabled' => (bool)$entry->enabled,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'fields' => $entry->getSerializedFieldValuesForDb(),
        ], $entries);
    }

    /**
     * Writes a Matrix field from a list produced by {@see _readMatrix()}. Nested entries with an ID are kept
     * (and restored from the trash if an earlier change removed them); ones without an ID are created;
     * existing ones that aren't listed are soft-deleted by Craft.
     *
     * @param ElementInterface $owner
     * @param Matrix $field
     * @param array<int, array<string, mixed>> $blocks
     */
    private function _writeMatrix(ElementInterface $owner, Matrix $field, array $blocks): void
    {
        $ids = array_values(array_filter(array_map(fn(array $block) => $block['id'] ?? null, $blocks)));

        if ($ids && $owner->id) {
            $trashed = Entry::find()
                ->id($ids)
                ->fieldId($field->id)
                ->ownerId($owner->id)
                ->siteId($owner->siteId)
                ->status(null)
                ->trashed()
                ->all();

            if ($trashed) {
                Craft::$app->getElements()->restoreElements($trashed);
            }
        }

        $value = [];
        $new = 0;

        foreach ($blocks as $block) {
            $key = $block['id'] ?? sprintf('new%s', ++$new);
            $value[$key] = [
                'type' => $block['type'],
                'enabled' => $block['enabled'] ?? true,
                'title' => $block['title'] ?? null,
                'slug' => $block['slug'] ?? null,
                'fields' => $block['fields'] ?? [],
            ];
        }

        $owner->setFieldValue($field->handle, $value);
    }

    /**
     * Resolves `matrixField.entryType.innerField` on a nested entry, checking it belongs to that field and type.
     *
     * @param ElementInterface $element
     * @param string $path
     * @return Target|null
     */
    private function _resolveNested(ElementInterface $element, string $path): ?Target
    {
        [$matrixHandle, $typeHandle, $innerHandle] = explode(self::PATH_SEPARATOR, $path);

        if (!$element instanceof Entry || $element->fieldId === null) {
            return null;
        }

        if ($element->getField()?->handle !== $matrixHandle || $element->getType()->handle !== $typeHandle) {
            return null;
        }

        $field = $element->getFieldLayout()?->getFieldByHandle($innerHandle);

        return $field ? new Target(['handle' => $path, 'field' => $field]) : null;
    }

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
