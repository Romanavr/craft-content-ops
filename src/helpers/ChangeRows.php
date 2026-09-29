<?php

namespace romanavr\contentops\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\Html;
use romanavr\contentops\ContentOps;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\records\Change;

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
        $targets = ContentOps::getInstance()->getTargets();

        return array_map(function(Change $change) use ($elements, $sites, $targets) {
            $old = Values::decode($change->oldValue);
            $new = Values::decode($change->newValue);
            [$before, $after] = Diff::isBlockList($old) && Diff::isBlockList($new)
                ? Diff::blocks($old, $new)
                : Diff::inline($old, $new);
            $element = $elements["$change->elementId:$change->siteId"] ?? null;

            return [
                'change' => $change,
                'elementHtml' => $element ? self::_elementHtml($element) : Craft::t('content-ops', 'Deleted element #{id}', ['id' => $change->elementId]),
                'site' => $sites->getSiteById($change->siteId)?->getName(),
                'target' => $targets->label($change->target),
                'before' => $before,
                'after' => $after,
            ];
        }, $changes);
    }

    // Private Methods
    // =========================================================================

    /**
     * Nested entries are shown as their owner's chip, since they often have no title of their own.
     *
     * @param ElementInterface $element
     * @return string
     */
    private static function _elementHtml(ElementInterface $element): string
    {
        if ($element instanceof Entry && $element->fieldId !== null && ($owner = $element->getOwner()) !== null) {
            return Cp::elementChipHtml($owner) . Html::tag('div', Html::encode($element->getType()->name), ['class' => 'smalltext light']);
        }

        return Cp::elementChipHtml($element);
    }
}
