<?php

use romanavr\contentops\ContentOps;

/*
| Content Ops is free: there are no editions and no feature limits.
*/

it('has no editions', function() {
    expect(ContentOps::editions())->toBe(['standard']);
});

it('undoes any changeset, not only the most recent one', function() {
    [$section, $field] = seedSection(['a']);
    $changesets = ContentOps::getInstance()->getChangesets();
    $admin = \craft\elements\User::find()->admin()->one() ?? \markhuot\craftpest\factories\User::factory()->admin(true)->create();

    $first = previewOp($section, op($field, 'append', ['value' => '1']));
    $changesets->applyNow($first->id);
    $second = previewOp($section, op($field, 'append', ['value' => '2']));
    $changesets->applyNow($second->id);

    expect($changesets->canUndo($changesets->getChangesetById($first->id), $admin))->toBeTrue()
        ->and($changesets->canUndo($changesets->getChangesetById($second->id), $admin))->toBeTrue();
});

it('renders the find and replace form with every option enabled', function() {
    $this->actingAsAdmin()
        ->get('/admin/content-ops/find-replace')
        ->assertOk()
        ->assertSee('name="regex"', false)
        ->assertDontSee('co-pro-badge', false)
        ->assertDontSee('Content Ops Pro');
});
