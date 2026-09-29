<?php

namespace romanavr\contentops\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\helpers\Json;
use romanavr\contentops\web\assets\bulkedit\BulkEditAsset;

/**
 * “Bulk edit…” element index action. Opens the bulk edit modal; nothing is performed server-side here.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class BulkEdit extends ElementAction
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('content-ops', 'Bulk edit…');
    }

    /**
     * @inheritdoc
     */
    public function getTriggerHtml(): ?string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(BulkEditAsset::class);

        $sites = [];

        foreach (Craft::$app->getSites()->getEditableSites() as $site) {
            $sites[] = ['id' => $site->id, 'name' => $site->getName()];
        }

        $view->registerJs(sprintf('Craft.ContentOps.BulkEdit.register(%s, %s);', Json::encode(static::class), Json::encode($sites)));

        return null;
    }
}
