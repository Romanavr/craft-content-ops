<?php

namespace romanavr\contentops\web\assets\bulkedit;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

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
}
