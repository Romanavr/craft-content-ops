<?php

use craft\elements\Entry;
use romanavr\contentops\ContentOps;
use romanavr\contentops\models\FindReplaceScope;
use romanavr\contentops\models\MatchSpec;

it('shows the search form', function() {
    $this->actingAsAdmin()
        ->get('/admin/content-ops/find-replace')
        ->assertOk()
        ->assertSee('Find matches');
});

it('lists matches, excludes one and applies the rest', function() {
    $s = seedFindReplace();
    $plugin = ContentOps::getInstance();
    $id = $plugin->getFindReplace()->queuePreview(
        new MatchSpec(['find' => 'acme', 'replace' => 'Globex', 'caseSensitive' => false]),
        new FindReplaceScope(['sections' => [$s['section']->handle], 'targets' => [$s['summary']->handle]]),
    );
    Craft::$app->getQueue()->run();

    $this->actingAsAdmin()
        ->get("/admin/content-ops/find-replace/$id")
        ->assertOk()
        ->assertSee('2 matches')
        ->assertSee('<del>Acme</del><ins>Globex</ins>', false);

    $change = $plugin->getChangesets()->getChanges($id)[0];

    $this->actingAsAdmin()
        ->withExceptionHandling()
        ->postJson('/admin/actions/content-ops/find-replace/exclude', [
            'changesetId' => $id,
            'shown' => [$change->id => [0, 1]],
            'include' => [$change->id => [0]],
            'apply' => 1,
        ]);
    Craft::$app->getQueue()->run();

    expect(Entry::find()->id($s['hit']->id)->one()->getFieldValue($s['summary']->handle))->toBe('Globex Corp and acme');
});

it('requires the find and replace permission', function() {
    $user = userWithPermissions(['contentops:bulkedit']);

    $this->withExceptionHandling()
        ->actingAs($user)
        ->get('/admin/content-ops/find-replace')
        ->assertStatus(403);
});
