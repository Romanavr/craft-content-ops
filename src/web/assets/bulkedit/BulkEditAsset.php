<?php

namespace romanavr\contentops\web\assets\bulkedit;

use Craft;
use craft\helpers\Cp;
use craft\helpers\Json;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Bulk edit modal assets.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class BulkEditAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['bulk-edit.js'];
        $this->css = ['bulk-edit.css'];

        parent::init();
    }

    /**
     * Registers the bundle and adds a “Bulk edit” button to entry indexes (works with or without a selection).
     *
     * @param View $view
     */
    public static function registerForIndexes(View $view): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $registered = true;
        $view->registerAssetBundle(self::class);
        $view->registerJs(sprintf('Craft.ContentOps.BulkEdit.installButton(%s);', Json::encode([
            'label' => Craft::t('content-ops', 'Bulk edit'),
            'icon' => Cp::iconSvg('wand-magic-sparkles'),
            'sites' => self::editableSites(),
        ])));
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public static function editableSites(): array
    {
        return array_map(fn($site) => ['id' => $site->id, 'name' => $site->getName()], Craft::$app->getSites()->getEditableSites());
    }
}
