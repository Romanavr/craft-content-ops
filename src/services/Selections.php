<?php

namespace romanavr\contentops\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use romanavr\contentops\models\Selection;
use yii\base\Component;

/**
 * Turns selections into element queries.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Selections extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns an element query for a selection: canonical elements only, one result per element per site,
     * in a stable order (element ID, then site ID).
     *
     * @param Selection $selection
     * @return ElementQueryInterface
     */
    public function createQuery(Selection $selection): ElementQueryInterface
    {
        /** @var class-string<ElementInterface> $elementType */
        $elementType = $selection->elementType;
        $criteria = $selection->criteria;
        unset($criteria['site'], $criteria['siteId'], $criteria['drafts'], $criteria['revisions'], $criteria['provisionalDrafts']);

        $siteIds = $selection->siteIds ?? Craft::$app->getSites()->getPrimarySite()->id;

        // `limit`/`offset` count elements, not element/site rows: resolve the element IDs first.
        if (isset($criteria['limit']) || isset($criteria['offset'])) {
            $idQuery = $elementType::find()->status(null);
            Craft::configure($idQuery, $criteria);
            $ids = $idQuery->siteId($siteIds)->unique()->orderBy(['elements.id' => SORT_ASC])->ids();
            // An empty ID list would mean "no filter", so match nothing explicitly.
            $criteria = ['id' => $ids ?: [0]];
        }

        $query = $elementType::find()->status(null);
        Craft::configure($query, $criteria);

        return $query
            ->siteId($siteIds)
            ->unique(false)
            ->drafts(false)
            ->provisionalDrafts(false)
            ->revisions(false)
            ->orderBy(['elements.id' => SORT_ASC, 'elements_sites.siteId' => SORT_ASC]);
    }
}
