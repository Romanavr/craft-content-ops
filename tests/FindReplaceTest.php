<?php

use craft\ckeditor\Field as CkeditorField;
use craft\elements\Entry;
use craft\fields\PlainText;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\EntryType as EntryTypeFactory;
use markhuot\craftpest\factories\Field as FieldFactory;
use markhuot\craftpest\factories\MatrixField as MatrixFieldFactory;
use markhuot\craftpest\factories\Section as SectionFactory;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetType;
use romanavr\contentops\models\FindReplaceScope;
use romanavr\contentops\models\MatchSpec;

/**
 * A section with a plain text field, a CKEditor field and a Matrix field (block type with a plain text field).
 */
function seedFindReplace(): array
{
    $summary = FieldFactory::factory()->type(PlainText::class)->create();
    $body = FieldFactory::factory()->type(CkeditorField::class)->create();
    $quote = FieldFactory::factory()->type(PlainText::class);
    $blockType = EntryTypeFactory::factory()->hasTitleField(false)->fields($quote);
    $matrix = MatrixFieldFactory::factory()->entryTypes($blockType)->create();
    $section = SectionFactory::factory()->fields($summary, $body, $matrix)->create();
    $quoteHandle = $quote->getMadeModels()->first()->handle;
    $blockTypeHandle = $blockType->getMadeModels()->first()->handle;

    $hit = EntryFactory::factory()
        ->section($section->handle)
        ->title('Acme news')
        ->set($summary->handle, 'Acme Corp and acme')
        ->set($body->handle, '<p>About <a href="http://old.test/acme">Acme</a></p>')
        ->{$matrix->handle}(EntryFactory::factory()->type($blockTypeHandle)->{$quoteHandle}('Quote about Acme'))
        ->create();
    $miss = EntryFactory::factory()->section($section->handle)->title('Nothing here')->create();

    return compact('section', 'summary', 'body', 'matrix', 'quoteHandle', 'blockTypeHandle', 'hit', 'miss');
}

function frPreview(array $spec, array $scope)
{
    return ContentOps::getInstance()->getFindReplace()->preview(new MatchSpec($spec), new FindReplaceScope($scope));
}

it('finds and replaces across text fields, HTML text and nested entries', function() {
    $s = seedFindReplace();
    $changeset = frPreview(['find' => 'Acme', 'replace' => 'Globex'], ['sections' => [$s['section']->handle]]);

    $targets = collect(ContentOps::getInstance()->getChangesets()->getChanges($changeset->id))->pluck('target')->unique()->sort()->values()->all();
    $expected = collect(['title', $s['summary']->handle, $s['body']->handle, "{$s['matrix']->handle}.{$s['blockTypeHandle']}.{$s['quoteHandle']}"])->sort()->values()->all();

    // Titles are translated per site, so on a multi-site install the title changes once per site.
    expect($changeset->type)->toBe(ChangesetType::FindReplace)
        ->and($targets)->toBe($expected);

    ContentOps::getInstance()->getChangesets()->applyNow($changeset->id);
    $entry = Entry::find()->id($s['hit']->id)->one();
    $block = $entry->getFieldValue($s['matrix']->handle)->one();

    expect($entry->title)->toBe('Globex news')
        ->and($entry->getFieldValue($s['summary']->handle))->toBe('Globex Corp and acme')
        ->and((string)$entry->getFieldValue($s['body']->handle))->toContain('href="http://old.test/acme"')
        ->and((string)$entry->getFieldValue($s['body']->handle))->toContain('>Globex</a>')
        ->and($block->getFieldValue($s['quoteHandle']))->toBe('Quote about Globex');

    ContentOps::getInstance()->getChangesets()->undoNow($changeset->id);

    expect(Entry::find()->id($s['hit']->id)->one()->title)->toBe('Acme news');
});

it('can skip nested entries', function() {
    $s = seedFindReplace();
    $changeset = frPreview(['find' => 'Acme', 'replace' => 'Globex'], ['sections' => [$s['section']->handle], 'includeNested' => false]);

    $targets = collect(ContentOps::getInstance()->getChangesets()->getChanges($changeset->id))->pluck('target')->unique()->all();

    expect($targets)->not->toContain("{$s['matrix']->handle}.{$s['blockTypeHandle']}.{$s['quoteHandle']}")
        ->and($targets)->toContain($s['summary']->handle);
});

it('excludes individual matches', function() {
    $s = seedFindReplace();
    $service = ContentOps::getInstance()->getFindReplace();
    $changeset = frPreview(['find' => 'acme', 'replace' => 'Globex', 'caseSensitive' => false], ['sections' => [$s['section']->handle], 'targets' => [$s['summary']->handle]]);

    $row = $service->matches($changeset)[0];
    expect($row['matches'])->toHaveCount(2);

    // Keep the lowercase "acme" (match #1).
    $service->exclude($changeset, [$row['change']->id => [1]]);
    ContentOps::getInstance()->getChangesets()->applyNow($changeset->id);

    expect(Entry::find()->id($s['hit']->id)->one()->getFieldValue($s['summary']->handle))->toBe('Globex Corp and acme');
});

it('pre-filters to the same entries as a full scan', function(array $spec) {
    $s = seedFindReplace();
    $service = ContentOps::getInstance()->getFindReplace();
    $matchSpec = new MatchSpec($spec);
    $siteIds = Craft::$app->getSites()->getAllSiteIds();

    expect($service->canPrefilter($matchSpec))->toBeTrue();

    $prefiltered = array_intersect([$s['hit']->id, $s['miss']->id], $service->prefilter($matchSpec, $siteIds, true));

    expect(array_values($prefiltered))->toBe([$s['hit']->id]);
})->with([
    'case-sensitive' => [['find' => 'Acme']],
    'case-insensitive' => [['find' => 'ACME', 'caseSensitive' => false]],
    'only in nested entry' => [['find' => 'Quote about']],
    'only in html attribute' => [['find' => 'old.test']],
]);

it('falls back to a full scan for non-ASCII and regex searches', function(array $spec) {
    expect(ContentOps::getInstance()->getFindReplace()->canPrefilter(new MatchSpec($spec)))->toBeFalse();
})->with([
    'unicode' => [['find' => 'Grüße']],
    'quote' => [['find' => 'say "hi"']],
    'regex' => [['find' => 'Ac.e', 'regex' => true]],
]);
