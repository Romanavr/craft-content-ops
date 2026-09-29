<?php

use romanavr\contentops\ContentOps;
use romanavr\contentops\errors\ProFeatureException;
use romanavr\contentops\models\FindReplaceScope;
use romanavr\contentops\models\MatchSpec;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;

beforeEach(function() {
    useEdition(ContentOps::EDITION_LITE);
});

it('allows plain find and replace in Lite', function() {
    $s = seedFindReplace();

    $changeset = ContentOps::getInstance()->getFindReplace()->preview(
        new MatchSpec(['find' => 'Acme', 'replace' => 'Globex']),
        new FindReplaceScope(['sections' => [$s['section']->handle]]),
    );

    expect($changeset->getCount('pending'))->toBeGreaterThan(0);
});

it('keeps advanced find and replace for Pro', function(array $spec, array $scope, string $message) {
    $s = seedFindReplace();

    expect(fn() => ContentOps::getInstance()->getFindReplace()->preview(
        new MatchSpec($spec),
        new FindReplaceScope(['sections' => [$s['section']->handle]] + $scope),
    ))->toThrow(ProFeatureException::class, $message);
})->with([
    'regex' => [['find' => 'Ac.e', 'regex' => true], [], 'Regular expressions requires Content Ops Pro'],
    'links' => [['find' => 'old.test', 'html' => MatchSpec::HTML_TEXT_AND_LINKS], [], 'links and image URLs'],
    'nested' => [['find' => 'Acme'], ['includeNested' => true], 'Matrix nested entries'],
]);

it('keeps Matrix editing for Pro', function() {
    [$section, $matrix, $type, $text] = seedMatrix([['a']]);

    ContentOps::getInstance()->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section]]),
        [new Operation(['target' => "$matrix.$type.$text", 'operator' => 'text', 'operation' => 'append', 'options' => ['value' => '!']])],
    );
})->throws(ProFeatureException::class, 'Matrix');

it('hides Matrix targets in Lite', function() {
    [, $matrix, , , $owners] = seedMatrix([['a']]);

    $handles = array_column(ContentOps::getInstance()->getTargets()->describeTargets(\craft\elements\Entry::class, [$owners[0]->id]), 'handle');

    expect($handles)->not->toContain($matrix)
        ->and(array_filter($handles, fn($h) => str_starts_with($h, "$matrix.")))->toBeEmpty();
});

it('only undoes the most recent changeset in Lite', function() {
    [$section, $field] = seedSection(['a']);
    $changesets = ContentOps::getInstance()->getChangesets();
    $admin = \craft\elements\User::find()->admin()->one() ?? \markhuot\craftpest\factories\User::factory()->admin(true)->create();

    $first = previewOp($section, op($field, 'append', ['value' => '1']));
    $changesets->applyNow($first->id);
    $second = previewOp($section, op($field, 'append', ['value' => '2']));
    $changesets->applyNow($second->id);

    expect($changesets->canUndo($changesets->getChangesetById($first->id), $admin))->toBeFalse()
        ->and($changesets->canUndo($changesets->getChangesetById($second->id), $admin))->toBeTrue();

    useEdition(ContentOps::EDITION_PRO);

    expect($changesets->canUndo($changesets->getChangesetById($first->id), $admin))->toBeTrue();
});

it('renders the Lite find and replace form with Pro options disabled', function() {
    $this->actingAsAdmin()
        ->get('/admin/content-ops/find-replace')
        ->assertOk()
        ->assertSee('co-pro-badge', false)
        ->assertSee('Content Ops Pro adds');
});
