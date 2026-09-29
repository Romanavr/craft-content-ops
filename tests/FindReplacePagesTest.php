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

it('checks all sections and sites by default', function() {
    $html = $this->actingAsAdmin()
        ->get('/admin/content-ops/find-replace')
        ->assertOk()
        ->assertSee('All sections')
        ->content;

    preg_match_all('/<input[^>]*>/', $html, $inputs);
    $allSections = array_values(array_filter($inputs[0], fn($tag) => str_contains($tag, 'name="sections"') && str_contains($tag, 'value="*"')));

    expect($allSections)->toHaveCount(1)
        ->and($allSections[0])->toContain('checked');
});

it('searches everything when All is checked', function() {
    $s = seedFindReplace();

    $this->actingAsAdmin()
        ->withExceptionHandling()
        ->postJson('/admin/actions/content-ops/find-replace/search', ['find' => 'Acme', 'replace' => 'Globex', 'sections' => '*', 'siteIds' => '*']);

    $changeset = ContentOps::getInstance()->getChangesets()->getRecentChangesets(1)[0];

    expect($changeset->status->value)->toBe('previewing')
        ->and($changeset->options['scope']['sections'])->toBe([])
        ->and($changeset->options['scope']['siteIds'])->toBe([]);
});

it('does not treat an empty choice as everything', function() {
    $before = count(ContentOps::getInstance()->getChangesets()->getRecentChangesets(50));

    // Posted like the real form: to the page, with an action param, so the form is shown again with the error.
    $this->actingAsAdmin()
        ->post('/admin/content-ops/find-replace', ['action' => 'content-ops/find-replace/search', 'find' => 'Acme', 'replace' => 'Globex', 'sections' => ''])
        ->assertSee('Choose at least one section.')
        ->assertSee('value="Acme"', false);

    expect(count(ContentOps::getInstance()->getChangesets()->getRecentChangesets(50)))->toBe($before);
});
