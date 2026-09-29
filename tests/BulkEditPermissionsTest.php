<?php

use craft\elements\Entry;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\Section as SectionFactory;
use markhuot\craftpest\factories\User as UserFactory;
use romanavr\contentops\ContentOps;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;

function userWithPermissions(array $permissions): \craft\elements\User
{
    $user = UserFactory::factory()->create();
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accesscp', ...$permissions]);

    return $user;
}

function targetsRequest(string $sectionUid, array $elementIds): array
{
    return [
        'elementType' => Entry::class,
        'source' => "section:$sectionUid",
        'context' => 'index',
        'criteria' => ['siteId' => Craft::$app->getSites()->getPrimarySite()->id],
        'scope' => 'selected',
        'elementIds' => $elementIds,
    ];
}

it('rejects users without the bulk edit permission', function() {
    $section = SectionFactory::factory()->create();
    $entry = EntryFactory::factory()->section($section->handle)->create();

    $this->withExceptionHandling()->actingAs(userWithPermissions(["viewentries:$section->uid", "saveentries:$section->uid"]))
        ->postJson('/admin/actions/content-ops/bulk-edit/targets', targetsRequest($section->uid, [$entry->id]))
        ->assertStatus(403);
});

it('returns targets for users with the bulk edit permission', function() {
    $section = SectionFactory::factory()->create();
    $entry = EntryFactory::factory()->section($section->handle)->create();

    $this->withExceptionHandling()->actingAs(userWithPermissions(['contentops:bulkedit', "viewentries:$section->uid", "saveentries:$section->uid"]))
        ->postJson('/admin/actions/content-ops/bulk-edit/targets', targetsRequest($section->uid, [$entry->id]))
        ->assertOk()
        ->assertJsonPath('total', 1);
});

it('only lets users apply their own changesets', function() {
    $section = SectionFactory::factory()->create();
    EntryFactory::factory()->section($section->handle)->create();
    $owner = userWithPermissions(['contentops:bulkedit']);

    $changeset = ContentOps::getInstance()->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section->handle]]),
        [new Operation(['target' => 'title', 'operator' => 'text', 'operation' => 'append', 'options' => ['value' => '!']])],
        $owner,
    );

    $this->withExceptionHandling()->actingAs(userWithPermissions(['contentops:bulkedit']))
        ->postJson('/admin/actions/content-ops/changesets/apply', ['changesetId' => $changeset->id])
        ->assertStatus(403);
});

it('skips elements the user cannot save', function() {
    $section = SectionFactory::factory()->create();
    EntryFactory::factory()->section($section->handle)->create();
    // Can view but not save entries in this section.
    $user = userWithPermissions(['contentops:bulkedit', "viewentries:$section->uid"]);

    $changeset = ContentOps::getInstance()->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section->handle]]),
        [new Operation(['target' => 'title', 'operator' => 'text', 'operation' => 'append', 'options' => ['value' => '!']])],
        $user,
    );

    expect($changeset->getCount('skipped'))->toBe(1)
        ->and($changeset->getCount('pending'))->toBe(0);
});
