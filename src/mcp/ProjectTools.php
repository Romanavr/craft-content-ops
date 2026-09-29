<?php

namespace romanavr\contentops\mcp;

use Craft;
use craft\base\FieldInterface;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;

/**
 * MCP tools that describe the project's structure (sites, sections, entry types, fields),
 * so an AI client understands the content model before it reads or proposes changes.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class ProjectTools
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the project's content model: sites, sections with their entry types and field layouts,
     * and every custom field with its type, translation method and type-specific settings.
     *
     * @return array<string, mixed>
     */
    public function getProjectSchema(): array
    {
        $entries = Craft::$app->getEntries();

        return [
            'craftVersion' => Craft::$app->getVersion(),
            'edition' => Craft::$app->edition->name,
            'sites' => array_map(fn(Site $site) => $this->_serializeSite($site), Craft::$app->getSites()->getAllSites()),
            'sections' => array_map(fn(Section $section) => $this->_serializeSection($section), $entries->getAllSections()),
            'entryTypes' => array_map(fn(EntryType $entryType) => $this->_serializeEntryType($entryType), $entries->getAllEntryTypes()),
            'fields' => array_map(fn(FieldInterface $field) => $this->_serializeField($field), Craft::$app->getFields()->getAllFields()),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @param Site $site
     * @return array<string, mixed>
     */
    private function _serializeSite(Site $site): array
    {
        return [
            'id' => $site->id,
            'handle' => $site->handle,
            'name' => $site->getName(),
            'language' => $site->language,
            'primary' => $site->primary,
            'enabled' => $site->getEnabled(),
            'baseUrl' => $site->getBaseUrl(),
            'group' => $site->getGroup()->getName(),
        ];
    }

    /**
     * @param Section $section
     * @return array<string, mixed>
     */
    private function _serializeSection(Section $section): array
    {
        $sites = Craft::$app->getSites();
        $siteSettings = [];

        foreach ($section->getSiteSettings() as $settings) {
            $siteSettings[] = [
                'site' => $sites->getSiteById($settings->siteId)?->handle,
                'hasUrls' => $settings->hasUrls,
                'uriFormat' => $settings->uriFormat,
                'enabledByDefault' => $settings->enabledByDefault,
            ];
        }

        return [
            'id' => $section->id,
            'handle' => $section->handle,
            'name' => $section->name,
            'type' => $section->type,
            'propagationMethod' => $section->propagationMethod->value,
            'maxLevels' => $section->maxLevels,
            'siteSettings' => $siteSettings,
            'entryTypes' => array_map(fn(EntryType $entryType) => $entryType->handle, $section->getEntryTypes()),
        ];
    }

    /**
     * @param EntryType $entryType
     * @return array<string, mixed>
     */
    private function _serializeEntryType(EntryType $entryType): array
    {
        $fields = [];

        foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
            $fields[] = [
                'handle' => $field->handle,
                'field' => $field->layoutElement?->getOriginalHandle() ?? $field->handle,
                'name' => $field->name,
                'required' => (bool)$field->layoutElement?->required,
            ];
        }

        return [
            'id' => $entryType->id,
            'handle' => $entryType->handle,
            'name' => $entryType->name,
            'hasTitleField' => $entryType->hasTitleField,
            'titleTranslationMethod' => $entryType->titleTranslationMethod,
            'fields' => $fields,
        ];
    }

    /**
     * @param FieldInterface $field
     * @return array<string, mixed>
     */
    private function _serializeField(FieldInterface $field): array
    {
        $data = [
            'id' => $field->id,
            'handle' => $field->handle,
            'name' => $field->name,
            'type' => $field::class,
            'typeName' => $field::displayName(),
            'translationMethod' => $field->translationMethod,
            'searchable' => $field->searchable,
            'instructions' => $field->instructions,
        ];

        if ($field instanceof BaseOptionsField) {
            $data['options'] = array_values(array_filter(array_map(
                fn(array $option) => isset($option['value']) ? ['label' => $option['label'], 'value' => $option['value']] : null,
                $field->options,
            )));
        }

        if ($field instanceof BaseRelationField) {
            $data['elementType'] = $field::elementType();
            $data['sources'] = $field->sources;
            $data['maxRelations'] = $field->maxRelations;
        }

        if ($field instanceof Matrix) {
            $data['entryTypes'] = array_map(fn(EntryType $entryType) => $entryType->handle, $field->getEntryTypes());
            $data['minEntries'] = $field->minEntries;
            $data['maxEntries'] = $field->maxEntries;
        }

        return $data;
    }
}
