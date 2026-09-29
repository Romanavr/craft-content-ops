<?php

use romanavr\contentops\ContentOps;

it('is installed and enabled', function() {
    expect(Craft::$app->getPlugins()->isPluginInstalled('content-ops'))->toBeTrue()
        ->and(ContentOps::getInstance())->toBeInstanceOf(ContentOps::class);
});
