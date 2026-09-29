<?php

namespace romanavr\contentops\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\Cp;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\records\Change;
use romanavr\contentops\services\Targets;

/**
 * Prepares change rows for display (element chip, site, target label, highlighted before/after).
 *
 * @author Romanavr
 * @since 1.0.0
 */
abstract class ChangeRows
{
    // Public Methods
    // =========================================================================

    /**
     * @param Changeset $changeset
     * @param Change[] $changes
     * @return array<int, array<string, mixed>>
     */
    public static function build(Changeset $changeset, array $changes): array
    {
        if (empty($changes)) {
            return [];
        }

        /** @var class-string<ElementInterface> $elementType */
        $elementType = $changeset->selection->elementType;
        $elements = [];

        foreach ($elementType::find()
            ->id(array_values(array_unique(array_map(fn(Change $change) => $change->elementId, $changes))))
            ->siteId(array_values(array_unique(array_map(fn(Change $change) => $change->siteId, $changes))))
            ->status(null)
            ->all() as $element) {
            $elements["$element->id:$element->siteId"] = $element;
        }

        $sites = Craft::$app->getSites();
        $fields = Craft::$app->getFields();

        return array_map(function(Change $change) use ($elements, $sites, $fields) {
            [$before, $after] = Diff::inline(Values::decode($change->oldValue), Values::decode($change->newValue));
            $element = $elements["$change->elementId:$change->siteId"] ?? null;

            return [
                'change' => $change,
                'elementHtml' => $element ? Cp::elementChipHtml($element) : Craft::t('content-ops', 'Deleted element #{id}', ['id' => $change->elementId]),
                'site' => $sites->getSiteById($change->siteId)?->getName(),
                'target' => Targets::ATTRIBUTE_LABELS[$change->target] ?? $fields->getFieldByHandle($change->target)->name ?? $change->target,
                'before' => $before,
                'after' => $after,
            ];
        }, $changes);
    }
}
