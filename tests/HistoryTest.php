<?php

use craft\elements\Entry;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\Section as SectionFactory;
use markhuot\craftpest\factories\User as UserFactory;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetStatus;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;

function appliedChangeset(?\craft\elements\User $user = null): Changeset
{
    $section = SectionFactory::factory()->create();
    EntryFactory::factory()->section($section->handle)->title('Acme <b>launch</b>')->create();
    $plugin = ContentOps::getInstance();

    $changeset = $plugin->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section->handle]]),
        [new Operation(['target' => 'title', 'operator' => 'text', 'operation' => 'replace', 'options' => ['find' => 'Acme', 'replace' => 'Globex']])],
        $user,
    );
    $plugin->getChangesets()->applyNow($changeset->id);

    return $plugin->getChangesets()->getChangesetById($changeset->id);
}

it('lists changesets', function() {
    $changeset = appliedChangeset();

    $this->actingAsAdmin()
        ->get('/admin/content-ops/history')
        ->assertOk()
        ->assertSee("#$changeset->id")
        ->assertSee('Applied');
});

it('shows a changeset with escaped diffs', function() {
    $changeset = appliedChangeset();

    $this->actingAsAdmin()
        ->get("/admin/content-ops/history/$changeset->id")
        ->assertOk()
        ->assertSee('<del>Acme</del>', false)
        ->assertSee('<ins>Globex</ins>', false)
        ->assertDontSee('<b>launch</b>', false);
});

it('undoes from the history page', function() {
    $changeset = appliedChangeset();
    $plugin = ContentOps::getInstance();

    $this->actingAsAdmin()
        ->withExceptionHandling()
        ->postJson('/admin/actions/content-ops/history/undo', ['changesetId' => $changeset->id])
        ->assertOk();

    // Run the queued undo job.
    Craft::$app->getQueue()->run();

    expect($plugin->getChangesets()->getChangesetById($changeset->id)->status)->toBe(ChangesetStatus::Undone)
        ->and(Entry::find()->title('Acme <b>launch</b>')->exists())->toBeTrue();
});

it('hides other users’ changesets without the view history permission', function() {
    $owner = UserFactory::factory()->create();
    $changeset = appliedChangeset($owner);
    $other = UserFactory::factory()->create();
    Craft::$app->getUserPermissions()->saveUserPermissions($other->id, ['accesscp', 'contentops:bulkedit', 'contentops:undo']);

    $this->withExceptionHandling()
        ->actingAs($other)
        ->get("/admin/content-ops/history/$changeset->id")
        ->assertStatus(403);
});
