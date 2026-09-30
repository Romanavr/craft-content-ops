<?php

use romanavr\contentops\ContentOps;

it('is installed and enabled', function() {
    expect(Craft::$app->getPlugins()->isPluginInstalled('content-ops'))->toBeTrue()
        ->and(ContentOps::getInstance())->toBeInstanceOf(ContentOps::class);
});

it('shows the banner on the settings page only', function() {
    $this->actingAsAdmin()
        ->get('/admin/settings/plugins/content-ops')
        ->assertOk()
        ->assertSee('co-settings-banner', false)
        ->assertSee('banner.jpg', false);

    $this->actingAsAdmin()
        ->get('/admin/content-ops/find-replace')
        ->assertOk()
        ->assertDontSee('co-settings-banner', false);
});
