<?php

use craft\elements\Entry;
use craft\fields\PlainText;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\EntryType as EntryTypeFactory;
use markhuot\craftpest\factories\Field as FieldFactory;
use markhuot\craftpest\factories\MatrixField as MatrixFieldFactory;
use markhuot\craftpest\factories\Section as SectionFactory;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetStatus;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;

/**
 * Creates owners with a Matrix field whose "block" entry type has a plain text field.
 * Returns [sectionHandle, matrixHandle, blockTypeHandle, textHandle, Entry[] owners].
 */
function seedMatrix(array $blocksPerOwner): array
{
    $text = FieldFactory::factory()->type(PlainText::class);
    $blockType = EntryTypeFactory::factory()->hasTitleField(false)->fields($text);
    $matrix = MatrixFieldFactory::factory()->entryTypes($blockType)->create();
    $section = SectionFactory::factory()->fields($matrix)->create();
    $blockTypeHandle = $blockType->getMadeModels()->first()->handle;
    $textHandle = $text->getMadeModels()->first()->handle;
    $owners = [];

    foreach ($blocksPerOwner as $values) {
        $blocks = array_map(fn($value) => EntryFactory::factory()->type($blockTypeHandle)->{$textHandle}($value), $values);
        $owners[] = EntryFactory::factory()->section($section->handle)->{$matrix->handle}(...$blocks)->create();
    }

    return [$section->handle, $matrix->handle, $blockTypeHandle, $textHandle, $owners];
}

function blockValues(Entry $owner, string $matrixHandle, string $textHandle): array
{
    $fresh = Entry::find()->id($owner->id)->status(null)->one();

    return array_map(fn(Entry $block) => $block->getFieldValue($textHandle), $fresh->getFieldValue($matrixHandle)->status(null)->all());
}

it('edits fields inside nested entries and undoes it', function() {
    [$section, $matrix, $type, $text, $owners] = seedMatrix([
        ['Acme one', 'nothing', 'Acme two'],
        ['Acme three'],
    ]);
    $plugin = ContentOps::getInstance();

    $changeset = $plugin->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section]]),
        [new Operation(['target' => "$matrix.$type.$text", 'operator' => 'text', 'operation' => 'replace', 'options' => ['find' => 'Acme', 'replace' => 'Globex']])],
    );

    expect($changeset->getCount('pending'))->toBe(3)
        ->and($changeset->getCount('unchanged'))->toBe(1);

    $plugin->getChangesets()->applyNow($changeset->id);

    expect($plugin->getChangesets()->getChangesetById($changeset->id)->status)->toBe(ChangesetStatus::Applied)
        ->and(blockValues($owners[0], $matrix, $text))->toBe(['Globex one', 'nothing', 'Globex two'])
        ->and(blockValues($owners[1], $matrix, $text))->toBe(['Globex three']);

    $plugin->getChangesets()->undoNow($changeset->id);

    expect(blockValues($owners[0], $matrix, $text))->toBe(['Acme one', 'nothing', 'Acme two'])
        ->and(blockValues($owners[1], $matrix, $text))->toBe(['Acme three']);
});

it('keeps nested entries attached to their owners', function() {
    [$section, $matrix, $type, $text, $owners] = seedMatrix([['a', 'b']]);
    $plugin = ContentOps::getInstance();
    $before = Entry::find()->ownerId($owners[0]->id)->status(null)->ids();

    $changeset = $plugin->getPreviewer()->preview(
        new Selection(['criteria' => ['section' => $section]]),
        [new Operation(['target' => "$matrix.$type.$text", 'operator' => 'text', 'operation' => 'append', 'options' => ['value' => '!']])],
    );
    $plugin->getChangesets()->applyNow($changeset->id);
    $plugin->getChangesets()->undoNow($changeset->id);

    expect(Entry::find()->ownerId($owners[0]->id)->status(null)->ids())->toBe($before)
        ->and(blockValues($owners[0], $matrix, $text))->toBe(['a', 'b']);
});

it('lists nested fields as targets', function() {
    [, $matrix, $type, $text, $owners] = seedMatrix([['a']]);

    $targets = collect(ContentOps::getInstance()->getTargets()->describeTargets(Entry::class, [$owners[0]->id]))->keyBy('handle');

    expect($targets)->toHaveKey("$matrix.$type.$text")
        ->and($targets["$matrix.$type.$text"]['label'])->toContain('›')
        ->and($targets["$matrix.$type.$text"]['operator'])->toBe('text');
});
